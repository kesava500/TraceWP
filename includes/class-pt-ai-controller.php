<?php
/**
 * REST controller for AI investigation tool endpoints.
 *
 * All endpoints are read-only. They are called by the browser-side JS
 * during an AI investigation conversation when the AI requests file
 * reads, directory listings, option lookups, etc.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_AI_Controller {

	/**
	 * Singleton instance.
	 *
	 * @var PT_AI_Controller|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return PT_AI_Controller
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
		$base_config = array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => array( $this, 'tool_permission' ),
		);

		register_rest_route( 'pt/v1', '/tool/read-file', array_merge( $base_config, array(
			'callback' => array( $this, 'handle_read_file' ),
			'args'     => array(
				'path' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $v ) { return strlen( $v ) <= 500; },
				),
			),
		) ) );

		register_rest_route( 'pt/v1', '/tool/list-directory', array_merge( $base_config, array(
			'callback' => array( $this, 'handle_list_directory' ),
			'args'     => array(
				'path'  => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $v ) { return strlen( $v ) <= 500; },
				),
				'depth' => array(
					'type'    => 'integer',
					'default' => 1,
				),
			),
		) ) );

		register_rest_route( 'pt/v1', '/tool/search-files', array_merge( $base_config, array(
			'callback' => array( $this, 'handle_search_files' ),
			'args'     => array(
				'directory' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $v ) { return strlen( $v ) <= 500; },
				),
				'pattern'   => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function( $v ) { return strlen( $v ) <= 200; },
				),
				'type'      => array(
					'type'    => 'string',
					'default' => 'name',
					'enum'    => array( 'name', 'content' ),
				),
			),
		) ) );

		register_rest_route( 'pt/v1', '/tool/get-option', array_merge( $base_config, array(
			'callback' => array( $this, 'handle_get_option' ),
			'args'     => array(
				'option_name' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => function( $v ) { return strlen( $v ) <= 200; },
				),
			),
		) ) );

		register_rest_route( 'pt/v1', '/tool/fetch-page-html', array_merge( $base_config, array(
			'callback' => array( $this, 'handle_fetch_page_html' ),
			'args'     => array(
				'url' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'esc_url_raw',
					'validate_callback' => function( $v ) { return strlen( $v ) <= 2048; },
				),
			),
		) ) );

		register_rest_route( 'pt/v1', '/tool/template-hierarchy', array_merge( $base_config, array(
			'callback' => array( $this, 'handle_template_hierarchy' ),
			'args'     => array(
				'url' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'esc_url_raw',
					'validate_callback' => function( $v ) { return strlen( $v ) <= 2048; },
				),
			),
		) ) );

		register_rest_route( 'pt/v1', '/tool/theme-files', array_merge( $base_config, array(
			'callback' => array( $this, 'handle_theme_files' ),
			'args'     => array(),
		) ) );
	}

	/**
	 * Permission callback for tool endpoints.
	 *
	 * Requires manage_options + rate limiting.
	 *
	 * @return bool|WP_Error
	 */
	public function tool_permission() {
		$auth = PT_Security::rest_permission();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		return PT_Security::rate_limit( 60, 60 );
	}

	/**
	 * Handle read_file tool call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_read_file( WP_REST_Request $request ) {
		return rest_ensure_response(
			PT_AI_Tools::read_file( $request->get_param( 'path' ) )
		);
	}

	/**
	 * Handle list_directory tool call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_list_directory( WP_REST_Request $request ) {
		return rest_ensure_response(
			PT_AI_Tools::list_directory(
				$request->get_param( 'path' ),
				$request->get_param( 'depth' )
			)
		);
	}

	/**
	 * Handle search_files tool call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_search_files( WP_REST_Request $request ) {
		return rest_ensure_response(
			PT_AI_Tools::search_files(
				$request->get_param( 'directory' ),
				$request->get_param( 'pattern' ),
				$request->get_param( 'type' )
			)
		);
	}

	/**
	 * Handle get_option tool call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_get_option( WP_REST_Request $request ) {
		return rest_ensure_response(
			PT_AI_Tools::get_option( $request->get_param( 'option_name' ) )
		);
	}

	/**
	 * Handle fetch_page_html tool call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_fetch_page_html( WP_REST_Request $request ) {
		return rest_ensure_response(
			PT_AI_Tools::fetch_page_html( $request->get_param( 'url' ) )
		);
	}

	/**
	 * Handle template_hierarchy tool call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_template_hierarchy( WP_REST_Request $request ) {
		return rest_ensure_response(
			PT_AI_Tools::get_template_hierarchy( $request->get_param( 'url' ) )
		);
	}

	/**
	 * Handle theme_files tool call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_theme_files( WP_REST_Request $request ) {
		return rest_ensure_response(
			PT_AI_Tools::get_active_theme_files()
		);
	}
}