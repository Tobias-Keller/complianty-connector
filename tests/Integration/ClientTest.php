<?php
/**
 * Api\Client tests.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Api\Client;
use Complianty\Connector\Api\Result;
use Complianty\Connector\Storage\Cache;
use Complianty\Connector\Storage\TokenStore;

/**
 * @covers \Complianty\Connector\Api\Client
 */
final class ClientTest extends IntegrationTestCase {

	/**
	 * Builds a client.
	 *
	 * @return Client
	 */
	private function client(): Client {
		return new Client( new TokenStore(), new Cache() );
	}

	public function test_without_token_nothing_is_sent(): void {
		$result = $this->client()->status();

		$this->assertSame( Result::NO_TOKEN, $result->kind() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_request_is_hardened_and_minimal(): void {
		$this->connect();
		$this->queue_response( 200, $this->status_payload() );

		$result = $this->client()->status();

		$this->assertTrue( $result->is_ok() );
		$this->assertCount( 1, $this->requests );

		$request = $this->requests[0];
		$args    = $request['args'];

		$this->assertSame( 'https://app.complianty.de/api/v1/connect/status', $request['url'] );
		$this->assertSame( 'GET', $args['method'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 8, $args['timeout'] );
		$this->assertSame( 1048576, $args['limit_response_size'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( 'ComplianytConnector/' . COMPLIANTY_CONNECTOR_VERSION, $args['user-agent'] );
		$this->assertEmpty( $args['body'] ?? '' );

		$this->assertSame(
			array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . self::TOKEN,
			),
			$args['headers']
		);
		$this->assertStringNotContainsString( self::TOKEN, $request['url'] );
		$this->assertStringNotContainsString( 'clp_site_', $request['url'] );
	}

	public function test_request_uses_the_safe_http_api(): void {
		$this->connect();
		$this->queue_response( 200, $this->status_payload() );
		$seen = array();
		add_filter(
			'http_request_args',
			static function ( $args ) use ( &$seen ) {
				$seen[] = $args;
				return $args;
			}
		);

		$this->client()->status();

		$this->assertTrue( $seen[0]['reject_unsafe_urls'] );
	}

	public function test_no_site_data_is_sent(): void {
		$this->connect();
		$this->queue_response( 200, $this->status_payload() );

		$this->client()->status();

		$serialized = (string) wp_json_encode( $this->requests );
		$this->assertStringNotContainsString( home_url(), $serialized );
		$this->assertStringNotContainsString( get_bloginfo( 'version' ), str_replace( self::TOKEN, '', $serialized ) );
	}

	public function test_scan_start_has_no_body(): void {
		$this->connect();
		$this->queue_response(
			202,
			array(
				'job_id'     => 'j1',
				'scan_state' => 'queued',
			)
		);

		$result = $this->client()->start_scan();

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertEmpty( $this->requests[0]['args']['body'] ?? '' );
		$this->assertSame( 'https://app.complianty.de/api/v1/connect/scan', $this->requests[0]['url'] );
	}

	public function test_redirect_is_not_followed(): void {
		$this->connect();
		$this->queue_response( 302, '', array( 'location' => 'https://evil.example/steal' ) );

		$result = $this->client()->status();

		$this->assertSame( Result::INVALID, $result->kind() );
		$this->assertCount( 1, $this->requests, 'Only the original request may be made.' );
		$this->assertSame( 0, $this->requests[0]['args']['redirection'] );
	}

	public function test_oversized_response_is_rejected(): void {
		$this->connect();
		$this->queue_response( 200, str_repeat( 'a', 1048576 ) );

		$this->assertSame( Result::INVALID, $this->client()->status()->kind() );
		$this->assertNull( ( new Cache() )->get_status() );
	}

	public function test_wrong_content_type_is_rejected(): void {
		$this->connect();
		$this->queue_response( 200, (string) wp_json_encode( $this->status_payload() ), array( 'content-type' => 'text/html' ) );

		$this->assertSame( Result::INVALID, $this->client()->status()->kind() );
	}

	public function test_schema_violation_is_rejected(): void {
		$this->connect();
		$this->queue_response(
			200,
			array(
				'status'     => 'fine',
				'scan_state' => 'idle',
			)
		);

		$this->assertSame( Result::INVALID, $this->client()->status()->kind() );
	}

	public function test_network_error_sets_backoff_and_blocks_further_calls(): void {
		$this->connect();
		$this->queue_error( 'cURL error 28: timeout' );

		$result = $this->client()->status();
		$this->assertSame( Result::NETWORK, $result->kind() );
		$this->assertSame( Cache::STATE_UNREACHABLE, ( new Cache() )->get_connection_state()['state'] );

		$again = $this->client()->status();
		$this->assertSame( Result::BACKOFF, $again->kind() );
		$this->assertGreaterThan( 0, $again->retry_after() );
		$this->assertCount( 1, $this->requests, 'The second call must not reach the network.' );
	}

	public function test_forced_call_ignores_the_backoff(): void {
		$this->connect();
		$this->queue_error( 'boom' );
		$this->client()->status();

		$this->queue_response( 200, $this->status_payload() );
		$result = $this->client()->status( true );

		$this->assertTrue( $result->is_ok() );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 0, ( new Cache() )->backoff_until(), 'A success lifts the backoff.' );
	}

	public function test_unauthorized_is_typed_and_remembered(): void {
		$this->connect();
		$this->queue_service_error( 401, 'unauthorized' );

		$result = $this->client()->status();

		$this->assertSame( Result::UNAUTHORIZED, $result->kind() );
		$this->assertSame( Cache::STATE_UNAUTHORIZED, ( new Cache() )->get_connection_state()['state'] );
		$this->assertGreaterThan( 0, ( new Cache() )->backoff_until() );
	}

	public function test_forbidden_codes_set_the_matching_state(): void {
		$this->connect();

		$this->queue_service_error( 403, 'subscription_inactive' );
		$this->client()->status( true );
		$this->assertSame( Cache::STATE_INACTIVE, ( new Cache() )->get_connection_state()['state'] );

		$this->queue_service_error( 403, 'feature_not_available' );
		$this->client()->status( true );
		$this->assertSame( Cache::STATE_NO_FEATURE, ( new Cache() )->get_connection_state()['state'] );

		$this->queue_service_error( 403, 'insufficient_scope' );
		$result = $this->client()->start_scan();
		$this->assertSame( Result::FORBIDDEN, $result->kind() );
		$this->assertSame( Cache::STATE_CONNECTED, ( new Cache() )->get_connection_state()['state'] );
	}

	public function test_scan_business_errors_do_not_cause_backoff(): void {
		$this->connect();

		$this->queue_service_error( 429, 'cooldown_active', array( 'retry_after' => 300 ) );
		$cooldown = $this->client()->start_scan();
		$this->assertSame( Result::COOLDOWN, $cooldown->kind() );
		$this->assertSame( 300, $cooldown->retry_after() );

		$this->queue_service_error( 429, 'daily_scan_budget_reached' );
		$this->assertSame( Result::BUDGET, $this->client()->start_scan()->kind() );

		$this->queue_service_error( 409, 'scan_already_running' );
		$this->assertSame( Result::CONFLICT, $this->client()->start_scan()->kind() );

		$this->assertSame( 0, ( new Cache() )->backoff_until() );
	}

	public function test_rate_limit_backs_off_for_the_hint(): void {
		$this->connect();
		$this->queue_response( 429, array( 'detail' => array( 'detail' => 'rate_limited' ) ), array( 'retry-after' => '60' ) );

		$result = $this->client()->status();

		$this->assertSame( Result::RATE_LIMITED, $result->kind() );
		$this->assertSame( 60, $result->retry_after() );
		$this->assertGreaterThanOrEqual( 60, ( new Cache() )->backoff_until() - time() );
	}

	public function test_server_error_body_does_not_leak(): void {
		$this->connect();
		$this->queue_response( 500, '<html>Internal error ' . self::TOKEN . '</html>', array( 'content-type' => 'text/html' ) );

		$result = $this->client()->status();

		$this->assertSame( Result::SERVER_ERROR, $result->kind() );
		$this->assertSame( '', $result->code() );
		$this->assertSame( array(), $result->data() );
	}

	public function test_free_scan_start_sends_only_the_site_url_and_no_token(): void {
		$this->connect();
		$this->queue_response(
			202,
			array(
				'job_id'       => 'demo-0123456789ab',
				'status'       => 'queued',
				'access_token' => 'abcdefghijklmnopqrstuvwxyz012345',
			)
		);

		$result = $this->client()->start_free_scan( home_url() );

		$this->assertTrue( $result->is_ok() );
		$request = $this->requests[0];
		$this->assertSame( 'https://app.complianty.de/api/v1/connect/free-scan', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( array( 'url' => home_url() ), json_decode( $request['args']['body'], true ) );
		$this->assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
		$this->assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( $request ) );
		$this->assertSame( 0, ( new Cache() )->backoff_until() );
	}

	public function test_free_scan_failures_never_cause_backoff(): void {
		$this->queue_service_error( 429, 'target_recently_scanned', array( 'retry_after' => 7200 ) );
		$result = $this->client()->start_free_scan( home_url() );

		$this->assertSame( Result::COOLDOWN, $result->kind() );
		$this->assertSame( 7200, $result->retry_after() );
		$this->assertSame( 0, ( new Cache() )->backoff_until() );

		$this->queue_service_error( 503, 'scanner_busy' );
		$this->assertSame( Result::SERVER_ERROR, $this->client()->start_free_scan( home_url() )->kind() );
		$this->assertNull( ( new Cache() )->get_connection_state() );
	}

	public function test_free_scan_job_token_travels_in_a_header_only(): void {
		$this->queue_response(
			200,
			array(
				'job_id' => 'demo-0123456789ab',
				'status' => 'running',
			)
		);

		$result = $this->client()->free_scan_job( 'demo-0123456789ab', 'abcdefghijklmnopqrstuvwxyz012345' );

		$this->assertTrue( $result->is_ok() );
		$request = $this->requests[0];
		$this->assertSame( 'https://app.complianty.de/api/v1/connect/free-scan/demo-0123456789ab', $request['url'] );
		$this->assertStringNotContainsString( 'abcdefghijklmnop', $request['url'] );
		$this->assertSame( 'abcdefghijklmnopqrstuvwxyz012345', $request['args']['headers']['X-Complianty-Job-Token'] );
	}

	public function test_free_scan_job_rejects_unsafe_identifiers_without_a_request(): void {
		$bad_ids = array( '../../status', 'demo-1/../x', "demo-1\r\nX: y", 'demo 1', '', str_repeat( 'a', 41 ), 'demo-1?token=x' );

		foreach ( $bad_ids as $job_id ) {
			$result = $this->client()->free_scan_job( $job_id, 'abcdefghijklmnopqrstuvwxyz012345' );
			$this->assertSame( Result::REJECTED, $result->kind() );
		}
		$this->assertSame( Result::REJECTED, $this->client()->free_scan_job( 'demo-1', "short\n" )->kind() );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Base URL cases.
	 *
	 * @return array<string,array{0:mixed,1:string,2:string}>
	 */
	public function base_cases(): array {
		$default = Client::DEFAULT_BASE;

		return array(
			'production ignores override' => array( 'https://staging.example/api', 'production', $default ),
			'staging accepts https'       => array( 'https://staging.example/api/v1/connect/', 'staging', 'https://staging.example/api/v1/connect' ),
			'local accepts https'         => array( 'https://dev.example', 'local', 'https://dev.example' ),
			'http is refused'             => array( 'http://staging.example/api', 'staging', $default ),
			'credentials are refused'     => array( 'https://user:pw@staging.example', 'staging', $default ),
			'query is refused'            => array( 'https://staging.example/?x=1', 'staging', $default ),
			'fragment is refused'         => array( 'https://staging.example/#x', 'staging', $default ),
			'other scheme is refused'     => array( 'ftp://staging.example', 'staging', $default ),
			'javascript is refused'       => array( 'javascript:alert(1)', 'staging', $default ),
			'no host is refused'          => array( 'https://', 'staging', $default ),
			'non string is refused'       => array( array( 'https://staging.example' ), 'staging', $default ),
			'empty is refused'            => array( '', 'staging', $default ),
		);
	}

	/**
	 * @dataProvider base_cases
	 *
	 * @param mixed  $override    Override.
	 * @param string $environment Environment type.
	 * @param string $expected    Expected base URL.
	 */
	public function test_base_url_resolution( $override, string $environment, string $expected ): void {
		$this->assertSame( $expected, Client::resolve_base( $override, $environment ) );
	}
}
