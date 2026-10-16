<?php
/**
 * Plugin wiring.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector;

use Complianty\Connector\Admin\DashboardWidget;
use Complianty\Connector\Admin\Notices;
use Complianty\Connector\Admin\SettingsPage;
use Complianty\Connector\Admin\StatusPage;
use Complianty\Connector\Api\Client;
use Complianty\Connector\Frontend\DseBlock;
use Complianty\Connector\Frontend\DseOutput;
use Complianty\Connector\Frontend\DseShortcode;
use Complianty\Connector\Frontend\DseSync;
use Complianty\Connector\Rest\FreeScanController;
use Complianty\Connector\Rest\ScanController;
use Complianty\Connector\Storage\Cache;
use Complianty\Connector\Storage\DseStore;
use Complianty\Connector\Storage\Options;
use Complianty\Connector\Storage\Settings;
use Complianty\Connector\Storage\TokenStore;
use Complianty\Connector\Support\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Registers all hooks. Contains no business logic.
 *
 * Booting makes no remote request and prints nothing. Network contact only happens after
 * the site owner entered a token or pressed the free check button (WordPress.org guideline 7).
 */
final class Plugin {

	/**
	 * Entry point called on plugins_loaded.
	 *
	 * @return void
	 */
	public static function boot(): void {
		// Ships German texts, loaded for the German locales when no language pack from WordPress.org exists.
		add_action( 'init', array( self::class, 'load_textdomain' ) );

		$tokens    = new TokenStore();
		$cache     = new Cache();
		$client    = new Client( $tokens, $cache );
		$dse_store = new DseStore();
		$dse_sync  = new DseSync( $client, $dse_store );
		$settings  = new Settings();
		$output    = new DseOutput( $dse_store, $dse_sync, $tokens, $settings );

		( new DseShortcode( $output ) )->register();
		( new DseBlock( $output ) )->register();
		add_action( Options::EVENT_REFRESH_DSE, array( $dse_sync, 'run_scheduled' ) );

		( new Privacy() )->register();

		( new ScanController( $client, $cache ) )->register();
		( new FreeScanController( $client, $tokens ) )->register();

		// Not gated by is_admin(): the token option must be sanitized wherever it is written (WP-CLI, cron).
		$status_page = new StatusPage( $client, $cache );
		( new SettingsPage( $tokens, $cache, $dse_store, $dse_sync, $status_page, new Notices(), $client, $settings ) )->register();
		( new DashboardWidget( $tokens, $status_page ) )->register();
	}

	/**
	 * Loads the translations shipped in languages/.
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- WordPress only auto-loads packs from wp-content/languages. The German texts shipped in languages/ need this call.
		load_plugin_textdomain( 'complianty-connector', false, dirname( plugin_basename( COMPLIANTY_CONNECTOR_FILE ) ) . '/languages' );
	}

	/**
	 * Deactivation: removes scheduled events. Stored data stays until uninstall.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Options::EVENT_REFRESH_DSE );
	}
}
