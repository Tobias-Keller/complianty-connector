<?php
/**
 * REST route tests: capability, nonce, free scan rules, throttling.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Admin\Notices;
use Complianty\Connector\Admin\StatusPage;
use Complianty\Connector\Api\Client;
use Complianty\Connector\Storage\Cache;
use Complianty\Connector\Storage\Options;
use Complianty\Connector\Storage\TokenStore;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * @covers \Complianty\Connector\Rest\ScanController
 * @covers \Complianty\Connector\Rest\FreeScanController
 * @covers \Complianty\Connector\Rest\Controller
 */
final class RestTest extends IntegrationTestCase {

	private const JOB_ID = 'demo-0123456789ab';
	private const JOB_TK = 'abcdefghijklmnopqrstuvwxyz012345';

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_SERVER['HTTP_X_WP_NONCE'], $GLOBALS['wp_rest_auth_cookie'] );
		parent::tear_down();
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $route  Route below the namespace.
	 * @param array<string,mixed> $params Body params.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/complianty-connector/v1/' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Routes and methods.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function routes(): array {
		return array(
			'scan start'      => array( 'POST', 'scan' ),
			'scan poll'       => array( 'GET', 'scan' ),
			'free scan start' => array( 'POST', 'free-scan' ),
			'free scan poll'  => array( 'GET', 'free-scan' ),
		);
	}

	/**
	 * @dataProvider routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 */
	public function test_only_administrators_may_call_the_routes( string $method, string $route ): void {
		$this->connect();

		foreach ( array( null, 'subscriber', 'contributor', 'author', 'editor' ) as $role ) {
			null === $role ? wp_set_current_user( 0 ) : $this->login_as( $role );
			$response = $this->dispatch( $method, $route, array( 'confirm' => true ) );

			$this->assertContains( $response->get_status(), array( 401, 403 ), 'Role: ' . ( $role ?? 'anonymous' ) );
		}

		$this->assertCount( 0, $this->requests );
	}

	/**
	 * @dataProvider routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 */
	public function test_cookie_authentication_without_a_nonce_is_refused( string $method, string $route ): void {
		$this->connect();
		$this->login_as( 'administrator' );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$GLOBALS['wp_rest_auth_cookie'] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates a request authenticated by the login cookie.

		// What core does for every REST request before the route runs.
		$auth = apply_filters( 'rest_authentication_errors', null );
		$this->assertTrue( is_wp_error( $auth ) || 0 === get_current_user_id() );
		$response = $this->dispatch( $method, $route, array( 'confirm' => true ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_cookie_authentication_with_a_valid_nonce_works(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$this->queue_response(
			202,
			array(
				'job_id'     => 'j1',
				'scan_state' => 'queued',
			)
		);

		$this->assertNull( apply_filters( 'rest_authentication_errors', null ) );
		$this->assertSame( 200, $this->dispatch( 'POST', 'scan' )->get_status() );
	}

	public function test_scan_start_succeeds(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->queue_response(
			202,
			array(
				'job_id'     => 'j1',
				'scan_state' => 'queued',
			)
		);

		$response = $this->dispatch( 'POST', 'scan' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'queued', $response->get_data()['state'] );
	}

	/**
	 * Failure cases for starting a scan.
	 *
	 * @return array<string,array{0:int,1:string,2:int,3:string}>
	 */
	public function scan_failures(): array {
		return array(
			'cooldown'     => array( 429, 'cooldown_active', 429, 'A scan was started recently' ),
			'budget'       => array( 429, 'daily_scan_budget_reached', 429, 'daily scan budget' ),
			'scope'        => array( 403, 'insufficient_scope', 403, 'not allowed to do this' ),
			'inactive'     => array( 403, 'subscription_inactive', 403, 'subscription' ),
			'unauthorized' => array( 401, 'unauthorized', 502, 'invalid or was revoked' ),
			'server'       => array( 500, 'x', 503, 'reports a problem' ),
		);
	}

	/**
	 * @dataProvider scan_failures
	 *
	 * @param int    $upstream_status Status from the service.
	 * @param string $detail          Service code.
	 * @param int    $expected_status Expected REST status.
	 * @param string $expected_text   Expected message part.
	 */
	public function test_scan_start_failures_become_plain_messages( int $upstream_status, string $detail, int $expected_status, string $expected_text ): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->queue_service_error( $upstream_status, $detail, array( 'retry_after' => 600 ) );

		$response = $this->dispatch( 'POST', 'scan' );

		$this->assertSame( $expected_status, $response->get_status() );
		$this->assertStringContainsString( $expected_text, $response->get_data()['message'] );
		$this->assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_a_running_scan_is_reported_as_running(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->queue_service_error( 409, 'scan_already_running' );

		$response = $this->dispatch( 'POST', 'scan' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'running', $response->get_data()['state'] );
	}

	public function test_scan_poll_calls_the_service_at_most_once_per_interval(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->queue_response(
			200,
			array(
				'scan_state'      => 'running',
				'last_scanned_at' => null,
				'last_scan_error' => null,
			)
		);

		for ( $i = 0; $i < 10; $i++ ) {
			$response = $this->dispatch( 'GET', 'scan' );
			$this->assertSame( 'running', $response->get_data()['state'] );
			$this->assertFalse( $response->get_data()['done'] );
		}

		$this->assertCount( 1, $this->requests );
	}

	public function test_scan_poll_refreshes_the_status_when_the_scan_is_done(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		( new Cache() )->set_status( $this->status_payload( array( 'status' => 'pending' ) ) );
		$this->queue_response(
			200,
			array(
				'scan_state'      => 'idle',
				'last_scanned_at' => '2026-10-16T09:00:00Z',
				'last_scan_error' => null,
			)
		);
		$this->queue_response(
			200,
			$this->status_payload(
				array(
					'status'        => 'ok',
					'open_critical' => 0,
				)
			)
		);

		$response = $this->dispatch( 'GET', 'scan' );

		$this->assertTrue( $response->get_data()['done'] );
		$this->assertSame( 'ok', ( new Cache() )->get_status()['data']['status'] );
	}

	public function test_scan_poll_does_not_hammer_a_failing_service(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->queue_error( 'timeout' );

		$this->assertSame( 503, $this->dispatch( 'GET', 'scan' )->get_status() );
		$this->assertSame( 503, $this->dispatch( 'GET', 'scan' )->get_status() );
		$this->assertSame( 503, $this->dispatch( 'GET', 'scan' )->get_status() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_free_scan_requires_an_explicit_confirmation(): void {
		$this->login_as( 'administrator' );

		foreach ( array( array(), array( 'confirm' => false ), array( 'confirm' => 'no' ), array( 'confirm' => '' ), array( 'confirm' => 0 ) ) as $params ) {
			$response = $this->dispatch( 'POST', 'free-scan', $params );
			$this->assertSame( 400, $response->get_status() );
		}

		$this->assertCount( 0, $this->requests );
	}

	public function test_free_scan_sends_only_the_home_url_whatever_the_request_says(): void {
		$this->login_as( 'administrator' );
		$this->queue_response(
			202,
			array(
				'job_id'       => self::JOB_ID,
				'status'       => 'queued',
				'access_token' => self::JOB_TK,
			)
		);

		$response = $this->dispatch(
			'POST',
			'free-scan',
			array(
				'confirm' => true,
				'url'     => 'https://victim.example/',
				'target'  => 'https://victim.example/',
				'host'    => 'victim.example',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( array( 'url' => home_url() ), json_decode( $this->requests[0]['args']['body'], true ) );
		$this->assertStringNotContainsString( 'victim', (string) wp_json_encode( $this->requests ) );
	}

	public function test_free_scan_responses_never_contain_the_job_token(): void {
		$this->login_as( 'administrator' );
		$this->queue_response(
			202,
			array(
				'job_id'       => self::JOB_ID,
				'status'       => 'queued',
				'access_token' => self::JOB_TK,
			)
		);

		$start = $this->dispatch( 'POST', 'free-scan', array( 'confirm' => true ) );
		$this->assertStringNotContainsString( self::JOB_TK, (string) wp_json_encode( $start->get_data() ) );

		$job              = get_transient( Options::T_FREE_SCAN );
		$job['polled_at'] = 0;
		set_transient( Options::T_FREE_SCAN, $job, 3600 );
		$this->queue_response(
			200,
			array(
				'job_id' => self::JOB_ID,
				'status' => 'running',
			)
		);
		$poll = $this->dispatch( 'GET', 'free-scan' );
		$this->assertStringNotContainsString( self::JOB_TK, (string) wp_json_encode( $poll->get_data() ) );
		$this->assertSame( self::JOB_TK, $this->requests[1]['args']['headers']['X-Complianty-Job-Token'] );
		$this->assertStringNotContainsString( self::JOB_TK, $this->requests[1]['url'] );
	}

	public function test_free_scan_is_refused_when_a_token_is_set(): void {
		$this->connect();
		$this->login_as( 'administrator' );

		$response = $this->dispatch( 'POST', 'free-scan', array( 'confirm' => true ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_free_scan_does_not_start_twice_while_running(): void {
		$this->login_as( 'administrator' );
		$this->queue_response(
			202,
			array(
				'job_id'       => self::JOB_ID,
				'status'       => 'queued',
				'access_token' => self::JOB_TK,
			)
		);

		$this->dispatch( 'POST', 'free-scan', array( 'confirm' => true ) );
		$again = $this->dispatch( 'POST', 'free-scan', array( 'confirm' => true ) );

		$this->assertSame( 200, $again->get_status() );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * Failures when starting the free scan.
	 *
	 * @return array<string,array{0:int,1:string,2:int,3:string}>
	 */
	public function free_scan_failures(): array {
		return array(
			'recently scanned' => array( 429, 'target_recently_scanned', 429, 'checked recently' ),
			'rate limited'     => array( 429, 'rate_limited', 429, 'Too many requests' ),
			'busy'             => array( 503, 'scanner_busy', 503, 'scanner is busy' ),
			'unsafe'           => array( 400, 'unsafe_url', 502, 'cannot be checked from outside' ),
		);
	}

	/**
	 * @dataProvider free_scan_failures
	 *
	 * @param int    $upstream Status from the service.
	 * @param string $detail   Service code.
	 * @param int    $expected Expected REST status.
	 * @param string $text     Expected message part.
	 */
	public function test_free_scan_start_failures( int $upstream, string $detail, int $expected, string $text ): void {
		$this->login_as( 'administrator' );
		$this->queue_service_error(
			$upstream,
			$detail,
			array(
				'retry_after' => 7200,
				'reason'      => 'internal host 10.0.0.1',
			)
		);

		$response = $this->dispatch( 'POST', 'free-scan', array( 'confirm' => true ) );

		$this->assertSame( $expected, $response->get_status() );
		$this->assertStringContainsString( $text, $response->get_data()['message'] );
		$this->assertStringNotContainsString( '10.0.0.1', (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 0, ( new Cache() )->backoff_until() );
	}

	public function test_free_scan_result_is_stored_and_rendered_escaped(): void {
		$this->login_as( 'administrator' );
		$this->queue_response(
			202,
			array(
				'job_id'       => self::JOB_ID,
				'status'       => 'queued',
				'access_token' => self::JOB_TK,
			)
		);
		$this->dispatch( 'POST', 'free-scan', array( 'confirm' => true ) );

		$job              = get_transient( Options::T_FREE_SCAN );
		$job['polled_at'] = 0;
		set_transient( Options::T_FREE_SCAN, $job, 3600 );

		$this->queue_response(
			200,
			array(
				'job_id' => self::JOB_ID,
				'status' => 'done',
				'result' => array(
					'url'               => 'https://example.test',
					'cmp_detected'      => true,
					'cmp_name'          => '<script>alert(1)</script>',
					'consent_required'  => true,
					'total_tools_found' => 4,
					'total_violations'  => 1,
					'preview_tools'     => array(
						array(
							'name'        => '<img src=x onerror=alert(1)>',
							'category'    => 'analytics',
							'pre_consent' => true,
						),
					),
					'impressum_overall' => 'ok',
					'ssl_overall'       => 'ok',
					'scanned_at'        => '2026-10-16T08:30:00+00:00',
				),
			)
		);
		$poll = $this->dispatch( 'GET', 'free-scan' );
		$this->assertTrue( $poll->get_data()['done'] );

		$client = new Client( new TokenStore(), new Cache() );
		$html   = $this->capture(
			static function () use ( $client ) {
				$page = new StatusPage( $client, new Cache() );
				$page->render_free_scan( $page->free_scan_job() );
			}
		);

		$this->assertStringContainsString( '4 tools were detected', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( self::JOB_TK, $html );
		$this->assertStringContainsString( 'https://app.complianty.de/registrieren', $html );
		$this->assertStringNotContainsString( 'type="email"', $html );
		$this->assertStringNotContainsString( '<input', $html, 'The free check collects nothing.' );
	}

	public function test_free_scan_poll_is_throttled(): void {
		$this->login_as( 'administrator' );
		$this->queue_response(
			202,
			array(
				'job_id'       => self::JOB_ID,
				'status'       => 'queued',
				'access_token' => self::JOB_TK,
			)
		);
		$this->dispatch( 'POST', 'free-scan', array( 'confirm' => true ) );

		$job              = get_transient( Options::T_FREE_SCAN );
		$job['polled_at'] = 0;
		set_transient( Options::T_FREE_SCAN, $job, 3600 );
		$this->queue_response(
			200,
			array(
				'job_id' => self::JOB_ID,
				'status' => 'running',
			)
		);

		for ( $i = 0; $i < 5; $i++ ) {
			$this->dispatch( 'GET', 'free-scan' );
		}

		$this->assertCount( 2, $this->requests, 'One start request and a single poll.' );
	}

	public function test_a_job_that_never_finishes_is_given_up(): void {
		$this->login_as( 'administrator' );
		set_transient(
			Options::T_FREE_SCAN,
			array(
				'job_id'       => self::JOB_ID,
				'access_token' => self::JOB_TK,
				'status'       => 'running',
				'started_at'   => time() - 4000,
				'polled_at'    => 0,
				'failed'       => false,
				'result'       => null,
			),
			3600
		);

		$data = $this->dispatch( 'GET', 'free-scan' )->get_data();

		$this->assertTrue( $data['done'] );
		$this->assertTrue( $data['failed'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_a_job_that_is_gone_on_the_service_ends_the_check(): void {
		$this->login_as( 'administrator' );
		set_transient(
			Options::T_FREE_SCAN,
			array(
				'job_id'       => self::JOB_ID,
				'access_token' => self::JOB_TK,
				'status'       => 'running',
				'started_at'   => time(),
				'polled_at'    => 0,
				'failed'       => false,
				'result'       => null,
			),
			3600
		);
		$this->queue_service_error( 404, 'not_found' );

		$data = $this->dispatch( 'GET', 'free-scan' )->get_data();

		$this->assertTrue( $data['failed'] );
	}

	public function test_poll_without_a_job_is_a_404(): void {
		$this->login_as( 'administrator' );

		$this->assertSame( 404, $this->dispatch( 'GET', 'free-scan' )->get_status() );
	}

	public function test_notices_do_not_leak_between_users(): void {
		$first = $this->login_as( 'administrator' );
		( new Notices() )->flash_success( 'dse_refreshed' );
		$this->login_as( 'administrator' );

		$this->assertSame( '', $this->capture( array( new Notices(), 'render' ) ) );
		$this->assertNotEmpty( get_transient( 'complianty_flash_' . $first ) );
	}
}
