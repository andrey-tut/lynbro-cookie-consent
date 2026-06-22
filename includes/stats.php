<?php
/**
 * Local, cookieless, privacy-first statistics (Wave D, free).
 *
 * Records aggregated counters only — never per-visitor rows, never an IP or any
 * other personal data. The front end fires a non-blocking `navigator.sendBeacon`
 * to a public REST endpoint; the server parses the User-Agent locally (on the
 * beacon request) into coarse device/OS buckets and increments daily counters in
 * a dedicated table. Everything is aggregated, so it is GDPR-safe and does not by
 * itself require consent.
 *
 * Events: shown, accept, reject, manage_open, lang_switch, settings_changed.
 * Distributions: browser language (top-N + other), device class, OS family.
 * Time-to-action: a running sum + count, so the admin can show an average.
 *
 * The aggregated statistics are stored locally and the in-dashboard view is
 * always available to the site owner. A do_action extension point lets add-ons
 * forward the already-aggregated, non-personal counters to an external service.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Current statistics-table schema version (for dbDelta upgrades).
 */
if ( ! defined( 'LYNBRO_COOKIE_CONSENT_STATS_DB_VERSION' ) ) {
	define( 'LYNBRO_COOKIE_CONSENT_STATS_DB_VERSION', '1' );
}

/**
 * Number of distinct browser-language buckets to keep before folding the rest
 * into an "other" bucket.
 */
if ( ! defined( 'LYNBRO_COOKIE_CONSENT_STATS_LANG_TOPN' ) ) {
	define( 'LYNBRO_COOKIE_CONSENT_STATS_LANG_TOPN', 12 );
}

/**
 * Fully-qualified statistics table name.
 *
 * @return string
 */
function lynbro_cookie_consent_stats_table() {
	global $wpdb;
	return $wpdb->prefix . 'lynbro_cookie_consent_stats';
}

/**
 * Create or update the statistics table via dbDelta.
 *
 * One row per UTC day holds every aggregated counter as a JSON blob in `data`,
 * plus the discrete event columns most useful for fast period sums. Keeping the
 * distributions (lang/device/os) in JSON avoids an ever-growing column list.
 *
 * @return void
 */
function lynbro_cookie_consent_stats_install() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = lynbro_cookie_consent_stats_table();
	$charset_collate = $wpdb->get_charset_collate();

	// dbDelta requires this specific formatting (two spaces before PRIMARY KEY).
	$sql = "CREATE TABLE {$table} (
		stat_date DATE NOT NULL,
		shown BIGINT UNSIGNED NOT NULL DEFAULT 0,
		accept BIGINT UNSIGNED NOT NULL DEFAULT 0,
		reject BIGINT UNSIGNED NOT NULL DEFAULT 0,
		manage_open BIGINT UNSIGNED NOT NULL DEFAULT 0,
		lang_switch BIGINT UNSIGNED NOT NULL DEFAULT 0,
		settings_changed BIGINT UNSIGNED NOT NULL DEFAULT 0,
		tta_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
		tta_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
		data LONGTEXT NOT NULL,
		PRIMARY KEY  (stat_date)
	) {$charset_collate};";

	dbDelta( $sql );

	update_option( 'lynbro_cookie_consent_stats_db_version', LYNBRO_COOKIE_CONSENT_STATS_DB_VERSION );
}

/**
 * Ensure the stats table exists / is current. Cheap guard run on admin_init.
 *
 * @return void
 */
function lynbro_cookie_consent_stats_maybe_upgrade() {
	if ( get_option( 'lynbro_cookie_consent_stats_db_version' ) !== LYNBRO_COOKIE_CONSENT_STATS_DB_VERSION ) {
		lynbro_cookie_consent_stats_install();
	}
}
add_action( 'admin_init', 'lynbro_cookie_consent_stats_maybe_upgrade' );

/**
 * Are anonymous statistics enabled? (Default on — aggregated, not personal data.)
 *
 * @return bool
 */
