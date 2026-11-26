<?php
/**
 * Built-in catalog of well-known trackers and embed providers.
 *
 * This catalog powers two free features:
 *  - Automatic blocking of known third-party scripts before consent (B10),
 *    by matching enqueued/printed <script> tags by handle or source URL pattern.
 *  - Embed/iframe placeholders (A1), by matching iframe sources to a provider.
 *
 * Everything is local: no external request is made. The catalog can be extended
 * by site owners / other plugins via the documented filters below.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catalog of known tracker scripts mapped to a consent category.
 *
 * Each entry:
 *   - name     : Human-readable service name.
 *   - category : Consent category slug it belongs to.
 *   - patterns : Array of case-insensitive substrings; if any appears in the
 *                script src (or in the registered handle), the script is gated.
 *
 * @return array<string,array<string,mixed>>
 */
function lynbro_cookie_consent_tracker_catalog() {
	$catalog = array(
		'google-analytics' => array(
			'name'     => 'Google Analytics (GA4 / Universal)',
			'category' => 'statistics',
			'patterns' => array( 'googletagmanager.com/gtag/js', 'google-analytics.com/analytics.js', 'google-analytics.com/ga.js', 'googletagmanager.com/gtm.js' ),
			// Reads Google Consent Mode: may load before consent in advanced mode.
			'signals'  => 'google',
		),
		'google-tag-manager' => array(
			'name'     => 'Google Tag Manager',
			'category' => 'statistics',
			'patterns' => array( 'googletagmanager.com/gtm.js', 'googletagmanager.com/ns.html' ),
			// Reads Google Consent Mode: may load before consent in advanced mode.
			'signals'  => 'google',
		),
		'google-ads' => array(
			'name'     => 'Google Ads / Conversion',
			'category' => 'marketing',
			'patterns' => array( 'googleadservices.com', 'googlesyndication.com', 'doubleclick.net' ),
			// Reads Google Consent Mode: may load before consent in advanced mode.
			'signals'  => 'google',
		),
		'meta-pixel' => array(
			'name'     => 'Meta (Facebook) Pixel',
			'category' => 'marketing',
			'patterns' => array( 'connect.facebook.net', 'facebook.com/tr' ),
		),
		'hotjar' => array(
			'name'     => 'Hotjar',
			'category' => 'statistics',
			'patterns' => array( 'static.hotjar.com', 'script.hotjar.com' ),
		),
		'microsoft-clarity' => array(
			'name'     => 'Microsoft Clarity',
			'category' => 'statistics',
			'patterns' => array( 'clarity.ms/tag', 'c.clarity.ms' ),
		),
		'microsoft-uet' => array(
			'name'     => 'Microsoft Advertising (UET)',
			'category' => 'marketing',
			'patterns' => array( 'bat.bing.com' ),
		),
		'linkedin-insight' => array(
			'name'     => 'LinkedIn Insight Tag',
			'category' => 'marketing',
			'patterns' => array( 'snap.licdn.com' ),
		),
		'tiktok-pixel' => array(
			'name'     => 'TikTok Pixel',
			'category' => 'marketing',
			'patterns' => array( 'analytics.tiktok.com' ),
		),
		'twitter-ads' => array(
			'name'     => 'X / Twitter Ads',
			'category' => 'marketing',
			'patterns' => array( 'static.ads-twitter.com', 'analytics.twitter.com' ),
		),
		'pinterest-tag' => array(
			'name'     => 'Pinterest Tag',
			'category' => 'marketing',
			'patterns' => array( 's.pinimg.com/ct' ),
		),
		'matomo' => array(
			'name'     => 'Matomo',
			'category' => 'statistics',
			'patterns' => array( 'matomo.js', 'piwik.js' ),
			// Matomo has its own consent API: it counts visits without cookies and
			// only sets them after consent (see lynbro_cookie_consent_blocking_catalog()).
			'signals'  => 'matomo',
		),
	);

	/**
	 * Filter the built-in tracker catalog used for automatic blocking.
	 *
	 * @param array $catalog Tracker definitions keyed by slug.
	 */
	return apply_filters( 'lynbro_cookie_consent_tracker_catalog', $catalog );
}

/**
 * The tracker catalog minus the entries that are not blocked on this site.
 *
 * - Matomo is never held back by default: its consent API (set up in the head
 *   stub) lets it count visits without cookies before consent and add its
 *   cookies only after the Statistics category is granted. First-party,
 *   cookieless statistics are not something the banner switches off.
 *   Filter `lynbro_cookie_consent_matomo_cookieless` (false = block it as before).
 * - In advanced Google Consent Mode, the Google tag, Tag Manager and Google Ads
 *   load before consent with every signal denied.
 *
 * @return array<string,array<string,mixed>>
 */
