<?php
/**
 * Shortcode definition and frontend asset enqueuing.
 *
 * @package    ARWP_Agents
 * @subpackage ARWP_Agents/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode markup and frontend assets.
 */
class ARWP_Shortcode {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_shortcode( 'arwp', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register public CSS and JS assets.
	 */
	public function register_assets() {
		wp_register_style(
			'arwp-chat-css',
			ARWP_URL . 'public/css/arwp-chat.css',
			array(),
			filemtime( ARWP_PATH . 'public/css/arwp-chat.css' )
		);

		wp_register_script(
			'arwp-marked-js',
			ARWP_URL . 'public/js/marked.min.js',
			array(),
			'18.1.3',
			true
		);

		wp_register_script(
			'arwp-chat-js',
			ARWP_URL . 'public/js/arwp-chat.js',
			array( 'arwp-marked-js' ),
			filemtime( ARWP_PATH . 'public/js/arwp-chat.js' ),
			true
		);
	}

	/**
	 * Render Chat Interface Shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		// Normalize attributes.
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'arwp'
		);

		$agent_id = intval( $atts['id'] );

		if ( ! $agent_id ) {
			return '<p class="arwp-error">' . esc_html__( 'Error: Agent ID is missing in the shortcode.', 'arwp-agents' ) . '</p>';
		}

		$agent_post = get_post( $agent_id );
		if ( ! $agent_post || 'arwp_agent' !== $agent_post->post_type ) {
			return '<p class="arwp-error">' . esc_html__( 'Error: That agent could not be found.', 'arwp-agents' ) . '</p>';
		}

		if ( 'publish' !== $agent_post->post_status && ! current_user_can( 'edit_post', $agent_id ) ) {
			return '<p class="arwp-error">' . esc_html__( 'This chat is not available.', 'arwp-agents' ) . '</p>';
		}

		$ui = $this->chat_ui( $agent_id, $agent_post );

		wp_enqueue_style( 'arwp-chat-css' );

		if ( ! arwp_visitor_can_use_agent( $agent_id ) ) {
			return $this->render_unavailable( $agent_id, $ui );
		}

		wp_enqueue_script( 'arwp-marked-js' );
		wp_enqueue_script( 'arwp-chat-js' );

		static $localized = false;
		if ( ! $localized ) {
			wp_localize_script(
				'arwp-chat-js',
				'arwp_globals',
				array(
					'api_url'    => esc_url_raw( rest_url( 'arwp/v1/' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'chat_nonce' => wp_create_nonce( 'arwp_chat_nonce' ),
					'i18n'       => array(
						'copy'            => __( 'Copy', 'arwp-agents' ),
						'copied'          => __( 'Copied', 'arwp-agents' ),
						'copy_failed'     => __( 'Copy failed', 'arwp-agents' ),
						'send'            => __( 'Send message', 'arwp-agents' ),
						'stop'            => __( 'Stop generating', 'arwp-agents' ),
						'fullscreen'      => __( 'Enter fullscreen', 'arwp-agents' ),
						'exit_fullscreen' => __( 'Exit fullscreen', 'arwp-agents' ),
						'message'         => __( 'Message', 'arwp-agents' ),
						/* translators: %d: seconds remaining before the visitor can send again. */
						'please_wait'     => __( 'Please wait %d seconds…', 'arwp-agents' ),
					),
				)
			);
			$localized = true;
		}

		$cooldown = arwp_meta_int( get_post_meta( $agent_id, '_arwp_cooldown', true ), arwp_default_cooldown() );

		ob_start();
		?>
		<div class="arwp-chat-wrapper arwp-theme-<?php echo esc_attr( $ui['theme'] ); ?>"
			id="arwp-chat-<?php echo esc_attr( $agent_id ); ?>"
			data-agent-id="<?php echo esc_attr( $agent_id ); ?>"
			data-cooldown="<?php echo esc_attr( $cooldown ); ?>"
			data-greeting="<?php echo esc_attr( $ui['greeting'] ); ?>"
			data-suggestions="<?php echo esc_attr( wp_json_encode( $ui['suggestions'] ) ); ?>"
			style="--arwp-primary-color: <?php echo esc_attr( $ui['color'] ); ?>;">

			<div class="arwp-chat-main">
				<?php $this->render_chat_header( $agent_id, $ui ); ?>

				<div class="arwp-chat-column">
			<div class="arwp-chat-body">
				<div class="arwp-chat-messages-container"
					id="arwp-messages-<?php echo esc_attr( $agent_id ); ?>"
					role="log"
					aria-live="polite"
					aria-relevant="additions text"
					aria-label="<?php esc_attr_e( 'Conversation', 'arwp-agents' ); ?>">
					<!-- Conversation bubbles will render here -->
				</div>

				<button type="button" class="arwp-scroll-bottom-btn" hidden aria-label="<?php esc_attr_e( 'Scroll to latest message', 'arwp-agents' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
				</button>

				<div class="arwp-chat-typing-container" id="arwp-typing-<?php echo esc_attr( $agent_id ); ?>">
					<div class="arwp-chat-typing-bubble">
						<span></span>
						<span></span>
						<span></span>
					</div>
				</div>
			</div>

			<div class="arwp-chat-footer">
				<form class="arwp-chat-form" id="arwp-form-<?php echo esc_attr( $agent_id ); ?>">
					<div class="arwp-chat-input-wrapper">
						<label class="screen-reader-text" for="arwp-input-<?php echo esc_attr( $agent_id ); ?>"><?php echo esc_html( $ui['placeholder'] ); ?></label>
						<textarea class="arwp-chat-input"
							id="arwp-input-<?php echo esc_attr( $agent_id ); ?>"
							placeholder="<?php echo esc_attr( $ui['placeholder'] ); ?>"
							aria-label="<?php echo esc_attr( $ui['placeholder'] ); ?>"
							rows="1"></textarea>
						<button type="submit" class="arwp-chat-submit-btn" id="arwp-submit-<?php echo esc_attr( $agent_id ); ?>" aria-label="<?php esc_attr_e( 'Send message', 'arwp-agents' ); ?>" disabled>
							<svg class="arwp-icon-send" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<path d="M12 19V5"></path>
								<polyline points="5 12 12 5 19 12"></polyline>
							</svg>
							<svg class="arwp-icon-stop" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true">
								<rect x="7" y="7" width="10" height="10" rx="2"></rect>
							</svg>
						</button>
					</div>
				</form>
				<div class="arwp-chat-error-message" id="arwp-error-<?php echo esc_attr( $agent_id ); ?>"></div>
				<?php if ( '' !== $ui['disclaimer'] ) : ?>
					<p class="arwp-chat-disclaimer"><?php echo esc_html( $ui['disclaimer'] ); ?></p>
				<?php endif; ?>
			</div>
			</div>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Shared chat appearance values.
	 *
	 * @param int     $agent_id   Agent post ID.
	 * @param WP_Post $agent_post Agent post.
	 * @return array
	 */
	private function chat_ui( $agent_id, $agent_post ) {
		$theme       = get_post_meta( $agent_id, '_arwp_ui_theme', true );
		$title       = get_post_meta( $agent_id, '_arwp_ui_title', true );
		$greeting    = get_post_meta( $agent_id, '_arwp_ui_greeting', true );
		$placeholder = get_post_meta( $agent_id, '_arwp_ui_placeholder', true );
		$color       = sanitize_hex_color( get_post_meta( $agent_id, '_arwp_ui_primary_color', true ) );
		$description = get_post_meta( $agent_id, '_arwp_ui_description', true );
		$disclaimer  = get_post_meta( $agent_id, '_arwp_ui_disclaimer', true );
		$suggestions = get_post_meta( $agent_id, '_arwp_ui_suggestions', true );
		$logo_id     = absint( get_post_meta( $agent_id, '_arwp_ui_logo_id', true ) );
		$logo_url    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

		if ( ! in_array( $theme, array( 'light', 'dark' ), true ) ) {
			$theme = 'dark';
		}
		if ( ! is_string( $title ) || '' === $title ) {
			$title = $agent_post->post_title;
		}
		if ( ! is_string( $greeting ) ) {
			$greeting = '';
		}
		if ( ! is_string( $placeholder ) || '' === $placeholder ) {
			$placeholder = __( 'Type your message here...', 'arwp-agents' );
		}
		if ( empty( $color ) ) {
			$color = '#3b82f6';
		}
		if ( ! is_string( $description ) ) {
			$description = '';
		}
		if ( ! is_string( $disclaimer ) ) {
			$disclaimer = '';
		}
		if ( ! is_string( $logo_url ) ) {
			$logo_url = '';
		}
		if ( ! is_array( $suggestions ) ) {
			$suggestions = array();
		}

		return array(
			'theme'       => $theme,
			'title'       => $title,
			'greeting'    => $greeting,
			'placeholder' => $placeholder,
			'color'       => $color,
			'description' => $description,
			'disclaimer'  => $disclaimer,
			'logo_url'    => $logo_url,
			'suggestions' => array_values( array_filter( $suggestions, 'is_string' ) ),
		);
	}

	/**
	 * Header shared by the open chat and the denied state.
	 *
	 * @param int   $agent_id Agent post ID.
	 * @param array $ui       Appearance values from chat_ui().
	 */
	private function render_chat_header( $agent_id, $ui ) {
		?>
		<div class="arwp-chat-header">
			<div class="arwp-chat-agent-info">
				<?php if ( '' !== $ui['logo_url'] ) : ?>
					<img class="arwp-chat-logo" src="<?php echo esc_url( $ui['logo_url'] ); ?>" alt="<?php echo esc_attr( $ui['title'] ); ?>" />
				<?php endif; ?>
				<div class="arwp-chat-agent-avatar">
					<?php if ( has_post_thumbnail( $agent_id ) ) : ?>
						<img src="<?php echo esc_url( get_the_post_thumbnail_url( $agent_id, 'thumbnail' ) ); ?>" alt="<?php echo esc_attr( $ui['title'] ); ?>" />
					<?php else : ?>
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
							<circle cx="12" cy="8" r="4"></circle>
							<path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"></path>
						</svg>
					<?php endif; ?>
				</div>
				<div class="arwp-chat-agent-text">
					<h3 class="arwp-chat-agent-name"><?php echo esc_html( $ui['title'] ); ?></h3>
					<?php if ( '' !== $ui['description'] ) : ?>
						<p class="arwp-chat-description"><?php echo esc_html( $ui['description'] ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<button type="button" class="arwp-fullscreen-btn" aria-expanded="false" aria-label="<?php esc_attr_e( 'Enter fullscreen', 'arwp-agents' ); ?>">
						<svg class="arwp-icon-expand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<polyline points="15 3 21 3 21 9"></polyline>
							<polyline points="9 21 3 21 3 15"></polyline>
							<line x1="21" y1="3" x2="14" y2="10"></line>
							<line x1="3" y1="21" x2="10" y2="14"></line>
						</svg>
						<svg class="arwp-icon-collapse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<polyline points="4 14 10 14 10 20"></polyline>
							<polyline points="20 10 14 10 14 4"></polyline>
							<line x1="10" y1="14" x2="3" y2="21"></line>
							<line x1="14" y1="10" x2="21" y2="3"></line>
						</svg>
					</button>
		</div>
		<?php
	}

	/**
	 * Chat shell without a form when this visitor cannot send messages.
	 *
	 * @param int   $agent_id Agent post ID.
	 * @param array $ui       Appearance values from chat_ui().
	 * @return string
	 */
	private function render_unavailable( $agent_id, $ui ) {
		$state = arwp_agent_access_state( $agent_id );
		ob_start();
		?>
		<div class="arwp-chat-wrapper arwp-theme-<?php echo esc_attr( $ui['theme'] ); ?>"
			id="arwp-chat-<?php echo esc_attr( $agent_id ); ?>"
			data-agent-id="<?php echo esc_attr( $agent_id ); ?>"
			style="--arwp-primary-color: <?php echo esc_attr( $ui['color'] ); ?>;">
			<div class="arwp-chat-main">
				<?php $this->render_chat_header( $agent_id, $ui ); ?>
				<p class="arwp-chat-unavailable-note">
					<?php if ( 'login' === $state ) : ?>
						<?php
						$login_url = is_singular() ? wp_login_url( get_permalink() ) : wp_login_url();
						esc_html_e( 'Log in to use this chat.', 'arwp-agents' );
						?>
						<a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'arwp-agents' ); ?></a>
					<?php else : ?>
						<?php esc_html_e( 'You don\'t have access to this chat.', 'arwp-agents' ); ?>
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
