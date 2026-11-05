<?php
/**
 * Custom REST API endpoint and OpenRouter API integration.
 *
 * @package    ARWP_Agents
 * @subpackage ARWP_Agents/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom chat tables have no core API. Caching would serve stale messages.

/**
 * REST chat endpoints and the OpenRouter client.
 */
class ARWP_API {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		$nonce_perm = array( $this, 'verify_request_nonce' );

		register_rest_route(
			'arwp/v1',
			'/chat-stream',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_chat_stream_request' ),
				'permission_callback' => $nonce_perm,
			)
		);

		register_rest_route(
			'arwp/v1',
			'/history',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_history_request' ),
				'permission_callback' => $nonce_perm,
			)
		);

		// Public on purpose: a full-page cache can serve a stale nonce, and the
		// browser needs a way to pick up a fresh one without a reload.
		register_rest_route(
			'arwp/v1',
			'/nonce',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_nonce_request' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verify Nonce for API Request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function verify_request_nonce( $request ) {
		$nonce = $request->get_param( 'nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
		}

		if ( wp_verify_nonce( $nonce, 'arwp_chat_nonce' ) || wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		return new WP_Error(
			'arwp_forbidden',
			__( 'Security check failed. Invalid nonce.', 'arwp-agents' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Issue a fresh chat nonce for pages served from a full-page cache.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_nonce_request() {
		// WordPress answers REST routes with the caller's Origin plus
		// Access-Control-Allow-Credentials, so without this check a foreign page
		// could read a nonce that is valid for the visitor's session.
		$origin = get_http_origin();
		if ( $origin ) {
			$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
			$home_host   = wp_parse_url( home_url(), PHP_URL_HOST );

			if ( ! $origin_host || strtolower( $origin_host ) !== strtolower( (string) $home_host ) ) {
				return new WP_Error(
					'arwp_bad_origin',
					__( 'Cross-origin requests are not allowed.', 'arwp-agents' ),
					array( 'status' => 403 )
				);
			}
		}

		$response = new WP_REST_Response(
			array(
				'success' => true,
				'nonce'   => wp_create_nonce( 'arwp_chat_nonce' ),
			),
			200
		);

		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'Vary', 'Origin' );

		return $response;
	}

	/**
	 * Stream chat tokens to the browser via SSE.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error|void
	 */
	public function handle_chat_stream_request( $request ) {
		$ctx = $this->prepare_chat_context( $request );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$this->stream_openrouter( $ctx );
		exit;
	}

	/**
	 * Validate request, persist the user turn, and build the OpenRouter payload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function prepare_chat_context( $request ) {
		global $wpdb;

		$agent_id      = intval( $request->get_param( 'agent_id' ) );
		$session_token = sanitize_text_field( (string) $request->get_param( 'session_token' ) );
		$new_message   = sanitize_textarea_field( (string) $request->get_param( 'new_message' ) );

		if ( ! $agent_id ) {
			return new WP_Error( 'arwp_invalid_agent', __( 'Agent ID is required.', 'arwp-agents' ), array( 'status' => 400 ) );
		}

		$agent_post = get_post( $agent_id );
		if ( ! $agent_post || 'arwp_agent' !== $agent_post->post_type ) {
			return new WP_Error( 'arwp_agent_not_found', __( 'Agent not found.', 'arwp-agents' ), array( 'status' => 404 ) );
		}

		if ( 'publish' !== $agent_post->post_status && ! current_user_can( 'edit_post', $agent_id ) ) {
			return new WP_Error( 'arwp_agent_not_found', __( 'Agent not found.', 'arwp-agents' ), array( 'status' => 404 ) );
		}

		$access_error = $this->access_denied_error( $agent_id );
		if ( is_wp_error( $access_error ) ) {
			return $access_error;
		}

		$max_chars = arwp_max_message_chars();
		$msg_chars = function_exists( 'mb_strlen' ) ? mb_strlen( $new_message ) : strlen( $new_message );
		if ( $max_chars > 0 && $msg_chars > $max_chars ) {
			return new WP_Error(
				'arwp_message_too_long',
				sprintf(
					/* translators: %d: character limit */
					__( 'Messages are limited to %d characters.', 'arwp-agents' ),
					$max_chars
				),
				array( 'status' => 400 )
			);
		}

		if ( '' === trim( $new_message ) ) {
			return new WP_Error( 'arwp_empty_message', __( 'Message content cannot be empty.', 'arwp-agents' ), array( 'status' => 400 ) );
		}

		if ( arwp_message_is_blocked( $agent_id, $new_message ) ) {
			return new WP_Error(
				'arwp_message_blocked',
				__( 'This message can\'t be sent.', 'arwp-agents' ),
				array( 'status' => 400 )
			);
		}

		$system_prompt = get_post_meta( $agent_id, '_arwp_system_prompt', true );
		$model         = get_post_meta( $agent_id, '_arwp_model', true );
		$temperature   = get_post_meta( $agent_id, '_arwp_temperature', true );
		$max_tokens    = get_post_meta( $agent_id, '_arwp_max_tokens', true );
		$cooldown      = get_post_meta( $agent_id, '_arwp_cooldown', true );

		if ( '' === $temperature ) {
			$temperature = 0.7;
		}
		if ( '' === $max_tokens ) {
			$max_tokens = 1000;
		}

		$cooldown   = arwp_meta_int( $cooldown, arwp_default_cooldown() );
		$rate_ip    = arwp_meta_int( get_post_meta( $agent_id, '_arwp_rate_limit_per_ip', true ), arwp_default_rate_limit_per_ip() );
		$rate_agent = arwp_meta_int( get_post_meta( $agent_id, '_arwp_rate_limit_per_agent', true ), arwp_default_rate_limit_per_agent() );

		if ( ! $model || 'default' === $model ) {
			$model = get_option( 'arwp_openrouter_default_model', 'openai/gpt-4o' );
			if ( empty( $model ) ) {
				$model = 'openai/gpt-4o';
			}
		}

		$temperature = floatval( $temperature );
		$max_tokens  = intval( $max_tokens );

		$session_id     = 0;
		$table_sessions = $wpdb->prefix . 'arwp_sessions';
		$table_messages = $wpdb->prefix . 'arwp_messages';

		if ( ! empty( $session_token ) ) {
			$session = $this->get_owned_session( $agent_id, $session_token );

			if ( $session ) {
				$session_id = intval( $session->id );
			} else {
				$session_token = '';
			}
		}

		if ( $session_id > 0 && $cooldown > 0 ) {
			$last_msg = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT created_at FROM %i WHERE session_id = %d ORDER BY id DESC LIMIT 1',
					$table_messages,
					$session_id
				)
			);

			if ( $last_msg ) {
				$last_time = strtotime( $last_msg->created_at );
				$now_time  = strtotime( current_time( 'mysql' ) );
				$elapsed   = $now_time - $last_time;

				if ( $elapsed < $cooldown ) {
					$wait_time = $cooldown - $elapsed;
					return new WP_Error(
						'arwp_cooldown_active',
						/* translators: %d: number of seconds to wait. */
						sprintf( __( 'Please wait %d seconds before sending another message.', 'arwp-agents' ), $wait_time ),
						array(
							'status'    => 429,
							'wait_time' => $wait_time,
						)
					);
				}
			}
		}

		// After cooldown, so a too-soon retry does not consume the hourly quota.
		$limited = $this->enforce_rate_limits( $agent_id, $rate_ip, $rate_agent );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( empty( $session_token ) || 0 === $session_id ) {
			try {
				$session_token = bin2hex( random_bytes( 32 ) );
			} catch ( Exception $e ) {
				$session_token = wp_generate_password( 64, false );
			}

			$user_id   = get_current_user_id();
			$now_mysql = current_time( 'mysql' );

			$title = wp_trim_words( $new_message, 5, '...' );
			if ( empty( $title ) ) {
				$title = __( 'New Chat', 'arwp-agents' );
			}

			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (agent_id, user_id, session_token, title, created_at) VALUES (%d, %d, %s, %s, %s)',
					$table_sessions,
					$agent_id,
					$user_id,
					$session_token,
					$title,
					$now_mysql
				)
			);

			$session_id = intval( $wpdb->insert_id );

			if ( ! empty( $system_prompt ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (session_id, role, content, tokens_used, created_at) VALUES (%d, %s, %s, %d, %s)',
						$table_messages,
						$session_id,
						'system',
						$system_prompt,
						0,
						$now_mysql
					)
				);
			}
		}

		$now_mysql = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (session_id, role, content, tokens_used, created_at) VALUES (%d, %s, %s, %d, %s)',
				$table_messages,
				$session_id,
				'user',
				$new_message,
				0,
				$now_mysql
			)
		);

		$history_limit = get_post_meta( $agent_id, '_arwp_history_context_limit', true );
		$history_limit = ( '' !== $history_limit && intval( $history_limit ) > 0 ) ? intval( $history_limit ) : arwp_default_history_limit();

		// Fetch newest first so an old conversation never loads (or bills for) its whole history.
		$messages_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM %i WHERE session_id = %d AND role != 'system' ORDER BY id DESC LIMIT %d",
				$table_messages,
				$session_id,
				$history_limit
			),
			ARRAY_A
		);
		$messages_data = array_reverse( $messages_data );

		$messages_payload = array();
		foreach ( $messages_data as $msg ) {
			$messages_payload[] = array(
				'role'    => $msg['role'],
				'content' => $msg['content'],
			);
		}

		if ( ! empty( $system_prompt ) ) {
			array_unshift(
				$messages_payload,
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				)
			);
		}

		$openrouter_payload = $this->build_openrouter_payload(
			array(
				'model'       => $model,
				'messages'    => $messages_payload,
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
			)
		);

		$api_key = class_exists( 'ARWP_Settings' ) ? ARWP_Settings::get_api_key() : '';
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'arwp_missing_api_key',
				__( 'The OpenRouter API key has not been configured in the plugin settings.', 'arwp-agents' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'agent_id'      => $agent_id,
			'session_id'    => $session_id,
			'session_token' => $session_token,
			'payload'       => $openrouter_payload,
			'api_key'       => $api_key,
		);
	}

	/**
	 * 403 when this visitor is not allowed to use the agent.
	 *
	 * @param int $agent_id Agent post ID.
	 * @return WP_Error|null
	 */
	private function access_denied_error( $agent_id ) {
		if ( arwp_visitor_can_use_agent( $agent_id ) ) {
			return null;
		}

		if ( 'login' === arwp_agent_access_state( $agent_id ) ) {
			return new WP_Error(
				'arwp_access_denied',
				__( 'Log in to use this chat.', 'arwp-agents' ),
				array( 'status' => 403 )
			);
		}

		return new WP_Error(
			'arwp_access_denied',
			__( 'You do not have access to this chat.', 'arwp-agents' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Hide upstream API details from visitors. Administrators still see the real message.
	 *
	 * @param string $message Detailed error.
	 * @return string
	 */
	public static function visitor_facing_error( $message ) {
		if ( current_user_can( 'manage_options' ) ) {
			return $message;
		}

		return __( 'The assistant is unavailable right now. Please try again in a moment.', 'arwp-agents' );
	}

	/**
	 * Apply per-visitor and per-agent hourly caps.
	 *
	 * @param int $agent_id   Agent post ID.
	 * @param int $ip_limit   Max turns per IP this hour. 0 = unlimited.
	 * @param int $agent_limit Max turns for the agent this hour. 0 = unlimited.
	 * @return true|WP_Error
	 */
	public function enforce_rate_limits( $agent_id, $ip_limit, $agent_limit ) {
		$agent_id = intval( $agent_id );
		$ip       = $this->visitor_ip();

		// No address means we cannot tell visitors apart. Counting them together
		// would lock the whole site out of the per-visitor cap.
		if ( '' !== $ip ) {
			$ip_hit = self::hit_rate_bucket(
				'arwp_rl_ip_' . $agent_id . '_' . self::hash_visitor( $ip ),
				$ip_limit,
				__( 'You have sent too many messages. Please wait before trying again.', 'arwp-agents' )
			);
			if ( is_wp_error( $ip_hit ) ) {
				return $ip_hit;
			}
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$user_message = __( 'You have sent too many messages. Please wait before trying again.', 'arwp-agents' );
			$user_limit   = arwp_meta_int( get_post_meta( $agent_id, '_arwp_rate_limit_per_user', true ), 0 );
			$user_hit     = self::hit_rate_bucket(
				'arwp_rl_user_' . $agent_id . '_' . $user_id,
				$user_limit,
				$user_message
			);
			if ( is_wp_error( $user_hit ) ) {
				return $user_hit;
			}

			$role_limits = get_post_meta( $agent_id, '_arwp_rate_limit_per_role', true );
			if ( ! is_array( $role_limits ) ) {
				$role_limits = array();
			}

			$user = wp_get_current_user();
			foreach ( (array) $user->roles as $role ) {
				$role_slug = sanitize_key( $role );
				if ( '' === $role_slug || ! isset( $role_limits[ $role_slug ] ) ) {
					continue;
				}

				$role_limit = intval( $role_limits[ $role_slug ] );
				if ( $role_limit <= 0 ) {
					continue;
				}

				$role_hit = self::hit_rate_bucket(
					'arwp_rl_role_' . $agent_id . '_' . substr( $role_slug, 0, 40 ),
					$role_limit,
					$user_message
				);
				if ( is_wp_error( $role_hit ) ) {
					return $role_hit;
				}
			}
		}

		return self::hit_rate_bucket(
			'arwp_rl_agent_' . $agent_id,
			$agent_limit,
			__( 'This agent is receiving a lot of messages right now. Please try again shortly.', 'arwp-agents' )
		);
	}

	/**
	 * Increment a rolling one-hour bucket stored in a transient.
	 *
	 * @param string $key     Transient key.
	 * @param int    $limit   Allowed hits. 0 or less skips the check.
	 * @param string $message Error shown when the cap is reached.
	 * @return true|WP_Error
	 */
	public static function hit_rate_bucket( $key, $limit, $message ) {
		$limit = intval( $limit );
		if ( $limit <= 0 ) {
			return true;
		}

		$now    = time();
		$bucket = get_transient( $key );

		if ( ! is_array( $bucket ) || empty( $bucket['reset'] ) || intval( $bucket['reset'] ) <= $now ) {
			set_transient(
				$key,
				array(
					'count' => 1,
					'reset' => $now + HOUR_IN_SECONDS,
				),
				HOUR_IN_SECONDS
			);
			return true;
		}

		$count = intval( $bucket['count'] );
		if ( $count >= $limit ) {
			$wait = max( 1, intval( $bucket['reset'] ) - $now );
			return new WP_Error(
				'arwp_rate_limited',
				$message,
				array(
					'status'    => 429,
					'wait_time' => $wait,
				)
			);
		}

		$bucket['count'] = $count + 1;
		set_transient( $key, $bucket, max( 1, intval( $bucket['reset'] ) - $now ) );

		return true;
	}

	/**
	 * Hash a visitor IP so rate-limit keys are not raw addresses.
	 *
	 * @param string $ip Remote address.
	 * @return string
	 */
	public static function hash_visitor( $ip ) {
		$ip = is_string( $ip ) ? $ip : '';
		if ( '' === $ip ) {
			return 'unknown';
		}

		return substr( hash( 'sha256', $ip ), 0, 20 );
	}

	/**
	 * Direct client address only. Forwarded headers are spoofable.
	 *
	 * @return string
	 */
	public function visitor_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Map agent meta values onto a valid OpenRouter chat payload.
	 *
	 * @param array $args Agent sampling settings plus messages.
	 * @return array
	 */
	public function build_openrouter_payload( $args ) {
		$payload = array(
			'model'    => $args['model'],
			'messages' => $args['messages'],
		);

		$temperature            = floatval( $args['temperature'] );
		$temperature            = max( 0.0, min( 2.0, $temperature ) );
		$payload['temperature'] = $temperature;

		$max_tokens = intval( $args['max_tokens'] );
		if ( $max_tokens > 0 ) {
			$payload['max_tokens']            = $max_tokens;
			$payload['max_completion_tokens'] = $max_tokens;
		}

		return $payload;
	}

	/**
	 * Shared OpenRouter request headers.
	 *
	 * @param string $api_key API key.
	 * @return array
	 */
	private function openrouter_headers( $api_key ) {
		return array(
			'Content-Type'       => 'application/json',
			'Authorization'      => 'Bearer ' . $api_key,
			'HTTP-Referer'       => esc_url( home_url() ),
			'X-Title'            => 'ARWP Agents',
			'X-OpenRouter-Title' => 'ARWP Agents',
		);
	}

	/**
	 * Non-streaming OpenRouter call.
	 *
	 * @param array $payload Request body.
	 * @return array|WP_Error { content, tokens }
	 */
	private function dispatch_openrouter( $payload ) {
		$api_key = class_exists( 'ARWP_Settings' ) ? ARWP_Settings::get_api_key() : '';

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions', // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- OpenRouter is the product. The WordPress AI client needs WordPress 7.0.
			array(
				'timeout' => 60,
				'headers' => $this->openrouter_headers( $api_key ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'arwp_api_error',
				/* translators: %s: underlying HTTP error message. */
				sprintf( __( 'API request failed: %s', 'arwp-agents' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			$err_data = json_decode( $response_body, true );
			$err_msg  = isset( $err_data['error']['message'] ) ? $err_data['error']['message'] : __( 'An error occurred during API communication.', 'arwp-agents' );
			return new WP_Error(
				'arwp_api_http_error',
				/* translators: 1: HTTP response code. 2: error message returned by the API. */
				sprintf( __( 'OpenRouter API returned error %1$d: %2$s', 'arwp-agents' ), $response_code, $err_msg ),
				array( 'status' => $response_code )
			);
		}

		$completion_data     = json_decode( $response_body, true );
		$ai_response_content = isset( $completion_data['choices'][0]['message']['content'] ) ? $completion_data['choices'][0]['message']['content'] : '';

		$tokens_used = isset( $completion_data['usage']['total_tokens'] ) ? intval( $completion_data['usage']['total_tokens'] ) : 0;

		if ( empty( $ai_response_content ) ) {
			return new WP_Error(
				'arwp_empty_response',
				__( 'Received an empty response from the AI model.', 'arwp-agents' ),
				array( 'status' => 502 )
			);
		}

		return array(
			'content' => $ai_response_content,
			'tokens'  => $tokens_used,
		);
	}

	/**
	 * Stream OpenRouter tokens as SSE, then persist the assistant message.
	 *
	 * @param array $ctx Prepared chat context.
	 */
	private function stream_openrouter( $ctx ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );
		// Keep running after a disconnect so the partial reply still gets saved. No WP API extends execution time; guarded for hosts that disable set_time_limit().
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		$last_flush = microtime( true );

		$raw_send = function ( $line ) use ( &$last_flush ) {
			// $line is always either a wp_json_encode()'d SSE data frame or a static padding/comment string built above -- never raw third-party or user content -- so HTML-escaping would corrupt the event-stream protocol.
			echo $line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( ob_get_level() > 0 ) {
				ob_flush();
			}
			flush();
			$last_flush = microtime( true );
		};

		$send = function ( $payload ) use ( $raw_send ) {
			$raw_send( 'data: ' . wp_json_encode( $payload ) . "\n\n" );
		};

		// Comment frame: pushes the response past proxy buffers before the first token.
		$raw_send( ':' . str_repeat( ' ', 2048 ) . "\n\n" );

		if ( ! function_exists( 'curl_init' ) ) {
			$result = $this->dispatch_openrouter( $ctx['payload'] );
			if ( is_wp_error( $result ) ) {
				$send(
					array(
						'type'    => 'error',
						'message' => self::visitor_facing_error( $result->get_error_message() ),
					)
				);
				return;
			}
			$this->save_assistant_message( $ctx['session_id'], $result['content'], $result['tokens'] );
			$send(
				array(
					'type'    => 'delta',
					'content' => $result['content'],
				)
			);
			$send( $this->stream_done_payload( $ctx, $result['content'] ) );
			return;
		}

		$payload           = $ctx['payload'];
		$payload['stream'] = true;

		$full_text = '';
		$tokens    = 0;
		$buffer    = '';
		$http_code = 0;

		// Direct cURL is required here: wp_remote_get()/wp_remote_post() buffer the entire
		// response before returning, which would defeat token-by-token SSE streaming to the
		// browser. CURLOPT_WRITEFUNCTION below is what makes the live typing effect possible.
		$ch = curl_init( 'https://openrouter.ai/api/v1/chat/completions' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init, PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- wp_remote buffers the body, so streaming uses cURL.
		curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			$ch,
			array(
				CURLOPT_POST             => true,
				CURLOPT_HTTPHEADER       => array(
					'Content-Type: application/json',
					'Authorization: Bearer ' . $ctx['api_key'],
					'HTTP-Referer: ' . home_url(),
					'X-Title: ARWP Agents',
					'X-OpenRouter-Title: ARWP Agents',
				),
				CURLOPT_POSTFIELDS       => wp_json_encode( $payload ),
				CURLOPT_TIMEOUT          => 90,
				CURLOPT_CONNECTTIMEOUT   => 15,
				CURLOPT_NOPROGRESS       => false,
				// Fires about once a second even while a slow model thinks, so the
				// connection stays warm and a closed tab stops the transfer.
				CURLOPT_PROGRESSFUNCTION => function () use ( $raw_send, &$last_flush ) {
					if ( connection_aborted() ) {
						return 1;
					}

					if ( microtime( true ) - $last_flush > 15 ) {
						$raw_send( ": ping\n\n" );
					}

					return 0;
				},
				CURLOPT_WRITEFUNCTION    => function ( $ch, $data ) use ( &$full_text, &$tokens, &$buffer, $send ) {
					$buffer .= $data;
					$lines   = explode( "\n", $buffer );
					$buffer  = array_pop( $lines );

					foreach ( $lines as $line ) {
						$line = trim( $line );
						if ( '' === $line || 'data: [DONE]' === $line ) {
							continue;
						}
						if ( 0 !== strpos( $line, 'data: ' ) ) {
							continue;
						}

						$json = json_decode( substr( $line, 6 ), true );
						if ( ! is_array( $json ) ) {
							continue;
						}

						if ( ! empty( $json['error']['message'] ) ) {
							$send(
								array(
									'type'    => 'error',
									'message' => self::visitor_facing_error( $json['error']['message'] ),
								)
							);
							continue;
						}

						$delta = '';
						if ( isset( $json['choices'][0]['delta']['content'] ) ) {
							$delta = $json['choices'][0]['delta']['content'];
						}

						if ( is_array( $delta ) ) {
							$delta = isset( $delta['text'] ) ? $delta['text'] : '';
						}

						if ( '' !== $delta && is_string( $delta ) ) {
							$full_text .= $delta;
							$send(
								array(
									'type'    => 'delta',
									'content' => $delta,
								)
							);
						}

						if ( isset( $json['usage']['total_tokens'] ) ) {
							$tokens = intval( $json['usage']['total_tokens'] );
						}
					}

					return strlen( $data );
				},
			)
		);

		$ok        = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$curl_err  = curl_error( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
		$http_code = intval( curl_getinfo( $ch, CURLINFO_HTTP_CODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		if ( PHP_VERSION_ID < 80500 ) {
			curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close, Generic.PHP.DeprecatedFunctions.Deprecated -- Required before PHP 8.0 releases the handle. Deprecated in 8.5.
		}

		$ai_response = $full_text;

		if ( ! $ok || $http_code >= 400 || '' === trim( $ai_response ) ) {
			if ( '' === trim( $ai_response ) ) {
				$result = $this->dispatch_openrouter( $ctx['payload'] );
				if ( ! is_wp_error( $result ) ) {
					$this->save_assistant_message( $ctx['session_id'], $result['content'], $result['tokens'] );
					$send(
						array(
							'type'    => 'delta',
							'content' => $result['content'],
						)
					);
					$send( $this->stream_done_payload( $ctx, $result['content'] ) );
					return;
				}
				$send(
					array(
						'type'    => 'error',
						'message' => self::visitor_facing_error( $curl_err ? $curl_err : ( is_wp_error( $result ) ? $result->get_error_message() : __( 'Stream failed.', 'arwp-agents' ) ) ),
					)
				);
				return;
			}
		}

		$this->save_assistant_message( $ctx['session_id'], $ai_response, $tokens );
		$send( $this->stream_done_payload( $ctx, $ai_response ) );
	}

	/**
	 * Persist an assistant message.
	 *
	 * @param int    $session_id Session ID.
	 * @param string $content    Message body.
	 * @param int    $tokens     Token count.
	 * @return int Inserted message ID.
	 */
	private function save_assistant_message( $session_id, $content, $tokens ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (session_id, role, content, tokens_used, created_at) VALUES (%d, %s, %s, %d, %s)',
				$wpdb->prefix . 'arwp_messages',
				$session_id,
				'assistant',
				$content,
				intval( $tokens ),
				current_time( 'mysql' )
			)
		);

		return intval( $wpdb->insert_id );
	}

	/**
	 * Resolve a session the current visitor is allowed to act on.
	 *
	 * Guests may only reach guest sessions. A logged-in visitor claims a guest
	 * session they still hold the token for, which keeps a conversation started
	 * before login attached to the account afterwards. Sessions owned by a
	 * different account are never returned, even with a valid token.
	 *
	 * @param int    $agent_id      Agent ID.
	 * @param string $session_token Token.
	 * @return object|null
	 */
	private function get_owned_session( $agent_id, $session_token ) {
		global $wpdb;

		if ( '' === (string) $session_token ) {
			return null;
		}

		$table   = $wpdb->prefix . 'arwp_sessions';
		$user_id = get_current_user_id();

		$session = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_token = %s AND agent_id = %d LIMIT 1',
				$table,
				$session_token,
				$agent_id
			)
		);

		if ( ! $session ) {
			return null;
		}

		$owner = intval( $session->user_id );

		if ( 0 === $user_id ) {
			return 0 === $owner ? $session : null;
		}

		if ( $owner === $user_id ) {
			return $session;
		}

		if ( 0 === $owner ) {
			$wpdb->update(
				$table,
				array( 'user_id' => $user_id ),
				array( 'id' => intval( $session->id ) ),
				array( '%d' ),
				array( '%d' )
			);
			$session->user_id = $user_id;
			return $session;
		}

		return null;
	}

	/**
	 * SSE done event.
	 *
	 * @param array  $ctx     Prepared chat context.
	 * @param string $content Full assistant reply.
	 * @return array
	 */
	private function stream_done_payload( $ctx, $content ) {
		return array(
			'type'          => 'done',
			'session_token' => $ctx['session_token'],
			'response'      => $content,
		);
	}

	/**
	 * Retrieve message history for a given session.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_history_request( $request ) {
		global $wpdb;

		$agent_id      = intval( $request->get_param( 'agent_id' ) );
		$session_token = sanitize_text_field( $request->get_param( 'session_token' ) );

		if ( ! $agent_id || empty( $session_token ) ) {
			return new WP_Error( 'arwp_invalid_params', __( 'Agent ID and Session Token are required.', 'arwp-agents' ), array( 'status' => 400 ) );
		}

		$agent_post = get_post( $agent_id );
		if ( ! $agent_post || 'arwp_agent' !== $agent_post->post_type ) {
			return new WP_Error( 'arwp_agent_not_found', __( 'Agent not found.', 'arwp-agents' ), array( 'status' => 404 ) );
		}

		if ( 'publish' !== $agent_post->post_status && ! current_user_can( 'edit_post', $agent_id ) ) {
			return new WP_Error( 'arwp_agent_not_found', __( 'Agent not found.', 'arwp-agents' ), array( 'status' => 404 ) );
		}

		$access_error = $this->access_denied_error( $agent_id );
		if ( is_wp_error( $access_error ) ) {
			return $access_error;
		}

		$session = $this->get_owned_session( $agent_id, $session_token );
		if ( ! $session ) {
			return new WP_Error( 'arwp_session_not_found', __( 'Session not found.', 'arwp-agents' ), array( 'status' => 404 ) );
		}

		$table_messages = $wpdb->prefix . 'arwp_messages';

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, role, content FROM %i WHERE session_id = %d AND role != 'system' ORDER BY id DESC LIMIT 100",
				$table_messages,
				$session->id
			),
			ARRAY_A
		);
		$messages = array_reverse( $messages );

		foreach ( $messages as &$msg ) {
			unset( $msg['id'] );
		}
		unset( $msg );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'messages' => $messages,
			),
			200
		);
	}
}
