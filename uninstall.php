<?php
/**
 * Uninstall handler.
 *
 * @package TraceWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tracewp_settings' );
delete_option( 'tracewp_version' );
delete_option( 'tracewp_openrouter_key' );

// Clean up transients.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pt_%' OR option_name LIKE '_transient_timeout_pt_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
