<?php
/**
 * Options: defaults, schema, getters and sanitization.
 *
 * Single source of truth for the plugin settings array stored under the option
 * name `lynbro_cookie_consent_options`.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Option name where all settings live (single array).
 */
if ( ! defined( 'LYNBRO_COOKIE_CONSENT_OPTION' ) ) {
	define( 'LYNBRO_COOKIE_CONSENT_OPTION', 'lynbro_cookie_consent_options' );
}

/**
 * Base consent version baked into the plugin. Bump in code when the default
 * categories/policy text change so visitors are asked to re-consent.
 *
 * The *effective* consent version used at runtime is this value combined with
 * the site owner's own "policy version" option, see
 * lynbro_cookie_consent_effective_consent_version().
 */
if ( ! defined( 'LYNBRO_COOKIE_CONSENT_CONSENT_VERSION' ) ) {
	define( 'LYNBRO_COOKIE_CONSENT_CONSENT_VERSION', 1 );
}

/**
 * Resolve the effective consent version (base version + owner policy version).
 *
 * When the site owner bumps their policy version in the settings, all stored
 * consents become outdated and visitors are asked again (consent versioning).
 *
 * @return int
 */
function lynbro_cookie_consent_effective_consent_version() {
	$policy_version = (int) lynbro_cookie_consent_get_option( 'policy_version', 1 );
	if ( $policy_version < 1 ) {
		$policy_version = 1;
	}
	return (int) LYNBRO_COOKIE_CONSENT_CONSENT_VERSION + ( $policy_version - 1 );
}

/**
 * Return the list of consent categories.
 *
 * `necessary` is always on and cannot be toggled off by the visitor.
 * Each category: key, label, description, optional (bool, can be disabled),
 * locked (bool, always granted), gcm (array of Google Consent Mode signals).
 *
 * @return array<string,array<string,mixed>>
 */
function lynbro_cookie_consent_get_categories() {
	$categories = array(
		'necessary'   => array(
			'label'       => __( 'Necessary', 'lynbro-cookie-consent' ),
			'description' => __( 'Required for the website to function and cannot be switched off. They are usually set in response to actions you make, such as setting privacy preferences or logging in.', 'lynbro-cookie-consent' ),
			'locked'      => true,
			'gcm'         => array( 'security_storage' ),
		),
		'preferences' => array(
			'label'       => __( 'Preferences', 'lynbro-cookie-consent' ),
			'description' => __( 'Allow the website to remember choices you make (such as language or region) to provide enhanced, personalized features.', 'lynbro-cookie-consent' ),
			'locked'      => false,
			'gcm'         => array( 'functionality_storage', 'personalization_storage' ),
		),
		'statistics'  => array(
			'label'       => __( 'Statistics', 'lynbro-cookie-consent' ),
			'description' => __( 'Help us understand how visitors interact with the website by collecting and reporting information anonymously.', 'lynbro-cookie-consent' ),
			'locked'      => false,
			'gcm'         => array( 'analytics_storage' ),
		),
		'marketing'   => array(
			'label'       => __( 'Marketing', 'lynbro-cookie-consent' ),
			'description' => __( 'Used to track visitors across websites to display relevant advertising and measure campaign effectiveness.', 'lynbro-cookie-consent' ),
			'locked'      => false,
			'gcm'         => array( 'ad_storage', 'ad_user_data', 'ad_personalization' ),
		),
	);

	/**
	 * Filter the consent categories.
	 *
	 * @param array $categories Category definitions keyed by slug.
	 */
	return apply_filters( 'lynbro_cookie_consent_categories', $categories );
}

/**
 * All Google Consent Mode v2 signals managed by the plugin, with their default state.
 *
 * Defaults are `denied` except `security_storage` which is tied to necessary cookies.
 *
 * @return array<string,string> Signal name => 'granted'|'denied'.
 */
function lynbro_cookie_consent_gcm_default_signals() {
	return array(
		'ad_storage'             => 'denied',
		'analytics_storage'      => 'denied',
		'ad_user_data'           => 'denied',
		'ad_personalization'     => 'denied',
		'functionality_storage'  => 'denied',
		'personalization_storage' => 'denied',
		'security_storage'       => 'granted',
	);
}

