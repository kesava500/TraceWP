<?php
/**
 * Environment and configuration collector.
 *
 * Gathers server environment, wp-config constants, .htaccess,
 * debug log, cron jobs, and other runtime configuration data.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Environment_Collector {

	/**
	 * Collect all environment data.
	 *
	 * @return array
	 */
	public function collect() {
		return array(
			'server'            => $this->server_environment(),
			'wp_constants'      => $this->wp_config_constants(),
			'htaccess'          => $this->htaccess_contents(),
			'debug_log'         => $this->debug_log_tail(),
			'cron'              => $this->cron_summary(),
			'rest_api'          => $this->rest_api_status(),
			'object_cache'      => $this->object_cache_status(),
		);
	}

	/**
	 * Server environment details.
	 *
	 * @return array
	 */
	private function server_environment() {
		return array(
			'php_version'         => PHP_VERSION,
			'server_software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown',
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'max_input_vars'      => ini_get( 'max_input_vars' ),
			'ssl'                 => is_ssl(),
			'php_extensions'      => $this->key_php_extensions(),
		);
	}

	/**
	 * Check for key PHP extensions.
	 *
	 * @return array
	 */
	private function key_php_extensions() {
		$check = array( 'curl', 'gd', 'imagick', 'mbstring', 'openssl', 'xml', 'zip', 'intl', 'sodium', 'redis', 'memcached' );
		$result = array();
		foreach ( $check as $ext ) {
			if ( extension_loaded( $ext ) ) {
				$result[] = $ext;
			}
		}
		return $result;
	}

	/**
	 * Extract non-secret constants from wp-config.php.
	 *
	 * @return array
	 */
	private function wp_config_constants() {
		$constants = array(
			'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY',
			'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT',
			'WP_CACHE', 'WP_POST_REVISIONS',
			'AUTOSAVE_INTERVAL', 'EMPTY_TRASH_DAYS',
			'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS',
			'FORCE_SSL_ADMIN', 'CONCATENATE_SCRIPTS',
			'WP_CRON_LOCK_TIMEOUT', 'DISABLE_WP_CRON',
			'WP_AUTO_UPDATE_CORE', 'AUTOMATIC_UPDATER_DISABLED',
			'WP_DEFAULT_THEME', 'UPLOADS',
			'MULTISITE', 'WP_ALLOW_MULTISITE',
		);

		$result = array();
		foreach ( $constants as $name ) {
			if ( defined( $name ) ) {
				$val = constant( $name );
				// Convert booleans to readable strings.
				if ( true === $val ) {
					$result[ $name ] = 'true';
				} elseif ( false === $val ) {
					$result[ $name ] = 'false';
				} else {
					$result[ $name ] = (string) $val;
				}
			}
		}
		return $result;
	}

	/**
	 * Read .htaccess contents.
	 *
	 * @return string|null
	 */
	private function htaccess_contents() {
		$path = ABSPATH . '.htaccess';
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return null;
		}

		// Cap at 10KB.
		if ( strlen( $contents ) > 10240 ) {
			$contents = substr( $contents, 0, 10240 ) . "\n[truncated]";
		}

		return $contents;
	}

	/**
	 * Get the tail of the debug log.
	 *
	 * @return array|null
	 */
	private function debug_log_tail() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return null;
		}

		$log_path = WP_CONTENT_DIR . '/debug.log';

		// Check if WP_DEBUG_LOG specifies a custom path.
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
			$log_path = WP_DEBUG_LOG;
		}

		if ( ! file_exists( $log_path ) || ! is_readable( $log_path ) ) {
			return null;
		}

		$size = filesize( $log_path );
		if ( 0 === $size ) {
			return array( 'size' => 0, 'lines' => array() );
		}

		// Read last 8KB to get the tail.
		$offset = max( 0, $size - 8192 );
		$tail   = file_get_contents( $log_path, false, null, $offset ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $tail ) {
			return null;
		}

		$lines = explode( "\n", trim( $tail ) );
		$lines = array_slice( $lines, -30 );

		return array(
			'size'      => $size,
			'size_human' => size_format( $size ),
			'lines'     => $lines,
		);
	}

	/**
	 * Cron jobs summary.
	 *
	 * @return array
	 */
	private function cron_summary() {
		$crons = _get_cron_array();
		if ( empty( $crons ) ) {
			return array( 'total' => 0, 'jobs' => array() );
		}

		$now     = time();
		$jobs    = array();
		$overdue = 0;

		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $event ) {
					$is_overdue = $timestamp < $now;
					if ( $is_overdue ) {
						$overdue++;
					}
					$jobs[] = array(
						'hook'      => $hook,
						'next_run'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
						'schedule'  => $event['schedule'] ?: 'one-time',
						'overdue'   => $is_overdue,
					);
				}
			}
		}

		// Sort by next run and limit to 20.
		usort( $jobs, static function ( $a, $b ) {
			return strcmp( $a['next_run'], $b['next_run'] );
		} );

		return array(
			'total'       => count( $jobs ),
			'overdue'     => $overdue,
			'disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'jobs'        => array_slice( $jobs, 0, 20 ),
		);
	}

	/**
	 * REST API accessibility check.
	 *
	 * @return array
	 */
	private function rest_api_status() {
		$url      = rest_url( 'wp/v2/' );
		$response = wp_remote_get( $url, array( 'timeout' => 5 ) );

		if ( is_wp_error( $response ) ) {
			return array(
				'accessible' => false,
				'error'      => $response->get_error_message(),
			);
		}

		return array(
			'accessible' => 200 === wp_remote_retrieve_response_code( $response ),
			'url'        => $url,
		);
	}

	/**
	 * Object cache status.
	 *
	 * @return array
	 */
	private function object_cache_status() {
		$using_ext = wp_using_ext_object_cache();
		$dropin    = file_exists( WP_CONTENT_DIR . '/object-cache.php' );

		return array(
			'external'    => $using_ext,
			'drop_in'     => $dropin,
			'type'        => $using_ext ? $this->detect_cache_type() : 'none',
		);
	}

	/**
	 * Detect the type of object cache in use.
	 *
	 * @return string
	 */
	private function detect_cache_type() {
		if ( extension_loaded( 'redis' ) && class_exists( 'Redis' ) ) {
			return 'redis';
		}
		if ( extension_loaded( 'memcached' ) ) {
			return 'memcached';
		}
		return 'unknown';
	}
}
