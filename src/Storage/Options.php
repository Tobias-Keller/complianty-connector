<?php
/**
 * Option and transient names.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Single list of everything the plugin stores. uninstall.php mirrors this list.
 */
final class Options {

	public const TOKEN    = 'complianty_connector_token';
	public const DSE      = 'complianty_connector_dse';
	public const SETTINGS = 'complianty_connector_settings';

	public const T_STATUS     = 'complianty_status';
	public const T_BACKOFF    = 'complianty_backoff';
	public const T_CONNECTION = 'complianty_connection';
	public const T_SCAN_STATE = 'complianty_scan_state';
	public const T_FREE_SCAN  = 'complianty_free_scan';
	public const T_DSE_LOCK   = 'complianty_dse_lock';

	public const EVENT_REFRESH_DSE = 'complianty_connector_refresh_dse';

	/**
	 * All option names.
	 *
	 * @return string[]
	 */
	public static function options(): array {
		return array( self::TOKEN, self::DSE, self::SETTINGS );
	}

	/**
	 * All transient names.
	 *
	 * @return string[]
	 */
	public static function transients(): array {
		return array(
			self::T_STATUS,
			self::T_BACKOFF,
			self::T_CONNECTION,
			self::T_SCAN_STATE,
			self::T_FREE_SCAN,
			self::T_DSE_LOCK,
		);
	}
}
