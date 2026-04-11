<?php
/**
 * REST controller.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_REST_Controller {

	/**
	 * Singleton instance.
	 *
	 * @var PT_REST_Controller|null
	 */
	private static $instance = null;

	/**
	 * Builder instance.
	 *
	 * @var PT_Payload_Builder
	 */
	private $builder;

	/**
	 * Formatter instance.
	 *
	 * @var PT_Formatter
	 */
	private $formatter;

	/**
	 * Get singleton instance.
	 *
	 * @return PT_REST_Controller
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->builder   = new PT_Payload_Builder();
		$this->formatter = new PT_Formatter();
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
	 * Shared argument schema for all context endpoints.
	 *
	 * @return array
	 */
	private function shared_args() {
		return array(
			'url'              => array(
				'type'              => 'string',
				'format'            => 'uri',
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
			),
			'post_id'          => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'safe_export'      => array(
				'type'              => 'boolean',
				'default'           => false,
			),
			'notes'            => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'element'          => array(
				'type'              => 'object',
				'default'           => array(),
			),
		);
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$shared_args = $this->shared_args();

		register_rest_route(
			'pt/v1',
			'/context/site',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( 'PT_Security', 'rest_permission' ),
				'callback'            => array( $this, 'site_context' ),
				'args'                => $shared_args,
			)
		);

		register_rest_route(
			'pt/v1',
			'/context/page',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( 'PT_Security', 'rest_permission' ),
				'callback'            => array( $this, 'page_context' ),
				'args'                => $shared_args,
			)
		);

		register_rest_route(
			'pt/v1',
			'/context/element',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( 'PT_Security', 'rest_permission' ),
				'callback'            => array( $this, 'element_context' ),
				'args'                => $shared_args,
			)
		);

		register_rest_route(
			'pt/v1',
			'/settings/ai',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( 'PT_Security', 'rest_permission' ),
				'callback'            => array( $this, 'save_ai_settings' ),
				'args'                => array(
					'ai_model'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'ai_free_only' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Save AI-specific settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_ai_settings( WP_REST_Request $request ) {
		$settings = PT_Settings::instance()->get();

		$settings['ai_model']     = sanitize_text_field( (string) $request->get_param( 'ai_model' ) );
		$settings['ai_free_only'] = rest_sanitize_boolean( $request->get_param( 'ai_free_only' ) ) ? 1 : 0;

		update_option( PT_Settings::OPTION_NAME, PT_Settings::instance()->sanitize_settings( $settings ) );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Build site context.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function site_context( WP_REST_Request $request ) {
		$size_check = PT_Security::check_request_size( $request );
		if ( is_wp_error( $size_check ) ) {
			return $size_check;
		}

		$rate_check = PT_Security::rate_limit( 30, 60, 'export' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		return rest_ensure_response( $this->build_response( 'site', $request ) );
	}

	/**
	 * Build page context.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function page_context( WP_REST_Request $request ) {
		$size_check = PT_Security::check_request_size( $request );
		if ( is_wp_error( $size_check ) ) {
			return $size_check;
		}

		$rate_check = PT_Security::rate_limit( 30, 60, 'export' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		return rest_ensure_response( $this->build_response( 'page', $request ) );
	}

	/**
	 * Build element context.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function element_context( WP_REST_Request $request ) {
		$size_check = PT_Security::check_request_size( $request );
		if ( is_wp_error( $size_check ) ) {
			return $size_check;
		}

		$rate_check = PT_Security::rate_limit( 30, 60, 'export' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		return rest_ensure_response( $this->build_response( 'element', $request ) );
	}

	/**
	 * Shared response builder.
	 *
	 * @param string          $scope Scope.
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function build_response( $scope, WP_REST_Request $request ) {
		$element_raw = $request->get_param( 'element' );

		$params = array(
			'context_scope'    => $scope,
			'url'              => esc_url_raw( (string) $request->get_param( 'url' ) ),
			'post_id'          => absint( $request->get_param( 'post_id' ) ),
			'safe_export'      => rest_sanitize_boolean( $request->get_param( 'safe_export' ) ),
			'notes'            => sanitize_textarea_field( (string) $request->get_param( 'notes' ) ),
			'element'          => 'element' === $scope && is_array( $element_raw )
				? PT_Support::sanitize_deep( $element_raw )
				: array(),
		);

		if ( empty( $params['url'] ) ) {
			$params['url'] = home_url( '/' );
		}

		$payload   = $this->builder->build( $params );
		$formatted = $this->formatter->format( $payload );

		$token_estimate = $this->estimate_tokens( $formatted );

		return array(
			'payload'        => $payload,
			'formatted'      => $formatted,
			'token_estimate' => $token_estimate,
		);
	}

	/**
	 * Rough token estimate for AI context windows.
	 *
	 * @param array $formatted Formatted output.
	 * @return array
	 */
	private function estimate_tokens( $formatted ) {
		$len = strlen( $formatted['output'] ?? '' );

		return array(
			'tokens' => (int) ceil( $len / 4 ),
			'note'   => __( 'Approximate token count (chars/4). Actual tokens vary by model.', 'tracewp' ),
		);
	}
}
