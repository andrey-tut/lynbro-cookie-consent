<?php
/**
 * WP Consent API integration (A6).
 *
 * Registers this plugin as the active consent management provider (CMP) for the
 * "WP Consent API" plugin (wordpress.org/plugins/wp-consent-api), and maps our
 * categories onto the WP Consent API consent types:
 *
 *   functional  <- necessary
 *   preferences <- preferences
 *   statistics  <- statistics
 *   marketing   <- marketing
 *   statistics-anonymous <- (left to other integrations)
 *
 * The actual `wp_set_consent()` calls happen on the front end via the JS bridge
 * (banner.js) once the WP Consent API JS is present, because consent state lives
 * client-side for cache-safety. Here we declare the CMP and the type mapping.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the WP Consent API integration should run.
 *
 * @return bool
 */
function lynbro_cookie_consent_wpca_active() {
	return ! empty( lynbro_cookie_consent_get_option( 'wp_consent_api', 1 ) );
}

/**
 * Declare this plugin as a consent type "optin" provider for WP Consent API.
 *
 * The filter name is dynamic: wp_consent_api_registered_{plugin_basename}.
 *
 * @return void
 */
function lynbro_cookie_consent_wpca_register() {
	if ( ! lynbro_cookie_consent_wpca_active() ) {
		return;
	}
	$plugin = plugin_basename( LYNBRO_COOKIE_CONSENT_FILE );
	add_filter( "wp_consent_api_registered_{$plugin}", '__return_true' );
}
add_action( 'plugins_loaded', 'lynbro_cookie_consent_wpca_register', 20 );

/**
 * Tell WP Consent API which consent model we use (optin / optout).
 *
 * @param string $type The default consent type.
 * @return string 'optin' or 'optout'.
 */
function lynbro_cookie_consent_wpca_type( $type ) {
	$mode = function_exists( 'lynbro_cookie_consent_resolve_mode' ) ? lynbro_cookie_consent_resolve_mode() : 'opt-in';
	return ( 'opt-out' === $mode || 'notice' === $mode ) ? 'optout' : 'optin';
}
add_filter( 'wp_get_consent_type', 'lynbro_cookie_consent_wpca_type' );

/**
 * Map our category slugs onto WP Consent API consent categories for the JS bridge.
 *
 * @return array<string,string> Our category => WP Consent API category.
 */
function lynbro_cookie_consent_wpca_category_map() {
	$map = array(
		'necessary'   => 'functional',
		'preferences' => 'preferences',
		'statistics'  => 'statistics',
		'marketing'   => 'marketing',
	);

	/**
	 * Filter the mapping between our categories and WP Consent API categories.
	 *
	 * @param array $map Category mapping.
	 */
	return apply_filters( 'lynbro_cookie_consent_wpca_category_map', $map );
}
