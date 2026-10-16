<?php
/**
 * Status and free scan sections of the plugin page.
 *
 * @package Complianty\Connector
 */

namespace Complianty\Connector\Admin;

use Complianty\Connector\Api\Client;
use Complianty\Connector\Api\Result;
use Complianty\Connector\Storage\Cache;
use Complianty\Connector\Storage\Options;
use Complianty\Connector\Support\Areas;
use Complianty\Connector\Support\Links;
use Complianty\Connector\Support\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the status card for connected sites and the free scan card for sites without token.
 *
 * Everything that comes from the service is plain text and is escaped on output.
 */
final class StatusPage {

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Cache.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * Status loaded during this request, so the page asks the service once.
	 *
	 * @var array{data:?array<string,mixed>,fetched_at:int,error:?Result}|null
	 */
	private $loaded = null;

	/**
	 * Constructor.
	 *
	 * @param Client $client API client.
	 * @param Cache  $cache  Cache.
	 */
	public function __construct( Client $client, Cache $cache ) {
		$this->client = $client;
		$this->cache  = $cache;
	}

	/**
	 * Returns the status to show: cached if fresh, otherwise fetched.
	 *
	 * @param bool $force   Fetch even if the cache is fresh (user initiated).
	 * @param int  $timeout Timeout in seconds for the request.
	 * @return array{data:?array<string,mixed>,fetched_at:int,error:?Result}
	 */
	public function load_status( bool $force = false, int $timeout = Client::TIMEOUT ): array {
		if ( ! $force && null !== $this->loaded ) {
			return $this->loaded;
		}

		$this->loaded = $this->fetch_status( $force, $timeout );

		return $this->loaded;
	}

	/**
	 * Reads the cache or asks the service.
	 *
	 * @param bool $force   Fetch even if the cache is fresh.
	 * @param int  $timeout Timeout in seconds for the request.
	 * @return array{data:?array<string,mixed>,fetched_at:int,error:?Result}
	 */
	private function fetch_status( bool $force, int $timeout ): array {
		$cached = $this->cache->get_status();

		if ( ! $force && $this->cache->is_status_fresh( $cached ) ) {
			return array(
				'data'       => $cached['data'] ?? null,
				'fetched_at' => $cached['fetched_at'] ?? 0,
				'error'      => null,
			);
		}

		$result = $this->client->status( $force, $timeout );
		if ( $result->is_ok() ) {
			$this->cache->set_status( $result->data() );
			$cached = $this->cache->get_status();

			return array(
				'data'       => $cached['data'] ?? null,
				'fetched_at' => $cached['fetched_at'] ?? 0,
				'error'      => null,
			);
		}

		// Keep showing the last known data next to the error.
		return array(
			'data'       => $cached['data'] ?? null,
			'fetched_at' => $cached['fetched_at'] ?? 0,
			'error'      => $result,
		);
	}

	/**
	 * Renders the status card.
	 *
	 * @return void
	 */
	public function render_status(): void {
		$loaded = $this->load_status();
		$data   = $loaded['data'];
		$error  = $loaded['error'];

		echo '<div class="complianty-card">';
		echo '<h2>' . esc_html__( 'Status of your website', 'complianty-connector' ) . '</h2>';

		if ( null !== $error ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( Messages::for_result( $error ) ) );
		}

		if ( null === $data ) {
			echo '<p>' . esc_html__( 'No status is available yet. Press Refresh to load it.', 'complianty-connector' ) . '</p>';
			$this->render_actions( null );
			echo '</div>';
			return;
		}

