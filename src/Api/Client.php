<?php
/**
 * Hardened API client.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Api;

use Complianty\Connector\Storage\Cache;
use Complianty\Connector\Storage\TokenStore;
use Complianty\Connector\Support\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The only place in the plugin that talks to the Complianty service.
 *
 * Rules, all enforced here:
 * - fixed https base URL, override only outside production;
 * - wp_safe_remote_request without redirects, 8 second timeout, 1 MB response limit;
 * - the token goes into the Authorization header only, never into a URL or body;
 * - nothing is sent except the token and the plugin user agent;
 * - every response is validated against Schema, failures become typed Results;
 * - failures suspend further calls with an exponential backoff.
 */
final class Client {

	public const DEFAULT_BASE = 'https://app.complianty.de/api/v1/connect';
	public const TIMEOUT      = 8;
	public const JOB_HEADER   = 'X-Complianty-Job-Token';

	/**
	 * Token storage.
	 *
	 * @var TokenStore
	 */
	private $tokens;

	/**
	 * Cache and backoff.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @param TokenStore $tokens Token storage.
	 * @param Cache      $cache  Cache and backoff.
	 */
	public function __construct( TokenStore $tokens, Cache $cache ) {
		$this->tokens = $tokens;
		$this->cache  = $cache;
	}

	/**
	 * Resolves the base URL.
	 *
	 * COMPLIANTY_API_BASE is honoured only outside production and only for plain https URLs
	 * without credentials, query or fragment. Anything else falls back to the default.
	 *
	 * @return string
	 */
	public function base_url(): string {
		if ( ! defined( 'COMPLIANTY_API_BASE' ) ) {
			return self::DEFAULT_BASE;
		}

		return self::resolve_base( constant( 'COMPLIANTY_API_BASE' ), wp_get_environment_type() );
	}

	/**
	 * Decides which base URL to use.
	 *
	 * @param mixed  $override    Value of COMPLIANTY_API_BASE.
	 * @param string $environment Result of wp_get_environment_type().
	 * @return string
	 */
	public static function resolve_base( $override, string $environment ): string {
		if ( 'production' === $environment || ! is_string( $override ) ) {
			return self::DEFAULT_BASE;
		}

		$override = rtrim( trim( $override ), '/' );
		$parts    = wp_parse_url( $override );
		if (
			! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| 'https' !== $parts['scheme']
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
		) {
			return self::DEFAULT_BASE;
		}

		return $override;
	}

	/**
	 * Fetches the status.
	 *
	 * @param bool $force   Ignore the failure backoff (user initiated).
	 * @param int  $timeout Timeout in seconds.
	 * @return Result
	 */
	public function status( bool $force = false, int $timeout = self::TIMEOUT ): Result {
		return $this->authenticated( 'GET', Endpoints::STATUS, $force, $timeout );
	}

	/**
	 * Fetches the privacy policy.
	 *
	 * @param bool $force   Ignore the failure backoff (user initiated).
	 * @param int  $timeout Timeout in seconds.
	 * @return Result
	 */
	public function dse( bool $force = false, int $timeout = self::TIMEOUT ): Result {
		return $this->authenticated( 'GET', Endpoints::DSE, $force, $timeout );
	}

	/**
	 * Starts a scan. Always user initiated, so the backoff does not apply.
	 *
	 * @return Result
	 */
	public function start_scan(): Result {
		return $this->authenticated( 'POST', Endpoints::SCAN, true, self::TIMEOUT );
	}

	/**
	 * Reads the scan state.
	 *
	 * @param bool $force Ignore the failure backoff.
	 * @return Result
	 */
	public function scan_status( bool $force = false ): Result {
		return $this->authenticated( 'GET', Endpoints::SCAN_STATUS, $force, self::TIMEOUT );
	}

	/**
	 * Starts a free scan. No token is involved. The caller passes home_url() and nothing else.
	 *
	 * @param string $site_url Address of this website.
	 * @return Result
	 */
	public function start_free_scan( string $site_url ): Result {
		$body = wp_json_encode( array( 'url' => $site_url ) );
		if ( false === $body ) {
			return Result::failure( Result::REJECTED, 'unsafe_url' );
		}

		return $this->send(
			'POST',
			Endpoints::FREE_SCAN,
			Endpoints::FREE_SCAN,
			array( 'Content-Type' => 'application/json' ),
			$body,
			self::TIMEOUT
		);
	}

	/**
	 * Polls a free scan job. The job token travels in a header, never in the URL.
	 *
	 * @param string $job_id       Job id from start_free_scan().
	 * @param string $access_token Job token from start_free_scan().
	 * @return Result
	 */
	public function free_scan_job( string $job_id, string $access_token ): Result {
		if ( 1 !== preg_match( Schema::JOB_ID, $job_id ) || 1 !== preg_match( Schema::JOB_TOKEN, $access_token ) ) {
			return Result::failure( Result::REJECTED, 'not_found' );
		}

		return $this->send(
			'GET',
			Endpoints::FREE_SCAN_JOB,
			Endpoints::FREE_SCAN . '/' . $job_id,
			array( self::JOB_HEADER => $access_token ),
			null,
			self::TIMEOUT
		);
	}

