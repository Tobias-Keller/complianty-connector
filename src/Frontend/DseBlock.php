<?php
/**
 * Dynamic block complianty/dse.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the block. Rendering happens on the server, the block saves no markup.
 */
final class DseBlock {

	public const EDITOR_HANDLE = 'complianty-connector-dse-editor';

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
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Registers the editor script and the block type.
	 *
	 * @return void
	 */
	public function register_block(): void {
		$dir = dirname( COMPLIANTY_CONNECTOR_FILE ) . '/blocks/dse';

		wp_register_script(
			self::EDITOR_HANDLE,
			plugins_url( 'blocks/dse/edit.js', COMPLIANTY_CONNECTOR_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render' ),
			COMPLIANTY_CONNECTOR_VERSION,
			true
		);
		wp_set_script_translations( self::EDITOR_HANDLE, 'complianty-connector', dirname( COMPLIANTY_CONNECTOR_FILE ) . '/languages' );

		register_block_type(
			$dir,
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Render callback. Ignores attributes and content.
	 *
	 * @return string
	 */
	public function render(): string {
		return $this->output->render();
	}
}
