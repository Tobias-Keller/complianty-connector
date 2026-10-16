<?php
/**
 * Loading of the shipped translations.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Plugin;
use WP_UnitTestCase;

/**
 * @covers \Complianty\Connector\Plugin
 */
final class AaTextdomainLoaderTest extends WP_UnitTestCase {

	public function tear_down() {
		unload_textdomain( 'complianty-connector' );
		parent::tear_down();
	}

	public function test_the_plugin_loads_its_own_texts_for_the_site_language(): void {
		add_filter(
			'locale',
			static function () {
				return 'de_DE';
			}
		);
		unload_textdomain( 'complianty-connector' );

		Plugin::load_textdomain();

		$this->assertSame( 'Aktualisieren', __( 'Refresh', 'complianty-connector' ) );
		$this->assertNotFalse( has_action( 'init', array( Plugin::class, 'load_textdomain' ) ) );
	}
}
