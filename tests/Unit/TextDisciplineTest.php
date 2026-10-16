<?php
/**
 * Wording rules for everything a user can read.
 *
 * WordPress.org guideline 9: no promises of legal compliance. House style: no dashes as punctuation.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Scans the shipped text for forbidden wording.
 */
final class TextDisciplineTest extends TestCase {

	/**
	 * Files with user facing text.
	 *
	 * @return string[]
	 */
	private function files(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array( $root . '/readme.txt', $root . '/complianty-connector.php', $root . '/uninstall.php', $root . '/SECURITY.md', $root . '/assets/admin.js', $root . '/assets/admin.css', $root . '/blocks/dse/block.json', $root . '/blocks/dse/edit.js' );

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Wording that promises legal outcomes.
	 *
	 * @return string
	 */
	private function forbidden_pattern(): string {
		return '/(dsgvo[- ]?konform|gdpr[- ]?(compliant|conform)|\bcompliant\b|\bcompliance\b|rechtssicher|rechtskonform|abmahnsicher|legally (safe|secure|compliant)|100 ?% ?(safe|secure|sicher)|powered by|guarantee)/i';
	}

	public function test_files_exist(): void {
		foreach ( $this->files() as $file ) {
			$this->assertFileExists( $file );
		}
	}

	public function test_no_compliance_promises(): void {
		foreach ( $this->files() as $file ) {
			$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.

			// The service's area key is a protocol identifier, not text a user reads.
			$content = str_replace( "'compliance'", "'area-key'", $content );

			$this->assertDoesNotMatchRegularExpression( $this->forbidden_pattern(), $content, basename( $file ) );
		}
	}

	public function test_no_dashes_in_texts(): void {
		foreach ( $this->files() as $file ) {
			$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.

			$this->assertStringNotContainsString( "\u{2014}", $content, basename( $file ) . ' contains an em dash.' );
			$this->assertStringNotContainsString( "\u{2013}", $content, basename( $file ) . ' contains an en dash.' );
		}
	}

	public function test_readme_follows_the_directory_rules(): void {
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.

		preg_match( '/^Tags: (.+)$/m', $readme, $tags );
		$this->assertLessThanOrEqual( 5, count( explode( ',', $tags[1] ) ), 'At most five tags.' );
		$this->assertStringContainsString( '== External services ==', $readme );
		$this->assertStringContainsString( 'https://complianty.de/agb', $readme );
		$this->assertStringContainsString( 'https://complianty.de/datenschutz', $readme );
		$this->assertMatchesRegularExpression( '/^Stable tag: \d+\.\d+\.\d+$/m', $readme );

		// Short description: the first paragraph after the header must stay under 150 characters.
		preg_match( '/License URI: .+\n\n(.+)\n/', $readme, $short );
		$this->assertLessThanOrEqual( 150, strlen( $short[1] ) );
	}

	public function test_every_external_host_in_the_code_is_a_complianty_host(): void {
		$root = dirname( __DIR__, 2 );
		$hits = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			preg_match_all( '#https?://([a-z0-9.-]+)#i', (string) file_get_contents( $file->getPathname() ), $matches ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.
			$hits = array_merge( $hits, $matches[1] );
		}

		foreach ( array_unique( $hits ) as $host ) {
			$this->assertMatchesRegularExpression( '/(^|\.)complianty\.de$|^example\.(com|org)$/', $host, "Unexpected host in code: $host" );
		}
	}
}
