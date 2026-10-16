<?php
/**
 * Privacy policy synchronisation.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Frontend;

use Complianty\Connector\Api\Client;
use Complianty\Connector\Api\Result;
use Complianty\Connector\Storage\DseStore;
use Complianty\Connector\Storage\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the privacy policy and keeps the local copy current.
 */
final class DseSync {

	public const FIRST_FETCH_TIMEOUT = 3;
	private const LOCK_SECONDS       = 900;

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Local copy.
	 *
	 * @var DseStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Client   $client API client.
	 * @param DseStore $store  Local copy.
	 */
	public function __construct( Client $client, DseStore $store ) {
		$this->client = $client;
		$this->store  = $store;
	}

	/**
	 * Fetches the policy and updates the copy.
	 *
	 * @param bool $force   Ignore the failure backoff (user initiated).
	 * @param int  $timeout Timeout in seconds.
	 * @return Result
	 */
	public function refresh( bool $force = false, int $timeout = Client::TIMEOUT ): Result {
		$result = $this->client->dse( $force, $timeout );

		if ( $result->is_ok() && ! $this->store->apply( $result->data(), time() ) ) {
			return Result::failure( Result::INVALID, 'empty_after_filter' );
		}

		return $result;
	}

	/**
	 * Schedules a background refresh, at most once per lock period.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( false !== get_transient( Options::T_DSE_LOCK ) || false !== wp_next_scheduled( Options::EVENT_REFRESH_DSE ) ) {
			return;
		}

		set_transient( Options::T_DSE_LOCK, 1, self::LOCK_SECONDS );
		wp_schedule_single_event( time() + 5, Options::EVENT_REFRESH_DSE );
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public function run_scheduled(): void {
		$this->refresh( false );
	}

	/**
	 * One short synchronous fetch for the very first request without any copy.
	 *
	 * A lock keeps parallel visitors from all fetching at once.
	 *
	 * @return void
	 */
	public function first_fetch(): void {
		if ( false !== get_transient( Options::T_DSE_LOCK ) ) {
			return;
		}

		set_transient( Options::T_DSE_LOCK, 1, 30 );
		$this->refresh( false, self::FIRST_FETCH_TIMEOUT );
	}
}
