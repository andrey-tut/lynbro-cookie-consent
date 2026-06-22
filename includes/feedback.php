<?php
/**
 * Feedback / feature requests (Wave D, opt-in action).
 *
 * An admin-only form that — when the owner submits it — sends a short message to
 * the Lynbro portal so we can prioritise improvements and new ideas. It is never
 * sent automatically: the owner types and clicks "Send". The endpoint and what
 * is sent are disclosed in the readme "External services" section.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allowed feedback categories.
 *
 * @return array<string,string> key => translatable label.
 */
function lynbro_cookie_consent_feedback_categories() {
	return array(
		'this-plugin' => __( 'Feedback on this plugin', 'lynbro-cookie-consent' ),
		'new-idea'    => __( 'Idea for a new feature or plugin', 'lynbro-cookie-consent' ),
		'bug'         => __( 'Report a bug', 'lynbro-cookie-consent' ),
	);
}

/**
 * Handle the feedback submission (admin-post action).
 *
 * @return void
 */
function lynbro_cookie_consent_feedback_submit() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'lynbro-cookie-consent' ) );
	}
	check_admin_referer( 'lynbro_cookie_consent_feedback' );

	$redirect = add_query_arg(
		array( 'page' => 'lynbro-cookie-consent' ),
		admin_url( 'options-general.php' )
	);

	$categories = lynbro_cookie_consent_feedback_categories();
	$category   = isset( $_POST['lynbro_cc_feedback_category'] )
		? sanitize_key( wp_unslash( $_POST['lynbro_cc_feedback_category'] ) )
		: '';
	if ( ! isset( $categories[ $category ] ) ) {
		$category = 'this-plugin';
	}

	$message = isset( $_POST['lynbro_cc_feedback_message'] )
		? sanitize_textarea_field( wp_unslash( $_POST['lynbro_cc_feedback_message'] ) )
		: '';
	$message = trim( $message );

	if ( '' === $message ) {
		wp_safe_redirect( add_query_arg( 'lynbro_cc_feedback', 'empty', $redirect ) . '#lynbro-cc-tab-feedback' );
		exit;
	}
	// Keep the message reasonable.
	if ( strlen( $message ) > 5000 ) {
		$message = substr( $message, 0, 5000 );
	}

	$email = '';
	if ( ! empty( $_POST['lynbro_cc_feedback_email'] ) ) {
		$email = sanitize_email( wp_unslash( $_POST['lynbro_cc_feedback_email'] ) );
		if ( ! is_email( $email ) ) {
			$email = '';
		}
	}

	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	$payload = array(
		'site'     => is_string( $host ) ? $host : '',
		'plugin'   => 'lynbro-cookie-consent',
		'version'  => LYNBRO_COOKIE_CONSENT_VERSION,
		'category' => $category,
		'message'  => $message,
	);
	if ( '' !== $email ) {
		$payload['email'] = $email;
	}

	$portal = defined( 'LYNBRO_COOKIE_CONSENT_PORTAL' ) ? LYNBRO_COOKIE_CONSENT_PORTAL : 'https://plugins.lynbro.dk';

	$response = wp_remote_post(
		$portal . '/v1/feedback',
		array(
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'        => wp_json_encode( $payload ),
			'user-agent'  => 'LynbroCookieConsent/' . LYNBRO_COOKIE_CONSENT_VERSION . '; ' . home_url( '/' ),
		)
	);

	$status = 'fail';
	if ( ! is_wp_error( $response ) ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			$status = 'ok';
		}
	}

	wp_safe_redirect( add_query_arg( 'lynbro_cc_feedback', $status, $redirect ) . '#lynbro-cc-tab-feedback' );
	exit;
}
add_action( 'admin_post_lynbro_cookie_consent_feedback', 'lynbro_cookie_consent_feedback_submit' );

/**
 * Show an admin notice after a feedback submission attempt.
 *
 * @return void
 */
function lynbro_cookie_consent_feedback_notice() {
	if ( empty( $_GET['lynbro_cc_feedback'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag.
		return;
	}
	$status   = sanitize_key( wp_unslash( $_GET['lynbro_cc_feedback'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$messages = array(
		'ok'    => array( 'success', __( 'Thank you! Your message was sent.', 'lynbro-cookie-consent' ) ),
		'empty' => array( 'error', __( 'Please enter a message before sending.', 'lynbro-cookie-consent' ) ),
		'fail'  => array( 'error', __( 'Sorry, the message could not be sent. Please try again later.', 'lynbro-cookie-consent' ) ),
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
add_action( 'admin_notices', 'lynbro_cookie_consent_feedback_notice' );
