<?php
/**
 * Language on demand (Wave D, opt-in action).
 *
 * For a locale that is not bundled with the plugin, the owner can click "Request
 * this language" in the Languages tab. The plugin asks the Lynbro portal to
 * generate (or return a cached) translation, then stores the returned .mo file in
 * the site's own uploads directory and loads it via the `load_textdomain_mofile`
 * filter. The plugin NEVER writes into its own /languages directory (which would
 * fail Plugin Check and be wiped on update).
 *
 * Only the plugin slug, version and locale code are sent to the portal — no
 * personal data. Disclosed in the readme "External services" section.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Absolute path to the writable, update-safe languages directory in uploads.
 *
 * @return string Path with a trailing slash, or '' when the uploads dir is unusable.
 */
function lynbro_cookie_consent_i18n_uploads_dir() {
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return '';
	}
	return trailingslashit( $uploads['basedir'] ) . 'lynbro-cookie-consent/languages/';
}

/**
 * Load an on-demand .mo from uploads for our text domain, if one exists.
 *
 * @param string $mofile Path to the .mo file WordPress is about to load.
 * @param string $domain Text domain.
 * @return string Possibly-redirected .mo path.
 */
function lynbro_cookie_consent_i18n_load_mofile( $mofile, $domain ) {
	if ( 'lynbro-cookie-consent' !== $domain ) {
		return $mofile;
	}
	// Only redirect when the bundled file is absent and we have a downloaded one.
	if ( is_readable( $mofile ) ) {
		return $mofile;
	}
	$dir = lynbro_cookie_consent_i18n_uploads_dir();
	if ( '' === $dir ) {
		return $mofile;
	}
	$candidate = $dir . basename( $mofile );
	if ( is_readable( $candidate ) ) {
		return $candidate;
	}
	return $mofile;
}
add_filter( 'load_textdomain_mofile', 'lynbro_cookie_consent_i18n_load_mofile', 10, 2 );

/**
 * List locales we already have on demand (downloaded into uploads).
 *
 * @return string[] Locale codes.
 */
function lynbro_cookie_consent_i18n_downloaded_locales() {
	$dir = lynbro_cookie_consent_i18n_uploads_dir();
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return array();
	}
	$found = glob( $dir . 'lynbro-cookie-consent-*.mo' );
	$out   = array();
	if ( is_array( $found ) ) {
		foreach ( $found as $path ) {
			$locale = lynbro_cookie_consent_sanitize_locale( str_replace( 'lynbro-cookie-consent-', '', basename( $path, '.mo' ) ) );
			if ( '' !== $locale ) {
				$out[] = $locale;
			}
		}
	}
	return $out;
}

/**
 * Handle a "request this language" submission (admin-post action).
 *
 * @return void
 */
function lynbro_cookie_consent_i18n_request() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'lynbro-cookie-consent' ) );
	}
	check_admin_referer( 'lynbro_cookie_consent_request_language' );

	$redirect = add_query_arg(
		array( 'page' => 'lynbro-cookie-consent' ),
		admin_url( 'options-general.php' )
	);

	$locale = isset( $_POST['lynbro_cc_request_locale'] )
		? lynbro_cookie_consent_sanitize_locale( sanitize_text_field( wp_unslash( $_POST['lynbro_cc_request_locale'] ) ) )
		: '';

	if ( '' === $locale ) {
		wp_safe_redirect( add_query_arg( 'lynbro_cc_i18n', 'badlocale', $redirect ) . '#lynbro-cc-tab-languages' );
		exit;
	}

	$result = lynbro_cookie_consent_i18n_call( $locale );
	$status = 'fail';
	if ( 'ready' === $result['status'] ) {
		$status = 'ok';
	} elseif ( 'pending' === $result['status'] ) {
		// The server started generating in the background; poll for it without
		// blocking the admin (a first-time generation can take ~a minute).
		$pending            = lynbro_cookie_consent_i18n_pending();
		$pending[ $locale ] = 0;
		update_option( 'lynbro_cookie_consent_i18n_pending', $pending, false );
		lynbro_cookie_consent_i18n_schedule_poll( (int) $result['retry_after'] );
		$status = 'pending';
	}

	wp_safe_redirect( add_query_arg( 'lynbro_cc_i18n', $status, $redirect ) . '#lynbro-cc-tab-languages' );
	exit;
}
add_action( 'admin_post_lynbro_cookie_consent_request_language', 'lynbro_cookie_consent_i18n_request' );

/**
 * Maximum background poll attempts before giving up on a pending generation.
 */
if ( ! defined( 'LYNBRO_COOKIE_CONSENT_I18N_MAX_POLLS' ) ) {
	define( 'LYNBRO_COOKIE_CONSENT_I18N_MAX_POLLS', 8 );
}

/**
 * Locales whose generation is pending on the server: locale => attempts.
 *
 * @return array<string,int>
 */
function lynbro_cookie_consent_i18n_pending() {
	$pending = get_option( 'lynbro_cookie_consent_i18n_pending', array() );
	return is_array( $pending ) ? $pending : array();
}

