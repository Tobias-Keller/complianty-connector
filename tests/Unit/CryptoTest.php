<?php
/**
 * Crypto tests.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Unit;

use Complianty\Connector\Security\Crypto;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Complianty\Connector\Security\Crypto
 */
final class CryptoTest extends TestCase {

	public function test_roundtrip(): void {
		$payload = Crypto::encrypt( 'clp_site_secret', 'salt-a' );

		$this->assertIsString( $payload );
		$this->assertSame( 'clp_site_secret', Crypto::decrypt( $payload, 'salt-a' ) );
	}

	public function test_ciphertext_does_not_contain_plaintext(): void {
		$payload = Crypto::encrypt( 'clp_site_secret', 'salt-a' );

		$this->assertIsString( $payload );
		$this->assertStringNotContainsString( 'clp_site_secret', $payload );
	}

	public function test_nonce_is_random(): void {
		$this->assertNotSame(
			Crypto::encrypt( 'same', 'salt-a' ),
			Crypto::encrypt( 'same', 'salt-a' )
		);
	}

	public function test_wrong_key_returns_null(): void {
		$payload = Crypto::encrypt( 'value', 'salt-a' );

		$this->assertIsString( $payload );
		$this->assertNull( Crypto::decrypt( $payload, 'salt-b' ) );
	}

	public function test_tampered_payload_returns_null(): void {
		$payload = Crypto::encrypt( 'value', 'salt-a' );
		$this->assertIsString( $payload );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test helper flipping a byte of binary data.
		$raw          = base64_decode( $payload, true );
		$last         = strlen( $raw ) - 1;
		$raw[ $last ] = 'A' === $raw[ $last ] ? 'B' : 'A';
		$tampered     = base64_encode( $raw );
		// phpcs:enable

		$this->assertNull( Crypto::decrypt( $tampered, 'salt-a' ) );
	}

	public function test_garbage_and_short_input_return_null(): void {
		$this->assertNull( Crypto::decrypt( 'not base64 !!!', 'salt-a' ) );
		$this->assertNull( Crypto::decrypt( 'c2hvcnQ=', 'salt-a' ) );
		$this->assertNull( Crypto::decrypt( '', 'salt-a' ) );
	}

	public function test_empty_secret_is_refused(): void {
		$this->assertNull( Crypto::encrypt( 'value', '' ) );
		$this->assertNull( Crypto::decrypt( 'abc', '' ) );
	}
}