/**
 * Default settings array.
 *
 * @return array<string,mixed>
 */
function lynbro_cookie_consent_default_options() {
	$defaults = array(
		// General.
		'consent_mode'   => 'geo', // geo|opt-in|opt-out|notice.
		'position'       => 'bottom-bar', // bottom-bar|top|box|center-modal.
		'box_corner'     => 'bottom-right', // bottom-right|bottom-left|top-right|top-left (box position only).
		'policy_url'     => '',
		'policy_version' => 1, // Owner-controlled policy version; bump to re-ask consent.
		'enable_gcm'     => 1,

		// Texts (translatable defaults resolved at render time, see helpers below).
		'title'          => '',
		'description'    => '',

		// Theme: light|dark|auto (auto follows prefers-color-scheme).
		'theme'          => 'light',

		// Banner geometry. All in pixels; 0 = stylesheet default.
		'banner_max_width' => 0,  // Max width of box/center-modal (0 = CSS default).
		'banner_radius'    => 0,  // Corner radius of the banner/box/modal (0 = CSS default).
		'banner_offset'    => 20, // Gap from the screen edges for box/bar layouts.

		// Design colours (light theme).
		'color_bg'       => '#ffffff',
		'color_text'     => '#1e1e1e',
		'color_accept'   => '#1a7f5a',
		'color_reject'   => '#6b6b6b',
		'color_btn_text' => '#ffffff',

		// Design colours (dark theme — used when theme=dark, or auto in dark mode).
		'color_bg_dark'       => '#1e1e1e',
		'color_text_dark'     => '#f3f3f3',
		'color_accept_dark'   => '#2f9e6f',
		'color_reject_dark'   => '#5a5a5a',
		'color_btn_text_dark' => '#ffffff',

		// Categories enabled by the site owner (necessary always on).
		'categories'     => array( 'preferences', 'statistics', 'marketing' ),

		// Floating re-open button (icon-only by default).
		'float_button'           => 1,
		'float_button_corner'    => 'bottom-left', // bottom-right|bottom-left|top-right|top-left.
		'float_button_label'     => '', // Falls back to translated default (accessible label/tooltip).
		'float_button_icon'      => 1, // Show the cookie icon.
		'float_button_shape'     => 'round', // round|rounded|square.
		'float_button_size'      => 40,  // Width/height in px (24–96).
		'float_button_offset_x'  => 16,  // Horizontal gap from the corner, px.
		'float_button_offset_y'  => 16,  // Vertical gap from the corner, px.
		'float_button_bg'        => '',  // Hex; empty -> use the Accept colour.
		'float_button_icon_color' => '', // Hex; empty -> use the Button-text colour.
		'float_button_opacity'   => 0.55, // Resting opacity (0.2–1.0).

		// Stacking / overlap. Banner, overlay, modal and float compute z-index from this.
		'z_index_base'        => 99990,

		// Embed / iframe placeholders.
		'embeds_enabled'  => 1,
		'embed_category'  => 'marketing', // Consent category embeds are gated behind.

		// Automatic tracker blocking by built-in catalog.
		'auto_block'      => 1,

		// GPC (Global Privacy Control).
		'honor_gpc'       => 1,

		// CCPA / CPRA "Do Not Sell or Share" link in opt-out mode.
		'ccpa_link'       => 1,

		// Local consent log (proof of consent).
		'consent_log'         => 1,
		'consent_log_retention' => 365, // Days; 0 = keep forever.

		// Per-page/post exclusions (banner not shown on these IDs).
		'exclude_ids'     => array(),

		// WP Consent API integration.
		'wp_consent_api'  => 1,

		// Languages (Wave C). Offered locales for browser auto-detect + switcher.
		'offered_locales'  => array(), // Empty -> sensible defaults at runtime.
		'lang_auto_detect' => 1,       // Pick the best offered locale from navigator.languages.
		'lang_switcher'    => 1,       // Show an in-banner language dropdown.

		// Local, cookieless, aggregated statistics (Wave D). On by default — the
		// data is aggregated and contains no IP/PII, so it is not personal data.
		'stats_enabled'    => 1,

		// Opt-in: share aggregated stats with the portal to get a benchmark. OFF.
		'telemetry_enabled' => 0,
	);

	/**
	 * Filter default options.
	 *
	 * @param array $defaults Default settings.
	 */
	return apply_filters( 'lynbro_cookie_consent_default_options', $defaults );
}

