<?php
/**
 * Schema tests for dse, scan and free scan payloads.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Tests\Unit;

use Complianty\Connector\Api\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Complianty\Connector\Api\Schema
 */
final class SchemaConnectTest extends TestCase {

	public function test_dse_active(): void {
		$data = Schema::dse(
			array(
				'state'        => 'active',
				'html'         => '<h2>Datenschutz</h2>',
				'approved_at'  => '2026-10-16T08:30:00Z',
				'generated_at' => '2026-10-15T08:30:00.123456Z',
				'extra'        => 'dropped',
			)
		);

		$this->assertSame( 'active', $data['state'] );
		$this->assertSame( '<h2>Datenschutz</h2>', $data['html'] );
		$this->assertArrayNotHasKey( 'extra', $data );
	}

	public function test_dse_active_needs_html_and_approval(): void {
		$base = array(
			'state'        => 'active',
			'html'         => '<p>x</p>',
			'approved_at'  => '2026-10-16T08:30:00Z',
			'generated_at' => null,
		);

		$this->assertNull( Schema::dse( array_merge( $base, array( 'html' => '' ) ) ) );
		$this->assertNull( Schema::dse( array_merge( $base, array( 'html' => null ) ) ) );
		$this->assertNull( Schema::dse( array_merge( $base, array( 'html' => array( '<p>' ) ) ) ) );
		$this->assertNull( Schema::dse( array_merge( $base, array( 'approved_at' => null ) ) ) );
	}

	public function test_dse_html_is_dropped_for_states_without_content(): void {
		foreach ( array( 'not_approved', 'missing' ) as $state ) {
			$data = Schema::dse(
				array(
					'state'        => $state,
					'html'         => '<script>alert(1)</script>',
					'approved_at'  => null,
					'generated_at' => '2026-10-15T08:30:00Z',
				)
			);

			$this->assertNull( $data['html'] );
		}
	}

	public function test_dse_lapsed_carries_the_notice(): void {
		$data = Schema::dse(
			array(
				'state'        => 'lapsed',
				'html'         => '<p>Hinweis</p>',
				'approved_at'  => null,
				'generated_at' => null,
			)
		);

		$this->assertSame( '<p>Hinweis</p>', $data['html'] );
	}

	public function test_dse_rejects_bad_input(): void {
		$this->assertNull( Schema::dse( 'x' ) );
		$this->assertNull( Schema::dse( array( 'state' => 'draft' ) ) );
		$this->assertNull(
			Schema::dse(
				array(
					'state'       => 'missing',
					'html'        => null,
					'approved_at' => 'yesterday',
				)
			)
		);
		$this->assertNull(
			Schema::dse(
				array(
					'state'       => 'active',
					'html'        => str_repeat( 'a', Schema::MAX_HTML + 1 ),
					'approved_at' => '2026-10-16T08:30:00Z',
				)
			)
		);
	}

	public function test_scan_accepted(): void {
		$this->assertSame(
			array(
				'job_id'     => 'job-1',
				'scan_state' => 'queued',
			),
			Schema::scan_accepted(
				array(
					'job_id'     => 'job-1',
					'scan_state' => 'queued',
					'x'          => 1,
				)
			)
		);
		$this->assertNull(
			Schema::scan_accepted(
				array(
					'job_id'     => 'a b',
					'scan_state' => 'queued',
				)
			)
		);
		$this->assertNull(
			Schema::scan_accepted(
				array(
					'job_id'     => 'job-1',
					'scan_state' => 'done',
				)
			)
		);
		$this->assertNull(
			Schema::scan_accepted(
				array(
					'job_id'     => 5,
					'scan_state' => 'queued',
				)
			)
		);
	}

	public function test_scan_status(): void {
		$data = Schema::scan_status(
			array(
				'scan_state'      => 'running',
				'last_scanned_at' => '2026-10-16T08:30:00Z',
				'last_scan_error' => "boom\x00<b>",
			)
		);

		$this->assertSame( 'running', $data['scan_state'] );
		$this->assertSame( 'boom<b>', $data['last_scan_error'] );
		$this->assertNull(
			Schema::scan_status(
				array(
					'scan_state'      => 'weird',
					'last_scanned_at' => null,
				)
			)
		);
		$this->assertNull(
			Schema::scan_status(
				array(
					'scan_state'      => 'idle',
					'last_scanned_at' => 'now',
				)
			)
		);
	}

	public function test_free_scan_accepted(): void {
		$data = Schema::free_scan_accepted(
			array(
				'job_id'       => 'demo-0123456789ab',
				'status'       => 'queued',
				'access_token' => 'abcdefghijklmnopqrstuvwxyz012345',
			)
		);

		$this->assertSame( 'demo-0123456789ab', $data['job_id'] );
		$this->assertNull(
			Schema::free_scan_accepted(
				array(
					'job_id'       => 'demo-1',
					'status'       => 'queued',
					'access_token' => 'short',
				)
			)
		);
		$this->assertNull(
			Schema::free_scan_accepted(
				array(
					'job_id'       => '../x',
					'status'       => 'queued',
					'access_token' => 'abcdefghijklmnopqrstuvwxyz012345',
				)
			)
		);
	}

