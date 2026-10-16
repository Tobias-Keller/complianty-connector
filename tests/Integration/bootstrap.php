<?php
/**
 * PHPUnit bootstrap for integration tests (real WordPress test suite).
 *
 * Run inside the wp-env tests container: npm run test:integration
 *
 * @package Complianty\Connector
 */

$complianty_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $complianty_tests_dir ) {
	$complianty_tests_dir = '/wordpress-phpunit';
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant read by the WordPress test suite.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once $complianty_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/complianty-connector.php';
	}
);

require $complianty_tests_dir . '/includes/bootstrap.php';
