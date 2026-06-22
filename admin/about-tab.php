<?php
/**
 * Admin — About tab renderer.
 *
 * A modest "about the author" panel: who makes the plugin, a few useful links
 * (all target=_blank rel="noopener noreferrer") and a version / updates note.
 * No tracking, no upsell spam — just author info and support links.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the About tab content.
 *
 * @return void
 */
function lynbro_cookie_consent_render_about_tab() {
	$plugin_url    = 'https://plugins.lynbro.dk/lynbro-cookie-consent';
	$company_url   = 'https://lynbro.dk';
	$support_url   = 'https://wordpress.org/support/plugin/lynbro-cookie-consent/';
	$reviews_url   = 'https://wordpress.org/support/plugin/lynbro-cookie-consent/reviews/';
	$changelog_url = 'https://wordpress.org/plugins/lynbro-cookie-consent/#developers';
	?>
	<div class="lynbro-cc-tab-panel" id="lynbro-cc-tab-about" hidden>
		<div class="lynbro-cc-about">
			<h2 class="title"><?php echo esc_html__( 'About Lynbro Cookie Consent', 'lynbro-cookie-consent' ); ?></h2>
			<p>
				<?php echo esc_html__( 'Lynbro Cookie Consent is a free, privacy-first cookie banner built by Lynbro. Our goal is a beautiful, genuinely multilingual, GDPR/ePrivacy-ready banner with an equal Reject button — and no pageview limits, no paywall on the essentials, and no front-end tracking.', 'lynbro-cookie-consent' ); ?>
			</p>

			<p class="lynbro-cc-about__links">
				<a class="button button-primary" href="<?php echo esc_url( $company_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html__( 'Visit Lynbro', 'lynbro-cookie-consent' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $plugin_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html__( 'Plugin home page', 'lynbro-cookie-consent' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $support_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html__( 'Get support', 'lynbro-cookie-consent' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $reviews_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html__( 'Leave a review', 'lynbro-cookie-consent' ); ?>
				</a>
			</p>

			<h2 class="title"><?php echo esc_html__( 'Version & updates', 'lynbro-cookie-consent' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Installed version', 'lynbro-cookie-consent' ); ?></th>
					<td>
						<code><?php echo esc_html( LYNBRO_COOKIE_CONSENT_VERSION ); ?></code>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Updates', 'lynbro-cookie-consent' ); ?></th>
					<td>
						<p class="description" style="margin-top:0;">
							<?php echo esc_html__( 'Updates are delivered automatically through WordPress.org — there is no custom updater and the free version never phones home.', 'lynbro-cookie-consent' ); ?>
							<a href="<?php echo esc_url( $changelog_url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html__( 'View changelog', 'lynbro-cookie-consent' ); ?>
							</a>
						</p>
					</td>
				</tr>
			</table>

			<p class="description">
				<?php echo esc_html__( 'This plugin helps with cookie compliance but does not constitute legal advice.', 'lynbro-cookie-consent' ); ?>
			</p>
		</div>
	</div>
	<?php
}
