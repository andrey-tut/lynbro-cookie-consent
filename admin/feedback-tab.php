<?php
/**
 * Admin — Feedback tab renderer (Wave D).
 *
 * A small opt-in form: the owner picks a category, types a message and (only if
 * they want) leaves an email, then clicks Send. The submission is handled by the
 * admin-post action in includes/feedback.php (capability + nonce checked there)
 * and posted to the Lynbro portal. Nothing is sent automatically.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the Feedback tab content.
 *
 * @return void
 */
function lynbro_cookie_consent_render_feedback_tab() {
	$categories = function_exists( 'lynbro_cookie_consent_feedback_categories' )
		? lynbro_cookie_consent_feedback_categories()
		: array();
	?>
	<div class="lynbro-cc-tab-panel" id="lynbro-cc-tab-feedback" hidden>
		<h2 class="title"><?php echo esc_html__( 'Send feedback', 'lynbro-cookie-consent' ); ?></h2>
		<p class="description">
			<?php echo esc_html__( 'Have an idea, a problem or a request? Send it straight to the developers. This is entirely optional and only sent when you click Send.', 'lynbro-cookie-consent' ); ?>
		</p>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="lynbro-cc-feedback-form">
			<input type="hidden" name="action" value="lynbro_cookie_consent_feedback" />
			<?php wp_nonce_field( 'lynbro_cookie_consent_feedback' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="lynbro-cc-feedback-category"><?php echo esc_html__( 'Category', 'lynbro-cookie-consent' ); ?></label></th>
					<td>
						<select id="lynbro-cc-feedback-category" name="lynbro_cc_feedback_category">
							<?php foreach ( $categories as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lynbro-cc-feedback-message"><?php echo esc_html__( 'Message', 'lynbro-cookie-consent' ); ?></label></th>
					<td>
						<textarea id="lynbro-cc-feedback-message" name="lynbro_cc_feedback_message"
							class="large-text" rows="6" required
							placeholder="<?php echo esc_attr__( 'Tell us what you think, what is missing, or what went wrong…', 'lynbro-cookie-consent' ); ?>"></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lynbro-cc-feedback-email"><?php echo esc_html__( 'Your email (optional)', 'lynbro-cookie-consent' ); ?></label></th>
					<td>
						<input type="email" id="lynbro-cc-feedback-email" name="lynbro_cc_feedback_email"
							class="regular-text" value="" placeholder="you@example.com" />
						<p class="description"><?php echo esc_html__( 'Only if you would like a reply. Leave empty to send anonymously.', 'lynbro-cookie-consent' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the portal privacy/terms page. */
					esc_html__( 'Your message, the plugin version and your site domain are sent to the Lynbro portal. See our %s.', 'lynbro-cookie-consent' ),
					'<a href="https://plugins.lynbro.dk" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy & Terms', 'lynbro-cookie-consent' ) . '</a>'
				);
				?>
			</p>

			<?php submit_button( __( 'Send feedback', 'lynbro-cookie-consent' ) ); ?>
		</form>
	</div>
	<?php
}
