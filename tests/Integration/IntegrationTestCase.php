<?php
/**
 * Base class for integration tests.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Storage\TokenStore;
use WP_Error;
use WP_UnitTestCase;

/**
 * Intercepts every outgoing HTTP request, records it and answers from a queue.
 * A request without a queued answer fails with an error, so a test notices unexpected network use.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase {

	protected const TOKEN = 'clp_site_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-ab';

	/**
	 * Recorded requests.
	 *
	 * @var array<int,array{url:string,args:array<string,mixed>}>
	 */
	protected $requests = array();

	/**
	 * Queued answers.
	 *
	 * @var array<int,mixed>
	 */
	private $queue = array();

	public function set_up() {
		parent::set_up();
		unload_textdomain( 'complianty-connector' );
		$this->requests = array();
		$this->queue    = array();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		parent::tear_down();
	}

	/**
	 * Filter callback for pre_http_request.
	 *
	 * @param mixed               $pre  Preemptive value.
	 * @param array<string,mixed> $args Request args.
	 * @param string              $url  URL.
	 * @return mixed
	 */
	public function intercept( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( array() === $this->queue ) {
			return new WP_Error( 'complianty_test_unexpected_request', 'No answer queued for ' . $url );
		}

		return array_shift( $this->queue );
	}

	/**
	 * Queues an HTTP answer.
	 *
	 * @param int                 $code    Status code.
	 * @param array<mixed>|string $body    Body, arrays are JSON encoded.
	 * @param array<string,mixed> $headers Headers, content-type defaults to JSON.
	 * @return void
	 */
	protected function queue_response( int $code, $body, array $headers = array() ): void {
		$this->queue[] = array(
			'headers'  => array_merge( array( 'content-type' => 'application/json' ), $headers ),
			'body'     => is_array( $body ) ? (string) wp_json_encode( $body ) : $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Queues a transport error.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	protected function queue_error( string $message ): void {
		$this->queue[] = new WP_Error( 'http_request_failed', $message );
	}

	/**
	 * Queues an error body in the service format.
	 *
	 * @param int                 $code   Status code.
	 * @param string              $detail Service code.
	 * @param array<string,mixed> $extra  Extra detail fields.
	 * @return void
	 */
	protected function queue_service_error( int $code, string $detail, array $extra = array() ): void {
		$this->queue_response( $code, array( 'detail' => array_merge( array( 'detail' => $detail ), $extra ) ) );
	}

	/**
	 * Stores the test token the normal way.
	 *
	 * @return void
	 */
	protected function connect(): void {
		$this->assertTrue( ( new TokenStore() )->set( self::TOKEN ) );
	}

	/**
	 * A valid status payload.
	 *
	 * @param array<string,mixed> $override Fields to replace.
	 * @return array<string,mixed>
	 */
	protected function status_payload( array $override = array() ): array {
		return array_merge(
			array(
				'status'               => 'gaps',
				'open_critical'        => 1,
				'open_important'       => 2,
				'last_scanned_at'      => '2026-10-16T08:30:00Z',
				'scan_state'           => 'idle',
				'scan_trigger_allowed' => true,
				'todos'                => array(
					array(
						'id'       => 'a1',
						'type'     => 'tool_pre_consent',
						'priority' => 'critical',
						'title'    => 'Tool before consent',
						'text'     => 'Details',
					),
				),
			),
			$override
		);
	}

	/**
	 * A valid privacy policy payload.
	 *
	 * @param string $state State.
	 * @param string $html  HTML.
	 * @return array<string,mixed>
	 */
	protected function dse_payload( string $state = 'active', string $html = '<h2>Datenschutz</h2><p>Text</p>' ): array {
		$has_html = in_array( $state, array( 'active', 'lapsed' ), true );

		return array(
			'state'        => $state,
			'html'         => $has_html ? $html : null,
			'approved_at'  => 'active' === $state ? '2026-10-16T08:30:00Z' : null,
			'generated_at' => '2026-10-15T08:30:00Z',
		);
	}

	/**
	 * Creates and activates a user.
	 *
	 * @param string $role Role.
	 * @return int User ID.
	 */
	protected function login_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Captures output of a callable.
	 *
	 * @param callable $callback Callback.
	 * @return string
	 */
	protected function capture( callable $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}
}
