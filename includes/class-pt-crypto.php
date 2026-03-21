<?php
/**
 * API key encryption/decryption.
 *
 * Uses AES-256-CBC with AUTH_KEY as the encryption key.
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
	 * Check if encryption is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Get the encryption key derived from WordPress AUTH_KEY.
	 *
	 * @return string 32-byte key.
	 */
	private static function get_key() {
		$source = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : 'tracewp-fallback-key-change-your-salts';
		return hash( 'sha256', $source, true );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * Requires OpenSSL. Returns empty string if unavailable.
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string Base64-encoded ciphertext with IV prepended, or empty on failure.
	 */
	public static function encrypt( $plaintext ) {
		if ( empty( $plaintext ) || ! self::is_available() ) {
			return '';
		}

		$iv         = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return '';
		}

		return 'enc:' . base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt a ciphertext string.
	 *
	 * Only accepts values encrypted by encrypt(). Rejects unencrypted
	 * or base64-only values — there is no insecure fallback.
	 *
	 * @param string $encrypted The encrypted value (from encrypt()).
	 * @return string Decrypted plaintext, or empty string on failure.
	 */
	public static function decrypt( $encrypted ) {
		if ( empty( $encrypted ) || ! self::is_available() ) {
			return '';
		}

		// Only accept properly encrypted values.
		if ( 0 !== strpos( $encrypted, 'enc:' ) ) {
			return '';
		}

		$raw    = base64_decode( substr( $encrypted, 4 ) );
		$iv_len = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $raw ) <= $iv_len ) {
			return '';
		}

		$iv         = substr( $raw, 0, $iv_len );
		$ciphertext = substr( $raw, $iv_len );
		$plaintext  = openssl_decrypt( $ciphertext, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv );

		return false !== $plaintext ? $plaintext : '';
	}
}