function lynbro_cookie_consent_stats_enabled() {
	return ! empty( lynbro_cookie_consent_get_option( 'stats_enabled', 1 ) );
}

/**
 * Known event keys that map to dedicated counter columns.
 *
 * @return string[]
 */
function lynbro_cookie_consent_stats_event_keys() {
	return array( 'shown', 'accept', 'reject', 'manage_open', 'lang_switch', 'settings_changed' );
}

/**
 * Register the public REST beacon route used to record one aggregated event.
 *
 * @return void
 */
function lynbro_cookie_consent_stats_register_rest() {
	register_rest_route(
		'lynbro-cc/v1',
		'/stat',
		array(
			'methods'             => 'POST',
			'callback'            => 'lynbro_cookie_consent_stats_rest_record',
			'permission_callback' => 'lynbro_cookie_consent_stats_rest_permission',
			'args'                => array(
				'event' => array( 'type' => 'string' ),
				'lang'  => array( 'type' => 'string' ),
				'tta'   => array( 'type' => 'integer' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'lynbro_cookie_consent_stats_register_rest' );

/**
 * Permission callback for the beacon: public (visitors are anonymous) but
 * protected by a light nonce (CSRF) and a per-window rate limit. Only allowed
 * when statistics are enabled.
 *
 * @param WP_REST_Request $request Request.
 * @return bool|WP_Error
 */
function lynbro_cookie_consent_stats_rest_permission( $request ) {
	if ( ! lynbro_cookie_consent_stats_enabled() ) {
		return new WP_Error( 'lynbro_cc_stats_disabled', __( 'Statistics are disabled.', 'lynbro-cookie-consent' ), array( 'status' => 403 ) );
	}

	// sendBeacon cannot set custom headers, so accept the light nonce from the
	// header or the _wpnonce query/body parameter (both are the wp_rest nonce).
	$nonce = $request->get_header( 'X-WP-Nonce' );
	if ( ! $nonce ) {
		$nonce = sanitize_text_field( (string) $request->get_param( '_wpnonce' ) );
	}
	if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_Error( 'lynbro_cc_stats_bad_nonce', __( 'Invalid security token.', 'lynbro-cookie-consent' ), array( 'status' => 403 ) );
	}

	if ( ! lynbro_cookie_consent_stats_rate_ok() ) {
		return new WP_Error( 'lynbro_cc_stats_rate', __( 'Too many requests.', 'lynbro-cookie-consent' ), array( 'status' => 429 ) );
	}

	return true;
}

/**
 * Coarse, privacy-preserving rate limit for the beacon endpoint.
 *
 * Uses a short-lived transient keyed by a *salted hash* of the IP — the IP is
 * never stored, only hashed in-memory to throttle abuse. Caps the number of
 * beacons accepted per visitor per minute.
 *
 * @return bool True when the request is within the allowed rate.
 */
function lynbro_cookie_consent_stats_rate_ok() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$salt = function_exists( 'lynbro_cookie_consent_log_salt' ) ? lynbro_cookie_consent_log_salt() : wp_salt();
	$key  = 'lynbro_cc_sr_' . substr( hash( 'sha256', $salt . '|' . $ip ), 0, 32 );

	$count = (int) get_transient( $key );
	if ( $count >= 30 ) {
		return false;
	}
	set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
	return true;
}

/**
 * REST callback: validate the beacon and increment the relevant counters.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function lynbro_cookie_consent_stats_rest_record( $request ) {
	$event = sanitize_key( (string) $request->get_param( 'event' ) );
	if ( ! in_array( $event, lynbro_cookie_consent_stats_event_keys(), true ) ) {
		return new WP_REST_Response( array( 'ok' => false ), 200 );
	}

	// Browser language: trust only a short, well-formed primary subtag.
	$lang = strtolower( sanitize_text_field( (string) $request->get_param( 'lang' ) ) );
	$lang = preg_replace( '/[^a-z\-]/', '', $lang );
	$lang = (string) strtok( (string) $lang, '-' );
	if ( '' === $lang || strlen( $lang ) > 8 ) {
		$lang = '';
	}

	// Time-to-action in milliseconds (only meaningful for action events). Bounded.
	$tta = (int) $request->get_param( 'tta' );
	if ( $tta < 0 || $tta > 600000 ) {
		$tta = 0;
	}

	// Parse the User-Agent server-side into coarse buckets (no UA is stored).
	$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	$device = lynbro_cookie_consent_stats_device_class( $ua );
	$os     = lynbro_cookie_consent_stats_os_family( $ua );

	lynbro_cookie_consent_stats_increment( $event, $lang, $device, $os, $tta );

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/**
 * Classify a User-Agent string into desktop|tablet|mobile (best effort, local).
 *
 * @param string $ua User-Agent header.
 * @return string
 */
function lynbro_cookie_consent_stats_device_class( $ua ) {
	$ua = strtolower( $ua );
	if ( '' === $ua ) {
		return 'desktop';
	}
	// iPad reports as "Macintosh" on iPadOS Safari; the "Mobile" token disambiguates.
	if ( false !== strpos( $ua, 'ipad' ) || ( false !== strpos( $ua, 'tablet' ) ) || ( false !== strpos( $ua, 'android' ) && false === strpos( $ua, 'mobile' ) ) ) {
		return 'tablet';
	}
	if ( false !== strpos( $ua, 'mobi' ) || false !== strpos( $ua, 'iphone' ) || false !== strpos( $ua, 'ipod' ) ) {
		return 'mobile';
	}
	return 'desktop';
}

/**
 * Classify a User-Agent string into an OS family (windows|macos|linux|android|ios|other).
 *
 * @param string $ua User-Agent header.
 * @return string
 */
function lynbro_cookie_consent_stats_os_family( $ua ) {
	$ua = strtolower( $ua );
	if ( false !== strpos( $ua, 'android' ) ) {
		return 'android';
	}
	if ( false !== strpos( $ua, 'iphone' ) || false !== strpos( $ua, 'ipad' ) || false !== strpos( $ua, 'ipod' ) ) {
		return 'ios';
	}
	if ( false !== strpos( $ua, 'windows' ) ) {
		return 'windows';
	}
	if ( false !== strpos( $ua, 'mac os' ) || false !== strpos( $ua, 'macintosh' ) ) {
		return 'macos';
	}
	if ( false !== strpos( $ua, 'linux' ) || false !== strpos( $ua, 'cros' ) || false !== strpos( $ua, 'x11' ) ) {
		return 'linux';
	}
	return 'other';
}

/**
 * Increment the counters for today's row (UTC), creating it if needed.
 *
 * Distributions (lang/device/os) live as a JSON map in the `data` column. The
 * write is atomic-ish: an INSERT ... ON DUPLICATE KEY UPDATE for the scalar
 * counters, then a read-modify-write of the JSON distributions. All values are
 * integers or whitelisted keys, and every query uses $wpdb->prepare().
 *
 * @param string $event  One of the known event keys.
 * @param string $lang   Primary browser language subtag (already sanitized) or ''.
 * @param string $device desktop|tablet|mobile.
 * @param string $os     OS family bucket.
 * @param int    $tta    Time-to-action in ms (0 when not applicable).
 * @return void
 */
function lynbro_cookie_consent_stats_increment( $event, $lang, $device, $os, $tta ) {
	global $wpdb;

	$table = lynbro_cookie_consent_stats_table();
	$date  = gmdate( 'Y-m-d' );

	// Only count time-to-action for explicit visitor actions, not for "shown".
	$action_events = array( 'accept', 'reject', 'manage_open', 'lang_switch', 'settings_changed' );
	$is_action     = in_array( $event, $action_events, true );
	$tta_sum       = ( $is_action && $tta > 0 ) ? $tta : 0;
	$tta_count     = ( $is_action && $tta > 0 ) ? 1 : 0;

	// Whitelist the event column name (never interpolate raw input into SQL).
	$valid_columns = lynbro_cookie_consent_stats_event_keys();
	if ( ! in_array( $event, $valid_columns, true ) ) {
		return;
	}

	// Scalar counters: upsert. The column is a fixed, whitelisted identifier.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; {$event} is a whitelisted column identifier validated against lynbro_cookie_consent_stats_event_keys() above.
	$sql = $wpdb->prepare(
		"INSERT INTO {$table} (stat_date, {$event}, tta_sum, tta_count, data)
			VALUES (%s, 1, %d, %d, %s)
			ON DUPLICATE KEY UPDATE
				{$event} = {$event} + 1,
				tta_sum = tta_sum + %d,
				tta_count = tta_count + %d",
		$date,
		$tta_sum,
		$tta_count,
		wp_json_encode( array() ),
		$tta_sum,
		$tta_count
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql built with $wpdb->prepare(); aggregated counter upsert, no per-row caching.

	// Distributions: read-modify-write the JSON map.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- aggregated counter row, freshly written above; table name from $wpdb->prefix.
	$row = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT data FROM {$table} WHERE stat_date = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$date
		)
	);

	$data = json_decode( (string) $row, true );
	if ( ! is_array( $data ) ) {
		$data = array();
	}
	$data['lang']   = isset( $data['lang'] ) && is_array( $data['lang'] ) ? $data['lang'] : array();
	$data['device'] = isset( $data['device'] ) && is_array( $data['device'] ) ? $data['device'] : array();
	$data['os']     = isset( $data['os'] ) && is_array( $data['os'] ) ? $data['os'] : array();

	// Count device/OS on the "shown" event (one per new visitor), so the mix
	// reflects the audience rather than only those who interact.
	if ( 'shown' === $event ) {
		$dev = in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ? $device : 'desktop';
		$osk = in_array( $os, array( 'windows', 'macos', 'linux', 'android', 'ios', 'other' ), true ) ? $os : 'other';
		$data['device'][ $dev ] = isset( $data['device'][ $dev ] ) ? (int) $data['device'][ $dev ] + 1 : 1;
		$data['os'][ $osk ]     = isset( $data['os'][ $osk ] ) ? (int) $data['os'][ $osk ] + 1 : 1;

		if ( '' !== $lang ) {
			$data['lang'][ $lang ] = isset( $data['lang'][ $lang ] ) ? (int) $data['lang'][ $lang ] + 1 : 1;
			// Keep the language map bounded to avoid unbounded growth.
			if ( count( $data['lang'] ) > 60 ) {
				arsort( $data['lang'] );
				$data['lang'] = array_slice( $data['lang'], 0, 60, true );
			}
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregated counter update.
	$wpdb->update(
		$table,
		array( 'data' => wp_json_encode( $data ) ),
		array( 'stat_date' => $date ),
		array( '%s' ),
		array( '%s' )
	);

	/**
	 * Extension point: forward the already-aggregated event to an external
	 * collector for real-time trends. The plugin itself makes no such request;
	 * the payload carries no personal data.
	 *
	 * @param string $event  Event key.
	 * @param string $device Device class.
	 * @param string $os     OS family.
	 */
	do_action( 'lynbro_cookie_consent_after_stat', $event, $device, $os );
}

/**
 * Aggregate counters over a number of trailing days (UTC), inclusive of today.
 *
 * @param int $days Number of days to include (1 = today only).
 * @return array<string,mixed> Aggregated totals + distributions.
 */
function lynbro_cookie_consent_stats_aggregate( $days ) {
	global $wpdb;

	$days  = max( 1, (int) $days );
	$table = lynbro_cookie_consent_stats_table();
	$from  = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );

	$cache_key = 'lynbro_cc_stats_agg_' . $days;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- aggregated read, cached in a transient below; table name from $wpdb->prefix.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE stat_date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$from
		)
	);

	$out = array(
		'shown'            => 0,
		'accept'           => 0,
		'reject'           => 0,
		'manage_open'      => 0,
		'lang_switch'      => 0,
		'settings_changed' => 0,
		'tta_sum'          => 0,
		'tta_count'        => 0,
		'lang'             => array(),
		'device'           => array(),
		'os'               => array(),
	);

	foreach ( $rows as $row ) {
		$out['shown']            += (int) $row->shown;
		$out['accept']           += (int) $row->accept;
		$out['reject']           += (int) $row->reject;
		$out['manage_open']      += (int) $row->manage_open;
		$out['lang_switch']      += (int) $row->lang_switch;
		$out['settings_changed'] += (int) $row->settings_changed;
		$out['tta_sum']          += (int) $row->tta_sum;
		$out['tta_count']        += (int) $row->tta_count;

		$data = json_decode( (string) $row->data, true );
		if ( ! is_array( $data ) ) {
			continue;
		}
		foreach ( array( 'lang', 'device', 'os' ) as $dist ) {
			if ( empty( $data[ $dist ] ) || ! is_array( $data[ $dist ] ) ) {
				continue;
			}
			foreach ( $data[ $dist ] as $k => $v ) {
				$k             = sanitize_key( (string) $k );
				$out[ $dist ][ $k ] = isset( $out[ $dist ][ $k ] ) ? $out[ $dist ][ $k ] + (int) $v : (int) $v;
			}
		}
	}

	// Fold the language distribution to top-N + "other".
	$out['lang'] = lynbro_cookie_consent_stats_top_n( $out['lang'], LYNBRO_COOKIE_CONSENT_STATS_LANG_TOPN );

	arsort( $out['device'] );
	arsort( $out['os'] );

	$out['avg_tta_ms'] = $out['tta_count'] > 0 ? (int) round( $out['tta_sum'] / $out['tta_count'] ) : 0;

	// Cache briefly so repeated admin views / telemetry don't re-scan every time.
	set_transient( $cache_key, $out, 5 * MINUTE_IN_SECONDS );

	return $out;
}

