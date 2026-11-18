<?php
/**
 * Fired when the plugin is uninstalled. Clean up plugin data.
 *
 * Only plugin-owned options are removed. The visitor's consent choice lives in
 * a client-side cookie and is not stored server-side in the free version.
 *
 * @package LynbroCookieConsent
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'lynbro_cookie_consent_version' );
delete_option( 'lynbro_cookie_consent_options' );
delete_option( 'lynbro_cookie_consent_languages' );
delete_option( 'lynbro_cookie_consent_log_db_version' );
delete_option( 'lynbro_cookie_consent_log_salt' );
delete_option( 'lynbro_cookie_consent_stats_db_version' );
delete_option( 'lynbro_cookie_consent_benchmark' );
delete_option( 'lynbro_cookie_consent_telemetry_last' );
delete_option( 'lynbro_cookie_consent_activated_at' );
delete_option( 'lynbro_cookie_consent_i18n_pending' );
delete_transient( 'lynbro_cookie_consent_i18n_done' );

// Per-user "review notice dismissed" meta.
delete_metadata( 'user', 0, 'lynbro_cookie_consent_review_dismissed', '', true );

// Drop the local consent-log + statistics tables.
$lynbro_cookie_consent_log_table   = $wpdb->prefix . 'lynbro_cookie_consent_log';
$lynbro_cookie_consent_stats_table = $wpdb->prefix . 'lynbro_cookie_consent_stats';
$wpdb->query( "DROP TABLE IF EXISTS {$lynbro_cookie_consent_log_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time uninstall cleanup; table name from $wpdb->prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$lynbro_cookie_consent_stats_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time uninstall cleanup; table name from $wpdb->prefix.

// Clear scheduled events.
foreach ( array( 'lynbro_cookie_consent_log_purge_event', 'lynbro_cookie_consent_telemetry_event', 'lynbro_cookie_consent_i18n_poll_event' ) as $lynbro_cookie_consent_event ) {
	$lynbro_cookie_consent_timestamp = wp_next_scheduled( $lynbro_cookie_consent_event );
	if ( $lynbro_cookie_consent_timestamp ) {
		wp_unschedule_event( $lynbro_cookie_consent_timestamp, $lynbro_cookie_consent_event );
	}
}

// Remove on-demand language files stored in the uploads directory.
$lynbro_cookie_consent_uploads = wp_upload_dir();
if ( empty( $lynbro_cookie_consent_uploads['error'] ) && ! empty( $lynbro_cookie_consent_uploads['basedir'] ) ) {
	$lynbro_cookie_consent_dir = trailingslashit( $lynbro_cookie_consent_uploads['basedir'] ) . 'lynbro-cookie-consent';
	if ( is_dir( $lynbro_cookie_consent_dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $lynbro_cookie_consent_dir, true );
		}
	}
}

// Extension point: add-ons can drop their own transients/options on uninstall here.
