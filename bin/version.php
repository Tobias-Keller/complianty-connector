<?php
/**
 * Keeps the plugin version in sync (WordPress.org guideline 15).
 *
 * Usage:
 *   php bin/version.php check [expected]   Fails if the places disagree (or differ from "expected").
 *   php bin/version.php set 1.2.3          Writes the version to every place.
 *
 * Places: plugin header, COMPLIANTY_CONNECTOR_VERSION, readme.txt "Stable tag", composer.json.
 * "set" also requires a changelog entry in readme.txt, "check" verifies it exists.
 *
 * @package Complianty\Connector
 */

// phpcs:disable -- Development script, not shipped in the release.

$root = dirname( __DIR__ );

$files = array(
	'plugin'   => $root . '/complianty-connector.php',
	'readme'   => $root . '/readme.txt',
	'composer' => $root . '/composer.json',
);

/**
 * Reads the versions found in each place.
 *
 * @param array $files File paths.
 * @return array
 */
function complianty_versions( array $files ): array {
	$plugin   = file_get_contents( $files['plugin'] );
	$readme   = file_get_contents( $files['readme'] );
	$composer = json_decode( file_get_contents( $files['composer'] ), true );

	preg_match( '/^ \* Version:\s+(\S+)$/m', $plugin, $header );
	preg_match( "/define\( 'COMPLIANTY_CONNECTOR_VERSION', '([^']+)' \);/", $plugin, $constant );
	preg_match( '/^Stable tag:\s+(\S+)$/m', $readme, $stable );

	return array(
		'plugin header' => $header[1] ?? '(missing)',
		'constant'      => $constant[1] ?? '(missing)',
		'readme.txt'    => $stable[1] ?? '(missing)',
		'composer.json' => $composer['version'] ?? '(missing)',
	);
}

$command = $argv[1] ?? '';
$value   = $argv[2] ?? '';

if ( 'set' === $command ) {
	if ( 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $value ) ) {
		fwrite( STDERR, "Version must look like 1.2.3\n" );
		exit( 2 );
	}

	$readme = file_get_contents( $files['readme'] );
	if ( false === strpos( $readme, "\n= {$value} =\n" ) ) {
		fwrite( STDERR, "Add a changelog entry \"= {$value} =\" to readme.txt first.\n" );
		exit( 2 );
	}

	$plugin = file_get_contents( $files['plugin'] );
	$plugin = preg_replace( '/^( \* Version:\s+)\S+$/m', '${1}' . $value, $plugin );
	$plugin = preg_replace( "/(define\( 'COMPLIANTY_CONNECTOR_VERSION', ')[^']+(' \);)/", '${1}' . $value . '${2}', $plugin );
	file_put_contents( $files['plugin'], $plugin );

	$readme = preg_replace( '/^(Stable tag:\s+)\S+$/m', '${1}' . $value, $readme );
	file_put_contents( $files['readme'], $readme );

	$composer            = json_decode( file_get_contents( $files['composer'] ), true );
	$composer['version'] = $value;
	file_put_contents( $files['composer'], json_encode( $composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );

	echo "Version set to {$value}. Review with git diff.\n";
	exit( 0 );
}

if ( 'check' === $command ) {
	$versions = complianty_versions( $files );
	$unique   = array_unique( array_values( $versions ) );
	$ok       = true;

	foreach ( $versions as $place => $found ) {
		echo str_pad( $place, 15 ) . $found . "\n";
	}

	if ( 1 !== count( $unique ) ) {
		fwrite( STDERR, "Versions differ.\n" );
		$ok = false;
	}
	if ( '' !== $value && $unique[0] !== $value ) {
		fwrite( STDERR, "Expected {$value}.\n" );
		$ok = false;
	}
	if ( false === strpos( file_get_contents( $files['readme'] ), "\n= {$unique[0]} =\n" ) ) {
		fwrite( STDERR, "readme.txt has no changelog entry for {$unique[0]}.\n" );
		$ok = false;
	}

	exit( $ok ? 0 : 1 );
}

fwrite( STDERR, "Usage: php bin/version.php check [expected] | set X.Y.Z\n" );
exit( 2 );
