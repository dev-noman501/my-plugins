/**
 * Referral Tracker Pro - front-end tracker.
 *
 * Goals:
 *  - First-touch attribution (the original referral is never overwritten).
 *  - Survives page changes / page caching (cookie + localStorage).
 *  - Only referred visitors generate any network traffic.
 *  - Never blocks the page or interferes with existing site scripts.
 *
 * Public API for custom integrations (e.g. JS-driven calculators):
 *   window.RTPTracker.trackCall('+441234567890');
 *   window.RTPTracker.trackForm({ form_id: 'calc', form_name: 'Price Calculator', fields: {...} });
 */
( function () {
	'use strict';

	if ( typeof window.RTP_CONFIG === 'undefined' ) {
		return;
	}

	var CFG = window.RTP_CONFIG;
	var STORE_KEY = 'rtp_ref_store';

	/* Debug mode: add ?rtp_debug=1 to any URL to see logs in the console. */
	var DEBUG = ( window.location.search.indexOf( 'rtp_debug=1' ) !== -1 );
	function log() {
		if ( DEBUG && window.console ) {
			var a = [ '%c[RTP]', 'color:#14506e;font-weight:bold' ];
			window.console.log.apply( window.console, a.concat( [].slice.call( arguments ) ) );
		}
	}

	/* ----------------------------------------------------------------- */
	/* Small utilities                                                    */
	/* ----------------------------------------------------------------- */

	function uuid() {
		if ( window.crypto && window.crypto.getRandomValues ) {
			var b = new Uint8Array( 16 );
			window.crypto.getRandomValues( b );
			b[ 6 ] = ( b[ 6 ] & 0x0f ) | 0x40;
			b[ 8 ] = ( b[ 8 ] & 0x3f ) | 0x80;
			var h = [];
			for ( var i = 0; i < 16; i++ ) {
				h.push( ( b[ i ] + 0x100 ).toString( 16 ).slice( 1 ) );
			}
			return (
				h[ 0 ] + h[ 1 ] + h[ 2 ] + h[ 3 ] + '-' + h[ 4 ] + h[ 5 ] + '-' +
				h[ 6 ] + h[ 7 ] + '-' + h[ 8 ] + h[ 9 ] + '-' +
				h[ 10 ] + h[ 11 ] + h[ 12 ] + h[ 13 ] + h[ 14 ] + h[ 15 ]
			);
		}
		return 'r' + Date.now().toString( 16 ) + Math.random().toString( 16 ).slice( 2, 10 );
	}

	function getParam( name ) {
		try {
			return new URLSearchParams( window.location.search ).get( name );
		} catch ( e ) {
			return null;
		}
	}

	function setCookie( name, value, days ) {
		var d = new Date();
		d.setTime( d.getTime() + days * 864e5 );
		var secure = ( 'https:' === window.location.protocol ) ? '; Secure' : '';
		document.cookie =
			name + '=' + encodeURIComponent( value ) +
			'; expires=' + d.toUTCString() +
			'; path=/; SameSite=Lax' + secure;
	}

	function lsGet( key ) {
		try {
			return window.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function lsSet( key, val ) {
		try {
			window.localStorage.setItem( key, val );
		} catch ( e ) {}
	}

	/* ----------------------------------------------------------------- */
	/* First-touch store                                                  */
	/* ----------------------------------------------------------------- */

	function readStore() {
		var raw = lsGet( STORE_KEY );
		if ( ! raw ) {
			// Fall back to the cookie (e.g. localStorage blocked).
			var m = document.cookie.match( new RegExp( '(?:^|; )' + CFG.cookieName + '=([^;]*)' ) );
			if ( m ) {
				try {
					return JSON.parse( decodeURIComponent( m[ 1 ] ) );
				} catch ( e ) {
					return null;
				}
			}
			return null;
		}
		try {
			return JSON.parse( raw );
		} catch ( e ) {
			return null;
		}
	}

	function persist( store ) {
		var json = JSON.stringify( store );
		lsSet( STORE_KEY, json );
		// Server reads this cookie for form-plugin attribution.
		setCookie(
			CFG.cookieName,
			JSON.stringify( { c: store.c, s: store.s, l: store.l, t: store.t } ),
			parseInt( CFG.cookieDays, 10 ) || 30
		);
	}

	function collectUtm() {
		var keys = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ];
		var out = {};
		for ( var i = 0; i < keys.length; i++ ) {
			var v = getParam( keys[ i ] );
			if ( v ) {
				out[ keys[ i ] ] = v;
			}
		}
		return out;
	}

	/**
	 * Resolves the active first-touch store, creating it on the first
	 * referred visit and never overwriting an existing one.
	 */
	function resolveStore() {
		var existing = readStore();
		var refParam = getParam( 'ref' );

		// First-touch already locked and not expired -> keep it.
		if ( existing && existing.c && existing.s ) {
			if ( ! existing.t || ( Date.now() - existing.t ) < ( ( parseInt( CFG.cookieDays, 10 ) || 30 ) * 864e5 ) ) {
				// Refresh the cookie so server-side stays in sync.
				persist( existing );
				return existing;
			}
		}

		if ( ! refParam ) {
			return null; // No attribution, nothing to track.
		}

		var store = {
			c: String( refParam ).slice( 0, 64 ),
			s: uuid(),
			l: window.location.href.slice( 0, 512 ),
			t: Date.now(),
			u: collectUtm()
		};
		persist( store );
		return store;
	}

	/* ----------------------------------------------------------------- */
	/* Transport                                                          */
	/* ----------------------------------------------------------------- */

	function send( payload ) {
		try {
			var body = JSON.stringify( payload );
			log( 'send "' + payload.type + '"', payload );

			if ( DEBUG ) {
				// Use fetch in debug mode so the server response is visible.
				fetch( CFG.restUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
					body: body,
					keepalive: true,
					credentials: 'same-origin'
				} ).then( function ( r ) {
					log( payload.type + ' -> HTTP ' + r.status );
				} ).catch( function ( err ) {
					log( payload.type + ' -> request failed', err );
				} );
				return;
			}

			if ( navigator.sendBeacon ) {
				var blob = new Blob( [ body ], { type: 'application/json' } );
				if ( navigator.sendBeacon( CFG.restUrl, blob ) ) {
					return;
				}
			}
			fetch( CFG.restUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
				body: body,
				keepalive: true,
				credentials: 'same-origin'
			} ).catch( function () {} );
		} catch ( e ) {
			log( 'send error', e );
		}
	}

	function basePayload( store, type ) {
		return {
			type: type,
			code: store.c,
			sid: store.s,
			landing: store.l,
			page: window.location.href.slice( 0, 512 ),
			docref: document.referrer ? document.referrer.slice( 0, 512 ) : '',
			utm: store.u || {}
		};
	}

	/* ----------------------------------------------------------------- */
	/* Event capture                                                      */
	/* ----------------------------------------------------------------- */

	var STORE = resolveStore();

	function trackVisit() {
		if ( ! STORE ) {
			return;
		}
		// De-dupe visits per page within the same tab session.
		try {
			var key = 'rtp_v_' + window.location.pathname;
			if ( window.sessionStorage.getItem( key ) ) {
				return;
			}
			window.sessionStorage.setItem( key, '1' );
		} catch ( e ) {}
		send( basePayload( STORE, 'visit' ) );
	}

	function trackCall( phone ) {
		if ( ! STORE ) {
			log( 'trackCall skipped: no referral on this visitor (open a ?ref= link first)' );
			return;
		}
		if ( ! parseInt( CFG.callTracking, 10 ) ) {
			log( 'trackCall skipped: call tracking disabled in settings' );
			return;
		}
		var p = basePayload( STORE, 'call' );
		p.phone = String( phone || '' ).slice( 0, 40 );
		send( p );
	}

	function trackForm( meta ) {
		if ( ! STORE ) {
			log( 'trackForm skipped: no referral on this visitor (open a ?ref= link first)' );
			return;
		}
		if ( ! parseInt( CFG.formTracking, 10 ) ) {
			log( 'trackForm skipped: form tracking disabled in settings' );
			return;
		}
		meta = meta || {};
		var p = basePayload( STORE, 'form' );
		// Allow caller to override the submission page (used by the
		// deferred calculator path so the recorded `event_page` is the
		// page where the user actually clicked submit, not the success
		// redirect URL).
		if ( meta.page ) {
			p.page = String( meta.page ).slice( 0, 512 );
		}
		p.form_id = String( meta.form_id || '' ).slice( 0, 100 );
		p.form_name = String( meta.form_name || '' ).slice( 0, 191 );
		p.form_type = String( meta.form_type || 'generic' ).slice( 0, 40 );
		// Always send fields when provided — the server extracts lead
		// identity (name/email/phone/amount) from them. The fieldStorage
		// setting only gates whether the full field dump is persisted.
		if ( meta.fields ) {
			p.fields = meta.fields;
		}
		send( p );
	}

	/* tel: link clicks (event delegation, passive, never preventDefault). */
	function onTelClick( e ) {
		var el = e.target;
		while ( el && el !== document ) {
			if ( el.tagName === 'A' && el.getAttribute( 'href' ) ) {
				var href = el.getAttribute( 'href' );
				if ( href.indexOf( 'tel:' ) === 0 ) {
					trackCall( href.replace( 'tel:', '' ).trim() );
					return;
				}
			}
			el = el.parentNode;
		}
	}

	/* Generic <form> submit fallback (Elementor/CF7/etc. also have a
	   server-side hook; this catches everything else, deduped server-side). */
	function onFormSubmit( e ) {
		var form = e.target;
		if ( ! form || form.tagName !== 'FORM' ) {
			return;
		}

		// Supported form plugins are captured server-side with richer data
		// (form name / id). Skip them here to avoid relying on the dedupe
		// window and to keep the better record.
		if (
			form.classList.contains( 'wpcf7-form' ) ||
			form.classList.contains( 'wpforms-form' ) ||
			form.classList.contains( 'elementor-form' ) ||
			( form.id && form.id.indexOf( 'gform_' ) === 0 ) ||
			form.closest( '.gform_wrapper' )
		) {
			return;
		}

		// Collect fields always so lead identity (name/email/phone) can be
		// extracted server-side. fieldStorage only gates whether the full
		// field dump is persisted in `extra`.
		var fields = {};
		try {
			var els = form.querySelectorAll( 'input, textarea, select' );
			for ( var i = 0; i < els.length; i++ ) {
				var n = els[ i ].name || els[ i ].id;
				var t = ( els[ i ].type || '' ).toLowerCase();
				if ( ! n || t === 'password' || t === 'hidden' || t === 'file' ) {
					continue;
				}
				fields[ n ] = els[ i ].value;
			}
		} catch ( err ) {}
		trackForm( {
			form_id: form.id || form.getAttribute( 'name' ) || 'form',
			form_name: form.getAttribute( 'data-name' ) || form.id || 'Form',
			form_type: 'generic',
			fields: fields
		} );
	}

	/* Custom JS-driven forms (e.g. the price calculator) that never fire a
	   real <form> submit. On click we DO NOT record immediately — we stash
	   the submission in sessionStorage. The actual RTP event is only created
	   on the next page load IF the form's own success-redirect happens
	   (URL contains `?submitted=1`). That way failed submissions (e.g. GHL
	   duplicate-contact rejection) never show up as leads. */

	var PENDING_KEY = 'rtp_pending_calc_form';

	function onCustomClick( e ) {
		if ( ! e.target || ! e.target.closest ) {
			return;
		}
		var selectors = String( CFG.customSel || '' )
			.split( ',' )
			.map( function ( s ) { return s.trim(); } )
			.filter( function ( s ) { return s.length; } );

		var hit     = null;
		var matched = '';
		for ( var i = 0; i < selectors.length; i++ ) {
			try {
				hit = e.target.closest( selectors[ i ] );
			} catch ( err ) {
				continue;
			}
			if ( hit ) {
				matched = selectors[ i ];
				break;
			}
		}
		if ( ! hit ) {
			return;
		}

		// Locate the form (button often sits outside <form> in Elementor).
		var form   = hit.closest( 'form' );
		var fields = {};
		try {
			if ( ! form ) {
				var allForms = document.querySelectorAll( 'form' );
				for ( var ff = 0; ff < allForms.length; ff++ ) {
					if ( allForms[ ff ].querySelector( '[id^="form-field-"]' ) ) {
						form = allForms[ ff ];
						break;
					}
				}
			}
			var els = form
				? form.querySelectorAll( 'input, textarea, select' )
				: document.querySelectorAll( '[id^="form-field-"]' );
			for ( var j = 0; j < els.length; j++ ) {
				var n = els[ j ].name || els[ j ].id;
				var t = ( els[ j ].type || '' ).toLowerCase();
				if ( ! n || t === 'password' || t === 'file' ) {
					continue;
				}
				fields[ n ] = els[ j ].value;
			}
		} catch ( err2 ) {}

		try {
			sessionStorage.setItem( PENDING_KEY, JSON.stringify( {
				ts:   Date.now(),
				page: window.location.href.slice( 0, 512 ),
				meta: {
					form_id:   ( form && ( form.id || form.getAttribute( 'name' ) ) ) || 'custom-form',
					form_name: ( form && form.getAttribute( 'name' ) ) || document.title || 'Custom Form',
					form_type: 'custom-js',
					fields:    fields
				}
			} ) );
			log( 'custom submit "' + matched + '" stashed pending (' + Object.keys( fields ).length + ' fields). Will record only on success redirect.' );
		} catch ( err3 ) {
			log( 'pending stash failed', err3 );
		}
	}

	/* Promote a stashed pending submission into a real RTP event — but
	   ONLY when the current URL carries the success marker (`?submitted=1`)
	   that the calculator uses on a successful redirect. If submission
	   failed, no marker is set and the stash is silently discarded. */
	function checkPendingCalcSubmission() {
		try {
			var raw = sessionStorage.getItem( PENDING_KEY );
			if ( ! raw ) {
				return;
			}

			var params = null;
			try { params = new URLSearchParams( window.location.search ); } catch ( e ) {}
			var hasSuccess = params && params.get( 'submitted' ) === '1';
			if ( ! hasSuccess ) {
				return; // Wait for the success redirect to land.
			}

			// Clear FIRST so a manual reload of the success page can't double-record.
			sessionStorage.removeItem( PENDING_KEY );

			var pending = JSON.parse( raw );
			if ( ! pending || ! pending.meta ) {
				return;
			}
			// Safety: discard if older than 2 minutes (probably a stale tab).
			if ( Date.now() - ( pending.ts || 0 ) > 120000 ) {
				log( 'pending calc stash expired, discarded' );
				return;
			}

			// Use the calculator-page URL (where they actually clicked submit),
			// not the post-redirect URL.
			pending.meta.page = pending.page;

			log( 'calculator success confirmed — recording form event' );
			trackForm( pending.meta );
		} catch ( err ) {
			log( 'checkPendingCalcSubmission error', err );
		}
	}

	function boot() {
		log( 'booted. referral =', STORE ? { code: STORE.c, session: STORE.s, landing: STORE.l } : null );
		if ( ! STORE ) {
			log( 'No referral attribution on this visitor. Open the site via a ?ref=CODE link (active code), in a window where you are NOT logged into WordPress.' );
		}
		trackVisit();
		checkPendingCalcSubmission();
		document.addEventListener( 'click', onTelClick, true );
		document.addEventListener( 'submit', onFormSubmit, true );
		document.addEventListener( 'click', onCustomClick, true );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	/* Public API for custom (non-standard) integrations. */
	window.RTPTracker = {
		trackCall: trackCall,
		trackForm: trackForm,
		getReferral: function () {
			return STORE ? { code: STORE.c, session: STORE.s } : null;
		}
	};
} )();
