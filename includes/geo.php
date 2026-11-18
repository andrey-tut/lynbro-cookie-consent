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
 * EU/EEA + UK ISO-3166 alpha-2 country codes (opt-in / prior consent).
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
