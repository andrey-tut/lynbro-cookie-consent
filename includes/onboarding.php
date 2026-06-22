<?php
/**
 * Onboarding & engagement (Wave D, deliberately gentle — no admin spam).
 *
 * Three small, polite touches:
 *  1. A "Settings" link in the plugin row on the Plugins screen.
 *  2. A one-time redirect to the settings page right after a single activation
 *     (never on bulk or network activation).
 *  3. ONE dismissible, neutral notice ("Enjoying it? A review helps") shown only
 *     after ~7 days of use. No "5 stars" pressure, no repeated nags, dismissed
 *     forever per-user. There are no notices about settings or statistics.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add a "Settings" action link to the plugin row.
 *
 * @param array<int,string> $links Existing action links.
 * @return array<int,string>
 */
function lynbro_cookie_consent_action_links( $links ) {
	$settings = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=lynbro-cookie-consent' ) ),
		esc_html__( 'Settings', 'lynbro-cookie-consent' )
	);
	array_unshift( $links, $settings );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( LYNBRO_COOKIE_CONSENT_FILE ), 'lynbro_cookie_consent_action_links' );

/**
 * Set the one-time "redirect after activation" flag, unless this is a bulk or
 * network activation. Called from the activation hook in the main plugin file.
 *
 * @param bool $network_wide Whether activation is network-wide.
 * @return void
 */
function lynbro_cookie_consent_onboarding_activate( $network_wide = false ) {
	// Record when the plugin was first activated (for the delayed review notice).
	if ( ! get_option( 'lynbro_cookie_consent_activated_at' ) ) {
		add_option( 'lynbro_cookie_consent_activated_at', time(), '', false );
	}

	// Do not redirect on network activation or when activating several plugins at once.
	if ( $network_wide ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core sets this on the bulk-activate screen; read-only check.
	if ( isset( $_REQUEST['action'] ) && 'activate-selected' === sanitize_key( wp_unslash( $_REQUEST['action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core sets this on the bulk-activate screen; read-only check.
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core posts an array of plugins on bulk activation; read-only check.
	if ( isset( $_REQUEST['checked'] ) && is_array( $_REQUEST['checked'] ) && count( $_REQUEST['checked'] ) > 1 ) {
		return;
	}

	set_transient( 'lynbro_cookie_consent_activation_redirect', 1, 30 );
}

/**
 * Perform the one-time post-activation redirect to the settings page.
 *
 * @return void
 */
function lynbro_cookie_consent_maybe_activation_redirect() {
	if ( ! get_transient( 'lynbro_cookie_consent_activation_redirect' ) ) {
		return;
	}
	delete_transient( 'lynbro_cookie_consent_activation_redirect' );

	if ( wp_doing_ajax() || ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Don't hijack multi-plugin activation flows.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only guard against bulk activation.
	if ( isset( $_GET['activate-multi'] ) ) {
		return;
	}

	wp_safe_redirect( admin_url( 'options-general.php?page=lynbro-cookie-consent' ) );
	exit;
}
add_action( 'admin_init', 'lynbro_cookie_consent_maybe_activation_redirect' );

/**
 * Show the single, delayed, dismissible review notice.
 *
 * Conditions: admin with manage_options, on our settings page only (so it never
 * follows the user around), at least 7 days since activation, and not previously
 * dismissed by this user. No rating widget, no "5 stars", no countdown.
 *
 * @return void
 */
function lynbro_cookie_consent_review_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Only on our own settings page.
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'settings_page_lynbro-cookie-consent' !== $screen->id ) {
		return;
	}

	// Already dismissed forever by this user?
	if ( get_user_meta( get_current_user_id(), 'lynbro_cookie_consent_review_dismissed', true ) ) {
		return;
	}

	$activated = (int) get_option( 'lynbro_cookie_consent_activated_at', 0 );
	if ( ! $activated || ( time() - $activated ) < 7 * DAY_IN_SECONDS ) {
		return;
	}

	$dismiss_url = wp_nonce_url(
		add_query_arg( 'lynbro_cc_dismiss_review', '1' ),
		'lynbro_cookie_consent_dismiss_review'
	);
	$support_url = 'https://wordpress.org/support/plugin/lynbro-cookie-consent/reviews/';
	?>
	<div class="notice notice-info is-dismissible lynbro-cc-review-notice">
		<p>
			<?php echo esc_html__( 'Enjoying Lynbro Cookie Consent? A short, honest review helps other site owners find it. No pressure — only if you have a minute.', 'lynbro-cookie-consent' ); ?>
		</p>
		<p>
			<a class="button button-secondary" href="<?php echo esc_url( $support_url ); ?>" target="_blank" rel="noopener noreferrer">
				<?php echo esc_html__( 'Write a review', 'lynbro-cookie-consent' ); ?>
			</a>
			<a class="button-link" href="<?php echo esc_url( $dismiss_url ); ?>">
				<?php echo esc_html__( 'No thanks, don’t show this again', 'lynbro-cookie-consent' ); ?>
			</a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'lynbro_cookie_consent_review_notice' );

/**
 * Persist the "dismiss forever" choice (per user) when the link is clicked.
 *
 * @return void
 */
function lynbro_cookie_consent_dismiss_review() {
	if ( empty( $_GET['lynbro_cc_dismiss_review'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below.
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'lynbro_cookie_consent_dismiss_review' );

	update_user_meta( get_current_user_id(), 'lynbro_cookie_consent_review_dismissed', 1 );

	wp_safe_redirect( remove_query_arg( array( 'lynbro_cc_dismiss_review', '_wpnonce' ) ) );
	exit;
}
add_action( 'admin_init', 'lynbro_cookie_consent_dismiss_review' );
