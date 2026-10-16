<?php
/**
 * The token must not appear in any log, message, markup or stored value except the one encrypted option.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Integration;

use Complianty\Connector\Admin\Notices;
use Complianty\Connector\Admin\SettingsPage;
use Complianty\Connector\Admin\StatusPage;
use Complianty\Connector\Api\Client;
use Complianty\Connector\Api\Result;
use Complianty\Connector\Frontend\DseSync;
use Complianty\Connector\Storage\Cache;
use Complianty\Connector\Storage\DseStore;
use Complianty\Connector\Storage\Options;
use Complianty\Connector\Storage\TokenStore;
use Complianty\Connector\Support\Messages;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @covers \Complianty\Connector\Support\Log
 * @covers \Complianty\Connector\Support\Messages
 */
final class TokenLeakTest extends IntegrationTestCase {

	/**
	 * Temporary PHP error log.
	 *
	 * @var string
	 */
	private $log_file;

	/**
	 * Previous error_log ini value.
	 *
	 * @var string|false
	 */
	private $previous_log;

	public function set_up() {
		parent::set_up();

		add_filter( 'complianty_connector_debug_log', '__return_true' );
		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'complianty-log' );
		$this->previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Captures the log in this test.
	}

	public function tear_down() {
		ini_set( 'error_log', (string) $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the log.
		if ( file_exists( $this->log_file ) ) {
			unlink( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup of a temp file.
		}
		parent::tear_down();
	}

	/**
	 * Contents of the captured log.
	 *
	 * @return string
	 */
	private function log(): string {
		return (string) file_get_contents( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the temp log.
	}

	/**
	 * Failures that echo the token back, as a hostile or broken service might.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function echoing_failures(): array {
		return array(
			'html error'       => array( 'html' ),
			'json error'       => array( 'json' ),
			'transport error'  => array( 'transport' ),
			'wrong type'       => array( 'type' ),
			'schema violation' => array( 'schema' ),
		);
	}

	/**
	 * Queues one kind of failure that contains the token.
	 *
	 * @param string $kind Failure kind.
	 */
	private function queue_echo( string $kind ): void {
		switch ( $kind ) {
			case 'html':
				$this->queue_response(
					502,
					'<h1>Bad gateway</h1> Authorization: Bearer ' . self::TOKEN,
					array(
						'content-type' => 'text/html',
						'x-echo'       => self::TOKEN,
					)
				);
				break;
			case 'json':
				$this->queue_response(
					500,
					array(
						'detail' => array(
							'detail' => self::TOKEN,
							'token'  => self::TOKEN,
						),
					)
				);
				break;
			case 'transport':
				$this->queue_error( 'cURL error 7: could not connect, sent Authorization: Bearer ' . self::TOKEN );
				break;
			case 'type':
				$this->queue_response( 200, self::TOKEN, array( 'content-type' => 'text/plain' ) );
				break;
			default:
				$this->queue_response(
					200,
					array(
						'status'     => self::TOKEN,
						'scan_state' => self::TOKEN,
					)
				);
		}
	}

	/**
	 * @dataProvider echoing_failures
	 *
	 * @param string $kind Failure kind.
	 */
	public function test_failures_never_surface_the_token( string $kind ): void {
		$this->connect();
		$this->login_as( 'administrator' );
		$cache  = new Cache();
		$client = new Client( new TokenStore(), $cache );
		$store  = new DseStore();

		$this->queue_echo( $kind );
		$result  = $client->status( true );
		$outputs = array(
			Messages::for_result( $result ),
			$result->kind() . $result->code() . wp_json_encode( $result->data() ),
		);

		// Admin page after the failure.
		$page      = new SettingsPage( new TokenStore(), $cache, $store, new DseSync( $client, $store ), new StatusPage( $client, $cache ), new Notices(), $client, new \Complianty\Connector\Storage\Settings() );
		$outputs[] = $this->capture( array( $page, 'render' ) );

		// Flash notice after a failed refresh.
		$notices = new Notices();
		$notices->flash_error( $result );
		$outputs[] = $this->capture( array( $notices, 'render' ) );

		// REST error.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->queue_echo( $kind );
		$response  = rest_do_request( new WP_REST_Request( 'POST', '/complianty-connector/v1/scan' ) );
		$outputs[] = (string) wp_json_encode( $response->get_data() );

		// Frontend.
		$this->queue_echo( $kind );
		( new DseSync( $client, $store ) )->refresh( true );
		$outputs[] = do_shortcode( '[complianty_dse]' );

		foreach ( $outputs as $index => $output ) {
			$this->assertStringNotContainsString( self::TOKEN, (string) $output, "Output $index leaks the token ($kind)." );
			$this->assertStringNotContainsString( substr( self::TOKEN, 9, 24 ), (string) $output, "Output $index leaks part of the token ($kind)." );
		}
		$this->assertStringNotContainsString( self::TOKEN, $this->log(), "The log leaks the token ($kind)." );
		$this->assertStringNotContainsString( substr( self::TOKEN, 9, 24 ), $this->log(), "The log leaks part of the token ($kind)." );
	}

	public function test_the_debug_log_records_failures_without_the_token(): void {
		$this->connect();
		$this->queue_echo( 'transport' );

		( new Client( new TokenStore(), new Cache() ) )->status( true );

		$this->assertStringContainsString( 'Complianty Connector', $this->log() );
		$this->assertStringContainsString( '[redacted]', $this->log() );
		$this->assertStringNotContainsString( self::TOKEN, $this->log() );
	}

	public function test_every_message_is_free_of_tokens_and_markup(): void {
		$kinds = array( Result::OK, Result::UNAUTHORIZED, Result::FORBIDDEN, Result::RATE_LIMITED, Result::COOLDOWN, Result::BUDGET, Result::CONFLICT, Result::REJECTED, Result::SERVER_ERROR, Result::NETWORK, Result::INVALID, Result::BACKOFF, Result::NO_TOKEN );
		$codes = array( '', 'subscription_inactive', 'feature_not_available', 'insufficient_scope', 'unsafe_url', 'scanner_busy' );

		foreach ( $kinds as $kind ) {
			foreach ( $codes as $code ) {
				foreach ( array( Messages::CONTEXT_GENERIC, Messages::CONTEXT_SCAN, Messages::CONTEXT_FREE_SCAN ) as $context ) {
					$message = Messages::for_result( Result::failure( $kind, $code, 3600 ), $context );
					$this->assertSame( wp_strip_all_tags( $message ), $message, "$kind/$code contains markup." );
					$this->assertStringNotContainsString( 'clp_site_', $message );
					$this->assertNotSame( '', $message );
				}
			}
		}
	}

	public function test_only_the_encrypted_option_holds_the_token_after_a_full_flow(): void {
		global $wpdb;
		$this->login_as( 'administrator' );
		update_option( Options::TOKEN, self::TOKEN );

		$cache  = new Cache();
		$client = new Client( new TokenStore(), $cache );
		$store  = new DseStore();
		$this->queue_response( 200, $this->status_payload() );
		$cache->set_status( $client->status( true )->data() );
		$this->queue_response( 200, $this->dse_payload() );
		( new DseSync( $client, $store ) )->refresh( true );
		$this->queue_error( 'boom' );
		$client->status( true );
		( new Notices() )->flash_error( Result::failure( Result::NETWORK ) );

		$hits = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( self::TOKEN ) . '%' ) );
		$this->assertSame( array(), $hits, 'No option or transient may contain the plaintext token.' );

		$meta = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->usermeta} WHERE meta_value LIKE %s", '%' . $wpdb->esc_like( self::TOKEN ) . '%' ) );
		$this->assertSame( array(), $meta );
	}

	public function test_the_token_is_only_sent_in_the_authorization_header(): void {
		$this->connect();
		$client = new Client( new TokenStore(), new Cache() );

		$this->queue_response( 200, $this->status_payload() );
		$client->status( true );
		$this->queue_response( 200, $this->dse_payload() );
		$client->dse( true );
		$this->queue_response(
			202,
			array(
				'job_id'     => 'j1',
				'scan_state' => 'queued',
			)
		);
		$client->start_scan();
		$this->queue_response(
			200,
			array(
				'scan_state'      => 'idle',
				'last_scanned_at' => null,
			)
		);
		$client->scan_status( true );

		$this->assertCount( 4, $this->requests );
		foreach ( $this->requests as $request ) {
			$copy = $request;
			unset( $copy['args']['headers']['Authorization'] );
			$this->assertStringNotContainsString( 'clp_site_', (string) wp_json_encode( $copy ), 'Token outside the Authorization header.' );
			$this->assertSame( 'Bearer ' . self::TOKEN, $request['args']['headers']['Authorization'] );
		}
	}
}
