<?php
/**
 * Cache and backoff tests.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Complianty\Connector\Storage\Cache;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Complianty\Connector\Storage\Cache
 */
final class CacheTest extends TestCase {

	/**
	 * In-memory transient store.
	 *
	 * @var array<string,mixed>
	 */
	private $store = array();

	/**
	 * Mutable clock.
	 *
	 * @var \stdClass
	 */
	private $clock;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->store = array();

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->store[ $key ] );
				return true;
			}
		);
	}

	/**
	 * Cache with a controllable clock (public property $time).
	 *
	 * @return Cache
	 */
	private function clock_cache(): Cache {
		$this->clock       = new \stdClass();
		$this->clock->time = 1000000;
		$clock             = $this->clock;

		return new Cache(
			static function () use ( $clock ) {
				return $clock->time;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_status_roundtrip_and_freshness(): void {
		$cache = $this->clock_cache();
		$this->assertNull( $cache->get_status() );

		$cache->set_status( array( 'status' => 'ok' ) );
		$cached = $cache->get_status();
		$this->assertSame( array( 'status' => 'ok' ), $cached['data'] );
		$this->assertTrue( $cache->is_status_fresh( $cached ) );

		$this->clock->time += Cache::STATUS_TTL_FRESH + 1;
		$this->assertFalse( $cache->is_status_fresh( $cache->get_status() ) );
		$this->assertFalse( $cache->is_status_fresh( null ) );
	}

	public function test_corrupt_status_is_ignored(): void {
		$cache                            = $this->clock_cache();
		$this->store['complianty_status'] = 'garbage';

		$this->assertNull( $cache->get_status() );
	}

	public function test_backoff_grows_exponentially_and_is_capped(): void {
		$cache = $this->clock_cache();
		$this->assertSame( 0, $cache->backoff_until() );

		$cache->register_failure();
		$this->assertSame( $this->clock->time + 300, $cache->backoff_until() );

		$cache->register_failure();
		$this->assertSame( $this->clock->time + 600, $cache->backoff_until() );

		for ( $i = 0; $i < 12; $i++ ) {
			$cache->register_failure();
		}
		$this->assertSame( $this->clock->time + 21600, $cache->backoff_until() );
	}

	public function test_backoff_respects_retry_hint_and_expires(): void {
		$cache = $this->clock_cache();
		$cache->register_failure( 3600 );
		$this->assertSame( $this->clock->time + 3600, $cache->backoff_until() );

		$this->clock->time += 3601;
		$this->assertSame( 0, $cache->backoff_until() );
	}

	public function test_clear_backoff_and_connection_data(): void {
		$cache = $this->clock_cache();
		$cache->register_failure();
		$cache->set_connection_state( Cache::STATE_UNAUTHORIZED );
		$cache->set_status( array( 'status' => 'ok' ) );

		$this->assertSame( Cache::STATE_UNAUTHORIZED, $cache->get_connection_state()['state'] );

		$cache->clear_backoff();
		$this->assertSame( 0, $cache->backoff_until() );

		$cache->clear_connection_data();
		$this->assertNull( $cache->get_status() );
		$this->assertNull( $cache->get_connection_state() );
	}
}
