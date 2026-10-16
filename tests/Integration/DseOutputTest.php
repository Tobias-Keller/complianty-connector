<?php
/**
 * Shortcode, block and privacy policy sync tests.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Api\Client;
use Complianty\Connector\Frontend\DseOutput;
use Complianty\Connector\Frontend\DseSync;
use Complianty\Connector\Storage\Cache;
use Complianty\Connector\Storage\DseStore;
use Complianty\Connector\Storage\Options;
use Complianty\Connector\Storage\Settings;
use Complianty\Connector\Storage\TokenStore;
use WP_Block_Type_Registry;

/**
 * @covers \Complianty\Connector\Frontend\DseOutput
 * @covers \Complianty\Connector\Frontend\DseSync
 * @covers \Complianty\Connector\Frontend\DseShortcode
 * @covers \Complianty\Connector\Frontend\DseBlock
 * @covers \Complianty\Connector\Storage\DseStore
 */
final class DseOutputTest extends IntegrationTestCase {

	/**
	 * Local copy.
	 *
	 * @var DseStore
	 */
	private $store;

	/**
	 * Sync.
	 *
	 * @var DseSync
	 */
	private $sync;

	public function set_up() {
		parent::set_up();
		$tokens      = new TokenStore();
		$this->store = new DseStore();
		$this->sync  = new DseSync( new Client( $tokens, new Cache() ), $this->store );
	}

	/**
	 * Renders through the registered shortcode, like a page would.
	 *
	 * @return string
	 */
	private function shortcode(): string {
		return do_shortcode( '[complianty_dse]' );
	}

