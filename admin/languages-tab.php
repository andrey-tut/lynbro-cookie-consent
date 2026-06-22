<?php
/**
 * Admin — Languages tab renderer (Wave C).
 *
 * Renders the offered-locales selector, auto-detect / manual-switcher toggles,
 * a custom-locale field, and the per-language text override editor. The form
 * posts to its own admin-post handler (lynbro_cookie_consent_save_languages)
 * with a dedicated nonce; all input is sanitized there.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the Languages tab content.
 *
 * @return void
 */
function lynbro_cookie_consent_render_languages_tab() {
	$available = lynbro_cookie_consent_available_locales();   // code => label.
	$offered   = lynbro_cookie_consent_offered_locales();     // codes (incl. site locale).
	$catalog   = lynbro_cookie_consent_string_catalog();      // key => default/label.
	$overrides = lynbro_cookie_consent_all_overrides();        // locale => key => text.
	$auto      = ! empty( lynbro_cookie_consent_get_option( 'lang_auto_detect', 1 ) );
	$switcher  = ! empty( lynbro_cookie_consent_get_option( 'lang_switcher', 0 ) );
	$site      = get_locale();
	?>
	<div class="lynbro-cc-tab-panel" id="lynbro-cc-tab-languages" hidden>
		<p class="description">
			<?php echo esc_html__( 'The banner language follows a cache-safe cascade: the current page language (Polylang/WPML/TranslatePress), then your site language, then English. Browser auto-detection and the optional in-banner switcher happen entirely in the visitor’s browser, so your page cache is never affected.', 'lynbro-cookie-consent' ); ?>
		</p>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="lynbro_cookie_consent_save_languages" />
			<?php wp_nonce_field( 'lynbro_cookie_consent_save_languages' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Auto-detect visitor language', 'lynbro-cookie-consent' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="lynbro_cc_lang_auto" value="1" <?php checked( $auto ); ?> />
							<?php echo esc_html__( 'Automatically show the banner in the visitor’s browser language when it is one of the offered languages.', 'lynbro-cookie-consent' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Show language switcher', 'lynbro-cookie-consent' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="lynbro_cc_lang_switcher" value="1" <?php checked( $switcher ); ?> />
							<?php echo esc_html__( 'Add a small language dropdown to the banner so visitors can switch language manually (their choice is remembered).', 'lynbro-cookie-consent' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Offered languages', 'lynbro-cookie-consent' ); ?></th>
					<td>
						<fieldset class="lynbro-cc-locales">
							<legend class="screen-reader-text"><?php echo esc_html__( 'Offered languages', 'lynbro-cookie-consent' ); ?></legend>
							<?php foreach ( $available as $code => $label ) : ?>
								<?php $is_site = ( $code === $site ); ?>
								<label class="lynbro-cc-locale">
									<input type="checkbox" name="lynbro_cc_offered[]" value="<?php echo esc_attr( $code ); ?>"
										<?php checked( in_array( $code, $offered, true ) ); ?>
										<?php disabled( $is_site ); ?> />
									<?php echo esc_html( $label ); ?>
									<code><?php echo esc_html( $code ); ?></code>
									<?php if ( $is_site ) : ?>
										<span class="lynbro-cc-locale__badge"><?php echo esc_html__( 'site language', 'lynbro-cookie-consent' ); ?></span>
										<input type="hidden" name="lynbro_cc_offered[]" value="<?php echo esc_attr( $code ); ?>" />
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description">
							<?php echo esc_html__( 'These are the languages prepared for browser auto-detection and the switcher. Languages with a bundled translation appear here automatically. Your site language is always included.', 'lynbro-cookie-consent' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lynbro-cc-custom-locale"><?php echo esc_html__( 'Add a custom language', 'lynbro-cookie-consent' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="lynbro-cc-custom-locale"
							name="lynbro_cc_custom_locale" value="" placeholder="xx_XX" />
						<p class="description">
							<?php echo esc_html__( 'Enter a WordPress locale code (for example pt_PT or ca) to offer a language that is not bundled. After saving, fill in its texts below.', 'lynbro-cookie-consent' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php echo esc_html__( 'Edit texts per language', 'lynbro-cookie-consent' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( 'Override any banner text for a specific language. Leave a field empty to use the bundled translation (or the English default). Priority: your override → bundled translation → default.', 'lynbro-cookie-consent' ); ?>
			</p>

			<?php
			// Build the locale list to edit: offered locales + any with existing overrides.
			$edit_locales = $offered;
			foreach ( array_keys( $overrides ) as $loc ) {
				if ( ! in_array( $loc, $edit_locales, true ) ) {
					$edit_locales[] = $loc;
				}
			}
			foreach ( $edit_locales as $locale ) :
				$loc_overrides = isset( $overrides[ $locale ] ) ? $overrides[ $locale ] : array();
				// Resolve the translation defaults for this locale (for placeholders).
				$resolved = lynbro_cookie_consent_strings_for_locale( $locale );
				?>
				<details class="lynbro-cc-lang-group">
					<summary>
						<strong><?php echo esc_html( lynbro_cookie_consent_locale_label( $locale ) ); ?></strong>
						<code><?php echo esc_html( $locale ); ?></code>
					</summary>
					<table class="form-table" role="presentation">
						<?php foreach ( $catalog as $key => $entry ) : ?>
							<?php
							$field_name  = 'lynbro_cc_lang[' . $locale . '][' . $key . ']';
							$field_id    = 'lynbro-cc-lang-' . sanitize_html_class( $locale . '-' . $key );
							$value       = isset( $loc_overrides[ $key ] ) ? $loc_overrides[ $key ] : '';
							$placeholder = isset( $resolved[ $key ] ) ? $resolved[ $key ] : '';
							$is_textarea = ( 'description' === $key );
							?>
							<tr>
								<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $entry['label'] ); ?></label></th>
								<td>
									<?php if ( $is_textarea ) : ?>
										<textarea class="large-text" rows="3" id="<?php echo esc_attr( $field_id ); ?>"
											name="<?php echo esc_attr( $field_name ); ?>"
											placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
									<?php else : ?>
										<input type="text" class="regular-text" id="<?php echo esc_attr( $field_id ); ?>"
											name="<?php echo esc_attr( $field_name ); ?>"
											value="<?php echo esc_attr( $value ); ?>"
											placeholder="<?php echo esc_attr( $placeholder ); ?>" />
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				</details>
			<?php endforeach; ?>

			<p class="description lynbro-cc-legal-note">
				<?php echo esc_html__( 'Tip: cookie/consent wording can have legal implications. Have the legal parts of any translation reviewed by a person familiar with your jurisdiction.', 'lynbro-cookie-consent' ); ?>
			</p>

			<?php submit_button( __( 'Save language settings', 'lynbro-cookie-consent' ) ); ?>
		</form>

		<?php lynbro_cookie_consent_render_request_language_form( $available ); ?>
	</div>
	<?php
}

/**
 * Render the optional "Request this language" form (Wave D, on-demand i18n).
 *
 * Lets the owner ask the Lynbro portal to generate a translation for a locale
 * that is not bundled. Only the plugin slug, version and locale code are sent.
 *
 * @param array<string,string> $available Bundled/available code => label map.
 * @return void
 */
function lynbro_cookie_consent_render_request_language_form( $available ) {
	// Offer the common WordPress locales that are not already bundled/downloaded.
	$candidates = array(
		'af', 'sq', 'hy', 'az', 'eu', 'be', 'bn_BD', 'bs_BA', 'ca', 'cy',
		'eo', 'eu', 'fa_IR', 'gl_ES', 'ka_GE', 'gu', 'hi_IN', 'is_IS', 'kk',
		'km', 'kn', 'ml_IN', 'mk_MK', 'mr', 'ms_MY', 'my_MM', 'ne_NP', 'pa_IN',
		'si_LK', 'sr_RS', 'sw', 'ta_IN', 'te', 'th', 'tl', 'ur', 'uz_UZ', 'vi',
		'zh_TW', 'zh_HK',
	);
	$downloaded = function_exists( 'lynbro_cookie_consent_i18n_downloaded_locales' )
		? lynbro_cookie_consent_i18n_downloaded_locales()
		: array();

	$offerable = array();
	foreach ( $candidates as $code ) {
		if ( isset( $available[ $code ] ) || in_array( $code, $downloaded, true ) ) {
			continue;
		}
		$offerable[ $code ] = lynbro_cookie_consent_locale_label( $code );
	}
	asort( $offerable );
	?>
	<h2 class="title"><?php echo esc_html__( 'Request a language', 'lynbro-cookie-consent' ); ?></h2>
	<p class="description">
		<?php echo esc_html__( 'Need a language that is not bundled? Request it and we will generate it for your site. The translation file is stored in your uploads folder (never overwritten on update) and activated automatically.', 'lynbro-cookie-consent' ); ?>
	</p>

	<?php if ( ! empty( $downloaded ) ) : ?>
		<p class="description">
			<?php echo esc_html__( 'Already downloaded on demand:', 'lynbro-cookie-consent' ); ?>
			<?php
			$labels = array();
			foreach ( $downloaded as $loc ) {
				$labels[] = lynbro_cookie_consent_locale_label( $loc ) . ' (' . $loc . ')';
			}
			echo esc_html( implode( ', ', $labels ) );
			?>
		</p>
	<?php endif; ?>

	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="lynbro-cc-request-language">
		<input type="hidden" name="action" value="lynbro_cookie_consent_request_language" />
		<?php wp_nonce_field( 'lynbro_cookie_consent_request_language' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="lynbro-cc-request-locale"><?php echo esc_html__( 'Language', 'lynbro-cookie-consent' ); ?></label></th>
				<td>
					<select id="lynbro-cc-request-locale" name="lynbro_cc_request_locale">
						<?php foreach ( $offerable as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?> (<?php echo esc_html( $code ); ?>)</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the portal privacy/terms page. */
							esc_html__( 'Sends only the plugin name, version and language code to the Lynbro portal. See our %s.', 'lynbro-cookie-consent' ),
							'<a href="https://plugins.lynbro.dk" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy & Terms', 'lynbro-cookie-consent' ) . '</a>'
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Request this language', 'lynbro-cookie-consent' ), 'secondary' ); ?>
	</form>
	<?php
}
