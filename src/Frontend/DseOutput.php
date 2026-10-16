<?php
/**
 * Privacy policy output.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Frontend;

use Complianty\Connector\Storage\DseStore;
use Complianty\Connector\Storage\Settings;
use Complianty\Connector\Storage\TokenStore;
use Complianty\Connector\Support\Links;

defined( 'ABSPATH' ) || exit;

/**
 * Shared output for the shortcode and the block.
 *
 * Visitors never contact the service. Output comes from the filtered local copy.
 * With a copy present there is never a synchronous remote call: a stale copy is
 * shown and refreshed in the background.
 */
final class DseOutput {

	/**
	 * Local copy.
	 *
	 * @var DseStore
	 */
	private $store;

	/**
	 * Synchronisation.
	 *
	 * @var DseSync
	 */
	private $sync;

	/**
	 * Token storage.
	 *
	 * @var TokenStore
	 */
	private $tokens;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param DseStore   $store    Local copy.
	 * @param DseSync    $sync     Synchronisation.
	 * @param TokenStore $tokens   Token storage.
	 * @param Settings   $settings Settings.
	 */
	public function __construct( DseStore $store, DseSync $sync, TokenStore $tokens, Settings $settings ) {
		$this->store    = $store;
		$this->sync     = $sync;
		$this->tokens   = $tokens;
		$this->settings = $settings;
	}

	/**
	 * Renders the policy, or an empty string if there is no approved copy.
	 *
	 * @return string HTML, already filtered.
	 */
	public function render(): string {
		$record = $this->store->get();

		if ( $this->tokens->has_token() ) {
			if ( null === $record ) {
				$this->sync->first_fetch();
				$record = $this->store->get();
			} elseif ( $this->store->is_stale( $record, time() ) ) {
				$this->sync->schedule();
			}
		}

		if ( null === $record || '' === $record['html'] ) {
			return '';
		}

		// Filtered when stored and again on output, so a tampered database value cannot inject markup.
		$credit = '';
		if ( $this->settings->show_credit() ) {
			// Opt-in only. Our own markup, outside the filtered policy text.
			$credit = sprintf(
				'<p class="complianty-dse__credit"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
				esc_url( Links::home() ),
				esc_html__( 'Privacy policy provided with Complianty', 'complianty-connector' )
			);
		}

		return '<div class="complianty-dse">' . DseRenderer::sanitize( $record['html'] ) . $credit . '</div>';
	}
}