	public function test_shortcode_and_block_are_registered(): void {
		$this->assertTrue( shortcode_exists( 'complianty_dse' ) );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'complianty/dse' ) );
	}

	public function test_without_token_nothing_is_output_and_nothing_is_sent(): void {
		$this->assertSame( '', $this->shortcode() );
		$this->assertSame( '', do_blocks( '<!-- wp:complianty/dse /-->' ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_first_render_fetches_once_with_a_short_timeout(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload() );

		$first = $this->shortcode();

		$this->assertSame( '<div class="complianty-dse"><h2>Datenschutz</h2><p>Text</p></div>', $first );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 3, $this->requests[0]['args']['timeout'] );

		$this->shortcode();
		$this->shortcode();
		$this->assertCount( 1, $this->requests, 'With a copy there is no further remote call.' );
	}

	public function test_block_renders_the_same_copy(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload() );

		$this->assertSame( $this->shortcode(), do_blocks( '<!-- wp:complianty/dse /-->' ) );
	}

	public function test_first_fetch_failure_leaves_the_output_empty_and_does_not_hammer(): void {
		$this->connect();
		$this->queue_error( 'timeout' );

		$this->assertSame( '', $this->shortcode() );
		$this->assertSame( '', $this->shortcode() );
		$this->assertSame( '', $this->shortcode() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_hostile_html_from_the_service_is_filtered_before_it_is_stored(): void {
		$this->connect();
		$evil = '<h2>Title</h2><script>alert(1)</script><p onclick="x()">Hi <a href="javascript:alert(1)">link</a></p><iframe src="https://evil.example"></iframe>';
		$this->queue_response( 200, $this->dse_payload( 'active', $evil ) );

		$out = $this->shortcode();

		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( 'onclick', $out );
		$this->assertStringNotContainsString( 'javascript:', $out );
		$this->assertStringNotContainsString( '<iframe', $out );
		$stored = $this->store->get();
		$this->assertStringNotContainsString( '<script', $stored['html'] );
		$this->assertStringNotContainsString( 'onclick', $stored['html'] );
	}

	public function test_a_tampered_database_value_is_filtered_on_output(): void {
		$this->connect();
		update_option(
			Options::DSE,
			array(
				'html'         => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)>',
				'state'        => 'active',
				'remote_state' => 'active',
				'approved_at'  => '2026-10-16T08:30:00Z',
				'generated_at' => null,
				'checked_at'   => time(),
			),
			false
		);

		$out = $this->shortcode();

		$this->assertStringContainsString( '<p>ok</p>', $out );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( '<img', $out );
	}

	public function test_a_stale_copy_is_served_and_refreshed_in_the_background(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload() );
		$this->sync->refresh();
		$this->requests = array();

		$option               = get_option( Options::DSE );
		$option['checked_at'] = time() - DseStore::MAX_AGE - 10;
		update_option( Options::DSE, $option, false );

		$out = $this->shortcode();

		$this->assertStringContainsString( 'Datenschutz', $out );
		$this->assertCount( 0, $this->requests, 'A stale copy must never trigger a synchronous remote call.' );
		$this->assertNotFalse( wp_next_scheduled( Options::EVENT_REFRESH_DSE ) );

		// Further page views do not schedule more events.
		$this->shortcode();
		$this->assertCount( 1, _get_cron_array()[ wp_next_scheduled( Options::EVENT_REFRESH_DSE ) ][ Options::EVENT_REFRESH_DSE ] );
	}

	public function test_the_scheduled_event_updates_the_copy(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload( 'active', '<p>Version 1</p>' ) );
		$this->sync->refresh();

		$this->queue_response( 200, $this->dse_payload( 'active', '<p>Version 2</p>' ) );
		$this->sync->run_scheduled();

		$this->assertSame( '<p>Version 2</p>', $this->store->get()['html'] );
	}

	public function test_the_event_is_registered_on_boot(): void {
		$this->assertNotFalse( has_action( Options::EVENT_REFRESH_DSE ) );
	}

	public function test_not_approved_keeps_the_last_approved_copy(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload( 'active', '<p>Approved</p>' ) );
		$this->sync->refresh();

		$this->queue_response( 200, $this->dse_payload( 'not_approved' ) );
		$this->sync->refresh();

		$this->assertSame( DseStore::REMOTE_NOT_APPROVED, $this->store->remote_state() );
		$this->assertStringContainsString( 'Approved', $this->shortcode() );
	}

	public function test_missing_keeps_the_last_approved_copy(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload( 'active', '<p>Approved</p>' ) );
		$this->sync->refresh();

		$this->queue_response( 200, $this->dse_payload( 'missing' ) );
		$this->sync->refresh();

		$this->assertSame( DseStore::REMOTE_MISSING, $this->store->remote_state() );
		$this->assertStringContainsString( 'Approved', $this->shortcode() );
	}

	public function test_without_an_approved_copy_not_approved_stays_empty_and_is_not_refetched(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload( 'not_approved' ) );

		$this->assertSame( '', $this->shortcode() );
		$this->assertSame( '', $this->shortcode() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_lapsed_replaces_the_copy_with_the_notice(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload( 'active', '<p>Approved</p>' ) );
		$this->sync->refresh();

		$this->queue_response( 200, $this->dse_payload( 'lapsed', '<p>Notice of the service</p>' ) );
		$this->sync->refresh();

		$out = $this->shortcode();
		$this->assertStringContainsString( 'Notice of the service', $out );
		$this->assertStringNotContainsString( 'Approved', $out );
	}

	public function test_after_lapsed_a_not_approved_state_does_not_show_the_old_notice(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload( 'lapsed', '<p>Notice of the service</p>' ) );
		$this->sync->refresh();

		$this->queue_response( 200, $this->dse_payload( 'not_approved' ) );
		$this->sync->refresh();

		$this->assertSame( '', $this->shortcode() );
	}

	public function test_an_active_response_that_filters_to_nothing_is_rejected(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload( 'active', '<p>Approved</p>' ) );
		$this->sync->refresh();

		$this->queue_response( 200, $this->dse_payload( 'active', '<script>alert(1)</script>' ) );
		$result = $this->sync->refresh( true );

		$this->assertFalse( $result->is_ok() );
		$this->assertStringContainsString( 'Approved', $this->shortcode() );
	}

	public function test_failures_keep_the_copy(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload( 'active', '<p>Approved</p>' ) );
		$this->sync->refresh();

		foreach ( array( 401, 403, 500 ) as $code ) {
			$this->queue_service_error( $code, 'unauthorized' );
			$this->sync->refresh( true );
			$this->assertStringContainsString( 'Approved', $this->shortcode(), "After HTTP $code" );
		}
	}

	public function test_attributes_are_ignored(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload() );

		$out = do_shortcode( '[complianty_dse html="<script>alert(1)</script>" url="https://evil.example"]' );

		$this->assertStringNotContainsString( 'evil', $out );
		$this->assertStringNotContainsString( '<script', $out );
	}

	public function test_an_active_backoff_blocks_the_first_fetch(): void {
		$this->connect();
		( new Cache() )->register_failure();

		$this->assertSame( '', $this->shortcode() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_output_object_without_token_does_not_touch_the_network(): void {
		$output = new DseOutput( $this->store, $this->sync, new TokenStore(), new Settings() );

		$this->assertSame( '', $output->render() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_no_link_to_complianty_by_default(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload() );

		$out = $this->shortcode();

		$this->assertStringNotContainsString( 'complianty-dse__credit', $out );
		$this->assertStringNotContainsString( 'complianty.de', $out );
		$this->assertFalse( ( new Settings() )->show_credit() );
	}

	public function test_the_link_appears_below_the_policy_once_the_owner_turns_it_on(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload() );
		update_option( Options::SETTINGS, array( 'show_credit' => true ) );

		$out = $this->shortcode();

		$this->assertMatchesRegularExpression( '#<p>Text</p><p class="complianty-dse__credit"><a href="https://complianty\.de/\?utm_source=wordpress-org(&\#038;|&amp;)utm_medium=plugin" target="_blank" rel="noopener noreferrer">[^<]+</a></p></div>$#', $out );
		$this->assertSame( $out, do_blocks( '<!-- wp:complianty/dse /-->' ), 'The block shows the same output.' );
	}

	public function test_the_link_is_not_shown_without_a_policy(): void {
		$this->connect();
		$this->queue_response( 200, $this->dse_payload( 'not_approved' ) );
		update_option( Options::SETTINGS, array( 'show_credit' => true ) );

		$this->assertSame( '', $this->shortcode() );
	}

	/**
	 * Hostile values for the setting.
	 *
	 * @return array<string,array{0:mixed,1:bool}>
	 */
	public function setting_inputs(): array {
		return array(
			'checked'        => array( array( 'show_credit' => '1' ), true ),
			'unchecked'      => array( array(), false ),
			'empty string'   => array( '', false ),
			'zero'           => array( array( 'show_credit' => '0' ), false ),
			'string instead' => array( 'show_credit', false ),
			'nested junk'    => array(
				array(
					'show_credit' => array( 'x' ),
					'evil'        => '<script>',
				),
				true,
			),
		);
	}

	/**
	 * @dataProvider setting_inputs
	 *
	 * @param mixed $input    Submitted value.
	 * @param bool  $expected Expected flag.
	 */
	public function test_the_setting_accepts_only_a_boolean( $input, bool $expected ): void {
		$clean = ( new Settings() )->sanitize( $input );

		$this->assertSame( array( 'show_credit' => $expected ), $clean );
	}

	public function test_saving_the_display_form_does_not_touch_the_token_and_vice_versa(): void {
		$this->connect();
		$before = get_option( Options::TOKEN );
		$this->login_as( 'administrator' );

		update_option( Options::SETTINGS, array( 'show_credit' => '1' ) );
		$this->assertSame( $before, get_option( Options::TOKEN ) );

		update_option( Options::TOKEN, 'clp_site_ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210_-ab' );
		$this->assertTrue( ( new Settings() )->show_credit() );
	}
}
