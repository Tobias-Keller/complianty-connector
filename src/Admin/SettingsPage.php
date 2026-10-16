<?php
/**
 * Settings page under Settings > Complianty.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Admin;

use Complianty\Connector\Api\Client;
use Complianty\Connector\Frontend\DseShortcode;
use Complianty\Connector\Frontend\DseSync;
use Complianty\Connector\Storage\Cache;
use Complianty\Connector\Storage\DseStore;
use Complianty\Connector\Storage\Options;
use Complianty\Connector\Storage\Settings;
use Complianty\Connector\Storage\TokenStore;
use Complianty\Connector\Support\Links;
use Complianty\Connector\Support\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Connection, status, privacy policy and external services in one page.
 *
 * Capability: manage_options everywhere. Forms that change data carry a nonce.
 * The token field uses the Settings API; the other actions use admin-post.php.
 */
final class SettingsPage {

	public const SLUG                  = 'complianty-connector';
	public const CAPABILITY            = 'manage_options';
	public const OPTION_GROUP          = 'complianty_connector';
	public const DISPLAY_GROUP         = 'complianty_connector_display';
	public const ACTION_DISCONNECT     = 'complianty_connector_disconnect';
	public const ACTION_REFRESH_STATUS = 'complianty_connector_refresh_status';
	public const ACTION_REFRESH_DSE    = 'complianty_connector_refresh_dse';

	/**
	 * Token storage.
	 *
	 * @var TokenStore
	 */
	private $tokens;

	/**
	 * Cache.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * Privacy policy copy.
	 *
	 * @var DseStore
	 */
	private $dse_store;

	/**
	 * Privacy policy sync.
	 *
	 * @var DseSync
	 */
	private $dse_sync;

	/**
	 * Status and free scan sections.
	 *
	 * @var StatusPage
	 */
	private $status_page;

	/**
	 * Notices.
	 *
	 * @var Notices
	 */
	private $notices;

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param TokenStore $tokens      Token storage.
	 * @param Cache      $cache       Cache.
	 * @param DseStore   $dse_store   Privacy policy copy.
	 * @param DseSync    $dse_sync    Privacy policy sync.
	 * @param StatusPage $status_page Status and free scan sections.
	 * @param Notices    $notices     Notices.
	 * @param Client     $client      API client.
	 * @param Settings   $settings    Settings.
	 */
	public function __construct( TokenStore $tokens, Cache $cache, DseStore $dse_store, DseSync $dse_sync, StatusPage $status_page, Notices $notices, Client $client, Settings $settings ) {
		$this->settings    = $settings;
		$this->tokens      = $tokens;
		$this->cache       = $cache;
		$this->dse_store   = $dse_store;
		$this->dse_sync    = $dse_sync;
		$this->status_page = $status_page;
		$this->notices     = $notices;
		$this->client      = $client;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( COMPLIANTY_CONNECTOR_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_' . self::ACTION_REFRESH_STATUS, array( $this, 'handle_refresh_status' ) );
		add_action( 'admin_post_' . self::ACTION_REFRESH_DSE, array( $this, 'handle_refresh_dse' ) );
	}

	/**
	 * Adds a Settings link next to "Deactivate" on the plugins screen.
	 *
	 * @param mixed $links Existing links.
	 * @return array<int|string,string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();

		if ( current_user_can( self::CAPABILITY ) ) {
			array_unshift(
				$links,
				sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'complianty-connector' ) )
			);
		}

		return $links;
	}

	/**
	 * Adds the page below Settings.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$suffix = add_options_page(
			__( 'Complianty Connector', 'complianty-connector' ),
			__( 'Complianty', 'complianty-connector' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = false === $suffix ? '' : $suffix;
	}

	/**
	 * Registers the token setting on init, so every write of the option goes through the sanitize callback.
	 *
	 * The option holds the encrypted token. The sanitize callback accepts a pasted token
	 * and encrypts it, or passes through an already encrypted value written by TokenStore.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Options::TOKEN,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_token' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::DISPLAY_GROUP,
			Options::SETTINGS,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize callback for the token option.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Value to store: the encrypted token, or the previous value.
	 */
	public function sanitize_token( $value ): string {
		$current = get_option( Options::TOKEN, '' );
		$current = is_string( $current ) ? $current : '';
		$input   = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $input ) {
			return $current;
		}

		if ( $this->tokens->is_valid_format( $input ) ) {
			if ( ! $this->current_user_may_manage() ) {
				return $current;
			}

			$had_token = $this->tokens->has_token();
			if ( ! $this->tokens->set( $input ) ) {
				add_settings_error( self::OPTION_GROUP, 'complianty_token_not_saved', $this->token_error_text(), 'error' );
				return $current;
			}

			$this->cache->clear_connection_data();
			if ( ! $had_token ) {
				// A fresh connection never inherits a leftover copy.
				$this->dse_store->delete();
			}
			add_settings_error( self::OPTION_GROUP, 'complianty_token_saved', __( 'The token was saved. The first status check runs when you open this page.', 'complianty-connector' ), 'success' );

			$stored = get_option( Options::TOKEN, '' );
			return is_string( $stored ) ? $stored : '';
		}

		// Not a raw token. Accept only a value that decrypts to a valid token (internal write by TokenStore).
		if ( $this->tokens->is_encrypted_token( $input ) ) {
			return $input;
		}

		add_settings_error( self::OPTION_GROUP, 'complianty_token_invalid', __( 'This is not a valid connection token. Copy the token from your Complianty account without spaces.', 'complianty-connector' ), 'error' );

		return $current;
	}

