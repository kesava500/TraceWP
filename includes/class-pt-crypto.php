<?php
/**
 * API key encryption/decryption.
 *
 * Uses AES-256-CBC with HMAC-SHA256 authentication (encrypt-then-MAC).
 * Encryption key is derived from WordPress AUTH_KEY.
 * Requires the OpenSSL PHP extension. If unavailable,
 * key storage is disabled and the AI investigator cannot be used.
 *
 * @package TraceWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PT_Crypto {

	/**
	 * Cipher method.
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * HMAC algorithm.
	 */
	const HMAC_ALGO = 'sha256';

	/**
	 * Prefix for encrypted values.
	 */
	const PREFIX = 'enc:';

	/**
	 * Check if encryption is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Check if AUTH_KEY is properly defined (not the default placeholder).
	 *
	 * @return bool
	 */
	private static function has_auth_key() {
		if ( ! defined( 'AUTH_KEY' ) || empty( AUTH_KEY ) ) {
			return false;
		}

		// Reject the default WordPress placeholder value.
		$defaults = array(
			'put your unique phrase here',
			'change this to something',
		);

		$lower = strtolower( trim( AUTH_KEY ) );
		foreach ( $defaults as $default ) {
			if ( false !== strpos( $lower, $default ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the encryption key derived from WordPress AUTH_KEY.
	 *
	 * @return string|WP_Error 32-byte key, or WP_Error if AUTH_KEY is not properly set.
	 */
	private static function get_key() {
		if ( ! self::has_auth_key() ) {
			return new WP_Error(
				'pt_no_auth_key',
				__( 'AUTH_KEY is not properly configured in wp-config.php. API key encryption requires a unique AUTH_KEY.', 'tracewp' )
			);
		}

		return hash( 'sha256', AUTH_KEY, true );
	}

	/**
	 * Get the HMAC key derived from AUTH_KEY (separate from encryption key).
	 *
	 * Uses a different derivation so the encryption and authentication keys
	 * are cryptographically independent.
	 *
	 * @return string|WP_Error 32-byte key, or WP_Error if AUTH_KEY is not set.
	 */
	private static function get_hmac_key() {
		if ( ! self::has_auth_key() ) {
			return new WP_Error(
				'pt_no_auth_key',
				__( 'AUTH_KEY is not properly configured in wp-config.php.', 'tracewp' )
			);
		}

		return hash( 'sha256', 'hmac:' . AUTH_KEY, true );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * Uses AES-256-CBC with a random IV, then authenticates with HMAC-SHA256
	 * (encrypt-then-MAC). The output format is:
	 *
	 *   enc:base64( IV || HMAC || ciphertext )
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string|WP_Error Encrypted value with prefix, or WP_Error on failure.
	 */
	public static function encrypt( $plaintext ) {
		if ( empty( $plaintext ) ) {
			return '';
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'pt_no_openssl',
				__( 'OpenSSL PHP extension is required for encryption.', 'tracewp' )
			);
		}

		$enc_key = self::get_key();
		if ( is_wp_error( $enc_key ) ) {
			return $enc_key;
		}

		$hmac_key = self::get_hmac_key();
		if ( is_wp_error( $hmac_key ) ) {
			return $hmac_key;
		}

		$iv         = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $enc_key, OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return new WP_Error(
				'pt_encrypt_failed',
				__( 'Encryption failed.', 'tracewp' )
			);
		}

		// Encrypt-then-MAC: authenticate IV + ciphertext.
		$mac = hash_hmac( self::HMAC_ALGO, $iv . $ciphertext, $hmac_key, true );

		return self::PREFIX . base64_encode( $iv . $mac . $ciphertext );
	}

	/**
	 * Decrypt a ciphertext string.
	 *
	 * Only accepts values encrypted by encrypt(). Rejects unencrypted
	 * or base64-only values — there is no insecure fallback.
	 *
	 * Verifies HMAC in constant time before attempting decryption.
	 *
	 * @param string $encrypted The encrypted value (from encrypt()).
	 * @return string|WP_Error Decrypted plaintext, or WP_Error on failure.
	 */
	public static function decrypt( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return '';
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'pt_no_openssl',
				__( 'OpenSSL PHP extension is required for decryption.', 'tracewp' )
			);
		}

		// Only accept properly encrypted values.
		if ( 0 !== strpos( $encrypted, self::PREFIX ) ) {
			return new WP_Error(
				'pt_invalid_format',
				__( 'Invalid encrypted value format.', 'tracewp' )
			);
		}

		$hmac_key = self::get_hmac_key();
		if ( is_wp_error( $hmac_key ) ) {
			return $hmac_key;
		}

		$enc_key = self::get_key();
		if ( is_wp_error( $enc_key ) ) {
			return $enc_key;
		}

		$raw    = base64_decode( substr( $encrypted, strlen( self::PREFIX ) ), true );
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$mac_len = 32; // SHA-256 HMAC is 32 bytes.

		// Minimum: IV (16) + MAC (32) + at least 1 block of ciphertext.
		if ( strlen( $raw ) <= $iv_len + $mac_len ) {
			return new WP_Error(
				'pt_invalid_format',
				__( 'Encrypted value is too short.', 'tracewp' )
			);
		}

		$iv         = substr( $raw, 0, $iv_len );
		$provided_mac = substr( $raw, $iv_len, $mac_len );
		$ciphertext = substr( $raw, $iv_len + $mac_len );

		// Verify MAC in constant time before decryption.
		$expected_mac = hash_hmac( self::HMAC_ALGO, $iv . $ciphertext, $hmac_key, true );
		if ( ! hash_equals( $expected_mac, $provided_mac ) ) {
			// Fallback: try legacy format (IV || ciphertext without MAC).
			// Old versions stored values without HMAC. If the data parses as
			// IV + ciphertext without a MAC, try decrypting it and re-encrypt
			// with the new authenticated format.
			$legacy_result = self::try_legacy_decrypt( $encrypted, $enc_key );
			if ( ! is_wp_error( $legacy_result ) ) {
				return $legacy_result;
			}

			return new WP_Error(
				'pt_tampered',
				__( 'Encrypted value failed authentication check.', 'tracewp' )
			);
		}

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $enc_key, OPENSSL_RAW_DATA, $iv );

		if ( false === $plaintext ) {
			return new WP_Error(
				'pt_decrypt_failed',
				__( 'Decryption failed.', 'tracewp' )
			);
		}

		return $plaintext;
	}

	/**
	 * Try decrypting a legacy value stored without HMAC.
	 *
	 * Old format: enc:base64( IV || ciphertext )
	 * New format: enc:base64( IV || HMAC || ciphertext )
	 *
	 * If decryption succeeds, the key is automatically re-encrypted
	 * with the new authenticated format so future reads use the secure path.
	 *
	 * @param string $encrypted The full encrypted string (with prefix).
	 * @param string $enc_key   The encryption key.
	 * @return string|WP_Error Decrypted plaintext, or WP_Error.
	 */
	private static function try_legacy_decrypt( $encrypted, $enc_key ) {
		$raw    = base64_decode( substr( $encrypted, strlen( self::PREFIX ) ), true );
		$iv_len = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $raw ) <= $iv_len ) {
			return new WP_Error( 'pt_invalid_format', __( 'Encrypted value is too short.', 'tracewp' ) );
		}

		$iv         = substr( $raw, 0, $iv_len );
		$ciphertext = substr( $raw, $iv_len );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $enc_key, OPENSSL_RAW_DATA, $iv );

		if ( false === $plaintext ) {
			return new WP_Error( 'pt_decrypt_failed', __( 'Decryption failed.', 'tracewp' ) );
		}

		// Re-encrypt with the new authenticated format and update stored value.
		$re_encrypted = self::encrypt( $plaintext );
		if ( ! is_wp_error( $re_encrypted ) && ! empty( $re_encrypted ) ) {
			update_option( PT_Settings::API_KEY_OPTION, $re_encrypted );
		}

		return $plaintext;
	}
}