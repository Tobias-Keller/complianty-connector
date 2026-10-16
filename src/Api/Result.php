<?php
/**
 * Typed API result.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of one API call. Carries validated data or a failure class, never raw server text.
 */
final class Result {

	public const OK           = 'ok';
	public const UNAUTHORIZED = 'unauthorized';
	public const FORBIDDEN    = 'forbidden';
	public const RATE_LIMITED = 'rate_limited';
	public const COOLDOWN     = 'cooldown';
	public const BUDGET       = 'budget';
	public const CONFLICT     = 'conflict';
	public const REJECTED     = 'rejected';
	public const SERVER_ERROR = 'server_error';
	public const NETWORK      = 'network';
	public const INVALID      = 'invalid';
	public const BACKOFF      = 'backoff';
	public const NO_TOKEN     = 'no_token';

	/**
	 * Result kind, one of the constants above.
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * Whitelisted machine code from the service, or an empty string.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * Validated payload for successful calls.
	 *
	 * @var array<string,mixed>
	 */
	private $data;

	/**
	 * Seconds until a retry makes sense, 0 if unknown.
	 *
	 * @var int
	 */
	private $retry_after;

	/**
	 * HTTP status, 0 if there was no response.
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Constructor, use the named constructors.
	 *
	 * @param string              $kind        Result kind.
	 * @param string              $code        Service code.
	 * @param array<string,mixed> $data        Payload.
	 * @param int                 $retry_after Retry hint in seconds.
	 * @param int                 $http_status HTTP status.
	 */
	private function __construct( string $kind, string $code, array $data, int $retry_after, int $http_status ) {
		$this->kind        = $kind;
		$this->code        = $code;
		$this->data        = $data;
		$this->retry_after = $retry_after;
		$this->http_status = $http_status;
	}

	/**
	 * Successful result.
	 *
	 * @param array<string,mixed> $data        Validated payload.
	 * @param int                 $http_status HTTP status.
	 * @return self
	 */
	public static function ok( array $data, int $http_status = 200 ): self {
		return new self( self::OK, '', $data, 0, $http_status );
	}

	/**
	 * Failed result.
	 *
	 * @param string $kind        Failure kind.
	 * @param string $code        Whitelisted service code.
	 * @param int    $retry_after Retry hint in seconds.
	 * @param int    $http_status HTTP status.
	 * @return self
	 */
	public static function failure( string $kind, string $code = '', int $retry_after = 0, int $http_status = 0 ): self {
		return new self( $kind, $code, array(), max( 0, $retry_after ), $http_status );
	}

	/**
	 * Whether the call succeeded.
	 *
	 * @return bool
	 */
	public function is_ok(): bool {
		return self::OK === $this->kind;
	}

	/**
	 * Result kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Service code, e.g. "cooldown_active". Empty if none.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Validated payload.
	 *
	 * @return array<string,mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Retry hint in seconds.
	 *
	 * @return int
	 */
	public function retry_after(): int {
		return $this->retry_after;
	}

	/**
	 * HTTP status, 0 if there was no response.
	 *
	 * @return int
	 */
	public function http_status(): int {
		return $this->http_status;
	}
}
