<?php
/**
 * Admin notices on the plugin page.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Admin;

use Complianty\Connector\Api\Result;
use Complianty\Connector\Support\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * One-shot messages shown after an action. They appear only on the plugin's own page.
 *
 * A message is stored as a fixed key or as a result kind plus code, never as free text,
 * and is turned into text when it is shown.
 */
final class Notices {

	private const TTL = 120;

	/**
	 * Success keys and their texts are resolved in text().
	 */
	public const SUCCESS_KEYS = array( 'disconnected', 'status_refreshed', 'dse_refreshed', 'token_saved' );

	/**
	 * Stores a success message for the current user.
	 *
	 * @param string $key One of SUCCESS_KEYS.
	 * @return void
	 */
	public function flash_success( string $key ): void {
		if ( ! in_array( $key, self::SUCCESS_KEYS, true ) ) {
			return;
		}

		$this->store( array( 'success' => $key ) );
	}

	/**
	 * Stores an error message for the current user.
	 *
	 * @param Result $result  Failed result.
	 * @param string $context Message context.
	 * @return void
	 */
	public function flash_error( Result $result, string $context = Messages::CONTEXT_GENERIC ): void {
		$this->store(
			array(
				'kind'    => $result->kind(),
				'code'    => $result->code(),
				'retry'   => $result->retry_after(),
				'context' => $context,
			)
		);
	}

	/**
	 * Prints and clears the stored message.
	 *
	 * @return void
	 */
	public function render(): void {
		$flash = get_transient( $this->key() );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $this->key() );

		if ( isset( $flash['success'] ) && is_string( $flash['success'] ) ) {
			$text = $this->success_text( $flash['success'] );
			if ( '' !== $text ) {
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $text ) );
			}
			return;
		}

		if ( isset( $flash['kind'], $flash['code'], $flash['retry'], $flash['context'] ) && is_string( $flash['kind'] ) && is_string( $flash['code'] ) && is_int( $flash['retry'] ) && is_string( $flash['context'] ) ) {
			$result = Result::failure( $flash['kind'], $flash['code'], $flash['retry'] );
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( Messages::for_result( $result, $flash['context'] ) ) );
		}
	}

	/**
	 * Text for a success key.
	 *
	 * @param string $key Success key.
	 * @return string
	 */
	private function success_text( string $key ): string {
		switch ( $key ) {
			case 'disconnected':
				return __( 'The connection was removed. The token and the cached data were deleted from this site.', 'complianty-connector' );
			case 'status_refreshed':
				return __( 'The status was updated.', 'complianty-connector' );
			case 'dse_refreshed':
				return __( 'The privacy policy was checked.', 'complianty-connector' );
			case 'token_saved':
				return __( 'The token was saved.', 'complianty-connector' );
			default:
				return '';
		}
	}

	/**
	 * Stores a message.
	 *
	 * @param array<string,mixed> $value Message data.
	 * @return void
	 */
	private function store( array $value ): void {
		set_transient( $this->key(), $value, self::TTL );
	}

	/**
	 * Transient key, per user.
	 *
	 * @return string
	 */
	private function key(): string {
		return 'complianty_flash_' . get_current_user_id();
	}
}
