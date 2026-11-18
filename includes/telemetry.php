<?php
/**
 * Telemetry to the Lynbro portal (Wave D, opt-in, default OFF).
 *
 * When — and only when — the site owner explicitly opts in, a weekly WP-Cron job
 * sends the *aggregated* local statistics (no visitor IP, no personal data) to
 * the portal so the owner can see how their accept-rate compares to the average.
 * The portal's benchmark response is cached and shown in the admin.
 *
 * Everything here is gated behind the `telemetry_enabled` option. The free
 * version never contacts the portal otherwise. The endpoint, the data sent and
 * the privacy posture are disclosed in the readme "External services" section.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Portal base URL for all opt-in network features.
 */
if ( ! defined( 'LYNBRO_COOKIE_CONSENT_PORTAL' ) ) {
	define( 'LYNBRO_COOKIE_CONSENT_PORTAL', 'https://plugins.lynbro.dk' );
}

/**
 * Is telemetry sharing enabled by the owner? (Opt-in, default off.)
 *
 * @return bool
 */
function lynbro_cookie_consent_telemetry_enabled() {
	return ! empty( lynbro_cookie_consent_get_option( 'telemetry_enabled', 0 ) );
}

/**
 * Register the weekly telemetry cron schedule on init when enabled, and clear it
 * when disabled, so toggling the option starts/stops the job cleanly.
 *
 * @return void
 */
function lynbro_cookie_consent_telemetry_manage_schedule() {
	$scheduled = wp_next_scheduled( 'lynbro_cookie_consent_telemetry_event' );

	if ( lynbro_cookie_consent_telemetry_enabled() ) {
		if ( ! $scheduled ) {
			// First run a little in the future so activation isn't blocked by a request.
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'lynbro_cookie_consent_telemetry_event' );
		}
	} elseif ( $scheduled ) {
		wp_unschedule_event( $scheduled, 'lynbro_cookie_consent_telemetry_event' );
	}
}
add_action( 'init', 'lynbro_cookie_consent_telemetry_manage_schedule' );

/**
 * Ensure a "weekly" cron schedule exists (WordPress ships daily/twicedaily/hourly).
 *
 * @param array<string,array<string,mixed>> $schedules Existing schedules.
 * @return array<string,array<string,mixed>>
 */
function lynbro_cookie_consent_telemetry_cron_schedule( $schedules ) {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'lynbro-cookie-consent' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'lynbro_cookie_consent_telemetry_cron_schedule' );

/**
 * Build the telemetry payload from the trailing 7-day aggregate.
 *
 * Contains aggregated counters and coarse distributions only. The "site" field
 * is the owner's own domain (they opted in); no visitor IP or UA is included.
 *
 * @return array<string,mixed>
 */
function lynbro_cookie_consent_telemetry_payload() {
	$agg = function_exists( 'lynbro_cookie_consent_stats_aggregate' )
		? lynbro_cookie_consent_stats_aggregate( 7 )
		: array();

	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	return array(
		'site'    => is_string( $host ) ? $host : '',
		'plugin'  => 'lynbro-cookie-consent',
		'version' => LYNBRO_COOKIE_CONSENT_VERSION,
		'stats'   => array(
			'shown'            => isset( $agg['shown'] ) ? (int) $agg['shown'] : 0,
			'accept'           => isset( $agg['accept'] ) ? (int) $agg['accept'] : 0,
			'reject'           => isset( $agg['reject'] ) ? (int) $agg['reject'] : 0,
			'manage_open'      => isset( $agg['manage_open'] ) ? (int) $agg['manage_open'] : 0,
			'lang_switch'      => isset( $agg['lang_switch'] ) ? (int) $agg['lang_switch'] : 0,
			'settings_changed' => isset( $agg['settings_changed'] ) ? (int) $agg['settings_changed'] : 0,
			'avg_tta_ms'       => isset( $agg['avg_tta_ms'] ) ? (int) $agg['avg_tta_ms'] : 0,
			'by_lang'          => isset( $agg['lang'] ) ? (array) $agg['lang'] : array(),
			'by_device'        => isset( $agg['device'] ) ? (array) $agg['device'] : array(),
			'by_os'            => isset( $agg['os'] ) ? (array) $agg['os'] : array(),
			'period_days'      => 7,
		),
	);
}

/**
 * Cron handler: POST the aggregated telemetry and cache the benchmark response.
 *
 * @return void
 */
function lynbro_cookie_consent_telemetry_send() {
	if ( ! lynbro_cookie_consent_telemetry_enabled() ) {
		return;
	}

	$payload = lynbro_cookie_consent_telemetry_payload();

	$response = wp_remote_post(
		LYNBRO_COOKIE_CONSENT_PORTAL . '/v1/telemetry',
		array(
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'        => wp_json_encode( $payload ),
			'user-agent'  => 'LynbroCookieConsent/' . LYNBRO_COOKIE_CONSENT_VERSION . '; ' . home_url( '/' ),
		)
	);

	update_option( 'lynbro_cookie_consent_telemetry_last', time(), false );

	if ( is_wp_error( $response ) ) {
		return;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return;
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || empty( $body['benchmark'] ) || ! is_array( $body['benchmark'] ) ) {
		return;
	}

	$bench = $body['benchmark'];
	$clean = array(
		'accept_rate_avg' => isset( $bench['accept_rate_avg'] ) ? (float) $bench['accept_rate_avg'] : 0.0,
		'reject_rate_avg' => isset( $bench['reject_rate_avg'] ) ? (float) $bench['reject_rate_avg'] : 0.0,
		'sample'          => isset( $bench['sample'] ) ? (int) $bench['sample'] : 0,
		'fetched'         => time(),
	);

	update_option( 'lynbro_cookie_consent_benchmark', $clean, false );
}
add_action( 'lynbro_cookie_consent_telemetry_event', 'lynbro_cookie_consent_telemetry_send' );

/**
 * Get the cached benchmark (or null when none yet).
 *
 * @return array<string,mixed>|null
 */
function lynbro_cookie_consent_get_benchmark() {
	$bench = get_option( 'lynbro_cookie_consent_benchmark', null );
	return is_array( $bench ) ? $bench : null;
}