	/**
	 * Error text when saving fails.
	 *
	 * @return string
	 */
	private function token_error_text(): string {
		if ( $this->tokens->is_constant_defined() ) {
			return __( 'The token is set in wp-config.php. Remove COMPLIANTY_API_TOKEN there to change it here.', 'complianty-connector' );
		}

		return __( 'The token could not be stored securely because PHP libsodium is not available. Set COMPLIANTY_API_TOKEN in wp-config.php instead.', 'complianty-connector' );
	}

	/**
	 * Whether the current user may manage the plugin.
	 *
	 * @return bool
	 */
	private function current_user_may_manage(): bool {
		return current_user_can( self::CAPABILITY ) || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Loads CSS and JavaScript on the plugin page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'complianty-connector-admin', plugins_url( 'assets/admin.css', COMPLIANTY_CONNECTOR_FILE ), array(), COMPLIANTY_CONNECTOR_VERSION );
		wp_enqueue_script( 'complianty-connector-admin', plugins_url( 'assets/admin.js', COMPLIANTY_CONNECTOR_FILE ), array( 'wp-i18n' ), COMPLIANTY_CONNECTOR_VERSION, true );
		wp_set_script_translations( 'complianty-connector-admin', 'complianty-connector', dirname( COMPLIANTY_CONNECTOR_FILE ) . '/languages' );
		wp_add_inline_script(
			'complianty-connector-admin',
			'window.compliantyConnector = ' . wp_json_encode(
				array(
					'restUrl' => esc_url_raw( rest_url( 'complianty-connector/v1/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'complianty-connector' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap complianty-wrap">';
		echo '<h1>' . esc_html__( 'Complianty Connector', 'complianty-connector' ) . '</h1>';
		// Core prints the settings errors of pages under Settings itself, so they are not printed again here.
		$this->notices->render();

		echo '<noscript><div class="notice notice-warning inline"><p>' . esc_html__( 'JavaScript is needed for scan progress. The other functions work without it.', 'complianty-connector' ) . '</p></div></noscript>';

		if ( $this->tokens->has_token() ) {
			$token = $this->tokens->get();
			if ( null !== $token ) {
				// Both calls update what the cards below show. Each asks the service only if its data is stale.
				$this->status_page->load_status();
				if ( $this->dse_store->is_stale( $this->dse_store->get(), time() ) ) {
					$this->dse_sync->refresh( false );
				}
				$this->status_page->render_status();
				$this->render_dse();
			}
			$this->render_connection( $token );
		} else {
			$this->render_connect_form();
			$this->status_page->render_free_scan( $this->status_page->free_scan_job() );
		}

		$this->render_external_services();
		echo '</div>';
	}

	/**
	 * Form for pasting the token.
	 *
	 * @return void
	 */
	private function render_connect_form(): void {
		echo '<div class="complianty-card">';
		echo '<h2>' . esc_html__( 'Connect', 'complianty-connector' ) . '</h2>';
		echo '<p>' . esc_html__( 'Create a connection token for this website in your Complianty account and paste it here. Until you do, this plugin does not contact any external service.', 'complianty-connector' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::OPTION_GROUP );
		echo '<p><label for="complianty-token"><strong>' . esc_html__( 'Connection token', 'complianty-connector' ) . '</strong></label><br>';
		echo '<input type="password" id="complianty-token" name="' . esc_attr( Options::TOKEN ) . '" class="regular-text code" value="" autocomplete="off" spellcheck="false" placeholder="clp_site_..."></p>';
		submit_button( __( 'Connect', 'complianty-connector' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<p class="description">' . esc_html__( 'Alternative: define the token in wp-config.php. Then nothing is stored in the database.', 'complianty-connector' ) . '</p>';
		echo '<pre class="complianty-code"><code>' . esc_html( "define( 'COMPLIANTY_API_TOKEN', 'clp_site_...' );" ) . '</code></pre>';
		printf(
			'<p>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_html__( 'You do not have an account yet?', 'complianty-connector' ),
			esc_url( Links::register() ),
			esc_html__( 'Create one at Complianty', 'complianty-connector' )
		);
		echo '</div>';
	}

	/**
	 * Connection state and disconnect button.
	 *
	 * @param string|null $token Plaintext token, null if it cannot be read.
	 * @return void
	 */
	private function render_connection( ?string $token ): void {
		$source = $this->tokens->source();
		$state  = $this->cache->get_connection_state();

		echo '<div class="complianty-card">';
		echo '<h2>' . esc_html__( 'Connection', 'complianty-connector' ) . '</h2>';

		if ( null === $token ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The token cannot be read. It is either invalid or, if it is stored in the database, the WordPress security keys changed. Disconnect and connect again with a new token.', 'complianty-connector' ) . '</p></div>';
		} else {
			printf(
				'<p>%s <code>%s</code></p>',
				esc_html__( 'Token:', 'complianty-connector' ),
				esc_html( $this->tokens->mask( $token ) )
			);
			printf(
				'<p>%s %s</p>',
				esc_html__( 'State:', 'complianty-connector' ),
				esc_html( Messages::connection_state( null !== $state ? $state['state'] : '' ) )
			);
		}

		if ( 'constant' === $source ) {
			echo '<p>' . esc_html__( 'The token is provided by wp-config.php. To disconnect, remove COMPLIANTY_API_TOKEN there.', 'complianty-connector' ) . '</p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DISCONNECT ) . '">';
			wp_nonce_field( self::ACTION_DISCONNECT );
			submit_button( __( 'Disconnect', 'complianty-connector' ), 'secondary', 'submit', false );
			echo '</form>';
			echo '<p class="description">' . esc_html__( 'Disconnecting deletes the token, the cached status and the stored privacy policy copy from this site.', 'complianty-connector' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Privacy policy section.
	 *
	 * @return void
	 */
	private function render_dse(): void {
		$record = $this->dse_store->get();
		$remote = $this->dse_store->remote_state();

		echo '<div class="complianty-card">';
		echo '<h2>' . esc_html__( 'Privacy policy', 'complianty-connector' ) . '</h2>';

		if ( DseStore::REMOTE_NOT_APPROVED === $remote ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A new version is waiting for approval in Complianty. Until then the last approved version stays on your website.', 'complianty-connector' ) . '</p></div>';
		} elseif ( DseStore::REMOTE_MISSING === $remote ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No privacy policy was found in your Complianty account.', 'complianty-connector' ) . '</p></div>';
		} elseif ( DseStore::REMOTE_LAPSED === $remote ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The Complianty subscription has ended. The service now provides a notice instead of the policy, and your website shows that notice.', 'complianty-connector' ) . '</p></div>';
		}

		if ( null !== $record && '' !== $record['html'] ) {
			if ( DseStore::REMOTE_LAPSED === $record['state'] ) {
				echo '<p>' . esc_html__( 'The stored copy is the notice of the service.', 'complianty-connector' ) . '</p>';
			} else {
				printf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: approval date */
							__( 'Approved version from: %s', 'complianty-connector' ),
							$this->format_time( $record['approved_at'] )
						)
					)
				);
			}
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: date and time of the last check */
						__( 'Last checked: %s', 'complianty-connector' ),
						$this->format_time( gmdate( 'c', $record['checked_at'] ) )
					)
				)
			);
		} else {
			echo '<p>' . esc_html__( 'There is no approved privacy policy on this site yet.', 'complianty-connector' ) . '</p>';
		}

		echo '<p>' . esc_html__( 'Output the privacy policy from your Complianty account with the shortcode or the block "Privacy policy (Complianty)".', 'complianty-connector' ) . '</p>';
		echo '<pre class="complianty-code"><code>[' . esc_html( DseShortcode::TAG ) . ']</code></pre>';

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::DISPLAY_GROUP );
		printf(
			'<p><label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s> %4$s</label></p>',
			esc_attr( Options::SETTINGS ),
			esc_attr( Settings::SHOW_CREDIT ),
			checked( $this->settings->show_credit(), true, false ),
			esc_html__( 'Show a link to Complianty below the privacy policy', 'complianty-connector' )
		);
		echo '<p class="description">' . esc_html__( 'Off by default. If you turn it on, a short link to complianty.de appears below the privacy policy on your website.', 'complianty-connector' ) . '</p>';
		submit_button( __( 'Save', 'complianty-connector' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REFRESH_DSE ) . '">';
		wp_nonce_field( self::ACTION_REFRESH_DSE );
		submit_button( __( 'Update privacy policy', 'complianty-connector' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * External services section.
	 *
	 * @return void
	 */
	private function render_external_services(): void {
		echo '<div class="complianty-card">';
		echo '<h2>' . esc_html__( 'External services', 'complianty-connector' ) . '</h2>';
		echo '<p>' . esc_html__( 'This plugin connects your server to the Complianty service at app.complianty.de.', 'complianty-connector' ) . '</p>';
		echo '<ul class="complianty-list">';
		echo '<li>' . esc_html__( 'Before you enter a token, nothing is sent. The only exception is the optional free check, which runs only after you press its button and sends the address of this website.', 'complianty-connector' ) . '</li>';
		echo '<li>' . esc_html__( 'With a token, your server sends the token and the plugin version. No website address, user name or WordPress version is sent.', 'complianty-connector' ) . '</li>';
		echo '<li>' . esc_html__( 'Requests happen when you open this page or the dashboard, press Refresh or Scan now, and in the background to keep the privacy policy copy current.', 'complianty-connector' ) . '</li>';
		echo '<li>' . esc_html__( 'Visitors of your website never contact Complianty. Your server fetches the data.', 'complianty-connector' ) . '</li>';
		echo '<li>' . esc_html__( 'The token is stored encrypted with libsodium, using a key derived from your WordPress security keys. This protects against leaks of the database alone, not against access to wp-config.php.', 'complianty-connector' ) . '</li>';
		echo '</ul>';
		printf(
			'<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a> | <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p>',
			esc_url( Links::privacy() ),
			esc_html__( 'Privacy policy', 'complianty-connector' ),
			esc_url( Links::terms() ),
			esc_html__( 'Terms of service', 'complianty-connector' )
		);
		echo '</div>';
	}

	/**
	 * Handler: disconnect.
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		$this->authorize( self::ACTION_DISCONNECT );

		$this->tokens->delete();
		$this->dse_store->delete();
		$this->cache->clear_connection_data();
		wp_clear_scheduled_hook( Options::EVENT_REFRESH_DSE );
		$this->notices->flash_success( 'disconnected' );

		$this->redirect_back();
	}

	/**
	 * Handler: refresh the status.
	 *
	 * @return void
	 */
	public function handle_refresh_status(): void {
		$this->authorize( self::ACTION_REFRESH_STATUS );

		$result = $this->client->status( true );
		if ( $result->is_ok() ) {
			$this->cache->set_status( $result->data() );
			$this->notices->flash_success( 'status_refreshed' );
		} else {
			$this->notices->flash_error( $result );
		}

		$this->redirect_back();
	}

	/**
	 * Handler: refresh the privacy policy.
	 *
	 * @return void
	 */
	public function handle_refresh_dse(): void {
		$this->authorize( self::ACTION_REFRESH_DSE );

		$result = $this->dse_sync->refresh( true );
		if ( $result->is_ok() ) {
			$this->notices->flash_success( 'dse_refreshed' );
		} else {
			$this->notices->flash_error( $result );
		}

		$this->redirect_back();
	}

	/**
	 * Requires a POST request, the capability and a valid nonce.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'complianty-connector' ), '', array( 'response' => 403 ) );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== strtoupper( $method ) ) {
			wp_die( esc_html__( 'Invalid request.', 'complianty-connector' ), '', array( 'response' => 405 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Redirects to the plugin page and stops.
	 *
	 * @return void
	 */
	private function redirect_back(): void {
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * URL of the plugin page.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return add_query_arg( 'page', self::SLUG, admin_url( 'options-general.php' ) );
	}

	/**
	 * Formats an ISO 8601 time in the site's timezone and format.
	 *
	 * @param string|null $iso ISO 8601 time.
	 * @return string
	 */
	private function format_time( ?string $iso ): string {
		$timestamp = null === $iso ? false : strtotime( $iso );
		if ( false === $timestamp ) {
			return __( 'unknown', 'complianty-connector' );
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
