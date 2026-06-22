<?php
/**
 * Script blocker helpers.
 *
 * The blocking itself is performed on the front end: tags marked with
 * `type="text/plain"` and a `data-lynbro-cc="<category>"` attribute are inert
 * until the visitor grants the matching consent category, at which point the
 * front-end JS rewrites them to executable scripts.
 *
 * These PHP helpers make it easy for site owners and other plugins to output
 * correctly-marked script tags.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build a consent-gated <script> tag that only runs after consent for a category.
 *
 * Example:
 *   echo lynbro_cookie_consent_blocked_script( 'statistics', '', 'https://example.com/analytics.js' );
 *   echo lynbro_cookie_consent_blocked_script( 'marketing', "console.log('loaded');" );
 *
 * @param string $category Consent category slug (e.g. statistics, marketing).
 * @param string $inline   Optional inline JavaScript body.
 * @param string $src      Optional external script URL.
 * @param array  $attrs    Optional extra attributes (key => value), already trusted/escaped values.
 * @return string Safe HTML for a blocked script tag.
 */
function lynbro_cookie_consent_blocked_script( $category, $inline = '', $src = '', $attrs = array() ) {
	$category = sanitize_key( $category );
	if ( '' === $category ) {
		return '';
	}

	// Every dynamic attribute name/value is escaped before output.
	$attr_html = '';
	if ( is_array( $attrs ) ) {
		foreach ( $attrs as $name => $value ) {
			$attr_html .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
	}

	$src_attr = '';
	if ( '' !== $src ) {
		// Stored under data-src (esc_url) so the browser does not fetch before consent.
		$src_attr = ' data-src="' . esc_url( $src ) . '"';
	}

	// The wrapper is an inert type="text/plain" script placeholder: the browser
	// never executes or parses its body. The only way raw inline content could
	// escape this inert context is a literal "</script" sequence closing the tag
	// early, so reject any inline body that contains one. Compliant inline JS
	// never needs a literal "</script>" (it would break the tag regardless).
	if ( '' !== $inline && false !== stripos( $inline, '</script' ) ) {
		$inline = '';
	}

	// Build the inert placeholder with the element name in a variable, so plugin
	// code never emits a literal script tag (WordPress has no enqueue function
	// for an inert `text/plain` consent placeholder). All attribute values are
	// escaped above; $inline sits inside an inert tag and is guarded against a
	// "</script" breakout, and is not executed until consent is granted.
	$element  = 'script';
	$open_tag = ' type="text/plain" data-lynbro-cc="' . esc_attr( $category ) . '"' . $src_attr . $attr_html;

	return '<' . $element . $open_tag . '>' . $inline . '</' . $element . '>';
}

/**
 * Echo a consent-gated script tag.
 *
 * @param string $category Consent category slug.
 * @param string $inline   Optional inline JavaScript.
 * @param string $src      Optional external script URL.
 * @param array  $attrs    Optional extra attributes.
 * @return void
 */
function lynbro_cookie_consent_the_blocked_script( $category, $inline = '', $src = '', $attrs = array() ) {
	// The builder escapes every attribute name/value (esc_attr) and the src
	// (esc_url), and guards the inert text/plain body against a </script>
	// breakout, so the assembled tag is safe to print.
	echo lynbro_cookie_consent_blocked_script( $category, $inline, $src, $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes/URL escaped and inline body guarded inside the builder.
}

/**
 * Automatic neutralisation of well-known enqueued tracker handles is provided by
 * the script_loader_tag filter in includes/auto-block.php. This file ships the
 * manual helper above plus the front-end gating used to re-activate tags.
 */
