<?php
/**
 * Admin settings page (Settings → Cookie Consent).
 *
 * Uses the WordPress Settings API. All input is sanitized via the registered
 * sanitize callback; all output is escaped; the page requires `manage_options`
 * and the Settings API form carries its own nonce.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

require_once LYNBRO_COOKIE_CONSENT_DIR . 'admin/languages-tab.php';
require_once LYNBRO_COOKIE_CONSENT_DIR . 'admin/statistics-tab.php';
require_once LYNBRO_COOKIE_CONSENT_DIR . 'admin/feedback-tab.php';
require_once LYNBRO_COOKIE_CONSENT_DIR . 'admin/about-tab.php';

/**
 * Admin page hook suffix, stored so we can scope asset loading.
 *
 * @var string|null
 */
$GLOBALS['lynbro_cookie_consent_settings_hook'] = null;

/**
 * Register the settings, sections and fields.
 *
 * @return void
 */
function lynbro_cookie_consent_register_settings() {
	register_setting(
		'lynbro_cookie_consent_group',
		LYNBRO_COOKIE_CONSENT_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'lynbro_cookie_consent_sanitize_options',
			'default'           => lynbro_cookie_consent_default_options(),
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', 'lynbro_cookie_consent_register_settings' );

/**
 * Add the settings page under the Settings menu.
 *
 * @return void
 */
function lynbro_cookie_consent_add_settings_page() {
	$hook = add_options_page(
		__( 'Cookie Consent', 'lynbro-cookie-consent' ),
		__( 'Cookie Consent', 'lynbro-cookie-consent' ),
		'manage_options',
		'lynbro-cookie-consent',
		'lynbro_cookie_consent_render_settings_page'
	);

	$GLOBALS['lynbro_cookie_consent_settings_hook'] = $hook;
}
add_action( 'admin_menu', 'lynbro_cookie_consent_add_settings_page' );

/**
 * Enqueue admin assets only on our settings page.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function lynbro_cookie_consent_admin_assets( $hook ) {
	if ( $hook !== $GLOBALS['lynbro_cookie_consent_settings_hook'] ) {
		return;
	}

	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );

	wp_enqueue_style(
		'lynbro-cookie-consent-admin',
		LYNBRO_COOKIE_CONSENT_URL . 'admin/admin.css',
		array( 'wp-color-picker' ),
		lynbro_cookie_consent_asset_ver( 'admin/admin.css' )
	);

	wp_enqueue_script(
		'lynbro-cookie-consent-admin',
		LYNBRO_COOKIE_CONSENT_URL . 'admin/admin.js',
		array( 'jquery', 'wp-color-picker' ),
		lynbro_cookie_consent_asset_ver( 'admin/admin.js' ),
		true
	);

	wp_localize_script(
		'lynbro-cookie-consent-admin',
		'lynbroCookieConsentAdmin',
		array(
			'i18n' => array(
				'previewTitle'    => lynbro_cookie_consent_get_title(),
				'previewDesc'     => lynbro_cookie_consent_get_description(),
				'accept'          => __( 'Accept all', 'lynbro-cookie-consent' ),
				'reject'          => __( 'Reject all', 'lynbro-cookie-consent' ),
				'prefs'           => __( 'Manage preferences', 'lynbro-cookie-consent' ),
				/* translators: %s: WCAG contrast ratio, e.g. 3.1. */
				'contrastFail'    => __( 'Low contrast (%s:1). Aim for at least 4.5:1 to meet WCAG AA.', 'lynbro-cookie-consent' ),
				/* translators: %s: WCAG contrast ratio, e.g. 7.4. */
				'contrastPass'    => __( 'Good contrast (%s:1) — meets WCAG AA.', 'lynbro-cookie-consent' ),
				'contrastText'    => __( 'Banner text on background', 'lynbro-cookie-consent' ),
				'contrastAccept'  => __( 'Button text on Accept button', 'lynbro-cookie-consent' ),
				'contrastReject'  => __( 'Button text on Reject button', 'lynbro-cookie-consent' ),
				'contrastAllPass' => __( 'Colours meet WCAG AA accessibility', 'lynbro-cookie-consent' ),
				/* translators: %s: number of colour pairs that fail the contrast check. */
				'contrastSomeFail' => __( 'Some colours have low contrast (%s) — see details.', 'lynbro-cookie-consent' ),
				'contrastDetails' => __( 'Details', 'lynbro-cookie-consent' ),
				'contrastHide'    => __( 'Hide details', 'lynbro-cookie-consent' ),
				'applyPreset'     => __( 'Apply this design preset? It will overwrite your current Design settings (you can still review before saving).', 'lynbro-cookie-consent' ),
				'presetApplied'   => __( 'Preset applied. Review the preview, then Save Changes to keep it.', 'lynbro-cookie-consent' ),
			),
			'presets' => lynbro_cookie_consent_design_presets(),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'lynbro_cookie_consent_admin_assets' );

/**
 * Named design presets shown in the Design tab.
 *
 * Each preset is a self-contained set of Design-tab option values. They are
 * applied client-side (admin.js fills the matching fields and refreshes the
 * live preview); nothing is written until the owner clicks "Save Changes",
 * so the normal Settings API sanitize/validation still applies on save.
 *
 * The visible preset names are intentionally short, brand-style labels; the
 * accompanying one-line descriptions are translatable.
 *
 * @return array<int,array<string,mixed>> Ordered list of presets.
 */
