<?php
/**
 * Settings page tests: capability, nonce, token handling, escaping.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Admin\Notices;
use Complianty\Connector\Admin\SettingsPage;
use Complianty\Connector\Admin\StatusPage;
use Complianty\Connector\Api\Client;
use Complianty\Connector\Frontend\DseSync;
use Complianty\Connector\Storage\Cache;
use Complianty\Connector\Storage\DseStore;
use Complianty\Connector\Storage\Options;
use Complianty\Connector\Storage\TokenStore;
use RuntimeException;
use WPDieException;

/**
 * @covers \Complianty\Connector\Admin\SettingsPage
 * @covers \Complianty\Connector\Admin\StatusPage
 * @covers \Complianty\Connector\Admin\Notices
 */
final class SettingsPageTest extends IntegrationTestCase {

	/**
	 * Page under test.
	 *
	 * @var SettingsPage
	 */
	private $page;

	/**
	 * Cache.
	 *
	 * @var Cache
	 */
	private $cache;

	public function set_up() {
		parent::set_up();

		global $wp_settings_errors;
		$wp_settings_errors = array();

		$this->cache = new Cache();
		$this->page  = $this->build_page();
		$this->page->register_settings();

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . $location );
			}
		);
	}

	/**
	 * Builds a page with fresh services, as a new request would.
	 *
	 * @return SettingsPage
	 */
	private function build_page(): SettingsPage {
		$tokens = new TokenStore();
		$client = new Client( $tokens, $this->cache );
		$store  = new DseStore();

		return new SettingsPage( $tokens, $this->cache, $store, new DseSync( $client, $store ), new StatusPage( $client, $this->cache ), new Notices(), $client, new \Complianty\Connector\Storage\Settings() );
	}

	/**
	 * Stores a fresh privacy policy copy, so that opening the page does not ask the service for it.
	 *
	 * @return void
	 */
	private function seed_fresh_dse(): void {
		update_option(
			Options::DSE,
			array(
				'html'         => '<p>Approved</p>',
				'state'        => 'active',
				'remote_state' => 'active',
				'approved_at'  => '2026-10-16T08:30:00Z',
				'generated_at' => null,
				'checked_at'   => time(),
			),
			false
		);
	}

	/**
	 * Prepares a POST request for an admin-post action.
	 *
	 * @param string      $action Nonce action.
	 * @param string|null $nonce  Nonce override, null for a valid one, '' for none.
	 * @param string      $method Request method.
	 * @return void
	 */
	private function request( string $action, ?string $nonce = null, string $method = 'POST' ): void {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_REQUEST                  = array();
		if ( '' !== $nonce ) {
			$_REQUEST['_wpnonce'] = $nonce ?? wp_create_nonce( $action );
		}
	}

	/**
	 * Runs a handler and returns the redirect location.
	 *
	 * @param string $handler Method name.
	 * @return string
	 */
	private function run_handler( string $handler ): string {
		try {
			$this->page->$handler();
		} catch ( RuntimeException $e ) {
			return substr( $e->getMessage(), strlen( 'redirect:' ) );
		}

		$this->fail( 'The handler did not redirect.' );
	}

	/**
	 * Handlers and their nonce actions.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function handlers(): array {
		return array(
			'disconnect'     => array( 'handle_disconnect', SettingsPage::ACTION_DISCONNECT ),
			'refresh status' => array( 'handle_refresh_status', SettingsPage::ACTION_REFRESH_STATUS ),
			'refresh dse'    => array( 'handle_refresh_dse', SettingsPage::ACTION_REFRESH_DSE ),
		);
	}

	/**
	 * @dataProvider handlers
	 *
	 * @param string $handler Handler method.
	 * @param string $action  Nonce action.
	 */
	public function test_roles_below_admin_are_refused( string $handler, string $action ): void {
		$this->connect();

		foreach ( array( 'editor', 'author', 'contributor', 'subscriber' ) as $role ) {
			$this->login_as( $role );
			$this->request( $action );

			try {
				$this->page->$handler();
				$this->fail( "$role was not refused." );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $e->getCode() );
			}
		}

		wp_set_current_user( 0 );
		$this->request( $action );
		$this->expectException( WPDieException::class );
		$this->page->$handler();
	}

	/**
	 * @dataProvider handlers
	 *
	 * @param string $handler Handler method.
	 * @param string $action  Nonce action.
	 */
	public function test_admin_without_a_valid_nonce_is_refused( string $handler, string $action ): void {
		$this->connect();
		$this->login_as( 'administrator' );

		foreach ( array( '', 'invalid', wp_create_nonce( 'some_other_action' ) ) as $nonce ) {
			$this->request( $action, $nonce );
			try {
				$this->page->$handler();
				$this->fail( 'Nonce "' . $nonce . '" was accepted.' );
			} catch ( WPDieException $e ) {
				$this->assertTrue( true );
			}
		}

		$this->assertTrue( ( new TokenStore() )->has_token(), 'Nothing may change without a valid nonce.' );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * @dataProvider handlers
	 *
	 * @param string $handler Handler method.
	 * @param string $action  Nonce action.
	 */
	public function test_get_requests_are_refused_even_with_a_valid_nonce( string $handler, string $action ): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->request( $action, null, 'GET' );

		$this->expectException( WPDieException::class );
		$this->page->$handler();
	}

	public function test_disconnect_removes_token_copy_and_cache(): void {
		$this->connect();
		update_option(
			Options::DSE,
			array(
				'html'       => '<p>x</p>',
				'checked_at' => time(),
			),
			false
		);
		$this->cache->set_status( array( 'status' => 'ok' ) );
		$this->cache->register_failure();
		$this->login_as( 'administrator' );
		$this->request( SettingsPage::ACTION_DISCONNECT );

		$location = $this->run_handler( 'handle_disconnect' );

		$this->assertStringContainsString( 'page=complianty-connector', $location );
		$this->assertEmpty( get_option( Options::TOKEN ) );
		$this->assertFalse( get_option( Options::DSE ) );
		$this->assertNull( $this->cache->get_status() );
		$this->assertSame( 0, $this->cache->backoff_until() );
		$this->assertFalse( wp_next_scheduled( Options::EVENT_REFRESH_DSE ) );
	}

	public function test_refresh_status_stores_the_result(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->request( SettingsPage::ACTION_REFRESH_STATUS );
		$this->queue_response( 200, $this->status_payload() );

		$this->run_handler( 'handle_refresh_status' );

		$this->assertSame( 'gaps', $this->cache->get_status()['data']['status'] );
	}

	public function test_refresh_failure_shows_a_fixed_message_and_no_server_text(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->request( SettingsPage::ACTION_REFRESH_STATUS );
		$this->queue_response( 500, '<b>Stack trace ' . self::TOKEN . '</b>', array( 'content-type' => 'text/html' ) );

		$this->run_handler( 'handle_refresh_status' );
		$notice = $this->capture( array( new Notices(), 'render' ) );

		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'The service reports a problem', $notice );
		$this->assertStringNotContainsString( 'Stack trace', $notice );
		$this->assertStringNotContainsString( self::TOKEN, $notice );
		$this->assertSame( '', $this->capture( array( new Notices(), 'render' ) ), 'A notice is shown once.' );
	}

	public function test_token_is_encrypted_and_not_autoloaded(): void {
		global $wpdb;
		$this->login_as( 'administrator' );

		update_option( Options::TOKEN, '  ' . self::TOKEN . "\n" );

		$stored = get_option( Options::TOKEN );
		$this->assertNotSame( self::TOKEN, $stored );
		$this->assertStringNotContainsString( 'clp_site_', (string) $stored );
		$this->assertSame( self::TOKEN, ( new TokenStore() )->get() );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Options::TOKEN ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );

		$like = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( self::TOKEN ) . '%' ) );
		$this->assertSame( '0', (string) $like, 'The plaintext token must not be in the database.' );
	}

	public function test_invalid_input_keeps_the_previous_token(): void {
		$this->connect();
		$before = get_option( Options::TOKEN );
		$this->login_as( 'administrator' );

		$bad_values = array( 'hello', 'clp_site_short', self::TOKEN . '<script>', 'clp_live_' . str_repeat( 'a', 40 ), "clp_site_\0" . str_repeat( 'a', 40 ), str_repeat( 'A', 5000 ) );
		foreach ( $bad_values as $bad ) {
			update_option( Options::TOKEN, $bad );
			$this->assertSame( $before, get_option( Options::TOKEN ), 'Rejected: ' . substr( $bad, 0, 30 ) );
		}
		$this->assertSame( self::TOKEN, ( new TokenStore() )->get() );
	}

	public function test_an_empty_submit_does_not_wipe_the_token(): void {
		$this->connect();
		$before = get_option( Options::TOKEN );
		$this->login_as( 'administrator' );

		update_option( Options::TOKEN, '' );

		$this->assertSame( $before, get_option( Options::TOKEN ) );
	}

	public function test_a_forged_encrypted_value_is_not_accepted(): void {
		$this->connect();
		$before = get_option( Options::TOKEN );
		$this->login_as( 'administrator' );

		update_option( Options::TOKEN, base64_encode( random_bytes( 80 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test input.

		$this->assertSame( $before, get_option( Options::TOKEN ) );
	}

	public function test_saving_a_new_token_clears_connection_data(): void {
		$this->login_as( 'administrator' );
		$this->cache->set_status( array( 'status' => 'ok' ) );
		$this->cache->register_failure();

		update_option( Options::TOKEN, self::TOKEN );

		$this->assertNull( $this->cache->get_status() );
		$this->assertSame( 0, $this->cache->backoff_until() );
	}

	public function test_a_first_connection_does_not_inherit_a_leftover_copy(): void {
		$this->login_as( 'administrator' );
		update_option(
			Options::DSE,
			array(
				'html'       => '<p>old site</p>',
				'checked_at' => time(),
			),
			false
		);

		update_option( Options::TOKEN, self::TOKEN );

		$this->assertFalse( get_option( Options::DSE ) );
	}

	public function test_replacing_a_token_keeps_the_approved_copy(): void {
		$this->connect();
		update_option(
			Options::DSE,
			array(
				'html'       => '<p>approved</p>',
				'checked_at' => time(),
			),
			false
		);
		$this->login_as( 'administrator' );

		update_option( Options::TOKEN, 'clp_site_ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210_-ab' );

		$this->assertSame( '<p>approved</p>', get_option( Options::DSE )['html'] );
	}

	public function test_render_without_token_shows_the_connect_form_and_sends_nothing(): void {
		$this->login_as( 'administrator' );

		$html = $this->capture( array( $this->page, 'render' ) );

		$this->assertStringContainsString( 'name="complianty_connector_token"', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringContainsString( 'data-complianty-free-start', $html );
		$this->assertStringContainsString( 'External services', $html );
		$this->assertStringContainsString( home_url(), $html, 'The free check shows what will be sent.' );
		$this->assertCount( 0, $this->requests );
	}

	public function test_render_escapes_everything_from_the_service(): void {
		$this->connect();
		$this->seed_fresh_dse();
		$this->login_as( 'administrator' );
		$hostile = '<img src=x onerror=alert(1)><script>alert(2)</script>"\'';
		$this->cache->set_status(
			$this->status_payload(
				array(
					'todos' => array(
						array(
							'id'       => 'x',
							'type'     => 't',
							'priority' => 'critical',
							'title'    => $hostile,
							'text'     => $hostile,
						),
					),
				)
			)
		);

		$html = $this->capture( array( $this->page, 'render' ) );

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertCount( 0, $this->requests, 'A fresh cache means no request.' );
	}

	public function test_render_never_contains_the_token(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->cache->set_status( $this->status_payload() );

		$html = $this->capture( array( $this->page, 'render' ) );

		$this->assertStringNotContainsString( self::TOKEN, $html );
		$this->assertStringContainsString( 'clp_site_…' . substr( self::TOKEN, -4 ), $html );
		$this->assertStringNotContainsString( substr( self::TOKEN, 9, 30 ), $html );
	}

	public function test_render_has_no_external_assets_and_safe_links(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->cache->set_status( $this->status_payload() );

		$html = $this->capture( array( $this->page, 'render' ) );

		$this->assertDoesNotMatchRegularExpression( '/<(script|link|iframe|img)\b/i', $html );
		preg_match_all( '/<a\b[^>]*href="(https?:[^"]+)"[^>]*>/i', $html, $links, PREG_SET_ORDER );
		$this->assertNotEmpty( $links );
		foreach ( $links as $link ) {
			$this->assertStringContainsString( 'rel="noopener noreferrer"', $link[0] );
			$this->assertMatchesRegularExpression( '#^https://(app\.)?complianty\.de/#', $link[1] );
		}
	}

	public function test_scan_button_depends_on_the_permission_reported_by_the_service(): void {
		$this->connect();
		$this->login_as( 'administrator' );

		$this->cache->set_status( $this->status_payload( array( 'scan_trigger_allowed' => false ) ) );
		$this->assertStringNotContainsString( 'data-complianty-scan-start', $this->capture( array( $this->build_page(), 'render' ) ) );

		$this->cache->set_status( $this->status_payload( array( 'scan_trigger_allowed' => true ) ) );
		$this->assertStringContainsString( 'data-complianty-scan-start', $this->capture( array( $this->build_page(), 'render' ) ) );
	}

	public function test_stale_status_is_refetched_when_the_page_opens(): void {
		$this->connect();
		$this->seed_fresh_dse();
		$this->login_as( 'administrator' );
		$this->queue_response( 200, $this->status_payload( array( 'status' => 'critical' ) ) );

		$html = $this->capture( array( $this->page, 'render' ) );

		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( 'Critical findings', $html );
	}

	public function test_render_shows_the_last_data_next_to_an_error(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->cache->set_status( $this->status_payload() );
		set_transient(
			Options::T_STATUS,
			array(
				'fetched_at' => time() - 100000,
				'data'       => $this->status_payload(),
			),
			600000
		);
		$this->queue_service_error( 401, 'unauthorized' );

		$html = $this->capture( array( $this->page, 'render' ) );

		$this->assertStringContainsString( 'The token is invalid or was revoked', $html );
		$this->assertStringContainsString( 'Gaps found', $html );
		$this->assertStringContainsString( 'Token invalid or revoked', $html );
	}

	public function test_render_refuses_roles_below_admin(): void {
		$this->login_as( 'editor' );

		$this->expectException( WPDieException::class );
		$this->page->render();
	}

	public function test_page_is_registered_below_settings_for_manage_options(): void {
		global $submenu;
		$this->login_as( 'administrator' );

		$this->page->add_menu();

		$slugs = wp_list_pluck( $submenu['options-general.php'] ?? array(), 2 );
		$this->assertContains( SettingsPage::SLUG, $slugs );
		foreach ( $submenu['options-general.php'] as $item ) {
			if ( SettingsPage::SLUG === $item[2] ) {
				$this->assertSame( 'manage_options', $item[1] );
			}
		}
	}

	public function test_assets_load_on_the_plugin_page_only_and_are_local(): void {
		$this->login_as( 'administrator' );
		$this->page->add_menu();
		$hook = get_plugin_page_hookname( SettingsPage::SLUG, 'options-general.php' );

		$this->page->enqueue_assets( 'index.php' );
		$this->assertFalse( wp_script_is( 'complianty-connector-admin', 'enqueued' ) );

		$this->page->enqueue_assets( $hook );
		$this->assertTrue( wp_script_is( 'complianty-connector-admin', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'complianty-connector-admin', 'enqueued' ) );

		$script = wp_scripts()->registered['complianty-connector-admin'];
		$this->assertStringStartsWith( plugins_url( '', COMPLIANTY_CONNECTOR_FILE ), $script->src );
		$this->assertNotContains( 'jquery', $script->deps );

		$inline = (string) $script->extra['before'][1] ?? '';
		$this->assertStringNotContainsString( self::TOKEN, $inline );
		$this->assertStringNotContainsString( 'clp_site_', $inline );
	}

	public function test_plugin_adds_no_admin_notices_elsewhere(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		set_current_screen( 'dashboard' );

		$this->assertSame(
			'',
			trim(
				$this->capture(
					static function () {
						do_action( 'admin_notices' );
						do_action( 'all_admin_notices' );
					}
				)
			)
		);
	}

	public function test_opening_the_page_fetches_a_missing_privacy_policy_copy_once(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->cache->set_status( $this->status_payload() );
		$this->queue_response( 200, $this->dse_payload() );

		$html = $this->capture( array( $this->build_page(), 'render' ) );

		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( 'Approved version from', $html );

		$this->capture( array( $this->build_page(), 'render' ) );
		$this->assertCount( 1, $this->requests, 'A fresh copy is not fetched again.' );
	}

	public function test_a_failed_status_call_stops_the_privacy_policy_call_on_the_same_page(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->queue_service_error( 401, 'unauthorized' );

		$this->capture( array( $this->build_page(), 'render' ) );

		$this->assertCount( 1, $this->requests, 'The backoff set by the first failure must protect the second call.' );
	}

	public function test_the_settings_saved_notice_is_shown_once(): void {
		$this->login_as( 'administrator' );
		add_settings_error( SettingsPage::OPTION_GROUP, 'complianty_token_saved', 'Saved once.', 'success' );

		$html = $this->capture( array( $this->build_page(), 'render' ) );

		$this->assertSame( 0, substr_count( $html, 'Saved once.' ), 'Core prints settings errors above the page; the page itself must not repeat them.' );
	}

	public function test_the_plugin_list_gets_a_settings_link_before_deactivate(): void {
		$this->login_as( 'administrator' );

		$links = $this->page->action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertSame( 'deactivate', array_key_last( $links ) );
		$this->assertStringContainsString( 'options-general.php?page=complianty-connector', (string) reset( $links ) );
		$this->assertStringContainsString( 'Settings', (string) reset( $links ) );
		$this->assertNotFalse( has_filter( 'plugin_action_links_' . plugin_basename( COMPLIANTY_CONNECTOR_FILE ) ) );
	}

	public function test_other_roles_get_no_settings_link(): void {
		$this->login_as( 'editor' );

		$this->assertSame( array( 'x' => 'y' ), $this->page->action_links( array( 'x' => 'y' ) ) );
		$this->assertSame( array(), $this->page->action_links( 'not an array' ) );
	}

	public function test_the_display_form_shows_the_option_off_by_default_and_is_a_separate_form(): void {
		$this->connect();
		$this->seed_fresh_dse();
		$this->login_as( 'administrator' );
		$this->cache->set_status( $this->status_payload() );

		$html = $this->capture( array( $this->build_page(), 'render' ) );

		$this->assertMatchesRegularExpression( '#<input type="checkbox" name="complianty_connector_settings\[show_credit\]" value="1">#', $html );
		$this->assertMatchesRegularExpression( '/name=.option_page. value=.complianty_connector_display./', $html );
		$this->assertStringContainsString( 'Off by default', $html );

		update_option( Options::SETTINGS, array( 'show_credit' => true ) );
		$html = $this->capture( array( $this->build_page(), 'render' ) );
		$this->assertStringContainsString( 'value="1" checked=\'checked\'', $html );
	}
}
