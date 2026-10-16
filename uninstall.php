<?php
/**
 * Removes all plugin data on uninstall.
 *
 * @package Complianty\Connector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'complianty_connector_uninstall_site' ) ) {
	/**
	 * Deletes options, transients and scheduled events of the current site.
	 *
	 * Keep the names in sync with Complianty\Connector\Storage\Options. The class is not
	 * loaded here on purpose: uninstall must work even if the autoloader is missing.
	 *
	 * @return void
	 */
	function complianty_connector_uninstall_site() {
		$options    = array(
			'complianty_connector_token',
			'complianty_connector_dse',
			'complianty_connector_settings',
		);
		$transients = array(
			'complianty_status',
			'complianty_backoff',
			'complianty_connection',
			'complianty_scan_state',
			'complianty_free_scan',
			'complianty_dse_lock',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}
		foreach ( $transients as $transient ) {
			delete_transient( $transient );
		}

		// One-shot messages are per user and expire after two minutes. Remove those of administrators now.
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);
		foreach ( $admins as $admin_id ) {
			delete_transient( 'complianty_flash_' . (int) $admin_id );
		}

		wp_clear_scheduled_hook( 'complianty_connector_refresh_dse' );
	}
}

if ( is_multisite() ) {
	$complianty_connector_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $complianty_connector_sites as $complianty_connector_site_id ) {
		switch_to_blog( (int) $complianty_connector_site_id );
		complianty_connector_uninstall_site();
		restore_current_blog();
	}
} else {
	complianty_connector_uninstall_site();
}
