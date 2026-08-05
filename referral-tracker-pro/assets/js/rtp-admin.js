/**
 * Referral Tracker Pro - admin helpers.
 * Copy-to-clipboard + referral code generator. Vanilla, no dependencies.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.rtp-copy' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var text = btn.getAttribute( 'data-clipboard' ) || '';
		var done = function () {
			var label = btn.textContent;
			btn.textContent = 'Copied!';
			setTimeout( function () {
				btn.textContent = label;
			}, 1500 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, function () {} );
		} else {
			var tmp = document.createElement( 'textarea' );
			tmp.value = text;
			document.body.appendChild( tmp );
			tmp.select();
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( err ) {}
			document.body.removeChild( tmp );
		}
	} );

	var gen = document.getElementById( 'rtp-gen-code' );
	if ( gen ) {
		gen.addEventListener( 'click', function () {
			var input = document.getElementById( 'rtp-code' );
			if ( ! input ) {
				return;
			}
			var chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
			var code = '';
			for ( var i = 0; i < 8; i++ ) {
				code += chars.charAt( Math.floor( Math.random() * chars.length ) );
			}
			input.value = code;
		} );
	}

	/* ============================================================
	 * Lead detail modal
	 * Intercepts clicks on `.rtp-view-lead`, fetches rendered HTML
	 * for the lead via AJAX, and shows it in a centered modal with
	 * "Download / Print PDF" and Close actions. WooCommerce-style.
	 * ============================================================ */

	if ( typeof window.RTP_ADMIN === 'undefined' ) {
		return;
	}
	var CFG = window.RTP_ADMIN;
	var modal = null;

	function buildModal() {
		var el = document.createElement( 'div' );
		el.id = 'rtp-modal';
		el.className = 'rtp-modal';
		el.innerHTML =
			'<div class="rtp-modal-backdrop"></div>' +
			'<div class="rtp-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rtp-modal-title">' +
				'<div class="rtp-modal-head">' +
					'<h2 id="rtp-modal-title">' + esc( CFG.i18n.title ) + '</h2>' +
					'<div class="rtp-modal-actions">' +
						'<button type="button" class="button button-primary rtp-modal-print">' +
							'<span class="dashicons dashicons-download"></span> ' +
							esc( CFG.i18n.download ) +
						'</button>' +
						'<button type="button" class="button rtp-modal-close" aria-label="Close">&times;</button>' +
					'</div>' +
				'</div>' +
				'<div class="rtp-modal-body rtp-printable"></div>' +
			'</div>';
		document.body.appendChild( el );

		el.querySelector( '.rtp-modal-backdrop' ).addEventListener( 'click', closeModal );
		el.querySelector( '.rtp-modal-close' ).addEventListener( 'click', closeModal );
		el.querySelector( '.rtp-modal-print' ).addEventListener( 'click', printModal );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && el.classList.contains( 'is-open' ) ) {
				closeModal();
			}
		} );

		return el;
	}

	function openModal( id ) {
		if ( ! modal ) {
			modal = buildModal();
		}
		var body = modal.querySelector( '.rtp-modal-body' );
		body.innerHTML = '<div class="rtp-modal-loading">' + esc( CFG.i18n.loading ) + '</div>';
		modal.classList.add( 'is-open' );
		document.body.classList.add( 'rtp-modal-open' );

		var url = CFG.ajaxUrl +
			'?action=rtp_lead_detail' +
			'&id=' + encodeURIComponent( id ) +
			'&_wpnonce=' + encodeURIComponent( CFG.nonce );

		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success && res.data ) {
					body.innerHTML = res.data;
				} else {
					body.innerHTML = '<p class="rtp-modal-error">' + esc( CFG.i18n.failed ) + '</p>';
				}
			} )
			.catch( function () {
				body.innerHTML = '<p class="rtp-modal-error">' + esc( CFG.i18n.failed ) + '</p>';
			} );
	}

	function closeModal() {
		if ( modal ) {
			modal.classList.remove( 'is-open' );
		}
		document.body.classList.remove( 'rtp-modal-open' );
	}

	function printModal() {
		document.body.classList.add( 'rtp-printing-modal' );
		window.print();
		setTimeout( function () {
			document.body.classList.remove( 'rtp-printing-modal' );
		}, 800 );
	}

	function esc( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.rtp-view-lead' ) : null;
		if ( ! btn ) {
			return;
		}
		var id = btn.getAttribute( 'data-id' );
		if ( ! id ) {
			return;
		}
		e.preventDefault();
		openModal( id );
	} );
} )();
