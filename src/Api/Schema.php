<?php
/**
 * Response validation.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Api;

use Complianty\Connector\Support\Areas;

defined( 'ABSPATH' ) || exit;

/**
 * Whitelist validation for API responses.
 *
 * Remote data is untrusted: unknown fields are dropped, types are enforced,
 * strings are length-capped. Invalid payloads yield null.
 */
final class Schema {

	public const MAX_TODOS     = 10;
	public const MAX_TEXT      = 200;
	public const MAX_ID        = 64;
	public const MAX_HTML      = 700000;
	public const MAX_TOOLS     = 20;
	private const STATES       = array( 'ok', 'gaps', 'critical', 'pending' );
	private const PRIORITIES   = array( 'critical', 'important', 'info' );
	private const SCAN_STATES  = array( 'idle', 'queued', 'running' );
	private const DSE_STATES   = array( 'active', 'lapsed', 'not_approved', 'missing' );
	private const JOB_STATES   = array( 'queued', 'running', 'done', 'error' );
	private const IMPRESSUM    = array( 'ok', 'issues', 'missing' );
	private const SSL          = array( 'ok', 'issues', 'critical' );
	public const JOB_ID        = '/\A[A-Za-z0-9_-]{1,40}\z/';
	public const JOB_TOKEN     = '/\A[A-Za-z0-9_-]{16,128}\z/';
	private const MAX_COUNT    = 100000;
	private const DATE_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/';

	/**
	 * Validates and normalises a status response.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array<string,mixed>|null
	 */
	public static function status( $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$state      = $data['status'] ?? null;
		$scan_state = $data['scan_state'] ?? null;
		if ( ! in_array( $state, self::STATES, true ) || ! in_array( $scan_state, self::SCAN_STATES, true ) ) {
			return null;
		}

		$critical  = self::count( $data['open_critical'] ?? null );
		$important = self::count( $data['open_important'] ?? null );
		if ( null === $critical || null === $important ) {
			return null;
		}

		$last_scanned = $data['last_scanned_at'] ?? null;
		if ( null !== $last_scanned && ( ! is_string( $last_scanned ) || 1 !== preg_match( self::DATE_PATTERN, $last_scanned ) ) ) {
			return null;
		}

		$todos = array();
		$raw   = $data['todos'] ?? array();
		if ( ! is_array( $raw ) ) {
			return null;
		}
		foreach ( array_slice( $raw, 0, self::MAX_TODOS ) as $item ) {
			$todo = self::todo( $item );
			if ( null === $todo ) {
				return null;
			}
			$todos[] = $todo;
		}

		return array(
			'status'               => $state,
			'open_critical'        => $critical,
			'open_important'       => $important,
			'last_scanned_at'      => $last_scanned,
			'scan_state'           => $scan_state,
			'scan_trigger_allowed' => true === ( $data['scan_trigger_allowed'] ?? false ),
			'todos'                => $todos,
		);
	}

	/**
	 * Validates a privacy policy response.
	 *
	 * Only "active" and "lapsed" carry HTML. An "active" response without HTML or without
	 * approval date contradicts the contract and is rejected.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array<string,mixed>|null
	 */
	public static function dse( $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$state = $data['state'] ?? null;
		if ( ! in_array( $state, self::DSE_STATES, true ) ) {
			return null;
		}

		$approved  = self::date( $data['approved_at'] ?? null );
		$generated = self::date( $data['generated_at'] ?? null );
		if ( false === $approved || false === $generated ) {
			return null;
		}

		$html = null;
		if ( 'active' === $state || 'lapsed' === $state ) {
			$raw = $data['html'] ?? null;
			if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_HTML || ( 'active' === $state && ( '' === trim( $raw ) || null === $approved ) ) ) {
				return null;
			}
			$html = $raw;
		}