function lynbro_cookie_consent_design_presets() {
	$presets = array(
		array(
			'id'     => 'bar-light',
			'name'   => 'Bar — Light',
			'desc'   => __( 'Full-width bar at the bottom, light and neutral.', 'lynbro-cookie-consent' ),
			'values' => array(
				'position'         => 'bottom-bar',
				'theme'            => 'light',
				'banner_radius'    => 0,
				'banner_max_width' => 0,
				'color_bg'         => '#ffffff',
				'color_text'       => '#1e1e1e',
				'color_accept'     => '#1a7f5a',
				'color_reject'     => '#6b6b6b',
				'color_btn_text'   => '#ffffff',
			),
		),
		array(
			'id'     => 'rounded-card',
			'name'   => 'Rounded card',
			'desc'   => __( 'Soft floating card in the bottom-right corner.', 'lynbro-cookie-consent' ),
			'values' => array(
				'position'         => 'box',
				'box_corner'       => 'bottom-right',
				'theme'            => 'light',
				'banner_radius'    => 16,
				'banner_max_width' => 420,
				'color_bg'         => '#ffffff',
				'color_text'       => '#1f2937',
				'color_accept'     => '#2563eb',
				'color_reject'     => '#64748b',
				'color_btn_text'   => '#ffffff',
			),
		),
		array(
			'id'     => 'center-modal-dark',
			'name'   => 'Center modal — Dark',
			'desc'   => __( 'Centered dialog with a dimmed overlay, dark theme.', 'lynbro-cookie-consent' ),
			'values' => array(
				'position'         => 'center-modal',
				'theme'            => 'dark',
				'banner_radius'    => 14,
				'banner_max_width' => 560,
				'color_bg_dark'       => '#15181d',
				'color_text_dark'     => '#f3f4f6',
				'color_accept_dark'   => '#3b82f6',
				'color_reject_dark'   => '#4b5563',
				'color_btn_text_dark' => '#ffffff',
			),
		),
		array(
			'id'     => 'minimal-top',
			'name'   => 'Minimal top',
			'desc'   => __( 'Slim, square bar pinned to the top of the page.', 'lynbro-cookie-consent' ),
			'values' => array(
				'position'         => 'top',
				'theme'            => 'light',
				'banner_radius'    => 0,
				'banner_max_width' => 0,
				'color_bg'         => '#111827',
				'color_text'       => '#f9fafb',
				'color_accept'     => '#10b981',
				'color_reject'     => '#374151',
				'color_btn_text'   => '#ffffff',
			),
		),
		array(
			'id'     => 'nordic',
			'name'   => 'Nordic',
			'desc'   => __( 'Calm, low-contrast palette in a rounded corner box.', 'lynbro-cookie-consent' ),
			'values' => array(
				'position'         => 'box',
				'box_corner'       => 'bottom-left',
				'theme'            => 'light',
				'banner_radius'    => 12,
				'banner_max_width' => 400,
				'color_bg'         => '#f4f6f8',
				'color_text'       => '#2e3a45',
				'color_accept'     => '#3a7d8c',
				'color_reject'     => '#8a9aa6',
				'color_btn_text'   => '#ffffff',
			),
		),
	);

	/**
	 * Filter the design presets offered in the admin Design tab.
	 *
	 * @param array $presets Ordered list of preset definitions.
	 */
	return apply_filters( 'lynbro_cookie_consent_design_presets', $presets );
}

/**
 * Render the settings page with tabbed sections.
 *
 * @return void
 */
