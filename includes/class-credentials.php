<?php
/**
 * Stripe credential layer — single source of auth headers.
 *
 * Keys are encrypted at rest using AES-256-CBC with a key derived from
 * WordPress's AUTH_KEY salt, so a database leak alone cannot expose them.
 *
 * @package SimpleDigitalDownloads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Credentials class.
 */
class SDD_Credentials {

	const CIPHER = 'aes-256-cbc';

	/**
	 * Get the Stripe secret key.
	 *
	 * @return string
	 */
	public static function get_secret_key() {
		if ( defined( 'SDD_STRIPE_SECRET_KEY' ) && SDD_STRIPE_SECRET_KEY ) {
			return SDD_STRIPE_SECRET_KEY;
		}
		$raw = (string) get_option( 'sdd_stripe_secret_key', '' );
		if ( '' === $raw ) {
			return '';
		}
		$decrypted = self::decrypt( $raw );
		if ( false !== $decrypted ) {
			return $decrypted;
		}
		// Plaintext from before encryption was added — migrate it.
		if ( preg_match( '/^(sk|rk)_(test|live)_/', $raw ) ) {
			self::save_secret_key( $raw );
			return $raw;
		}
		return '';
	}

	/**
	 * Whether Stripe credentials are configured.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$key = self::get_secret_key();
		return ! empty( $key ) && preg_match( '/^(sk|rk)_(test|live)_/', $key );
	}

	/**
	 * Derive mode from key prefix.
	 *
	 * @return string 'test'|'live'|''
	 */
	public static function get_mode() {
		$key = self::get_secret_key();
		if ( preg_match( '/_(test)_/', $key ) ) {
			return 'test';
		}
		if ( preg_match( '/_(live)_/', $key ) ) {
			return 'live';
		}
		return '';
	}

	/**
	 * Request headers for Stripe API calls.
	 *
	 * @return array<string,string>
	 */
	public static function get_request_args() {
		return array(
			'Authorization'  => 'Bearer ' . self::get_secret_key(),
			'Stripe-Version' => SDD_STRIPE_API_VERSION,
		);
	}

	/**
	 * Mask a secret key for display.
	 *
	 * @param string $key Secret key.
	 * @return string
	 */
	public static function mask_key( $key ) {
		if ( strlen( $key ) < 12 ) {
			return '••••••••';
		}
		return substr( $key, 0, 7 ) . '••••' . substr( $key, -4 );
	}

	/**
	 * Save a secret key to options (encrypted).
	 *
	 * @param string $key Secret key.
	 */
	public static function save_secret_key( $key ) {
		$key = sanitize_text_field( $key );
		update_option( 'sdd_stripe_secret_key', self::encrypt( $key ), false );
	}

	/**
	 * Get webhook signing secret.
	 *
	 * @return string
	 */
	public static function get_webhook_secret() {
		$raw = (string) get_option( 'sdd_stripe_webhook_secret', '' );
		if ( '' === $raw ) {
			return '';
		}
		$decrypted = self::decrypt( $raw );
		if ( false !== $decrypted ) {
			return $decrypted;
		}
		// Plaintext from before encryption was added — migrate it.
		if ( 0 === strpos( $raw, 'whsec_' ) ) {
			self::save_webhook_secret( $raw );
			return $raw;
		}
		return $raw;
	}

	/**
	 * Save webhook signing secret (encrypted).
	 *
	 * @param string $secret Signing secret.
	 */
	public static function save_webhook_secret( $secret ) {
		$secret = sanitize_text_field( $secret );
		update_option( 'sdd_stripe_webhook_secret', self::encrypt( $secret ), false );
	}

	/**
	 * Encrypt a value.
	 *
	 * @param string $value Plaintext.
	 * @return string Base64-encoded IV + ciphertext.
	 */
	private static function encrypt( $value ) {
		if ( '' === $value ) {
			return '';
		}
		$key = self::get_encryption_key();
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$encrypted = openssl_encrypt( $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return $value;
		}
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a value.
	 *
	 * @param string $value Base64-encoded IV + ciphertext.
	 * @return string|false Plaintext or false on failure.
	 */
	private static function decrypt( $value ) {
		if ( '' === $value ) {
			return '';
		}
		$raw = base64_decode( $value, true );
		if ( false === $raw ) {
			return false;
		}
		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		if ( strlen( $raw ) <= $iv_length ) {
			return false;
		}
		$iv        = substr( $raw, 0, $iv_length );
		$encrypted = substr( $raw, $iv_length );
		$key       = self::get_encryption_key();
		return openssl_decrypt( $encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
	}

	/**
	 * Derive a 32-byte encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private static function get_encryption_key() {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'sdd-fallback-key';
		return hash( 'sha256', $salt, true );
	}
}
