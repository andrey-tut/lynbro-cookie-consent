<?php
/**
 * Front-end banner: rendering, asset loading and Google Consent Mode v2 stub.
 *
 * All dynamic output is escaped. No external requests are made.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cookie name that stores the visitor's consent choice on the front end (JS).
 */
if ( ! defined( 'LYNBRO_COOKIE_CONSENT_COOKIE' ) ) {
	define( 'LYNBRO_COOKIE_CONSENT_COOKIE', 'lynbro_cc_consent' );
}

/**
 * Decide whether the banner should be loaded for the current request.
 *
 * @return bool
 */
function lynbro_cookie_consent_should_show() {
	$show = ! is_admin();

	// Per-page/post exclusions: hide the banner machinery on chosen content.
	if ( $show ) {
		$exclude = (array) lynbro_cookie_consent_get_option( 'exclude_ids', array() );
		if ( ! empty( $exclude ) ) {
			$current_id = 0;
			if ( function_exists( 'is_singular' ) && is_singular() ) {
				$current_id = (int) get_queried_object_id();
			}
			if ( $current_id && in_array( $current_id, array_map( 'absint', $exclude ), true ) ) {
				$show = false;
			}
		}
	}

	/**
	 * Filter whether the banner machinery should load.
	 *
	 * @param bool $show Whether to show.
	 */
	return (bool) apply_filters( 'lynbro_cookie_consent_should_show', $show );
}

/**
 * Build the front-end configuration array shared with JavaScript.
 *
 * @return array<string,mixed>
 */
function lynbro_cookie_consent_frontend_config() {
	$options    = lynbro_cookie_consent_get_options();
	$mode       = lynbro_cookie_consent_resolve_mode();
	$categories = lynbro_cookie_consent_get_categories();
	$enabled    = (array) $options['categories'];

	// Map categories -> GCM signals for the active (owner-enabled) categories only.
	$cat_signals = array();
	foreach ( $categories as $key => $cat ) {
		if ( ! empty( $cat['locked'] ) || in_array( $key, $enabled, true ) ) {
			$cat_signals[ $key ] = isset( $cat['gcm'] ) ? array_values( (array) $cat['gcm'] ) : array();
		}
	}

	$config = array(
		'cookieName'      => LYNBRO_COOKIE_CONSENT_COOKIE,
		'consentVersion'  => (int) lynbro_cookie_consent_effective_consent_version(),
		'mode'            => $mode, // opt-in|opt-out|notice.
		'enableGcm'       => ! empty( $options['enable_gcm'] ),
		'categorySignals' => $cat_signals,
		// One year cookie lifetime in days.
		'cookieDays'      => 365,
		// GPC (Global Privacy Control).
		'honorGpc'        => ! empty( $options['honor_gpc'] ),
		// Embeds.
		'embeds'          => ! empty( $options['embeds_enabled'] ),
		'embedCategory'   => sanitize_key( $options['embed_category'] ),
		// Consent log REST endpoint (nonce-protected).
		'log'             => ! empty( $options['consent_log'] ),
		'restUrl'         => esc_url_raw( rest_url( 'lynbro-cookie-consent/v1/consent' ) ),
		'restNonce'       => wp_create_nonce( 'wp_rest' ),
		// Aggregated, cookieless statistics beacon (nonce-protected, opt-out option).
		'stats'           => ! empty( $options['stats_enabled'] ),
		'statUrl'         => esc_url_raw( rest_url( 'lynbro-cc/v1/stat' ) ),
		// WP Consent API bridge.
		'wpConsentApi'    => ! empty( $options['wp_consent_api'] ),
		'wpcaMap'         => function_exists( 'lynbro_cookie_consent_wpca_category_map' ) ? lynbro_cookie_consent_wpca_category_map() : array(),
		// Page language (cache-safe: tied to the URL via get_locale / multilingual plugins).
		'lang'            => function_exists( 'lynbro_cookie_consent_current_locale' ) ? lynbro_cookie_consent_current_locale() : ( function_exists( 'determine_locale' ) ? determine_locale() : get_locale() ),
		// Browser language auto-detection (client-side only — cache-safe).
		'autoDetectLang'  => ! empty( $options['lang_auto_detect'] ),
		// In-banner manual language switcher.
		'langSwitcher'    => ! empty( $options['lang_switcher'] ),
		// Cookie remembering the visitor's chosen banner language.
		'langCookie'      => 'lynbro_cc_lang',
		// Per-locale translations of every core string { locale: { key: text } }.
		'translations'    => function_exists( 'lynbro_cookie_consent_js_translations' ) ? lynbro_cookie_consent_js_translations() : array(),
		// Offered locales as code => label (for the switcher dropdown).
		'locales'         => function_exists( 'lynbro_cookie_consent_switcher_locales' ) ? lynbro_cookie_consent_switcher_locales() : array(),
		// Strings used by JS-built UI (GPC toast).
		'i18n'            => array(
			'gpcApplied'    => __( 'We detected a Global Privacy Control signal and automatically opted you out of non-essential cookies.', 'lynbro-cookie-consent' ),
			'languageLabel' => __( 'Choose language', 'lynbro-cookie-consent' ),
		),
	);

	/**
	 * Filter the front-end JavaScript configuration.
	 *
	 * @param array $config Configuration array passed to banner.js.
	 */
	return apply_filters( 'lynbro_cookie_consent_frontend_config', $config );
}

