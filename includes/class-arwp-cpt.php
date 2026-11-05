<?php
/**
 * Registers arwp_agent Custom Post Type and custom Meta Boxes.
 *
 * @package    ARWP_Agents
 * @subpackage ARWP_Agents/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent post type, meta boxes, and admin list UI.
 */
class ARWP_CPT {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'after_setup_theme', array( $this, 'enable_thumbnails' ) );
		add_action( 'init', array( $this, 'register_arwp_agent_cpt' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_agent_meta_boxes' ) );
		add_action( 'save_post_arwp_agent', array( $this, 'save_agent_meta' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_filter( 'default_title', array( $this, 'set_default_agent_title' ), 10, 2 );

		// Custom columns for the All Agents list.
		add_filter( 'manage_arwp_agent_posts_columns', array( $this, 'set_custom_agent_columns' ) );
		add_action( 'manage_arwp_agent_posts_custom_column', array( $this, 'custom_agent_column_content' ), 10, 2 );

		// Clone agent row action.
		add_filter( 'post_row_actions', array( $this, 'add_clone_agent_action' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'handle_clone_agent' ) );

		// Model filter dropdown.
		add_action( 'restrict_manage_posts', array( $this, 'add_agent_model_filter' ) );
		add_filter( 'pre_get_posts', array( $this, 'filter_agents_by_model' ) );
		add_action( 'edit_form_after_title', array( $this, 'render_editor_help' ) );
		add_action( 'admin_notices', array( $this, 'maybe_setup_notice' ) );
		add_action( 'admin_menu', array( $this, 'remove_add_agent_submenu' ), 99 );
	}

	/**
	 * Drop the automatic Add Agent item. New agents are created from the list screen.
	 */
	public function remove_add_agent_submenu() {
		remove_submenu_page( 'edit.php?post_type=arwp_agent', 'post-new.php?post_type=arwp_agent' );
	}

	/**
	 * Ensure featured images work even if the theme omitted post-thumbnails.
	 */
	public function enable_thumbnails() {
		add_theme_support( 'post-thumbnails', array( 'arwp_agent' ) );
	}

	/**
	 * Short how-to under the agent title.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_editor_help( $post ) {
		if ( 'arwp_agent' !== $post->post_type ) {
			return;
		}
		echo '<p class="arwp-admin-help">' . esc_html__( 'Name the agent, choose a model, then copy the shortcode from the sidebar to put the chat on a page.', 'arwp-agents' ) . '</p>';
	}

	/**
	 * Prompt setup when the API key is missing.
	 */
	public function maybe_setup_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-arwp_agent' !== $screen->id ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'arwp_openrouter_api_key', '' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %s: settings URL */
			esc_html__( 'Connect your OpenRouter API key before chatting. %s', 'arwp-agents' ),
			'<a href="' . esc_url( admin_url( 'edit.php?post_type=arwp_agent&page=arwp-settings' ) ) . '">' . esc_html__( 'Open Settings', 'arwp-agents' ) . '</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Set default title for new agents.
	 *
	 * @param string  $title Default title.
	 * @param WP_Post $post  Post being created.
	 * @return string
	 */
	public function set_default_agent_title( $title, $post ) {
		if ( 'arwp_agent' === $post->post_type ) {
			return __( 'New AI Agent', 'arwp-agents' );
		}
		return $title;
	}

	/**
	 * Define custom columns for the arwp_agent post type list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function set_custom_agent_columns( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $title ) {
			if ( 'date' === $key ) {
				$new_columns['arwp_model']     = __( 'Model', 'arwp-agents' );
				$new_columns['arwp_shortcode'] = __( 'Shortcode', 'arwp-agents' );
			}
			$new_columns[ $key ] = $title;
		}
		return $new_columns;
	}

	/**
	 * Render content for custom columns in the arwp_agent list table.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function custom_agent_column_content( $column, $post_id ) {
		if ( 'arwp_shortcode' === $column ) {
			$shortcode = '[arwp id="' . intval( $post_id ) . '"]';
			?>
			<input type="text" readonly="readonly" class="arwp-list-shortcode-input" value="<?php echo esc_attr( $shortcode ); ?>" style="width: 100%; max-width: 150px; text-align: center; font-size: 13px; padding: 4px; cursor: pointer; background: #f0f0f1; border-color: #8c8f94; color: #3c434a;" title="<?php esc_attr_e( 'Click to copy', 'arwp-agents' ); ?>" />
			<span class="arwp-list-copied-msg" style="display:none; color:green; font-size:11px; margin-left:5px;"><?php esc_html_e( 'Copied!', 'arwp-agents' ); ?></span>
			<?php
		} elseif ( 'arwp_model' === $column ) {
			$model = get_post_meta( $post_id, '_arwp_model', true );
			if ( empty( $model ) || 'default' === $model ) {
				echo '<strong>' . esc_html__( 'Global Default', 'arwp-agents' ) . '</strong>';
			} else {
				$fetched_models = class_exists( 'ARWP_Settings' ) ? ARWP_Settings::get_models() : array();
				$label          = isset( $fetched_models[ $model ] ) ? $fetched_models[ $model ] : $model;
				echo esc_html( $label );
			}
		}
	}

	/**
	 * Add Model filter dropdown to All Agents screen.
	 *
	 * @param string $post_type Current post type.
	 */
	public function add_agent_model_filter( $post_type ) {
		if ( 'arwp_agent' !== $post_type ) {
			return;
		}

		$fetched_models = class_exists( 'ARWP_Settings' ) ? ARWP_Settings::get_models() : array();
		$models         = array(
			'default' => __( 'Global Default Model', 'arwp-agents' ),
		) + $fetched_models;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin list filter, mirrors core's own GET-based list table filters.
		$selected = isset( $_GET['arwp_model_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['arwp_model_filter'] ) ) : '';

		echo '<select name="arwp_model_filter" id="arwp_model_filter">';
		echo '<option value="">' . esc_html__( 'All Models', 'arwp-agents' ) . '</option>';
		foreach ( $models as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Filter agents by selected model.
	 *
	 * @param WP_Query $query Main query.
	 */
	public function filter_agents_by_model( $query ) {
		global $pagenow;
		// Read-only admin list filter, mirrors core's own GET-based list table filters (e.g. ?post_status=), so no nonce is required.
		if ( is_admin() && 'edit.php' === $pagenow && isset( $_GET['post_type'] ) && 'arwp_agent' === $_GET['post_type'] && $query->is_main_query() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filter_val = isset( $_GET['arwp_model_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['arwp_model_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' !== $filter_val ) {
				$meta_query = $query->get( 'meta_query' );
				if ( ! is_array( $meta_query ) ) {
					$meta_query = array();
				}

				if ( 'default' === $filter_val ) {
					$meta_query[] = array(
						'relation' => 'OR',
						array(
							'key'     => '_arwp_model',
							'value'   => 'default',
							'compare' => '=',
						),
						array(
							'key'     => '_arwp_model',
							'compare' => 'NOT EXISTS',
						),
					);
				} else {
					$meta_query[] = array(
						'key'     => '_arwp_model',
						'value'   => $filter_val,
						'compare' => '=',
					);
				}
				$query->set( 'meta_query', $meta_query );
			}
		}
	}

	/**
	 * Add 'Clone' link to post row actions.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Current post.
	 * @return array
	 */
	public function add_clone_agent_action( $actions, $post ) {
		if ( 'arwp_agent' === $post->post_type ) {
			$url              = wp_nonce_url( admin_url( 'admin.php?action=arwp_clone_agent&post=' . $post->ID ), 'arwp_clone_agent_nonce', 'nonce' );
			$actions['clone'] = '<a href="' . esc_url( $url ) . '" title="' . esc_attr__( 'Clone this Agent', 'arwp-agents' ) . '">' . esc_html__( 'Clone', 'arwp-agents' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Handle the clone agent action.
	 */
	public function handle_clone_agent() {
		if ( ! isset( $_GET['action'] ) || 'arwp_clone_agent' !== $_GET['action'] ) {
			return;
		}

		if ( ! isset( $_GET['post'] ) || ! isset( $_GET['nonce'] ) ) {
			wp_die( esc_html__( 'Missing required parameters.', 'arwp-agents' ) );
		}

		$post_id = intval( $_GET['post'] );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'arwp_clone_agent_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'arwp-agents' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to clone agents.', 'arwp-agents' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'arwp_agent' !== $post->post_type ) {
			wp_die( esc_html__( 'Invalid agent.', 'arwp-agents' ) );
		}

		// Create a copy of the post.
		$new_post_args = array(
			'post_title'   => $post->post_title . ' ' . __( '(Copy)', 'arwp-agents' ),
			'post_content' => $post->post_content,
			'post_status'  => 'draft', // Draft is safer so they don't get confused about which is which before publishing.
			'post_type'    => $post->post_type,
			'post_author'  => get_current_user_id(),
		);

		$new_post_id = wp_insert_post( $new_post_args );

		if ( ! is_wp_error( $new_post_id ) ) {
			$meta_data = get_post_custom( $post_id );
			$skip_meta = array(
				'_edit_lock',
				'_edit_last',
			);

			foreach ( $meta_data as $key => $values ) {
				if ( in_array( $key, $skip_meta, true ) ) {
					continue;
				}
				foreach ( $values as $value ) {
					$unserialized = maybe_unserialize( $value );
					add_post_meta( $new_post_id, $key, $unserialized );
				}
			}

			// Redirect back to the agent list.
			wp_safe_redirect( admin_url( 'edit.php?post_type=arwp_agent' ) );
			exit;
		} else {
			wp_die( esc_html__( 'Failed to clone the agent.', 'arwp-agents' ) );
		}
	}

	/**
	 * Admin CSS and editor/list scripts for ARWP Agents screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_ours = ( false !== strpos( $hook, 'arwp-settings' ) || false !== strpos( $hook, 'arwp-conversations' ) );
		if ( $screen && isset( $screen->post_type ) && 'arwp_agent' === $screen->post_type ) {
			$is_ours = true;
		}
		if ( ! $is_ours ) {
			return;
		}

		wp_register_style( 'arwp-admin', false, array(), ARWP_VERSION );
		wp_enqueue_style( 'arwp-admin' );
		wp_add_inline_style(
			'arwp-admin',
			'
			.arwp-admin-help { margin: 8px 0 16px; color: #646970; font-size: 14px; }
			.arwp-meta-table { width: 100%; border-spacing: 0 12px; }
			.arwp-meta-table th { text-align: left; vertical-align: top; width: 200px; font-weight: 600; }
			.arwp-meta-table td input[type="number"],
			.arwp-meta-table td select,
			.arwp-meta-table td textarea { width: 100%; max-width: 600px; box-sizing: border-box; }
			.arwp-meta-table td textarea { min-height: 120px; }
			.arwp-meta-table td p.description { margin-top: 4px; color: #666; font-size: 12px; }
			.post-type-arwp_agent #misc-publishing-actions,
			.post-type-arwp_agent #preview-action,
			.post-type-arwp_agent #delete-action { display: none !important; }
			.post-type-arwp_agent #submitpost { display: flex; justify-content: space-between; align-items: center; padding: 15px 10px; }
			.post-type-arwp_agent #minor-publishing,
			.post-type-arwp_agent #minor-publishing-actions,
			.post-type-arwp_agent #major-publishing-actions,
			.post-type-arwp_agent #publishing-action,
			.post-type-arwp_agent #save-action { display: contents !important; }
			'
		);

		$is_editor = ( 'post.php' === $hook || 'post-new.php' === $hook ) && $screen && 'arwp_agent' === $screen->post_type;
		$is_list   = ( 'edit.php' === $hook && $screen && 'edit-arwp_agent' === $screen->id );

		wp_register_script( 'arwp-admin', false, array( 'jquery' ), ARWP_VERSION, true );

		if ( $is_editor ) {
			wp_enqueue_style( 'arwp-select2-css', ARWP_URL . 'assets/vendor/select2/select2.min.css', array(), '4.1.0' );
			wp_enqueue_script( 'arwp-select2-js', ARWP_URL . 'assets/vendor/select2/select2.min.js', array( 'jquery' ), '4.1.0', true );
			wp_enqueue_script( 'arwp-admin' );
			wp_add_inline_script(
				'arwp-admin',
				"jQuery(function($){
					$('#arwp_model').select2({ width: '100%', maxWidth: '600px' });
					$('#arwp_shortcode_input').on('click', function() {
						this.select();
						document.execCommand('copy');
						$('#arwp_shortcode_msg').stop().fadeIn().delay(2000).fadeOut();
					});
				});"
			);
		}

		if ( $is_list ) {
			wp_enqueue_script( 'arwp-admin' );
			wp_add_inline_script(
				'arwp-admin',
				"jQuery(function($){
					$('.arwp-list-shortcode-input').on('click', function() {
						$(this).select();
						document.execCommand('copy');
						$(this).next('.arwp-list-copied-msg').stop().fadeIn('fast').delay(1500).fadeOut('fast');
					});
				});"
			);
		}
	}

	/**
	 * Register arwp_agent Custom Post Type.
	 */
	public function register_arwp_agent_cpt() {
		$labels = array(
			'name'                  => _x( 'Agents', 'Post Type General Name', 'arwp-agents' ),
			'singular_name'         => _x( 'Agent', 'Post Type Singular Name', 'arwp-agents' ),
			'menu_name'             => __( 'ARWP Agents', 'arwp-agents' ),
			'name_admin_bar'        => __( 'Agent', 'arwp-agents' ),
			'archives'              => __( 'Agent Archives', 'arwp-agents' ),
			'attributes'            => __( 'Agent Attributes', 'arwp-agents' ),
			'parent_item_colon'     => __( 'Parent Agent:', 'arwp-agents' ),
			'all_items'             => __( 'Agents', 'arwp-agents' ),
			'add_new_item'          => __( 'Add New Agent', 'arwp-agents' ),
			'add_new'               => __( 'Add Agent', 'arwp-agents' ),
			'new_item'              => __( 'New Agent', 'arwp-agents' ),
			'edit_item'             => __( 'Edit Agent', 'arwp-agents' ),
			'update_item'           => __( 'Update Agent', 'arwp-agents' ),
			'view_item'             => __( 'View Agent', 'arwp-agents' ),
			'view_items'            => __( 'View Agents', 'arwp-agents' ),
			'search_items'          => __( 'Search Agent', 'arwp-agents' ),
			'not_found'             => __( 'Not found', 'arwp-agents' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'arwp-agents' ),
			'featured_image'        => __( 'Chat Avatar', 'arwp-agents' ),
			'set_featured_image'    => __( 'Set chat avatar', 'arwp-agents' ),
			'remove_featured_image' => __( 'Remove chat avatar', 'arwp-agents' ),
			'use_featured_image'    => __( 'Use as chat avatar', 'arwp-agents' ),
			'insert_into_item'      => __( 'Insert into agent', 'arwp-agents' ),
			'uploaded_to_this_item' => __( 'Uploaded to this agent', 'arwp-agents' ),
			'items_list'            => __( 'Agents list', 'arwp-agents' ),
			'items_list_navigation' => __( 'Agents list navigation', 'arwp-agents' ),
			'filter_items_list'     => __( 'Filter agents list', 'arwp-agents' ),
		);
		$args   = array(
			'label'               => __( 'Agent', 'arwp-agents' ),
			'description'         => __( 'Chat agents for this site.', 'arwp-agents' ),
			'labels'              => $labels,
			'supports'            => array( 'title', 'thumbnail' ),
			'hierarchical'        => false,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_position'       => 26,
			'menu_icon'           => 'dashicons-format-chat',
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => false,
			'can_export'          => true,
			'has_archive'         => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'capabilities'        => arwp_agent_capabilities(),
			'show_in_rest'        => false,
		);
		register_post_type( 'arwp_agent', $args );
	}

	/**
	 * Add Meta Box for arwp_agent custom post type.
	 */
	public function add_agent_meta_boxes() {
		global $wp_meta_boxes;

		// Change default 'Publish' metabox title to 'Publish Agent'.
		if ( isset( $wp_meta_boxes['arwp_agent']['side']['core']['submitdiv'] ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core has no filter for this metabox title.
			$wp_meta_boxes['arwp_agent']['side']['core']['submitdiv']['title'] = __( 'Publish Agent', 'arwp-agents' );
		}

		add_meta_box(
			'arwp_agent_core_settings',
			__( 'Agent & Model', 'arwp-agents' ),
			array( $this, 'render_agent_core_settings_meta_box' ),
			'arwp_agent',
			'normal',
			'high'
		);

		add_meta_box(
			'arwp_agent_ui_settings',
			__( 'Chat Appearance', 'arwp-agents' ),
			array( $this, 'render_agent_ui_settings_meta_box' ),
			'arwp_agent',
			'normal',
			'high'
		);

		add_meta_box(
			'arwp_agent_shortcode',
			__( 'Add to a page', 'arwp-agents' ),
			array( $this, 'render_agent_shortcode_meta_box' ),
			'arwp_agent',
			'side',
			'high'
		);
	}

	/**
	 * Render Agent Shortcode Meta Box.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_agent_shortcode_meta_box( $post ) {
		if ( 'auto-draft' === $post->post_status || empty( $post->ID ) ) {
			echo '<p>' . esc_html__( 'Please save or publish the agent first to generate a shortcode.', 'arwp-agents' ) . '</p>';
			return;
		}

		$shortcode = '[arwp id="' . intval( $post->ID ) . '"]';
		?>
		<p><?php esc_html_e( 'Paste this on any page or post to show the chat.', 'arwp-agents' ); ?></p>
		<input type="text" readonly="readonly" class="large-text" id="arwp_shortcode_input" value="<?php echo esc_attr( $shortcode ); ?>" style="text-align: center; font-size: 14px; padding: 10px; cursor: pointer; background: #f0f0f1; border-color: #8c8f94; color: #3c434a;" title="<?php esc_attr_e( 'Click to copy', 'arwp-agents' ); ?>" />
		<p class="description" id="arwp_shortcode_msg" style="text-align:center; color:green; display:none;"><?php esc_html_e( 'Copied to clipboard!', 'arwp-agents' ); ?></p>
		<?php
	}

	/**
	 * Render Frontend Chat UI Settings Meta Box.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_agent_ui_settings_meta_box( $post ) {
		wp_nonce_field( 'arwp_save_agent_ui_meta_action', 'arwp_agent_ui_meta_nonce' );

		$ui_theme       = get_post_meta( $post->ID, '_arwp_ui_theme', true );
		$ui_title       = get_post_meta( $post->ID, '_arwp_ui_title', true );
		$ui_greeting    = get_post_meta( $post->ID, '_arwp_ui_greeting', true );
		$ui_placeholder = get_post_meta( $post->ID, '_arwp_ui_placeholder', true );
		$primary_color  = get_post_meta( $post->ID, '_arwp_ui_primary_color', true );

		if ( empty( $ui_theme ) ) {
			$ui_theme = 'dark';
		}
		if ( empty( $ui_title ) ) {
			$ui_title = 'AI Assistant';
		}
		if ( empty( $ui_greeting ) ) {
			$ui_greeting = 'Hello! How can I help you today?';
		}
		if ( empty( $ui_placeholder ) ) {
			$ui_placeholder = 'Type your message here...';
		}
		if ( empty( $primary_color ) ) {
			$primary_color = '#3b82f6';
		}
		?>
		<table class="arwp-meta-table">
			<tr>
				<th><label for="arwp_ui_theme"><?php esc_html_e( 'UI Theme', 'arwp-agents' ); ?></label></th>
				<td>
					<select name="arwp_ui_theme" id="arwp_ui_theme">
						<option value="dark" <?php selected( $ui_theme, 'dark' ); ?>><?php esc_html_e( 'Dark Mode', 'arwp-agents' ); ?></option>
						<option value="light" <?php selected( $ui_theme, 'light' ); ?>><?php esc_html_e( 'Light Mode', 'arwp-agents' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Select the base color scheme for the chat.', 'arwp-agents' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="arwp_ui_title"><?php esc_html_e( 'Chat Title', 'arwp-agents' ); ?></label></th>
				<td>
					<input type="text" name="arwp_ui_title" id="arwp_ui_title" value="<?php echo esc_attr( $ui_title ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Title displayed in the chat header. Defaults to Agent Post Title if empty.', 'arwp-agents' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="arwp_ui_greeting"><?php esc_html_e( 'Initial Greeting Message', 'arwp-agents' ); ?></label></th>
				<td>
					<textarea name="arwp_ui_greeting" id="arwp_ui_greeting" class="large-text" rows="3"><?php echo esc_textarea( $ui_greeting ); ?></textarea>
					<p class="description"><?php esc_html_e( 'The first message sent by the agent automatically when the chat loads (for new conversations).', 'arwp-agents' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="arwp_ui_placeholder"><?php esc_html_e( 'Input Placeholder Text', 'arwp-agents' ); ?></label></th>
				<td>
					<input type="text" name="arwp_ui_placeholder" id="arwp_ui_placeholder" value="<?php echo esc_attr( $ui_placeholder ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><label for="arwp_ui_primary_color"><?php esc_html_e( 'Primary UI Color', 'arwp-agents' ); ?></label></th>
				<td>
					<input type="color" name="arwp_ui_primary_color" id="arwp_ui_primary_color" value="<?php echo esc_attr( $primary_color ); ?>" />
					<p class="description"><?php esc_html_e( 'Used for buttons and user message bubbles.', 'arwp-agents' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render Agent Identity & Core Settings Meta Box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_agent_core_settings_meta_box( $post ) {
		// Add nonce field for security.
		wp_nonce_field( 'arwp_save_agent_meta_action', 'arwp_agent_meta_nonce' );

		$system_prompt = get_post_meta( $post->ID, '_arwp_system_prompt', true );
		$model         = get_post_meta( $post->ID, '_arwp_model', true );
		$cooldown      = get_post_meta( $post->ID, '_arwp_cooldown', true );
		$rate_ip       = get_post_meta( $post->ID, '_arwp_rate_limit_per_ip', true );
		$rate_agent    = get_post_meta( $post->ID, '_arwp_rate_limit_per_agent', true );
		$history_limit = get_post_meta( $post->ID, '_arwp_history_context_limit', true );
		$temperature   = get_post_meta( $post->ID, '_arwp_temperature', true );
		$max_tokens    = get_post_meta( $post->ID, '_arwp_max_tokens', true );

		if ( empty( $system_prompt ) ) {
			$system_prompt = 'You are a helpful and professional AI assistant.';
		}
		if ( ! $model ) {
			$model = 'default';
		}
		if ( '' === $cooldown ) {
			$cooldown = arwp_default_cooldown();
		}
		if ( '' === $rate_ip ) {
			$rate_ip = arwp_default_rate_limit_per_ip();
		}
		if ( '' === $rate_agent ) {
			$rate_agent = arwp_default_rate_limit_per_agent();
		}
		if ( '' === $temperature ) {
			$temperature = 0.7;
		}
		if ( '' === $max_tokens ) {
			$max_tokens = 1000;
		}

		$fetched_models = class_exists( 'ARWP_Settings' ) ? ARWP_Settings::get_models() : array();
		$models         = array(
			'default' => __( 'Use Global Default Model', 'arwp-agents' ),
		) + $fetched_models;
		?>
		<table class="arwp-meta-table">
			<tr>
				<th><label for="arwp_system_prompt"><?php esc_html_e( 'System Prompt', 'arwp-agents' ); ?></label></th>
				<td>
					<textarea name="arwp_system_prompt" id="arwp_system_prompt" class="large-text"><?php echo esc_textarea( $system_prompt ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Define the behavior, identity, and rules for this specific agent.', 'arwp-agents' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="arwp_model"><?php esc_html_e( 'OpenRouter Model', 'arwp-agents' ); ?></label></th>
				<td>
					<select name="arwp_model" id="arwp_model">
						<?php foreach ( $models as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Select which AI model this agent should use via OpenRouter.', 'arwp-agents' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="arwp_temperature"><?php esc_html_e( 'Temperature', 'arwp-agents' ); ?></label></th>
				<td>
					<input type="number" name="arwp_temperature" id="arwp_temperature" min="0.0" max="2.0" step="0.1" value="<?php echo esc_attr( $temperature ); ?>" />
					<p class="description"><?php esc_html_e( 'Controls creativity (0.0 is deterministic, 2.0 is highly creative). Recommended range: 0.2 - 1.0.', 'arwp-agents' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="arwp_max_tokens"><?php esc_html_e( 'Max Tokens', 'arwp-agents' ); ?></label></th>
				<td>
					<input type="number" name="arwp_max_tokens" id="arwp_max_tokens" min="1" step="1" value="<?php echo esc_attr( $max_tokens ); ?>" />
					<p class="description"><?php esc_html_e( 'Maximum number of tokens to generate in the agent completion.', 'arwp-agents' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="arwp_cooldown"><?php esc_html_e( 'Cooldown (seconds)', 'arwp-agents' ); ?></label></th>
				<td>
					<input type="number" name="arwp_cooldown" id="arwp_cooldown" min="0" step="1" value="<?php echo esc_attr( $cooldown ); ?>" />
					<p class="description">
						<?php
						printf(
							/* translators: %d: default cooldown in seconds */
							esc_html__( 'Minimum wait between messages in the same chat. 0 disables it. Default: %d.', 'arwp-agents' ),
							intval( arwp_default_cooldown() )
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="arwp_rate_limit_per_ip"><?php esc_html_e( 'Max messages per visitor / hour', 'arwp-agents' ); ?></label></th>
				<td>
					<input type="number" name="arwp_rate_limit_per_ip" id="arwp_rate_limit_per_ip" min="0" step="1" value="<?php echo esc_attr( $rate_ip ); ?>" />
					<p class="description">
						<?php
						printf(
							/* translators: %d: default per-IP hourly cap */
							esc_html__( 'Stops one person from flooding this agent. Counted by IP address. 0 = unlimited. Default: %d.', 'arwp-agents' ),
							intval( arwp_default_rate_limit_per_ip() )
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="arwp_rate_limit_per_agent"><?php esc_html_e( 'Max messages for this agent / hour', 'arwp-agents' ); ?></label></th>
				<td>
					<input type="number" name="arwp_rate_limit_per_agent" id="arwp_rate_limit_per_agent" min="0" step="1" value="<?php echo esc_attr( $rate_agent ); ?>" />
					<p class="description">
						<?php
						printf(
							/* translators: %d: default per-agent hourly cap */
							esc_html__( 'Protects your OpenRouter quota and server if the page gets busy. Shared by all visitors of this agent. 0 = unlimited. Default: %d.', 'arwp-agents' ),
							intval( arwp_default_rate_limit_per_agent() )
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="arwp_history_context_limit"><?php esc_html_e( 'History Context Limit', 'arwp-agents' ); ?></label></th>
				<td>
					<input type="number" name="arwp_history_context_limit" id="arwp_history_context_limit" min="0" step="1" value="<?php echo esc_attr( $history_limit ); ?>" />
					<p class="description">
						<?php
						printf(
							/* translators: %d: default number of messages replayed to the model */
							esc_html__( 'Number of previous messages to send as context. Leave empty to use the default of %d. Longer context costs more per reply.', 'arwp-agents' ),
							intval( arwp_default_history_limit() )
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save agent meta box values.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_agent_meta( $post_id ) {
		// Nonce verification.
		if ( ! isset( $_POST['arwp_agent_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['arwp_agent_meta_nonce'] ) ), 'arwp_save_agent_meta_action' ) ) {
			return;
		}

		// Prevent autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Capability checks.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Sanitize and update the system prompt.
		if ( isset( $_POST['arwp_system_prompt'] ) ) {
			update_post_meta( $post_id, '_arwp_system_prompt', sanitize_textarea_field( wp_unslash( $_POST['arwp_system_prompt'] ) ) );
		}

		// Sanitize and update the model.
		if ( isset( $_POST['arwp_model'] ) ) {
			$model = sanitize_text_field( wp_unslash( $_POST['arwp_model'] ) );
			if ( 'default' !== $model && ! preg_match( '/^[A-Za-z0-9_.:@+\-\/]{1,120}$/', $model ) ) {
				$model = 'default';
			}
			update_post_meta( $post_id, '_arwp_model', $model );
		}

		// Sanitize and update the temperature (0.0 to 2.0).
		if ( isset( $_POST['arwp_temperature'] ) ) {
			$temp = floatval( $_POST['arwp_temperature'] );
			$temp = max( 0.0, min( 2.0, $temp ) );
			update_post_meta( $post_id, '_arwp_temperature', $temp );
		}

		// Sanitize and update max tokens.
		if ( isset( $_POST['arwp_max_tokens'] ) ) {
			$max_t = intval( $_POST['arwp_max_tokens'] );
			$max_t = max( 1, $max_t );
			update_post_meta( $post_id, '_arwp_max_tokens', $max_t );
		}

		// Sanitize and update the cooldown period (seconds).
		if ( isset( $_POST['arwp_cooldown'] ) ) {
			update_post_meta( $post_id, '_arwp_cooldown', min( 3600, max( 0, intval( $_POST['arwp_cooldown'] ) ) ) );
		}
		if ( isset( $_POST['arwp_rate_limit_per_ip'] ) ) {
			update_post_meta( $post_id, '_arwp_rate_limit_per_ip', min( 10000, max( 0, intval( $_POST['arwp_rate_limit_per_ip'] ) ) ) );
		}
		if ( isset( $_POST['arwp_rate_limit_per_agent'] ) ) {
			update_post_meta( $post_id, '_arwp_rate_limit_per_agent', min( 100000, max( 0, intval( $_POST['arwp_rate_limit_per_agent'] ) ) ) );
		}
		if ( isset( $_POST['arwp_history_context_limit'] ) ) {
			$history_limit = sanitize_text_field( wp_unslash( $_POST['arwp_history_context_limit'] ) );
			$history_limit = ( '' === $history_limit ) ? '' : max( 0, intval( $history_limit ) );
			update_post_meta( $post_id, '_arwp_history_context_limit', $history_limit );
		}

		if ( isset( $_POST['arwp_agent_ui_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['arwp_agent_ui_meta_nonce'] ) ), 'arwp_save_agent_ui_meta_action' ) ) {
			if ( isset( $_POST['arwp_ui_theme'] ) ) {
				$theme = sanitize_text_field( wp_unslash( $_POST['arwp_ui_theme'] ) );
				if ( ! in_array( $theme, array( 'light', 'dark' ), true ) ) {
					$theme = 'dark';
				}
				update_post_meta( $post_id, '_arwp_ui_theme', $theme );
			}
			if ( isset( $_POST['arwp_ui_title'] ) ) {
				update_post_meta( $post_id, '_arwp_ui_title', sanitize_text_field( wp_unslash( $_POST['arwp_ui_title'] ) ) );
			}
			if ( isset( $_POST['arwp_ui_greeting'] ) ) {
				update_post_meta( $post_id, '_arwp_ui_greeting', sanitize_textarea_field( wp_unslash( $_POST['arwp_ui_greeting'] ) ) );
			}
			if ( isset( $_POST['arwp_ui_placeholder'] ) ) {
				update_post_meta( $post_id, '_arwp_ui_placeholder', sanitize_text_field( wp_unslash( $_POST['arwp_ui_placeholder'] ) ) );
			}
			if ( isset( $_POST['arwp_ui_primary_color'] ) ) {
				$color = sanitize_hex_color( wp_unslash( $_POST['arwp_ui_primary_color'] ) );
				if ( $color ) {
					update_post_meta( $post_id, '_arwp_ui_primary_color', $color );
				}
			}
		}
	}
}
