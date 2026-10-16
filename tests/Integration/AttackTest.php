<?php
/**
 * Attack scenarios against the plugin: hostile services, hostile plugins, hostile input.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Api\Client;
use Complianty\Connector\Api\Result;
use Complianty\Connector\Api\Schema;
use Complianty\Connector\Frontend\DseRenderer;
use Complianty\Connector\Storage\Cache;
use Complianty\Connector\Storage\TokenStore;

/**
 * @coversNothing
 */
final class AttackTest extends IntegrationTestCase {

	/**
	 * Builds a client.
	 *
	 * @return Client
	 */
	private function client(): Client {
		return new Client( new TokenStore(), new Cache() );
	}

	/**
	 * Values that end in a line break or carry other tricks around a valid token.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function malformed_tokens(): array {
		$valid = 'clp_site_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-ab';

		return array(
			'trailing newline'  => array( $valid . "\n" ),
			'trailing CRLF'     => array( $valid . "\r\n" ),
			'leading newline'   => array( "\n" . $valid ),
			'header injection'  => array( $valid . "\r\nX-Evil: 1" ),
			'trailing space'    => array( $valid . ' ' ),
			'null byte'         => array( $valid . "\0" ),
			'unicode lookalike' => array( 'clp_site_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-a' . "\u{0430}" ),
			'too long'          => array( 'clp_site_' . str_repeat( 'a', 65 ) ),
			'too short'         => array( 'clp_site_' . str_repeat( 'a', 31 ) ),
			'wrong prefix'      => array( 'clp_live_' . str_repeat( 'a', 40 ) ),
		);
	}

	/**
	 * @dataProvider malformed_tokens
	 *
	 * @param string $token Token candidate.
	 */
	public function test_malformed_tokens_are_never_accepted_or_sent( string $token ): void {
		$store = new TokenStore();

		$this->assertFalse( $store->is_valid_format( $token ) );
		$this->assertFalse( $store->set( $token ) );
		$this->assertFalse( $store->has_token() );
		$this->assertSame( Result::NO_TOKEN, $this->client()->status()->kind() );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * @dataProvider malformed_tokens
	 *
	 * @param string $token Token candidate.
	 */
	public function test_a_pasted_malformed_token_is_not_stored( string $token ): void {
		$this->login_as( 'administrator' );
		$page_input = trim( $token );

		// A pasted value is trimmed. Whatever survives trimming must still be a valid token to be stored.
		update_option( 'complianty_connector_token', $page_input );

		$stored = ( new TokenStore() )->get();
		if ( 1 === preg_match( '/\Aclp_site_[A-Za-z0-9_-]{32,64}\z/', $page_input ) ) {
			$this->assertSame( $page_input, $stored );
		} else {
			$this->assertNull( $stored );
			$this->assertEmpty( get_option( 'complianty_connector_token' ) );
		}
	}

	/**
	 * Identifiers that must not reach a URL.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function malformed_job_ids(): array {
		return array(
			'trailing newline' => array( "demo-1\n" ),
			'trailing CRLF'    => array( "demo-1\r\n" ),
			'slash'            => array( 'demo/1' ),
			'dot dot'          => array( '..' ),
			'query'            => array( 'demo-1?x=1' ),
			'fragment'         => array( 'demo-1#x' ),
			'percent'          => array( 'demo%2F1' ),
			'space'            => array( 'demo 1' ),
			'empty'            => array( '' ),
		);
	}

	/**
	 * @dataProvider malformed_job_ids
	 *
	 * @param string $job_id Job id.
	 */
	public function test_malformed_job_ids_never_reach_the_network( string $job_id ): void {
		$result = $this->client()->free_scan_job( $job_id, 'abcdefghijklmnopqrstuvwxyz012345' );

		$this->assertSame( Result::REJECTED, $result->kind() );
		$this->assertCount( 0, $this->requests );
		$this->assertNull(
			Schema::scan_accepted(
				array(
					'job_id'     => $job_id,
					'scan_state' => 'queued',
				)
			)
		);
		$this->assertNull(
			Schema::free_scan_accepted(
				array(
					'job_id'       => $job_id,
					'status'       => 'queued',
					'access_token' => 'abcdefghijklmnopqrstuvwxyz012345',
				)
			)
		);
	}

	public function test_a_job_token_with_a_trailing_newline_is_rejected(): void {
		$this->assertNull(
			Schema::free_scan_accepted(
				array(
					'job_id'       => 'demo-1',
					'status'       => 'queued',
					'access_token' => "abcdefghijklmnopqrstuvwxyz012345\n",
				)
			)
		);
		$this->assertSame( Result::REJECTED, $this->client()->free_scan_job( 'demo-1', "abcdefghijklmnopqrstuvwxyz012345\n" )->kind() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_another_plugin_cannot_weaken_the_transport(): void {
		$this->connect();
		add_filter(
			'http_request_args',
			static function ( $args ) {
				$args['sslverify']          = false;
				$args['redirection']        = 10;
				$args['reject_unsafe_urls'] = false;
				$args['timeout']            = 600;
				return $args;
			},
			PHP_INT_MAX - 1
		);
		add_filter( 'https_ssl_verify', '__return_false', PHP_INT_MAX );
		$verify_seen = null;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$verify_seen ) {
				$verify_seen = apply_filters( 'https_ssl_verify', $args['sslverify'], $url );
				return $pre;
			},
			1,
			3
		);
		$this->queue_response( 200, $this->status_payload() );

		$this->client()->status( true );

		$args = $this->requests[0]['args'];
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertLessThanOrEqual( 8, $args['timeout'] );
		$this->assertTrue( $verify_seen, 'The ssl verification filter must not be able to switch verification off.' );
	}

	public function test_the_hardening_filters_do_not_leak_to_other_requests(): void {
		$this->connect();
		$this->queue_response( 200, $this->status_payload() );
		$this->client()->status( true );

		global $wp_filter;
		$this->assertFalse( has_filter( 'https_ssl_verify', '__return_true' ) );
		$late = isset( $wp_filter['http_request_args'] ) ? array_keys( $wp_filter['http_request_args']->callbacks ) : array();
		$this->assertNotContains( PHP_INT_MAX, $late, 'No filter may stay registered after the request.' );

		$other = apply_filters(
			'http_request_args',
			array(
				'redirection' => 5,
				'sslverify'   => false,
			),
			'https://elsewhere.example/'
		);
		$this->assertSame( 5, $other['redirection'] );
		$this->assertFalse( $other['sslverify'] );
	}

	/**
	 * Hostile status payloads.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function hostile_status_payloads(): array {
		$base = array(
			'status'               => 'gaps',
			'open_critical'        => 1,
			'open_important'       => 1,
			'last_scanned_at'      => '2026-10-16T08:30:00Z',
			'scan_state'           => 'idle',
			'scan_trigger_allowed' => true,
			'todos'                => array(),
		);

		return array(
			'negative count'     => array( array_merge( $base, array( 'open_critical' => -1 ) ) ),
			'huge count'         => array( array_merge( $base, array( 'open_critical' => PHP_INT_MAX ) ) ),
			'float count'        => array( array_merge( $base, array( 'open_critical' => 1.5 ) ) ),
			'string count'       => array( array_merge( $base, array( 'open_critical' => '1<script>' ) ) ),
			'object status'      => array( array_merge( $base, array( 'status' => array( 'ok' ) ) ) ),
			'unknown status'     => array( array_merge( $base, array( 'status' => 'ok<script>' ) ) ),
			'scan state html'    => array( array_merge( $base, array( 'scan_state' => '<b>x</b>' ) ) ),
			'date injection'     => array( array_merge( $base, array( 'last_scanned_at' => '2026-10-16T08:30:00Z"><script>' ) ) ),
			'date trailing lf'   => array( array_merge( $base, array( 'last_scanned_at' => "2026-10-16T08:30:00Z\n" ) ) ),
			'todos not a list'   => array( array_merge( $base, array( 'todos' => 'x' ) ) ),
			'todo not an object' => array( array_merge( $base, array( 'todos' => array( 'x' ) ) ) ),
			'todo bad priority'  => array(
				array_merge(
					$base,
					array(
						'todos' => array(
							array(
								'id'       => '1',
								'type'     => 't',
								'priority' => 'urgent',
								'title'    => 'x',
								'text'     => 'y',
							),
						),
					)
				),
			),
			'todo array title'   => array(
				array_merge(
					$base,
					array(
						'todos' => array(
							array(
								'id'       => '1',
								'type'     => 't',
								'priority' => 'info',
								'title'    => array( 'x' ),
								'text'     => 'y',
							),
						),
					)
				),
			),
		);
	}

	/**
	 * @dataProvider hostile_status_payloads
	 *
	 * @param array<string,mixed> $payload Payload.
	 */
	public function test_hostile_status_payloads_are_rejected_and_nothing_is_stored( array $payload ): void {
		$this->connect();
		$this->queue_response( 200, $payload );

		$result = $this->client()->status( true );

		$this->assertSame( Result::INVALID, $result->kind() );
		$this->assertNull( ( new Cache() )->get_status() );
	}

	public function test_extra_and_nested_fields_are_dropped(): void {
		$this->connect();
		$payload                    = $this->status_payload();
		$payload['evidence']        = array( 'screenshot' => 'data:image/png;base64,AAAA' );
		$payload['tenant_name']     = 'Secret GmbH';
		$payload['todos'][0]['url'] = 'https://evil.example';
		$this->queue_response( 200, $payload );

		$data = $this->client()->status( true )->data();

		$this->assertArrayNotHasKey( 'evidence', $data );
		$this->assertArrayNotHasKey( 'tenant_name', $data );
		$this->assertArrayNotHasKey( 'url', $data['todos'][0] );
	}

	public function test_oversized_text_fields_are_cut(): void {
		$this->connect();
		$payload                      = $this->status_payload();
		$payload['todos'][0]['text']  = str_repeat( 'x', 100000 );
		$payload['todos'][0]['title'] = str_repeat( "\u{00e4}", 100000 );
		$this->queue_response( 200, $payload );

		$todo = $this->client()->status( true )->data()['todos'][0];

		$this->assertSame( 200, strlen( $todo['text'] ) );
		$this->assertSame( 200, mb_strlen( $todo['title'] ) );
	}

	public function test_only_ten_todos_are_kept(): void {
		$this->connect();
		$payload          = $this->status_payload();
		$payload['todos'] = array_fill( 0, 500, $payload['todos'][0] );
		$this->queue_response( 200, $payload );

		$this->assertCount( 10, $this->client()->status( true )->data()['todos'] );
	}

	public function test_text_direction_overrides_are_removed_from_service_text(): void {
		$text = "Safe\u{202E}evil\u{202C} \u{2066}x\u{2069} \u{200E}ok";
		$this->connect();
		$payload                      = $this->status_payload();
		$payload['todos'][0]['title'] = $text;
		$this->queue_response( 200, $payload );

		$title = $this->client()->status( true )->data()['todos'][0]['title'];

		$this->assertStringNotContainsString( "\u{202E}", $title );
		$this->assertStringNotContainsString( "\u{202C}", $title );
		$this->assertStringNotContainsString( "\u{2066}", $title );
		$this->assertStringContainsString( 'Safe', $title );
	}

	/**
	 * Inputs that make naive filters slow.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function pathological_html(): array {
		return array(
			'many unclosed iframes'  => array( str_repeat( '<iframe>', 80000 ) ),
			'many unclosed objects'  => array( str_repeat( '<object data=x>', 40000 ) ),
			'many unclosed svg'      => array( str_repeat( '<svg>', 100000 ) ),
			'many unclosed scripts'  => array( str_repeat( '<script>', 80000 ) ),
			'many unclosed styles'   => array( str_repeat( '<style>a', 80000 ) ),
			'many openers no gt'     => array( str_repeat( '<a href="x', 60000 ) ),
			'script openers no gt'   => array( str_repeat( '<script ', 90000 ) ),
			'iframe openers no gt'   => array( str_repeat( '<iframe ', 90000 ) ),
			'mixed openers'          => array( str_repeat( '<script><style><iframe><svg>', 25000 ) ),
			'closers only'           => array( str_repeat( '</script></iframe>', 40000 ) ),
			'many unclosed comments' => array( str_repeat( '<!--', 150000 ) ),
			'many attributes'        => array( '<a ' . str_repeat( 'a ', 150000 ) . '>x</a>' ),
			'many attribute values'  => array( '<a ' . str_repeat( 'href="a" ', 70000 ) . '>x</a>' ),
			'deep nesting'           => array( str_repeat( '<ul><li>', 20000 ) ),
			'many links'             => array( str_repeat( '<a href="https://example.com">x</a>', 20000 ) ),
			'many angle brackets'    => array( str_repeat( '<', 300000 ) ),
			'entity flood'           => array( str_repeat( '&#x', 100000 ) ),
		);
	}

	/**
	 * @dataProvider pathological_html
	 *
	 * @param string $html Hostile input.
	 */
	public function test_pathological_html_is_handled_quickly_and_safely( string $html ): void {
		$html  = substr( $html, 0, Schema::MAX_HTML );
		$start = microtime( true );

		$out = DseRenderer::sanitize( $html );

		$elapsed = microtime( true ) - $start;
		$this->assertLessThan( 3.0, $elapsed, 'Sanitizing took ' . round( $elapsed, 2 ) . ' s.' );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( '<iframe', $out );
		$this->assertStringNotContainsString( '<style', $out );
	}
}
