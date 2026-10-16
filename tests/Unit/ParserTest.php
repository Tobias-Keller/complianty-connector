<?php
/**
 * Parser tests.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Unit;

use Complianty\Connector\Api\Endpoints;
use Complianty\Connector\Api\Parser;
use Complianty\Connector\Api\Result;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Complianty\Connector\Api\Parser
 * @covers \Complianty\Connector\Api\Result
 */
final class ParserTest extends TestCase {

	private const JSON = 'application/json';

	/**
	 * Error body in the service format.
	 *
	 * @param string              $code  Error code.
	 * @param array<string,mixed> $extra Extra detail fields.
	 * @return string
	 */
	private function error_body( string $code, array $extra = array() ): string {
		return (string) json_encode( array( 'detail' => array_merge( array( 'detail' => $code ), $extra ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in unit tests.
	}

	public function test_scan_accepted_is_parsed(): void {
		$body   = '{"job_id":"abc123","scan_state":"queued"}';
		$result = Parser::parse( Endpoints::SCAN, 202, self::JSON, $body );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( 'abc123', $result->data()['job_id'] );
	}

	public function test_charset_in_content_type_is_accepted(): void {
		$body = '{"scan_state":"idle","last_scanned_at":null,"last_scan_error":null}';

		$this->assertTrue( Parser::parse( Endpoints::SCAN_STATUS, 200, 'application/json; charset=utf-8', $body )->is_ok() );
	}

	public function test_wrong_content_type_is_invalid(): void {
		$body   = '{"scan_state":"idle","last_scanned_at":null}';
		$result = Parser::parse( Endpoints::SCAN_STATUS, 200, 'text/html', $body );

		$this->assertSame( Result::INVALID, $result->kind() );
		$this->assertSame( array(), $result->data() );
	}

	public function test_missing_content_type_is_invalid(): void {
		$result = Parser::parse( Endpoints::SCAN_STATUS, 200, '', '{"scan_state":"idle","last_scanned_at":null}' );

		$this->assertSame( Result::INVALID, $result->kind() );
	}

	public function test_broken_json_is_invalid(): void {
		$this->assertSame( Result::INVALID, Parser::parse( Endpoints::STATUS, 200, self::JSON, '{"status":' )->kind() );
	}

	public function test_deeply_nested_json_is_invalid(): void {
		$body = str_repeat( '[', 200 ) . str_repeat( ']', 200 );

		$this->assertSame( Result::INVALID, Parser::parse( Endpoints::STATUS, 200, self::JSON, $body )->kind() );
	}

	public function test_oversized_body_is_invalid(): void {
		$body   = str_repeat( ' ', Parser::MAX_BODY );
		$result = Parser::parse( Endpoints::STATUS, 200, self::JSON, $body );

		$this->assertSame( Result::INVALID, $result->kind() );
		$this->assertSame( 'too_large', $result->code() );
	}

	public function test_schema_violation_is_invalid(): void {
		$result = Parser::parse( Endpoints::SCAN, 202, self::JSON, '{"job_id":"../../etc","scan_state":"queued"}' );

		$this->assertSame( Result::INVALID, $result->kind() );
	}

	public function test_redirect_is_invalid(): void {
		$result = Parser::parse( Endpoints::STATUS, 302, self::JSON, '' );

		$this->assertSame( Result::INVALID, $result->kind() );
		$this->assertSame( 'redirect', $result->code() );
	}

	public function test_unexpected_success_status_is_invalid(): void {
		$this->assertSame( Result::INVALID, Parser::parse( Endpoints::STATUS, 204, self::JSON, '' )->kind() );
	}

	/**
	 * Error classification cases.
	 *
	 * @return array<string,array{0:int,1:string,2:string,3:array<string,mixed>}>
	 */
	public function error_cases(): array {
		return array(
			'unauthorized'       => array( 401, 'unauthorized', Result::UNAUTHORIZED, array() ),
			'inactive'           => array( 403, 'subscription_inactive', Result::FORBIDDEN, array() ),
			'feature'            => array( 403, 'feature_not_available', Result::FORBIDDEN, array() ),
			'scope'              => array( 403, 'insufficient_scope', Result::FORBIDDEN, array() ),
			'running'            => array( 409, 'scan_already_running', Result::CONFLICT, array() ),
			'cooldown'           => array( 429, 'cooldown_active', Result::COOLDOWN, array( 'retry_after' => 120 ) ),
			'target recent'      => array( 429, 'target_recently_scanned', Result::COOLDOWN, array( 'retry_after' => 900 ) ),
			'budget'             => array( 429, 'daily_scan_budget_reached', Result::BUDGET, array() ),
			'rate limited'       => array( 429, 'rate_limited', Result::RATE_LIMITED, array() ),
			'scanner busy'       => array( 503, 'scanner_busy', Result::SERVER_ERROR, array() ),
			'unsafe url'         => array( 400, 'unsafe_url', Result::REJECTED, array() ),
			'not found'          => array( 404, 'not_found', Result::REJECTED, array() ),
			'plain server error' => array( 500, 'whatever', Result::SERVER_ERROR, array() ),
		);
	}

	/**
	 * @dataProvider error_cases
	 *
	 * @param int                 $http     HTTP status.
	 * @param string              $code     Service code.
	 * @param string              $expected Expected kind.
	 * @param array<string,mixed> $extra    Extra detail fields.
	 */
	public function test_errors_are_classified( int $http, string $code, string $expected, array $extra ): void {
		$result = Parser::parse( Endpoints::STATUS, $http, self::JSON, $this->error_body( $code, $extra ) );

		$this->assertSame( $expected, $result->kind() );
		$this->assertSame( $http, $result->http_status() );
	}

	public function test_retry_after_comes_from_detail_and_is_clamped(): void {
		$result = Parser::parse( Endpoints::SCAN, 429, self::JSON, $this->error_body( 'cooldown_active', array( 'retry_after' => 120 ) ) );
		$this->assertSame( 120, $result->retry_after() );

		$huge = Parser::parse( Endpoints::SCAN, 429, self::JSON, $this->error_body( 'cooldown_active', array( 'retry_after' => 99999999 ) ) );
		$this->assertSame( Parser::MAX_RETRY, $huge->retry_after() );

		$negative = Parser::parse( Endpoints::SCAN, 429, self::JSON, $this->error_body( 'cooldown_active', array( 'retry_after' => -5 ) ) );
		$this->assertSame( 0, $negative->retry_after() );
	}

	public function test_retry_after_header_is_the_fallback(): void {
		$result = Parser::parse( Endpoints::STATUS, 429, self::JSON, $this->error_body( 'rate_limited' ), 60 );

		$this->assertSame( 60, $result->retry_after() );
	}

	public function test_unknown_error_codes_are_dropped(): void {
		$result = Parser::parse( Endpoints::STATUS, 403, self::JSON, $this->error_body( '<script>alert(1)</script>' ) );

		$this->assertSame( Result::FORBIDDEN, $result->kind() );
		$this->assertSame( '', $result->code() );
	}

	public function test_html_error_page_does_not_leak(): void {
		$result = Parser::parse( Endpoints::STATUS, 502, 'text/html', '<html>Bad gateway clp_site_secret</html>' );

		$this->assertSame( Result::SERVER_ERROR, $result->kind() );
		$this->assertSame( '', $result->code() );
		$this->assertSame( array(), $result->data() );
	}

	public function test_error_body_with_wrong_shape_is_tolerated(): void {
		$this->assertSame( Result::UNAUTHORIZED, Parser::parse( Endpoints::STATUS, 401, self::JSON, '{"detail":"unauthorized"}' )->kind() );
		$this->assertSame( Result::SERVER_ERROR, Parser::parse( Endpoints::STATUS, 500, self::JSON, '[]' )->kind() );
	}

	public function test_the_flat_error_form_is_tolerated(): void {
		$result = Parser::parse( Endpoints::STATUS, 403, self::JSON, '{"detail":"subscription_inactive"}' );

		$this->assertSame( Result::FORBIDDEN, $result->kind() );
		$this->assertSame( 'subscription_inactive', $result->code() );

		$free = Parser::parse( Endpoints::STATUS, 403, self::JSON, '{"detail":"Not authenticated <script>"}' );
		$this->assertSame( '', $free->code() );
	}
}
