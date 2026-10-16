<?php
/**
 * Finding areas.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The fixed set of areas the service groups findings into. Unknown keys fall back to "other".
 */
final class Areas {

	public const OTHER = 'other';

	/**
	 * Area keys in display order.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array( 'tracking', 'banner_audit', 'external_content', 'compliance', 'impressum', 'a11y', 'infrastructure', 'formulare', self::OTHER );
	}

	/**
	 * Labels by key.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'tracking'         => __( 'Tracking', 'complianty-connector' ),
			'banner_audit'     => __( 'Cookie banner', 'complianty-connector' ),
			'external_content' => __( 'External content', 'complianty-connector' ),
			'compliance'       => __( 'Documentation', 'complianty-connector' ),
			'impressum'        => __( 'Imprint', 'complianty-connector' ),
			'a11y'             => __( 'Accessibility', 'complianty-connector' ),
			'infrastructure'   => __( 'Infrastructure', 'complianty-connector' ),
			'formulare'        => __( 'Forms', 'complianty-connector' ),
			self::OTHER        => __( 'Other', 'complianty-connector' ),
		);
	}

	/**
	 * Normalises a key from the service. Older service versions send none.
	 *
	 * @param mixed $key Raw value.
	 * @return string
	 */
	public static function normalize( $key ): string {
		return is_string( $key ) && in_array( $key, self::keys(), true ) ? $key : self::OTHER;
	}
}
