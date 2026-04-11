<?php
/**
 * Security helpers.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Security {

	/**
	 * Minimum allowed capabilities for plugin access.
	 *
	 * @var array
	 */
	private static $allowed_capabilities = array(
		'manage_options',
		'activate_plugins',
	);

	/**
	 * Required capability.
	 *
	 * The capability can be filtered, but only to another high-privilege
	 * capability. This plugin exports sensitive site structure data, so
	 * access must remain restricted to administrators.
	 *
	 * @return string
	 */
	public static function capability() {
		$cap = apply_filters( 'pt_capability', 'manage_options' );

		if ( ! in_array( $cap, self::$allowed_capabilities, true ) ) {
			$cap = 'manage_options';
		}

		return $cap;
	}

	/**
	 * Check whether current user may use the plugin.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::capability() );
	}

	/**
	 * REST permission callback.
	 *
	 * @return bool|\WP_Error
	 */
	public static function rest_permission() {
		if ( ! is_user_logged_in() || ! self::current_user_can_manage() ) {
			return new WP_Error(
				'pt_forbidden',
				__( 'You do not have permission to access this endpoint.', 'tracewp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Verify a nonce from a REST request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool
	 */
	public static function verify_rest_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		return ! empty( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Check Content-Length header against a size limit.
	 *
	 * Call this early in endpoint handlers to reject oversized payloads
	 * before the body is parsed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int            $max_bytes Maximum body size in bytes.
	 * @return bool|WP_Error True if OK, WP_Error if too large.
	 */
	public static function check_request_size( WP_REST_Request $request, $max_bytes = 1048576 ) {
		$length = $request->get_header( 'content-length' );
		if ( $length && (int) $length > $max_bytes ) {
			return new WP_Error(
				'pt_payload_too_large',
				sprintf( __( 'Request body too large. Maximum size is %dKB.', 'tracewp' ), $max_bytes / 1024 ),
				array( 'status' => 413 )
			);
		}
		return true;
	}

	/**
	 * Simple transient-based rate limiter.
	 *
	 * @param int $limit  Max requests per window.
	 * @param int $window Window in seconds.
	 * @return bool|\WP_Error True if allowed, WP_Error if rate limited.
	 */
	public static function rate_limit( $limit = 30, $window = 60, $scope = '' ) {
		$user_id = get_current_user_id();
		$key     = 'pt_rate_' . $user_id . ( $scope ? '_' . $scope : '' );
		$data    = get_transient( $key );

		if ( false === $data ) {
			set_transient( $key, array( 'count' => 1, 'start' => time() ), $window );
			return true;
		}

		if ( $data['count'] >= $limit ) {
			return new WP_Error(
				'pt_rate_limited',
				__( 'Too many requests. Please wait before exporting again.', 'tracewp' ),
				array( 'status' => 429 )
			);
		}

		$data['count']++;
		set_transient( $key, $data, $window - ( time() - $data['start'] ) );

		return true;
	}

	/**
	 * Send Content-Security-Policy headers for TraceWP admin pages.
	 *
	 * Restricts script sources to the site's own domain and the WordPress
	 * admin area, preventing injection of external scripts. AI-generated
	 * HTML output is already escaped, but CSP provides defense-in-depth.
	 *
	 * @return void
	 */
	public static function send_csp_headers() {
		if ( headers_sent() ) {
			return;
		}

		// Only set CSP on TraceWP admin pages.
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'pt' ) ) {
			return;
		}

		$domain = wp_parse_url( site_url(), PHP_URL_HOST );
		if ( $domain ) {
			$csp = "default-src 'self'; " .
				"script-src 'self' 'unsafe-inline' 'unsafe-eval'; " . // WP admin needs inline/eval.
				"style-src 'self' 'unsafe-inline'; " .
				"img-src 'self' data: blob:; " . // Data URIs for screenshots.
				"connect-src 'self'; " . // REST calls go to same origin (proxied).
				"frame-ancestors 'none'; " . // Prevent framing/clickjacking.
				"form-action 'self'; " .
				"base-uri 'self';";

			header( 'Content-Security-Policy: ' . $csp );
		}
	}
}
