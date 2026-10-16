<?php
/**
 * Shortcode [complianty_dse].
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shortcode.
 */
final class DseShortcode {

	public const TAG = 'complianty_dse';

	/**
	 * Output service.
	 *
	 * @var DseOutput
	 */
	private $output;

	/**
	 * Constructor.
	 *
	 * @param DseOutput $output Output service.
	 */
	public function __construct( DseOutput $output ) {
		$this->output = $output;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback. Attributes are ignored on purpose.
	 *
	 * @return string
	 */
	public function render(): string {
		return $this->output->render();
	}
}
