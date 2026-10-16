<?php
/**
 * DseRenderer XSS tests against the real wp_kses.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Frontend\DseRenderer;
use WP_UnitTestCase;

/**
 * @covers \Complianty\Connector\Frontend\DseRenderer
 */
final class DseRendererTest extends WP_UnitTestCase {

	/**
	 * Hostile payloads. Each one is wrapped in harmless markup.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function payloads(): array {
		$payloads = array(
			'script tag'                 => '<script>alert(1)</script>',
			'script with src'            => '<script src="https://evil.example/x.js"></script>',
			'script uppercase'           => '<SCRIPT>alert(1)</SCRIPT>',
			'script split case'          => '<ScRiPt >alert(1)</sCrIpT>',
			'script nested'              => '<scr<script>ipt>alert(1)</scr</script>ipt>',
			'img onerror'                => '<img src=x onerror=alert(1)>',
			'img onerror quoted'         => '<img src="x" onerror="alert(1)">',
			'svg onload'                 => '<svg onload=alert(1)></svg>',
			'svg script'                 => '<svg><script>alert(1)</script></svg>',
			'math'                       => '<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
			'iframe'                     => '<iframe src="https://evil.example"></iframe>',
			'iframe srcdoc'              => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
			'iframe javascript'          => '<iframe src="javascript:alert(1)"></iframe>',
			'object'                     => '<object data="https://evil.example/x.swf"></object>',
			'embed'                      => '<embed src="https://evil.example/x.swf">',
			'applet'                     => '<applet code="x"></applet>',
			'form'                       => '<form action="https://evil.example" method="post"><input name="p"><button>Go</button></form>',
			'input autofocus'            => '<input autofocus onfocus=alert(1)>',
			'textarea'                   => '<textarea autofocus onfocus=alert(1)></textarea>',
			'select'                     => '<select onfocus=alert(1) autofocus></select>',
			'button formaction'          => '<button formaction="javascript:alert(1)">x</button>',
			'style tag'                  => '<style>body{display:none}</style>',
			'style attribute'            => '<p style="background:url(javascript:alert(1))">x</p>',
			'style position'             => '<p style="position:fixed;top:0;left:0;width:100%;height:100%">x</p>',
			'link stylesheet'            => '<link rel="stylesheet" href="https://evil.example/x.css">',
			'meta refresh'               => '<meta http-equiv="refresh" content="0;url=https://evil.example">',
			'base'                       => '<base href="https://evil.example/">',
			'body onload'                => '<body onload=alert(1)>',
			'html tag'                   => '<html onmouseover=alert(1)>x</html>',
			'video onerror'              => '<video><source onerror=alert(1)></video>',
			'audio'                      => '<audio src=x onerror=alert(1)></audio>',
			'details ontoggle'           => '<details open ontoggle=alert(1)>x</details>',
			'marquee'                    => '<marquee onstart=alert(1)>x</marquee>',
			'a onclick'                  => '<a href="https://example.com" onclick="alert(1)">x</a>',
			'a onmouseover'              => '<a href="https://example.com" onmouseover=alert(1)>x</a>',
			'p onmouseover'              => '<p onmouseover="alert(1)">x</p>',
			'h2 onclick'                 => '<h2 onclick="alert(1)">x</h2>',
			'a javascript'               => '<a href="javascript:alert(1)">x</a>',
			'a javascript uppercase'     => '<a href="JAVASCRIPT:alert(1)">x</a>',
			'a javascript mixed'         => '<a href="JaVaScRiPt:alert(1)">x</a>',
			'a javascript leading space' => '<a href="  javascript:alert(1)">x</a>',
			'a javascript tab'           => "<a href=\"java\tscript:alert(1)\">x</a>",
			'a javascript newline'       => "<a href=\"java\nscript:alert(1)\">x</a>",
			'a javascript entity'        => '<a href="jav&#x61;script:alert(1)">x</a>',
			'a javascript entity dec'    => '<a href="&#106;avascript:alert(1)">x</a>',
			'a javascript entity colon'  => '<a href="javascript&colon;alert(1)">x</a>',
			'a javascript null'          => "<a href=\"java\0script:alert(1)\">x</a>",
			'a vbscript'                 => '<a href="vbscript:msgbox(1)">x</a>',
			'a data html'                => '<a href="data:text/html,<script>alert(1)</script>">x</a>',
			'a data base64'              => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
			'a data uppercase'           => '<a href="DATA:text/html,x">x</a>',
			'a file'                     => '<a href="file:///etc/passwd">x</a>',
			'a ftp'                      => '<a href="ftp://evil.example/x">x</a>',
			'a tel'                      => '<a href="tel:+491234">x</a>',
			'a blob'                     => '<a href="blob:https://example.com/x">x</a>',
			'a target blank'             => '<a href="https://example.com" target="_blank">x</a>',
			'a rel opener'               => '<a href="https://example.com" rel="opener">x</a>',
			'a ping'                     => '<a href="https://example.com" ping="https://evil.example/log">x</a>',
			'a download'                 => '<a href="https://example.com/x.exe" download>x</a>',
			'a style'                    => '<a href="https://example.com" style="position:absolute">x</a>',
			'a id class'                 => '<a href="https://example.com" id="x" class="y" data-x="z">x</a>',
			'comment'                    => '<!-- <script>alert(1)</script> -->',
			'conditional comment'        => '<!--[if IE]><script>alert(1)</script><![endif]-->',
			'cdata'                      => '<![CDATA[<script>alert(1)</script>]]>',
			'php tag'                    => '<?php echo 1; ?>',
			'xml pi'                     => '<?xml version="1.0"?><x/>',
			'unclosed tag'               => '<a href="https://example.com" onclick="alert(1)"',
			'attribute without quotes'   => '<a href=https://example.com onclick=alert(1)>x</a>',
			'slash separator'            => '<img/src=x/onerror=alert(1)>',
			'null in tag'                => "<scr\0ipt>alert(1)</scr\0ipt>",
			'utf7'                       => '+ADw-script+AD4-alert(1)+ADw-/script+AD4-',
			'td colspan script'          => '<table><tr><td colspan="<script>alert(1)</script>">x</td></tr></table>',
			'th rowspan quote'           => '<table><tr><th rowspan="1" onclick="alert(1)">x</th></tr></table>',
			'template'                   => '<template><script>alert(1)</script></template>',
			'noscript'                   => '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>',
			'xmp'                        => '<xmp><script>alert(1)</script></xmp>',
			'plaintext'                  => '<plaintext><script>alert(1)</script>',
			'dialog'                     => '<dialog open onclose=alert(1)>x</dialog>',
			'canvas'                     => '<canvas onclick=alert(1)></canvas>',
		);

		$cases = array();
		foreach ( $payloads as $name => $payload ) {
			$cases[ $name ] = array( $payload );
		}

		return $cases;
	}

	/**
	 * Asserts that no active content is left in the output.
	 *
	 * Text that merely looks like markup is escaped by kses (a literal "<" becomes "&lt;"),
	 * so every "<" left in the output starts a real tag. Each of them must be an allowed
	 * tag with allowed, double quoted attributes and a safe link target.
	 *
	 * @param string $out     Sanitized output.
	 * @param string $payload Payload, for the failure message.
	 */
	private function assert_inert( string $out, string $payload ): void {
		$message = 'Payload survived: ' . $payload . ' => ' . $out;

		$allowed_tags  = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a' );
		$allowed_attrs = array( 'href', 'rel', 'colspan', 'rowspan' );

		$this->assertStringNotContainsString( "\0", $out, $message );
		$this->assertStringNotContainsString( '<!', $out, $message );
		$this->assertStringNotContainsString( '<?', $out, $message );

		$tag_count = preg_match_all( '/<([^>]*)>/', $out, $matches );
		$this->assertSame( substr_count( $out, '<' ), $tag_count, 'Stray "<" in output. ' . $message );

		foreach ( $matches[1] as $inner ) {
			$this->assertSame( 1, preg_match( '#^/?([a-z][a-z0-9]*)((?:\s+[a-z]+="[^"<>]*")*)\s*/?$#', $inner, $parts ), 'Malformed tag <' . $inner . '>. ' . $message );
			$this->assertContains( $parts[1], $allowed_tags, 'Tag not allowed: ' . $parts[1] . '. ' . $message );

			preg_match_all( '/\s([a-z]+)="([^"]*)"/', $parts[2], $attrs, PREG_SET_ORDER );
			foreach ( $attrs as $attr ) {
				$this->assertContains( $attr[1], $allowed_attrs, 'Attribute not allowed: ' . $attr[1] . '. ' . $message );
				if ( 'href' === $attr[1] ) {
					$this->assert_safe_url( $attr[2], $message );
				}
			}
		}
	}