/**
 * Get all options merged with defaults.
 *
 * @return array<string,mixed>
 */
function lynbro_cookie_consent_get_options() {
	$stored = get_option( LYNBRO_COOKIE_CONSENT_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return wp_parse_args( $stored, lynbro_cookie_consent_default_options() );
}

/**
 * Get a single option value with default fallback.
 *
 * @param string $key     Option key.
 * @param mixed  $default Fallback value.
 * @return mixed
 */
function lynbro_cookie_consent_get_option( $key, $default = '' ) {
	$options = lynbro_cookie_consent_get_options();
	return isset( $options[ $key ] ) ? $options[ $key ] : $default;
}

/**
 * Resolved banner title (falls back to translatable default).
 *
 * @return string
 */
function lynbro_cookie_consent_get_title() {
	$title = (string) lynbro_cookie_consent_get_option( 'title', '' );
	if ( '' === trim( $title ) ) {
		$title = __( 'We value your privacy', 'lynbro-cookie-consent' );
	}
	return $title;
}

/**
 * Resolved banner description (falls back to translatable default).
 *
 * @return string
 */
function lynbro_cookie_consent_get_description() {
	$desc = (string) lynbro_cookie_consent_get_option( 'description', '' );
	if ( '' === trim( $desc ) ) {
		$desc = __( 'We use cookies to enhance your browsing experience, serve personalized content and analyze our traffic. You can accept all cookies, reject non-essential ones, or manage your preferences.', 'lynbro-cookie-consent' );
	}
	return $desc;
}

/**
 * Sanitize the full options array. Used as the Settings API sanitize callback.
 *
 * @param mixed $input Raw input from the settings form.
 * @return array<string,mixed> Clean settings.
 */
function lynbro_cookie_consent_sanitize_options( $input ) {
	$defaults = lynbro_cookie_consent_default_options();
	$clean    = array();

	if ( ! is_array( $input ) ) {
		$input = array();
	}

	// Consent mode (whitelist).
	$allowed_modes         = array( 'geo', 'opt-in', 'opt-out', 'notice' );
	$mode                  = isset( $input['consent_mode'] ) ? sanitize_key( $input['consent_mode'] ) : $defaults['consent_mode'];
	$clean['consent_mode'] = in_array( $mode, $allowed_modes, true ) ? $mode : $defaults['consent_mode'];

	// Position (whitelist).
	$allowed_positions = array( 'bottom-bar', 'top', 'box', 'center-modal' );
	$position          = isset( $input['position'] ) ? sanitize_key( $input['position'] ) : $defaults['position'];
	$clean['position'] = in_array( $position, $allowed_positions, true ) ? $position : $defaults['position'];

	// Box corner (whitelist).
	$allowed_corners     = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );
	$box_corner          = isset( $input['box_corner'] ) ? sanitize_key( $input['box_corner'] ) : $defaults['box_corner'];
	$clean['box_corner'] = in_array( $box_corner, $allowed_corners, true ) ? $box_corner : $defaults['box_corner'];

	// Policy URL.
	$clean['policy_url'] = isset( $input['policy_url'] ) ? esc_url_raw( trim( (string) $input['policy_url'] ) ) : '';

	// Policy version (positive integer).
	$clean['policy_version'] = isset( $input['policy_version'] ) ? max( 1, absint( $input['policy_version'] ) ) : 1;

	// Google Consent Mode toggle.
	$clean['enable_gcm'] = empty( $input['enable_gcm'] ) ? 0 : 1;

	// Texts — allow basic inline markup in description, plain text for title.
	$clean['title']       = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
	$clean['description'] = isset( $input['description'] ) ? wp_kses( (string) $input['description'], lynbro_cookie_consent_allowed_text_html() ) : '';

	// Theme (whitelist).
	$allowed_themes = array( 'light', 'dark', 'auto' );
	$theme          = isset( $input['theme'] ) ? sanitize_key( $input['theme'] ) : $defaults['theme'];
	$clean['theme'] = in_array( $theme, $allowed_themes, true ) ? $theme : $defaults['theme'];

	// Banner geometry (px, clamped to sane ranges). 0 means "use the CSS default".
	$clean['banner_max_width'] = isset( $input['banner_max_width'] ) ? min( 2000, absint( $input['banner_max_width'] ) ) : $defaults['banner_max_width'];
	$clean['banner_radius']    = isset( $input['banner_radius'] ) ? min( 40, absint( $input['banner_radius'] ) ) : $defaults['banner_radius'];
	$clean['banner_offset']    = isset( $input['banner_offset'] ) ? min( 200, absint( $input['banner_offset'] ) ) : $defaults['banner_offset'];

	// Stacking base (z-index). Clamp to a safe non-zero range.
	$clean['z_index_base'] = isset( $input['z_index_base'] ) ? min( 2147483000, max( 1, absint( $input['z_index_base'] ) ) ) : $defaults['z_index_base'];

	// Colours— sanitize_hex_color returns null on invalid; fall back to default.
	$color_keys = array(
		'color_bg',
		'color_text',
		'color_accept',
		'color_reject',
		'color_btn_text',
		'color_bg_dark',
		'color_text_dark',
		'color_accept_dark',
		'color_reject_dark',
		'color_btn_text_dark',
	);
	foreach ( $color_keys as $ckey ) {
		$raw            = isset( $input[ $ckey ] ) ? sanitize_hex_color( (string) $input[ $ckey ] ) : null;
		$clean[ $ckey ] = ( null === $raw || '' === $raw ) ? $defaults[ $ckey ] : $raw;
	}

	// Categories — only allow known optional category keys; necessary is implicit.
	$all_categories     = lynbro_cookie_consent_get_categories();
	$optional_keys      = array();
	foreach ( $all_categories as $cat_key => $cat ) {
		if ( empty( $cat['locked'] ) ) {
			$optional_keys[] = $cat_key;
		}
	}
	$selected             = isset( $input['categories'] ) && is_array( $input['categories'] ) ? array_map( 'sanitize_key', $input['categories'] ) : array();
	$clean['categories']  = array_values( array_intersect( $optional_keys, $selected ) );

	// Floating re-open button.
	$clean['float_button']        = empty( $input['float_button'] ) ? 0 : 1;
	$float_corner                 = isset( $input['float_button_corner'] ) ? sanitize_key( $input['float_button_corner'] ) : $defaults['float_button_corner'];
	$clean['float_button_corner'] = in_array( $float_corner, $allowed_corners, true ) ? $float_corner : $defaults['float_button_corner'];
	$clean['float_button_label']  = isset( $input['float_button_label'] ) ? sanitize_text_field( $input['float_button_label'] ) : '';
	$clean['float_button_icon']   = empty( $input['float_button_icon'] ) ? 0 : 1;

	// Floating button shape (whitelist).
	$allowed_shapes              = array( 'round', 'rounded', 'square' );
	$float_shape                 = isset( $input['float_button_shape'] ) ? sanitize_key( $input['float_button_shape'] ) : $defaults['float_button_shape'];
	$clean['float_button_shape'] = in_array( $float_shape, $allowed_shapes, true ) ? $float_shape : $defaults['float_button_shape'];

	// Floating button size and offsets (px, clamped).
	$clean['float_button_size']     = isset( $input['float_button_size'] ) ? min( 96, max( 24, absint( $input['float_button_size'] ) ) ) : $defaults['float_button_size'];
	$clean['float_button_offset_x'] = isset( $input['float_button_offset_x'] ) ? min( 200, absint( $input['float_button_offset_x'] ) ) : $defaults['float_button_offset_x'];
	$clean['float_button_offset_y'] = isset( $input['float_button_offset_y'] ) ? min( 200, absint( $input['float_button_offset_y'] ) ) : $defaults['float_button_offset_y'];

	// Floating button colours (empty allowed -> fall back to Accept / Button-text at render time).
	$fb_bg                     = isset( $input['float_button_bg'] ) ? sanitize_hex_color( (string) $input['float_button_bg'] ) : null;
	$clean['float_button_bg']  = ( null === $fb_bg ) ? '' : $fb_bg;
	$fb_ic                            = isset( $input['float_button_icon_color'] ) ? sanitize_hex_color( (string) $input['float_button_icon_color'] ) : null;
	$clean['float_button_icon_color'] = ( null === $fb_ic ) ? '' : $fb_ic;

	// Floating button opacity (0.2–1.0, one decimal step).
	$fb_op                        = isset( $input['float_button_opacity'] ) ? (float) $input['float_button_opacity'] : (float) $defaults['float_button_opacity'];
	$fb_op                        = min( 1.0, max( 0.2, $fb_op ) );
	$clean['float_button_opacity'] = round( $fb_op, 2 );

	// Embeds.
	$clean['embeds_enabled'] = empty( $input['embeds_enabled'] ) ? 0 : 1;
	$embed_cat               = isset( $input['embed_category'] ) ? sanitize_key( $input['embed_category'] ) : $defaults['embed_category'];
	$clean['embed_category'] = in_array( $embed_cat, $optional_keys, true ) ? $embed_cat : $defaults['embed_category'];

	// Automatic tracker blocking.
	$clean['auto_block'] = empty( $input['auto_block'] ) ? 0 : 1;

	// GPC.
	$clean['honor_gpc'] = empty( $input['honor_gpc'] ) ? 0 : 1;

	// CCPA link.
	$clean['ccpa_link'] = empty( $input['ccpa_link'] ) ? 0 : 1;

	// Consent log.
	$clean['consent_log']           = empty( $input['consent_log'] ) ? 0 : 1;
	$clean['consent_log_retention'] = isset( $input['consent_log_retention'] ) ? absint( $input['consent_log_retention'] ) : $defaults['consent_log_retention'];

	// Per-page/post exclusions.
	$exclude              = isset( $input['exclude_ids'] ) ? $input['exclude_ids'] : array();
	if ( is_string( $exclude ) ) {
		$exclude = preg_split( '/[\s,]+/', $exclude );
	}
	$exclude              = is_array( $exclude ) ? array_map( 'absint', $exclude ) : array();
	$clean['exclude_ids'] = array_values( array_unique( array_filter( $exclude ) ) );

	// WP Consent API.
	$clean['wp_consent_api'] = empty( $input['wp_consent_api'] ) ? 0 : 1;

	// Statistics / telemetry toggles (Wave D). Like the language fields, these are
	// kept as-is when the submitted form doesn't carry them (e.g. an import that
	// predates them), so they are never silently wiped.
	$current_d = lynbro_cookie_consent_get_options();

	if ( array_key_exists( 'stats_enabled', $input ) ) {
		$clean['stats_enabled'] = empty( $input['stats_enabled'] ) ? 0 : 1;
	} else {
		$clean['stats_enabled'] = ! empty( $current_d['stats_enabled'] ) ? 1 : 0;
	}

	if ( array_key_exists( 'telemetry_enabled', $input ) ) {
		$clean['telemetry_enabled'] = empty( $input['telemetry_enabled'] ) ? 0 : 1;
	} else {
		$clean['telemetry_enabled'] = ! empty( $current_d['telemetry_enabled'] ) ? 1 : 0;
	}

	// Languages (Wave C). These are normally managed by a dedicated form
	// (admin-post handler), so when they are absent from the submitted input
	// (e.g. saving the main settings form), keep the currently stored values
	// instead of wiping them. They are still sanitized when present (import).
	$current = lynbro_cookie_consent_get_options();

	if ( array_key_exists( 'lang_auto_detect', $input ) ) {
		$clean['lang_auto_detect'] = empty( $input['lang_auto_detect'] ) ? 0 : 1;
	} else {
		$clean['lang_auto_detect'] = ! empty( $current['lang_auto_detect'] ) ? 1 : 0;
	}

	if ( array_key_exists( 'lang_switcher', $input ) ) {
		$clean['lang_switcher'] = empty( $input['lang_switcher'] ) ? 0 : 1;
	} else {
		$clean['lang_switcher'] = ! empty( $current['lang_switcher'] ) ? 1 : 0;
	}

	if ( array_key_exists( 'offered_locales', $input ) ) {
		$offered       = is_array( $input['offered_locales'] ) ? $input['offered_locales'] : array();
		$offered_clean = array();
		foreach ( $offered as $locale ) {
			$locale = function_exists( 'lynbro_cookie_consent_sanitize_locale' )
				? lynbro_cookie_consent_sanitize_locale( $locale )
				: '';
			if ( '' !== $locale ) {
				$offered_clean[] = $locale;
			}
		}
		$clean['offered_locales'] = array_values( array_unique( $offered_clean ) );
	} else {
		$clean['offered_locales'] = isset( $current['offered_locales'] ) && is_array( $current['offered_locales'] )
			? $current['offered_locales']
			: array();
	}

	/**
	 * Filter the sanitized options before they are saved.
	 *
	 * @param array $clean Sanitized settings.
	 * @param array $input Raw input.
	 */
	return apply_filters( 'lynbro_cookie_consent_sanitize_options', $clean, $input );
}

