<?php
/**
 * Settings registration.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Settings {

	/**
	 * Option name for general settings.
	 */
	const OPTION_NAME = 'tracewp_settings';

	/**
	 * Option name for the encrypted API key (stored separately).
	 */
	const API_KEY_OPTION = 'tracewp_openrouter_key';

	/**
	 * Singleton instance.
	 *
	 * @var PT_Settings|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return PT_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register settings hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_pt_save_api_key', array( $this, 'ajax_save_api_key' ) );
		add_action( 'wp_ajax_pt_validate_api_key', array( $this, 'ajax_validate_api_key' ) );
		add_action( 'wp_ajax_pt_fetch_models', array( $this, 'ajax_fetch_models' ) );
	}

	/**
	 * Register the plugin setting.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'tracewp_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->defaults(),
			)
		);
	}

	/**
	 * Get defaults.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'safe_export_default' => 1,
			'inspector_admin_bar' => 1,
			'ai_model'            => '',
			'ai_free_only'        => 1,
		);
	}

	/**
	 * Fetch merged settings.
	 *
	 * @return array
	 */
	public function get() {
		return wp_parse_args( get_option( self::OPTION_NAME, array() ), $this->defaults() );
	}

	/**
	 * Check whether an API key is configured.
	 *
	 * @return bool
	 */
	public function has_api_key() {
		$encrypted = get_option( self::API_KEY_OPTION, '' );
		return ! empty( $encrypted );
	}

	/**
	 * Get the decrypted API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		$encrypted = get_option( self::API_KEY_OPTION, '' );
		return PT_Crypto::decrypt( $encrypted );
	}

	/**
	 * Get a masked version of the key for display.
	 *
	 * @return string
	 */
	public function get_masked_key() {
		$key = $this->get_api_key();
		if ( empty( $key ) ) {
			return '';
		}

		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '*', strlen( $key ) );
		}

		return substr( $key, 0, 4 ) . str_repeat( '*', strlen( $key ) - 8 ) . substr( $key, -4 );
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = $this->defaults();

		return array(
			'safe_export_default' => empty( $input['safe_export_default'] ) ? 0 : 1,
			'inspector_admin_bar' => empty( $input['inspector_admin_bar'] ) ? 0 : 1,
			'ai_model'            => sanitize_text_field( $input['ai_model'] ?? '' ),
			'ai_free_only'        => empty( $input['ai_free_only'] ) ? 0 : 1,
		);
	}

	/**
	 * AJAX: Save API key (encrypted).
	 *
	 * @return void
	 */
	public function ajax_save_api_key() {
		check_ajax_referer( 'tracewp_settings_nonce', 'nonce' );

		if ( ! PT_Security::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'tracewp' ) ), 403 );
		}

		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( empty( $key ) ) {
			delete_option( self::API_KEY_OPTION );
			wp_send_json_success( array( 'message' => __( 'API key removed.', 'tracewp' ) ) );
		}

		if ( ! PT_Crypto::is_available() ) {
			wp_send_json_error( array( 'message' => __( 'OpenSSL PHP extension is required to store API keys securely. Contact your hosting provider to enable it.', 'tracewp' ) ) );
		}

		$encrypted = PT_Crypto::encrypt( $key );
		if ( empty( $encrypted ) ) {
			wp_send_json_error( array( 'message' => __( 'Encryption failed. Check that your wp-config.php AUTH_KEY is set.', 'tracewp' ) ) );
		}

		update_option( self::API_KEY_OPTION, $encrypted );

		wp_send_json_success( array(
			'message' => __( 'API key saved.', 'tracewp' ),
			'masked'  => $this->get_masked_key(),
		) );
	}

	/**
	 * AJAX: Validate the stored API key against OpenRouter.
	 *
	 * @return void
	 */
	public function ajax_validate_api_key() {
		check_ajax_referer( 'tracewp_settings_nonce', 'nonce' );

		if ( ! PT_Security::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'tracewp' ) ), 403 );
		}

		$key = $this->get_api_key();
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'No API key configured.', 'tracewp' ) ) );
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
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			wp_send_json_error( array(
				'message' => 401 === $code
					? __( 'Invalid API key.', 'tracewp' )
					: sprintf( __( 'OpenRouter returned HTTP %d.', 'tracewp' ), $code ),
			) );
		}

		wp_send_json_success( array(
			'message' => __( 'Connected', 'tracewp' ),
			'data'    => $body['data'] ?? array(),
		) );
	}

	/**
	 * AJAX: Fetch available models from OpenRouter.
	 *
	 * Caches the model list in a transient for 1 hour.
	 *
	 * @return void
	 */
	public function ajax_fetch_models() {
		check_ajax_referer( 'tracewp_settings_nonce', 'nonce' );

		if ( ! PT_Security::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'tracewp' ) ), 403 );
		}

		// Check cache first.
		$cached = get_transient( 'pt_openrouter_models' );
		if ( false !== $cached ) {
			wp_send_json_success( array( 'models' => $cached ) );
		}

		$key = $this->get_api_key();
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'No API key configured.', 'tracewp' ) ) );
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
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
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

			// Sort by name.
			usort( $models, static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			} );
		}

		// Cache for 1 hour.
		set_transient( 'pt_openrouter_models', $models, HOUR_IN_SECONDS );

		wp_send_json_success( array( 'models' => $models ) );
	}


}
