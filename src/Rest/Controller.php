<?php
/**
 * REST controller base.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Rest;

use Complianty\Connector\Api\Result;
use Complianty\Connector\Support\Messages;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Shared rules for the plugin's REST routes, which only serve the admin screen.
 *
 * Authentication is the WordPress cookie plus the X-WP-Nonce header, checked by core.
 * On top of that every route requires manage_options.
 */
abstract class Controller {

	public const REST_NAMESPACE = 'complianty-connector/v1';

	/**
	 * Registers the routes on rest_api_init.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes of this controller.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Permission callback shared by all routes.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Converts a failed result into a REST error with a plain text message.
	 *
	 * @param Result $result  Failed result.
	 * @param string $context Message context.
	 * @return WP_Error
	 */
	protected function error( Result $result, string $context = Messages::CONTEXT_GENERIC ): WP_Error {
		switch ( $result->kind() ) {
			case Result::FORBIDDEN:
				$status = 403;
				break;
			case Result::COOLDOWN:
			case Result::BUDGET:
			case Result::RATE_LIMITED:
				$status = 429;
				break;
			case Result::NO_TOKEN:
				$status = 400;
				break;
			case Result::UNAUTHORIZED:
			case Result::INVALID:
			case Result::REJECTED:
				$status = 502;
				break;
			default:
				$status = 503;
		}

		return new WP_Error(
			'complianty_' . $result->kind(),
			Messages::for_result( $result, $context ),
			array(
				'status'      => $status,
				'retry_after' => $result->retry_after(),
			)
		);
	}
}