		$this->render_summary( $data );
		$this->render_todos( $data );

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: date and time of the last update */
				__( 'Updated: %s', 'complianty-connector' ),
				$this->format_time( gmdate( 'c', $loaded['fetched_at'] ) )
			)
		) . '</p>';

		$this->render_actions( $data );
		echo '</div>';
	}

	/**
	 * Traffic light, counters and scan time.
	 *
	 * @param array<string,mixed> $data Validated status.
	 * @return void
	 */
	public function render_summary( array $data ): void {
		$labels = array(
			'ok'       => __( 'No open findings', 'complianty-connector' ),
			'gaps'     => __( 'Gaps found', 'complianty-connector' ),
			'critical' => __( 'Critical findings', 'complianty-connector' ),
			'pending'  => __( 'Not checked yet', 'complianty-connector' ),
		);
		$state  = (string) $data['status'];
		$label  = $labels[ $state ] ?? $labels['pending'];
		$class  = isset( $labels[ $state ] ) ? $state : 'pending';

		$last = isset( $data['last_scanned_at'] ) && is_string( $data['last_scanned_at'] )
			? $this->format_time( $data['last_scanned_at'] )
			: __( 'No scan yet', 'complianty-connector' );

		$scan_labels = array(
			'idle'    => __( 'Ready', 'complianty-connector' ),
			'queued'  => __( 'Waiting to start', 'complianty-connector' ),
			'running' => __( 'Scan running', 'complianty-connector' ),
		);

		printf(
			'<div class="complianty-summary complianty-summary--%1$s"><div class="complianty-light"><span class="complianty-light__dot" aria-hidden="true"></span><strong class="complianty-light__label">%2$s</strong></div>',
			esc_attr( $class ),
			esc_html( $label )
		);

		echo '<div class="complianty-pills">';
		printf(
			'<span class="complianty-pill complianty-pill--critical">%s</span>',
			esc_html(
				sprintf(
					/* translators: %d: number of open critical findings */
					_n( '%d critical', '%d critical', (int) $data['open_critical'], 'complianty-connector' ),
					(int) $data['open_critical']
				)
			)
		);
		printf(
			'<span class="complianty-pill complianty-pill--important">%s</span>',
			esc_html(
				sprintf(
					/* translators: %d: number of open important findings */
					_n( '%d important', '%d important', (int) $data['open_important'], 'complianty-connector' ),
					(int) $data['open_important']
				)
			)
		);
		echo '</div>';

		printf(
			'<p class="complianty-meta">%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: date and time of the last scan, 2: scan state, e.g. "Ready" */
					__( 'Last scan: %1$s. Scan state: %2$s.', 'complianty-connector' ),
					$last,
					$scan_labels[ (string) $data['scan_state'] ] ?? $scan_labels['idle']
				)
			)
		);
	}

	/**
	 * Open findings as plain text, grouped by area.
	 *
	 * @param array<string,mixed> $data  Validated status.
	 * @param int                 $limit   Maximum number of findings to show, 0 for all.
	 * @param bool                $grouped Group by area. Without grouping the findings are one plain list.
	 * @return void
	 */
	public function render_todos( array $data, int $limit = 0, bool $grouped = true ): void {
		$todos = isset( $data['todos'] ) && is_array( $data['todos'] ) ? $data['todos'] : array();
		if ( $limit > 0 ) {
			$todos = array_slice( $todos, 0, $limit );
		}
		if ( array() === $todos ) {
			return;
		}

		$priorities = array(
			'critical'  => __( 'Critical', 'complianty-connector' ),
			'important' => __( 'Important', 'complianty-connector' ),
			'info'      => __( 'Note', 'complianty-connector' ),
		);

		$groups = array();
		foreach ( $todos as $todo ) {
			if ( is_array( $todo ) ) {
				$groups[ $grouped ? Areas::normalize( $todo['area'] ?? null ) : 'all' ][] = $todo;
			}
		}

		if ( $grouped ) {
			echo '<h3>' . esc_html__( 'Most important open findings', 'complianty-connector' ) . '</h3>';
		}
		$labels = $grouped ? Areas::labels() : array( 'all' => '' );
		foreach ( $labels as $key => $area_label ) {
			if ( ! isset( $groups[ $key ] ) ) {
				continue;
			}

			if ( $grouped ) {
				printf(
					'<h4 class="complianty-area">%1$s <span class="complianty-area__count">%2$d</span></h4>',
					esc_html( $area_label ),
					count( $groups[ $key ] )
				);
			}
			echo '<ul class="complianty-todos">';
			foreach ( $groups[ $key ] as $todo ) {
				$priority = (string) ( $todo['priority'] ?? '' );
				$priority = isset( $priorities[ $priority ] ) ? $priority : 'info';
				printf(
					'<li class="complianty-todo complianty-todo--%1$s"><span class="complianty-pill complianty-pill--%1$s">%2$s</span><strong class="complianty-todo__title">%3$s</strong><span class="complianty-todo__text">%4$s</span></li>',
					esc_attr( $priority ),
					esc_html( $priorities[ $priority ] ),
					esc_html( (string) ( $todo['title'] ?? '' ) ),
					esc_html( (string) ( $todo['text'] ?? '' ) )
				);
			}
			echo '</ul>';
		}
	}

	/**
	 * Refresh, scan and dashboard controls.
	 *
	 * @param array<string,mixed>|null $data Validated status, if any.
	 * @return void
	 */
	private function render_actions( ?array $data ): void {
		echo '<div class="complianty-actions">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="complianty-inline-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( SettingsPage::ACTION_REFRESH_STATUS ) . '">';
		wp_nonce_field( SettingsPage::ACTION_REFRESH_STATUS );
		submit_button( __( 'Refresh', 'complianty-connector' ), 'secondary', 'submit', false );
		echo '</form> ';

		if ( null !== $data && true === ( $data['scan_trigger_allowed'] ?? false ) ) {
			$busy = in_array( $data['scan_state'] ?? 'idle', array( 'queued', 'running' ), true );
			printf(
				'<button type="button" class="button button-primary" data-complianty-scan-start%s>%s</button> ',
				$busy ? ' disabled' : '',
				esc_html__( 'Scan now', 'complianty-connector' )
			);
		}

		printf(
			'<a class="button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( Links::dashboard() ),
			esc_html__( 'Open in the Complianty dashboard', 'complianty-connector' )
		);
		echo '</div>';

		$busy = null !== $data && in_array( $data['scan_state'] ?? 'idle', array( 'queued', 'running' ), true );
		printf(
			'<p class="complianty-progress" data-complianty-scan-progress role="status" aria-live="polite"%s></p>',
			$busy ? ' data-complianty-resume="1"' : ''
		);
	}

	/**
	 * Renders the free scan card for sites without a token.
	 *
	 * @param array<string,mixed>|null $job Stored free scan job, if any.
	 * @return void
	 */
	public function render_free_scan( ?array $job ): void {
		echo '<div class="complianty-card">';
		echo '<h2>' . esc_html__( 'Check this website for free', 'complianty-connector' ) . '</h2>';

		if ( null !== $job && 'done' === ( $job['status'] ?? '' ) && isset( $job['result'] ) && is_array( $job['result'] ) ) {
			$this->render_free_result( $job['result'] );
			echo '</div>';
			return;
		}

		$running = null !== $job && in_array( $job['status'] ?? '', array( 'queued', 'running' ), true );

		if ( null !== $job && true === ( $job['failed'] ?? false ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The check could not be completed. Please try again later.', 'complianty-connector' ) . '</p></div>';
		}

		if ( ! $running ) {
			echo '<p>' . esc_html__( 'You can run a short check of this website without an account. It lists the cookie banner and the tools that were found.', 'complianty-connector' ) . '</p>';
			echo '<p><strong>' . esc_html__( 'What is sent:', 'complianty-connector' ) . '</strong> ';
			echo esc_html(
				sprintf(
					/* translators: %s: address of this website */
					__( 'Only the address of this website (%s) is sent to the Complianty service, and only when you press the button. No account, no e-mail address and no other data is sent.', 'complianty-connector' ),
					home_url()
				)
			);
			echo ' ';
			printf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( Links::privacy() ),
				esc_html__( 'Privacy policy of Complianty', 'complianty-connector' )
			);
			echo '</p>';
			printf(
				'<p><button type="button" class="button button-primary" data-complianty-free-start>%s</button></p>',
				esc_html__( 'Check this website now', 'complianty-connector' )
			);
		}

		printf(
			'<p class="complianty-progress" data-complianty-free-progress role="status" aria-live="polite"%s>%s</p>',
			$running ? ' data-complianty-resume="1"' : '',
			$running ? esc_html__( 'The check is running. This can take a minute.', 'complianty-connector' ) : ''
		);
		echo '</div>';
	}

	/**
	 * Short free scan result. All wording is a finding, not a legal assessment.
	 *
	 * @param array<string,mixed> $result Validated result.
	 * @return void
	 */
	private function render_free_result( array $result ): void {
		echo '<h3>' . esc_html__( 'Result of the short check', 'complianty-connector' ) . '</h3><ul class="complianty-counts">';

		if ( true === ( $result['cmp_detected'] ?? false ) ) {
			$name = isset( $result['cmp_name'] ) && is_string( $result['cmp_name'] ) && '' !== $result['cmp_name'] ? $result['cmp_name'] : '';
			printf(
				'<li>%s</li>',
				esc_html(
					'' !== $name
						/* translators: %s: name of the detected cookie banner */
						? sprintf( __( 'A cookie banner was detected: %s', 'complianty-connector' ), $name )
						: __( 'A cookie banner was detected.', 'complianty-connector' )
				)
			);
		} else {
			printf( '<li>%s</li>', esc_html__( 'No cookie banner was detected.', 'complianty-connector' ) );
		}

		$tools_found = (int) ( $result['total_tools_found'] ?? 0 );
		$before      = (int) ( $result['total_violations'] ?? 0 );

		printf(
			'<li>%s</li>',
			esc_html(
				sprintf(
					/* translators: %d: number of detected tools */
					_n( '%d tool was detected.', '%d tools were detected.', $tools_found, 'complianty-connector' ),
					$tools_found
				)
			)
		);
		printf(
			'<li>%s</li>',
			esc_html(
				sprintf(
					/* translators: %d: number of tools that were active before consent */
					_n( '%d tool was active before consent was given.', '%d tools were active before consent was given.', $before, 'complianty-connector' ),
					$before
				)
			)
		);
		echo '</ul>';

		$tools = isset( $result['preview_tools'] ) && is_array( $result['preview_tools'] ) ? $result['preview_tools'] : array();
		if ( array() !== $tools ) {
			echo '<ul class="complianty-todos">';
			foreach ( $tools as $tool ) {
				if ( ! is_array( $tool ) ) {
					continue;
				}
				printf(
					'<li>%1$s (%2$s)%3$s</li>',
					esc_html( (string) ( $tool['name'] ?? '' ) ),
					esc_html( (string) ( $tool['category'] ?? '' ) ),
					true === ( $tool['pre_consent'] ?? false ) ? ' ' . esc_html__( 'before consent', 'complianty-connector' ) : ''
				);
			}
			echo '</ul>';
		}

		echo '<p class="description">' . esc_html__( 'This is a short automatic check. It lists what was observed and is not a legal assessment.', 'complianty-connector' ) . '</p>';

		printf(
			'<p><a class="button button-primary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( Links::register() ),
			esc_html__( 'Get the full report in Complianty', 'complianty-connector' )
		);
	}

	/**
	 * Reads the stored free scan job.
	 *
	 * @return array<string,mixed>|null
	 */
	public function free_scan_job(): ?array {
		$job = get_transient( Options::T_FREE_SCAN );

		return is_array( $job ) ? $job : null;
	}

	/**
	 * Formats an ISO 8601 time in the site's timezone and format.
	 *
	 * @param string $iso ISO 8601 time.
	 * @return string
	 */
	private function format_time( string $iso ): string {
		$timestamp = strtotime( $iso );
		if ( false === $timestamp ) {
			return '';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
