<?php
/**
 * German translation tests.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Api\Result;
use Complianty\Connector\Support\Messages;
use WP_UnitTestCase;

/**
 * @covers \Complianty\Connector\Plugin
 */
final class TranslationTest extends WP_UnitTestCase {

	/**
	 * Locale forced by the current test.
	 *
	 * @var string
	 */
	private $forced_locale = 'en_US';

	public function tear_down() {
		$this->use_locale( 'en_US' );
		parent::tear_down();
	}

	/**
	 * Forces a locale and reloads the plugin texts, like a site with that language would.
	 *
	 * @param string $locale Locale.
	 * @return void
	 */
	private function use_locale( string $locale ): void {
		$this->forced_locale = $locale;
		remove_all_filters( 'locale' );
		add_filter(
			'locale',
			function () {
				return $this->forced_locale;
			}
		);
		unload_textdomain( 'complianty-connector' );
		$mofile = dirname( COMPLIANTY_CONNECTOR_FILE ) . '/languages/complianty-connector-' . $locale . '.mo';
		if ( is_readable( $mofile ) ) {
			load_textdomain( 'complianty-connector', $mofile, $locale );
		}
	}

	/**
	 * German locales the plugin ships.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function locales(): array {
		return array(
			'de_DE'        => array( 'de_DE' ),
			'de_DE_formal' => array( 'de_DE_formal' ),
			'de_AT'        => array( 'de_AT' ),
			'de_CH'        => array( 'de_CH' ),
		);
	}

	/**
	 * @dataProvider locales
	 *
	 * @param string $locale Locale.
	 */
	public function test_texts_are_german_in_german_locales( string $locale ): void {
		$this->use_locale( $locale );

		$text = Messages::for_result( Result::failure( Result::UNAUTHORIZED ) );
		$this->assertStringContainsString( 'Das Token ist ungültig oder wurde widerrufen', $text );
		$this->assertSame( 'Datenschutzerklärung', __( 'Privacy policy', 'complianty-connector' ) );
	}

	public function test_english_stays_the_default(): void {
		$text = Messages::for_result( Result::failure( Result::UNAUTHORIZED ) );

		$this->assertStringContainsString( 'The token is invalid or was revoked', $text );
	}

	public function test_plural_forms_are_translated(): void {
		$this->use_locale( 'de_DE' );

		$this->assertSame( '1 kritisch', sprintf( _n( '%d critical', '%d critical', 1, 'complianty-connector' ), 1 ) );
		$this->assertSame( '1 Tool wurde erkannt.', sprintf( _n( '%d tool was detected.', '%d tools were detected.', 1, 'complianty-connector' ), 1 ) );
		$this->assertSame( '3 Tools wurden erkannt.', sprintf( _n( '%d tool was detected.', '%d tools were detected.', 3, 'complianty-connector' ), 3 ) );
	}

	public function test_every_string_is_translated_and_placeholders_match(): void {
		$po     = (string) file_get_contents( dirname( COMPLIANTY_CONNECTOR_FILE ) . '/languages/complianty-connector-de_DE.po' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.
		$blocks = preg_split( '/\n\n/', $po );
		$empty  = array();

		foreach ( array_slice( $blocks, 1 ) as $block ) {
			if ( 1 !== preg_match( '/^msgid "(.*)"$/m', $block, $id ) || '' === $id[1] ) {
				continue;
			}
			if ( preg_match( '/^msgstr(?:\[0\])? ""$/m', $block ) && false === strpos( $block, 'msgstr ""' . "\n\"" ) ) {
				$empty[] = $id[1];
				continue;
			}
			preg_match_all( '/^msgid "(.*)"$/m', $block, $ids );
			preg_match_all( '/^msgstr(?:\[\d\])? "(.*)"$/m', $block, $strs );
			preg_match_all( '/%(?:\d\$)?[sd]/', implode( '', $ids[1] ), $wanted );
			preg_match_all( '/%(?:\d\$)?[sd]/', $strs[1][0], $got );
			$this->assertSame( $wanted[0], $got[0], 'Placeholders differ in: ' . $id[1] );
		}

		$this->assertSame( array( 'https://complianty.de/', 'Complianty' ), $empty, 'Only the untranslatable strings may stay empty.' );
	}

	public function test_javascript_translation_files_exist_for_both_scripts(): void {
		foreach ( array( 'de_DE', 'de_DE_formal', 'de_AT', 'de_CH' ) as $locale ) {
			foreach ( array( 'assets/admin.js', 'blocks/dse/edit.js' ) as $script ) {
				$file = dirname( COMPLIANTY_CONNECTOR_FILE ) . '/languages/complianty-connector-' . $locale . '-' . md5( $script ) . '.json';
				$this->assertFileExists( $file );
				$this->assertNotNull( json_decode( (string) file_get_contents( $file ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.
			}
		}
	}
}
