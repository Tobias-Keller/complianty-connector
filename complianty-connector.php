<?php
/**
 * Plugin Name:       Complianty Connector
 * Plugin URI:        https://complianty.de/
 * Description:       Shows the status of your Complianty website scan in the WordPress admin and outputs your privacy policy from your Complianty account.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Complianty
 * Author URI:        https://complianty.de/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       complianty-connector
 * Domain Path:       /languages
 *
 * @package Complianty\Connector
 */

defined( 'ABSPATH' ) || exit;

define( 'COMPLIANTY_CONNECTOR_VERSION', '1.0.0' );
define( 'COMPLIANTY_CONNECTOR_FILE', __FILE__ );

$complianty_connector_autoload = __DIR__ . '/vendor/autoload.php';

if ( ! is_readable( $complianty_connector_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Complianty Connector: The installation is incomplete. Please reinstall the plugin.', 'complianty-connector' )
				. '</p></div>';
		}
	);
	return;
}

require_once $complianty_connector_autoload;

add_action( 'plugins_loaded', array( \Complianty\Connector\Plugin::class, 'boot' ) );
register_deactivation_hook( __FILE__, array( \Complianty\Connector\Plugin::class, 'deactivate' ) );
