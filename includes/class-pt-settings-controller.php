<?php
/**
 * REST controller for settings endpoints.
 *
 * Replaces AJAX handlers that previously used a nonce exposed in
 * page-localized JS. All endpoints now use the standard WP REST
 * nonce (X-WP-Nonce header), which is the canonical CSRF protection
 * mechanism for WordPress REST APIs.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Settings_Controller {

	/**
	 * Singleton instance.
	 *
	 * @var PT_Settings_Controller|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return PT_Settings_Controller
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
		// Save / remove API key.
		register_rest_route( 'pt/v1', '/settings/api-key', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => array( 'PT_Security', 'rest_permission' ),
			'callback'            => array( $this, 'save_api_key' ),
			'args'                => array(
				'api_key' => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// Validate API key.
		register_rest_route( 'pt/v1', '/settings/validate-key', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => array( 'PT_Security', 'rest_permission' ),
			'callback'            => array( $this, 'validate_api_key' ),
		) );

		// Fetch available models.
		register_rest_route( 'pt/v1', '/settings/models', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => array( 'PT_Security', 'rest_permission' ),
			'callback'            => array( $this, 'fetch_models' ),
		) );
	}

	/**
	 * Save or remove the OpenRouter API key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_api_key( WP_REST_Request $request ) {
		$size_check = PT_Security::check_request_size( $request, 10240 ); // 10KB max for settings.
		if ( is_wp_error( $size_check ) ) {
			return $size_check;
		}

		$key = $request->get_param( 'api_key' );

		if ( empty( $key ) ) {
			delete_option( PT_Settings::API_KEY_OPTION );
			return rest_ensure_response( array(
				'message' => __( 'API key removed.', 'tracewp' ),
			) );
		}

		if ( ! PT_Crypto::is_available() ) {
			return new WP_Error(
				'pt_no_openssl',
				__( 'OpenSSL PHP extension is required to store API keys securely. Contact your hosting provider to enable it.', 'tracewp' ),
				array( 'status' => 500 )
			);
		}

		$encrypted = PT_Crypto::encrypt( $key );
		if ( empty( $encrypted ) ) {
			return new WP_Error(
				'pt_encrypt_failed',
				__( 'Encryption failed. Check that your wp-config.php AUTH_KEY is set.', 'tracewp' ),
				array( 'status' => 500 )
			);
		}

		update_option( PT_Settings::API_KEY_OPTION, $encrypted );

		return rest_ensure_response( array(
			'message' => __( 'API key saved.', 'tracewp' ),
			'masked'  => PT_Settings::instance()->get_masked_key(),
		) );
	}

	/**
	 * Validate the stored API key against OpenRouter.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_api_key() {
		$key = PT_Settings::instance()->get_api_key();
		if ( empty( $key ) ) {
			return new WP_Error(
				'pt_no_key',
				__( 'No API key configured.', 'tracewp' ),
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_get(
			'https://openrouter.ai/api/v1/auth/key',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pt_connection_error', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new WP_Error(
				'pt_invalid_key',
				401 === $code ? __( 'Invalid API key.', 'tracewp' ) : sprintf( __( 'OpenRouter returned HTTP %d.', 'tracewp' ), $code ),
				array( 'status' => $code )
			);
		}

		return rest_ensure_response( array(
			'message' => __( 'Connected', 'tracewp' ),
			'data'    => $body['data'] ?? array(),
		) );
	}

	/**
	 * Fetch available models from OpenRouter.
	 *
	 * Caches the model list in a transient for 1 hour.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function fetch_models() {
		// Check cache first.
		$cached = get_transient( 'pt_openrouter_models' );
		if ( false !== $cached ) {
			return rest_ensure_response( array( 'models' => $cached ) );
		}

		$key = PT_Settings::instance()->get_api_key();
		if ( empty( $key ) ) {
			return new WP_Error(
				'pt_no_key',
				__( 'No API key configured.', 'tracewp' ),
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_get(
			'https://openrouter.ai/api/v1/models',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pt_connection_error', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();

		if ( ! empty( $body['data'] ) && is_array( $body['data'] ) ) {
			foreach ( $body['data'] as $model ) {
				$models[] = array(
					'id'             => $model['id'] ?? '',
					'name'           => $model['name'] ?? $model['id'] ?? '',
					'context_length' => (int) ( $model['context_length'] ?? 0 ),
					'pricing'        => array(
						'prompt'     => (float) ( $model['pricing']['prompt'] ?? 0 ),
						'completion' => (float) ( $model['pricing']['completion'] ?? 0 ),
					),
					'supports_vision' => ! empty( $model['architecture']['modality'] ) && 'multimodal' === $model['architecture']['modality'],
				);
			}

			usort( $models, static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			} );
		}

		// Cache for 1 hour.
		set_transient( 'pt_openrouter_models', $models, HOUR_IN_SECONDS );

		return rest_ensure_response( array( 'models' => $models ) );
	}
}