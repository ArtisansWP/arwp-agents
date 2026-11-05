<?php
/**
 * Fired during plugin activation.
 *
 * @package    ARWP_Agents
 * @subpackage ARWP_Agents/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom chat tables have no core API. Caching would serve stale messages.

/**
 * Activation, custom tables, and cleanup of removed features.
 */
class ARWP_Activator {

	/**
	 * Activate the plugin.
	 *
	 * Creates custom database tables and flushes rewrite rules.
	 */
	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Table names.
		$table_sessions = $wpdb->prefix . 'arwp_sessions';
		$table_messages = $wpdb->prefix . 'arwp_messages';

		// SQL for arwp_sessions.
		// Two spaces are required between PRIMARY KEY and (id) for dbDelta.
		// All field types must be lowercase. All SQL keywords must be uppercase.
		$sql_sessions = "CREATE TABLE $table_sessions (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) NOT NULL,
			user_id bigint(20) DEFAULT 0 NOT NULL,
			session_token varchar(64) NOT NULL,
			title varchar(255) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY agent_id (agent_id),
			KEY session_token (session_token)
		) $charset_collate;";

		// SQL for arwp_messages.
		$sql_messages = "CREATE TABLE $table_messages (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			session_id bigint(20) NOT NULL,
			role varchar(20) NOT NULL,
			content longtext NOT NULL,
			tokens_used int(11) DEFAULT 0 NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql_sessions );
		dbDelta( $sql_messages );

		flush_rewrite_rules();
	}

	/**
	 * Drop tables, options, and post meta from features that are no longer shipped.
	 */
	public static function cleanup_removed_features() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'arwp_promos',
			$wpdb->prefix . 'arwp_promo_events',
			$wpdb->prefix . 'arwp_documents',
			$wpdb->prefix . 'arwp_embeddings',
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes tables from features that are no longer shipped. Names are prefixed constants.
		}

		$options = array(
			'arwp_promos_enabled',
			'arwp_promos_cooldown',
			'arwp_promos_blocked_topics',
			'arwp_promos_disclosure',
			'arwp_promos_utm_enabled',
			'arwp_promos_utm_source',
			'arwp_promos_utm_medium',
			'arwp_promos_semantic',
			'arwp_openai_api_key',
			'arwp_rag_chunk_size',
			'arwp_widget_enabled',
			'arwp_widget_agent_id',
			'arwp_widget_position',
			'arwp_widget_label',
			'arwp_rate_limit_per_hour',
			'arwp_trust_proxy',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		$meta_keys = array(
			'_arwp_top_p',
			'_arwp_top_k',
			'_arwp_frequency_penalty',
			'_arwp_presence_penalty',
			'_arwp_repetition_penalty',
			'_arwp_seed',
			'_arwp_stop',
			'_arwp_response_format',
			'_arwp_ui_immersive',
			'_arwp_ui_threads_enabled',
			'_arwp_ui_allow_attachments',
			'_arwp_ui_attachment_types',
			'_arwp_promos_enabled',
			'_arwp_promos_include_global',
		);

		foreach ( $meta_keys as $meta_key ) {
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time cleanup of meta from removed features.
		}

		delete_transient( 'arwp_db_upgraded_threads_v1' );
	}
}
