<?php
/**
 * HTTP response parsing.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one HTTP response into a typed Result. Pure logic, no WordPress calls.
 *
 * The body is decoded with a depth limit and validated against Schema. Error bodies
 * are reduced to a whitelisted code. Raw server text never leaves this class.
 */
final class Parser {

	public const MAX_BODY        = 1048576;
	public const MAX_RETRY       = 86400;
	private const JSON_DEPTH     = 8;
	private const ERROR_CODES    = array(
		'unauthorized',
		'subscription_inactive',
		'feature_not_available',
		'insufficient_scope',
		'scan_already_running',
		'cooldown_active',
		'daily_scan_budget_reached',
		'target_recently_scanned',
		'rate_limited',
		'scanner_busy',
		'unsafe_url',
		'not_found',
	);
	private const SCHEMA_METHODS = array(
		Endpoints::STATUS        => 'status',
		Endpoints::DSE           => 'dse',
		Endpoints::SCAN          => 'scan_accepted',
		Endpoints::SCAN_STATUS   => 'scan_status',
		Endpoints::FREE_SCAN     => 'free_scan_accepted',
		Endpoints::FREE_SCAN_JOB => 'free_scan_status',
	);

	/**
	 * Parses a response.
	 *
	 * @param string $endpoint     Endpoint constant.
	 * @param int    $status       HTTP status code.
	 * @param string $content_type Content-Type header value, empty if absent.
	 * @param string $body         Response body.
	 * @param int    $retry_header Parsed Retry-After header in seconds, 0 if absent.
	 * @return Result
	 */
	public static function parse( string $endpoint, int $status, string $content_type, string $body, int $retry_header = 0 ): Result {
		if ( $status >= 300 && $status < 400 ) {
			// Redirects are never followed. A redirect is a protocol violation.
			return Result::failure( Result::INVALID, 'redirect', 0, $status );
		}

		if ( strlen( $body ) >= self::MAX_BODY ) {
			return Result::failure( Result::INVALID, 'too_large', 0, $status );
		}

		$decoded = self::decode( $content_type, $body );

		if ( $status >= 200 && $status < 300 ) {
			return self::parse_success( $endpoint, $status, $decoded );
		}

		return self::parse_error( $status, $decoded, $retry_header );
	}

	/**
	 * Decodes a JSON body.
	 *
	 * @param string $content_type Content-Type header.
	 * @param string $body         Body.
	 * @return mixed Decoded value, or null if not valid JSON.
	 */
	private static function decode( string $content_type, string $body ) {
		if ( 1 !== preg_match( '#^application/(?:[a-z0-9.+-]+\+)?json\s*(?:;|$)#i', trim( $content_type ) ) ) {
			return null;
		}

		try {
			return json_decode( $body, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return null;
		}
	}

	/**
	 * Handles a 2xx response.
	 *
	 * @param string $endpoint Endpoint constant.
	 * @param int    $status   HTTP status.
	 * @param mixed  $decoded  Decoded body.
	 * @return Result
	 */
	private static function parse_success( string $endpoint, int $status, $decoded ): Result {
		if ( 200 !== $status && 202 !== $status ) {
			return Result::failure( Result::INVALID, 'unexpected_status', 0, $status );
		}

		if ( null === $decoded ) {
			return Result::failure( Result::INVALID, 'not_json', 0, $status );
		}

		if ( isset( self::SCHEMA_METHODS[ $endpoint ] ) ) {
			$method = self::SCHEMA_METHODS[ $endpoint ];
		} else {
			return Result::failure( Result::INVALID, 'unknown_endpoint', 0, $status );
		}

		/**
		 * Validated payload.
		 *
		 * @var array<string,mixed>|null $data
		 */
		$data = call_user_func( array( Schema::class, $method ), $decoded );
		if ( null === $data ) {
			return Result::failure( Result::INVALID, 'schema', 0, $status );
		}

		return Result::ok( $data, $status );
	}

	/**
	 * Handles a non-2xx response.
	 *
	 * @param int   $status       HTTP status.
	 * @param mixed $decoded      Decoded body, may be null.
	 * @param int   $retry_header Retry-After header in seconds.
	 * @return Result
	 */
	private static function parse_error( int $status, $decoded, int $retry_header ): Result {
		$code  = '';
		$retry = $retry_header;

		$detail = is_array( $decoded ) ? ( $decoded['detail'] ?? null ) : null;
		if ( is_string( $detail ) && in_array( $detail, self::ERROR_CODES, true ) ) {
			// Flat form {"detail": "<code>"}, tolerated next to the nested form the service uses.
			$code = $detail;
		} elseif ( is_array( $detail ) ) {
			$candidate = $detail['detail'] ?? null;
			if ( is_string( $candidate ) && in_array( $candidate, self::ERROR_CODES, true ) ) {
				$code = $candidate;
			}
			if ( isset( $detail['retry_after'] ) && is_int( $detail['retry_after'] ) ) {
				$retry = $detail['retry_after'];
			}
		}
		$retry = max( 0, min( self::MAX_RETRY, $retry ) );

		if ( 401 === $status ) {
			return Result::failure( Result::UNAUTHORIZED, 'unauthorized', 0, $status );
		}
		if ( 403 === $status ) {
			return Result::failure( Result::FORBIDDEN, $code, 0, $status );
		}
		if ( 409 === $status ) {
			return Result::failure( Result::CONFLICT, $code, 0, $status );
		}
		if ( 429 === $status ) {
			return self::rate_limit( $code, $retry, $status );
		}
		if ( $status >= 500 ) {
			return Result::failure( Result::SERVER_ERROR, $code, $retry, $status );
		}

		return Result::failure( Result::REJECTED, $code, 0, $status );
	}

	/**
	 * Classifies a 429 response.
	 *
	 * @param string $code   Whitelisted service code.
	 * @param int    $retry  Retry hint in seconds.
	 * @param int    $status HTTP status.
	 * @return Result
	 */
	private static function rate_limit( string $code, int $retry, int $status ): Result {
		if ( 'cooldown_active' === $code || 'target_recently_scanned' === $code ) {
			return Result::failure( Result::COOLDOWN, $code, $retry, $status );
		}
		if ( 'daily_scan_budget_reached' === $code ) {
			return Result::failure( Result::BUDGET, $code, $retry, $status );
		}

		return Result::failure( Result::RATE_LIMITED, 'rate_limited', $retry, $status );
	}
}