/**
 * Build the Google Consent Mode v2 default stub JavaScript.
 *
 * Sets all signals to denied (security_storage granted) before any tag loads,
 * so consent-aware Google tags behave correctly until the visitor chooses.
 *
 * @return string Inline JavaScript, or empty string when GCM is disabled.
 */
function lynbro_cookie_consent_gcm_stub_js() {
	if ( ! lynbro_cookie_consent_should_show() ) {
		return '';
	}

	$options = lynbro_cookie_consent_get_options();
	if ( empty( $options['enable_gcm'] ) ) {
		return '';
	}

	$defaults = lynbro_cookie_consent_gcm_default_signals();

	// wait_for_update gives the consent JS a brief window to apply a stored choice.
	$default_obj                    = $defaults;
	$default_obj['wait_for_update'] = 500;

	$json = wp_json_encode( $default_obj );
	if ( false === $json ) {
		return '';
	}

	return "window.dataLayer = window.dataLayer || [];\n"
		. "function gtag(){dataLayer.push(arguments);}\n"
		. 'gtag(\'consent\', \'default\', ' . $json . ');';
}

/**
 * Register and enqueue the early head-stub script handle and attach the Google
 * Consent Mode v2 default stub as an inline script.
 *
 * The handle has no external src; the inline JS is printed in <head> via the
 * `wp_head` enqueue at priority 1 so consent defaults load before any tags.
 *
 * @return void
 */
function lynbro_cookie_consent_enqueue_gcm_stub() {
	$js = lynbro_cookie_consent_gcm_stub_js();
	if ( '' === $js ) {
		return;
	}

	// Registration-only handle (no file): a vehicle for the inline stub in <head>.
	wp_register_script( 'lynbro-cookie-consent-gcm', false, array(), LYNBRO_COOKIE_CONSENT_VERSION, false );
	wp_enqueue_script( 'lynbro-cookie-consent-gcm' );
	wp_add_inline_script( 'lynbro-cookie-consent-gcm', $js, 'before' );
}
add_action( 'wp_enqueue_scripts', 'lynbro_cookie_consent_enqueue_gcm_stub', 0 );

/**
 * Print the head-stub handle early in <head> (priority 1) so the Google Consent
 * Mode defaults are set before any consent-aware tags execute.
 *
 * @return void
 */
function lynbro_cookie_consent_print_gcm_stub() {
	if ( ! wp_script_is( 'lynbro-cookie-consent-gcm', 'enqueued' ) && ! wp_script_is( 'lynbro-cookie-consent-gcm', 'registered' ) ) {
		return;
	}
	wp_print_scripts( 'lynbro-cookie-consent-gcm' );
}
add_action( 'wp_head', 'lynbro_cookie_consent_print_gcm_stub', 1 );

/**
 * Enqueue front-end CSS/JS and pass configuration.
 *
 * @return void
 */
