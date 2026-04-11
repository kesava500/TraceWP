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
}