		return array(
			'state'        => $state,
			'html'         => $html,
			'approved_at'  => $approved,
			'generated_at' => $generated,
		);
	}

	/**
	 * Validates the response to a scan request.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array<string,string>|null
	 */
	public static function scan_accepted( $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$job_id = $data['job_id'] ?? null;
		$state  = $data['scan_state'] ?? null;
		if ( ! is_string( $job_id ) || 1 !== preg_match( self::JOB_ID, $job_id ) || ! in_array( $state, self::SCAN_STATES, true ) ) {
			return null;
		}

		return array(
			'job_id'     => $job_id,
			'scan_state' => $state,
		);
	}

	/**
	 * Validates a scan status response.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array<string,mixed>|null
	 */
	public static function scan_status( $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$state = $data['scan_state'] ?? null;
		$last  = self::date( $data['last_scanned_at'] ?? null );
		if ( ! in_array( $state, self::SCAN_STATES, true ) || false === $last ) {
			return null;
		}

		$error = $data['last_scan_error'] ?? null;
		if ( null !== $error ) {
			$error = self::text( $error, self::MAX_TEXT );
			if ( null === $error ) {
				return null;
			}
		}

		return array(
			'scan_state'      => $state,
			'last_scanned_at' => $last,
			'last_scan_error' => $error,
		);
	}

	/**
	 * Validates the response to a free scan request.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array<string,string>|null
	 */
	public static function free_scan_accepted( $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$job_id = $data['job_id'] ?? null;
		$state  = $data['status'] ?? null;
		$token  = $data['access_token'] ?? null;
		if (
			! is_string( $job_id ) || 1 !== preg_match( self::JOB_ID, $job_id )
			|| ! in_array( $state, self::JOB_STATES, true )
			|| ! is_string( $token ) || 1 !== preg_match( self::JOB_TOKEN, $token )
		) {
			return null;
		}

		return array(
			'job_id'       => $job_id,
			'status'       => $state,
			'access_token' => $token,
		);
	}

	/**
	 * Validates a free scan job status, with the short result once it is done.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array<string,mixed>|null
	 */
	public static function free_scan_status( $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$job_id = $data['job_id'] ?? null;
		$state  = $data['status'] ?? null;
		if ( ! is_string( $job_id ) || 1 !== preg_match( self::JOB_ID, $job_id ) || ! in_array( $state, self::JOB_STATES, true ) ) {
			return null;
		}

		$result = null;
		if ( 'done' === $state ) {
			$result = self::free_scan_result( $data['result'] ?? null );
			if ( null === $result ) {
				return null;
			}
		}

		return array(
			'job_id' => $job_id,
			'status' => $state,
			'result' => $result,
			'failed' => 'error' === $state,
		);
	}

	/**
	 * Validates the short free scan result.
	 *
	 * @param mixed $data Raw result.
	 * @return array<string,mixed>|null
	 */
	private static function free_scan_result( $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$total      = self::count( $data['total_tools_found'] ?? null );
		$violations = self::count( $data['total_violations'] ?? null );
		$scanned    = self::text( $data['scanned_at'] ?? '', 40 );
		if ( null === $total || null === $violations || null === $scanned || ! is_bool( $data['cmp_detected'] ?? null ) ) {
			return null;
		}

		$consent = $data['consent_required'] ?? null;
		if ( null !== $consent && ! is_bool( $consent ) ) {
			return null;
		}

		$cmp_name = $data['cmp_name'] ?? null;
		if ( null !== $cmp_name ) {
			$cmp_name = self::text( $cmp_name, 100 );
		}

		$impressum = $data['impressum_overall'] ?? null;
		$ssl       = $data['ssl_overall'] ?? null;
		if ( ( null !== $impressum && ! in_array( $impressum, self::IMPRESSUM, true ) ) || ( null !== $ssl && ! in_array( $ssl, self::SSL, true ) ) ) {
			return null;
		}

		$raw_tools = $data['preview_tools'] ?? array();
		if ( ! is_array( $raw_tools ) ) {
			return null;
		}
		$tools = array();
		foreach ( array_slice( $raw_tools, 0, self::MAX_TOOLS ) as $item ) {
			$name     = is_array( $item ) ? self::text( $item['name'] ?? null, 80 ) : null;
			$category = is_array( $item ) ? self::text( $item['category'] ?? null, 40 ) : null;
			if ( null === $name || null === $category || ! is_bool( $item['pre_consent'] ?? null ) ) {
				return null;
			}
			$tools[] = array(
				'name'        => $name,
				'category'    => $category,
				'pre_consent' => $item['pre_consent'],
			);
		}

		return array(
			'cmp_detected'      => $data['cmp_detected'],
			'cmp_name'          => $cmp_name,
			'consent_required'  => $consent,
			'total_tools_found' => $total,
			'total_violations'  => $violations,
			'preview_tools'     => $tools,
			'impressum_overall' => $impressum,
			'ssl_overall'       => $ssl,
			'scanned_at'        => $scanned,
		);
	}

	/**
	 * Nullable ISO 8601 timestamp.
	 *
	 * @param mixed $value Raw value.
	 * @return string|false|null String if valid, null if absent, false if malformed.
	 */
	private static function date( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( self::DATE_PATTERN, $value ) ) {
			return false;
		}
		return $value;
	}

	/**
	 * Validates one to-do entry.
	 *
	 * @param mixed $item Raw entry.
	 * @return array<string,string>|null
	 */
	private static function todo( $item ): ?array {
		if ( ! is_array( $item ) ) {
			return null;
		}

		$priority = $item['priority'] ?? null;
		if ( ! in_array( $priority, self::PRIORITIES, true ) ) {
			return null;
		}

		$id    = self::text( $item['id'] ?? null, self::MAX_ID );
		$type  = self::text( $item['type'] ?? null, self::MAX_ID );
		$title = self::text( $item['title'] ?? null, self::MAX_TEXT );
		if ( null === $id || null === $type || null === $title ) {
			return null;
		}

		return array(
			'id'       => $id,
			'type'     => $type,
			'priority' => $priority,
			'area'     => Areas::normalize( $item['area'] ?? null ),
			'title'    => $title,
			'text'     => self::text( $item['text'] ?? '', self::MAX_TEXT ) ?? '',
		);
	}

	/**
	 * Plain text, length-capped, control characters removed. No HTML is accepted as markup.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum length in characters.
	 * @return string|null
	 */
	private static function text( $value, int $max ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$clean = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		if ( null === $clean ) {
			return null;
		}

		// Bidirectional overrides and isolates could make service text read differently from what it is.
		// This pattern needs valid UTF-8, so invalid text is rejected here.
		$clean = preg_replace( '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $clean );
		if ( null === $clean ) {
			return null;
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $clean, 0, $max ) : substr( $clean, 0, $max );
	}

	/**
	 * Non-negative bounded integer.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private static function count( $value ): ?int {
		if ( ! is_int( $value ) || $value < 0 || $value > self::MAX_COUNT ) {
			return null;
		}
		return $value;
	}
}
