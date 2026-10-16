<?php
/**
 * Log scrubbing tests.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Unit;

use Complianty\Connector\Support\Log;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Complianty\Connector\Support\Log
 */
final class LogTest extends TestCase {

	public function test_tokens_are_removed(): void {
		$out = Log::scrub( 'failed with clp_site_abcdefghijklmnopqrstuvwxyz012345 and more' );

		$this->assertStringNotContainsString( 'abcdefghijkl', $out );
		$this->assertStringContainsString( 'clp_site_[redacted]', $out );
	}

	public function test_bearer_values_are_removed(): void {
		$out = Log::scrub( 'Authorization: Bearer some-other-secret-value' );

		$this->assertStringNotContainsString( 'some-other-secret-value', $out );
	}

	public function test_long_messages_are_capped(): void {
		$this->assertLessThanOrEqual( 500, strlen( Log::scrub( str_repeat( 'a', 5000 ) ) ) );
	}
}