	/**
	 * Authenticated call with backoff and connection bookkeeping.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint Endpoint constant.
	 * @param bool   $force    Ignore the backoff.
	 * @param int    $timeout  Timeout in seconds.
	 * @return Result
	 */
	private function authenticated( string $method, string $endpoint, bool $force, int $timeout ): Result {
		$token = $this->tokens->get();
		if ( null === $token ) {
			return Result::failure( Result::NO_TOKEN );
		}

		if ( ! $force ) {
			$until = $this->cache->backoff_until();
			if ( $until > 0 ) {
				return Result::failure( Result::BACKOFF, '', $until - time() );
			}
		}

		$result = $this->send( $method, $endpoint, $endpoint, array( 'Authorization' => 'Bearer ' . $token ), null, $timeout );
		$this->record( $result );

		return $result;
	}

	/**
	 * Updates connection state and backoff from a result of an authenticated call.
	 *
	 * @param Result $result Result.
	 * @return void
	 */
	private function record( Result $result ): void {
		switch ( $result->kind() ) {
			case Result::OK:
			case Result::COOLDOWN:
			case Result::BUDGET:
			case Result::CONFLICT:
				$this->cache->clear_backoff();
				$this->cache->set_connection_state( Cache::STATE_CONNECTED );
				break;

			case Result::UNAUTHORIZED:
				$this->cache->set_connection_state( Cache::STATE_UNAUTHORIZED );
				$this->cache->register_failure( HOUR_IN_SECONDS );
				break;

			case Result::FORBIDDEN:
				if ( 'subscription_inactive' === $result->code() ) {
					$this->cache->set_connection_state( Cache::STATE_INACTIVE );
					$this->cache->register_failure( HOUR_IN_SECONDS );
				} elseif ( 'feature_not_available' === $result->code() ) {
					$this->cache->set_connection_state( Cache::STATE_NO_FEATURE );
					$this->cache->register_failure( HOUR_IN_SECONDS );
				} else {
					// Valid token without the required scope: the connection itself works.
					$this->cache->set_connection_state( Cache::STATE_CONNECTED );
				}
				break;

			case Result::RATE_LIMITED:
				$this->cache->register_failure( $result->retry_after() );
				break;

			case Result::NETWORK:
			case Result::SERVER_ERROR:
				$this->cache->set_connection_state( Cache::STATE_UNREACHABLE );
				$this->cache->register_failure( $result->retry_after() );
				break;

			case Result::INVALID:
				$this->cache->set_connection_state( Cache::STATE_INVALID );
				$this->cache->register_failure();
				break;
		}
	}

	/**
	 * Performs one request and parses the response.
	 *
	 * @param string               $method   HTTP method.
	 * @param string               $endpoint Endpoint constant, selects the schema.
	 * @param string               $path     Path below the base URL.
	 * @param array<string,string> $headers  Extra headers.
	 * @param string|null          $body     Request body.
	 * @param int                  $timeout  Timeout in seconds.
	 * @return Result
	 */
	private function send( string $method, string $endpoint, string $path, array $headers, ?string $body, int $timeout ): Result {
		$args = array(
			'method'              => $method,
			'timeout'             => max( 1, min( self::TIMEOUT, $timeout ) ),
			'redirection'         => 0,
			'limit_response_size' => Parser::MAX_BODY,
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'user-agent'          => 'ComplianytConnector/' . COMPLIANTY_CONNECTOR_VERSION,
			'cookies'             => array(),
			'headers'             => array_merge( array( 'Accept' => 'application/json' ), $headers ),
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$base = $this->base_url();

		// Other plugins can filter every WordPress HTTP request. Re-assert the transport rules last,
		// for this one request only, so the token is never sent over an unverified or redirected connection.
		$enforce = static function ( $request_args, $url ) use ( $base, $args ) {
			if ( ! is_array( $request_args ) || ! is_string( $url ) || 0 !== strpos( $url, $base . '/' ) ) {
				return $request_args;
			}

			$request_args['sslverify']           = true;
			$request_args['redirection']         = 0;
			$request_args['reject_unsafe_urls']  = true;
			$request_args['timeout']             = $args['timeout'];
			$request_args['limit_response_size'] = $args['limit_response_size'];

			return $request_args;
		};
		add_filter( 'http_request_args', $enforce, PHP_INT_MAX, 2 );
		add_filter( 'https_ssl_verify', '__return_true', PHP_INT_MAX );

		try {
			$response = wp_safe_remote_request( $base . $path, $args );
		} finally {
			remove_filter( 'http_request_args', $enforce, PHP_INT_MAX );
			remove_filter( 'https_ssl_verify', '__return_true', PHP_INT_MAX );
		}

		if ( is_wp_error( $response ) ) {
			Log::debug( sprintf( 'Request to %s failed: %s %s', $endpoint, $response->get_error_code(), $response->get_error_message() ) );
			return Result::failure( Result::NETWORK );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$retry  = wp_remote_retrieve_header( $response, 'retry-after' );
		$retry  = is_string( $retry ) && 1 === preg_match( '/^\d{1,6}$/', $retry ) ? (int) $retry : 0;

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$result       = Parser::parse(
			$endpoint,
			$status,
			is_string( $content_type ) ? $content_type : '',
			(string) wp_remote_retrieve_body( $response ),
			$retry
		);

		if ( Result::INVALID === $result->kind() || Result::SERVER_ERROR === $result->kind() ) {
			Log::debug( sprintf( 'Unusable response from %s: HTTP %d, %s', $endpoint, $status, $result->code() ) );
		}

		return $result;
	}
}
