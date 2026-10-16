<?php
/**
 * Transient cache and failure backoff.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Last known status, connection state and the failure backoff.
 */
final class Cache {

	public const STATUS_TTL_FRESH = 21600;
	private const STATUS_KEEP     = 604800;
	private const BACKOFF_MIN     = 300;
	private const BACKOFF_MAX     = 21600;
	private const BACKOFF_KEEP    = 43200;

	public const STATE_CONNECTED    = 'connected';
	public const STATE_UNAUTHORIZED = 'unauthorized';
	public const STATE_INACTIVE     = 'subscription_inactive';
	public const STATE_NO_FEATURE   = 'feature_not_available';
	public const STATE_UNREACHABLE  = 'unreachable';
	public const STATE_INVALID      = 'invalid_response';

	/**
	 * Clock returning a Unix timestamp.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Optional clock for tests.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Current time.
	 *
	 * @return int
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * Cached status payload with fetch time.
	 *
	 * @return array{fetched_at:int,data:array<string,mixed>}|null
	 */
	public function get_status(): ?array {
		$cached = get_transient( Options::T_STATUS );
		if ( ! is_array( $cached ) || ! isset( $cached['fetched_at'], $cached['data'] ) || ! is_int( $cached['fetched_at'] ) || ! is_array( $cached['data'] ) ) {
			return null;
		}

		return array(
			'fetched_at' => $cached['fetched_at'],
			'data'       => $cached['data'],
		);
	}

	/**
	 * Stores a validated status payload.
	 *
	 * @param array<string,mixed> $data Validated status.
	 * @return void
	 */
	public function set_status( array $data ): void {
		set_transient(
			Options::T_STATUS,
			array(
				'fetched_at' => $this->now(),
				'data'       => $data,
			),
			self::STATUS_KEEP
		);
	}

	/**
	 * Whether a cached status is younger than six hours.
	 *
	 * @param array{fetched_at:int,data:array<string,mixed>}|null $cached Cached status.
	 * @return bool
	 */
	public function is_status_fresh( ?array $cached ): bool {
		return null !== $cached && ( $this->now() - $cached['fetched_at'] ) < self::STATUS_TTL_FRESH;
	}

	/**
	 * Drops the cached status.
	 *
	 * @return void
	 */
	public function clear_status(): void {
		delete_transient( Options::T_STATUS );
	}

	/**
	 * Remembers the outcome of the last authenticated call.
	 *
	 * @param string $state One of the STATE_ constants.
	 * @return void
	 */
	public function set_connection_state( string $state ): void {
		set_transient(
			Options::T_CONNECTION,
			array(
				'state'      => $state,
				'checked_at' => $this->now(),
			),
			self::STATUS_KEEP
		);
	}

	/**
	 * Outcome of the last authenticated call.
	 *
	 * @return array{state:string,checked_at:int}|null
	 */
	public function get_connection_state(): ?array {
		$cached = get_transient( Options::T_CONNECTION );
		if ( ! is_array( $cached ) || ! isset( $cached['state'], $cached['checked_at'] ) || ! is_string( $cached['state'] ) || ! is_int( $cached['checked_at'] ) ) {
			return null;
		}

		return array(
			'state'      => $cached['state'],
			'checked_at' => $cached['checked_at'],
		);
	}

	/**
	 * Unix time until which remote calls are suspended, 0 if none.
	 *
	 * @return int
	 */
	public function backoff_until(): int {
		$state = get_transient( Options::T_BACKOFF );
		if ( ! is_array( $state ) || ! isset( $state['until'] ) || ! is_int( $state['until'] ) ) {
			return 0;
		}

		return $state['until'] > $this->now() ? $state['until'] : 0;
	}

	/**
	 * Registers a failure and extends the suspension: 5 minutes, doubling, at most 6 hours.
	 *
	 * @param int $min_delay Lower bound in seconds, e.g. a Retry-After hint.
	 * @return void
	 */
	public function register_failure( int $min_delay = 0 ): void {
		$state    = get_transient( Options::T_BACKOFF );
		$failures = is_array( $state ) && isset( $state['failures'] ) && is_int( $state['failures'] ) ? $state['failures'] : 0;

		$delay = (int) min( self::BACKOFF_MAX, self::BACKOFF_MIN * ( 2 ** min( $failures, 8 ) ) );
		$delay = (int) min( self::BACKOFF_MAX, max( $delay, $min_delay ) );

		set_transient(
			Options::T_BACKOFF,
			array(
				'failures' => $failures + 1,
				'until'    => $this->now() + $delay,
			),
			self::BACKOFF_KEEP
		);
	}

	/**
	 * Lifts the suspension, e.g. after a success or a token change.
	 *
	 * @return void
	 */
	public function clear_backoff(): void {
		delete_transient( Options::T_BACKOFF );
	}

	/**
	 * Drops everything cached about a connection: status, state, backoff, scan state, free scan.
	 *
	 * @return void
	 */
	public function clear_connection_data(): void {
		foreach ( Options::transients() as $transient ) {
			delete_transient( $transient );
		}
	}
}