	/**
	 * Asserts that a link target is relative or uses an allowed scheme.
	 *
	 * @param string $url     Attribute value.
	 * @param string $message Failure message.
	 */
	private function assert_safe_url( string $url, string $message ): void {
		$decoded = strtolower( (string) preg_replace( '/[\x00-\x20]+/', '', html_entity_decode( $url, ENT_QUOTES | ENT_HTML5 ) ) );

		if ( 1 === preg_match( '#^([a-z][a-z0-9+.-]*):#', $decoded, $scheme ) ) {
			$this->assertContains( $scheme[1], array( 'http', 'https', 'mailto' ), 'Unsafe link scheme in ' . $url . '. ' . $message );
		}
	}

	/**
	 * @dataProvider payloads
	 *
	 * @param string $payload Hostile markup.
	 */
	public function test_payload_is_neutralised( string $payload ): void {
		$html = '<h2>Heading</h2><p>Before</p>' . $payload . '<p>After</p>';
		$out  = DseRenderer::sanitize( $html );

		$this->assert_inert( $out, $payload );
		$this->assertStringContainsString( '<h2>Heading</h2>', $out );
		$this->assertStringContainsString( 'Before', $out );
		$this->assertStringContainsString( 'After', $out );
	}

	/**
	 * @dataProvider payloads
	 *
	 * @param string $payload Hostile markup.
	 */
	public function test_sanitizing_is_idempotent( string $payload ): void {
		$once  = DseRenderer::sanitize( $payload );
		$twice = DseRenderer::sanitize( $once );

		$this->assertSame( $once, $twice );
	}