function lynbro_cookie_consent_enqueue_assets() {
	if ( ! lynbro_cookie_consent_should_show() ) {
		return;
	}

	wp_enqueue_style(
		'lynbro-cookie-consent',
		LYNBRO_COOKIE_CONSENT_URL . 'public/banner.css',
		array(),
		lynbro_cookie_consent_asset_ver( 'public/banner.css' )
	);

	// Inline CSS custom properties from the saved colours (theme-aware).
	wp_add_inline_style( 'lynbro-cookie-consent', lynbro_cookie_consent_build_inline_css() );

	wp_enqueue_script(
		'lynbro-cookie-consent',
		LYNBRO_COOKIE_CONSENT_URL . 'public/banner.js',
		array(),
		lynbro_cookie_consent_asset_ver( 'public/banner.js' ),
		true
	);

	wp_localize_script(
		'lynbro-cookie-consent',
		'lynbroCookieConsentConfig',
		lynbro_cookie_consent_frontend_config()
	);
}
add_action( 'wp_enqueue_scripts', 'lynbro_cookie_consent_enqueue_assets' );

/**
 * Build the inline CSS (custom properties for light/dark themes).
 *
 * @return string
 */
function lynbro_cookie_consent_build_inline_css() {
	$options = lynbro_cookie_consent_get_options();

	$light = sprintf(
		'--lynbro-cc-bg:%1$s;--lynbro-cc-text:%2$s;--lynbro-cc-accept:%3$s;--lynbro-cc-reject:%4$s;--lynbro-cc-btn-text:%5$s;',
		esc_attr( $options['color_bg'] ),
		esc_attr( $options['color_text'] ),
		esc_attr( $options['color_accept'] ),
		esc_attr( $options['color_reject'] ),
		esc_attr( $options['color_btn_text'] )
	);

	$dark = sprintf(
		'--lynbro-cc-bg:%1$s;--lynbro-cc-text:%2$s;--lynbro-cc-accept:%3$s;--lynbro-cc-reject:%4$s;--lynbro-cc-btn-text:%5$s;',
		esc_attr( $options['color_bg_dark'] ),
		esc_attr( $options['color_text_dark'] ),
		esc_attr( $options['color_accept_dark'] ),
		esc_attr( $options['color_reject_dark'] ),
		esc_attr( $options['color_btn_text_dark'] )
	);

	// Geometry + stacking custom properties (theme-independent).
	$z_base    = (int) $options['z_index_base'];
	$geometry  = '';
	$geometry .= sprintf( '--lynbro-cc-z-base:%d;', $z_base );
	// Overlay/root sits above page; the float button sits just below the banner.
	$geometry .= sprintf( '--lynbro-cc-z-root:%d;', $z_base + 5 );
	$geometry .= sprintf( '--lynbro-cc-z-float:%d;', $z_base - 1 );
	$geometry .= sprintf( '--lynbro-cc-z-toast:%d;', $z_base + 10 );

	$offset = (int) $options['banner_offset'];
	$geometry .= sprintf( '--lynbro-cc-offset:%dpx;', $offset );

	if ( (int) $options['banner_max_width'] > 0 ) {
		$geometry .= sprintf( '--lynbro-cc-max-width:%dpx;', (int) $options['banner_max_width'] );
		$geometry .= sprintf( '--lynbro-cc-modal-width:%dpx;', (int) $options['banner_max_width'] );
	}
	if ( (int) $options['banner_radius'] > 0 ) {
		$geometry .= sprintf( '--lynbro-cc-radius:%dpx;', (int) $options['banner_radius'] );
	}

	// Floating button geometry + colours.
	$geometry .= sprintf( '--lynbro-cc-float-size:%dpx;', (int) $options['float_button_size'] );
	$geometry .= sprintf( '--lynbro-cc-float-x:%dpx;', (int) $options['float_button_offset_x'] );
	$geometry .= sprintf( '--lynbro-cc-float-y:%dpx;', (int) $options['float_button_offset_y'] );
	$geometry .= sprintf( '--lynbro-cc-float-opacity:%s;', esc_attr( (string) (float) $options['float_button_opacity'] ) );
	$geometry .= sprintf( '--lynbro-cc-float-bg:%s;', esc_attr( lynbro_cookie_consent_get_float_bg() ) );
	$geometry .= sprintf( '--lynbro-cc-float-icon:%s;', esc_attr( lynbro_cookie_consent_get_float_icon_color() ) );

	$theme = $options['theme'];
	$css   = '';

	if ( 'dark' === $theme ) {
		$css .= ':root{' . $dark . $geometry . '}';
	} elseif ( 'auto' === $theme ) {
		// Light by default, dark when the OS prefers it (cache-safe: pure CSS).
		$css .= ':root{' . $light . $geometry . '}';
		$css .= '@media (prefers-color-scheme: dark){:root{' . $dark . '}}';
	} else {
		$css .= ':root{' . $light . $geometry . '}';
	}

	return $css;
}

