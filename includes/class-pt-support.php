<?php
/**
 * Shared helpers.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Support {

	/**
	 * Deep sanitize text-ish values.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return mixed
	 */
	public static function sanitize_deep( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'sanitize_deep' ), $value );
		}

		if ( is_bool( $value ) || is_numeric( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Recursively redact sensitive strings while preserving site URLs.
	 *
	 * URLs belonging to the site's own domain are preserved so the
	 * export remains functional. All other URLs, emails, and phone
	 * numbers are redacted.
	 *
	 * @param mixed  $value       Payload value.
	 * @param string $site_domain Optional. Site domain to whitelist. Auto-detected if empty.
	 * @return mixed
	 */
	public static function redact_deep( $value, $site_domain = '' ) {
		if ( empty( $site_domain ) ) {
			$site_domain = self::get_site_domain();
		}

		if ( is_array( $value ) ) {
			$redacted = array();
			foreach ( $value as $key => $item ) {
				$redacted[ $key ] = self::redact_deep( $item, $site_domain );
			}

			return $redacted;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$value = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $value );

		// Redact phone numbers, but not ISO 8601 date/datetime strings.
		$value = preg_replace( '/(?<!\d{4}-)(?<!\d{2}:)\+?\d[\d\-\s\(\)]{7,}\d(?!T\d{2}:)/', '[redacted-phone]', $value );

		// Redact URLs that are NOT on the site's own domain.
		$value = preg_replace_callback(
			'#https?://[^\s"\']+#',
			static function ( $match ) use ( $site_domain ) {
				$url_host = wp_parse_url( $match[0], PHP_URL_HOST );
				if ( $url_host && self::is_same_domain( $url_host, $site_domain ) ) {
					return $match[0];
				}

				return '[redacted-url]';
			},
			$value
		);

		return $value;
	}

	/**
	 * Get the site's primary domain for URL whitelisting.
	 *
	 * @return string
	 */
	public static function get_site_domain() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Check if a URL host belongs to the same domain (including subdomains).
	 *
	 * @param string $url_host    Host from a URL.
	 * @param string $site_domain Site domain.
	 * @return bool
	 */
	private static function is_same_domain( $url_host, $site_domain ) {
		$url_host    = strtolower( $url_host );
		$site_domain = strtolower( $site_domain );

		return $url_host === $site_domain || substr( $url_host, -strlen( '.' . $site_domain ) ) === '.' . $site_domain;
	}

	/**
	 * Convert a list of class names to a stable array.
	 *
	 * @param string $classes Class string.
	 * @return array
	 */
	public static function class_string_to_array( $classes ) {
		$classes = preg_split( '/\s+/', trim( (string) $classes ) );
		$classes = array_filter( array_map( 'sanitize_html_class', $classes ) );

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Generate a simple CSS selector hint.
	 *
	 * @param array $element Element fragment.
	 * @return string
	 */
	public static function generate_selector_hint( $element ) {
		$selector = array();
		$chain    = array();

		if ( ! empty( $element['parent_chain'] ) && is_array( $element['parent_chain'] ) ) {
			$chain = $element['parent_chain'];
		} elseif ( ! empty( $element['path'] ) && is_array( $element['path'] ) ) {
			foreach ( array_slice( $element['path'], -3 ) as $path_part ) {
				$chain[] = array(
					'raw' => sanitize_text_field( $path_part ),
				);
			}
		}

		foreach ( array_slice( $chain, -2 ) as $ancestor ) {
			$part = self::selector_part_from_element( is_array( $ancestor ) ? $ancestor : array() );
			if ( $part ) {
				$selector[] = $part;
			}
		}

		$target_part = self::selector_part_from_element( $element );
		if ( $target_part ) {
			$selector[] = $target_part;
		}

		return implode( ' > ', array_slice( $selector, -3 ) );
	}

	/**
	 * Build a selector fragment from a captured element.
	 *
	 * @param array $element Element fragment.
	 * @return string
	 */
	public static function selector_part_from_element( $element ) {
		if ( ! empty( $element['raw'] ) && is_string( $element['raw'] ) ) {
			return sanitize_text_field( $element['raw'] );
		}

		$parts = array();

		if ( ! empty( $element['tag'] ) ) {
			$parts[] = sanitize_key( $element['tag'] );
		}

		if ( ! empty( $element['id'] ) ) {
			$parts[] = '#' . sanitize_html_class( $element['id'] );
		}

		if ( ! empty( $element['classes'] ) && is_array( $element['classes'] ) ) {
			foreach ( array_slice( $element['classes'], 0, 2 ) as $class_name ) {
				$parts[] = '.' . sanitize_html_class( $class_name );
			}
		}

		return implode( '', $parts );
	}

	/**
	 * Best-effort map URL to a WP object.
	 *
	 * @param string $url URL to inspect.
	 * @return array
	 */
	public static function resolve_url_to_context( $url ) {
		$url  = esc_url_raw( $url );
		$data = array(
			'url'          => $url,
			'post_id'      => 0,
			'object_type'  => 'unknown',
			'archive_type' => '',
		);

		if ( empty( $url ) ) {
			return $data;
		}

		$post_id = url_to_postid( $url );
		if ( $post_id ) {
			$data['post_id']     = (int) $post_id;
			$data['object_type'] = get_post_type( $post_id );

			return $data;
		}

		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path      = wp_parse_url( $url, PHP_URL_PATH );

		if ( '/' === $path || $path === $home_path ) {
			$data['object_type'] = 'front_page';

			return $data;
		}

		return $data;
	}

	/**
	 * Prepare data for output.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function to_pretty_json( $data ) {
		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}
}