	public function test_safe_content_is_kept(): void {
		$html = '<h1>A</h1><h2>B</h2><h3>C</h3><p>Text <strong>bold</strong> <em>em</em> <b>b</b> <i>i</i><br>next</p>'
			. '<ul><li>One</li></ul><ol><li>Two</li></ol><blockquote>Quote</blockquote>'
			. '<table><thead><tr><th colspan="2">H</th></tr></thead><tbody><tr><td rowspan="2">D</td></tr></tbody></table>';

		$out = DseRenderer::sanitize( $html );

		foreach ( array( '<h1>A</h1>', '<h3>C</h3>', '<strong>bold</strong>', '<em>em</em>', '<br>', '<li>One</li>', '<blockquote>Quote</blockquote>', '<th colspan="2">H</th>', '<td rowspan="2">D</td>' ) as $expected ) {
			$this->assertStringContainsString( $expected, $out );
		}
	}

	public function test_allowed_links_get_a_safe_rel(): void {
		$out = DseRenderer::sanitize( '<a href="https://example.com/a?x=1&amp;y=2">web</a> <a href="http://example.com">plain</a> <a href="mailto:mail@example.com">mail</a>' );

		$this->assertSame( 3, substr_count( $out, 'rel="noopener noreferrer"' ) );
		$this->assertStringContainsString( 'href="https://example.com/a?x=1&amp;y=2"', $out );
		$this->assertStringContainsString( 'href="mailto:mail@example.com"', $out );
	}

	public function test_an_existing_rel_cannot_weaken_the_link(): void {
		$out = DseRenderer::sanitize( '<a href="https://example.com" rel="opener external">x</a>' );

		$this->assertSame( '<a rel="noopener noreferrer" href="https://example.com">x</a>', $out );
	}

	public function test_relative_and_anchor_links_are_allowed_without_scripts(): void {
		$out = DseRenderer::sanitize( '<a href="#section">anchor</a><a href="/datenschutz">rel</a>' );

		$this->assertStringContainsString( 'href="#section"', $out );
		$this->assertStringContainsString( 'href="/datenschutz"', $out );
	}

	public function test_script_text_content_is_not_executable(): void {
		$out = DseRenderer::sanitize( '<script>alert(document.cookie)</script>' );

		$this->assertStringNotContainsString( '<', $out );
	}

	public function test_empty_and_huge_input(): void {
		$this->assertSame( '', DseRenderer::sanitize( '' ) );

		$big = str_repeat( '<p>' . str_repeat( 'a', 100 ) . '</p>', 5000 );
		$this->assertStringContainsString( '<p>', DseRenderer::sanitize( $big ) );
	}

	public function test_text_that_looks_like_a_rel_attribute_is_left_alone(): void {
		$out = DseRenderer::sanitize( '<p>Use rel="nofollow" on links.</p><a href="https://example.com">x</a>' );

		$this->assertStringContainsString( 'Use rel="nofollow" on links.', $out );
	}

	public function test_script_and_style_content_does_not_show_up_as_text(): void {
		$out = DseRenderer::sanitize( '<style>body{display:none}</style><p>Visible</p><script>alert(1)</script><svg><text>x</text></svg>' );

		$this->assertSame( '<p>Visible</p>', $out );
	}

	public function test_unclosed_script_is_removed_with_its_content(): void {
		$out = DseRenderer::sanitize( '<p>Visible</p><script>alert(1)' );

		$this->assertSame( '<p>Visible</p>', $out );
	}

	public function test_a_void_element_does_not_swallow_the_text_after_it(): void {
		$out = DseRenderer::sanitize( '<p>One</p><embed src="https://evil.example/x"><input name="x"><p>Two</p>' );

		$this->assertSame( '<p>One</p><p>Two</p>', $out );
	}

	public function test_an_oversized_tag_is_dropped_and_the_text_around_it_stays(): void {
		$huge = '<a href="https://example.com" ' . str_repeat( 'x="y" ', 1500 ) . '>link</a>';
		$out  = DseRenderer::sanitize( '<p>Before</p>' . $huge . '<p>After</p>' );

		$this->assertStringContainsString( '<p>Before</p>', $out );
		$this->assertStringContainsString( '<p>After</p>', $out );
		$this->assertStringNotContainsString( 'x="y"', $out );
	}

	public function test_long_but_normal_links_survive(): void {
		$url = 'https://example.com/' . str_repeat( 'a', 1500 );
		$out = DseRenderer::sanitize( '<a href="' . $url . '">x</a>' );

		$this->assertStringContainsString( $url, $out );
	}
}
