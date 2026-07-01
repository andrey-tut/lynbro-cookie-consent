<?php
/**
 * Plugin Name:       Lynbro Cookie Consent
 * Plugin URI:        https://plugins.lynbro.dk/lynbro-cookie-consent
 * Description:       Free, multilingual, fully configurable cookie consent banner — GDPR/ePrivacy and Google Consent Mode v2 ready, with no pageview limits.
 * Version:           0.4.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Lynbro
 * Author URI:        https://lynbro.dk
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lynbro-cookie-consent
 * Domain Path:       /languages
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

define( 'LYNBRO_COOKIE_CONSENT_VERSION', '0.4.4' );
define( 'LYNBRO_COOKIE_CONSENT_FILE', __FILE__ );
define( 'LYNBRO_COOKIE_CONSENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LYNBRO_COOKIE_CONSENT_URL', plugin_dir_url( __FILE__ ) );
define( 'LYNBRO_COOKIE_CONSENT_SLUG', 'lynbro-cookie-consent' );

/**
 * Cache-busting version for an asset: the plugin version plus the file's
 * modification time, so updated CSS/JS is never served stale from a browser
 * cache after a change or update.
 *
 * @param string $relative_path Path relative to the plugin root, e.g. 'public/banner.js'.
 * @return string Version string for wp_enqueue_*.
 */
function lynbro_cookie_consent_asset_ver( $relative_path ) {
	$file  = LYNBRO_COOKIE_CONSENT_DIR . ltrim( $relative_path, '/' );
	$mtime = is_readable( $file ) ? (int) filemtime( $file ) : 0;
	return $mtime ? LYNBRO_COOKIE_CONSENT_VERSION . '.' . $mtime : LYNBRO_COOKIE_CONSENT_VERSION;
}

/*
 * Translations are loaded automatically. Since WordPress 4.6 the bundled .mo
 * files in /languages are loaded just-in-time for plugins hosted on
 * WordPress.org, so no explicit load_plugin_textdomain() call is required
 * (and that function is discouraged by Plugin Check).
 */

/**
 * Activation: seed default options and create the consent-log table.
 *
 * The settings live in a single option array; the visitor's choice is stored
 * client-side in a cookie. The local consent log (proof of consent) and the
 * aggregated, cookieless statistics each live in a dedicated table created here
 * via dbDelta.
 *
 * @param bool $network_wide Whether the plugin is being activated network-wide.
 * @return void
 */
function lynbro_cookie_consent_activate( $network_wide = false ) {
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/options.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/consent-log.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/stats.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/onboarding.php';

	add_option( 'lynbro_cookie_consent_version', LYNBRO_COOKIE_CONSENT_VERSION );

	if ( false === get_option( LYNBRO_COOKIE_CONSENT_OPTION, false ) ) {
		add_option( LYNBRO_COOKIE_CONSENT_OPTION, lynbro_cookie_consent_default_options() );
	}

	lynbro_cookie_consent_log_install();
	lynbro_cookie_consent_stats_install();

	// Set the one-time settings redirect flag (skips bulk / network activation).
	lynbro_cookie_consent_onboarding_activate( $network_wide );
}
register_activation_hook( __FILE__, 'lynbro_cookie_consent_activate' );

/**
 * Deactivation: clear our scheduled cron events; keep user data intact.
 */
function lynbro_cookie_consent_deactivate() {
	$timestamp = wp_next_scheduled( 'lynbro_cookie_consent_log_purge_event' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'lynbro_cookie_consent_log_purge_event' );
	}

	// Weekly telemetry cron (opt-in feature).
	$telemetry = wp_next_scheduled( 'lynbro_cookie_consent_telemetry_event' );
	if ( $telemetry ) {
		wp_unschedule_event( $telemetry, 'lynbro_cookie_consent_telemetry_event' );
	}

	// Background language-on-demand poll (single events, but clear any pending one).
	$i18n_poll = wp_next_scheduled( 'lynbro_cookie_consent_i18n_poll_event' );
	if ( $i18n_poll ) {
		wp_unschedule_event( $i18n_poll, 'lynbro_cookie_consent_i18n_poll_event' );
	}
	// Extension point: clear any add-on scheduled cron events here.
}
register_deactivation_hook( __FILE__, 'lynbro_cookie_consent_deactivate' );

/**
 * Bootstrap the plugin: load modules.
 */
function lynbro_cookie_consent_init() {
	// Shared logic.
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/options.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/languages.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/geo.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/tracker-catalog.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/script-blocker.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/shortcode.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/consent-log.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/wp-consent-api.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/import-export.php';

	// Aggregated, cookieless statistics + opt-in portal features (Wave D).
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/stats.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/telemetry.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/i18n-ondemand.php';

	// Front-end banner + front-end-only modules.
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'public/banner.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/auto-block.php';
	require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/embeds.php';

	// Admin settings + engagement (loaded after front-end so admin can reuse helpers).
	if ( is_admin() ) {
		require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/onboarding.php';
		require_once LYNBRO_COOKIE_CONSENT_DIR . 'includes/feedback.php';
		require_once LYNBRO_COOKIE_CONSENT_DIR . 'admin/settings.php';
	}

	/**
	 * Extension point: add-ons can hook here to load additional modules.
	 */
	do_action( 'lynbro_cookie_consent_loaded' );
}
add_action( 'plugins_loaded', 'lynbro_cookie_consent_init' );
