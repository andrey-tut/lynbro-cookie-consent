<?php
/**
 * No emoji images from WordPress.org.
 *
 * WordPress core prints an emoji detection script on every page. In a browser without an emoji font it replaces
 * emoji (in content, menus, widgets) with images from s.w.org, so the visitor's IP address goes to WordPress.org
 * before they have been asked anything; older WordPress versions also print a dns-prefetch to s.w.org. With the
 * `local_emoji` option on (default), the site leaves emoji to the visitor's own font and no request leaves for
 * WordPress.org. Owner decision 2026-11-17: this capability belongs to Lynbro Cookie Consent (suite rule §7: one
 * owner); themes mark only their own glyphs with `wp-exclude-emoji`.
 *
 * The admin, the block editor and e-mails are not touched.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the front end of this request should load no emoji images from WordPress.org.
 *
 * @return bool
 */
function lynbro_cookie_consent_local_emoji_active() {
	if ( is_admin() ) {
		return false;
	}
	/**
	 * Filters whether core's emoji images from s.w.org are switched off on the front end.
	 *
	 * @param bool $active The `local_emoji` setting (on by default).
	 */
	return (bool) apply_filters( 'lynbro_cookie_consent_local_emoji', ! empty( lynbro_cookie_consent_get_option( 'local_emoji', 1 ) ) );
}

/**
 * Unhook core's front-end emoji detection script and styles (feeds keep their static images: a feed reader is
 * not a visitor of the site).
 *
 * @return void
 */
function lynbro_cookie_consent_local_emoji() {
	if ( ! lynbro_cookie_consent_local_emoji_active() ) {
		return;
	}
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'embed_head', 'print_emoji_detection_script' );
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_action( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles' );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	add_filter( 'wp_resource_hints', 'lynbro_cookie_consent_local_emoji_hints', 10, 2 );
}
add_action( 'init', 'lynbro_cookie_consent_local_emoji', 5 );

/**
 * Drop resource hints that point at WordPress.org's emoji host.
 *
 * @param array<int,string|array<string,string>> $urls          Hints.
 * @param string                                 $relation_type dns-prefetch, preconnect, ….
 * @return array<int,string|array<string,string>>
 */
function lynbro_cookie_consent_local_emoji_hints( $urls, $relation_type ) {
	if ( 'dns-prefetch' !== $relation_type && 'preconnect' !== $relation_type ) {
		return $urls;
	}
	$out = array();
	foreach ( (array) $urls as $url ) {
		$href = is_array( $url ) ? (string) ( $url['href'] ?? '' ) : (string) $url;
		if ( preg_match( '#^(https?:)?//s\.w\.org(/|$)#i', $href ) ) {
			continue;
		}
		$out[] = $url;
	}
	return $out;
}
