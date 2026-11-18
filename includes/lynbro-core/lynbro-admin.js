/**
 * Lynbro shared admin behaviour.
 *
 * Canonical source: plugins/_shared/lynbro-core/. Two jobs:
 *   1. Ask before a destructive submit. The message is an attribute rather
 *      than a string in here so it arrives already translated, and the handler
 *      is a real script rather than an onsubmit="" attribute so nothing
 *      plugin-authored sits in the markup.
 *   2. Make the review request and the suggestions block answer instantly.
 *      Every answer is a plain nonce'd link that works without this script
 *      (admin-post records it and sends you back); here it is sent in the
 *      background instead, so a broken script elsewhere on the page can never
 *      leave the prompt stuck on screen.
 */
( function () {
	'use strict';

	document.addEventListener(
		'submit',
		function ( event ) {
			var form = event.target;

			if ( ! form || ! form.getAttribute ) {
				return;
			}

			var message = form.getAttribute( 'data-lynbro-confirm' );

			if ( message && ! window.confirm( message ) ) {
				event.preventDefault();
			}
		},
		true
	);

	function send( url ) {
		if ( ! url || ! window.fetch ) {
			return;
		}
		window.fetch( url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'lynbro_ajax=1', {
			credentials: 'same-origin'
		} );
	}

	function close( box ) {
		if ( box && box.parentNode ) {
			box.parentNode.removeChild( box );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}

		var box = target.closest( '.lynbro-prompt' );
		if ( ! box ) {
			return;
		}

		// Core's own dismiss button on the notice means "not now".
		if ( target.closest( '.notice-dismiss' ) ) {
			send( box.getAttribute( 'data-lynbro-later' ) );
			return;
		}

		var link = target.closest( 'a[data-lynbro-answer]' );
		if ( ! link ) {
			return;
		}

		if ( 'done' === link.getAttribute( 'data-lynbro-answer' ) ) {
			// Let the link open the review page in its new tab (it records the
			// answer on the way); only tidy up this one.
			window.setTimeout( function () {
				close( box.closest( '.lynbro-suggest-wrap' ) || box );
			}, 0 );
			return;
		}

		event.preventDefault();
		send( link.getAttribute( 'href' ) );
		close( box.closest( '.lynbro-suggest-wrap' ) || box );
	} );
}() );
