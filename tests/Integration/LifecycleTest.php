<?php
/**
 * Boot, privacy text, deactivation and uninstall.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Admin\SettingsPage;
use Complianty\Connector\Plugin;
use Complianty\Connector\Storage\Options;
use Complianty\Connector\Support\Privacy;

/**
 * @covers \Complianty\Connector\Plugin
 * @covers \Complianty\Connector\Support\Privacy
 */
final class LifecycleTest extends IntegrationTestCase {

	public function test_a_site_without_token_never_contacts_the_service(): void {
		$this->login_as( 'administrator' );

		do_action( 'admin_menu' );
		do_action( 'rest_api_init', rest_get_server() );
		do_action( 'wp_enqueue_scripts' );
		set_current_screen( 'settings_page_complianty-connector' );
		do_action( 'admin_enqueue_scripts', 'settings_page_complianty-connector' );
		do_shortcode( '[complianty_dse]' );
		do_blocks( '<!-- wp:complianty/dse /-->' );
		$this->capture( array( new SettingsPage( ...$this->page_args() ), 'render' ) );

		$this->assertCount( 0, $this->requests );
		$this->assertFalse( wp_next_scheduled( Options::EVENT_REFRESH_DSE ) );
	}

	/**
	 * Constructor arguments for a page, built the way Plugin::boot does.
	 *
	 * @return array<int,object>
	 */
	private function page_args(): array {
		$tokens = new \Complianty\Connector\Storage\TokenStore();
		$cache  = new \Complianty\Connector\Storage\Cache();
		$client = new \Complianty\Connector\Api\Client( $tokens, $cache );
		$store  = new \Complianty\Connector\Storage\DseStore();

		return array( $tokens, $cache, $store, new \Complianty\Connector\Frontend\DseSync( $client, $store ), new \Complianty\Connector\Admin\StatusPage( $client, $cache ), new \Complianty\Connector\Admin\Notices(), $client, new \Complianty\Connector\Storage\Settings() );
	}

	public function test_nothing_is_stored_on_activation_and_boot(): void {
		global $wpdb;

		$rows = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'complianty%'" );

		$this->assertSame( array(), $rows );
	}

	public function test_privacy_text_is_registered_and_states_the_data_flow(): void {
		global $wp_filter;

		$registered = false;
		foreach ( $wp_filter['admin_init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Privacy ) {
					$registered = true;
				}
			}
		}
		$this->assertTrue( $registered, 'The privacy text must be added on admin_init.' );

		$content = ( new Privacy() )->text();
		$this->assertStringContainsString( 'connection token', $content );
		$this->assertStringContainsString( 'Visitors of this website do not contact Complianty', $content );
		$this->assertStringContainsString( 'https://complianty.de/datenschutz', $content );
		$this->assertStringContainsString( 'optional free check', $content );
		$this->assertStringNotContainsString( '<script', $content );
	}

	public function test_deactivation_removes_the_scheduled_event_but_keeps_data(): void {
		$this->connect();
		wp_schedule_single_event( time() + 60, Options::EVENT_REFRESH_DSE );

		Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( Options::EVENT_REFRESH_DSE ) );
		$this->assertTrue( ( new \Complianty\Connector\Storage\TokenStore() )->has_token() );
	}

	public function test_uninstall_removes_everything(): void {
		global $wpdb;
		$admin = $this->login_as( 'administrator' );
		$this->connect();
		update_option(
			Options::DSE,
			array(
				'html'       => '<p>x</p>',
				'checked_at' => time(),
			),
			false
		);
		foreach ( Options::transients() as $transient ) {
			set_transient( $transient, 'x', 600 );
		}
		set_transient( 'complianty_flash_' . $admin, array( 'success' => 'disconnected' ), 600 );
		wp_schedule_single_event( time() + 60, Options::EVENT_REFRESH_DSE );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'complianty-connector/complianty-connector.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant, required by uninstall.php.
		}
		include dirname( COMPLIANTY_CONNECTOR_FILE ) . '/uninstall.php';

		$left = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%complianty%'" );
		$this->assertSame( array(), $left );
		$this->assertFalse( wp_next_scheduled( Options::EVENT_REFRESH_DSE ) );
	}

	public function test_uninstall_list_matches_the_options_class(): void {
		$source = (string) file_get_contents( dirname( COMPLIANTY_CONNECTOR_FILE ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.

		foreach ( array_merge( Options::options(), Options::transients() ) as $name ) {
			$this->assertStringContainsString( "'" . $name . "'", $source, "uninstall.php does not remove $name." );
		}
	}

	public function test_uninstall_refuses_to_run_outside_of_wordpress_uninstall(): void {
		$source = (string) file_get_contents( dirname( COMPLIANTY_CONNECTOR_FILE ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.

		$this->assertMatchesRegularExpression( '/if \( ! defined\( \'WP_UNINSTALL_PLUGIN\' \) \) \{\s+exit;/', $source );
	}

	public function test_every_php_file_blocks_direct_access(): void {
		$root  = dirname( COMPLIANTY_CONNECTOR_FILE );
		$files = array_merge( glob( $root . '/src/*.php' ), glob( $root . '/src/*/*.php' ), array( $root . '/complianty-connector.php' ) );

		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$this->assertMatchesRegularExpression( '/defined\( \'ABSPATH\' \) \|\| exit;/', (string) file_get_contents( $file ), basename( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local files in a test.
		}
	}
}
