<?php
/**
 * Core plugin bootstrap.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var PT_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get singleton instance.
	 *
	 * @return PT_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->load_files();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init' ) );

		$this->booted = true;
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Set default options if not already present.
		if ( false === get_option( 'tracewp_settings' ) ) {
			add_option(
				'tracewp_settings',
				array(
					'safe_export_default' => 1,
					'inspector_admin_bar' => 1,
				)
			);
		}

		// Store version for future upgrade routines.
		update_option( 'tracewp_version', PT_VERSION );
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Clean up transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pt_rate_%' OR option_name LIKE '_transient_timeout_pt_rate_%'" );
	}

	/**
	 * Load plugin dependencies.
	 *
	 * @return void
	 */
	private function load_files() {
		$files = array(
			'includes/class-pt-support.php',
			'includes/class-pt-crypto.php',
			'includes/class-pt-security.php',
			'includes/class-pt-settings.php',
			'includes/class-pt-detector.php',
			'includes/class-pt-site-collector.php',
			'includes/class-pt-environment-collector.php',
			'includes/class-pt-page-collector.php',
			'includes/class-pt-payload-builder.php',
			'includes/class-pt-formatter.php',
			'includes/class-pt-rest-controller.php',
			'includes/class-pt-ai-tools.php',
			'includes/class-pt-ai-controller.php',
			'includes/class-pt-chat-proxy.php',
			'includes/class-pt-admin.php',
			'includes/class-pt-inspector.php',
		);

		foreach ( $files as $file ) {
			require_once PT_PLUGIN_DIR . $file;
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'tracewp', false, dirname( plugin_basename( PT_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Initialize runtime services.
	 *
	 * @return void
	 */
	public function init() {
		PT_Settings::instance()->register();
		PT_REST_Controller::instance()->register();
		PT_AI_Controller::instance()->register();
		PT_Chat_Proxy::instance()->register();
		PT_Admin::instance()->register();
		PT_Inspector::instance()->register();
	}
}
