<?php
/**
 * Local consent log — proof of consent (B9).
 *
 * Stores a minimal, privacy-respecting record of each consent decision in a
 * dedicated table on the site's own database. No raw IP address is stored: an
 * anonymised, salted hash of IP+User-Agent is kept only for de-duplication.
 *
 * The record is written via a nonce-protected, capability-free public REST
 * endpoint (visitors are not logged in) that validates and rate-limits input.
 * Admins can view recent records and export the full log as CSV.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Current consent-log schema version (for dbDelta upgrades).
 */
if ( ! defined( 'LYNBRO_COOKIE_CONSENT_LOG_DB_VERSION' ) ) {
	define( 'LYNBRO_COOKIE_CONSENT_LOG_DB_VERSION', '1' );
}

/**
 * Fully-qualified log table name.
 *
 * @return string
 */
function lynbro_cookie_consent_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'lynbro_cookie_consent_log';
}

/**
 * Create or update the consent-log table via dbDelta.
 *
 * Called on activation and when the stored DB version changes.
 *
 * @return void
 */
function lynbro_cookie_consent_log_install() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = lynbro_cookie_consent_log_table();
	$charset_collate = $wpdb->get_charset_collate();

	// dbDelta requires this specific formatting.
	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		consent_id CHAR(36) NOT NULL,
		created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
		policy_version INT UNSIGNED NOT NULL DEFAULT 1,
		categories TEXT NOT NULL,
		method VARCHAR(20) NOT NULL DEFAULT '',
		lang VARCHAR(20) NOT NULL DEFAULT '',
		region VARCHAR(20) NOT NULL DEFAULT '',
		anon_hash CHAR(64) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY consent_id (consent_id),
		KEY created_at (created_at),
		KEY anon_hash (anon_hash)
	) {$charset_collate};";

	dbDelta( $sql );

	update_option( 'lynbro_cookie_consent_log_db_version', LYNBRO_COOKIE_CONSENT_LOG_DB_VERSION );
}

/**
 * Ensure the table exists / is current. Cheap guard run on admin_init.
 *
 * @return void
 */
function lynbro_cookie_consent_log_maybe_upgrade() {
	if ( get_option( 'lynbro_cookie_consent_log_db_version' ) !== LYNBRO_COOKIE_CONSENT_LOG_DB_VERSION ) {
		lynbro_cookie_consent_log_install();
	}
}
add_action( 'admin_init', 'lynbro_cookie_consent_log_maybe_upgrade' );

/**
 * Compute the anonymisation salt (stored once, random).
 *
 * @return string
 */
function lynbro_cookie_consent_log_salt() {
	$salt = get_option( 'lynbro_cookie_consent_log_salt' );
	if ( ! $salt ) {
		$salt = wp_generate_password( 32, false, false );
		add_option( 'lynbro_cookie_consent_log_salt', $salt, '', false );
	}
	return $salt;
}

/**
 * Build an anonymised, salted hash of the visitor (IP + UA). No raw IP stored.
 *
 * @return string 64-char hex hash.
 */
function lynbro_cookie_consent_log_anon_hash() {
	$ip = '';
	if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}
	$ua = '';
	if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
	}
	return hash( 'sha256', lynbro_cookie_consent_log_salt() . '|' . $ip . '|' . $ua );
}

/**
 * Register the REST route used by the front end to record a consent decision.
 *
 * @return void
 */