/**
 * Reduce a distribution map to its top N keys, summing the remainder into "other".
 *
 * @param array<string,int> $map Distribution map.
 * @param int               $n   Number of buckets to keep.
 * @return array<string,int>
 */
function lynbro_cookie_consent_stats_top_n( $map, $n ) {
	if ( ! is_array( $map ) || empty( $map ) ) {
		return array();
	}
	arsort( $map );
	if ( count( $map ) <= $n ) {
		return $map;
	}
	$top   = array_slice( $map, 0, $n, true );
	$rest  = array_slice( $map, $n, null, true );
	$other = 0;
	foreach ( $rest as $v ) {
		$other += (int) $v;
	}
	if ( $other > 0 ) {
		$top['other'] = isset( $top['other'] ) ? $top['other'] + $other : $other;
	}
	return $top;
}

/**
 * Invalidate the short-lived aggregate caches (e.g. after telemetry resets).
 *
 * @return void
 */
function lynbro_cookie_consent_stats_flush_cache() {
	foreach ( array( 1, 2, 7, 30, 365 ) as $d ) {
		delete_transient( 'lynbro_cc_stats_agg_' . $d );
	}
}

/**
 * Record a settings change so the owner can see how often the policy/config
 * shifts. Aggregated, one counter, no payload.
 *
 * @return void
 */
function lynbro_cookie_consent_stats_on_settings_update() {
	if ( ! lynbro_cookie_consent_stats_enabled() ) {
		return;
	}
	lynbro_cookie_consent_stats_increment( 'settings_changed', '', '', '', 0 );
	lynbro_cookie_consent_stats_flush_cache();
}
add_action( 'update_option_' . LYNBRO_COOKIE_CONSENT_OPTION, 'lynbro_cookie_consent_stats_on_settings_update', 10, 0 );