/**
 * Add cache/optimizer-safety attributes to our core script tag, and register
 * exclusions with popular caching/optimization plugins so they never defer,
 * delay, combine or minify our consent core (which would break the banner).
 *
 * @param string $tag    The full <script> tag.
 * @param string $handle The script handle.
 * @return string
 */
function lynbro_cookie_consent_script_attributes( $tag, $handle ) {
	if ( 'lynbro-cookie-consent' !== $handle ) {
		return $tag;
	}
	// data-no-optimize/defer/minify: WP Rocket, SiteGround, Autoptimize, etc.
	// data-cfasync="false": Cloudflare Rocket Loader.
	$attrs = ' data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false"';
	// Inject the attributes into the existing tag WordPress built (we modify the
	// tag, we do not emit a new script), matching the opening tag without a
	// literal script-tag string in plugin code.
	$out = preg_replace_callback(
		'#<\s*script\b#i',
		static function ( $m ) use ( $attrs ) {
			return $m[0] . $attrs;
		},
		$tag,
		1
	);
	return ( null === $out ) ? $tag : $out;
}
add_filter( 'script_loader_tag', 'lynbro_cookie_consent_script_attributes', 10, 2 );

/**
 * Register cache/optimizer exclusions for our core handle and source path.
 *
 * Keeps the consent core out of "delay JS", "defer", "combine" and "minify"
 * features of the common optimization stack so it always runs immediately.
 *
 * @return void
 */
function lynbro_cookie_consent_register_cache_exclusions() {
	$needle = 'lynbro-cookie-consent';

	$add_needle = static function ( $list ) use ( $needle ) {
		$list   = is_array( $list ) ? $list : array();
		$list[] = $needle;
		return $list;
	};

	// WP Rocket.
	add_filter( 'rocket_exclude_js', $add_needle );
	add_filter( 'rocket_minify_excluded_external_js', $add_needle );
	add_filter( 'rocket_delay_js_exclusions', $add_needle );
	add_filter( 'rocket_exclude_defer_js', $add_needle );

	// LiteSpeed Cache.
	add_filter( 'litespeed_optm_js_defer_exc', $add_needle );
	add_filter( 'litespeed_optm_gm_js_exc', $add_needle );

	// SiteGround Optimizer.
	add_filter( 'sgo_js_minify_exclude', $add_needle );
	add_filter( 'sgo_js_async_exclude', $add_needle );
	add_filter( 'sgo_javascript_combine_exclude', $add_needle );
	add_filter( 'sgo_javascript_combine_excluded_inline_content', $add_needle );

	// Autoptimize.
	add_filter( 'autoptimize_filter_js_exclude', static function ( $excl ) use ( $needle ) {
		return ( is_string( $excl ) && '' !== $excl ) ? $excl . ',' . $needle : $needle;
	} );
}
add_action( 'wp_enqueue_scripts', 'lynbro_cookie_consent_register_cache_exclusions', 1 );

/**
 * Render the banner markup in the footer.
 *
 * @return void
 */
