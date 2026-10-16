<?php
/**
 * Free scan routes for the admin screen.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Rest;

use Complianty\Connector\Api\Client;
use Complianty\Connector\Api\Result;
use Complianty\Connector\Storage\Options;
use Complianty\Connector\Storage\TokenStore;
use Complianty\Connector\Support\Messages;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /free-scan starts the free check of this website, GET /free-scan reports it.
 *
 * Only home_url() is ever sent. There is no parameter for an address. The request body must
 * carry the explicit confirmation that the user pressed the button after reading the notice.
 */
final class FreeScanController extends Controller {

	private const JOB_MAX_AGE = 900;
	private const KEEP        = 21600;
	private const MIN_POLL    = 3;

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Token storage.
	 *
	 * @var TokenStore
	 */
	private $tokens;

	/**
	 * Constructor.
	 *
	 * @param Client     $client API client.
	 * @param TokenStore $tokens Token storage.
	 */
	public function __construct( Client $client, TokenStore $tokens ) {
		$this->client = $client;
		$this->tokens = $tokens;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/free-scan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'confirm' => array(
							'type'              => 'boolean',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_confirm' ),
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'poll' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Only an explicit true counts as confirmation.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public function validate_confirm( $value ): bool {
		return true === $value || 'true' === $value || 1 === $value || '1' === $value;
	}

	/**
	 * Starts the free scan.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start() {
		if ( $this->tokens->has_token() ) {
			return new WP_Error( 'complianty_connected', __( 'The free check is only available while no token is set.', 'complianty-connector' ), array( 'status' => 403 ) );
		}

		$job = $this->job();
		if ( null !== $job && in_array( $job['status'], array( 'queued', 'running' ), true ) && ( time() - $job['started_at'] ) < self::JOB_MAX_AGE ) {
			return new WP_REST_Response( array( 'status' => $job['status'] ) );
		}

		$result = $this->client->start_free_scan( home_url() );
		if ( ! $result->is_ok() ) {
			return $this->error( $result, Messages::CONTEXT_FREE_SCAN );
		}

		$data = $result->data();
		$this->save(
			array(
				'job_id'       => (string) $data['job_id'],
				'access_token' => (string) $data['access_token'],
				'status'       => (string) $data['status'],
				'started_at'   => time(),
				'polled_at'    => 0,
				'failed'       => false,
				'result'       => null,
			)
		);

		return new WP_REST_Response( array( 'status' => (string) $data['status'] ) );
	}

	/**
	 * Reports the state of the stored job and fetches the result when it is ready.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function poll() {
		$job = $this->job();
		if ( null === $job ) {
			return new WP_Error( 'complianty_no_job', __( 'No check is running.', 'complianty-connector' ), array( 'status' => 404 ) );
		}

		if ( ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
			return $this->job_response( $job );
		}

		if ( ( time() - $job['started_at'] ) >= self::JOB_MAX_AGE ) {
			$job['status'] = 'error';
			$job['failed'] = true;
			$this->save( $job );
			return $this->job_response( $job );
		}

		if ( ( time() - $job['polled_at'] ) < self::MIN_POLL ) {
			return $this->job_response( $job );
		}

		$job['polled_at'] = time();
		$result           = $this->client->free_scan_job( $job['job_id'], $job['access_token'] );
		if ( ! $result->is_ok() ) {
			$this->save( $job );
			if ( Result::REJECTED === $result->kind() ) {
				// The job is gone on the service side.
				$job['status'] = 'error';
				$job['failed'] = true;
				$this->save( $job );
				return $this->job_response( $job );
			}
			return $this->error( $result, Messages::CONTEXT_FREE_SCAN );
		}

		$data          = $result->data();
		$job['status'] = (string) $data['status'];
		$job['failed'] = true === $data['failed'];
		if ( 'done' === $job['status'] && is_array( $data['result'] ) ) {
			$job['result'] = $data['result'];
		}
		$this->save( $job );

		return $this->job_response( $job );
	}

	/**
	 * Poll response without secrets.
	 *
	 * @param array<string,mixed> $job Stored job.
	 * @return WP_REST_Response
	 */
	private function job_response( array $job ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'status' => $job['status'],
				'done'   => in_array( $job['status'], array( 'done', 'error' ), true ),
				'failed' => true === $job['failed'],
			)
		);
	}

	/**
	 * Reads the stored job.
	 *
	 * @return array{job_id:string,access_token:string,status:string,started_at:int,polled_at:int,failed:bool,result:?array<string,mixed>}|null
	 */
	private function job(): ?array {
		$job = get_transient( Options::T_FREE_SCAN );
		if (
			! is_array( $job )
			|| ! isset( $job['job_id'], $job['access_token'], $job['status'], $job['started_at'] )
			|| ! is_string( $job['job_id'] ) || ! is_string( $job['access_token'] ) || ! is_string( $job['status'] ) || ! is_int( $job['started_at'] )
		) {
			return null;
		}

		return array(
			'job_id'       => $job['job_id'],
			'access_token' => $job['access_token'],
			'status'       => $job['status'],
			'started_at'   => $job['started_at'],
			'polled_at'    => isset( $job['polled_at'] ) && is_int( $job['polled_at'] ) ? $job['polled_at'] : 0,
			'failed'       => true === ( $job['failed'] ?? false ),
			'result'       => isset( $job['result'] ) && is_array( $job['result'] ) ? $job['result'] : null,
		);
	}

	/**
	 * Stores the job.
	 *
	 * @param array<string,mixed> $job Job data.
	 * @return void
	 */
	private function save( array $job ): void {
		set_transient( Options::T_FREE_SCAN, $job, self::KEEP );
	}
}
