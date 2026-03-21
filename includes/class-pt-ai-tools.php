<?php
/**
 * Read-only AI investigation tools.
 *
 * Each tool is a static method that performs a read-only operation
 * on the WordPress installation. These are called by the AI via
 * REST endpoints during an investigation conversation.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_AI_Tools {

	/**
	 * Maximum file size for reading (100KB).
	 */
	const MAX_FILE_SIZE = 102400;

	/**
	 * Maximum HTML fetch size (200KB).
	 */
	const MAX_HTML_SIZE = 204800;

	/**
	 * Maximum directory listing entries.
	 */
	const MAX_DIR_ENTRIES = 200;

	/**
	 * Maximum search results.
	 */
	const MAX_SEARCH_RESULTS = 50;

	/**
	 * Directories to skip by default in listings/searches.
	 */
	const SKIP_DIRS = array( 'node_modules', '.git', 'vendor', '.svn', '.hg' );

	/**
	 * Blocked file extensions (binary/dangerous).
	 */
	const BLOCKED_EXTENSIONS = array(
		'zip', 'tar', 'gz', 'rar', '7z',
		'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp',
		'mp3', 'mp4', 'avi', 'mov', 'wmv', 'flv',
		'woff', 'woff2', 'ttf', 'eot', 'otf',
		'exe', 'dll', 'so', 'phar',
		'sql', 'sqlite',
	);

	/**
	 * Sensitive option name patterns to block.
	 */
	const BLOCKED_OPTION_PATTERNS = array(
		'password', 'passwd', 'secret', 'key', 'token', 'salt', 'nonce', 'hash',
		'auth_key', 'secure_auth', 'logged_in_key', 'nonce_key',
		'session_tokens', 'user_pass',
		'openrouter', 'tracewp_openrouter',
		'mailchimp_api', 'stripe_secret', 'paypal_secret',
	);

	/**
	 * Read a file from the WordPress installation.
	 *
	 * @param string $path Relative path from ABSPATH or wp-content.
	 * @return array Result with 'content' or 'error'.
	 */
	public static function read_file( $path ) {
		$resolved = self::resolve_path( $path );

		if ( is_wp_error( $resolved ) ) {
			return array( 'error' => $resolved->get_error_message() );
		}

		// Check if wp-config.php — serve redacted version.
		if ( 'wp-config.php' === basename( $resolved ) ) {
			return self::read_wp_config_redacted( $resolved );
		}

		// Check extension.
		$ext = strtolower( pathinfo( $resolved, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, self::BLOCKED_EXTENSIONS, true ) ) {
			return array( 'error' => 'Binary file type (' . $ext . '), cannot display contents.' );
		}

		// Check size.
		$size = filesize( $resolved );
		if ( false === $size ) {
			return array( 'error' => 'Could not read file size.' );
		}

		$truncated = false;
		if ( $size > self::MAX_FILE_SIZE ) {
			$truncated = true;
		}

		$content = file_get_contents( $resolved, false, null, 0, self::MAX_FILE_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return array( 'error' => 'Could not read file.' );
		}

		// Check if content is binary.
		if ( self::is_binary( $content ) ) {
			return array( 'error' => 'File appears to be binary, cannot display contents.' );
		}

		$result = array(
			'path'    => $path,
			'size'    => $size,
			'content' => $content,
		);

		if ( $truncated ) {
			$result['truncated'] = true;
			$result['note']      = sprintf( 'Truncated at %dKB (full size: %dKB).', self::MAX_FILE_SIZE / 1024, $size / 1024 );
		}

		return $result;
	}

	/**
	 * List files and directories at a given path.
	 *
	 * @param string $path  Relative path.
	 * @param int    $depth Max depth (1-3).
	 * @return array Result with 'entries' or 'error'.
	 */
	public static function list_directory( $path, $depth = 1 ) {
		$resolved = self::resolve_path( $path );

		if ( is_wp_error( $resolved ) ) {
			return array( 'error' => $resolved->get_error_message() );
		}

		if ( ! is_dir( $resolved ) ) {
			return array( 'error' => 'Not a directory: ' . $path );
		}

		$depth   = max( 1, min( 3, (int) $depth ) );
		$entries = array();
		self::scan_directory( $resolved, $entries, $depth, 0, $path );

		return array(
			'path'    => $path,
			'count'   => count( $entries ),
			'entries' => $entries,
		);
	}

	/**
	 * Search for files by name or content.
	 *
	 * @param string $directory Directory to search.
	 * @param string $pattern   Search pattern.
	 * @param string $type      'name' or 'content'.
	 * @return array Result with 'matches' or 'error'.
	 */
	public static function search_files( $directory, $pattern, $type = 'name' ) {
		$resolved = self::resolve_path( $directory );

		if ( is_wp_error( $resolved ) ) {
			return array( 'error' => $resolved->get_error_message() );
		}

		if ( ! is_dir( $resolved ) ) {
			return array( 'error' => 'Not a directory: ' . $directory );
		}

		$matches  = array();
		$start    = microtime( true );
		$skip     = self::SKIP_DIRS;
		$filtered = new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $resolved, RecursiveDirectoryIterator::SKIP_DOTS ),
			static function ( $current ) use ( $skip ) {
				// Prune skipped directories — prevents descent.
				if ( $current->isDir() && in_array( $current->getFilename(), $skip, true ) ) {
					return false;
				}
				return true;
			}
		);
		$iterator = new RecursiveIteratorIterator( $filtered, RecursiveIteratorIterator::SELF_FIRST );

		foreach ( $iterator as $file ) {
			// Timeout after 5 seconds.
			if ( microtime( true ) - $start > 5 ) {
				$matches[] = array( 'path' => '[search timed out after 5s]', 'preview' => '' );
				break;
			}

			if ( count( $matches ) >= self::MAX_SEARCH_RESULTS ) {
				break;
			}

			if ( ! $file->isFile() || ! $file->isReadable() ) {
				continue;
			}

			$ext = strtolower( $file->getExtension() );
			if ( in_array( $ext, self::BLOCKED_EXTENSIONS, true ) ) {
				continue;
			}

			$relative = self::make_relative( $file->getPathname() );

			if ( 'name' === $type ) {
				if ( fnmatch( '*' . $pattern . '*', $file->getFilename(), FNM_CASEFOLD ) ) {
					$matches[] = array(
						'path' => $relative,
						'size' => $file->getSize(),
					);
				}
			} elseif ( 'content' === $type ) {
				if ( $file->getSize() > self::MAX_FILE_SIZE ) {
					continue;
				}

				$content = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( false !== $content && false !== stripos( $content, $pattern ) ) {
					// Find the line containing the match.
					$lines   = explode( "\n", $content );
					$preview = '';
					foreach ( $lines as $num => $line ) {
						if ( false !== stripos( $line, $pattern ) ) {
							$preview = 'L' . ( $num + 1 ) . ': ' . trim( substr( $line, 0, 200 ) );
							break;
						}
					}

					$matches[] = array(
						'path'    => $relative,
						'preview' => $preview,
					);
				}
			}
		}

		return array(
			'directory' => $directory,
			'pattern'   => $pattern,
			'type'      => $type,
			'count'     => count( $matches ),
			'matches'   => $matches,
		);
	}

	/**
	 * Read a WordPress option value.
	 *
	 * @param string $option_name Option name.
	 * @return array Result with 'value' or 'error'.
	 */
	public static function get_option( $option_name ) {
		$option_name = sanitize_key( $option_name );

		if ( empty( $option_name ) ) {
			return array( 'error' => 'Option name is required.' );
		}

		// Block sensitive options.
		$lower = strtolower( $option_name );
		foreach ( self::BLOCKED_OPTION_PATTERNS as $blocked ) {
			if ( false !== strpos( $lower, $blocked ) ) {
				return array( 'error' => 'Access to this option is blocked for security.' );
			}
		}

		$value = get_option( $option_name, null );

		if ( null === $value ) {
			return array( 'error' => 'Option not found: ' . $option_name );
		}

		// Convert to JSON-safe representation.
		$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( strlen( $json ) > 51200 ) {
			$json = substr( $json, 0, 51200 ) . "\n[truncated at 50KB]";
		}

		return array(
			'option' => $option_name,
			'value'  => $json,
		);
	}

	/**
	 * Fetch the rendered HTML of a site page.
	 *
	 * @param string $url URL to fetch (must be same domain).
	 * @return array Result with 'html' or 'error'.
	 */
	public static function fetch_page_html( $url ) {
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return array( 'error' => 'URL is required.' );
		}

		// Verify same domain.
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! $url_host || strtolower( $url_host ) !== strtolower( $site_host ) ) {
			return array( 'error' => 'URL must be on the same domain as this site.' );
		}

		$response = wp_remote_get( $url, array(
			'timeout'    => 15,
			'user-agent' => 'WPAIContextExporter/' . PT_VERSION,
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Fetch failed: ' . $response->get_error_message() );
		}

		$html = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return array( 'error' => 'Page returned HTTP ' . $code . '.' );
		}

		// Strip script tags to save tokens.
		$html = preg_replace( '#<script[^>]*>.*?</script>#si', '', $html );

		if ( strlen( $html ) > self::MAX_HTML_SIZE ) {
			$html = substr( $html, 0, self::MAX_HTML_SIZE ) . "\n<!-- truncated at 200KB -->";
		}

		return array(
			'url'  => $url,
			'code' => $code,
			'size' => strlen( $html ),
			'html' => $html,
		);
	}

	/**
	 * Determine the template hierarchy for a given URL.
	 *
	 * @param string $url URL to check.
	 * @return array Result with 'templates' or 'error'.
	 */
	public static function get_template_hierarchy( $url ) {
		$url = esc_url_raw( $url );

		// Resolve URL to a post.
		$post_id = url_to_postid( $url );
		$theme   = wp_get_theme();
		$results = array(
			'url'       => $url,
			'post_id'   => $post_id,
			'theme_dir' => $theme->get_stylesheet_directory(),
			'templates' => array(),
		);

		if ( ! $post_id ) {
			// Check if it's the home URL.
			$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$url_path  = wp_parse_url( $url, PHP_URL_PATH );

			if ( $url_path === $home_path || '/' === $url_path ) {
				$results['type'] = 'front_page';
				$candidates      = array( 'front-page.php', 'home.php', 'index.php' );
			} else {
				$results['type'] = 'unknown';
				$candidates      = array( 'index.php' );
			}
		} else {
			$post      = get_post( $post_id );
			$post_type = $post ? $post->post_type : 'post';
			$slug      = $post ? $post->post_name : '';

			$results['post_type'] = $post_type;
			$results['type']      = 'singular';

			if ( 'page' === $post_type ) {
				$template = get_page_template_slug( $post_id );
				$candidates = array_filter( array(
					$template ?: null,
					'page-' . $slug . '.php',
					'page-' . $post_id . '.php',
					'page.php',
					'singular.php',
					'index.php',
				) );
			} elseif ( 'post' === $post_type ) {
				$candidates = array(
					'single-post-' . $slug . '.php',
					'single-post.php',
					'single.php',
					'singular.php',
					'index.php',
				);
			} else {
				$candidates = array(
					'single-' . $post_type . '-' . $slug . '.php',
					'single-' . $post_type . '.php',
					'single.php',
					'singular.php',
					'index.php',
				);
			}
		}

		$theme_dir  = $theme->get_stylesheet_directory();
		$parent_dir = $theme->get_template_directory();

		foreach ( $candidates as $template_file ) {
			$exists_in_child  = file_exists( $theme_dir . '/' . $template_file );
			$exists_in_parent = ( $theme_dir !== $parent_dir ) && file_exists( $parent_dir . '/' . $template_file );

			$results['templates'][] = array(
				'file'   => $template_file,
				'exists' => $exists_in_child || $exists_in_parent,
				'source' => $exists_in_child ? 'child' : ( $exists_in_parent ? 'parent' : 'not_found' ),
			);
		}

		return $results;
	}

	/**
	 * List all PHP/CSS/JS files in the active theme.
	 *
	 * @return array Theme file listing.
	 */
	public static function get_active_theme_files() {
		$theme     = wp_get_theme();
		$theme_dir = $theme->get_stylesheet_directory();
		$files     = array();

		self::collect_theme_files( $theme_dir, $files, $theme_dir );

		// If child theme, also list parent.
		$parent_dir = $theme->get_template_directory();
		$parent_files = array();
		if ( $theme_dir !== $parent_dir ) {
			self::collect_theme_files( $parent_dir, $parent_files, $parent_dir );
		}

		return array(
			'theme'        => $theme->get( 'Name' ),
			'is_child'     => $theme_dir !== $parent_dir,
			'files'        => $files,
			'parent_files' => $parent_files,
		);
	}

	// ── Private helpers ─────────────────────────────────

	/**
	 * Resolve a relative path to an absolute path, jailed to ABSPATH.
	 *
	 * @param string $path Relative path.
	 * @return string|WP_Error Resolved absolute path or error.
	 */
	private static function resolve_path( $path ) {
		$path = str_replace( array( "\0", "\r", "\n" ), '', $path );
		$path = ltrim( $path, '/' );

		// Block obvious traversal.
		if ( false !== strpos( $path, '..' ) ) {
			return new WP_Error( 'path_traversal', 'Path traversal not allowed.' );
		}

		// Try relative to ABSPATH first.
		$absolute = realpath( ABSPATH . $path );

		// If not found, try wp-content.
		if ( ! $absolute || ! file_exists( $absolute ) ) {
			$absolute = realpath( WP_CONTENT_DIR . '/' . $path );
		}

		if ( ! $absolute || ! file_exists( $absolute ) ) {
			return new WP_Error( 'not_found', 'File not found: ' . $path );
		}

		// Jail check — must be under ABSPATH.
		$abspath = realpath( ABSPATH );
		if ( 0 !== strpos( $absolute, $abspath ) ) {
			return new WP_Error( 'access_denied', 'Access denied.' );
		}

		// Block .env files (all variants).
		$basename = basename( $absolute );
		if ( 0 === strpos( $basename, '.env' ) ) {
			return new WP_Error( 'blocked', 'Access to .env files is blocked.' );
		}

		// Block log files over 50KB.
		if ( 'log' === strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) ) && filesize( $absolute ) > 51200 ) {
			return new WP_Error( 'too_large', 'Log file too large. Only log files under 50KB can be read.' );
		}

		return $absolute;
	}

	/**
	 * Make a path relative to ABSPATH for display.
	 *
	 * @param string $absolute Absolute path.
	 * @return string Relative path.
	 */
	private static function make_relative( $absolute ) {
		$abspath = realpath( ABSPATH );
		if ( 0 === strpos( $absolute, $abspath ) ) {
			return ltrim( substr( $absolute, strlen( $abspath ) ), '/' );
		}

		return $absolute;
	}

	/**
	 * Check if content appears to be binary.
	 *
	 * @param string $content File content.
	 * @return bool
	 */
	private static function is_binary( $content ) {
		$sample = substr( $content, 0, 8192 );
		return false !== strpos( $sample, "\x00" );
	}

	/**
	 * Recursively scan a directory.
	 *
	 * @param string $dir         Absolute directory path.
	 * @param array  &$entries    Collected entries.
	 * @param int    $max_depth   Max depth.
	 * @param int    $depth       Current depth.
	 * @param string $relative_base Relative base for display.
	 * @return void
	 */
	private static function scan_directory( $dir, &$entries, $max_depth, $depth, $relative_base ) {
		if ( count( $entries ) >= self::MAX_DIR_ENTRIES ) {
			return;
		}

		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			if ( count( $entries ) >= self::MAX_DIR_ENTRIES ) {
				$entries[] = array( 'name' => '[listing capped at ' . self::MAX_DIR_ENTRIES . ' entries]', 'type' => 'note' );
				return;
			}

			$full = $dir . '/' . $item;
			$rel  = $relative_base . '/' . $item;

			if ( is_dir( $full ) ) {
				if ( in_array( $item, self::SKIP_DIRS, true ) ) {
					continue;
				}

				$entries[] = array(
					'name' => $rel,
					'type' => 'dir',
				);

				if ( $depth < $max_depth - 1 ) {
					self::scan_directory( $full, $entries, $max_depth, $depth + 1, $rel );
				}
			} else {
				$entries[] = array(
					'name'     => $rel,
					'type'     => 'file',
					'size'     => filesize( $full ),
					'modified' => gmdate( 'Y-m-d H:i', filemtime( $full ) ),
				);
			}
		}
	}

	/**
	 * Collect PHP/CSS/JS files from a theme directory.
	 *
	 * @param string $dir      Directory to scan.
	 * @param array  &$files   Collected files.
	 * @param string $base_dir Base directory for relative paths.
	 * @return void
	 */
	private static function collect_theme_files( $dir, &$files, $base_dir ) {
		$allowed_ext = array( 'php', 'css', 'js', 'json', 'txt', 'html' );
		$skip        = self::SKIP_DIRS;
		$filtered    = new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			static function ( $current ) use ( $skip ) {
				if ( $current->isDir() && in_array( $current->getFilename(), $skip, true ) ) {
					return false;
				}
				return true;
			}
		);
		$iterator = new RecursiveIteratorIterator( $filtered, RecursiveIteratorIterator::SELF_FIRST );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, $allowed_ext, true ) ) {
				continue;
			}

			$relative = ltrim( substr( $file->getPathname(), strlen( $base_dir ) ), '/' );
			$files[]  = array(
				'path' => $relative,
				'size' => $file->getSize(),
			);
		}
	}

	/**
	 * Read wp-config.php with credentials redacted.
	 *
	 * @param string $path Absolute path to wp-config.php.
	 * @return array
	 */
	private static function read_wp_config_redacted( $path ) {
		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return array( 'error' => 'Could not read wp-config.php.' );
		}

		// Redact DB credentials.
		$content = preg_replace(
			"/define\(\s*['\"]DB_(NAME|USER|PASSWORD|HOST)['\"]\s*,\s*['\"]([^'\"]*)['\"](\s*)\)/",
			"define( '$1', '[REDACTED]' )",
			$content
		);

		// Redact salts and keys.
		$content = preg_replace(
			"/define\(\s*['\"](" .
			"AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|" .
			"AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT" .
			")['\"]\s*,\s*['\"]([^'\"]*)['\"](\s*)\)/",
			"define( '$1', '[REDACTED]' )",
			$content
		);

		return array(
			'path'    => 'wp-config.php',
			'content' => $content,
			'note'    => 'Database credentials and security keys have been redacted.',
		);
	}
}
