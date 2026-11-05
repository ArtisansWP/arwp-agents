<?php
/**
 * Global Settings for ARWP Agents.
 *
 * @package    ARWP_Agents
 * @subpackage ARWP_Agents/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenRouter settings screen.
 */
class ARWP_Settings {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_arwp_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Enqueue admin scripts for Settings Page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( strpos( $hook, 'arwp-settings' ) === false ) {
			return;
		}
		wp_enqueue_style( 'arwp-select2-css', ARWP_URL . 'assets/vendor/select2/select2.min.css', array(), '4.1.0' );
		wp_enqueue_script( 'arwp-select2-js', ARWP_URL . 'assets/vendor/select2/select2.min.js', array( 'jquery' ), '4.1.0', true );
		wp_localize_script(
			'arwp-select2-js',
			'arwp_settings',
			array(
				'nonce'                => wp_create_nonce( 'arwp_test_connection_nonce' ),
				'enter_api_key'        => __( 'Please enter an API Key first.', 'arwp-agents' ),
				'testing'              => __( 'Testing connection...', 'arwp-agents' ),
				'request_failed'       => __( 'Request failed.', 'arwp-agents' ),
				'select_default_model' => __( '— Select Default Model —', 'arwp-agents' ),
			)
		);
		wp_add_inline_script( 'arwp-select2-js', $this->settings_script() );
	}

	/**
	 * Settings page behavior, printed with the enqueued Select2 script.
	 *
	 * @return string
	 */
	private function settings_script() {
		return <<<'JS'
jQuery(function($) {
	$('#arwp_openrouter_default_model').select2({ width: '300px' });

	var apiKeyInput = $('#arwp_openrouter_api_key');
	var testBtn = $('#arwp_test_connection_btn');
	var badge = $('#arwp_connected_badge');

	apiKeyInput.on('input', function() {
		testBtn.show();
		badge.hide();
	});

	testBtn.on('click', function() {
		var btn = $(this);
		var apiKey = apiKeyInput.val();
		var spinner = $('#arwp_test_spinner');
		var messageBox = $('#arwp_test_message');
		var modelSelect = $('#arwp_openrouter_default_model');
		var currentModel = modelSelect.val();

		if (!apiKey) {
			window.alert(arwp_settings.enter_api_key);
			return;
		}

		btn.prop('disabled', true);
		spinner.addClass('is-active');
		messageBox.css('color', '#666').text(arwp_settings.testing);

		$.post(ajaxurl, {
			action: 'arwp_test_connection',
			nonce: arwp_settings.nonce,
			api_key: apiKey
		}, function(response) {
			spinner.removeClass('is-active');

			if (response.success) {
				btn.hide();
				btn.prop('disabled', false);
				badge.css('display', 'inline-flex');
				messageBox.css('color', 'green').text(response.data.message);

				modelSelect.empty();
				modelSelect.append($('<option>', { value: '', text: arwp_settings.select_default_model }));
				$.each(response.data.models, function(id, name) {
					var opt = $('<option>', { value: String(id), text: String(name) });
					if (currentModel === id) {
						opt.prop('selected', true);
					}
					modelSelect.append(opt);
				});
				modelSelect.trigger('change');
			} else {
				btn.prop('disabled', false);
				messageBox.css('color', 'red').text('Error: ' + response.data);
			}
		}).fail(function() {
			btn.prop('disabled', false);
			spinner.removeClass('is-active');
			messageBox.css('color', 'red').text(arwp_settings.request_failed);
		});
	});
});
JS;
	}

	/**
	 * Add Settings page to Settings menu.
	 */
	public function add_settings_page() {
		add_submenu_page(
			'edit.php?post_type=arwp_agent',
			__( 'Settings', 'arwp-agents' ),
			__( 'Settings', 'arwp-agents' ),
			'manage_options',
			'arwp-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register option settings.
	 */
	public function register_settings() {
		register_setting(
			'arwp_settings_group',
			'arwp_openrouter_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'default'           => '',
			)
		);

		register_setting(
			'arwp_settings_group',
			'arwp_openrouter_default_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_section(
			'arwp_api_settings_section',
			__( 'OpenRouter API Integration', 'arwp-agents' ),
			array( $this, 'render_api_settings_section_info' ),
			'arwp-settings'
		);

		add_settings_field(
			'arwp_openrouter_api_key',
			__( 'OpenRouter API Key', 'arwp-agents' ),
			array( $this, 'render_api_key_field' ),
			'arwp-settings',
			'arwp_api_settings_section'
		);

		add_settings_field(
			'arwp_openrouter_default_model',
			__( 'Default Model', 'arwp-agents' ),
			array( $this, 'render_default_model_field' ),
			'arwp-settings',
			'arwp_api_settings_section'
		);
	}

	/**
	 * Sanitize the API Key.
	 *
	 * @param string $value Submitted key.
	 * @return string
	 */
	public function sanitize_api_key( $value ) {
		$sanitized = sanitize_text_field( trim( $value ) );
		// If the API key is changed via the settings form, reset the connection status.
		if ( get_option( 'arwp_openrouter_api_key' ) !== $sanitized ) {
			update_option( 'arwp_api_status', 'untested' );
		}
		return $sanitized;
	}

	/**
	 * Hardcoded models when OpenRouter has not been queried yet.
	 *
	 * @return array
	 */
	public static function get_fallback_models() {
		return array(
			'openai/gpt-4o'                     => 'OpenAI GPT-4o',
			'openai/gpt-4o-mini'                => 'OpenAI GPT-4o Mini',
			'anthropic/claude-sonnet-4.6'       => 'Anthropic Claude Sonnet 4.6',
			'google/gemini-2.5-flash'           => 'Google Gemini 2.5 Flash',
			'meta-llama/llama-3.3-70b-instruct' => 'Meta Llama 3.3 70B Instruct',
		);
	}

	/**
	 * Fetch the live OpenRouter model catalog.
	 *
	 * @param string $api_key OpenRouter API key.
	 * @return array|WP_Error id => name on success.
	 */
	public static function fetch_live_models( $api_key ) {
		$response = wp_remote_get(
			'https://openrouter.ai/api/v1/models', // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- The model catalog is an OpenRouter endpoint.
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error(
				'arwp_openrouter_http',
				__( 'Connection failed. Please check your API key.', 'arwp-agents' )
			);
		}

		return self::parse_models_response( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Turn an OpenRouter models JSON body into id => name.
	 *
	 * @param string $body Raw response body.
	 * @return array|WP_Error
	 */
	public static function parse_models_response( $body ) {
		$data = json_decode( (string) $body, true );

		if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return new WP_Error(
				'arwp_openrouter_body',
				__( 'Connection failed. Please check your API key.', 'arwp-agents' )
			);
		}

		$fetched_models = array();
		foreach ( $data['data'] as $model ) {
			if ( empty( $model['id'] ) ) {
				continue;
			}
			$id                    = $model['id'];
			$name                  = isset( $model['name'] ) && ! empty( $model['name'] ) ? $model['name'] : $id;
			$fetched_models[ $id ] = $name;
		}

		if ( empty( $fetched_models ) ) {
			return new WP_Error(
				'arwp_openrouter_empty',
				__( 'Connection failed. Please check your API key.', 'arwp-agents' )
			);
		}

		asort( $fetched_models );

		return $fetched_models;
	}

	/**
	 * Cached OpenRouter models, or the fallback list.
	 *
	 * @param bool   $force_refresh Bypass the transient.
	 * @param string $api_key       Optional key override.
	 * @return array
	 */
	public static function get_models( $force_refresh = false, $api_key = '' ) {
		$cache_key     = 'arwp_openrouter_models';
		$cached_models = get_transient( $cache_key );

		if ( ! $force_refresh && false !== $cached_models && is_array( $cached_models ) ) {
			return $cached_models;
		}

		if ( empty( $api_key ) ) {
			$api_key = get_option( 'arwp_openrouter_api_key', '' );
		}

		if ( empty( $api_key ) ) {
			return self::get_fallback_models();
		}

		$fetched_models = self::fetch_live_models( $api_key );
		if ( is_wp_error( $fetched_models ) ) {
			return self::get_fallback_models();
		}

		set_transient( $cache_key, $fetched_models, WEEK_IN_SECONDS );

		return $fetched_models;
	}

	/**
	 * Render settings section explanation.
	 */
	public function render_api_settings_section_info() {
		echo '<p>' . esc_html__( 'Please enter your OpenRouter credentials to connect your agents. You can manage and retrieve API keys from your OpenRouter dashboard.', 'arwp-agents' ) . '</p>';
	}

	/**
	 * Render API Key field in settings.
	 */
	public function render_api_key_field() {
		$api_key      = get_option( 'arwp_openrouter_api_key', '' );
		$status       = get_option( 'arwp_api_status', 'untested' );
		$is_connected = ( 'connected' === $status && ! empty( $api_key ) );
		?>
		<div style="display:flex; align-items:center; gap:10px;">
			<input type="password" name="arwp_openrouter_api_key" id="arwp_openrouter_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" style="width: 400px;" />
			
			<button type="button" class="button button-secondary" id="arwp_test_connection_btn" style="display: <?php echo $is_connected ? 'none' : 'inline-block'; ?>;">
				<?php esc_html_e( 'Test Connection', 'arwp-agents' ); ?>
			</button>
			
			<span id="arwp_connected_badge" style="display: <?php echo $is_connected ? 'inline-flex' : 'none'; ?>; align-items:center; gap:4px; color: #10b981; font-weight: 600;">
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
				<?php esc_html_e( 'Connected', 'arwp-agents' ); ?>
			</span>
			
			<span class="spinner" id="arwp_test_spinner" style="float:none; margin:0;"></span>
		</div>
		<p class="description" id="arwp_test_message"><?php esc_html_e( 'Securely stores your OpenRouter API Key.', 'arwp-agents' ); ?></p>
		<?php
	}

	/**
	 * Render Default Model field.
	 */
	public function render_default_model_field() {
		$default_model = get_option( 'arwp_openrouter_default_model', '' );
		$models        = self::get_models();
		?>
		<select name="arwp_openrouter_default_model" id="arwp_openrouter_default_model" style="min-width: 300px;">
			<option value=""><?php esc_html_e( '&mdash; Select Default Model &mdash;', 'arwp-agents' ); ?></option>
			<?php foreach ( $models as $id => $name ) : ?>
				<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $default_model, $id ); ?>><?php echo esc_html( $name ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'This model will be used if an agent is set to "Use Global Default Model". Models are fetched and cached automatically.', 'arwp-agents' ); ?></p>
		<?php
	}

	/**
	 * Render settings page view.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="arwp-admin-help"><?php esc_html_e( 'Connect your OpenRouter API key so agents can generate replies.', 'arwp-agents' ); ?></p>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'arwp_settings_group' );
				do_settings_sections( 'arwp-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * AJAX handler for testing OpenRouter connection and fetching models.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'arwp_test_connection_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'arwp-agents' ) );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( empty( $api_key ) ) {
			wp_send_json_error( __( 'Please enter an API Key first.', 'arwp-agents' ) );
		}

		$models = self::fetch_live_models( $api_key );
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( $models->get_error_message() );
		}

		update_option( 'arwp_openrouter_api_key', $api_key );
		update_option( 'arwp_api_status', 'connected' );
		set_transient( 'arwp_openrouter_models', $models, WEEK_IN_SECONDS );

		wp_send_json_success(
			array(
				'message' => __( 'Connection successful! API Key saved.', 'arwp-agents' ),
				'models'  => $models,
			)
		);
	}
}
