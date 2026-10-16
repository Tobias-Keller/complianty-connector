<?php
/**
 * Schema tests.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Unit;

use Complianty\Connector\Api\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Complianty\Connector\Api\Schema
 */
final class SchemaTest extends TestCase {

	/**
	 * Valid payload.
	 *
	 * @return array<string,mixed>
	 */
	private function valid(): array {
		return array(
			'status'               => 'gaps',
			'open_critical'        => 1,
			'open_important'       => 2,
			'last_scanned_at'      => '2026-10-16T08:30:00Z',
			'scan_state'           => 'idle',
			'scan_trigger_allowed' => true,
			'todos'                => array(
				array(
					'id'       => 'abc',
					'type'     => 'tool_pre_consent',
					'priority' => 'critical',
					'title'    => 'Tool vor Einwilligung',
					'text'     => 'Details',
				),
			),
		);
	}

	public function test_valid_payload_is_normalised(): void {
		$result = Schema::status( $this->valid() );

		$this->assertIsArray( $result );
		$this->assertSame( 'gaps', $result['status'] );
		$this->assertTrue( $result['scan_trigger_allowed'] );
		$this->assertCount( 1, $result['todos'] );
	}

	public function test_unknown_fields_are_dropped(): void {
		$data                     = $this->valid();
		$data['extra']            = '<script>alert(1)</script>';
		$data['todos'][0]['evil'] = 'x';

		$result = Schema::status( $data );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'extra', $result );
		$this->assertArrayNotHasKey( 'evil', $result['todos'][0] );
	}

	/**
	 * @dataProvider invalid_payloads
	 *
	 * @param mixed $mutation Callable mutating a valid payload, or a raw value.
	 */
	public function test_invalid_payloads_are_rejected( $mutation ): void {
		$data = is_callable( $mutation ) ? $mutation( $this->valid() ) : $mutation;

		$this->assertNull( Schema::status( $data ) );
	}

	/**
	 * @return array<string,array<mixed>>
	 */
	public function invalid_payloads(): array {
		return array(
			'not an array'       => array( 'string' ),
			'null'               => array( null ),
			'unknown status'     => array(
				static function ( array $d ) {
					$d['status'] = 'hacked';
					return $d;
				},
			),
			'unknown scan state' => array(
				static function ( array $d ) {
					$d['scan_state'] = 'weird';
					return $d;
				},
			),
			'negative count'     => array(
				static function ( array $d ) {
					$d['open_critical'] = -1;
					return $d;
				},
			),
			'string count'       => array(
				static function ( array $d ) {
					$d['open_important'] = '2';
					return $d;
				},
			),
			'huge count'         => array(
				static function ( array $d ) {
					$d['open_critical'] = 99999999;
					return $d;
				},
			),
			'bad date'           => array(
				static function ( array $d ) {
					$d['last_scanned_at'] = 'yesterday';
					return $d;
				},
			),
			'bad priority'       => array(
				static function ( array $d ) {
					$d['todos'][0]['priority'] = 'urgent';
					return $d;
				},
			),
			'todo not array'     => array(
				static function ( array $d ) {
					$d['todos'] = array( 'x' );
					return $d;
				},
			),
			'missing title'      => array(
				static function ( array $d ) {
					unset( $d['todos'][0]['title'] );
					return $d;
				},
			),
		);
	}

	public function test_pending_status_and_queued_scan_state_are_accepted(): void {
		$data                    = $this->valid();
		$data['status']          = 'pending';
		$data['last_scanned_at'] = null;
		$data['scan_state']      = 'queued';

		$result = Schema::status( $data );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 'queued', $result['scan_state'] );
	}

	public function test_null_last_scan_is_allowed(): void {
		$data                    = $this->valid();
		$data['last_scanned_at'] = null;

		$result = Schema::status( $data );

		$this->assertIsArray( $result );
		$this->assertNull( $result['last_scanned_at'] );
	}

	public function test_long_strings_are_capped(): void {
		$data                      = $this->valid();
		$data['todos'][0]['title'] = str_repeat( 'a', 5000 );

		$result = Schema::status( $data );

		$this->assertIsArray( $result );
		$this->assertSame( Schema::MAX_TEXT, strlen( $result['todos'][0]['title'] ) );
	}

	public function test_todo_list_is_capped(): void {
		$data          = $this->valid();
		$data['todos'] = array_fill( 0, 50, $data['todos'][0] );

		$result = Schema::status( $data );

		$this->assertIsArray( $result );
		$this->assertCount( Schema::MAX_TODOS, $result['todos'] );
	}

	public function test_control_characters_are_removed(): void {
		$data                      = $this->valid();
		$data['todos'][0]['title'] = "Ti\x00tle\x07";

		$result = Schema::status( $data );

		$this->assertIsArray( $result );
		$this->assertSame( 'Title', $result['todos'][0]['title'] );
	}

	public function test_scan_trigger_requires_strict_true(): void {
		$data                         = $this->valid();
		$data['scan_trigger_allowed'] = 'yes';

		$result = Schema::status( $data );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['scan_trigger_allowed'] );
	}

	public function test_area_is_whitelisted_and_defaults_to_other(): void {
		$with                     = $this->valid();
		$with['todos'][0]['area'] = 'a11y';
		$this->assertSame( 'a11y', Schema::status( $with )['todos'][0]['area'] );

		$bad                     = $this->valid();
		$bad['todos'][0]['area'] = '<script>';
		$this->assertSame( 'other', Schema::status( $bad )['todos'][0]['area'] );

		$this->assertSame( 'other', Schema::status( $this->valid() )['todos'][0]['area'], 'Older service versions send no area.' );
	}
}