/**
 * Schedule a single background poll for pending languages.
 *
 * @param int $delay Seconds to wait before polling.
 * @return void
 */
function lynbro_cookie_consent_i18n_schedule_poll( $delay ) {
	if ( ! wp_next_scheduled( 'lynbro_cookie_consent_i18n_poll_event' ) ) {
		wp_schedule_single_event( time() + max( 20, (int) $delay ), 'lynbro_cookie_consent_i18n_poll_event' );
	}
}

/**
 * Background poll handler: finish any pending language generations.
 *
 * Reschedules itself while work remains; records completed locales in a
 * transient so an admin notice can confirm them.
 *
 * @return void
 */
function lynbro_cookie_consent_i18n_poll() {
	$pending = lynbro_cookie_consent_i18n_pending();
	if ( empty( $pending ) ) {
		return;
	}
	$done  = get_transient( 'lynbro_cookie_consent_i18n_done' );
	$done  = is_array( $done ) ? $done : array();
	$still = false;

	foreach ( $pending as $locale => $attempts ) {
		$result = lynbro_cookie_consent_i18n_call( $locale );
		if ( 'ready' === $result['status'] ) {
			$done[ $locale ] = 'ok';
			unset( $pending[ $locale ] );
		} elseif ( 'pending' === $result['status'] && (int) $attempts < LYNBRO_COOKIE_CONSENT_I18N_MAX_POLLS ) {
			$pending[ $locale ] = (int) $attempts + 1;
			$still              = true;
		} else {
			$done[ $locale ] = 'fail';
			unset( $pending[ $locale ] );
		}
	}

	update_option( 'lynbro_cookie_consent_i18n_pending', $pending, false );
	if ( ! empty( $done ) ) {
		set_transient( 'lynbro_cookie_consent_i18n_done', $done, DAY_IN_SECONDS );
	}
	if ( $still ) {
		wp_schedule_single_event( time() + 60, 'lynbro_cookie_consent_i18n_poll_event' );
	}
}
add_action( 'lynbro_cookie_consent_i18n_poll_event', 'lynbro_cookie_consent_i18n_poll' );

/**
 * Ask the portal for a locale. Returns its status; stores the .mo when ready.
 *
 * The portal answers immediately: "ready" with the compiled .mo (cache hit), or
 * "pending" after kicking off a background generation. A short timeout is safe
 * because the server never blocks — generation is polled, not waited on.
 *
 * @param string $locale Sanitized locale code.
 * @return array{status:string,retry_after:int} status is ready|pending|fail.
 */
