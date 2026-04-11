<?php
/**
 * Server-side proxy for OpenRouter AI chat requests.
 *
 * The API key never reaches the browser. This endpoint receives
 * the chat payload from the JS client, adds the key server-side,
 * forwards the request to OpenRouter, and streams the response back.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Chat_Proxy {

	/**
	 * Singleton instance.
	 *
	 * @var PT_Chat_Proxy|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return PT_Chat_Proxy
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'pt/v1',
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'chat_permission' ),
				'callback'            => array( $this, 'handle_chat' ),
				'args'                => array(
					'messages'   => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array(
							'type' => 'object',
						),
					),
					'tools'      => array(
						'type'    => 'array',
						'default' => array(),
					),
					'model'      => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'max_tokens' => array(
						'type'    => 'integer',
						'default' => 4096,
					),
				),
			)
		);
	}

	/**
	 * Permission callback for the chat proxy.
	 *
	 * Requires admin capability + stricter rate limiting.
	 *
	 * @return bool|WP_Error
	 */
	public function chat_permission() {
		$auth = PT_Security::rest_permission();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		// Stricter rate limit for AI calls: 20 requests per 60 seconds.
		return PT_Security::rate_limit( 20, 60 );
	}

	/**
	 * Handle chat proxy request.
	 *
	 * Validates input, builds the OpenRouter request, and streams
	 * the response back via server-sent events.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle_chat( WP_REST_Request $request ) {
		$api_key = PT_Settings::instance()->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'pt_no_api_key',
				__( 'No OpenRouter API key configured. Add one in Settings.', 'tracewp' ),
				array( 'status' => 400 )
			);
		}

		$settings   = PT_Settings::instance()->get();
		$free_only  = ! empty( $settings['ai_free_only'] );
		$model      = $request->get_param( 'model' );
		$messages   = $request->get_param( 'messages' );
		$tools      = $request->get_param( 'tools' );
		$max_tokens = min( $request->get_param( 'max_tokens' ), 8192 );

		// Resolve model: free-only overrides.
		if ( $free_only ) {
			$model = 'openrouter/free';
		} elseif ( empty( $model ) ) {
			$model = $settings['ai_model'] ?: 'openrouter/auto';
		}

		// Sanitize messages — only allow known roles.
		$clean_messages = $this->sanitize_messages( $messages );

		if ( empty( $clean_messages ) ) {
			return new WP_Error(
				'pt_empty_messages',
				__( 'No valid messages provided.', 'tracewp' ),
				array( 'status' => 400 )
			);
		}

		// Sanitize tool definitions — only allow known tools.
		$clean_tools = $this->sanitize_tools( $tools );

		// Build the OpenRouter request body.
		$body = array(
			'model'      => $model,
			'messages'   => $clean_messages,
			'stream'     => true,
			'max_tokens' => $max_tokens,
		);

		if ( ! empty( $clean_tools ) ) {
			$body['tools'] = $clean_tools;
		}

		// Stream directly via cURL (WP_Http doesn't support streaming responses).
		$this->stream_response( $body, $api_key );
	}

	/**
	 * Stream the OpenRouter response using server-sent events.
	 *
	 * Uses cURL directly because WP_Http doesn't support streaming.
	 * Buffers the initial response to check the HTTP status code.
	 * If non-200, returns a JSON error instead of committing to SSE.
	 * If 200, switches to streaming mode for subsequent chunks.
	 *
	 * @param array  $body    Request body.
	 * @param string $api_key OpenRouter API key.
	 */
	private function stream_response( $body, $api_key ) {
		$response_body = '';
		$streaming    = false;

		$ch = curl_init( 'https://openrouter.ai/api/v1/chat/completions' );

		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_HTTPHEADER     => array(
				'Authorization: Bearer ' . $api_key,
				'Content-Type: application/json',
				'HTTP-Referer: ' . home_url(),
				'X-Title: TraceWP',
			),
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_HEADERFUNCTION => function ( $ch, $header ) {
				// Consume headers so they don't appear in the body.
				return strlen( $header );
			},
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$streaming, &$response_body ) {
				if ( ! $streaming ) {
					// Still buffering — collect data until we know the status.
					$response_body .= $data;
					return strlen( $data );
				}

				// SSE streaming mode — pass through directly.
				echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SSE passthrough.
				if ( function_exists( 'flush' ) ) {
					flush();
				}
				return strlen( $data );
			},
			CURLOPT_RETURNTRANSFER => false,
		) );

		$result     = curl_exec( $ch );
		$http_code  = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$curl_error = curl_error( $ch );
		curl_close( $ch );

		// Handle cURL-level failures.
		if ( ! $result && $curl_error ) {
			if ( ! headers_sent() ) {
				status_header( 502 );
				header( 'Content-Type: application/json' );
			}
			echo wp_json_encode( array(
				'code'    => 'pt_proxy_error',
				'message' => 'Proxy connection failed: ' . $curl_error,
				'data'    => array( 'status' => 502 ),
			) );
			exit;
		}

		// If OpenRouter returned a non-200 and we haven't started streaming,
		// return a proper JSON error that the JS client can parse.
		if ( $http_code && 200 !== $http_code && ! $streaming ) {
			$err_body = json_decode( $response_body, true );
			$err_msg  = isset( $err_body['error']['message'] ) ? $err_body['error']['message'] : 'HTTP ' . $http_code;

			if ( ! headers_sent() ) {
				status_header( $http_code );
				header( 'Content-Type: application/json' );
			}
			echo wp_json_encode( array(
				'code'    => 'pt_openrouter_error',
				'message' => $err_msg,
				'data'    => array( 'status' => $http_code ),
			) );
			exit;
		}

		// We got a 200 — if streaming never started but we have buffered data,
		// send SSE headers and flush the buffer.
		if ( ! $streaming && ! empty( $response_body ) ) {
			while ( ob_get_level() ) {
				ob_end_clean();
			}
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			header( 'Connection: keep-alive' );
			header( 'X-Accel-Buffering: no' );

			echo $response_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SSE passthrough.
			if ( function_exists( 'flush' ) ) {
				flush();
			}
		}

		exit;
	}

	/**
	 * Sanitize messages array — only allow known roles.
	 *
	 * @param array $messages Raw messages.
	 * @return array
	 */
	private function sanitize_messages( $messages ) {
		$clean = array();
		if ( ! is_array( $messages ) ) {
			return $clean;
		}

		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) || empty( $msg['role'] ) ) {
				continue;
			}

			$role = sanitize_text_field( $msg['role'] );
			if ( ! in_array( $role, array( 'system', 'user', 'assistant', 'tool' ), true ) ) {
				continue;
			}

			$item = array( 'role' => $role );

			// Handle content (string or array for multimodal).
			if ( isset( $msg['content'] ) ) {
				if ( is_string( $msg['content'] ) ) {
					$item['content'] = $msg['content'];
				} elseif ( is_array( $msg['content'] ) ) {
					$item['content'] = $this->sanitize_content_parts( $msg['content'] );
				}
			}

			// Tool calls from assistant.
			if ( 'assistant' === $role && ! empty( $msg['tool_calls'] ) && is_array( $msg['tool_calls'] ) ) {
				$item['tool_calls'] = $this->sanitize_tool_calls( $msg['tool_calls'] );
			}

			// Tool response.
			if ( 'tool' === $role && ! empty( $msg['tool_call_id'] ) ) {
				$item['tool_call_id'] = sanitize_text_field( $msg['tool_call_id'] );
				if ( isset( $msg['content'] ) ) {
					$item['content'] = is_string( $msg['content'] ) ? $msg['content'] : wp_json_encode( $msg['content'] );
				}
			}

			$clean[] = $item;
		}

		return $clean;
	}

	/**
	 * Sanitize tool definitions — only allow known tools.
	 *
	 * @param array $tools Raw tool definitions.
	 * @return array
	 */
	private function sanitize_tools( $tools ) {
		$clean = array();
		if ( ! is_array( $tools ) ) {
			return $clean;
		}

		$allowed_tools = array(
			'read_file', 'list_directory', 'search_files',
			'get_option', 'fetch_page_html', 'get_template_hierarchy',
			'get_active_theme_files',
		);

		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) || empty( $tool['function']['name'] ) ) {
				continue;
			}

			$name = sanitize_text_field( $tool['function']['name'] );
			if ( ! in_array( $name, $allowed_tools, true ) ) {
				continue;
			}

			$clean[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $name,
					'description' => isset( $tool['function']['description'] ) ? sanitize_text_field( $tool['function']['description'] ) : '',
					'parameters'  => isset( $tool['function']['parameters'] ) && is_array( $tool['function']['parameters'] )
						? $tool['function']['parameters']
						: array( 'type' => 'object', 'properties' => array() ),
				),
			);
		}

		return $clean;
	}

	/**
	 * Sanitize multimodal content parts (text + image_url).
	 *
	 * @param array $parts Content parts.
	 * @return array
	 */
	private function sanitize_content_parts( $parts ) {
		$clean = array();
		if ( ! is_array( $parts ) ) {
			return $clean;
		}

		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) || empty( $part['type'] ) ) {
				continue;
			}

			$type = sanitize_text_field( $part['type'] );

			if ( 'text' === $type && isset( $part['text'] ) ) {
				$clean[] = array(
					'type' => 'text',
					'text' => $part['text'],
				);
			} elseif ( 'image_url' === $type && ! empty( $part['image_url']['url'] ) ) {
				// Allow data URIs (base64 screenshots) and https URLs.
				$url = $part['image_url']['url'];
				if ( 0 === strpos( $url, 'data:image/' ) || 0 === strpos( $url, 'https://' ) ) {
					$clean[] = array(
						'type'      => 'image_url',
						'image_url' => array( 'url' => $url ),
					);
				}
			}
		}

		return $clean;
	}

	/**
	 * Sanitize tool_calls from assistant messages.
	 *
	 * @param array $tool_calls Raw tool calls.
	 * @return array
	 */
	private function sanitize_tool_calls( $tool_calls ) {
		$clean = array();
		if ( ! is_array( $tool_calls ) ) {
			return $clean;
		}

		$allowed_tools = array(
			'read_file', 'list_directory', 'search_files',
			'get_option', 'fetch_page_html', 'get_template_hierarchy',
			'get_active_theme_files',
		);

		foreach ( $tool_calls as $tc ) {
			if ( ! is_array( $tc ) ) {
				continue;
			}

			$name = isset( $tc['function']['name'] ) ? sanitize_text_field( $tc['function']['name'] ) : '';
			if ( ! in_array( $name, $allowed_tools, true ) ) {
				continue;
			}

			$clean[] = array(
				'id'       => isset( $tc['id'] ) ? sanitize_text_field( $tc['id'] ) : '',
				'type'     => 'function',
				'function' => array(
					'name'      => $name,
					'arguments' => isset( $tc['function']['arguments'] ) ? $tc['function']['arguments'] : '',
				),
			);
		}

		return $clean;
	}
}