function lynbro_cookie_consent_log_register_rest() {
	register_rest_route(
		'lynbro-cookie-consent/v1',
		'/consent',
		array(
			'methods'             => 'POST',
			'callback'            => 'lynbro_cookie_consent_log_rest_record',
			'permission_callback' => 'lynbro_cookie_consent_log_rest_permission',
			'args'                => array(
				'consent_id' => array( 'type' => 'string' ),
				'method'     => array( 'type' => 'string' ),
				'categories' => array( 'type' => 'object' ),
				'lang'       => array( 'type' => 'string' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'lynbro_cookie_consent_log_register_rest' );

/**
 * Permission callback: verify the front-end nonce. Visitors are anonymous, so
 * we rely on a nonce (CSRF protection) rather than capability checks.
 *
 * @param WP_REST_Request $request Request.
 * @return bool|WP_Error
 */
function lynbro_cookie_consent_log_rest_permission( $request ) {
	if ( empty( lynbro_cookie_consent_get_option( 'consent_log', 1 ) ) ) {
		return new WP_Error( 'lynbro_cc_log_disabled', __( 'Consent logging is disabled.', 'lynbro-cookie-consent' ), array( 'status' => 403 ) );
	}
	$nonce = $request->get_header( 'X-WP-Nonce' );
	if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_Error( 'lynbro_cc_bad_nonce', __( 'Invalid security token.', 'lynbro-cookie-consent' ), array( 'status' => 403 ) );
	}
	if ( ! lynbro_cookie_consent_log_rate_ok() ) {
		return new WP_Error( 'lynbro_cc_log_rate', __( 'Too many requests.', 'lynbro-cookie-consent' ), array( 'status' => 429 ) );
	}
	return true;
}

/**
 * Coarse, privacy-preserving rate limit for the public consent-log endpoint.
 *
 * Mirrors the stats beacon throttle (30 requests/visitor/minute): uses a
 * short-lived transient keyed by a *salted hash* of the IP — the raw IP is
 * never stored, only hashed in-memory to throttle abuse.
 *
 * @return bool True when the request is within the allowed rate.
 */
function lynbro_cookie_consent_log_rate_ok() {
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$salt = function_exists( 'lynbro_cookie_consent_log_salt' ) ? lynbro_cookie_consent_log_salt() : wp_salt();
	$key  = 'lynbro_cc_lr_' . substr( hash( 'sha256', $salt . '|' . $ip ), 0, 32 );

	$count = (int) get_transient( $key );
	if ( $count >= 30 ) {
		return false;
	}
	set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
	return true;
}

/**
 * REST callback: store a single consent record (validated + sanitized).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function lynbro_cookie_consent_log_rest_record( $request ) {
	global $wpdb;

	$consent_id = sanitize_text_field( (string) $request->get_param( 'consent_id' ) );
	// Accept only UUID-shaped ids; otherwise generate one server-side.
	if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $consent_id ) ) {
		$consent_id = wp_generate_uuid4();
	}

	$method        = sanitize_key( (string) $request->get_param( 'method' ) );
	$allowed_methods = array( 'accept', 'reject', 'custom', 'gpc' );
	if ( ! in_array( $method, $allowed_methods, true ) ) {
		$method = 'custom';
	}

	$raw_cats   = (array) $request->get_param( 'categories' );
	$categories = array();
	foreach ( $raw_cats as $key => $value ) {
		$categories[ sanitize_key( $key ) ] = (bool) $value;
	}

	$lang = sanitize_text_field( (string) $request->get_param( 'lang' ) );
	$lang = substr( $lang, 0, 20 );

	$anon_hash      = lynbro_cookie_consent_log_anon_hash();
	$policy_version = lynbro_cookie_consent_effective_consent_version();
	$region         = function_exists( 'lynbro_cookie_consent_resolve_mode' ) ? lynbro_cookie_consent_resolve_mode() : '';

	// De-duplicate: skip if the same visitor logged the same method+version recently.
	$table  = lynbro_cookie_consent_log_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- de-dup lookup; table name from $wpdb->prefix, all values bound via prepare().
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE anon_hash = %s AND method = %s AND policy_version = %d AND created_at > %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
			$anon_hash,
			$method,
			$policy_version,
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		)
	);

	if ( ! $exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $wpdb->insert() with a typed value array; caching is not applicable to an insert.
		$wpdb->insert(
			$table,
			array(
				'consent_id'     => $consent_id,
				'created_at'     => current_time( 'mysql', true ),
				'policy_version' => $policy_version,
				'categories'     => wp_json_encode( $categories ),
				'method'         => $method,
				'lang'           => $lang,
				'region'         => sanitize_text_field( $region ),
				'anon_hash'      => $anon_hash,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Extension point: forward the consent receipt to an external collector for
	 * centralized, long-retention, auditable storage (ISO/IEC 27560-inspired).
	 * No external request is made by the plugin itself.
	 *
	 * @param array $record The consent record just stored locally.
	 */
	do_action(
		'lynbro_cookie_consent_after_log',
		array(
			'consent_id'     => $consent_id,
			'policy_version' => $policy_version,
			'categories'     => $categories,
			'method'         => $method,
			'lang'           => $lang,
			'region'         => $region,
		)
	);

	return new WP_REST_Response( array( 'logged' => true, 'consent_id' => $consent_id ), 200 );
}

/**
 * Purge log rows older than the configured retention period.
 *
 * @return void
 */
function lynbro_cookie_consent_log_purge() {
	$retention = (int) lynbro_cookie_consent_get_option( 'consent_log_retention', 365 );
	if ( $retention <= 0 ) {
		return; // Keep forever.
	}
	global $wpdb;
	$table  = lynbro_cookie_consent_log_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- scheduled retention purge; table name from $wpdb->prefix, value bound via prepare().
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$cutoff
		)
	);
}
add_action( 'lynbro_cookie_consent_log_purge_event', 'lynbro_cookie_consent_log_purge' );

