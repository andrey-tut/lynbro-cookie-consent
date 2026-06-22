/**
 * Admin settings page behaviour:
 *  - tab switching,
 *  - colour pickers,
 *  - live banner preview (position-aware + responsive device widths),
 *  - WCAG-AA contrast validator (compact summary + expandable details),
 *  - one-click design presets.
 *
 * @package LynbroCookieConsent
 */
( function ( $ ) {
	'use strict';

	var data = window.lynbroCookieConsentAdmin || {};
	var i18n = data.i18n || {};
	var presets = data.presets || [];

	var DEVICE_STORE = 'lynbroCcPreviewDevice';
	var DEVICE_WIDTHS = { desktop: '100%', tablet: '768px', mobile: '375px' };

	/* ---------- Contrast helpers (WCAG 2.1 relative luminance) ---------- */

	function hexToRgb( hex ) {
		if ( ! hex ) {
			return null;
		}
		hex = ( '' + hex ).replace( '#', '' ).trim();
		if ( 3 === hex.length ) {
			hex = hex.charAt( 0 ) + hex.charAt( 0 ) + hex.charAt( 1 ) + hex.charAt( 1 ) + hex.charAt( 2 ) + hex.charAt( 2 );
		}
		if ( ! /^[0-9a-fA-F]{6}$/.test( hex ) ) {
			return null;
		}
		return {
			r: parseInt( hex.substr( 0, 2 ), 16 ),
			g: parseInt( hex.substr( 2, 2 ), 16 ),
			b: parseInt( hex.substr( 4, 2 ), 16 )
		};
	}

	function channel( c ) {
		c = c / 255;
		return ( c <= 0.03928 ) ? ( c / 12.92 ) : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
	}

	function luminance( rgb ) {
		return 0.2126 * channel( rgb.r ) + 0.7152 * channel( rgb.g ) + 0.0722 * channel( rgb.b );
	}

	function contrastRatio( hex1, hex2 ) {
		var a = hexToRgb( hex1 );
		var b = hexToRgb( hex2 );
		if ( ! a || ! b ) {
			return null;
		}
		var l1 = luminance( a );
		var l2 = luminance( b );
		var lighter = Math.max( l1, l2 );
		var darker = Math.min( l1, l2 );
		return ( lighter + 0.05 ) / ( darker + 0.05 );
	}

	/* ---------- Read current field values ---------- */

	function val( id ) {
		return $( '#lynbro-cc-' + id ).val();
	}

	function activeColors() {
		var theme = $( '#lynbro-cc-theme' ).val();
		var suffix = ( 'dark' === theme ) ? '_dark' : '';
		return {
			bg: val( 'color_bg' + suffix ),
			text: val( 'color_text' + suffix ),
			accept: val( 'color_accept' + suffix ),
			reject: val( 'color_reject' + suffix ),
			btnText: val( 'color_btn_text' + suffix )
		};
	}

	/* ---------- Floating button preview ---------- */

	function updateFloatPreview( c ) {
		var $float = $( '#lynbro-cc-float-preview' );
		if ( ! $float.length ) {
			return;
		}
		var size = parseInt( $( '#lynbro-cc-float-size' ).val(), 10 ) || 40;
		var shape = $( '#lynbro-cc-float-shape' ).val() || 'round';
		var opacity = parseFloat( $( '#lynbro-cc-float-opacity' ).val() );
		var bg = $( '#lynbro-cc-float_button_bg' ).val() || c.accept;
		var icon = $( '#lynbro-cc-float_button_icon_color' ).val() || c.btnText;
		var show = $( 'input[name$="[float_button]"]' ).is( ':checked' );

		if ( isNaN( opacity ) ) {
			opacity = 0.55;
		}
		var br = '50%';
		if ( 'rounded' === shape ) {
			br = '12px';
		} else if ( 'square' === shape ) {
			br = '0';
		}

		$float.css( {
			display: show ? 'inline-flex' : 'none',
			width: size + 'px',
			height: size + 'px',
			borderRadius: br,
			background: bg,
			color: icon,
			opacity: opacity
		} );
	}

	/* ---------- Position-aware layout ---------- */

	function applyPosition( $stage ) {
		var position = $( '#lynbro-cc-position' ).val() || 'bottom-bar';
		var corner = $( '#lynbro-cc-box-corner' ).val() || 'bottom-right';

		// Drive layout via data-attributes consumed by admin.css.
		$stage.attr( 'data-lynbro-cc-position', position );
		$stage.attr( 'data-lynbro-cc-corner', corner );
	}

	/* ---------- Live preview ---------- */

	function updatePreview() {
		var $preview = $( '#lynbro-cc-preview' );
		if ( ! $preview.length ) {
			return;
		}
		var c = activeColors();
		var title = $( '#lynbro-cc-title' ).val() || i18n.previewTitle || '';
		var desc = $( '#lynbro-cc-desc' ).val() || i18n.previewDesc || '';

		var $stage = $preview.find( '.lynbro-cc-preview__stage' );
		var $banner = $preview.find( '.lynbro-cc-preview__banner' );
		var radius = parseInt( $( '#lynbro-cc-banner-radius' ).val(), 10 );
		var maxWidth = parseInt( $( '#lynbro-cc-banner-max-width' ).val(), 10 );

		applyPosition( $stage );

		$banner.css( {
			background: c.bg,
			color: c.text,
			borderRadius: ( radius > 0 ? radius : 12 ) + 'px',
			maxWidth: ( maxWidth > 0 ? maxWidth + 'px' : '' )
		} );
		updateFloatPreview( c );
		$preview.find( '.lynbro-cc-preview__title' ).text( title ).css( 'color', c.text );
		$preview.find( '.lynbro-cc-preview__desc' ).text( desc ).css( 'color', c.text );

		$preview.find( '.lynbro-cc-preview__btn--accept' ).text( i18n.accept || 'Accept all' )
			.css( { background: c.accept, color: c.btnText, borderColor: c.accept } );
		$preview.find( '.lynbro-cc-preview__btn--reject' ).text( i18n.reject || 'Reject all' )
			.css( { background: c.reject, color: c.btnText, borderColor: c.reject } );
		$preview.find( '.lynbro-cc-preview__btn--prefs' ).text( i18n.prefs || 'Manage preferences' )
			.css( { background: 'transparent', color: c.text, borderColor: c.text } );

		updateContrast( c );
	}

	function fmt( template, value ) {
		return ( '' + template ).replace( '%s', value );
	}

	function esc( str ) {
		return $( '<span>' ).text( '' + str ).html();
	}

	/**
	 * Build one detailed contrast row (used inside the expandable details box).
	 *
	 * @param {string} label Pair label.
	 * @param {number|null} ratio Contrast ratio.
	 * @return {Object} { html, pass }.
	 */
	function contrastRow( label, ratio ) {
		if ( null === ratio ) {
			return { html: '', pass: true };
		}
		var rounded = Math.round( ratio * 10 ) / 10;
		var pass = ratio >= 4.5;
		var msg = pass
			? fmt( i18n.contrastPass || 'Good contrast (%s:1).', rounded )
			: fmt( i18n.contrastFail || 'Low contrast (%s:1).', rounded );
		var html = '<p class="lynbro-cc-contrast__row ' + ( pass ? 'is-pass' : 'is-fail' ) +
			'"><strong>' + esc( label ) + ':</strong> ' + esc( msg ) + '</p>';
		return { html: html, pass: pass };
	}

	function updateContrast( c ) {
		var $summary = $( '#lynbro-cc-contrast' );
		if ( ! $summary.length ) {
			return;
		}
		var $toggle = $( '#lynbro-cc-contrast-toggle' );
		var $details = $( '#lynbro-cc-contrast-details' );

		var rows = [
			contrastRow( i18n.contrastText || 'Text on background', contrastRatio( c.text, c.bg ) ),
			contrastRow( i18n.contrastAccept || 'Accept button', contrastRatio( c.btnText, c.accept ) ),
			contrastRow( i18n.contrastReject || 'Reject button', contrastRatio( c.btnText, c.reject ) )
		];

		var detailsHtml = '';
		var failCount = 0;
		var i;
		for ( i = 0; i < rows.length; i++ ) {
			detailsHtml += rows[ i ].html;
			if ( ! rows[ i ].pass ) {
				failCount++;
			}
		}
		$details.html( detailsHtml );

		if ( 0 === failCount ) {
			// Compact single PASS line.
			$summary.html(
				'<p class="lynbro-cc-contrast__status is-pass">' +
				'<span class="lynbro-cc-contrast__icon" aria-hidden="true">&#10003;</span> ' +
				esc( i18n.contrastAllPass || 'Colours meet WCAG AA accessibility' ) +
				'</p>'
			);
		} else {
			// Warning line naming how many pairs fail.
			$summary.html(
				'<p class="lynbro-cc-contrast__status is-fail">' +
				'<span class="lynbro-cc-contrast__icon" aria-hidden="true">&#9888;</span> ' +
				esc( fmt( i18n.contrastSomeFail || 'Some colours have low contrast (%s) — see details.', failCount ) ) +
				'</p>'
			);
		}

		// Show the Details toggle whenever there is something to expand.
		$toggle.prop( 'hidden', false );
		var expanded = 'true' === $toggle.attr( 'aria-expanded' );
		// When there is a failure, expand by default the first time.
		if ( failCount > 0 && ! $toggle.data( 'autoOpened' ) ) {
			expanded = true;
			$toggle.data( 'autoOpened', true );
		}
		$toggle.attr( 'aria-expanded', expanded ? 'true' : 'false' )
			.text( expanded ? ( i18n.contrastHide || 'Hide details' ) : ( i18n.contrastDetails || 'Details' ) );
		$details.prop( 'hidden', ! expanded );
	}

	/* ---------- Device width switcher ---------- */

	function setDevice( device ) {
		if ( ! DEVICE_WIDTHS[ device ] ) {
			device = 'desktop';
		}
		var $viewport = $( '.lynbro-cc-preview__viewport' );
		$viewport.attr( 'data-lynbro-cc-device', device )
			.css( 'max-width', DEVICE_WIDTHS[ device ] );

		$( '.lynbro-cc-device' ).each( function () {
			var active = $( this ).data( 'lynbroCcDevice' ) === device;
			$( this ).toggleClass( 'is-active', active ).attr( 'aria-pressed', active ? 'true' : 'false' );
		} );

		try {
			window.localStorage.setItem( DEVICE_STORE, device );
		} catch ( e ) {
			// Storage may be unavailable (private mode); ignore.
		}
	}

	function initDevice() {
		var stored = 'desktop';
		try {
			stored = window.localStorage.getItem( DEVICE_STORE ) || 'desktop';
		} catch ( e ) {
			stored = 'desktop';
		}
		setDevice( stored );

		$( '.lynbro-cc-device' ).on( 'click', function () {
			setDevice( $( this ).data( 'lynbroCcDevice' ) );
		} );
	}

	/* ---------- Design presets ---------- */

	function findPreset( id ) {
		var i;
		for ( i = 0; i < presets.length; i++ ) {
			if ( presets[ i ].id === id ) {
				return presets[ i ];
			}
		}
		return null;
	}

	function setColorField( key, value ) {
		var $input = $( '#lynbro-cc-' + key );
		if ( ! $input.length ) {
			return;
		}
		$input.val( value );
		// Sync the WP colour picker UI if present.
		if ( $.fn.wpColorPicker ) {
			$input.wpColorPicker( 'color', value );
		}
	}

	// Maps preset option keys to their settings-form field id (without the
	// shared "lynbro-cc-" prefix). Colour keys use the raw key as the id.
	var FIELD_ID = {
		position: 'position',
		box_corner: 'box-corner',
		theme: 'theme',
		banner_radius: 'banner-radius',
		banner_max_width: 'banner-max-width'
	};

	function applyPreset( preset ) {
		if ( ! preset || ! preset.values ) {
			return;
		}
		var values = preset.values;
		var key;
		for ( key in values ) {
			if ( ! Object.prototype.hasOwnProperty.call( values, key ) ) {
				continue;
			}
			if ( 0 === key.indexOf( 'color_' ) ) {
				setColorField( key, values[ key ] );
			} else if ( FIELD_ID[ key ] ) {
				$( '#lynbro-cc-' + FIELD_ID[ key ] ).val( values[ key ] );
			}
		}
		updatePreview();
	}

	function initPresets() {
		$( '.lynbro-cc-preset' ).on( 'click', function () {
			var id = $( this ).data( 'lynbroCcPreset' );
			var preset = findPreset( id );
			if ( ! preset ) {
				return;
			}
			var ok = window.confirm( i18n.applyPreset || 'Apply this design preset? It will overwrite your current Design settings.' );
			if ( ! ok ) {
				return;
			}
			applyPreset( preset );
			window.wp = window.wp || {};
			if ( window.wp.a11y && window.wp.a11y.speak ) {
				window.wp.a11y.speak( i18n.presetApplied || 'Preset applied.' );
			}
		} );
	}

	$( function () {
		// Colour pickers — refresh preview on change.
		if ( $.fn.wpColorPicker ) {
			$( '.lynbro-cc-color' ).wpColorPicker( {
				change: function () {
					// wpColorPicker updates the input after this callback; defer.
					window.setTimeout( updatePreview, 50 );
				},
				clear: function () {
					window.setTimeout( updatePreview, 50 );
				}
			} );
		}

		// Tabs.
		var $tabs = $( '.lynbro-cc-tabs .nav-tab' );

		function activateTab( tab ) {
			if ( ! tab ) {
				return;
			}
			$tabs.removeClass( 'nav-tab-active' );
			$tabs.filter( '[data-lynbro-cc-tab="' + tab + '"]' ).addClass( 'nav-tab-active' );

			$( '.lynbro-cc-tab-panel' ).attr( 'hidden', true );
			$( '#lynbro-cc-tab-' + tab ).removeAttr( 'hidden' );

			// The import form lives in a separate panel shown with the Tools tab.
			if ( 'tools' === tab ) {
				$( '#lynbro-cc-tab-tools-import' ).removeAttr( 'hidden' );
			}

			// Show the generic "Save Changes" button only on tabs that hold
			// editable option fields; hide it on read-only / action-only tabs.
			var $submit = $( '#lynbro-cc-submit' );
			if ( $submit.length ) {
				var settingsTabs = ( $submit.data( 'lynbroCcSettingsTabs' ) || '' ).toString().split( ',' );
				if ( $.inArray( tab, settingsTabs ) !== -1 ) {
					$submit.removeAttr( 'hidden' );
				} else {
					$submit.attr( 'hidden', true );
				}
			}
		}

		$tabs.on( 'click', function ( e ) {
			e.preventDefault();
			activateTab( $( this ).data( 'lynbroCcTab' ) );
		} );

		// Quick links inside the status block can jump to a tab.
		$( document ).on( 'click', '.lynbro-cc-status [data-lynbro-cc-tab]', function ( e ) {
			e.preventDefault();
			activateTab( $( this ).data( 'lynbroCcTab' ) );
		} );

		// Open the tab referenced by the URL hash (e.g. after saving languages),
		// otherwise sync state (incl. the Save button) with the active tab that
		// PHP rendered on load.
		if ( window.location.hash.indexOf( '#lynbro-cc-tab-' ) === 0 ) {
			activateTab( window.location.hash.replace( '#lynbro-cc-tab-', '' ) );
		} else {
			activateTab( $tabs.filter( '.nav-tab-active' ).data( 'lynbroCcTab' ) || 'general' );
		}

		// Contrast details toggle.
		$( '#lynbro-cc-contrast-toggle' ).on( 'click', function () {
			var expanded = 'true' === $( this ).attr( 'aria-expanded' );
			expanded = ! expanded;
			$( this ).attr( 'aria-expanded', expanded ? 'true' : 'false' )
				.text( expanded ? ( i18n.contrastHide || 'Hide details' ) : ( i18n.contrastDetails || 'Details' ) )
				.data( 'autoOpened', true );
			$( '#lynbro-cc-contrast-details' ).prop( 'hidden', ! expanded );
		} );

		// Live preview: update on ANY settings field change (delegated so it also
		// covers fields in tabs that are revealed later). Colour pickers also call
		// updatePreview from their own wpColorPicker change/clear callbacks above.
		$( '.lynbro-cc-settings' ).on( 'input change', 'input, select, textarea', updatePreview );

		initDevice();
		initPresets();
		updatePreview();
	} );
} )( jQuery );
