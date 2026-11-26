<?php
/**
 * Geo / consent-mode resolution.
 *
 * Determines which legal consent model applies to the current visitor:
 *  - opt-in   : EU/EEA/UK (prior consent required, GDPR/ePrivacy).
 *  - opt-out  : US (CCPA/CPRA — "Do Not Sell or Share").
 *  - notice   : rest of the world / notice-only (configurable).
 *
 * No external API is contacted. Region detection relies only on CDN/edge headers
 * that may already be present (e.g. Cloudflare's `CF-IPCountry`). If nothing is
 * available, we fall back to the safest legal option: opt-in.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * EU/EEA + UK + Switzerland ISO-3166 alpha-2 country codes (opt-in / prior consent).
 *
 * @return string[]
 */
function lynbro_cookie_consent_eu_countries() {
	return array(
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
		'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
		'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', // EU.
		'IS', 'LI', 'NO', // EEA.
		'GB', // UK.
		'CH', // Switzerland: Google (Consent Mode, ads) and Microsoft (UET, Clarity) require consent signals there too.
	);
}

/**
 * Detect the visitor's ISO country code from edge/CDN headers only.
 *
 * Returns an empty string when unknown (no external lookup is performed).
 *
 * @return string Two-letter uppercase country code, or '' if unknown.
 */
function lynbro_cookie_consent_detect_country() {
	$header_keys = array(
		'HTTP_CF_IPCOUNTRY',      // Cloudflare.
		'HTTP_X_GEO_COUNTRY',     // Generic / some CDNs.
		'HTTP_CLOUDFRONT_VIEWER_COUNTRY', // AWS CloudFront.
		'GEOIP_COUNTRY_CODE',     // Apache mod_geoip.
	);

	$country = '';
	foreach ( $header_keys as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			$candidate = strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
			// Valid two-letter code, ignore reserved values like XX / T1.
			if ( preg_match( '/^[A-Z]{2}$/', $candidate ) && 'XX' !== $candidate && 'T1' !== $candidate ) {
				$country = $candidate;
				break;
			}
		}
	}

	return $country;
}

/**
 * Resolve the effective consent model for the current request.
 *
 * @return string One of: opt-in|opt-out|notice.
 */
function lynbro_cookie_consent_resolve_mode() {
	$configured = lynbro_cookie_consent_get_option( 'consent_mode', 'geo' );

	// Manual override modes bypass geo detection entirely.
	if ( in_array( $configured, array( 'opt-in', 'opt-out', 'notice' ), true ) ) {
		$mode = $configured;
	} else {
		// Geo mode.
		$country = lynbro_cookie_consent_detect_country();

		if ( '' === $country ) {
			// Unknown region -> safest legal default.
			$mode = 'opt-in';
		} elseif ( in_array( $country, lynbro_cookie_consent_eu_countries(), true ) ) {
			$mode = 'opt-in';
		} elseif ( 'US' === $country ) {
			$mode = 'opt-out';
		} else {
			$mode = 'notice';
		}
	}

	/**
	 * Filter the resolved consent region/mode.
	 *
	 * @param string $mode       Resolved mode (opt-in|opt-out|notice).
	 * @param string $configured Configured consent_mode option.
	 */
	return apply_filters( 'lynbro_cookie_consent_geo_region', $mode, $configured );
}

/**
 * Keep a page cache from serving one visitor's region to another.
 *
 * In geo mode the consent model comes from the visitor's country (edge header).
 * The model is printed into the page, so a full-page cache (WP Rocket, LiteSpeed,
 * nginx fastcgi_cache, Cloudflare APO, Lynbro Cache …) that stored the page for
 * a visitor from the US (opt-out: trackers run until they object) — Googlebot,
 * say — would serve that opt-out page to visitors from the EU. Opt-in pages are
 * safe for everyone, so they stay cacheable; any other model marks the response
 * as not cacheable with every signal the common caches read. banner.js also
 * falls back to opt-in when the visitor's time zone is in Europe.
 *
 * @return void
 */
function lynbro_cookie_consent_geo_cache_guard() {
	if ( is_admin() || 'geo' !== lynbro_cookie_consent_get_option( 'consent_mode', 'geo' ) ) {
		return;
	}
	if ( ! lynbro_cookie_consent_should_show() || 'opt-in' === lynbro_cookie_consent_resolve_mode() ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the shared page-cache constant. WP Rocket, W3TC, WP Super Cache, Cache Enabler, Lynbro Cache.
	}
	do_action( 'litespeed_control_set_nocache', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.
		'lynbro-cookie-consent: region-specific consent model'
	);
	if ( ! headers_sent() ) {
		nocache_headers();
		header( 'X-Accel-Expires: 0' );            // nginx fastcgi/proxy cache.
		header( 'CDN-Cache-Control: no-store' );    // Cloudflare and other CDNs.
		header( 'Vary: CF-IPCountry', false );
	}
}
add_action( 'template_redirect', 'lynbro_cookie_consent_geo_cache_guard', 0 );
