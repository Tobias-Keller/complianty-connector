/**
 * Editor script for the Complianty privacy policy block.
 *
 * Plain JavaScript without a build step. The block renders on the server.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;

	wp.blocks.registerBlockType( 'complianty/dse', {
		edit: function () {
			var blockProps = wp.blockEditor.useBlockProps();

			return el(
				'div',
				blockProps,
				el( wp.serverSideRender, {
					block: 'complianty/dse',
					EmptyResponsePlaceholder: function () {
						return el(
							'p',
							null,
							__(
								'No approved privacy policy yet. Connect this site in Settings > Complianty and approve the policy in your Complianty account.',
								'complianty-connector'
							)
						);
					},
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
