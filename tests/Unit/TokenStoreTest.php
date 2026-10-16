<?php
/**
 * TokenStore tests.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Complianty\Connector\Storage\TokenStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Complianty\Connector\Storage\TokenStore
 */
final class TokenStoreTest extends TestCase {

	private const TOKEN = 'clp_site_abcdefghijklmnopqrstuvwxyz012345';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_format_validation(): void {
		$store = new TokenStore();

		$this->assertTrue( $store->is_valid_format( self::TOKEN ) );
		$this->assertFalse( $store->is_valid_format( 'clp_live_abcdefghijklmnopqrstuvwxyz012345' ) );
		$this->assertFalse( $store->is_valid_format( 'clp_site_short' ) );
		$this->assertFalse( $store->is_valid_format( self::TOKEN . '<script>' ) );
		$this->assertFalse( $store->is_valid_format( '' ) );
		$this->assertFalse( $store->is_valid_format( self::TOKEN . "\n" ), 'A trailing line break must not pass.' );
		$this->assertFalse( $store->is_valid_format( self::TOKEN . "\r\nX-Evil: 1" ) );
	}

	public function test_set_stores_encrypted_non_autoloaded_option(): void {
		$stored = null;
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value, $deprecated, $autoload ) use ( &$stored ) {
				$stored = array( $name, $value, $autoload );
				return true;
			}
		);

		$this->assertTrue( ( new TokenStore() )->set( self::TOKEN ) );
		$this->assertSame( TokenStore::OPTION, $stored[0] );
		$this->assertStringNotContainsString( self::TOKEN, $stored[1] );
		$this->assertFalse( $stored[2] );
	}

	public function test_set_rejects_invalid_format(): void {
		$this->assertFalse( ( new TokenStore() )->set( 'nope' ) );
	}

	public function test_get_roundtrip(): void {
		$value = null;
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'add_option' )->alias(
			static function ( $name, $encrypted ) use ( &$value ) {
				$value = $encrypted;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function () use ( &$value ) {
				return $value;
			}
		);

		$store = new TokenStore();
		$store->set( self::TOKEN );

		$this->assertSame( self::TOKEN, $store->get() );
	}

	public function test_get_returns_null_when_nothing_stored(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertNull( ( new TokenStore() )->get() );
	}

	public function test_get_returns_null_for_corrupt_value(): void {
		Functions\when( 'get_option' )->justReturn( 'garbage' );

		$this->assertNull( ( new TokenStore() )->get() );
	}

	public function test_mask_never_reveals_token(): void {
		$masked = ( new TokenStore() )->mask( self::TOKEN );

		$this->assertSame( 'clp_site_…2345', $masked );
		$this->assertStringNotContainsString( 'abcdefghijkl', $masked );
	}
}
