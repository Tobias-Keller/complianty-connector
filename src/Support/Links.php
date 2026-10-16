<?php
/**
 * Fixed external links.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Links to the vendor's own site. Links carry a campaign tag so the vendor can count
 * visits from this plugin (no personal data, no site address).
 */
final class Links {

	private const UTM = '?utm_source=wordpress-org&utm_medium=plugin';

	/**
	 * Home page of the service.
	 *
	 * @return string
	 */
	public static function home(): string {
		return 'https://complianty.de/' . self::UTM;
	}

	/**
	 * Complianty dashboard.
	 *
	 * @return string
	 */
	public static function dashboard(): string {
		return 'https://app.complianty.de/dashboard' . self::UTM;
	}

	/**
	 * Registration page.
	 *
	 * @return string
	 */
	public static function register(): string {
		return 'https://app.complianty.de/registrieren' . self::UTM;
	}

	/**
	 * Privacy policy of the service.
	 *
	 * @return string
	 */
	public static function privacy(): string {
		return 'https://complianty.de/datenschutz';
	}

	/**
	 * Terms of service.
	 *
	 * @return string
	 */
	public static function terms(): string {
		return 'https://complianty.de/agb';
	}
}
