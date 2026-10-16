<?php
/**
 * Widget on the admin dashboard.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Admin;

use Complianty\Connector\Storage\TokenStore;
use Complianty\Connector\Support\Links;
use Complianty\Connector\Support\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Compact status for administrators, shown only while a token is set.
 *
 * It reads the same cached status as the plugin page. A stale status is refreshed with a short
 * timeout and the usual failure backoff, so a slow service cannot hold up the dashboard.
 */
final class DashboardWidget {

	public const ID           = 'complianty_connector_status';
	private const TIMEOUT     = 3;
	private const SHOWN_ITEMS = 3;

	/**
	 * Token storage.
	 *
	 * @var TokenStore
	 */
	private $tokens;

	/**
	 * Status rendering and loading.
	 *
	 * @var StatusPage
	 */
	private $status_page;

	/**
	 * Constructor.
	 *
	 * @param TokenStore $tokens      Token storage.
	 * @param StatusPage $status_page Status rendering and loading.
	 */
	public function __construct( TokenStore $tokens, StatusPage $status_page ) {
		$this->tokens      = $tokens;
		$this->status_page = $status_page;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the widget for administrators with a configured token.
	 *
	 * @return void
	 */
	public function add_widget(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) || ! $this->tokens->has_token() ) {
			return;
		}

		wp_add_dashboard_widget( self::ID, __( 'Complianty status', 'complianty-connector' ), array( $this, 'render' ) );
	}

	/**
	 * Loads the stylesheet on the dashboard only.
	 *
	 * @param string $hook Admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'index.php' !== $hook || ! current_user_can( SettingsPage::CAPABILITY ) || ! $this->tokens->has_token() ) {
			return;
		}

		wp_enqueue_style( 'complianty-connector-admin', plugins_url( 'assets/admin.css', COMPLIANTY_CONNECTOR_FILE ), array(), COMPLIANTY_CONNECTOR_VERSION );
	}

	/**
	 * Widget content.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		echo '<div class="complianty-wrap complianty-widget">';

		$loaded = $this->status_page->load_status( false, self::TIMEOUT );
		if ( null !== $loaded['error'] ) {
			printf( '<p>%s</p>', esc_html( Messages::for_result( $loaded['error'] ) ) );
		}

		if ( null !== $loaded['data'] ) {
			$this->status_page->render_summary( $loaded['data'] );
			$this->status_page->render_todos( $loaded['data'], self::SHOWN_ITEMS, false );
		}

		printf(
			'<p class="complianty-widget__links"><a href="%1$s">%2$s</a> | <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p>',
			esc_url( SettingsPage::page_url() ),
			esc_html__( 'Details and scan', 'complianty-connector' ),
			esc_url( Links::dashboard() ),
			esc_html__( 'Complianty dashboard', 'complianty-connector' )
		);
		echo '</div>';
	}
}
