<?php
/**
 * Local copy of the privacy policy.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Storage;

use Complianty\Connector\Frontend\DseRenderer;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the last approved privacy policy, already filtered, plus the state of the last check.
 *
 * The copy is only ever replaced by a newer approved version or by the service's notice
 * for a lapsed subscription. An edit or a deletion in Complianty does not remove it.
 */
final class DseStore {

	public const MAX_AGE = 21600;

	public const REMOTE_ACTIVE       = 'active';
	public const REMOTE_LAPSED       = 'lapsed';
	public const REMOTE_NOT_APPROVED = 'not_approved';
	public const REMOTE_MISSING      = 'missing';

	/**
	 * Returns the stored record.
	 *
	 * @return array{html:string,state:string,approved_at:?string,generated_at:?string,checked_at:int}|null
	 */
	public function get(): ?array {
		$raw = get_option( Options::DSE, null );
		if ( ! is_array( $raw ) || ! isset( $raw['checked_at'] ) || ! is_int( $raw['checked_at'] ) ) {
			return null;
		}

		$state = isset( $raw['state'] ) && is_string( $raw['state'] ) ? $raw['state'] : '';

		return array(
			'html'         => isset( $raw['html'] ) && is_string( $raw['html'] ) ? $raw['html'] : '',
			'state'        => $state,
			'approved_at'  => isset( $raw['approved_at'] ) && is_string( $raw['approved_at'] ) ? $raw['approved_at'] : null,
			'generated_at' => isset( $raw['generated_at'] ) && is_string( $raw['generated_at'] ) ? $raw['generated_at'] : null,
			'checked_at'   => $raw['checked_at'],
		);
	}

	/**
	 * Whether a displayable copy exists.
	 *
	 * @return bool
	 */
	public function has_copy(): bool {
		$record = $this->get();

		return null !== $record && '' !== $record['html'];
	}

	/**
	 * Whether the last check is older than the maximum age.
	 *
	 * @param array{checked_at:int}|null $record Stored record.
	 * @param int                        $now    Current time.
	 * @return bool
	 */
	public function is_stale( ?array $record, int $now ): bool {
		return null === $record || ( $now - $record['checked_at'] ) >= self::MAX_AGE;
	}

	/**
	 * Applies a validated service response.
	 *
	 * @param array<string,mixed> $dse Output of Api\Schema::dse().
	 * @param int                 $now Current time.
	 * @return bool False if an active response had to be rejected because filtering left nothing.
	 */
	public function apply( array $dse, int $now ): bool {
		$current = $this->get();
		$remote  = (string) $dse['state'];

		$html         = null !== $current ? $current['html'] : '';
		$state        = null !== $current ? $current['state'] : '';
		$approved_at  = null !== $current ? $current['approved_at'] : null;
		$generated_at = isset( $dse['generated_at'] ) && is_string( $dse['generated_at'] ) ? $dse['generated_at'] : null;

		if ( self::REMOTE_ACTIVE === $remote || self::REMOTE_LAPSED === $remote ) {
			$filtered = DseRenderer::sanitize( (string) $dse['html'] );
			if ( '' === trim( wp_strip_all_tags( $filtered ) ) ) {
				return false;
			}
			$html        = $filtered;
			$state       = $remote;
			$approved_at = isset( $dse['approved_at'] ) && is_string( $dse['approved_at'] ) ? $dse['approved_at'] : null;
		} elseif ( self::REMOTE_LAPSED === $state ) {
			// The stored text is the service's notice, not an approved policy. Nothing to keep.
			$html        = '';
			$state       = '';
			$approved_at = null;
		}

		update_option(
			Options::DSE,
			array(
				'html'         => $html,
				'state'        => $state,
				'remote_state' => $remote,
				'approved_at'  => $approved_at,
				'generated_at' => $generated_at,
				'checked_at'   => $now,
			),
			false
		);

		return true;
	}

	/**
	 * State reported by the service on the last check.
	 *
	 * @return string One of the REMOTE_ constants, or an empty string.
	 */
	public function remote_state(): string {
		$raw = get_option( Options::DSE, null );

		return is_array( $raw ) && isset( $raw['remote_state'] ) && is_string( $raw['remote_state'] ) ? $raw['remote_state'] : '';
	}

	/**
	 * Removes the copy.
	 *
	 * @return void
	 */
	public function delete(): void {
		delete_option( Options::DSE );
	}
}
