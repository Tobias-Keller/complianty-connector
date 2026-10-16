<?php
/**
 * Dashboard widget and area grouping tests.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Admin\DashboardWidget;
use Complianty\Connector\Admin\StatusPage;
use Complianty\Connector\Api\Client;
use Complianty\Connector\Storage\Cache;
use Complianty\Connector\Storage\TokenStore;

/**
 * @covers \Complianty\Connector\Admin\DashboardWidget
 * @covers \Complianty\Connector\Admin\StatusPage
 * @covers \Complianty\Connector\Support\Areas
 */
final class DashboardWidgetTest extends IntegrationTestCase {

	/**
	 * Builds a widget with fresh services.
	 *
	 * @return DashboardWidget
	 */
	private function widget(): DashboardWidget {
		$cache = new Cache();

		return new DashboardWidget( new TokenStore(), new StatusPage( new Client( new TokenStore(), $cache ), $cache ) );
	}

	/**
	 * Todos in several areas.
	 *
	 * @return array<string,mixed>
	 */
	private function grouped_status(): array {
		$todo = static function ( string $id, string $priority, string $area, string $title ): array {
			return array(
				'id'       => $id,
				'type'     => 't',
				'priority' => $priority,
				'area'     => $area,
				'title'    => $title,
				'text'     => 'Text ' . $id,
			);
		};

		return $this->status_payload(
			array(
				'todos' => array(
					$todo( '1', 'critical', 'tracking', 'YouTube before consent' ),
					$todo( '2', 'critical', 'banner_audit', 'No consent manager' ),
					$todo( '3', 'important', 'a11y', 'Contrast too low' ),
					$todo( '4', 'important', 'a11y', 'Form labels missing' ),
					$todo( '5', 'important', 'nonsense', '<b>Unknown area</b>' ),
				),
			)
		);
	}

	public function test_no_widget_without_a_token_or_for_other_roles(): void {
		global $wp_meta_boxes;
		set_current_screen( 'dashboard' );

		$this->login_as( 'administrator' );
		$this->widget()->add_widget();
		$this->assertArrayNotHasKey( DashboardWidget::ID, $wp_meta_boxes['dashboard']['normal']['core'] ?? array() );

		$this->connect();
		$this->login_as( 'editor' );
		$this->widget()->add_widget();
		$this->assertArrayNotHasKey( DashboardWidget::ID, $wp_meta_boxes['dashboard']['normal']['core'] ?? array() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_widget_is_added_for_administrators_with_a_token(): void {
		global $wp_meta_boxes;
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );
		$this->connect();
		$this->login_as( 'administrator' );

		$this->widget()->add_widget();

		$found = false;
		foreach ( $wp_meta_boxes['dashboard'] as $context ) {
			foreach ( $context as $priority ) {
				$found = $found || isset( $priority[ DashboardWidget::ID ] );
			}
		}
		$this->assertTrue( $found );
	}

	public function test_widget_shows_the_summary_and_three_findings_from_the_cache(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		( new Cache() )->set_status( $this->grouped_status() );

		$html = $this->capture( array( $this->widget(), 'render' ) );

		$this->assertStringContainsString( 'Gaps found', $html );
		$this->assertStringContainsString( 'YouTube before consent', $html );
		$this->assertStringContainsString( 'Contrast too low', $html );
		$this->assertStringNotContainsString( 'Form labels missing', $html, 'Only three findings fit the widget.' );
		$this->assertStringNotContainsString( 'complianty-area', $html, 'The widget list is not grouped.' );
		$this->assertStringContainsString( 'options-general.php?page=complianty-connector', $html );
		$this->assertCount( 0, $this->requests );
	}

	public function test_widget_escapes_service_text_and_never_shows_the_token(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		( new Cache() )->set_status( $this->grouped_status() );

		$html = $this->capture( array( $this->widget(), 'render' ) );

		$this->assertStringNotContainsString( self::TOKEN, $html );
		$this->assertDoesNotMatchRegularExpression( '/<(script|img|iframe)\b/i', $html );
	}

	public function test_a_stale_status_is_fetched_with_a_short_timeout(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->queue_response( 200, $this->status_payload( array( 'status' => 'critical' ) ) );

		$html = $this->capture( array( $this->widget(), 'render' ) );

		$this->assertStringContainsString( 'Critical findings', $html );
		$this->assertSame( 3, $this->requests[0]['args']['timeout'] );
	}

	public function test_a_failing_service_does_not_slow_the_dashboard_down_again(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$this->queue_error( 'timeout' );

		$first = $this->capture( array( $this->widget(), 'render' ) );
		$this->capture( array( $this->widget(), 'render' ) );

		$this->assertStringContainsString( 'could not be reached', $first );
		$this->assertCount( 1, $this->requests );
	}

	public function test_findings_are_grouped_by_area_in_display_order(): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$cache = new Cache();
		$cache->set_status( $this->grouped_status() );
		$page = new StatusPage( new Client( new TokenStore(), $cache ), $cache );

		$html = $this->capture(
			static function () use ( $page ) {
				$page->render_todos( $page->load_status()['data'] );
			}
		);

		$tracking = strpos( $html, 'Tracking' );
		$banner   = strpos( $html, 'Cookie banner' );
		$a11y     = strpos( $html, 'Accessibility' );
		$other    = strpos( $html, 'Other' );
		$this->assertNotFalse( $tracking );
		$this->assertTrue( $tracking < $banner && $banner < $a11y && $a11y < $other );
		$this->assertStringContainsString( 'complianty-area__count">2<', $html, 'Two accessibility findings.' );
		$this->assertStringContainsString( '&lt;b&gt;Unknown area&lt;/b&gt;', $html, 'An unknown area becomes "Other", text stays escaped.' );
	}

	public function test_assets_load_on_the_dashboard_only_with_a_token(): void {
		$this->login_as( 'administrator' );

		$this->widget()->enqueue_assets( 'index.php' );
		$this->assertFalse( wp_style_is( 'complianty-connector-admin', 'enqueued' ) );

		$this->connect();
		$this->widget()->enqueue_assets( 'edit.php' );
		$this->assertFalse( wp_style_is( 'complianty-connector-admin', 'enqueued' ) );

		$this->widget()->enqueue_assets( 'index.php' );
		$this->assertTrue( wp_style_is( 'complianty-connector-admin', 'enqueued' ) );
	}
}