function lynbro_cookie_consent_render_banner() {
	if ( ! lynbro_cookie_consent_should_show() ) {
		return;
	}

	$options    = lynbro_cookie_consent_get_options();
	$categories = lynbro_cookie_consent_get_categories();
	$enabled    = (array) $options['categories'];
	$position   = $options['position'];
	$mode       = lynbro_cookie_consent_resolve_mode();
	$policy_url = $options['policy_url'];

	$title       = lynbro_cookie_consent_get_title();
	$description = lynbro_cookie_consent_get_description();

	/**
	 * Filter banner template arguments before rendering.
	 *
	 * @param array $args Banner arguments.
	 */
	$args = apply_filters(
		'lynbro_cookie_consent_banner_args',
		array(
			'title'       => $title,
			'description' => $description,
			'position'    => $position,
			'box_corner'  => $options['box_corner'],
			'mode'        => $mode,
			'policy_url'  => $policy_url,
			'categories'  => $categories,
			'enabled'     => $enabled,
			'theme'       => $options['theme'],
			'ccpa_link'   => ! empty( $options['ccpa_link'] ),
			'lang_switcher' => ! empty( $options['lang_switcher'] ),
		)
	);

	$is_modal = ( 'center-modal' === $args['position'] );

	$wrapper_class  = 'lynbro-cc lynbro-cc--' . sanitize_html_class( $args['position'] );
	$wrapper_class .= ' lynbro-cc--mode-' . sanitize_html_class( $args['mode'] );
	$wrapper_class .= ' lynbro-cc--theme-' . sanitize_html_class( $args['theme'] );
	if ( 'box' === $args['position'] ) {
		$wrapper_class .= ' lynbro-cc--corner-' . sanitize_html_class( $args['box_corner'] );
	}
	if ( is_rtl() ) {
		$wrapper_class .= ' lynbro-cc--rtl';
	}

	ob_start();
	?>
	<div id="lynbro-cc-root" class="<?php echo esc_attr( $wrapper_class ); ?>" hidden>
		<div class="lynbro-cc__dialog" role="dialog" aria-modal="<?php echo $is_modal ? 'true' : 'false'; ?>"
			aria-labelledby="lynbro-cc-title" aria-describedby="lynbro-cc-desc">

			<div class="lynbro-cc__body">
				<?php if ( ! empty( $args['lang_switcher'] ) ) : ?>
					<div class="lynbro-cc__lang" hidden>
						<label class="lynbro-cc__lang-label" for="lynbro-cc-lang">
							<?php echo esc_html__( 'Choose language', 'lynbro-cookie-consent' ); ?>
						</label>
						<select id="lynbro-cc-lang" class="lynbro-cc__lang-select" data-lynbro-cc-lang-select="1"></select>
					</div>
				<?php endif; ?>
				<h2 id="lynbro-cc-title" class="lynbro-cc__title" data-lynbro-cc-text="title"><?php echo esc_html( $args['title'] ); ?></h2>
				<div id="lynbro-cc-desc" class="lynbro-cc__desc">
					<span class="lynbro-cc__desc-text" data-lynbro-cc-html="description"><?php echo wp_kses( $args['description'], lynbro_cookie_consent_allowed_text_html() ); ?></span>
					<?php if ( ! empty( $args['policy_url'] ) ) : ?>
						<a class="lynbro-cc__policy" href="<?php echo esc_url( $args['policy_url'] ); ?>" rel="nofollow" data-lynbro-cc-text="readMore">
							<?php echo esc_html__( 'Read more', 'lynbro-cookie-consent' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<?php
				// CCPA/CPRA "Do Not Sell or Share" — shown in opt-out (US) mode.
				if ( ! empty( $args['ccpa_link'] ) && 'opt-out' === $args['mode'] ) :
					?>
					<p class="lynbro-cc__ccpa">
						<button type="button" class="lynbro-cc__link" data-lynbro-cc-action="dns">
							<?php echo esc_html__( 'Do Not Sell or Share My Personal Information', 'lynbro-cookie-consent' ); ?>
						</button>
					</p>
				<?php endif; ?>

				<div class="lynbro-cc__categories" hidden>
					<?php foreach ( $args['categories'] as $cat_key => $cat ) : ?>
						<?php
						$is_locked = ! empty( $cat['locked'] );
						// Skip optional categories the owner disabled.
						if ( ! $is_locked && ! in_array( $cat_key, $args['enabled'], true ) ) {
							continue;
						}
						$field_id = 'lynbro-cc-cat-' . sanitize_html_class( $cat_key );
						?>
						<div class="lynbro-cc__category">
							<label class="lynbro-cc__category-head" for="<?php echo esc_attr( $field_id ); ?>">
								<input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>"
									class="lynbro-cc__toggle"
									data-lynbro-cc-category="<?php echo esc_attr( $cat_key ); ?>"
									<?php echo $is_locked ? 'checked disabled' : ''; ?> />
								<span class="lynbro-cc__category-name" data-lynbro-cc-text="cat_<?php echo esc_attr( $cat_key ); ?>_label"><?php echo esc_html( $cat['label'] ); ?></span>
								<?php if ( $is_locked ) : ?>
									<span class="lynbro-cc__badge"><?php echo esc_html__( 'Always on', 'lynbro-cookie-consent' ); ?></span>
								<?php endif; ?>
							</label>
							<p class="lynbro-cc__category-desc" data-lynbro-cc-text="cat_<?php echo esc_attr( $cat_key ); ?>_desc"><?php echo esc_html( $cat['description'] ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="lynbro-cc__actions">
				<button type="button" class="lynbro-cc__btn lynbro-cc__btn--reject" data-lynbro-cc-action="reject" data-lynbro-cc-text="reject">
					<?php echo esc_html__( 'Reject all', 'lynbro-cookie-consent' ); ?>
				</button>
				<button type="button" class="lynbro-cc__btn lynbro-cc__btn--prefs" data-lynbro-cc-action="prefs" aria-expanded="false" data-lynbro-cc-text="prefs">
					<?php echo esc_html__( 'Manage preferences', 'lynbro-cookie-consent' ); ?>
				</button>
				<button type="button" class="lynbro-cc__btn lynbro-cc__btn--save" data-lynbro-cc-action="save" data-lynbro-cc-text="save" hidden>
					<?php echo esc_html__( 'Save preferences', 'lynbro-cookie-consent' ); ?>
				</button>
				<button type="button" class="lynbro-cc__btn lynbro-cc__btn--accept" data-lynbro-cc-action="accept" data-lynbro-cc-text="accept">
					<?php echo esc_html__( 'Accept all', 'lynbro-cookie-consent' ); ?>
				</button>
			</div>
		</div>
	</div>
	<?php
	$html = ob_get_clean();

	/**
	 * Filter the final banner HTML.
	 *
	 * @param string $html Banner markup.
	 * @param array  $args Banner arguments.
	 */
	echo apply_filters( 'lynbro_cookie_consent_banner_html', $html, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup assembled with esc_* above.
}
add_action( 'wp_footer', 'lynbro_cookie_consent_render_banner' );

/**
 * Render the floating "Cookie settings" re-open button in the footer.
 *
 * It is hidden until the visitor has made a choice (the JS reveals it), so it
 * never competes with the banner itself. Configurable corner, label and icon.
 *
 * @return void
 */
function lynbro_cookie_consent_render_float_button() {
	if ( ! lynbro_cookie_consent_should_show() ) {
		return;
	}
	$options = lynbro_cookie_consent_get_options();
	if ( empty( $options['float_button'] ) ) {
		return;
	}

	$corner   = sanitize_html_class( $options['float_button_corner'] );
	$shape    = sanitize_html_class( $options['float_button_shape'] );
	$show_icon = ! empty( $options['float_button_icon'] );
	$label    = lynbro_cookie_consent_get_float_label();

	$classes  = 'lynbro-cc-float lynbro-cc-float--' . $corner;
	$classes .= ' lynbro-cc-float--shape-' . $shape;
	if ( ! $show_icon ) {
		// No icon: show the text label instead of an icon-only pill.
		$classes .= ' lynbro-cc-float--text';
	}
	?>
	<button type="button" id="lynbro-cc-float" class="<?php echo esc_attr( $classes ); ?>"
		data-lynbro-cc-open="1" aria-label="<?php echo esc_attr( $label ); ?>"
		title="<?php echo esc_attr( $label ); ?>" hidden>
		<?php if ( $show_icon ) : ?>
			<span class="lynbro-cc-float__icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="22" height="22" focusable="false" fill="currentColor">
					<path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-4-4 4 4 0 0 1-4-4 2 2 0 0 1-2-2zm-3 7a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm7 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm-7 3a1.25 1.25 0 1 1 0 2.5A1.25 1.25 0 0 1 9 16z"/>
				</svg>
			</span>
		<?php endif; ?>
		<span class="lynbro-cc-float__label"><?php echo esc_html( $label ); ?></span>
	</button>
	<?php
}
add_action( 'wp_footer', 'lynbro_cookie_consent_render_float_button', 20 );
