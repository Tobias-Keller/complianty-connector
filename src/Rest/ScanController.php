<?php
/**
 * Scan routes for the admin screen.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Rest;

use Complianty\Connector\Api\Client;
use Complianty\Connector\Api\Result;
use Complianty\Connector\Storage\Cache;
use Complianty\Connector\Storage\Options;
use Complianty\Connector\Support\Messages;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /scan starts a scan, GET /scan reports its state.
 *
 * The poll route calls the service at most once per few seconds, however often it is polled.
 */
final class ScanController extends Controller {

	private const POLL_INTERVAL = 4;

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Cache.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @param Client $client API client.
	 * @param Cache  $cache  Cache.
	 */
	public function __construct( Client $client, Cache $cache ) {
		$this->client = $client;
		$this->cache  = $cache;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/scan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(),
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
	 * Starts a scan.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start() {
		$result = $this->client->start_scan();

		if ( $result->is_ok() ) {
			delete_transient( Options::T_SCAN_STATE );
			return new WP_REST_Response( array( 'state' => 'queued' ) );
		}

		if ( Result::CONFLICT === $result->kind() ) {
			return new WP_REST_Response(
				array(
					'state'   => 'running',
					'message' => Messages::for_result( $result, Messages::CONTEXT_SCAN ),
				)
			);
		}

		return $this->error( $result, Messages::CONTEXT_SCAN );
	}

	/**
	 * Reports the scan state. Refreshes the cached status once the scan is finished.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function poll() {
		$cached = get_transient( Options::T_SCAN_STATE );
		if ( is_array( $cached ) && isset( $cached['scan_state'] ) && is_string( $cached['scan_state'] ) ) {
			return $this->state_response( $cached['scan_state'] );
		}

		$result = $this->client->scan_status();
		if ( ! $result->is_ok() ) {
			return $this->error( $result, Messages::CONTEXT_SCAN );
		}

		$state = (string) $result->data()['scan_state'];
		set_transient( Options::T_SCAN_STATE, array( 'scan_state' => $state ), self::POLL_INTERVAL );

		if ( 'idle' === $state ) {
			$status = $this->client->status( true );
			if ( $status->is_ok() ) {
				$this->cache->set_status( $status->data() );
			}
		}

		return $this->state_response( $state );
	}

	/**
	 * Builds the poll response.
	 *
	 * @param string $state Scan state.
	 * @return WP_REST_Response
	 */
	private function state_response( string $state ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'state' => $state,
				'done'  => 'idle' === $state,
			)
		);
	}
}