function lynbro_cookie_consent_blocking_catalog() {
	$advanced = 'advanced' === lynbro_cookie_consent_get_option( 'gcm_mode', 'basic' )
		&& ! empty( lynbro_cookie_consent_get_option( 'enable_gcm', 1 ) );
	/**
	 * Filter whether Matomo runs cookieless before consent (true) or is held back
	 * until the Statistics category is granted (false).
	 *
	 * @param bool $cookieless Default true.
	 */
	$matomo_cookieless = (bool) apply_filters( 'lynbro_cookie_consent_matomo_cookieless', true );

	$out = array();
	foreach ( lynbro_cookie_consent_tracker_catalog() as $slug => $entry ) {
		$signals = isset( $entry['signals'] ) ? $entry['signals'] : '';
		if ( 'google' === $signals && $advanced ) {
			continue;
		}
		if ( 'matomo' === $signals && $matomo_cookieless ) {
			continue;
		}
		$out[ $slug ] = $entry;
	}
	return $out;
}

/**
 * Catalog of known embed/iframe providers mapped to a service name.
 *
 * Each entry:
 *   - name     : Human-readable service name (shown on the placeholder).
 *   - patterns : Array of case-insensitive substrings matched against iframe src.
 *
 * @return array<string,array<string,mixed>>
 */
function lynbro_cookie_consent_embed_catalog() {
	$catalog = array(
		'youtube' => array(
			'name'     => 'YouTube',
			'patterns' => array( 'youtube.com/embed', 'youtube-nocookie.com/embed', 'youtu.be/' ),
		),
		'vimeo' => array(
			'name'     => 'Vimeo',
			'patterns' => array( 'player.vimeo.com/video' ),
		),
		'google-maps' => array(
			'name'     => 'Google Maps',
			'patterns' => array( 'google.com/maps/embed', 'maps.google.com', 'google.com/maps' ),
		),
		'openstreetmap' => array(
			'name'     => 'OpenStreetMap',
			'patterns' => array( 'openstreetmap.org/export/embed' ),
		),
		'spotify' => array(
			'name'     => 'Spotify',
			'patterns' => array( 'open.spotify.com/embed' ),
		),
		'soundcloud' => array(
			'name'     => 'SoundCloud',
			'patterns' => array( 'w.soundcloud.com/player' ),
		),
		'twitter' => array(
			'name'     => 'X / Twitter',
			'patterns' => array( 'platform.twitter.com', 'twitter.com/widgets', 'syndication.twitter.com' ),
		),
		'instagram' => array(
			'name'     => 'Instagram',
			'patterns' => array( 'instagram.com/embed', 'instagram.com/p/' ),
		),
		'facebook' => array(
			'name'     => 'Facebook',
			'patterns' => array( 'facebook.com/plugins', 'facebook.com/v' ),
		),
		'tiktok' => array(
			'name'     => 'TikTok',
			'patterns' => array( 'tiktok.com/embed' ),
		),
		'google-recaptcha' => array(
			'name'     => 'Google reCAPTCHA',
			'patterns' => array( 'google.com/recaptcha', 'recaptcha.net' ),
		),
		'dailymotion' => array(
			'name'     => 'Dailymotion',
			'patterns' => array( 'dailymotion.com/embed' ),
		),
	);

	/**
	 * Filter the built-in embed/iframe provider catalog.
	 *
	 * @param array $catalog Embed provider definitions keyed by slug.
	 */
	return apply_filters( 'lynbro_cookie_consent_embed_catalog', $catalog );
}

/**
 * Match a URL/string against a catalog and return the matching slug.
 *
 * @param string $haystack The string to test (script src or iframe src).
 * @param array  $catalog  Catalog from one of the functions above.
 * @return string|false Matching catalog slug, or false if none.
 */
function lynbro_cookie_consent_catalog_match( $haystack, $catalog ) {
	$haystack = strtolower( (string) $haystack );
	if ( '' === $haystack ) {
		return false;
	}
	foreach ( $catalog as $slug => $entry ) {
		if ( empty( $entry['patterns'] ) ) {
			continue;
		}
		foreach ( (array) $entry['patterns'] as $pattern ) {
			$pattern = strtolower( (string) $pattern );
			if ( '' !== $pattern && false !== strpos( $haystack, $pattern ) ) {
				return $slug;
			}
		}
	}
	return false;
}
