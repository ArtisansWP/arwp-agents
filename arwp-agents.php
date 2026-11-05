<?php
/**
 * Plugin Name:       ARWP Agents
 * Plugin URI:        https://wordpress.org/plugins/arwp-agents/
 * Description:       Self-hosted AI chat agents for WordPress, using your own OpenRouter API key.
 * Version:           1.5.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            ARWP
 * Author URI:        https://profiles.wordpress.org/artisanswp/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       arwp-agents
 * Domain Path:       /languages
 *
 * @package ARWP_Agents
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'ARWP_VERSION', '1.5.0' );
define( 'ARWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'ARWP_URL', plugin_dir_url( __FILE__ ) );
define( 'ARWP_BASENAME', plugin_basename( __FILE__ ) );

// Include required classes.
require_once ARWP_PATH . 'includes/class-arwp-activator.php';
require_once ARWP_PATH . 'includes/class-arwp-settings.php';
require_once ARWP_PATH . 'includes/class-arwp-cpt.php';
require_once ARWP_PATH . 'includes/class-arwp-api.php';
require_once ARWP_PATH . 'includes/class-arwp-shortcode.php';
require_once ARWP_PATH . 'includes/class-arwp-conversations.php';

/**
 * Hard ceiling on how many past messages are replayed to the model.
 *
 * Without a cap an old conversation resends its entire history on every turn, which
 * grows the bill and the request size without bound.
 *
 * @return int
 */
function arwp_default_history_limit() {
	return (int) apply_filters( 'arwp_default_history_limit', 40 );
}

/**
 * Maximum characters accepted in a single chat message. 0 disables the check.
 *
 * @return int
 */
function arwp_max_message_chars() {
	return (int) apply_filters( 'arwp_max_message_chars', 8000 );
}

/**
 * Seconds a visitor must wait between messages in the same session.
 *
 * @return int
 */
function arwp_default_cooldown() {
	return (int) apply_filters( 'arwp_default_cooldown', 5 );
}

/**
 * Max chat turns per visitor IP, per agent, per hour. 0 disables the cap.
 *
 * @return int
 */
function arwp_default_rate_limit_per_ip() {
	return (int) apply_filters( 'arwp_default_rate_limit_per_ip', 30 );
}

/**
 * Max chat turns across all visitors of one agent per hour. 0 disables the cap.
 *
 * @return int
 */
function arwp_default_rate_limit_per_agent() {
	return (int) apply_filters( 'arwp_default_rate_limit_per_agent', 200 );
}

/**
 * Read a non-negative int from post meta, using $default when the field was never saved.
 *
 * @param mixed $raw      Raw meta value.
 * @param int   $fallback Fallback when empty.
 * @return int
 */
function arwp_meta_int( $raw, $fallback ) {
	if ( '' === $raw || false === $raw || null === $raw ) {
		return (int) $fallback;
	}

	return max( 0, (int) $raw );
}

/**
 * Capabilities for the agent post type.
 *
 * These must be unique names. Mapping them onto manage_options makes WordPress
 * treat manage_options as a post meta capability, which hides this menu and
 * breaks other administrator checks.
 *
 * @return array<string, string>
 */
function arwp_agent_capabilities() {
	return array(
		'edit_post'              => 'edit_arwp_agent',
		'read_post'              => 'read_arwp_agent',
		'delete_post'            => 'delete_arwp_agent',
		'edit_posts'             => 'edit_arwp_agents',
		'edit_others_posts'      => 'edit_others_arwp_agents',
		'delete_posts'           => 'delete_arwp_agents',
		'publish_posts'          => 'publish_arwp_agents',
		'read_private_posts'     => 'read_private_arwp_agents',
		'delete_private_posts'   => 'delete_private_arwp_agents',
		'delete_published_posts' => 'delete_published_arwp_agents',
		'delete_others_posts'    => 'delete_others_arwp_agents',
		'edit_private_posts'     => 'edit_private_arwp_agents',
		'edit_published_posts'   => 'edit_published_arwp_agents',
		'create_posts'           => 'edit_arwp_agents',
	);
}

/**
 * Give administrators the agent capabilities. Editors do not receive them.
 */
function arwp_grant_agent_caps_to_administrators() {
	$role = get_role( 'administrator' );
	if ( ! $role ) {
		return;
	}

	foreach ( array_unique( arwp_agent_capabilities() ) as $cap ) {
		if ( ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
		}
	}

	$user = wp_get_current_user();
	if ( $user instanceof WP_User && in_array( 'administrator', (array) $user->roles, true ) ) {
		$user->get_role_caps();
	}
}
add_action( 'init', 'arwp_grant_agent_caps_to_administrators', 5 );

/**
 * The code that runs during plugin activation.
 */
function arwp_activate_plugin() {
	ARWP_Activator::activate();
}
register_activation_hook( __FILE__, 'arwp_activate_plugin' );

/**
 * Development paths that are omitted from the release zip.
 *
 * Plugin Check scans the installed directory. These match .distignore so the
 * local result matches the package WordPress.org would receive.
 *
 * @param array $directories Directories to skip.
 * @return array
 */
function arwp_plugin_check_ignore_directories( $directories ) {
	return array_merge( $directories, array( 'tests', '.github' ) );
}
add_filter( 'wp_plugin_check_ignore_directories', 'arwp_plugin_check_ignore_directories' );

/**
 * Development files that are omitted from the release zip.
 *
 * @param array $files Files to skip.
 * @return array
 */
function arwp_plugin_check_ignore_files( $files ) {
	return array_merge(
		$files,
		array(
			'.distignore',
			'.gitignore',
			'.phpcs.cache',
			'.phpunit.result.cache',
			'phpcs.xml.dist',
			'phpunit.xml.dist',
		)
	);
}
add_filter( 'wp_plugin_check_ignore_files', 'arwp_plugin_check_ignore_files' );

/**
 * Initialize all core components of the plugin.
 *
 * Translations ship through the Domain Path header. WordPress loads them.
 */
function arwp_init_plugin() {
	if ( get_option( 'arwp_db_version' ) !== '1.6' ) {
		ARWP_Activator::activate();
		ARWP_Activator::cleanup_removed_features();
		update_option( 'arwp_db_version', '1.6' );
	}

	$cpt = new ARWP_CPT();
	$cpt->init();

	$settings = new ARWP_Settings();
	$settings->init();

	$api = new ARWP_API();
	$api->init();

	$shortcode = new ARWP_Shortcode();
	$shortcode->init();

	$conversations = new ARWP_Conversations();
	$conversations->init();
}
add_action( 'plugins_loaded', 'arwp_init_plugin' );
