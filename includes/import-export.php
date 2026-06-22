<?php
/**
 * Settings import / export as JSON (B11).
 *
 * Lets agencies move a configuration between sites. Export streams a JSON file;
 * import accepts an uploaded JSON file, validates it through the same sanitize
 * callback as the settings form, and saves it. Both actions require the
 * `manage_options` capability and a valid nonce.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handle the JSON export (admin-post action).
 *
 * @return void
 */
function lynbro_cookie_consent_export_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'lynbro-cookie-consent' ) );
	}
	check_admin_referer( 'lynbro_cookie_consent_export_settings' );

	$payload = array(
		'plugin'   => 'lynbro-cookie-consent',
		'version'  => LYNBRO_COOKIE_CONSENT_VERSION,
		'exported' => gmdate( 'c' ),
		'settings' => lynbro_cookie_consent_get_options(),
	);

	$json = wp_json_encode( $payload, JSON_PRETTY_PRINT );

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="lynbro-cookie-consent-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
	echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download payload.
	exit;
}
add_action( 'admin_post_lynbro_cookie_consent_export_settings', 'lynbro_cookie_consent_export_settings' );

/**
 * Handle the JSON import (admin-post action).
 *
 * @return void
 */
function lynbro_cookie_consent_import_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'lynbro-cookie-consent' ) );
	}
	check_admin_referer( 'lynbro_cookie_consent_import_settings' );

	$redirect = add_query_arg(
		array( 'page' => 'lynbro-cookie-consent' ),
		admin_url( 'options-general.php' )
	);

	if ( empty( $_FILES['lynbro_cc_import_file']['tmp_name'] ) || ! is_uploaded_file( sanitize_text_field( wp_unslash( $_FILES['lynbro_cc_import_file']['tmp_name'] ) ) ) ) {
		wp_safe_redirect( add_query_arg( 'lynbro_cc_import', 'nofile', $redirect ) );
		exit;
	}

	// Validate the uploaded file type.
	$file = $_FILES['lynbro_cc_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- individual members sanitized below.
	$check = wp_check_filetype( sanitize_file_name( wp_unslash( $file['name'] ) ) );
	if ( 'json' !== $check['ext'] ) {
		wp_safe_redirect( add_query_arg( 'lynbro_cc_import', 'badtype', $redirect ) );
		exit;
	}

	$contents = file_get_contents( sanitize_text_field( wp_unslash( $file['tmp_name'] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a freshly uploaded local temp file.
	$data     = json_decode( (string) $contents, true );

	if ( ! is_array( $data ) || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
		wp_safe_redirect( add_query_arg( 'lynbro_cc_import', 'invalid', $redirect ) );
		exit;
	}

	// Run the import through the same sanitize routine as the settings form.
	$clean = lynbro_cookie_consent_sanitize_options( $data['settings'] );
	update_option( LYNBRO_COOKIE_CONSENT_OPTION, $clean );

	wp_safe_redirect( add_query_arg( 'lynbro_cc_import', 'ok', $redirect ) );
	exit;
}
add_action( 'admin_post_lynbro_cookie_consent_import_settings', 'lynbro_cookie_consent_import_settings' );

/**
 * Show an admin notice after an import attempt.
 *
 * @return void
 */
function lynbro_cookie_consent_import_notice() {
	if ( empty( $_GET['lynbro_cc_import'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag, no state change.
		return;
	}
	$status = sanitize_key( wp_unslash( $_GET['lynbro_cc_import'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$messages = array(
		'ok'      => array( 'success', __( 'Settings imported successfully.', 'lynbro-cookie-consent' ) ),
		'nofile'  => array( 'error', __( 'No file was uploaded.', 'lynbro-cookie-consent' ) ),
		'badtype' => array( 'error', __( 'Please upload a .json settings file.', 'lynbro-cookie-consent' ) ),
		'invalid' => array( 'error', __( 'The file does not contain valid Lynbro Cookie Consent settings.', 'lynbro-cookie-consent' ) ),
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
add_action( 'admin_notices', 'lynbro_cookie_consent_import_notice' );
