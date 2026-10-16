<?php
/**
 * Authenticated encryption for values stored in the database.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Thin libsodium wrapper (XSalsa20-Poly1305 secretbox).
 *
 * Protects against database-only leaks (backups, staging copies, SQL dumps).
 * It does not protect against access to wp-config.php, where the key material lives.
 */
final class Crypto {

	/**
	 * Whether libsodium is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' );
	}

	/**
	 * Encrypts a value.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @param string $secret    Key material, e.g. wp_salt( 'auth' ).
	 * @return string|null Base64 payload (nonce + ciphertext) or null if unavailable.
	 */
	public static function encrypt( string $plaintext, string $secret ): ?string {
		if ( ! self::is_available() || '' === $secret ) {
			return null;
		}

		$key    = self::derive_key( $secret );
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );

		sodium_memzero( $key );

		return base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe transport encoding, not obfuscation.
	}

	/**
	 * Decrypts a value.
	 *
	 * @param string $payload Base64 payload from encrypt().
	 * @param string $secret  Key material used for encryption.
	 * @return string|null Plaintext or null if invalid, tampered or wrong key.
	 */
	public static function decrypt( string $payload, string $secret ): ?string {
		if ( ! self::is_available() || '' === $secret ) {
			return null;
		}

		$raw = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary-safe transport encoding, not obfuscation.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key    = self::derive_key( $secret );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

		sodium_memzero( $key );

		return false === $plain ? null : $plain;
	}

	/**
	 * Derives a fixed-length key from the secret.
	 *
	 * @param string $secret Key material.
	 * @return string
	 */
	private static function derive_key( string $secret ): string {
		return sodium_crypto_generichash( $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
