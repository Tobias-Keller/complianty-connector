<?php
/**
 * Token storage.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Storage;

use Complianty\Connector\Security\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the API token.
 *
 * Preferred: constant COMPLIANTY_API_TOKEN in wp-config.php (nothing is stored in the database).
 * Otherwise: encrypted option with autoload disabled.
 */
final class TokenStore {

	public const OPTION  = 'complianty_connector_token';
	public const PATTERN = '/\Aclp_site_[A-Za-z0-9_-]{32,64}\z/';

	/**
	 * Whether a token is provided by wp-config.php.
	 *
	 * @return bool
	 */
	public function is_constant_defined(): bool {
		if ( ! defined( 'COMPLIANTY_API_TOKEN' ) ) {
			return false;
		}

		$value = constant( 'COMPLIANTY_API_TOKEN' );

		return is_string( $value ) && '' !== $value;
	}

	/**
	 * Whether any token is configured, without decrypting it.
	 *
	 * Cheap enough for frontend requests. A true result does not mean that get() can read it.
	 *
	 * @return bool
	 */
	public function has_token(): bool {
		if ( $this->is_constant_defined() ) {
			return true;
		}

		$stored = get_option( self::OPTION, '' );

		return is_string( $stored ) && '' !== $stored;
	}

	/**
	 * Where the token comes from.
	 *
	 * @return string "constant", "database" or "none".
	 */
	public function source(): string {
		if ( $this->is_constant_defined() ) {
			return 'constant';
		}

		return $this->has_token() ? 'database' : 'none';
	}

	/**
	 * Whether a token string has the expected format.
	 *
	 * @param string $token Candidate token.
	 * @return bool
	 */
	public function is_valid_format( string $token ): bool {
		return 1 === preg_match( self::PATTERN, $token );
	}

	/**
	 * Returns the plaintext token, or null if none is configured or it cannot be read.
	 *
	 * @return string|null
	 */
	public function get(): ?string {
		if ( $this->is_constant_defined() ) {
			$token = (string) constant( 'COMPLIANTY_API_TOKEN' );
			return $this->is_valid_format( $token ) ? $token : null;
		}

		$stored = get_option( self::OPTION, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$token = Crypto::decrypt( $stored, wp_salt( 'auth' ) );

		return ( null !== $token && $this->is_valid_format( $token ) ) ? $token : null;
	}

	/**
	 * Stores a token encrypted. Refuses when constant is set, format is invalid or libsodium is missing.
	 *
	 * @param string $token Plaintext token.
	 * @return bool True on success.
	 */
	public function set( string $token ): bool {
		if ( $this->is_constant_defined() || ! $this->is_valid_format( $token ) ) {
			return false;
		}

		$encrypted = Crypto::encrypt( $token, wp_salt( 'auth' ) );
		if ( null === $encrypted ) {
			return false;
		}

		delete_option( self::OPTION );
		return add_option( self::OPTION, $encrypted, '', false );
	}

	/**
	 * Whether a value is an encrypted payload that decrypts to a valid token.
	 *
	 * Used to tell an internal write apart from user input.
	 *
	 * @param string $payload Candidate payload.
	 * @return bool
	 */
	public function is_encrypted_token( string $payload ): bool {
		$token = Crypto::decrypt( $payload, wp_salt( 'auth' ) );

		return null !== $token && $this->is_valid_format( $token );
	}

	/**
	 * Removes the stored token.
	 *
	 * @return void
	 */
	public function delete(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Masked representation for display, never the full token.
	 *
	 * @param string $token Plaintext token.
	 * @return string
	 */
	public function mask( string $token ): string {
		return 'clp_site_…' . substr( $token, -4 );
	}
}
