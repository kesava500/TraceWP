<?php
/**
 * Front-end inspector bootstrap.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Inspector {

	/**
	 * Singleton instance.
	 *
	 * @var PT_Inspector|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return PT_Inspector
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
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_link' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Generate a nonce-protected inspect URL.
	 *
	 * @param string $base_url URL to add inspect params to.
	 * @return string
	 */
	public static function get_inspect_url( $base_url = '' ) {
		if ( empty( $base_url ) ) {
			$base_url = home_url( '/' );
		}

		return add_query_arg(
			array(
				'pt_inspect' => '1',
				'_pt_nonce'  => wp_create_nonce( 'pt_inspect' ),
			),
			$base_url
		);
	}

	/**
	 * Verify the inspector nonce from the current request.
	 *
	 * @return bool
	 */
	private function verify_inspect_nonce() {
		if ( ! isset( $_GET['_pt_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		return (bool) wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_GET['_pt_nonce'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'pt_inspect'
		);
	}

	/**
	 * Add admin bar entry.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar instance.
	 * @return void
	 */
	public function admin_bar_link( $admin_bar ) {
		$settings = PT_Settings::instance()->get();

		if ( empty( $settings['inspector_admin_bar'] ) || ! is_admin_bar_showing() || ! PT_Security::current_user_can_manage() || is_admin() ) {
			return;
		}

		$current_url = $this->current_url();

		$admin_bar->add_node(
			array(
				'id'    => 'pt-inspector',
				'title' => __( 'Inspect with TraceWP', 'tracewp' ),
				'href'  => self::get_inspect_url( $current_url ),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'pt-export-current-page',
				'parent' => 'pt-inspector',
				'title'  => __( 'Export Current Page Context', 'tracewp' ),
				'href'   => add_query_arg(
					'url',
					rawurlencode( $current_url ),
					admin_url( 'admin.php?page=pt-export' )
				),
			)
		);
	}

	/**
	 * Enqueue inspector assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( is_admin() || ! PT_Security::current_user_can_manage() ) {
			return;
		}

		if ( ! isset( $_GET['pt_inspect'] ) || ! $this->verify_inspect_nonce() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_style( 'pt-inspector', PT_PLUGIN_URL . 'assets/css/inspector.css', array(), PT_VERSION );

		$has_key  = PT_Settings::instance()->has_api_key();
		$settings = PT_Settings::instance()->get();

		// Load the investigate chat module if AI is configured.
		if ( $has_key ) {
			wp_enqueue_script( 'pt-investigate', PT_PLUGIN_URL . 'assets/js/investigate.js', array(), PT_VERSION, true );
		}

		wp_enqueue_script( 'pt-inspector', PT_PLUGIN_URL . 'assets/js/inspector.js', $has_key ? array( 'pt-investigate' ) : array(), PT_VERSION, true );

		$localized = array(
			'restUrl'     => esc_url_raw( rest_url( 'pt/v1/context/element' ) ),
			'pageRestUrl' => esc_url_raw( rest_url( 'pt/v1/context/page' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'pageUrl'     => $this->current_url(),
			'hasApiKey'   => $has_key,
		);

		// Pass AI config for front-end chat.
		if ( $has_key ) {
			$localized['aiModel']       = $settings['ai_model'];
			$localized['aiFreeOnly']    = ! empty( $settings['ai_free_only'] );
			$localized['aiRestUrl']     = esc_url_raw( rest_url( 'pt/v1/' ) );
			$localized['ajaxUrl']       = admin_url( 'admin-ajax.php' );
			$localized['settingsNonce'] = wp_create_nonce( 'tracewp_settings_nonce' );
		}

		wp_localize_script( 'pt-inspector', 'ptInspector', $localized );
	}

	/**
	 * Get the current full URL using the site domain (not HTTP_HOST).
	 *
	 * @return string
	 */
	private function current_url() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$scheme      = is_ssl() ? 'https://' : 'http://';
		$host        = wp_parse_url( home_url(), PHP_URL_HOST );

		return esc_url_raw( $scheme . $host . $request_uri );
	}
}
