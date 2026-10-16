<?php
/**
 * User-facing messages for API results.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Support;

use Complianty\Connector\Api\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Translates a Result into a plain text sentence that names the next step.
 *
 * Messages never contain server text, tokens or technical details.
 */
final class Messages {

	public const CONTEXT_GENERIC   = 'generic';
	public const CONTEXT_SCAN      = 'scan';
	public const CONTEXT_FREE_SCAN = 'free_scan';

	/**
	 * Message for a failed result.
	 *
	 * @param Result $result  Failed result.
	 * @param string $context One of the CONTEXT_ constants.
	 * @return string Plain text, escape on output.
	 */
	public static function for_result( Result $result, string $context = self::CONTEXT_GENERIC ): string {
		$wait = self::duration( $result->retry_after() );

		switch ( $result->kind() ) {
			case Result::NO_TOKEN:
				return __( 'No connection token is set. Paste the token from your Complianty account to connect.', 'complianty-connector' );

			case Result::UNAUTHORIZED:
				return __( 'The token is invalid or was revoked. Create a new token in your Complianty account and connect again.', 'complianty-connector' );

			case Result::FORBIDDEN:
				return self::forbidden( $result->code() );

			case Result::RATE_LIMITED:
				return '' === $wait
					? __( 'Too many requests. Please try again in a few minutes.', 'complianty-connector' )
					/* translators: %s: waiting time, e.g. "5 minutes" */
					: sprintf( __( 'Too many requests. Please try again in %s.', 'complianty-connector' ), $wait );

			case Result::COOLDOWN:
				return self::cooldown( $context, $wait );

			case Result::BUDGET:
				return __( 'The daily scan budget of your plan is used up. Please try again tomorrow.', 'complianty-connector' );

			case Result::CONFLICT:
				return __( 'A scan is already running. The result appears here when it is finished.', 'complianty-connector' );

			case Result::REJECTED:
				return 'unsafe_url' === $result->code()
					? __( 'The address of this website cannot be checked from outside, for example because it is a local or private address.', 'complianty-connector' )
					: __( 'The service did not accept the request. Please reconnect or try again later.', 'complianty-connector' );

			case Result::SERVER_ERROR:
				return 'scanner_busy' === $result->code()
					? __( 'The scanner is busy right now. Please try again in a few minutes.', 'complianty-connector' )
					: __( 'The service reports a problem. Please try again later.', 'complianty-connector' );

			case Result::NETWORK:
				return __( 'The service could not be reached. Check that your server may connect to app.complianty.de and try again.', 'complianty-connector' );

			case Result::BACKOFF:
				return '' === $wait
					? __( 'The service was not reachable recently. The next attempt is made automatically, or press Refresh.', 'complianty-connector' )
					/* translators: %s: waiting time, e.g. "5 minutes" */
					: sprintf( __( 'The service was not reachable recently. The next attempt is made automatically in %s, or press Refresh.', 'complianty-connector' ), $wait );

			default:
				return __( 'The service sent an unexpected response. Please try again later.', 'complianty-connector' );
		}
	}

	/**
	 * Message for a 403 response.
	 *
	 * @param string $code Whitelisted service code.
	 * @return string
	 */
	private static function forbidden( string $code ): string {
		if ( 'subscription_inactive' === $code ) {
			return __( 'The Complianty subscription for this website is not active. Reactivate it in your Complianty account.', 'complianty-connector' );
		}
		if ( 'feature_not_available' === $code ) {
			return __( 'The connection is not available for your plan at the moment. Check your plan in your Complianty account.', 'complianty-connector' );
		}

		return __( 'This token is not allowed to do this. Create a token with the required permission in your Complianty account.', 'complianty-connector' );
	}

	/**
	 * Message for a cooldown.
	 *
	 * @param string $context Context.
	 * @param string $wait    Human readable waiting time, may be empty.
	 * @return string
	 */
	private static function cooldown( string $context, string $wait ): string {
		if ( self::CONTEXT_FREE_SCAN === $context ) {
			return '' === $wait
				? __( 'This website was checked recently. Please try again later.', 'complianty-connector' )
				/* translators: %s: waiting time, e.g. "5 hours" */
				: sprintf( __( 'This website was checked recently. Please try again in %s.', 'complianty-connector' ), $wait );
		}

		return '' === $wait
			? __( 'A scan was started recently. Please try again later.', 'complianty-connector' )
			/* translators: %s: waiting time, e.g. "5 minutes" */
			: sprintf( __( 'A scan was started recently. Please try again in %s.', 'complianty-connector' ), $wait );
	}

	/**
	 * Human readable duration, empty for zero.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function duration( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return '';
		}

		return human_time_diff( 0, max( 60, $seconds ) );
	}

	/**
	 * Label for the stored connection state.
	 *
	 * @param string $state One of the Cache::STATE_ constants.
	 * @return string
	 */
	public static function connection_state( string $state ): string {
		switch ( $state ) {
			case 'connected':
				return __( 'Connected', 'complianty-connector' );
			case 'unauthorized':
				return __( 'Token invalid or revoked', 'complianty-connector' );
			case 'subscription_inactive':
				return __( 'Subscription not active', 'complianty-connector' );
			case 'feature_not_available':
				return __( 'Not available for your plan', 'complianty-connector' );
			case 'unreachable':
				return __( 'Service not reachable', 'complianty-connector' );
			case 'invalid_response':
				return __( 'Unexpected response from the service', 'complianty-connector' );
			default:
				return __( 'Not checked yet', 'complianty-connector' );
		}
	}
}
