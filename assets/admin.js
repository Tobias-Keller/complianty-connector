/**
 * Complianty Connector admin script.
 *
 * Plain JavaScript, no jQuery, no external requests. Talks to this site's own REST routes only.
 * Text from the server is written with textContent, never as HTML.
 */
( function () {
	'use strict';

	var config = window.compliantyConnector;
	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( text ) { return text; };

	if ( ! config || ! config.restUrl || ! config.nonce ) {
		return;
	}

	var POLL_MS = 5000;
	var MAX_POLLS = 180;

	/**
	 * Calls one of the plugin's REST routes.
	 *
	 * @param {string} route  Route below the REST namespace.
	 * @param {string} method HTTP method.
	 * @param {Object} body   Optional JSON body.
	 * @return {Promise<{ok:boolean,data:Object}>}
	 */
	function call( route, method, body ) {
		var options = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' },
		};
		if ( body ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}

		return window.fetch( config.restUrl + route, options ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				return { ok: response.ok, data: data || {} };
			} );
		} );
	}

	function setText( node, text ) {
		if ( node ) {
			node.textContent = text;
		}
	}

	/**
	 * Polls a route until it reports done, then reloads the page.
	 *
	 * @param {string}      route    Route to poll.
	 * @param {HTMLElement} progress Element for the progress text.
	 * @param {string}      waiting  Text while waiting.
	 * @param {number}      minIdle  Number of polls to ignore an immediate idle state.
	 */
	function poll( route, progress, waiting, minIdle ) {
		var count = 0;
		setText( progress, waiting );

		function tick() {
			count += 1;
			call( route, 'GET' ).then( function ( result ) {
				if ( ! result.ok ) {
					setText( progress, result.data.message || __( 'The state could not be read. Reload the page to try again.', 'complianty-connector' ) );
					return;
				}
				if ( result.data.done && ( count > minIdle || result.data.state !== 'idle' ) ) {
					window.location.reload();
					return;
				}
				if ( count >= MAX_POLLS ) {
					setText( progress, __( 'This takes longer than expected. Reload the page later to see the result.', 'complianty-connector' ) );
					return;
				}
				window.setTimeout( tick, POLL_MS );
			} ).catch( function () {
				setText( progress, __( 'The state could not be read. Reload the page to try again.', 'complianty-connector' ) );
			} );
		}

		window.setTimeout( tick, 2000 );
	}

	function init() {
		var scanButton = document.querySelector( '[data-complianty-scan-start]' );
		var scanProgress = document.querySelector( '[data-complianty-scan-progress]' );
		var freeButton = document.querySelector( '[data-complianty-free-start]' );
		var freeProgress = document.querySelector( '[data-complianty-free-progress]' );

		var scanWaiting = __( 'The scan is running. This page updates when it is finished.', 'complianty-connector' );
		var freeWaiting = __( 'The check is running. This can take a minute.', 'complianty-connector' );

		if ( scanButton ) {
			scanButton.addEventListener( 'click', function () {
				scanButton.disabled = true;
				setText( scanProgress, __( 'Starting the scan...', 'complianty-connector' ) );
				call( 'scan', 'POST' ).then( function ( result ) {
					if ( ! result.ok ) {
						setText( scanProgress, result.data.message || __( 'The scan could not be started.', 'complianty-connector' ) );
						scanButton.disabled = false;
						return;
					}
					poll( 'scan', scanProgress, scanWaiting, 2 );
				} ).catch( function () {
					setText( scanProgress, __( 'The scan could not be started.', 'complianty-connector' ) );
					scanButton.disabled = false;
				} );
			} );
		}

		if ( scanProgress && scanProgress.getAttribute( 'data-complianty-resume' ) ) {
			poll( 'scan', scanProgress, scanWaiting, 0 );
		}

		if ( freeButton ) {
			freeButton.addEventListener( 'click', function () {
				freeButton.disabled = true;
				setText( freeProgress, __( 'Starting the check...', 'complianty-connector' ) );
				call( 'free-scan', 'POST', { confirm: true } ).then( function ( result ) {
					if ( ! result.ok ) {
						setText( freeProgress, result.data.message || __( 'The check could not be started.', 'complianty-connector' ) );
						freeButton.disabled = false;
						return;
					}
					poll( 'free-scan', freeProgress, freeWaiting, 0 );
				} ).catch( function () {
					setText( freeProgress, __( 'The check could not be started.', 'complianty-connector' ) );
					freeButton.disabled = false;
				} );
			} );
		}

		if ( freeProgress && freeProgress.getAttribute( 'data-complianty-resume' ) ) {
			poll( 'free-scan', freeProgress, freeWaiting, 0 );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