/**
 * Is a consent category currently active (locked, or owner-enabled)?
 *
 * @param string $category Category slug.
 * @return bool
 */
function lynbro_cookie_consent_category_is_active( $category ) {
	$category   = sanitize_key( $category );
	$categories = lynbro_cookie_consent_get_categories();
	if ( isset( $categories[ $category ]['locked'] ) && $categories[ $category ]['locked'] ) {
		return true;
	}
	$enabled = (array) lynbro_cookie_consent_get_option( 'categories', array() );
	return in_array( $category, $enabled, true );
}

/**
 * Resolved floating button label (falls back to translatable default).
 *
 * @return string
 */
function lynbro_cookie_consent_get_float_label() {
	$label = (string) lynbro_cookie_consent_get_option( 'float_button_label', '' );
	if ( '' === trim( $label ) ) {
		$label = __( 'Cookie settings', 'lynbro-cookie-consent' );
	}
	return $label;
}

/**
 * Resolve the floating-button background colour.
 *
 * Empty option falls back to the (light) Accept colour, matching the default
 * icon-only translucent look.
 *
 * @return string Hex colour.
 */
function lynbro_cookie_consent_get_float_bg() {
	$bg = (string) lynbro_cookie_consent_get_option( 'float_button_bg', '' );
	if ( '' === trim( $bg ) ) {
		$bg = (string) lynbro_cookie_consent_get_option( 'color_accept', '#1a7f5a' );
	}
	$sanitized = sanitize_hex_color( $bg );
	return $sanitized ? $sanitized : '#1a7f5a';
}

/**
 * Resolve the floating-button icon colour.
 *
 * Empty option falls back to the (light) Button-text colour.
 *
 * @return string Hex colour.
 */
function lynbro_cookie_consent_get_float_icon_color() {
	$color = (string) lynbro_cookie_consent_get_option( 'float_button_icon_color', '' );
	if ( '' === trim( $color ) ) {
		$color = (string) lynbro_cookie_consent_get_option( 'color_btn_text', '#ffffff' );
	}
	$sanitized = sanitize_hex_color( $color );
	return $sanitized ? $sanitized : '#ffffff';
}

/**
 * Allowed HTML for the banner description field.
 *
 * @return array<string,array<string,bool>>
 */
function lynbro_cookie_consent_allowed_text_html() {
	return array(
		'a'      => array(
			'href'   => true,
			'title'  => true,
			'rel'    => true,
			'target' => true,
		),
		'strong' => array(),
		'em'     => array(),
		'br'     => array(),
	);
}
