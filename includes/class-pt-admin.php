<?php
/**
 * Admin screen controller.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var PT_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return PT_Admin
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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( 'PT_Security', 'send_csp_headers' ) );
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		if ( ! PT_Security::current_user_can_manage() ) {
			return;
		}

		add_menu_page(
			__( 'TraceWP', 'tracewp' ),
			__( 'TraceWP', 'tracewp' ),
			PT_Security::capability(),
			'pt-export',
			array( $this, 'render_export' ),
			'dashicons-search',
			58
		);

		add_submenu_page( 'pt-export', __( 'Export', 'tracewp' ), __( 'Export', 'tracewp' ), PT_Security::capability(), 'pt-export', array( $this, 'render_export' ) );
		add_submenu_page( 'pt-export', __( 'Settings', 'tracewp' ), __( 'Settings', 'tracewp' ), PT_Security::capability(), 'pt-settings', array( $this, 'render_settings' ) );
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook_suffix Current screen.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'pt' ) ) {
			return;
		}

		wp_enqueue_style( 'pt-admin', PT_PLUGIN_URL . 'assets/css/admin.css', array(), PT_VERSION );
		wp_enqueue_style( 'dashicons' );
		$admin_deps = array();

		// Load the investigate chat module first if AI is configured.
		if ( PT_Settings::instance()->has_api_key() ) {
			wp_enqueue_script( 'pt-investigate', PT_PLUGIN_URL . 'assets/js/investigate.js', array(), PT_VERSION, true );
			$admin_deps[] = 'pt-investigate';
		}

		wp_enqueue_script( 'pt-admin', PT_PLUGIN_URL . 'assets/js/admin.js', $admin_deps, PT_VERSION, true );

		$settings = PT_Settings::instance()->get();

		wp_localize_script(
			'pt-admin',
			'ptAdmin',
			array(
				'restUrl'        => esc_url_raw( rest_url( 'pt/v1/' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'inspectUrl'     => PT_Inspector::get_inspect_url( home_url( '/' ) ),
				'currentPageUrl' => isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'hasApiKey'      => PT_Settings::instance()->has_api_key(),
				'aiModel'        => $settings['ai_model'],
				'aiFreeOnly'     => ! empty( $settings['ai_free_only'] ),
			)
		);
	}

	/**
	 * Render unified export page.
	 *
	 * @return void
	 */
	public function render_export() {
		$content_options = $this->get_content_options();
		$this->render_template( 'export', compact( 'content_options' ) );
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings() {
		$settings = PT_Settings::instance()->get();
		$this->render_template( 'settings', compact( 'settings' ) );
	}

	/**
	 * Template loader.
	 *
	 * @param string $template Template name.
	 * @param array  $vars Template vars.
	 * @return void
	 */
	private function render_template( $template, $vars = array() ) {
		if ( ! PT_Security::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tracewp' ) );
		}

		$file = PT_PLUGIN_DIR . 'templates/' . $template . '.php';
		if ( ! file_exists( $file ) ) {
			return;
		}

		// Make vars available to templates via $tpl array (avoids extract).
		$tpl = $vars;
		include $file;
	}

	/**
	 * Get lightweight content options for admin selection.
	 *
	 * @return array
	 */
	private function get_content_options() {
		$options    = array();
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		foreach ( $post_types as $post_type => $post_type_object ) {
			if ( in_array( $post_type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
				continue;
			}

			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => 20,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);

			foreach ( $posts as $post ) {
				$options[] = array(
					'id'        => (int) $post->ID,
					'label'     => sprintf( '%s: %s', $post_type_object->labels->singular_name, get_the_title( $post ) ?: '#' . $post->ID ),
					'url'       => get_permalink( $post ),
					'post_type' => $post_type,
				);
			}
		}

		return $options;
	}
}
