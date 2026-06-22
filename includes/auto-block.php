<?php
/**
 * Automatic blocking of well-known tracker scripts before consent (B10).
 *
 * Enqueued scripts: filter `script_loader_tag` to neutralise registered handles
 * whose source matches the tracker catalog, turning them into inert
 * `type="text/plain"` tags re-activated by the front-end JS after the matching
 * consent category is granted. This is the standard, fully local approach and
 * does not buffer or rewrite the whole page.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether automatic tracker blocking is active for the current request.
 *
 * @return bool
 */
function lynbro_cookie_consent_auto_block_active() {
	if ( is_admin() ) {
		return false;
	}
	if ( ! lynbro_cookie_consent_should_show() ) {
		return false;
	}
	return ! empty( lynbro_cookie_consent_get_option( 'auto_block', 1 ) );
}

/**
 * Neutralise enqueued tracker scripts by handle/source via script_loader_tag.
 *
 * @param string $tag    The full <script> tag.
 * @param string $handle The script handle.
 * @param string $src    The script source URL.
 * @return string Possibly rewritten tag.
 */
function lynbro_cookie_consent_auto_block_tag( $tag, $handle, $src ) {
	if ( ! lynbro_cookie_consent_auto_block_active() ) {
		return $tag;
	}

	$catalog = lynbro_cookie_consent_tracker_catalog();
	$match   = lynbro_cookie_consent_catalog_match( $src, $catalog );
	if ( false === $match ) {
		// Also try matching by handle name (some snippets register descriptive handles).
		$match = lynbro_cookie_consent_catalog_match( $handle, $catalog );
	}
	if ( false === $match ) {
		return $tag;
	}

	$category = isset( $catalog[ $match ]['category'] ) ? sanitize_key( $catalog[ $match ]['category'] ) : 'marketing';

	// Only gate categories the visitor actually controls.
	if ( ! lynbro_cookie_consent_category_is_active( $category ) ) {
		return $tag;
	}

	// Neutralise the enqueued tracker tag IN PLACE (we modify the existing tag
	// WordPress built, we do not emit a new script): rename `src` to `data-src`
	// so the browser never fetches it, drop the executable `type`, and mark it
	// with the consent category. The front-end JS re-activates it after the
	// matching category is granted. WordPress has no enqueue function for an
	// inert placeholder, so the script_loader_tag filter is the correct hook.
	$marker      = ' type="text/plain" data-lynbro-cc="' . esc_attr( $category ) . '"';
	$neutralised = preg_replace( '#\stype=(["\']).*?\1#i', '', $tag );
	$neutralised = preg_replace( '#\ssrc=#i', ' data-src=', (string) $neutralised, 1 );
	$neutralised = preg_replace_callback(
		'#<\s*script\b#i',
		static function ( $m ) use ( $marker ) {
			return $m[0] . $marker;
		},
		(string) $neutralised,
		1
	);

	return ( null === $neutralised || '' === $neutralised ) ? $tag : $neutralised;
}
add_filter( 'script_loader_tag', 'lynbro_cookie_consent_auto_block_tag', 20, 3 );
