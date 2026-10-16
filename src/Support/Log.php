<?php
/**
 * Debug logging.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Writes technical details to the PHP error log, only when WP_DEBUG_LOG is enabled.
 *
 * Every message is scrubbed: anything that looks like a connection token is replaced.
 * The user interface never shows these details.
 */
final class Log {

	/**
	 * Logs a debug message.
	 *
	 * @param string $message Message without secrets.
	 * @return void
	 */
	public static function debug( string $message ): void {
		$enabled = defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

		/**
		 * Filters whether technical details are written to the PHP error log.
		 *
		 * @param bool $enabled True when WP_DEBUG and WP_DEBUG_LOG are on.
		 */
		if ( ! apply_filters( 'complianty_connector_debug_log', $enabled ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Opt-in debug log, scrubbed of tokens.
		error_log( 'Complianty Connector: ' . self::scrub( $message ) );
	}

	/**
	 * Removes token-like strings and the Authorization header value.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	public static function scrub( string $message ): string {
		$clean = preg_replace( '/clp_site_[A-Za-z0-9_-]+/', 'clp_site_[redacted]', $message );
		$clean = preg_replace( '/Bearer\s+\S+/i', 'Bearer [redacted]', (string) $clean );

		return substr( (string) $clean, 0, 500 );
	}
}
