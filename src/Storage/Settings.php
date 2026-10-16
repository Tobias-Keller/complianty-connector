<?php
/**
 * Plugin settings.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Display settings. Everything is off by default (WordPress.org guideline 10: no credit link
 * on the public site unless the site owner turns it on).
 */
final class Settings {

	public const SHOW_CREDIT = 'show_credit';

	/**
	 * Whether the link to Complianty is shown below the privacy policy.
	 *
	 * @return bool
	 */
	public function show_credit(): bool {
		$settings = get_option( Options::SETTINGS, array() );

		return is_array( $settings ) && true === ( $settings[ self::SHOW_CREDIT ] ?? false );
	}

	/**
	 * Sanitize callback for the settings option.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<string,bool>
	 */
	public function sanitize( $value ): array {
		return array(
			self::SHOW_CREDIT => is_array( $value ) && ! empty( $value[ self::SHOW_CREDIT ] ),
		);
	}
}
