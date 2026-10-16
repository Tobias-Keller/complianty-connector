<?php
/**
 * Privacy policy HTML filtering.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Filters privacy policy HTML from the remote service with a narrow whitelist.
 *
 * The filtered copy is what gets stored and output. The raw HTML is never output.
 */
final class DseRenderer {

	/**
	 * Allowed tags and attributes.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_html(): array {
		return array(
			'h1'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'h5'         => array(),
			'h6'         => array(),
			'p'          => array(),
			'br'         => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'blockquote' => array(),
			'table'      => array(),
			'thead'      => array(),
			'tbody'      => array(),
			'tr'         => array(),
			'th'         => array(
				'colspan' => true,
				'rowspan' => true,
			),
			'td'         => array(
				'colspan' => true,
				'rowspan' => true,
			),
			'a'          => array(
				'href' => true,
				'rel'  => true,
			),
		);
	}

	/**
	 * Allowed URL protocols in links.
	 *
	 * @return string[]
	 */
	public static function allowed_protocols(): array {
		return array( 'http', 'https', 'mailto' );
	}

	/**
	 * Filters HTML.
	 *
	 * @param string $html Raw HTML from the service.
	 * @return string Safe HTML.
	 */
	public static function sanitize( string $html ): string {
		// Cosmetic pre-pass: kses drops these tags but keeps their text, which would show up as
		// visible junk (script source, CSS). The security gate is kses below, not this pattern.
		// A tag with thousands of characters of attributes is never legitimate, and kses gets very slow on it
		// (quadratic in the number of attributes on older WordPress versions). Drop such tags. The scan stops at
		// the next angle bracket, so this stays linear on input with thousands of unclosed tags.
		$html = (string) preg_replace( '#<[a-z/!?][^<>]{4000,}+>?#i', '', $html );

		// Bounded tag length and "$" as the end keep this linear on input with thousands of unclosed tags.
		$html = (string) preg_replace( '#<(script|style|textarea|title|iframe|object|noscript|template|svg|math)\b[^>]{0,1000}+>.*?(?:</\1\s*>|$)#is', '', $html );

		$safe = wp_kses( $html, self::allowed_html(), self::allowed_protocols() );

		// kses keeps comments. A privacy policy has no use for them, so drop them.
		do {
			$before = $safe;
			$safe   = (string) preg_replace( '/<!--.*?(?:-->|$)/s', '', $safe );
		} while ( $safe !== $before );

		// Force a safe rel on every link. Only real tags are touched, never text.
		return (string) preg_replace_callback(
			'/<a\s[^>]*>/i',
			static function ( array $found ): string {
				$tag = (string) preg_replace( '/\srel="[^"]*"/i', '', $found[0] );

				return (string) preg_replace( '/^<a\s+/i', '<a rel="noopener noreferrer" ', $tag );
			},
			$safe
		);
	}
}
