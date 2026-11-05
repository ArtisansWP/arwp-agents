<?php
/**
 * Admin conversation logs.
 *
 * @package ARWP_Agents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom chat tables have no core API. Caching would serve stale messages.

/**
 * Admin screen for stored chats.
 */
class ARWP_Conversations {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_arwp_delete_session', array( $this, 'handle_delete_session' ) );
	}

	/**
	 * Add Conversations submenu under Agents.
	 */
	public function add_menu() {
		add_submenu_page(
			'edit.php?post_type=arwp_agent',
			__( 'Chats', 'arwp-agents' ),
			__( 'Chats', 'arwp-agents' ),
			'manage_options',
			'arwp-conversations',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Delete a session and its messages.
	 */
	public function handle_delete_session() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete conversations.', 'arwp-agents' ) );
		}

		check_admin_referer( 'arwp_delete_session' );

		$session_id = isset( $_GET['session_id'] ) ? intval( $_GET['session_id'] ) : 0;
		if ( $session_id ) {
			global $wpdb;
			$table_sessions = $wpdb->prefix . 'arwp_sessions';
			$table_messages = $wpdb->prefix . 'arwp_messages';
			$wpdb->delete( $table_messages, array( 'session_id' => $session_id ), array( '%d' ) );
			$wpdb->delete( $table_sessions, array( 'id' => $session_id ), array( '%d' ) );
		}

		$redirect = admin_url( 'edit.php?post_type=arwp_agent&page=arwp-conversations' );
		if ( ! empty( $_GET['agent_id'] ) ) {
			$redirect = add_query_arg( 'agent_id', intval( $_GET['agent_id'] ), $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render list or single-session view.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Read-only admin screen navigation (which session to view / which page); intval()-cast, no data is written.
		$session_id = isset( $_GET['session_id'] ) ? intval( $_GET['session_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $session_id ) {
			$this->render_session_view( $session_id );
			return;
		}

		$this->render_list_view();
	}

	/**
	 * Paginated session list.
	 */
	private function render_list_view() {
		global $wpdb;

		$table_sessions = $wpdb->prefix . 'arwp_sessions';
		$table_messages = $wpdb->prefix . 'arwp_messages';
		$per_page       = 20;
		// Read-only pagination/filter params, intval()-cast, no data is written.
		$paged        = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset       = ( $paged - 1 ) * $per_page;
		$agent_filter = isset( $_GET['agent_id'] ) ? intval( $_GET['agent_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$where  = '1=1';
		$params = array();
		if ( $agent_filter ) {
			$where   .= ' AND s.agent_id = %d';
			$params[] = $agent_filter;
		}

		// $where only ever concatenates the static ' AND s.agent_id = %d' fragment above; the value itself is always bound through prepare() via $count_params, never interpolated raw.
		$count_sql    = "SELECT COUNT(*) FROM %i s WHERE $where"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_params = array_merge( array( $table_sessions ), $params );
		$total        = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total_pages  = max( 1, (int) ceil( $total / $per_page ) );

		// Same $where fragment as above -- safe, values bound via $list_params, never interpolated raw.
		$list_sql = "SELECT s.*, 
			(SELECT COUNT(*) FROM %i m WHERE m.session_id = s.id AND m.role != 'system') AS message_count,
			(SELECT COALESCE(SUM(tokens_used),0) FROM %i m WHERE m.session_id = s.id) AS tokens_used
			FROM %i s
			WHERE $where
			ORDER BY s.created_at DESC
			LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$list_params   = array_merge( array( $table_messages, $table_messages, $table_sessions ), $params );
		$list_params[] = $per_page;
		$list_params[] = $offset;
		$sessions      = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$agents = get_posts(
			array(
				'post_type'      => 'arwp_agent',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Chats', 'arwp-agents' ); ?></h1>
			<p class="arwp-admin-help"><?php esc_html_e( 'Every conversation visitors have with your agents.', 'arwp-agents' ); ?></p>
			<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" style="margin: 16px 0;">
				<input type="hidden" name="post_type" value="arwp_agent" />
				<input type="hidden" name="page" value="arwp-conversations" />
				<label for="arwp_agent_filter" class="screen-reader-text"><?php esc_html_e( 'Filter by agent', 'arwp-agents' ); ?></label>
				<select name="agent_id" id="arwp_agent_filter">
					<option value="0"><?php esc_html_e( 'All Agents', 'arwp-agents' ); ?></option>
					<?php foreach ( $agents as $agent ) : ?>
						<option value="<?php echo esc_attr( $agent->ID ); ?>" <?php selected( $agent_filter, $agent->ID ); ?>>
							<?php echo esc_html( $agent->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Filter', 'arwp-agents' ), 'secondary', '', false ); ?>
			</form>

			<p class="description">
				<?php
				printf(
					/* translators: %d: total conversation count */
					esc_html( _n( '%d conversation', '%d conversations', $total, 'arwp-agents' ) ),
					intval( $total )
				);
				?>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'arwp-agents' ); ?></th>
						<th><?php esc_html_e( 'Agent', 'arwp-agents' ); ?></th>
						<th><?php esc_html_e( 'User', 'arwp-agents' ); ?></th>
						<th><?php esc_html_e( 'Messages', 'arwp-agents' ); ?></th>
						<th><?php esc_html_e( 'Tokens', 'arwp-agents' ); ?></th>
						<th><?php esc_html_e( 'Created', 'arwp-agents' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'arwp-agents' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $sessions ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No conversations yet.', 'arwp-agents' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $sessions as $session ) : ?>
							<?php
							$view_url   = add_query_arg(
								array(
									'post_type'  => 'arwp_agent',
									'page'       => 'arwp-conversations',
									'session_id' => $session->id,
								),
								admin_url( 'edit.php' )
							);
							$delete_url = wp_nonce_url(
								add_query_arg(
									array(
										'action'     => 'arwp_delete_session',
										'session_id' => $session->id,
										'agent_id'   => $agent_filter,
									),
									admin_url( 'admin-post.php' )
								),
								'arwp_delete_session'
							);
							$user_label = intval( $session->user_id ) > 0 ? $this->user_label( $session->user_id ) : __( 'Guest', 'arwp-agents' );
							$chat_title = arwp_redact_personal_details( $session->title ? $session->title : __( 'Untitled', 'arwp-agents' ) );
							?>
							<tr>
								<td>
									<a href="<?php echo esc_url( $view_url ); ?>">
										<strong><?php echo esc_html( $chat_title ); ?></strong>
									</a>
								</td>
								<td>
									<?php
									$agent_title = get_the_title( $session->agent_id );
									echo esc_html( $agent_title ? $agent_title : '#' . intval( $session->agent_id ) );
									?>
								</td>
								<td><?php echo esc_html( $user_label ); ?></td>
								<td><?php echo esc_html( intval( $session->message_count ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( intval( $session->tokens_used ) ) ); ?></td>
								<td><?php echo esc_html( $session->created_at ); ?></td>
								<td>
									<a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View', 'arwp-agents' ); ?></a>
									|
									<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this conversation and all of its messages?', 'arwp-agents' ) ); ?>');"><?php esc_html_e( 'Delete', 'arwp-agents' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
									'total'     => $total_pages,
									'current'   => $paged,
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Single conversation transcript.
	 *
	 * @param int $session_id Session ID.
	 */
	private function render_session_view( $session_id ) {
		global $wpdb;

		$table_sessions = $wpdb->prefix . 'arwp_sessions';
		$table_messages = $wpdb->prefix . 'arwp_messages';

		$session = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table_sessions, $session_id ) );
		if ( ! $session ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Conversation not found.', 'arwp-agents' ) . '</h1></div>';
			return;
		}

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %d ORDER BY id ASC',
				$table_messages,
				$session_id
			)
		);

		$back_url   = admin_url( 'edit.php?post_type=arwp_agent&page=arwp-conversations' );
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'arwp_delete_session',
					'session_id' => $session->id,
				),
				admin_url( 'admin-post.php' )
			),
			'arwp_delete_session'
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html( arwp_redact_personal_details( $session->title ? $session->title : __( 'Untitled', 'arwp-agents' ) ) ); ?></h1>
			<p>
				<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'All chats', 'arwp-agents' ); ?></a>
				|
				<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this conversation and all of its messages?', 'arwp-agents' ) ); ?>');"><?php esc_html_e( 'Delete', 'arwp-agents' ); ?></a>
			</p>
			<table class="widefat striped" style="max-width:960px;">
				<tbody>
					<tr><th style="width:160px;"><?php esc_html_e( 'Agent', 'arwp-agents' ); ?></th><td><?php echo esc_html( get_the_title( $session->agent_id ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'User', 'arwp-agents' ); ?></th><td><?php echo esc_html( intval( $session->user_id ) > 0 ? $this->user_label( $session->user_id ) : __( 'Guest', 'arwp-agents' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Created', 'arwp-agents' ); ?></th><td><?php echo esc_html( $session->created_at ); ?></td></tr>
				</tbody>
			</table>

			<h2 style="margin-top:24px;"><?php esc_html_e( 'Messages', 'arwp-agents' ); ?></h2>
			<?php foreach ( $messages as $msg ) : ?>
				<div style="max-width:960px;margin:12px 0;padding:12px 16px;border:1px solid #dcdcde;border-radius:6px;background:<?php echo 'user' === $msg->role ? '#f0f6fc' : ( 'system' === $msg->role ? '#f6f7f7' : '#fff' ); ?>;">
					<p style="margin:0 0 8px;font-size:12px;color:#646970;">
						<strong><?php echo esc_html( ucfirst( $msg->role ) ); ?></strong>
						&middot; <?php echo esc_html( $msg->created_at ); ?>
						<?php if ( intval( $msg->tokens_used ) ) : ?>
							<?php /* translators: %d: number of tokens used by this message. */ ?>
							&middot; <?php echo esc_html( sprintf( __( '%d tokens', 'arwp-agents' ), intval( $msg->tokens_used ) ) ); ?>
						<?php endif; ?>
					</p>
					<div style="white-space:pre-wrap;word-break:break-word;"><?php echo esc_html( arwp_redact_personal_details( $msg->content ) ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Display name for a WP user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function user_label( $user_id ) {
		$user_id = intval( $user_id );
		if ( '1' !== (string) get_option( 'arwp_chats_show_details', '0' ) ) {
			return $user_id > 0 ? __( 'Logged-in visitor', 'arwp-agents' ) : __( 'Guest', 'arwp-agents' );
		}

		if ( $user_id <= 0 ) {
			return __( 'Guest', 'arwp-agents' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '#' . $user_id;
		}

		return $user->display_name . ' (' . $user->user_login . ')';
	}
}
