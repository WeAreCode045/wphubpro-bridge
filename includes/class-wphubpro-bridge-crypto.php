<?php
/**
 * Encrypt/decrypt helpers using WordPress wp_salt().
 * Used to securely store API secrets in wp_options.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crypto helper using wp_salt() for key derivation.
 */
class WPHubPro_Bridge_Crypto {

	/**
	 * Derive a 256-bit key from WordPress salts.
	 *
	 * @return string Binary key (32 bytes).
	 */
	private static function get_derived_key() {
		$salt = wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . 'wphubpro_bridge_v1';
		return hash( 'sha256', $salt, true );
	}

	/**
	 * Check if a value appears to be encrypted (iv:encrypted:tag format).
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		if ( ! is_string( $value ) || empty( $value ) ) {
			return false;
		}
		$parts = explode( ':', $value );
		return count( $parts ) === 3
			&& strlen( $parts[0] ) === 24
			&& strlen( $parts[2] ) === 32
			&& ctype_xdigit( $parts[0] )
			&& ctype_xdigit( $parts[1] )
			&& ctype_xdigit( $parts[2] );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext Plaintext to encrypt.
	 * @return string Encrypted value as iv_hex:encrypted_hex:tag_hex, or empty on failure.
	 */
	public static function encrypt( $plaintext ) {
		if ( ! is_string( $plaintext ) || $plaintext === '' ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $plaintext;
		}
		$key   = self::get_derived_key();
		$iv    = random_bytes( 12 );
		$tag   = '';
		$cipher = 'aes-256-gcm';
		$encrypted = openssl_encrypt(
			$plaintext,
			$cipher,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16
		);
		if ( $encrypted === false ) {
			return $plaintext;
		}
		return bin2hex( $iv ) . ':' . bin2hex( $encrypted ) . ':' . bin2hex( $tag );
	}

	/**
	 * Decrypt an encrypted value.
	 *
	 * @param string $encrypted Value in iv_hex:encrypted_hex:tag_hex format.
	 * @return string Plaintext, or original value if decryption fails.
	 */
	public static function decrypt( $encrypted ) {
		if ( ! is_string( $encrypted ) || empty( $encrypted ) ) {
			return '';
		}
		if ( ! self::is_encrypted( $encrypted ) ) {
			return $encrypted;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $encrypted;
		}
		$parts = explode( ':', $encrypted, 3 );
		$iv         = hex2bin( $parts[0] );
		$ciphertext = hex2bin( $parts[1] );
		$tag        = hex2bin( $parts[2] );
		$key        = self::get_derived_key();
		$cipher     = 'aes-256-gcm';
		$decrypted = openssl_decrypt(
			$ciphertext,
			$cipher,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);
		return $decrypted !== false ? $decrypted : $encrypted;
	}

	/**
	 * Encrypt and store a secret. Always encrypts new values.
	 *
	 * @param string $option Option name.
	 * @param string $plaintext Plaintext to store.
	 */
	public static function encrypt_and_store( $option, $plaintext ) {
		if ( ! is_string( $plaintext ) || $plaintext === '' ) {
			delete_option( $option );
			return;
		}
		$encrypted = self::encrypt( $plaintext );
		update_option( $option, $encrypted );
	}

	/**
	 * Retrieve and decrypt a secret. Handles both encrypted and legacy plaintext.
	 *
	 * @param string $option Option name.
	 * @return string Plaintext, or empty string.
	 */
	public static function retrieve_and_decrypt( $option ) {
		$value = get_option( $option, '' );
		if ( $value === '' ) {
			return '';
		}
		return self::is_encrypted( $value ) ? self::decrypt( $value ) : $value;
	}
}
