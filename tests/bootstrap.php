<?php
/**
 * PHPUnit bootstrap for unit tests (no WordPress runtime).
 *
 * @package Complianty\Connector
 */

define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant, defined for unit tests without WordPress.

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