	/**
	 * Valid free scan result.
	 *
	 * @return array<string,mixed>
	 */
	private function free_result(): array {
		return array(
			'url'               => 'https://example.de',
			'cmp_detected'      => true,
			'cmp_name'          => 'Cookiebot',
			'consent_required'  => true,
			'total_tools_found' => 7,
			'total_violations'  => 2,
			'preview_tools'     => array(
				array(
					'name'        => 'Google Analytics',
					'category'    => 'analytics',
					'pre_consent' => true,
				),
			),
			'impressum_overall' => 'ok',
			'ssl_overall'       => 'issues',
			'scanned_at'        => '2026-10-16T08:30:00+00:00',
		);
	}

	public function test_free_scan_done(): void {
		$data = Schema::free_scan_status(
			array(
				'job_id' => 'demo-1',
				'status' => 'done',
				'result' => $this->free_result(),
			)
		);

		$this->assertSame( 'done', $data['status'] );
		$this->assertSame( 7, $data['result']['total_tools_found'] );
		$this->assertSame( 'Google Analytics', $data['result']['preview_tools'][0]['name'] );
		$this->assertArrayNotHasKey( 'url', $data['result'] );
	}

	public function test_free_scan_running_and_error_have_no_result(): void {
		$running = Schema::free_scan_status(
			array(
				'job_id' => 'demo-1',
				'status' => 'running',
			)
		);
		$this->assertNull( $running['result'] );
		$this->assertFalse( $running['failed'] );

		$failed = Schema::free_scan_status(
			array(
				'job_id' => 'demo-1',
				'status' => 'error',
				'error'  => 'scan_failed',
			)
		);
		$this->assertTrue( $failed['failed'] );
	}

	public function test_free_scan_done_without_valid_result_is_rejected(): void {
		$this->assertNull(
			Schema::free_scan_status(
				array(
					'job_id' => 'demo-1',
					'status' => 'done',
				)
			)
		);

		$broken                     = $this->free_result();
		$broken['total_violations'] = -1;
		$this->assertNull(
			Schema::free_scan_status(
				array(
					'job_id' => 'demo-1',
					'status' => 'done',
					'result' => $broken,
				)
			)
		);

		$bad_tool                  = $this->free_result();
		$bad_tool['preview_tools'] = array( array( 'name' => 'x' ) );
		$this->assertNull(
			Schema::free_scan_status(
				array(
					'job_id' => 'demo-1',
					'status' => 'done',
					'result' => $bad_tool,
				)
			)
		);

		$bad_enum                = $this->free_result();
		$bad_enum['ssl_overall'] = 'great';
		$this->assertNull(
			Schema::free_scan_status(
				array(
					'job_id' => 'demo-1',
					'status' => 'done',
					'result' => $bad_enum,
				)
			)
		);
	}

	public function test_free_scan_texts_are_capped_and_tools_limited(): void {
		$result                  = $this->free_result();
		$result['cmp_name']      = str_repeat( 'a', 500 );
		$result['preview_tools'] = array_fill(
			0,
			50,
			array(
				'name'        => str_repeat( 'n', 500 ),
				'category'    => 'x',
				'pre_consent' => false,
			)
		);

		$data = Schema::free_scan_status(
			array(
				'job_id' => 'demo-1',
				'status' => 'done',
				'result' => $result,
			)
		);

		$this->assertSame( 100, strlen( $data['result']['cmp_name'] ) );
		$this->assertCount( Schema::MAX_TOOLS, $data['result']['preview_tools'] );
		$this->assertSame( 80, strlen( $data['result']['preview_tools'][0]['name'] ) );
	}

	public function test_line_breaks_at_the_end_of_identifiers_and_dates_are_rejected(): void {
		$this->assertNull(
			Schema::scan_accepted(
				array(
					'job_id'     => "job-1\n",
					'scan_state' => 'queued',
				)
			)
		);
		$this->assertNull(
			Schema::scan_status(
				array(
					'scan_state'      => 'idle',
					'last_scanned_at' => "2026-10-16T08:30:00Z\n",
				)
			)
		);
		$this->assertNull(
			Schema::free_scan_accepted(
				array(
					'job_id'       => 'demo-1',
					'status'       => 'queued',
					'access_token' => "abcdefghijklmnopqrstuvwxyz012345\n",
				)
			)
		);
	}

	public function test_text_direction_overrides_are_stripped_and_invalid_utf8_is_rejected(): void {
		$data = Schema::scan_status(
			array(
				'scan_state'      => 'idle',
				'last_scanned_at' => null,
				'last_scan_error' => "ab\u{202E}cd",
			)
		);
		$this->assertSame( 'abcd', $data['last_scan_error'] );

		$this->assertNull(
			Schema::scan_status(
				array(
					'scan_state'      => 'idle',
					'last_scanned_at' => null,
					'last_scan_error' => "bad \xC3\x28 utf8",
				)
			)
		);
	}
}