/**
 * Schedule the daily purge event.
 *
 * @return void
 */
function lynbro_cookie_consent_log_schedule_purge() {
	if ( ! wp_next_scheduled( 'lynbro_cookie_consent_log_purge_event' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lynbro_cookie_consent_log_purge_event' );
	}
}
add_action( 'init', 'lynbro_cookie_consent_log_schedule_purge' );

/**
 * Fetch recent log rows for the admin viewer.
 *
 * @param int $limit  Max rows.
 * @param int $offset Offset.
 * @return array<int,object>
 */
function lynbro_cookie_consent_log_get_rows( $limit = 50, $offset = 0 ) {
	global $wpdb;
	$table  = lynbro_cookie_consent_log_table();
	$limit  = max( 1, (int) $limit );
	$offset = max( 0, (int) $offset );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- admin log-viewer read; table name from $wpdb->prefix, values bound via prepare().
	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$limit,
			$offset
		)
	);
}

/**
 * Count total log rows.
 *
 * @return int
 */
function lynbro_cookie_consent_log_count() {
	global $wpdb;
	$table = lynbro_cookie_consent_log_table();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- count, table name from $wpdb->prefix.
}

/**
 * Handle the CSV export request (admin-post action).
 *
 * @return void
 */
function lynbro_cookie_consent_log_export_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'lynbro-cookie-consent' ) );
	}
	check_admin_referer( 'lynbro_cookie_consent_export_log' );

	global $wpdb;
	$table = lynbro_cookie_consent_log_table();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="consent-log-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$columns = array( 'consent_id', 'created_at', 'policy_version', 'categories', 'method', 'lang', 'region', 'anon_hash' );

	// Header row.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download payload, fields escaped by lynbro_cookie_consent_csv_row().
	echo lynbro_cookie_consent_csv_row( $columns ) . "\r\n";

	// Export every row. To keep memory bounded on large logs, fetch in batches
	// and stream each batch progressively rather than loading the full table.
	$batch  = 2000;
	$offset = 0;
	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- admin full-export read; table name from $wpdb->prefix, values bound via prepare().
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT consent_id, created_at, policy_version, categories, method, lang, region, anon_hash FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$batch,
				$offset
			)
		);

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download payload, fields escaped by lynbro_cookie_consent_csv_row().
			echo lynbro_cookie_consent_csv_row(
				array(
					$row->consent_id,
					$row->created_at,
					$row->policy_version,
					$row->categories,
					$row->method,
					$row->lang,
					$row->region,
					$row->anon_hash,
				)
			) . "\r\n";
		}

		// Flush each batch so memory does not accumulate across the whole table.
		if ( function_exists( 'flush' ) ) {
			flush();
		}

		$offset += $batch;
	} while ( count( $rows ) === $batch );

	exit;
}

/**
 * Build a single CSV row from an array of field values (RFC 4180 quoting).
 *
 * @param array $fields Field values.
 * @return string
 */
function lynbro_cookie_consent_csv_row( $fields ) {
	$escaped = array();
	foreach ( $fields as $field ) {
		$field = (string) $field;
		// Neutralize CSV/spreadsheet formula injection: prefix a single quote
		// when a field begins with a formula trigger ( = + - @ ) or a leading
		// tab/CR that spreadsheets may treat as the start of a formula cell.
		if ( '' !== $field && in_array( $field[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$field = "'" . $field;
		}
		$field     = str_replace( '"', '""', $field );
		$escaped[] = '"' . $field . '"';
	}
	return implode( ',', $escaped );
}
add_action( 'admin_post_lynbro_cookie_consent_export_log', 'lynbro_cookie_consent_log_export_csv' );
