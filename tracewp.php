<?php
/**
 * Plugin Name: TraceWP
 * Plugin URI:  https://belletty.com/tracewp
 * Description: Package your WordPress site context for AI. One-click export of theme, plugins, page data, and element context — paste into ChatGPT, Claude, or any LLM. Includes optional built-in AI investigator via OpenRouter.
 * Version:     1.0.0
 * Author:      Belletty Digital
 * Author URI:  https://belletty.com
 * Text Domain: tracewp
 * Domain Path: /languages
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PT_VERSION', '1.0.0' );
define( 'PT_PLUGIN_FILE', __FILE__ );
define( 'PT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PT_PLUGIN_DIR . 'includes/class-pt-plugin.php';

register_activation_hook( __FILE__, array( 'PT_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PT_Plugin', 'deactivate' ) );

PT_Plugin::instance()->boot();
