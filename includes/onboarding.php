<?php
/**
 * Onboarding & engagement (Wave D, deliberately gentle — no admin spam).
 *
 * Two small, polite touches:
 *  1. A "Settings" link in the plugin row on the Plugins screen.
 *  2. ONE neutral review request ("Enjoying it? A review helps") drawn inside
 *     our own settings page, only after ~7 days of use. No "5 stars" pressure,
 *     no repeated nags, dismissed forever per-user.
 *
 * Nothing is printed through admin notices and activation does not navigate
 * anywhere: the dashboard belongs to the site owner, so everything this plugin
 * has to say is said on its own page.
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
		esc_url( admin_url( 'admin.php?page=lynbro-cookie-consent' ) ),
		esc_html__( 'Settings', 'lynbro-cookie-consent' )
	);
	array_unshift( $links, $settings );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( LYNBRO_COOKIE_CONSENT_FILE ), 'lynbro_cookie_consent_action_links' );

/**
 * Record when the plugin was first activated (used to delay the review panel).
 * Called from the activation hook in the main plugin file.
 *
 * @param bool $network_wide Whether activation is network-wide (unused).
 * @return void
 */
function lynbro_cookie_consent_onboarding_activate( $network_wide = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- kept for the activation hook signature.
	if ( ! get_option( 'lynbro_cookie_consent_activated_at' ) ) {
		add_option( 'lynbro_cookie_consent_activated_at', time(), '', false );
	}
}

/**
 * Send the old Settings → Cookie Consent address to the new one.
 *
 * The page moved into the shared "Lynbro" menu. Bookmarks, support answers and
 * the screenshots in our own readme all still point at options-general.php,
 * where WordPress would answer with "you are not allowed to access this page" —
 * which is both untrue and unhelpful.
 *
 * @return void
 */
function lynbro_cookie_consent_redirect_legacy_settings_url() {
	global $pagenow;

	if ( 'options-general.php' !== $pagenow ) {
		return;
	}
	// Read-only navigation check on a GET address; nothing is changed here.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'lynbro-cookie-consent' !== $page ) {
		return;
	}

	wp_safe_redirect( admin_url( 'admin.php?page=lynbro-cookie-consent' ), 301 );
	exit;
}
add_action( 'admin_init', 'lynbro_cookie_consent_redirect_legacy_settings_url' );

/**
 * Draw the single, delayed, dismissible review request.
 *
 * Runs on the lynbro_cookie_consent_page_notices action, which only our own
 * settings page fires, so it never follows the user around the dashboard.
 * Conditions: admin with manage_options, at least 7 days since activation, and
 * not previously dismissed by this user. No rating widget, no "5 stars".
 *
 * @return void
 */
function lynbro_cookie_consent_review_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
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
	<div class="notice notice-info inline lynbro-cc-review-notice">
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
add_action( 'lynbro_cookie_consent_page_notices', 'lynbro_cookie_consent_review_notice' );

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
