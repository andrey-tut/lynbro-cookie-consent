/**
 * Cookie consent banner — front-end logic (vanilla JS, no dependencies).
 *
 * Responsibilities:
 *  - Show/hide the banner based on stored consent and consent version.
 *  - Store the visitor choice in a cookie (JSON: categories + version + timestamp).
 *  - Update Google Consent Mode v2 signals after a choice.
 *  - Honor Global Privacy Control (GPC) for opt-out modes with a visible toast.
 *  - Activate scripts marked <script type="text/plain" data-lynbro-cc="cat">.
 *  - Restore consent-gated embeds (YouTube/Maps/Vimeo/…) on consent or click.
 *  - Bridge consent state into the WP Consent API when present.
 *  - Record the decision in the local consent log (REST, nonce-protected).
 *  - Expose a small API and dispatch a "lynbro_cc:consent" event.
 *
 * @package LynbroCookieConsent
 */
( function () {
	'use strict';

	var cfg = window.lynbroCookieConsentConfig || {};
	var COOKIE = cfg.cookieName || 'lynbro_cc_consent';
	var VERSION = parseInt( cfg.consentVersion, 10 ) || 1;
	var MODE = cfg.mode || 'opt-in';
	var DAYS = parseInt( cfg.cookieDays, 10 ) || 365;
	var CAT_SIGNALS = cfg.categorySignals || {};
	var ALLOW_KEY = 'lynbro_cc_embed_allow'; // localStorage key: per-service "always allow".

	/* ---------- Language (cache-safe, client-side only) ---------- */

	var TRANSLATIONS = cfg.translations || {}; // { locale: { key: text } }.
	var LOCALES = cfg.locales || {};           // { locale: label } for the switcher.
	var LANG_COOKIE = cfg.langCookie || 'lynbro_cc_lang';
	var currentLocale = cfg.lang || 'en_US';

	// Normalise a BCP-47 / WP locale to a comparable lowercase form.
	function normLocale( code ) {
		return ( '' + code ).toLowerCase().replace( /-/g, '_' );
	}

	// Offered locales as a lookup of normalised => original key.
	function offeredLookup() {
		var map = {};
		for ( var loc in TRANSLATIONS ) {
			if ( TRANSLATIONS.hasOwnProperty( loc ) ) {
				map[ normLocale( loc ) ] = loc;
			}
		}
		return map;
	}

	// Choose the best offered locale for the visitor from navigator.languages.
	function detectLocale() {
		var offered = offeredLookup();
		var langs = ( navigator && navigator.languages && navigator.languages.length )
			? navigator.languages
			: ( navigator && navigator.language ? [ navigator.language ] : [] );

		for ( var i = 0; i < langs.length; i++ ) {
			var want = normLocale( langs[ i ] );
			// Exact match (e.g. de_de) wins.
			if ( offered[ want ] ) {
				return offered[ want ];
			}
			// Language-only match (e.g. "de" -> first offered de_*).
			var base = want.split( '_' )[ 0 ];
			for ( var key in offered ) {
				if ( offered.hasOwnProperty( key ) && key.split( '_' )[ 0 ] === base ) {
					return offered[ key ];
				}
			}
		}
		return null;
	}

	// Apply a locale's strings to every marked banner element.
	function applyLocale( locale ) {
		var strings = TRANSLATIONS[ locale ];
		if ( ! strings ) {
			return;
		}
		currentLocale = locale;

		var textNodes = document.querySelectorAll( '[data-lynbro-cc-text]' );
		Array.prototype.forEach.call( textNodes, function ( node ) {
			var key = node.getAttribute( 'data-lynbro-cc-text' );
			if ( strings.hasOwnProperty( key ) ) {
				node.textContent = strings[ key ];
			}
		} );

		// Description allows a small set of inline HTML supplied by the owner.
		var htmlNodes = document.querySelectorAll( '[data-lynbro-cc-html]' );
		Array.prototype.forEach.call( htmlNodes, function ( node ) {
			var key = node.getAttribute( 'data-lynbro-cc-html' );
			if ( strings.hasOwnProperty( key ) ) {
				node.innerHTML = sanitizeInline( strings[ key ] );
			}
		} );

		if ( root ) {
			root.setAttribute( 'lang', locale.split( '_' )[ 0 ] );
		}
	}

	// Allow only the same limited inline tags the server permits in the description.
	function sanitizeInline( html ) {
		var tmp = document.createElement( 'div' );
		tmp.innerHTML = '' + html;
		var allowed = { A: 1, STRONG: 1, EM: 1, BR: 1 };
		var walker = tmp.querySelectorAll( '*' );
		Array.prototype.forEach.call( walker, function ( el ) {
			if ( ! el.parentNode ) {
				return; // Already detached by a previous replacement.
			}
			if ( ! allowed[ el.tagName ] ) {
				// Replace disallowed element with its text content.
				el.parentNode.replaceChild( document.createTextNode( el.textContent || '' ), el );
				return;
			}
			// Strip event handlers / unsafe attributes.
			Array.prototype.slice.call( el.attributes ).forEach( function ( attr ) {
				var n = attr.name.toLowerCase();
				if ( 'A' === el.tagName && ( 'href' === n || 'title' === n || 'rel' === n || 'target' === n ) ) {
					if ( 'href' === n && /^\s*javascript:/i.test( attr.value ) ) {
						el.removeAttribute( attr.name );
					}
					return;
				}
				el.removeAttribute( attr.name );
			} );
		} );
		return tmp.innerHTML;
	}

	// Build the in-banner language switcher dropdown.
	function setupSwitcher() {
		if ( ! cfg.langSwitcher || ! root ) {
			return;
		}
		var wrap = root.querySelector( '.lynbro-cc__lang' );
		var select = root.querySelector( '[data-lynbro-cc-lang-select]' );
		if ( ! wrap || ! select ) {
			return;
		}
		var count = 0;
		for ( var loc in LOCALES ) {
			if ( ! LOCALES.hasOwnProperty( loc ) || ! TRANSLATIONS[ loc ] ) {
				continue;
			}
			var opt = document.createElement( 'option' );
			opt.value = loc;
			opt.textContent = LOCALES[ loc ];
			select.appendChild( opt );
			count++;
		}
		if ( count < 2 ) {
			return; // Nothing to switch between.
		}
		select.value = currentLocale;
		wrap.hidden = false;
		select.addEventListener( 'change', function () {
			applyLocale( select.value );
			writeCookie( LANG_COOKIE, select.value, DAYS );
			sendStat( 'lang_switch', shownAt ? ( Date.now() - shownAt ) : 0 );
		} );
	}

	// Decide the banner language: saved choice -> browser detection -> server default.
	function initLanguage() {
		var saved  = readCookie( LANG_COOKIE );
		var target = null;
		if ( saved && TRANSLATIONS[ saved ] ) {
			target = saved;                       // visitor's remembered choice
		} else if ( cfg.autoDetectLang ) {
			target = detectLocale();              // browser language (may be null)
		}
		// Fall back to the configured site/page locale. The server always renders
		// the English source (plugin-bundled .mo files are not loaded server-side),
		// so applying it here makes the banner match the site language even when
		// auto-detect is off and on non-English sites.
		if ( ! target && cfg.lang && TRANSLATIONS[ cfg.lang ] ) {
			target = cfg.lang;
		}
		if ( target && TRANSLATIONS[ target ] ) {
			applyLocale( target );
		}
		setupSwitcher();
	}

	/* ---------- Cookie helpers ---------- */

	function readCookie( name ) {
		var match = document.cookie.match(
			new RegExp( '(?:^|; )' + name.replace( /([.*+?^${}()|[\]\\])/g, '\\$1' ) + '=([^;]*)' )
		);
		return match ? decodeURIComponent( match[ 1 ] ) : null;
	}

	function writeCookie( name, value, days ) {
		var date = new Date();
		date.setTime( date.getTime() + days * 24 * 60 * 60 * 1000 );
		var secure = ( 'https:' === location.protocol ) ? '; Secure' : '';
		document.cookie =
			name + '=' + encodeURIComponent( value ) +
			'; expires=' + date.toUTCString() +
			'; path=/; SameSite=Lax' + secure;
	}

	function getStoredConsent() {
		var raw = readCookie( COOKIE );
		if ( ! raw ) {
			return null;
		}
		try {
			var data = JSON.parse( raw );
			if ( ! data || data.v !== VERSION ) {
				return null; // Outdated consent version -> ask again.
			}
			return data;
		} catch ( e ) {
			return null;
		}
	}

	/* ---------- Consent model ---------- */

	// Optional categories the owner enabled (everything except locked "necessary").
	function optionalCategories() {
		var keys = [];
		for ( var k in CAT_SIGNALS ) {
			if ( CAT_SIGNALS.hasOwnProperty( k ) && 'necessary' !== k ) {
				keys.push( k );
			}
		}
		return keys;
	}

	function allGranted() {
		var out = { necessary: true };
		optionalCategories().forEach( function ( k ) {
			out[ k ] = true;
		} );
		return out;
	}

	function allDenied() {
		var out = { necessary: true };
		optionalCategories().forEach( function ( k ) {
			out[ k ] = false;
		} );
		return out;
	}

	/* ---------- Global Privacy Control (GPC) ---------- */

	function gpcSignalPresent() {
		try {
			return navigator && true === navigator.globalPrivacyControl;
		} catch ( e ) {
			return false;
		}
	}

	/* ---------- Google Consent Mode v2 ---------- */

	function gtagAvailable() {
		return cfg.enableGcm && typeof window.gtag === 'function';
	}

	function updateGcm( categories ) {
		if ( ! gtagAvailable() ) {
			return;
		}
		var update = {};
		for ( var cat in CAT_SIGNALS ) {
			if ( ! CAT_SIGNALS.hasOwnProperty( cat ) ) {
				continue;
			}
			var granted = !! categories[ cat ];
			CAT_SIGNALS[ cat ].forEach( function ( signal ) {
				update[ signal ] = granted ? 'granted' : 'denied';
			} );
		}
		window.gtag( 'consent', 'update', update );
	}

	/* ---------- WP Consent API bridge ---------- */

	function updateWpConsentApi( categories ) {
		if ( ! cfg.wpConsentApi || typeof window.wp_set_consent !== 'function' ) {
			return;
		}
		var map = cfg.wpcaMap || {};
		for ( var ourCat in map ) {
			if ( ! map.hasOwnProperty( ourCat ) ) {
				continue;
			}
			var wpType = map[ ourCat ];
			var granted = ( 'necessary' === ourCat ) ? true : !! categories[ ourCat ];
			try {
				window.wp_set_consent( wpType, granted ? 'allow' : 'deny' );
			} catch ( e ) {}
		}
	}

	/* ---------- Script activation ---------- */

	function activateScripts( categories ) {
		var nodes = document.querySelectorAll( 'script[type="text/plain"][data-lynbro-cc]' );
		nodes.forEach( function ( node ) {
			var cat = node.getAttribute( 'data-lynbro-cc' );
			if ( ! categories[ cat ] ) {
				return;
			}
			var fresh = document.createElement( 'script' );
			// Copy attributes except the marker ones.
			for ( var i = 0; i < node.attributes.length; i++ ) {
				var attr = node.attributes[ i ];
				if ( 'type' === attr.name || 'data-lynbro-cc' === attr.name || 'data-src' === attr.name ) {
					continue;
				}
				fresh.setAttribute( attr.name, attr.value );
			}
			var dataSrc = node.getAttribute( 'data-src' );
			if ( dataSrc ) {
				fresh.src = dataSrc;
			} else {
				fresh.text = node.text || node.textContent || '';
			}
			node.parentNode.replaceChild( fresh, node );
		} );
	}

	/* ---------- Embeds ---------- */

	function readAllowedServices() {
		try {
			var raw = window.localStorage.getItem( ALLOW_KEY );
			return raw ? JSON.parse( raw ) : {};
		} catch ( e ) {
			return {};
		}
	}

	function rememberService( service ) {
		try {
			var list = readAllowedServices();
			list[ service ] = true;
			window.localStorage.setItem( ALLOW_KEY, JSON.stringify( list ) );
		} catch ( e ) {}
	}

	function restoreEmbed( wrapper ) {
		var tpl = wrapper.querySelector( 'template.lynbro-cc-embed__frame' );
		if ( ! tpl ) {
			return;
		}
		var frame;
		if ( tpl.content && tpl.content.firstElementChild ) {
			frame = tpl.content.firstElementChild.cloneNode( true );
		} else {
			// Fallback for browsers without <template>.content support.
			var tmp = document.createElement( 'div' );
			tmp.innerHTML = tpl.innerHTML;
			frame = tmp.firstElementChild;
		}
		if ( frame ) {
			wrapper.parentNode.replaceChild( frame, wrapper );
		}
	}

	function processEmbeds( categories ) {
		var allowed = readAllowedServices();
		var wrappers = document.querySelectorAll( '.lynbro-cc-embed[data-lynbro-cc-embed]' );
		wrappers.forEach( function ( wrapper ) {
			var service = wrapper.getAttribute( 'data-lynbro-cc-embed' );
			var cat = wrapper.getAttribute( 'data-lynbro-cc-category' );
			if ( ( cat && categories && categories[ cat ] ) || allowed[ service ] ) {
				restoreEmbed( wrapper );
			}
		} );
	}

	function bindEmbedButtons() {
		document.addEventListener( 'click', function ( e ) {
			var loadBtn = e.target.closest( '[data-lynbro-cc-embed-load]' );
			if ( ! loadBtn ) {
				return;
			}
			var wrapper = loadBtn.closest( '.lynbro-cc-embed' );
			if ( ! wrapper ) {
				return;
			}
			e.preventDefault();
			var always = wrapper.querySelector( '[data-lynbro-cc-embed-always]' );
			if ( always && always.checked ) {
				rememberService( wrapper.getAttribute( 'data-lynbro-cc-embed' ) );
			}
			restoreEmbed( wrapper );
		} );
	}

	/* ---------- Consent log (REST) ---------- */

	function logConsent( categories, method ) {
		if ( ! cfg.log || ! cfg.restUrl || ! window.fetch ) {
			return;
		}
		try {
			window.fetch( cfg.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.restNonce || ''
				},
				body: JSON.stringify( {
					consent_id: ( window.crypto && window.crypto.randomUUID ) ? window.crypto.randomUUID() : '',
					method: method,
					categories: categories,
					lang: currentLocale || cfg.lang || ''
				} ),
				credentials: 'same-origin',
				keepalive: true
			} ).catch( function () {} );
		} catch ( e ) {}
	}

	/* ---------- Aggregated, cookieless statistics (beacon) ---------- */

	// Timestamp the banner was shown, so action beacons can report time-to-action.
	var shownAt = 0;

	// Send one aggregated event via sendBeacon (non-blocking) with a fetch fallback.
	function sendStat( event, tta ) {
		if ( ! cfg.stats || ! cfg.statUrl ) {
			return;
		}
		var payload = {
			event: event,
			lang: ( navigator && navigator.language ) ? navigator.language : '',
			tta: ( typeof tta === 'number' && tta > 0 ) ? Math.round( tta ) : 0
		};
		// A light nonce (wp_rest) protects the endpoint; it travels as a query arg
		// because sendBeacon cannot set custom headers.
		var url = cfg.statUrl +
			( cfg.statUrl.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'_wpnonce=' + encodeURIComponent( cfg.restNonce || '' );

		try {
			if ( navigator && typeof navigator.sendBeacon === 'function' ) {
				var blob = new Blob( [ JSON.stringify( payload ) ], { type: 'application/json' } );
				if ( navigator.sendBeacon( url, blob ) ) {
					return;
				}
			}
		} catch ( e ) {}

		// Fallback for browsers without sendBeacon.
		if ( window.fetch ) {
			try {
				window.fetch( cfg.statUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
					body: JSON.stringify( payload ),
					credentials: 'same-origin',
					keepalive: true
				} ).catch( function () {} );
			} catch ( e2 ) {}
		}
	}

	// Map a consent method to its statistics event.
	function statEventForMethod( method ) {
		if ( 'accept' === method ) {
			return 'accept';
		}
		if ( 'reject' === method || 'gpc' === method ) {
			return 'reject';
		}
		if ( 'custom' === method ) {
			return 'settings_changed';
		}
		return '';
	}

	/* ---------- Apply / persist ---------- */

	function applyConsent( categories, method, persist ) {
		if ( persist ) {
			writeCookie( COOKIE, JSON.stringify( {
				v: VERSION,
				c: categories,
				m: method,
				t: Math.floor( Date.now() / 1000 )
			} ), DAYS );
		}

		updateGcm( categories );
		updateWpConsentApi( categories );
		activateScripts( categories );
		processEmbeds( categories );

		if ( persist ) {
			logConsent( categories, method );

			// Aggregated statistics: report the decision with time-to-action.
			var statEvent = statEventForMethod( method );
			if ( statEvent ) {
				sendStat( statEvent, shownAt ? ( Date.now() - shownAt ) : 0 );
			}
		}

		// Notify integrations.
		try {
			document.dispatchEvent( new CustomEvent( 'lynbro_cc:consent', {
				detail: { categories: categories, method: method }
			} ) );
		} catch ( e ) {
			// IE-safe fallback.
			var ev = document.createEvent( 'CustomEvent' );
			ev.initCustomEvent( 'lynbro_cc:consent', true, true, { categories: categories, method: method } );
			document.dispatchEvent( ev );
		}
	}

	/* ---------- Banner UI ---------- */

	var root, dialog, prefsPanel, saveBtn, prefsBtn, floatBtn, lastFocus;

	function isModal() {
		return root && root.classList.contains( 'lynbro-cc--center-modal' );
	}

	function focusableElements() {
		if ( ! dialog ) {
			return [];
		}
		var sel = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';
		return Array.prototype.slice.call( dialog.querySelectorAll( sel ) ).filter( function ( el ) {
			return ! el.hidden && el.offsetParent !== null;
		} );
	}

	function trapFocus( e ) {
		if ( ! isModal() || 'Tab' !== e.key ) {
			return;
		}
		var items = focusableElements();
		if ( ! items.length ) {
			return;
		}
		var first = items[ 0 ];
		var last = items[ items.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	function showBanner() {
		if ( ! root ) {
			return;
		}
		lastFocus = document.activeElement;
		if ( ! shownAt ) {
			shownAt = Date.now();
		}
		root.hidden = false;
		// Hide the floating button while the banner/modal is open so they never
		// overlap or compete for clicks with other overlays.
		hideFloat();
		if ( isModal() ) {
			document.body.classList.add( 'lynbro-cc-modal-open' );
		}
		var first = root.querySelector( '.lynbro-cc__btn--reject' );
		if ( first ) {
			first.focus();
		}
	}

	function hideBanner() {
		if ( ! root ) {
			return;
		}
		root.hidden = true;
		document.body.classList.remove( 'lynbro-cc-modal-open' );
		revealFloat();
		if ( lastFocus && typeof lastFocus.focus === 'function' ) {
			lastFocus.focus();
		}
	}

	function revealFloat() {
		if ( floatBtn ) {
			floatBtn.hidden = false;
		}
	}

	function hideFloat() {
		if ( floatBtn ) {
			floatBtn.hidden = true;
		}
	}

	function togglePrefs( open ) {
		if ( ! prefsPanel ) {
			return;
		}
		prefsPanel.hidden = ! open;
		if ( saveBtn ) {
			saveBtn.hidden = ! open;
		}
		if ( prefsBtn ) {
			prefsBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
	}

	function readToggles() {
		var categories = { necessary: true };
		var toggles = root.querySelectorAll( '.lynbro-cc__toggle[data-lynbro-cc-category]' );
		toggles.forEach( function ( t ) {
			var cat = t.getAttribute( 'data-lynbro-cc-category' );
			categories[ cat ] = t.disabled ? true : !! t.checked;
		} );
		return categories;
	}

	/* ---------- GPC toast ---------- */

	function showGpcToast() {
		var msg = ( cfg.i18n && cfg.i18n.gpcApplied ) ? cfg.i18n.gpcApplied : 'Global Privacy Control applied.';
		var toast = document.createElement( 'div' );
		toast.className = 'lynbro-cc-toast';
		toast.setAttribute( 'role', 'status' );
		toast.setAttribute( 'aria-live', 'polite' );
		toast.textContent = msg;
		document.body.appendChild( toast );
		// Force a frame before adding the visible class for the transition.
		window.requestAnimationFrame( function () {
			toast.classList.add( 'lynbro-cc-toast--show' );
		} );
		window.setTimeout( function () {
			toast.classList.remove( 'lynbro-cc-toast--show' );
			window.setTimeout( function () {
				if ( toast.parentNode ) {
					toast.parentNode.removeChild( toast );
				}
			}, 400 );
		}, 6000 );
	}

	/* ---------- Events ---------- */

	function bindEvents() {
		root.addEventListener( 'click', function ( e ) {
			var target = e.target.closest( '[data-lynbro-cc-action]' );
			if ( ! target ) {
				return;
			}
			var action = target.getAttribute( 'data-lynbro-cc-action' );

			if ( 'accept' === action ) {
				applyConsent( allGranted(), 'accept', true );
				hideBanner();
			} else if ( 'reject' === action || 'dns' === action ) {
				// "Do Not Sell or Share" is a full opt-out of non-essential cookies.
				applyConsent( allDenied(), 'reject', true );
				hideBanner();
			} else if ( 'prefs' === action ) {
				var willOpen = prefsPanel.hidden;
				togglePrefs( willOpen );
				if ( willOpen ) {
					sendStat( 'manage_open', shownAt ? ( Date.now() - shownAt ) : 0 );
				}
			} else if ( 'save' === action ) {
				applyConsent( readToggles(), 'custom', true );
				hideBanner();
			}
		} );

		// Re-open via shortcode/float button or API.
		document.addEventListener( 'click', function ( e ) {
			var opener = e.target.closest( '[data-lynbro-cc-open]' );
			if ( opener ) {
				e.preventDefault();
				openSettings();
			}
		} );

		// Keyboard: ESC closes (only if a choice exists); Tab is trapped in modal.
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! root.hidden && getStoredConsent() ) {
				hideBanner();
			}
			trapFocus( e );
		} );
	}

	function openSettings() {
		// Pre-fill toggles from stored consent if any.
		var stored = getStoredConsent();
		if ( stored && stored.c ) {
			var toggles = root.querySelectorAll( '.lynbro-cc__toggle[data-lynbro-cc-category]' );
			toggles.forEach( function ( t ) {
				if ( t.disabled ) {
					return;
				}
				var cat = t.getAttribute( 'data-lynbro-cc-category' );
				t.checked = !! stored.c[ cat ];
			} );
		}
		togglePrefs( true );
		showBanner();
	}

	/* ---------- Public API ---------- */

	window.LynbroCookieConsent = {
		getConsent: function () {
			var stored = getStoredConsent();
			return stored ? stored.c : null;
		},
		openSettings: openSettings,
		onChange: function ( cb ) {
			if ( typeof cb === 'function' ) {
				document.addEventListener( 'lynbro_cc:consent', function ( e ) {
					cb( e.detail );
				} );
			}
		}
	};

	/* ---------- Init ---------- */

	function init() {
		root = document.getElementById( 'lynbro-cc-root' );
		floatBtn = document.getElementById( 'lynbro-cc-float' );

		// Embeds and gated scripts work even without the banner root.
		bindEmbedButtons();

		if ( ! root ) {
			return;
		}
		dialog = root.querySelector( '.lynbro-cc__dialog' );
		prefsPanel = root.querySelector( '.lynbro-cc__categories' );
		saveBtn = root.querySelector( '.lynbro-cc__btn--save' );
		prefsBtn = root.querySelector( '.lynbro-cc__btn--prefs' );

		// Pick + apply the banner language before anything is shown (cache-safe).
		initLanguage();

		bindEvents();

		var stored = getStoredConsent();
		if ( stored && stored.c ) {
			// Apply previous choice silently (no re-persist).
			applyConsent( stored.c, stored.m || 'stored', false );
			revealFloat();
			return;
		}

		// GPC: in opt-out / notice contexts, auto-apply opt-out and confirm visibly.
		if ( cfg.honorGpc && gpcSignalPresent() && ( 'opt-out' === MODE || 'notice' === MODE ) ) {
			applyConsent( allDenied(), 'gpc', true );
			revealFloat();
			showGpcToast();
			return;
		}

		if ( 'opt-out' === MODE || 'notice' === MODE ) {
			// CCPA opt-out / notice-only: trackers may run until the visitor opts out.
			applyConsent( allGranted(), 'implied', false );
			showBanner();
		} else {
			// opt-in: nothing runs until consent.
			showBanner();
		}

		// New visitor saw the banner: record one aggregated "shown" event.
		sendStat( 'shown', 0 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