function lynbro_cookie_consent_i18n_call( $locale ) {
	$portal = defined( 'LYNBRO_COOKIE_CONSENT_PORTAL' ) ? LYNBRO_COOKIE_CONSENT_PORTAL : 'https://plugins.lynbro.dk';
	$fail   = array( 'status' => 'fail', 'retry_after' => 60 );

	$response = wp_remote_post(
		$portal . '/v1/i18n/translate',
		array(
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'        => wp_json_encode(
				array(
					'plugin'  => 'lynbro-cookie-consent',
					'version' => LYNBRO_COOKIE_CONSENT_VERSION,
					'locale'  => $locale,
				)
			),
			'user-agent'  => 'LynbroCookieConsent/' . LYNBRO_COOKIE_CONSENT_VERSION . '; ' . home_url( '/' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $fail;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return $fail;
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) ) {
		return $fail;
	}

	$status      = isset( $body['status'] ) ? sanitize_key( (string) $body['status'] ) : 'ready';
	$retry_after = isset( $body['retry_after'] ) ? absint( $body['retry_after'] ) : 60;

	if ( 'pending' === $status ) {
		return array( 'status' => 'pending', 'retry_after' => $retry_after );
	}

	// "ready": validate and store the compiled .mo.
	if ( empty( $body['data_base64'] ) || 'mo' !== ( isset( $body['format'] ) ? $body['format'] : '' ) ) {
		return $fail;
	}
	// Decode the compiled .mo returned by our portal (transport encoding, not
	// obfuscation). Validated as a real gettext .mo by magic number below.
	$binary = base64_decode( (string) $body['data_base64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64 is the JSON transport encoding for the .mo file; content is magic-number validated.
	if ( false === $binary || '' === $binary || ! lynbro_cookie_consent_i18n_is_mo( $binary ) ) {
		return $fail;
	}
	if ( ! lynbro_cookie_consent_i18n_store_mo( $locale, $binary ) ) {
		return $fail;
	}
	return array( 'status' => 'ready', 'retry_after' => 0 );
}

/**
 * Quick magic-number check for a GNU gettext .mo file.
 *
 * @param string $binary File contents.
 * @return bool
 */
function lynbro_cookie_consent_i18n_is_mo( $binary ) {
	if ( strlen( $binary ) < 4 ) {
		return false;
	}
	$magic = substr( $binary, 0, 4 );
	// 0x950412de (LE) or 0xde120495 (BE).
	return ( "\xde\x12\x04\x95" === $magic || "\x95\x04\x12\xde" === $magic );
}

/**
 * Write the .mo into the uploads languages directory using WP_Filesystem.
 *
 * @param string $locale Sanitized locale.
 * @param string $binary .mo contents.
 * @return bool
 */
function lynbro_cookie_consent_i18n_store_mo( $locale, $binary ) {
	global $wp_filesystem;

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! WP_Filesystem() ) {
		return false;
	}

	$dir = lynbro_cookie_consent_i18n_uploads_dir();
	if ( '' === $dir ) {
		return false;
	}

	if ( ! $wp_filesystem->is_dir( $dir ) ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// Drop a basic index file so the directory can't be browsed.
		$wp_filesystem->put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
	}

	$file = $dir . 'lynbro-cookie-consent-' . $locale . '.mo';
	if ( ! $wp_filesystem->put_contents( $file, $binary, FS_CHMOD_FILE ) ) {
		return false;
	}

	// New translation available — drop the cached JS translation map.
	if ( function_exists( 'lynbro_cookie_consent_i18n_flush_js_cache' ) ) {
		lynbro_cookie_consent_i18n_flush_js_cache();
	}

	/**
	 * Extension point fired after a translation is downloaded (e.g. for add-ons
	 * that pin/edit it or sync it across a multisite network).
	 *
	 * @param string $locale Locale that was just downloaded.
	 */
	do_action( 'lynbro_cookie_consent_i18n_downloaded', $locale );

	return true;
}

/**
 * Make the on-demand directory readable by the multilingual engine so its .mo
 * files are also picked up for the cache-safe banner translations object.
 *
 * @param array<string,string> $locales Existing code => label map.
 * @return array<string,string>
 */
function lynbro_cookie_consent_i18n_register_locales( $locales ) {
	foreach ( lynbro_cookie_consent_i18n_downloaded_locales() as $locale ) {
		if ( ! isset( $locales[ $locale ] ) ) {
			$locales[ $locale ] = lynbro_cookie_consent_locale_label( $locale );
		}
	}
	return $locales;
}
add_filter( 'lynbro_cookie_consent_available_locales', 'lynbro_cookie_consent_i18n_register_locales' );

/**
 * Best-effort flush of the multilingual engine's cached JS translation map.
 *
 * @return void
 */
function lynbro_cookie_consent_i18n_flush_js_cache() {
	// The map self-invalidates by signature, but bump nothing here directly; the
	// next request recomputes once the new .mo is in available_locales. This stub
	// exists so add-ons can hook a more aggressive purge.
	do_action( 'lynbro_cookie_consent_i18n_cache_flush' );
}

/**
 * Show an admin notice after a language request.
 *
 * @return void
 */
function lynbro_cookie_consent_i18n_notice() {
	if ( empty( $_GET['lynbro_cc_i18n'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag.
		return;
	}
	$status   = sanitize_key( wp_unslash( $_GET['lynbro_cc_i18n'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$messages = array(
		'ok'        => array( 'success', __( 'Language downloaded and activated.', 'lynbro-cookie-consent' ) ),
		'pending'   => array( 'info', __( 'Preparing your language… This can take about a minute the first time. It will be added automatically — you can keep working.', 'lynbro-cookie-consent' ) ),
		'fail'      => array( 'error', __( 'Sorry, that language could not be fetched right now. Please try again later.', 'lynbro-cookie-consent' ) ),
		'badlocale' => array( 'error', __( 'Please choose a valid language code.', 'lynbro-cookie-consent' ) ),
	);
	if ( ! isset( $messages[ $status ] ) ) {
		return;
	}
	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $messages[ $status ][0] ),
		esc_html( $messages[ $status ][1] )
	);
}
add_action( 'admin_notices', 'lynbro_cookie_consent_i18n_notice' );

/**
 * Announce languages whose background generation just finished.
 *
 * @return void
 */
function lynbro_cookie_consent_i18n_done_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$done = get_transient( 'lynbro_cookie_consent_i18n_done' );
	if ( ! is_array( $done ) || empty( $done ) ) {
		return;
	}
	delete_transient( 'lynbro_cookie_consent_i18n_done' );

	$ready  = array();
	$failed = array();
	foreach ( $done as $locale => $state ) {
		$label = lynbro_cookie_consent_locale_label( $locale );
		if ( 'ok' === $state ) {
			$ready[] = $label;
		} else {
			$failed[] = $label;
		}
	}
	if ( ! empty( $ready ) ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of language names. */
					__( 'Language ready and activated: %s', 'lynbro-cookie-consent' ),
					implode( ', ', $ready )
				)
			)
		);
	}
	if ( ! empty( $failed ) ) {
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of language names. */
					__( 'Could not prepare: %s. Please try again later.', 'lynbro-cookie-consent' ),
					implode( ', ', $failed )
				)
			)
		);
	}
}
add_action( 'admin_notices', 'lynbro_cookie_consent_i18n_done_notice' );