function lynbro_cookie_consent_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'lynbro-cookie-consent' ) );
	}

	$options = lynbro_cookie_consent_get_options();
	$opt     = LYNBRO_COOKIE_CONSENT_OPTION;

	$tabs = array(
		'general'    => __( 'General', 'lynbro-cookie-consent' ),
		'design'     => __( 'Design', 'lynbro-cookie-consent' ),
		'categories' => __( 'Categories', 'lynbro-cookie-consent' ),
		'blocking'   => __( 'Blocking & Embeds', 'lynbro-cookie-consent' ),
		'languages'  => __( 'Languages', 'lynbro-cookie-consent' ),
		'consent'    => __( 'Consent Mode', 'lynbro-cookie-consent' ),
		'statistics' => __( 'Statistics', 'lynbro-cookie-consent' ),
		'log'        => __( 'Consent Log', 'lynbro-cookie-consent' ),
		'feedback'   => __( 'Feedback', 'lynbro-cookie-consent' ),
		'tools'      => __( 'Tools', 'lynbro-cookie-consent' ),
		'about'      => __( 'About', 'lynbro-cookie-consent' ),
	);
	?>
	<div class="wrap lynbro-cc-settings">
		<h1><?php echo esc_html__( 'Cookie Consent', 'lynbro-cookie-consent' ); ?></h1>

		<p class="description">
			<?php echo esc_html__( 'Configure your GDPR/ePrivacy and Google Consent Mode v2 cookie banner. This plugin helps with compliance but does not constitute legal advice.', 'lynbro-cookie-consent' ); ?>
		</p>

		<?php lynbro_cookie_consent_render_status_block( $options ); ?>

		<h2 class="nav-tab-wrapper lynbro-cc-tabs">
			<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
				<a href="#lynbro-cc-tab-<?php echo esc_attr( $tab_key ); ?>"
					class="nav-tab<?php echo ( 'general' === $tab_key ) ? ' nav-tab-active' : ''; ?>"
					data-lynbro-cc-tab="<?php echo esc_attr( $tab_key ); ?>">
					<?php echo esc_html( $tab_label ); ?>
				</a>
			<?php endforeach; ?>
		</h2>

		<?php /* Live preview — updated instantly by admin.js as fields change. */ ?>
		<div id="lynbro-cc-preview" class="lynbro-cc-preview" aria-hidden="true">
			<div class="lynbro-cc-preview__bar">
				<p class="lynbro-cc-preview__hint"><?php echo esc_html__( 'Live preview (updates as you change Design and General settings):', 'lynbro-cookie-consent' ); ?></p>
				<?php /* Device width switcher (Desktop / Tablet / Mobile). */ ?>
				<div class="lynbro-cc-devices" role="group" aria-label="<?php echo esc_attr__( 'Preview device width', 'lynbro-cookie-consent' ); ?>">
					<button type="button" class="lynbro-cc-device is-active" data-lynbro-cc-device="desktop" aria-pressed="true" title="<?php echo esc_attr__( 'Desktop', 'lynbro-cookie-consent' ); ?>">
						<svg viewBox="0 0 24 24" width="18" height="18" focusable="false" aria-hidden="true" fill="currentColor"><path d="M4 4h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-6v2h3v2H7v-2h3v-2H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm1 2v8h14V6H5z"/></svg>
						<span class="screen-reader-text"><?php echo esc_html__( 'Desktop', 'lynbro-cookie-consent' ); ?></span>
					</button>
					<button type="button" class="lynbro-cc-device" data-lynbro-cc-device="tablet" aria-pressed="false" title="<?php echo esc_attr__( 'Tablet', 'lynbro-cookie-consent' ); ?>">
						<svg viewBox="0 0 24 24" width="18" height="18" focusable="false" aria-hidden="true" fill="currentColor"><path d="M6 2h12a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm0 2v14h12V4H6zm6 15.25a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/></svg>
						<span class="screen-reader-text"><?php echo esc_html__( 'Tablet', 'lynbro-cookie-consent' ); ?></span>
					</button>
					<button type="button" class="lynbro-cc-device" data-lynbro-cc-device="mobile" aria-pressed="false" title="<?php echo esc_attr__( 'Mobile', 'lynbro-cookie-consent' ); ?>">
						<svg viewBox="0 0 24 24" width="18" height="18" focusable="false" aria-hidden="true" fill="currentColor"><path d="M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm0 2v16h10V4H7zm5 14.5a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/></svg>
						<span class="screen-reader-text"><?php echo esc_html__( 'Mobile', 'lynbro-cookie-consent' ); ?></span>
					</button>
				</div>
			</div>
			<div class="lynbro-cc-preview__viewport">
				<div class="lynbro-cc-preview__stage" data-lynbro-cc-position="bottom-bar">
					<div class="lynbro-cc-preview__overlay" aria-hidden="true"></div>
					<div class="lynbro-cc-preview__banner">
						<h3 class="lynbro-cc-preview__title"></h3>
						<p class="lynbro-cc-preview__desc"></p>
						<div class="lynbro-cc-preview__actions">
							<button type="button" class="lynbro-cc-preview__btn lynbro-cc-preview__btn--reject"></button>
							<button type="button" class="lynbro-cc-preview__btn lynbro-cc-preview__btn--prefs"></button>
							<button type="button" class="lynbro-cc-preview__btn lynbro-cc-preview__btn--accept"></button>
						</div>
					</div>
					<span id="lynbro-cc-float-preview" class="lynbro-cc-float-preview" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="22" height="22" focusable="false" fill="currentColor">
							<path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-4-4 4 4 0 0 1-4-4 2 2 0 0 1-2-2zm-3 7a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm7 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm-7 3a1.25 1.25 0 1 1 0 2.5A1.25 1.25 0 0 1 9 16z"/>
						</svg>
					</span>
				</div>
			</div>
			<div class="lynbro-cc-contrast" aria-live="polite">
				<p class="lynbro-cc-contrast__heading"><?php echo esc_html__( 'Accessibility', 'lynbro-cookie-consent' ); ?></p>
				<div id="lynbro-cc-contrast" class="lynbro-cc-contrast__summary"></div>
				<button type="button" id="lynbro-cc-contrast-toggle" class="button-link lynbro-cc-contrast__toggle" aria-expanded="false" hidden></button>
				<div id="lynbro-cc-contrast-details" class="lynbro-cc-contrast__details" hidden></div>
			</div>
		</div>

		<form action="options.php" method="post">
			<?php settings_fields( 'lynbro_cookie_consent_group' ); ?>

			<?php /* General tab. */ ?>
			<div class="lynbro-cc-tab-panel" id="lynbro-cc-tab-general">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lynbro-cc-mode"><?php echo esc_html__( 'Consent mode', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<select id="lynbro-cc-mode" name="<?php echo esc_attr( $opt ); ?>[consent_mode]">
								<?php
								$modes = array(
									'geo'     => __( 'Auto by region (EU opt-in, US opt-out, rest by choice)', 'lynbro-cookie-consent' ),
									'opt-in'  => __( 'Opt-in everywhere (GDPR — safest)', 'lynbro-cookie-consent' ),
									'opt-out' => __( 'Opt-out everywhere (CCPA style)', 'lynbro-cookie-consent' ),
									'notice'  => __( 'Notice only', 'lynbro-cookie-consent' ),
								);
								foreach ( $modes as $mkey => $mlabel ) :
									?>
									<option value="<?php echo esc_attr( $mkey ); ?>" <?php selected( $options['consent_mode'], $mkey ); ?>><?php echo esc_html( $mlabel ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Region is detected from CDN/edge headers (e.g. Cloudflare). When unknown, the safest opt-in model is used. No external geolocation API is called.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-policy"><?php echo esc_html__( 'Privacy/Cookie policy URL', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="url" class="regular-text code" id="lynbro-cc-policy"
								name="<?php echo esc_attr( $opt ); ?>[policy_url]"
								value="<?php echo esc_attr( $options['policy_url'] ); ?>"
								placeholder="https://example.com/privacy" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-title"><?php echo esc_html__( 'Banner title', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="lynbro-cc-title"
								name="<?php echo esc_attr( $opt ); ?>[title]"
								value="<?php echo esc_attr( $options['title'] ); ?>"
								placeholder="<?php echo esc_attr( lynbro_cookie_consent_get_title() ); ?>" />
							<p class="description"><?php echo esc_html__( 'Leave empty to use the translated default.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-desc"><?php echo esc_html__( 'Banner description', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="4" id="lynbro-cc-desc"
								name="<?php echo esc_attr( $opt ); ?>[description]"
								placeholder="<?php echo esc_attr( lynbro_cookie_consent_get_description() ); ?>"><?php echo esc_textarea( $options['description'] ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Basic HTML allowed: links, bold, italic, line breaks. Leave empty for the translated default.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-policy-version"><?php echo esc_html__( 'Policy version', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="number" min="1" step="1" class="small-text" id="lynbro-cc-policy-version"
								name="<?php echo esc_attr( $opt ); ?>[policy_version]"
								value="<?php echo esc_attr( (int) $options['policy_version'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Increase this number whenever your cookie policy changes. Visitors will be asked to consent again.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-exclude"><?php echo esc_html__( 'Exclude pages/posts', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="lynbro-cc-exclude"
								name="<?php echo esc_attr( $opt ); ?>[exclude_ids]"
								value="<?php echo esc_attr( implode( ', ', array_map( 'absint', (array) $options['exclude_ids'] ) ) ); ?>"
								placeholder="12, 34, 56" />
							<p class="description"><?php echo esc_html__( 'Comma-separated page/post IDs where the banner should not be shown (e.g. landing pages).', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php echo esc_html__( 'Statistics', 'lynbro-cookie-consent' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Anonymous statistics', 'lynbro-cookie-consent' ); ?></th>
						<td>
							<?php /* Hidden field guarantees the key is always submitted, so the box can be turned off. */ ?>
							<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[stats_enabled]" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[stats_enabled]" value="1" <?php checked( ! empty( $options['stats_enabled'] ) ); ?> />
								<?php echo esc_html__( 'Collect anonymous statistics (how many visitors accept, reject or open preferences). Fully aggregated and cookieless — no IP address or personal data is stored. Shown in the Statistics tab.', 'lynbro-cookie-consent' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Benchmark sharing', 'lynbro-cookie-consent' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[telemetry_enabled]" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[telemetry_enabled]" value="1" <?php checked( ! empty( $options['telemetry_enabled'] ) ); ?> />
								<?php echo esc_html__( 'Share anonymous statistics to help improve the plugin — and see how your accept rate compares to the average. Off by default. Sends only aggregated numbers and your site domain (never visitor IPs) to the Lynbro portal, once a week.', 'lynbro-cookie-consent' ); ?>
							</label>
							<p class="description">
								<a href="https://plugins.lynbro.dk" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Privacy Policy & Terms', 'lynbro-cookie-consent' ); ?></a>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<?php /* Design tab. */ ?>
			<div class="lynbro-cc-tab-panel" id="lynbro-cc-tab-design" hidden>
				<h2 class="title"><?php echo esc_html__( 'Design presets', 'lynbro-cookie-consent' ); ?></h2>
				<p class="description"><?php echo esc_html__( 'One-click starting points. Applying a preset fills the Design fields below and updates the live preview — review it, then Save Changes to keep it.', 'lynbro-cookie-consent' ); ?></p>
				<div class="lynbro-cc-presets">
					<?php foreach ( lynbro_cookie_consent_design_presets() as $preset ) : ?>
						<button type="button" class="lynbro-cc-preset" data-lynbro-cc-preset="<?php echo esc_attr( $preset['id'] ); ?>">
							<span class="lynbro-cc-preset__name"><?php echo esc_html( $preset['name'] ); ?></span>
							<span class="lynbro-cc-preset__desc"><?php echo esc_html( $preset['desc'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lynbro-cc-position"><?php echo esc_html__( 'Position', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<select id="lynbro-cc-position" name="<?php echo esc_attr( $opt ); ?>[position]">
								<?php
								$positions = array(
									'bottom-bar'   => __( 'Bottom bar', 'lynbro-cookie-consent' ),
									'top'          => __( 'Top bar', 'lynbro-cookie-consent' ),
									'box'          => __( 'Floating box (corner)', 'lynbro-cookie-consent' ),
									'center-modal' => __( 'Center modal (with overlay)', 'lynbro-cookie-consent' ),
								);
								foreach ( $positions as $pkey => $plabel ) :
									?>
									<option value="<?php echo esc_attr( $pkey ); ?>" <?php selected( $options['position'], $pkey ); ?>><?php echo esc_html( $plabel ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-box-corner"><?php echo esc_html__( 'Box corner', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<select id="lynbro-cc-box-corner" name="<?php echo esc_attr( $opt ); ?>[box_corner]">
								<?php
								$box_corners = array(
									'bottom-right' => __( 'Bottom right', 'lynbro-cookie-consent' ),
									'bottom-left'  => __( 'Bottom left', 'lynbro-cookie-consent' ),
									'top-right'    => __( 'Top right', 'lynbro-cookie-consent' ),
									'top-left'     => __( 'Top left', 'lynbro-cookie-consent' ),
								);
								foreach ( $box_corners as $ckey => $clabel ) :
									?>
									<option value="<?php echo esc_attr( $ckey ); ?>" <?php selected( $options['box_corner'], $ckey ); ?>><?php echo esc_html( $clabel ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Used only when Position is "Floating box".', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-theme"><?php echo esc_html__( 'Theme', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<select id="lynbro-cc-theme" name="<?php echo esc_attr( $opt ); ?>[theme]">
								<?php
								$themes = array(
									'light' => __( 'Light', 'lynbro-cookie-consent' ),
									'dark'  => __( 'Dark', 'lynbro-cookie-consent' ),
									'auto'  => __( 'Auto (follow visitor’s system)', 'lynbro-cookie-consent' ),
								);
								foreach ( $themes as $tkey => $tlabel ) :
									?>
									<option value="<?php echo esc_attr( $tkey ); ?>" <?php selected( $options['theme'], $tkey ); ?>><?php echo esc_html( $tlabel ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Auto uses the light colors by default and the dark colors when the visitor’s device prefers a dark color scheme.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-banner-max-width"><?php echo esc_html__( 'Banner max width (px)', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="number" min="0" max="2000" step="1" class="small-text" id="lynbro-cc-banner-max-width"
								name="<?php echo esc_attr( $opt ); ?>[banner_max_width]"
								value="<?php echo esc_attr( (int) $options['banner_max_width'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Maximum width of the floating box and center modal. Set 0 to use the default.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-banner-radius"><?php echo esc_html__( 'Corner radius (px)', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="number" min="0" max="40" step="1" class="small-text" id="lynbro-cc-banner-radius"
								name="<?php echo esc_attr( $opt ); ?>[banner_radius]"
								value="<?php echo esc_attr( (int) $options['banner_radius'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Rounding of the banner, box and modal corners. Set 0 to use the default.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-banner-offset"><?php echo esc_html__( 'Edge offset (px)', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="number" min="0" max="200" step="1" class="small-text" id="lynbro-cc-banner-offset"
								name="<?php echo esc_attr( $opt ); ?>[banner_offset]"
								value="<?php echo esc_attr( (int) $options['banner_offset'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Gap from the screen edges for the floating box layout.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-z-index-base"><?php echo esc_html__( 'Stacking (z-index base)', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="number" min="1" max="2147483000" step="1" class="regular-text" id="lynbro-cc-z-index-base"
								name="<?php echo esc_attr( $opt ); ?>[z_index_base]"
								value="<?php echo esc_attr( (int) $options['z_index_base'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Base z-index for the banner, overlay, modal and floating button. Increase it if another element covers the banner; lower it if the banner covers something it should not.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php echo esc_html__( 'Light colors', 'lynbro-cookie-consent' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$colors = array(
						'color_bg'       => __( 'Banner background', 'lynbro-cookie-consent' ),
						'color_text'     => __( 'Text color', 'lynbro-cookie-consent' ),
						'color_accept'   => __( 'Accept button', 'lynbro-cookie-consent' ),
						'color_reject'   => __( 'Reject button', 'lynbro-cookie-consent' ),
						'color_btn_text' => __( 'Button text', 'lynbro-cookie-consent' ),
					);
					foreach ( $colors as $ckey => $clabel ) :
						?>
						<tr>
							<th scope="row"><label for="lynbro-cc-<?php echo esc_attr( $ckey ); ?>"><?php echo esc_html( $clabel ); ?></label></th>
							<td>
								<input type="text" class="lynbro-cc-color" data-lynbro-cc-color="<?php echo esc_attr( $ckey ); ?>"
									id="lynbro-cc-<?php echo esc_attr( $ckey ); ?>"
									name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $ckey ); ?>]"
									value="<?php echo esc_attr( $options[ $ckey ] ); ?>"
									data-default-color="<?php echo esc_attr( $options[ $ckey ] ); ?>" />
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2 class="title"><?php echo esc_html__( 'Dark colors', 'lynbro-cookie-consent' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$dark_colors = array(
						'color_bg_dark'       => __( 'Banner background', 'lynbro-cookie-consent' ),
						'color_text_dark'     => __( 'Text color', 'lynbro-cookie-consent' ),
						'color_accept_dark'   => __( 'Accept button', 'lynbro-cookie-consent' ),
						'color_reject_dark'   => __( 'Reject button', 'lynbro-cookie-consent' ),
						'color_btn_text_dark' => __( 'Button text', 'lynbro-cookie-consent' ),
					);
					foreach ( $dark_colors as $ckey => $clabel ) :
						?>
						<tr>
							<th scope="row"><label for="lynbro-cc-<?php echo esc_attr( $ckey ); ?>"><?php echo esc_html( $clabel ); ?></label></th>
							<td>
								<input type="text" class="lynbro-cc-color" data-lynbro-cc-color="<?php echo esc_attr( $ckey ); ?>"
									id="lynbro-cc-<?php echo esc_attr( $ckey ); ?>"
									name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $ckey ); ?>]"
									value="<?php echo esc_attr( $options[ $ckey ] ); ?>"
									data-default-color="<?php echo esc_attr( $options[ $ckey ] ); ?>" />
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2 class="title"><?php echo esc_html__( 'Floating settings button', 'lynbro-cookie-consent' ); ?></h2>
				<p class="description"><?php echo esc_html__( 'A small icon-only button that lets visitors reopen the cookie settings after they have made a choice. It is automatically hidden while the banner or preferences dialog is open.', 'lynbro-cookie-consent' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Visibility', 'lynbro-cookie-consent' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[float_button]" value="1" <?php checked( ! empty( $options['float_button'] ) ); ?> />
								<?php echo esc_html__( 'Show floating settings button on the site', 'lynbro-cookie-consent' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'Unchecking this hides the floating button completely — visitors will not see a way to reopen their cookie settings (unless you add the [lynbro_cookie_settings] shortcode somewhere).', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-float-corner"><?php echo esc_html__( 'Position', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<select id="lynbro-cc-float-corner" name="<?php echo esc_attr( $opt ); ?>[float_button_corner]">
								<?php
								$float_corners = array(
									'bottom-right' => __( 'Bottom right', 'lynbro-cookie-consent' ),
									'bottom-left'  => __( 'Bottom left', 'lynbro-cookie-consent' ),
									'top-right'    => __( 'Top right', 'lynbro-cookie-consent' ),
									'top-left'     => __( 'Top left', 'lynbro-cookie-consent' ),
								);
								foreach ( $float_corners as $ckey => $clabel ) :
									?>
									<option value="<?php echo esc_attr( $ckey ); ?>" <?php selected( $options['float_button_corner'], $ckey ); ?>><?php echo esc_html( $clabel ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-float-shape"><?php echo esc_html__( 'Shape', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<select id="lynbro-cc-float-shape" name="<?php echo esc_attr( $opt ); ?>[float_button_shape]">
								<?php
								$float_shapes = array(
									'round'   => __( 'Round', 'lynbro-cookie-consent' ),
									'rounded' => __( 'Rounded', 'lynbro-cookie-consent' ),
									'square'  => __( 'Square', 'lynbro-cookie-consent' ),
								);
								foreach ( $float_shapes as $skey => $slabel ) :
									?>
									<option value="<?php echo esc_attr( $skey ); ?>" <?php selected( $options['float_button_shape'], $skey ); ?>><?php echo esc_html( $slabel ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-float-size"><?php echo esc_html__( 'Size (px)', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="number" min="24" max="96" step="1" class="small-text" id="lynbro-cc-float-size"
								name="<?php echo esc_attr( $opt ); ?>[float_button_size]"
								value="<?php echo esc_attr( (int) $options['float_button_size'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Width and height of the button (24–96). Default is 40.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-float-offset-x"><?php echo esc_html__( 'Offset from corner (px)', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<label class="lynbro-cc-inline-label" for="lynbro-cc-float-offset-x"><?php echo esc_html__( 'Horizontal', 'lynbro-cookie-consent' ); ?></label>
							<input type="number" min="0" max="200" step="1" class="small-text" id="lynbro-cc-float-offset-x"
								name="<?php echo esc_attr( $opt ); ?>[float_button_offset_x]"
								value="<?php echo esc_attr( (int) $options['float_button_offset_x'] ); ?>" />
							<label class="lynbro-cc-inline-label" for="lynbro-cc-float-offset-y"><?php echo esc_html__( 'Vertical', 'lynbro-cookie-consent' ); ?></label>
							<input type="number" min="0" max="200" step="1" class="small-text" id="lynbro-cc-float-offset-y"
								name="<?php echo esc_attr( $opt ); ?>[float_button_offset_y]"
								value="<?php echo esc_attr( (int) $options['float_button_offset_y'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-float_button_bg"><?php echo esc_html__( 'Background color', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="text" class="lynbro-cc-color" data-lynbro-cc-color="float_button_bg"
								id="lynbro-cc-float_button_bg"
								name="<?php echo esc_attr( $opt ); ?>[float_button_bg]"
								value="<?php echo esc_attr( $options['float_button_bg'] ); ?>"
								data-default-color="<?php echo esc_attr( lynbro_cookie_consent_get_float_bg() ); ?>" />
							<p class="description"><?php echo esc_html__( 'Leave empty to use the Accept button color.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-float_button_icon_color"><?php echo esc_html__( 'Icon color', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="text" class="lynbro-cc-color" data-lynbro-cc-color="float_button_icon_color"
								id="lynbro-cc-float_button_icon_color"
								name="<?php echo esc_attr( $opt ); ?>[float_button_icon_color]"
								value="<?php echo esc_attr( $options['float_button_icon_color'] ); ?>"
								data-default-color="<?php echo esc_attr( lynbro_cookie_consent_get_float_icon_color() ); ?>" />
							<p class="description"><?php echo esc_html__( 'Leave empty to use the Button text color.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-float-opacity"><?php echo esc_html__( 'Opacity', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="number" min="0.2" max="1" step="0.05" class="small-text" id="lynbro-cc-float-opacity"
								name="<?php echo esc_attr( $opt ); ?>[float_button_opacity]"
								value="<?php echo esc_attr( (string) (float) $options['float_button_opacity'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Resting transparency (0.2–1.0). The button becomes fully opaque on hover or focus. Default is 0.55.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-float-label"><?php echo esc_html__( 'Accessible label', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="lynbro-cc-float-label"
								name="<?php echo esc_attr( $opt ); ?>[float_button_label]"
								value="<?php echo esc_attr( $options['float_button_label'] ); ?>"
								placeholder="<?php echo esc_attr( lynbro_cookie_consent_get_float_label() ); ?>" />
							<p class="description"><?php echo esc_html__( 'Used as the tooltip and screen-reader label. Leave empty for the translated default.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Icon', 'lynbro-cookie-consent' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[float_button_icon]" value="1" <?php checked( ! empty( $options['float_button_icon'] ) ); ?> />
								<?php echo esc_html__( 'Show the cookie icon (recommended). When off, the accessible label is shown as a small text button instead.', 'lynbro-cookie-consent' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>

			<?php /* Categories tab. */ ?>
			<div class="lynbro-cc-tab-panel" id="lynbro-cc-tab-categories" hidden>
				<p class="description"><?php echo esc_html__( 'Necessary cookies are always active and cannot be disabled. Choose which optional categories visitors can control.', 'lynbro-cookie-consent' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( lynbro_cookie_consent_get_categories() as $cat_key => $cat ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $cat['label'] ); ?></th>
							<td>
								<?php if ( ! empty( $cat['locked'] ) ) : ?>
									<label>
										<input type="checkbox" checked disabled />
										<?php echo esc_html__( 'Always on', 'lynbro-cookie-consent' ); ?>
									</label>
								<?php else : ?>
									<label>
										<input type="checkbox"
											name="<?php echo esc_attr( $opt ); ?>[categories][]"
											value="<?php echo esc_attr( $cat_key ); ?>"
											<?php checked( in_array( $cat_key, (array) $options['categories'], true ) ); ?> />
										<?php echo esc_html__( 'Enable this category', 'lynbro-cookie-consent' ); ?>
									</label>
								<?php endif; ?>
								<p class="description"><?php echo esc_html( $cat['description'] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			</div>

			<?php /* Blocking & Embeds tab. */ ?>
			<div class="lynbro-cc-tab-panel" id="lynbro-cc-tab-blocking" hidden>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Automatic tracker blocking', 'lynbro-cookie-consent' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[auto_block]" value="1" <?php checked( ! empty( $options['auto_block'] ) ); ?> />
								<?php echo esc_html__( 'Automatically block known third-party trackers (Google Analytics, GTM, Meta Pixel, Hotjar, Clarity, and more) until the matching category is granted.', 'lynbro-cookie-consent' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'This complements manual script marking. All detection is local; no external request is made.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Embed placeholders', 'lynbro-cookie-consent' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[embeds_enabled]" value="1" <?php checked( ! empty( $options['embeds_enabled'] ) ); ?> />
								<?php echo esc_html__( 'Block embedded content (YouTube, Vimeo, Google Maps, social embeds, reCAPTCHA, and more) until consent, showing a tidy placeholder with an "allow" button.', 'lynbro-cookie-consent' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-embed-cat"><?php echo esc_html__( 'Embed consent category', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<select id="lynbro-cc-embed-cat" name="<?php echo esc_attr( $opt ); ?>[embed_category]">
								<?php
								foreach ( lynbro_cookie_consent_get_categories() as $cat_key => $cat ) :
									if ( ! empty( $cat['locked'] ) ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $cat_key ); ?>" <?php selected( $options['embed_category'], $cat_key ); ?>><?php echo esc_html( $cat['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Embeds are unblocked when the visitor grants this category (or clicks "allow" on the placeholder).', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="description">
					<?php echo esc_html__( 'You can still block your own scripts manually:', 'lynbro-cookie-consent' ); ?>
					<code>&lt;script type="text/plain" data-lynbro-cc="statistics"&gt;...&lt;/script&gt;</code>
				</p>
			</div>

			<?php /* Consent Mode tab. */ ?>
			<div class="lynbro-cc-tab-panel" id="lynbro-cc-tab-consent" hidden>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Google Consent Mode v2', 'lynbro-cookie-consent' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enable_gcm]" value="1" <?php checked( ! empty( $options['enable_gcm'] ) ); ?> />
								<?php echo esc_html__( 'Enable Google Consent Mode v2 signals', 'lynbro-cookie-consent' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Sets all consent signals to "denied" by default before consent, then updates them based on the visitor’s choice (ad_storage, analytics_storage, ad_user_data, ad_personalization, functionality_storage, personalization_storage, security_storage).', 'lynbro-cookie-consent' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Global Privacy Control (GPC)', 'lynbro-cookie-consent' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[honor_gpc]" value="1" <?php checked( ! empty( $options['honor_gpc'] ) ); ?> />
								<?php echo esc_html__( 'Honor the browser Global Privacy Control signal. In opt-out (US/CCPA) mode, visitors with GPC are automatically opted out and shown a visible confirmation (required by California from 2026).', 'lynbro-cookie-consent' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'CCPA / CPRA opt-out link', 'lynbro-cookie-consent' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[ccpa_link]" value="1" <?php checked( ! empty( $options['ccpa_link'] ) ); ?> />
								<?php echo esc_html__( 'Show a "Do Not Sell or Share My Personal Information" link in opt-out mode.', 'lynbro-cookie-consent' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'WP Consent API', 'lynbro-cookie-consent' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[wp_consent_api]" value="1" <?php checked( ! empty( $options['wp_consent_api'] ) ); ?> />
								<?php echo esc_html__( 'Register as the consent provider for the WP Consent API plugin and notify other plugins of the visitor’s choice (functional, preferences, statistics, marketing).', 'lynbro-cookie-consent' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php
				/**
				 * Extension point: add-ons can render additional design settings here.
				 */
				do_action( 'lynbro_cookie_consent_settings_design_after' );
				?>
			</div>

			<?php /* Consent Log tab. */ ?>
			<div class="lynbro-cc-tab-panel" id="lynbro-cc-tab-log" hidden>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Local consent log', 'lynbro-cookie-consent' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[consent_log]" value="1" <?php checked( ! empty( $options['consent_log'] ) ); ?> />
								<?php echo esc_html__( 'Store a privacy-respecting proof of consent in your own database. No raw IP address is stored — only a salted, anonymised hash for de-duplication.', 'lynbro-cookie-consent' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lynbro-cc-log-retention"><?php echo esc_html__( 'Retention (days)', 'lynbro-cookie-consent' ); ?></label></th>
						<td>
							<input type="number" min="0" step="1" class="small-text" id="lynbro-cc-log-retention"
								name="<?php echo esc_attr( $opt ); ?>[consent_log_retention]"
								value="<?php echo esc_attr( (int) $options['consent_log_retention'] ); ?>" />
							<p class="description"><?php echo esc_html__( 'Records older than this are deleted automatically. Set to 0 to keep records indefinitely.', 'lynbro-cookie-consent' ); ?></p>
						</td>
					</tr>
				</table>

				<?php lynbro_cookie_consent_render_log_viewer(); ?>
				<?php
				/**
				 * Extension point: add-ons can render additional consent-log tools here.
				 */
				do_action( 'lynbro_cookie_consent_settings_log_after' );
				?>
			</div>

			<?php /* Tools tab. */ ?>
			<div class="lynbro-cc-tab-panel" id="lynbro-cc-tab-tools" hidden>
				<h2 class="title"><?php echo esc_html__( 'Export settings', 'lynbro-cookie-consent' ); ?></h2>
				<p class="description"><?php echo esc_html__( 'Download all settings as a JSON file to reuse on another site.', 'lynbro-cookie-consent' ); ?></p>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lynbro_cookie_consent_export_settings' ), 'lynbro_cookie_consent_export_settings' ) ); ?>">
						<?php echo esc_html__( 'Export settings (JSON)', 'lynbro-cookie-consent' ); ?>
					</a>
				</p>
			</div>

			<?php
			/*
			 * The generic "Save Changes" button is only meaningful on tabs that
			 * contain editable option fields. It is hidden by default and revealed
			 * by admin.js for the settings tabs (see the data-attribute list). The
			 * Tools tab inside this form is action-only (Export link + separate
			 * Import form), so it gets no generic Save button.
			 */
			?>
			<div class="lynbro-cc-submit"
				id="lynbro-cc-submit"
				data-lynbro-cc-settings-tabs="general,design,categories,blocking,consent,log"
				hidden>
				<?php submit_button(); ?>
			</div>
		</form>

		<?php /* Import is a separate form (file upload to admin-post.php). */ ?>
		<div class="lynbro-cc-tab-panel lynbro-cc-tools-import" id="lynbro-cc-tab-tools-import" hidden>
			<h2 class="title"><?php echo esc_html__( 'Import settings', 'lynbro-cookie-consent' ); ?></h2>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
				<input type="hidden" name="action" value="lynbro_cookie_consent_import_settings" />
				<?php wp_nonce_field( 'lynbro_cookie_consent_import_settings' ); ?>
				<p>
					<input type="file" name="lynbro_cc_import_file" accept="application/json,.json" required />
				</p>
				<p class="description"><?php echo esc_html__( 'Importing overwrites your current settings.', 'lynbro-cookie-consent' ); ?></p>
				<?php submit_button( __( 'Import settings', 'lynbro-cookie-consent' ), 'secondary' ); ?>
			</form>
		</div>

		<?php /* Languages tab — its own form (separate save handler + nonce). */ ?>
		<?php lynbro_cookie_consent_render_languages_tab(); ?>

		<?php /* Statistics tab — read-only local aggregates. */ ?>
		<?php lynbro_cookie_consent_render_statistics_tab(); ?>

		<?php /* Feedback tab — its own opt-in form (separate handler + nonce). */ ?>
		<?php lynbro_cookie_consent_render_feedback_tab(); ?>

		<?php /* About tab — informational only. */ ?>
		<?php lynbro_cookie_consent_render_about_tab(); ?>
	</div>
	<?php
}

/**
 * Render the "quick start / status" block shown above the tabs.
 *
 * Gives a one-glance confirmation that the banner is live plus the handful of
 * settings most owners want first. Read-only summary (full controls in tabs).
 *
 * @param array $options Current settings.
 * @return void
 */
function lynbro_cookie_consent_render_status_block( $options ) {
	$mode_labels = array(
		'geo'     => __( 'Auto by region', 'lynbro-cookie-consent' ),
		'opt-in'  => __( 'Opt-in everywhere', 'lynbro-cookie-consent' ),
		'opt-out' => __( 'Opt-out everywhere', 'lynbro-cookie-consent' ),
		'notice'  => __( 'Notice only', 'lynbro-cookie-consent' ),
	);
	$mode    = isset( $options['consent_mode'] ) ? $options['consent_mode'] : 'geo';
	$has_url = ! empty( $options['policy_url'] );
	?>
	<div class="lynbro-cc-status">
		<p class="lynbro-cc-status__headline">
			<span class="lynbro-cc-status__dot" aria-hidden="true">&#9989;</span>
			<strong><?php echo esc_html__( 'Your consent banner is live', 'lynbro-cookie-consent' ); ?></strong>
			<?php echo esc_html__( 'It works out of the box with GDPR-ready defaults — an equal Reject button and non-essential cookies blocked until consent.', 'lynbro-cookie-consent' ); ?>
		</p>
		<ul class="lynbro-cc-status__items">
			<li>
				<span class="lynbro-cc-status__label"><?php echo esc_html__( 'Legal mode', 'lynbro-cookie-consent' ); ?>:</span>
				<?php echo esc_html( isset( $mode_labels[ $mode ] ) ? $mode_labels[ $mode ] : $mode ); ?>
			</li>
			<li>
				<span class="lynbro-cc-status__label"><?php echo esc_html__( 'Consent Mode v2', 'lynbro-cookie-consent' ); ?>:</span>
				<?php echo ! empty( $options['enable_gcm'] ) ? esc_html__( 'On', 'lynbro-cookie-consent' ) : esc_html__( 'Off', 'lynbro-cookie-consent' ); ?>
			</li>
			<li>
				<span class="lynbro-cc-status__label"><?php echo esc_html__( 'Auto-block trackers', 'lynbro-cookie-consent' ); ?>:</span>
				<?php echo ! empty( $options['auto_block'] ) ? esc_html__( 'On', 'lynbro-cookie-consent' ) : esc_html__( 'Off', 'lynbro-cookie-consent' ); ?>
			</li>
			<li>
				<span class="lynbro-cc-status__label"><?php echo esc_html__( 'Policy URL', 'lynbro-cookie-consent' ); ?>:</span>
				<?php if ( $has_url ) : ?>
					<?php echo esc_html__( 'Set', 'lynbro-cookie-consent' ); ?>
				<?php else : ?>
					<a href="#lynbro-cc-tab-general" data-lynbro-cc-tab="general"><?php echo esc_html__( 'Add your privacy policy link', 'lynbro-cookie-consent' ); ?></a>
				<?php endif; ?>
			</li>
		</ul>
	</div>
	<?php
}

/**
 * Render the recent consent-log records and CSV export button.
 *
 * @return void
 */
function lynbro_cookie_consent_render_log_viewer() {
	if ( ! function_exists( 'lynbro_cookie_consent_log_get_rows' ) ) {
		return;
	}
	$total = lynbro_cookie_consent_log_count();
	$rows  = lynbro_cookie_consent_log_get_rows( 50, 0 );
	?>
	<h2 class="title"><?php echo esc_html__( 'Recent consent records', 'lynbro-cookie-consent' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: %d: total number of consent records. */
			esc_html__( 'Total records: %d (showing the 50 most recent).', 'lynbro-cookie-consent' ),
			(int) $total
		);
		?>
		<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lynbro_cookie_consent_export_log' ), 'lynbro_cookie_consent_export_log' ) ); ?>">
			<?php echo esc_html__( 'Export CSV', 'lynbro-cookie-consent' ); ?>
		</a>
	</p>
	<table class="widefat striped lynbro-cc-log-table">
		<thead>
			<tr>
				<th><?php echo esc_html__( 'Date (UTC)', 'lynbro-cookie-consent' ); ?></th>
				<th><?php echo esc_html__( 'Method', 'lynbro-cookie-consent' ); ?></th>
				<th><?php echo esc_html__( 'Policy version', 'lynbro-cookie-consent' ); ?></th>
				<th><?php echo esc_html__( 'Categories', 'lynbro-cookie-consent' ); ?></th>
				<th><?php echo esc_html__( 'Language', 'lynbro-cookie-consent' ); ?></th>
				<th><?php echo esc_html__( 'Region', 'lynbro-cookie-consent' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="6"><?php echo esc_html__( 'No consent records yet.', 'lynbro-cookie-consent' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><?php echo esc_html( $row->method ); ?></td>
						<td><?php echo esc_html( $row->policy_version ); ?></td>
						<td><code><?php echo esc_html( $row->categories ); ?></code></td>
						<td><?php echo esc_html( $row->lang ); ?></td>
						<td><?php echo esc_html( $row->region ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	<?php
}
