<?php
/**
 * Suggested text for the WordPress privacy policy page.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the suggested privacy policy text (Settings > Privacy).
 */
final class Privacy {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_content' ) );
	}

	/**
	 * Adds the suggested text.
	 *
	 * @return void
	 */
	public function add_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content( __( 'Complianty Connector', 'complianty-connector' ), $this->text() );
	}

	/**
	 * Suggested text. It describes the data flow of the plugin and nothing else.
	 *
	 * @return string
	 */
	public function text(): string {
		$paragraphs = array(
			__( 'Suggested text. Review and adapt it to your situation.', 'complianty-connector' ),
			sprintf(
				/* translators: %s: link to the privacy policy of Complianty */
				__( 'If an administrator of this website connects the Complianty Connector plugin to a Complianty account, the server of this website contacts the service Complianty (app.complianty.de) to retrieve the status of the website check and the privacy policy that was created and approved in that account. Each request contains a connection token and the plugin version. Personal data of visitors of this website is not sent. Information about the provider is available in its privacy policy: %s', 'complianty-connector' ),
				Links::privacy()
			),
			__( 'Visitors of this website do not contact Complianty through this plugin. If the privacy policy of your Complianty account is shown on this website, it is stored on this website and delivered from there.', 'complianty-connector' ),
			__( 'Before a token is entered, the plugin makes no requests. The optional free check of the website is only started by an administrator and sends the address of this website to Complianty.', 'complianty-connector' ),
		);

		return wp_kses_post( wpautop( implode( "\n\n", $paragraphs ) ) );
	}
}
