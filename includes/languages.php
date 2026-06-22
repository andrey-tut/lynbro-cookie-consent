<?php
/**
 * Multilingual engine (Wave C) — cache-safe banner language handling.
 *
 * The PHP side never varies the rendered HTML by the visitor's browser, which
 * would poison full-page caches (WP Rocket / LiteSpeed / Cloudflare). Instead:
 *
 *  - The *initial* banner language follows a cache-safe cascade:
 *      1. current page language of an active multilingual plugin
 *         (Polylang / WPML / TranslatePress) — tied to the URL, cache-safe;
 *      2. the site locale via determine_locale() / get_locale();
 *      3. the en_US fallback.
 *  - For browser auto-detection and the manual switcher, PHP collects the
 *    translations of every core banner string for each "offered" locale (using
 *    switch_to_locale()) and ships them to JavaScript as a single object
 *    { locale: { key: text } }. The HTML stays identical for everyone, so the
 *    cache is never broken; banner.js picks the best locale from
 *    navigator.languages and swaps the text on the client.
 *
 * Render priority for every string (both server and JS object):
 *      per-language override  ->  .mo translation  ->  English default.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Option name where per-language text overrides live (array keyed by locale).
 */
if ( ! defined( 'LYNBRO_COOKIE_CONSENT_LANG_OPTION' ) ) {
	define( 'LYNBRO_COOKIE_CONSENT_LANG_OPTION', 'lynbro_cookie_consent_languages' );
}

/**
 * Default offered locales when the owner has not chosen any yet.
 *
 * Site locale is always merged in at runtime; these are sensible, widely-used
 * extras so browser auto-detection works out of the box.
 *
 * @return string[]
 */
function lynbro_cookie_consent_default_offered_locales() {
	// Offer every bundled language out of the box, so the banner is fully
	// multilingual on activation (auto-detection + switcher cover all of them).
	return array_keys( lynbro_cookie_consent_available_locales() );
}

/**
 * Validate a WordPress locale code (whitelist format: xx, xx_XX or xx_XX_variant).
 *
 * @param string $locale Candidate locale.
 * @return string Sanitized locale, or '' when invalid.
 */
function lynbro_cookie_consent_sanitize_locale( $locale ) {
	$locale = (string) $locale;
	// Allow letters, digits and single underscores: e.g. en, en_US, ca_valencia, pt_BR.
	if ( ! preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$/', $locale ) ) {
		return '';
	}
	return $locale;
}

/**
 * List locales that ship a compiled .mo file in /languages, plus en_US.
 *
 * @return array<string,string> locale => display label.
 */
function lynbro_cookie_consent_available_locales() {
	$locales = array( 'en_US' => lynbro_cookie_consent_locale_label( 'en_US' ) );

	$dir = LYNBRO_COOKIE_CONSENT_DIR . 'languages/';
	$mo  = glob( $dir . 'lynbro-cookie-consent-*.mo' );
	if ( is_array( $mo ) ) {
		foreach ( $mo as $path ) {
			$file   = basename( $path, '.mo' );
			$locale = lynbro_cookie_consent_sanitize_locale( str_replace( 'lynbro-cookie-consent-', '', $file ) );
			if ( '' !== $locale ) {
				$locales[ $locale ] = lynbro_cookie_consent_locale_label( $locale );
			}
		}
	}

	// Include any custom locales the owner added overrides for.
	foreach ( array_keys( lynbro_cookie_consent_all_overrides() ) as $locale ) {
		if ( ! isset( $locales[ $locale ] ) ) {
			$locales[ $locale ] = lynbro_cookie_consent_locale_label( $locale );
		}
	}

	/**
	 * Filter the list of selectable banner locales.
	 *
	 * @param array $locales locale => label map.
	 */
	$locales = apply_filters( 'lynbro_cookie_consent_available_locales', $locales );
	ksort( $locales );
	return $locales;
}

/**
 * Human-readable label for a locale code (best effort, translatable fallback).
 *
 * @param string $locale Locale code.
 * @return string
 */
function lynbro_cookie_consent_locale_label( $locale ) {
	$names = array(
		'en_US' => 'English',
		'da_DK' => 'Dansk',
		'de_DE' => 'Deutsch',
		'fr_FR' => 'Français',
		'es_ES' => 'Español',
		'nl_NL' => 'Nederlands',
		'sv_SE' => 'Svenska',
		'nb_NO' => 'Norsk bokmål',
		'fi'    => 'Suomi',
		'it_IT' => 'Italiano',
		'pt_BR' => 'Português (Brasil)',
		'pt_PT' => 'Português',
		'pl_PL' => 'Polski',
		'cs_CZ' => 'Čeština',
		'ro_RO' => 'Română',
		'hu_HU' => 'Magyar',
		'el'    => 'Ελληνικά',
		'sk_SK' => 'Slovenčina',
		'bg_BG' => 'Български',
		'hr'    => 'Hrvatski',
		'uk'    => 'Українська',
		'ru_RU' => 'Русский',
		'tr_TR' => 'Türkçe',
		'ja'    => '日本語',
		'zh_CN' => '简体中文',
		'et'    => 'Eesti',
		'lt_LT' => 'Lietuvių',
		'lv'    => 'Latviešu',
		'sl_SI' => 'Slovenščina',
		'ga'    => 'Gaeilge',
		'mt_MT' => 'Malti',
		'id_ID' => 'Bahasa Indonesia',
		'ko_KR' => '한국어',
		'ar'    => 'العربية',
		'he_IL' => 'עברית',
		'fa_IR' => 'فارسی',
	);
	if ( isset( $names[ $locale ] ) ) {
		return $names[ $locale ];
	}
	$short = strtok( $locale, '_' );
	return isset( $names[ $short ] ) ? $names[ $short ] : $locale;
}

/**
 * The locales the owner offers to visitors (auto-detect + manual switcher),
 * always including the site locale, all sanitized and de-duplicated.
 *
 * @return string[]
 */
function lynbro_cookie_consent_offered_locales() {
	$stored = lynbro_cookie_consent_get_option( 'offered_locales', array() );
	if ( ! is_array( $stored ) || empty( $stored ) ) {
		$stored = lynbro_cookie_consent_default_offered_locales();
	}

	$site = lynbro_cookie_consent_sanitize_locale( get_locale() );
	if ( '' === $site ) {
		$site = 'en_US';
	}

	$list = array( $site );
	foreach ( $stored as $locale ) {
		$locale = lynbro_cookie_consent_sanitize_locale( $locale );
		if ( '' !== $locale ) {
			$list[] = $locale;
		}
	}

	$list = array_values( array_unique( $list ) );

	/**
	 * Filter the offered locales for the banner.
	 *
	 * @param string[] $list Offered locale codes.
	 */
	return apply_filters( 'lynbro_cookie_consent_offered_locales', $list );
}

/**
 * Resolve the initial banner locale (cache-safe cascade).
 *
 * @return string A locale code (always valid).
 */
function lynbro_cookie_consent_current_locale() {
	$locale = '';

	// 1. Active multilingual plugin's current page language.
	if ( function_exists( 'pll_current_language' ) ) {
		$pll = pll_current_language( 'locale' );
		if ( $pll ) {
			$locale = (string) $pll;
		}
	}
	if ( '' === $locale && defined( 'ICL_LANGUAGE_CODE' ) ) {
		$wpml = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own public filter; the name is defined by WPML, not by this plugin.
		if ( $wpml ) {
			// WPML returns a language code; map to a locale via its API when possible.
			$wpml_locale = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own public filter; the name is defined by WPML, not by this plugin.
			$locale      = (string) ( $wpml_locale ? $wpml_locale : $wpml );
		}
	}
	if ( '' === $locale && function_exists( 'trp_get_current_language' ) ) {
		$trp = trp_get_current_language();
		if ( $trp ) {
			$locale = (string) $trp;
		}
	}

	// 2. Site locale.
	if ( '' === $locale ) {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	}

	$locale = lynbro_cookie_consent_sanitize_locale( $locale );

	// 3. Fallback.
	if ( '' === $locale ) {
		$locale = 'en_US';
	}

	/**
	 * Filter the resolved initial banner locale.
	 *
	 * @param string $locale Locale code.
	 */
	return apply_filters( 'lynbro_cookie_consent_current_locale', $locale );
}

/**
 * All stored per-language overrides (array keyed by locale => key => text).
 *
 * @return array<string,array<string,string>>
 */
function lynbro_cookie_consent_all_overrides() {
	$stored = get_option( LYNBRO_COOKIE_CONSENT_LANG_OPTION, array() );
	return is_array( $stored ) ? $stored : array();
}

/**
 * Overrides for a single locale.
 *
 * @param string $locale Locale code.
 * @return array<string,string>
 */
function lynbro_cookie_consent_overrides_for( $locale ) {
	$all = lynbro_cookie_consent_all_overrides();
	return isset( $all[ $locale ] ) && is_array( $all[ $locale ] ) ? $all[ $locale ] : array();
}

/**
 * The catalog of translatable core banner strings.
 *
 * Each entry: key => array( 'default' => translatable default, 'label' => admin label ).
 * The defaults are produced with __() so they follow the active .mo translation.
 * Category labels/descriptions are added dynamically from the category catalog.
 *
 * @return array<string,array<string,string>>
 */
function lynbro_cookie_consent_string_catalog() {
	$catalog = array(
		'title'        => array(
			'default' => lynbro_cookie_consent_get_title(),
			'label'   => __( 'Banner title', 'lynbro-cookie-consent' ),
		),
		'description'  => array(
			'default' => lynbro_cookie_consent_get_description(),
			'label'   => __( 'Banner description', 'lynbro-cookie-consent' ),
		),
		'accept'       => array(
			'default' => __( 'Accept all', 'lynbro-cookie-consent' ),
			'label'   => __( 'Accept button', 'lynbro-cookie-consent' ),
		),
		'reject'       => array(
			'default' => __( 'Reject all', 'lynbro-cookie-consent' ),
			'label'   => __( 'Reject button', 'lynbro-cookie-consent' ),
		),
		'prefs'        => array(
			'default' => __( 'Manage preferences', 'lynbro-cookie-consent' ),
			'label'   => __( 'Manage-preferences button', 'lynbro-cookie-consent' ),
		),
		'save'         => array(
			'default' => __( 'Save preferences', 'lynbro-cookie-consent' ),
			'label'   => __( 'Save-preferences button', 'lynbro-cookie-consent' ),
		),
		'readMore'     => array(
			'default' => __( 'Read more', 'lynbro-cookie-consent' ),
			'label'   => __( '"Read more" link', 'lynbro-cookie-consent' ),
		),
	);

	// Category names + descriptions, so they can be overridden per language too.
	foreach ( lynbro_cookie_consent_get_categories() as $cat_key => $cat ) {
		$catalog[ 'cat_' . $cat_key . '_label' ] = array(
			'default' => (string) $cat['label'],
			/* translators: %s: consent category slug. */
			'label'   => sprintf( __( 'Category “%s” — name', 'lynbro-cookie-consent' ), $cat_key ),
		);
		$catalog[ 'cat_' . $cat_key . '_desc' ] = array(
			'default' => (string) $cat['description'],
			/* translators: %s: consent category slug. */
			'label'   => sprintf( __( 'Category “%s” — description', 'lynbro-cookie-consent' ), $cat_key ),
		);
	}

	/**
	 * Filter the catalog of overridable core banner strings.
	 *
	 * @param array $catalog Strings keyed by id.
	 */
	return apply_filters( 'lynbro_cookie_consent_string_catalog', $catalog );
}

/**
 * Resolve every core banner string for a given locale.
 *
 * Priority: per-language override -> .mo translation (via switch_to_locale)
 * -> English default. Cache-safe: only used to build the JS object or the
 * initial server render (the latter still keyed by the page URL).
 *
 * @param string $locale Locale code.
 * @return array<string,string> key => resolved text.
 */
function lynbro_cookie_consent_strings_for_locale( $locale ) {
	$locale    = lynbro_cookie_consent_sanitize_locale( $locale );
	$overrides = lynbro_cookie_consent_overrides_for( $locale );

	$current = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

	// Build the catalogue with ENGLISH source strings (msgids). English is always
	// the in-code source, so on a non-English site we briefly switch to en_US to
	// obtain the untranslated keys to look up in the .mo files.
	$switched = false;
	if ( 'en_US' !== $current && function_exists( 'switch_to_locale' ) ) {
		$switched = switch_to_locale( 'en_US' );
	}
	$catalog = lynbro_cookie_consent_string_catalog();
	if ( $switched ) {
		restore_previous_locale();
	}

	// Read the target locale's bundled .mo DIRECTLY via the MO reader. WordPress'
	// just-in-time loading only targets WordPress.org language packs, so it never
	// applies the .mo files bundled inside this plugin (neither for a switched
	// locale nor before the plugin is published). Reading the file ourselves is
	// deterministic and cache-proof.
	$mo = null;
	if ( '' !== $locale && $locale !== 'en_US' ) {
		$mofile = LYNBRO_COOKIE_CONSENT_DIR . 'languages/lynbro-cookie-consent-' . $locale . '.mo';
		if ( is_readable( $mofile ) ) {
			if ( ! class_exists( 'MO' ) ) {
				require_once ABSPATH . WPINC . '/pomo/mo.php';
			}
			$reader = new MO();
			if ( $reader->import_from_file( $mofile ) ) {
				$mo = $reader;
			}
		}
	}

	$out = array();
	foreach ( $catalog as $key => $entry ) {
		if ( isset( $overrides[ $key ] ) && '' !== trim( (string) $overrides[ $key ] ) ) {
			$out[ $key ] = (string) $overrides[ $key ];
			continue;
		}
		$default     = (string) $entry['default'];
		$out[ $key ] = $mo ? (string) $mo->translate( $default ) : $default;
	}

	return $out;
}

/**
 * Build the per-locale translation object for JavaScript.
 *
 * Only the offered locales are included (not all 35), keeping payload small.
 *
 * @return array<string,array<string,string>> locale => key => text.
 */
function lynbro_cookie_consent_js_translations() {
	$offered = lynbro_cookie_consent_offered_locales();

	// With every language offered this resolves dozens of .mo files, so cache the
	// assembled map. The key changes whenever the version, offered locales, banner
	// texts/categories or per-language overrides change, so it self-invalidates.
	$signature = wp_json_encode(
		array(
			LYNBRO_COOKIE_CONSENT_VERSION,
			$offered,
			lynbro_cookie_consent_string_catalog(),
			lynbro_cookie_consent_all_overrides(),
		)
	);
	$key    = 'lynbro_cc_js_tr_' . md5( (string) $signature );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$out = array();
	foreach ( $offered as $locale ) {
		$out[ $locale ] = lynbro_cookie_consent_strings_for_locale( $locale );
	}

	set_transient( $key, $out, WEEK_IN_SECONDS );
	return $out;
}

/**
 * Locale list for the front-end language switcher (code => label),
 * limited to the offered locales.
 *
 * @return array<string,string>
 */
function lynbro_cookie_consent_switcher_locales() {
	$out = array();
	foreach ( lynbro_cookie_consent_offered_locales() as $locale ) {
		$out[ $locale ] = lynbro_cookie_consent_locale_label( $locale );
	}
	return $out;
}

/**
 * Sanitize the per-language overrides submitted from the admin form.
 *
 * Stored as its own option (not part of the main settings array) so the
 * settings export/import stays lean. Title/buttons are plain text; the
 * description allows the same limited inline HTML as the main description.
 *
 * @param array $raw Raw posted overrides: locale => key => value.
 * @return array<string,array<string,string>>
 */
function lynbro_cookie_consent_sanitize_overrides( $raw ) {
	$clean = array();
	if ( ! is_array( $raw ) ) {
		return $clean;
	}

	$catalog   = lynbro_cookie_consent_string_catalog();
	$html_keys = array( 'description' );

	foreach ( $raw as $locale => $fields ) {
		$locale = lynbro_cookie_consent_sanitize_locale( $locale );
		if ( '' === $locale || ! is_array( $fields ) ) {
			continue;
		}
		$entry = array();
		foreach ( $fields as $key => $value ) {
			$key = sanitize_key( $key );
			if ( ! isset( $catalog[ $key ] ) ) {
				continue;
			}
			$value = (string) $value;
			if ( '' === trim( $value ) ) {
				continue; // Empty override falls back to translation/default.
			}
			if ( in_array( $key, $html_keys, true ) ) {
				$entry[ $key ] = wp_kses( $value, lynbro_cookie_consent_allowed_text_html() );
			} else {
				$entry[ $key ] = sanitize_text_field( $value );
			}
		}
		if ( ! empty( $entry ) ) {
			$clean[ $locale ] = $entry;
		}
	}

	return $clean;
}

/**
 * Handle saving of the per-language overrides + offered locales (admin-post).
 *
 * @return void
 */
function lynbro_cookie_consent_save_languages() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'lynbro-cookie-consent' ) );
	}
	check_admin_referer( 'lynbro_cookie_consent_save_languages' );

	$redirect = add_query_arg(
		array( 'page' => 'lynbro-cookie-consent' ),
		admin_url( 'options-general.php' )
	);

	// Offered locales (multi-select).
	$offered = isset( $_POST['lynbro_cc_offered'] ) ? (array) wp_unslash( $_POST['lynbro_cc_offered'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value sanitized below.
	$offered_clean = array();
	foreach ( $offered as $locale ) {
		$locale = lynbro_cookie_consent_sanitize_locale( $locale );
		if ( '' !== $locale ) {
			$offered_clean[] = $locale;
		}
	}

	// A custom locale the owner typed in (optional).
	if ( ! empty( $_POST['lynbro_cc_custom_locale'] ) ) {
		$custom = lynbro_cookie_consent_sanitize_locale( sanitize_text_field( wp_unslash( $_POST['lynbro_cc_custom_locale'] ) ) );
		if ( '' !== $custom ) {
			$offered_clean[] = $custom;
		}
	}
	$offered_clean = array_values( array_unique( $offered_clean ) );

	// Persist offered locales inside the main settings array.
	$options                    = lynbro_cookie_consent_get_options();
	$options['offered_locales'] = $offered_clean;
	$options['lang_auto_detect'] = empty( $_POST['lynbro_cc_lang_auto'] ) ? 0 : 1;
	$options['lang_switcher']    = empty( $_POST['lynbro_cc_lang_switcher'] ) ? 0 : 1;
	update_option( LYNBRO_COOKIE_CONSENT_OPTION, $options );

	// Per-language overrides (separate option).
	$raw_overrides = isset( $_POST['lynbro_cc_lang'] ) ? (array) wp_unslash( $_POST['lynbro_cc_lang'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in callback.
	$overrides     = lynbro_cookie_consent_sanitize_overrides( $raw_overrides );
	update_option( LYNBRO_COOKIE_CONSENT_LANG_OPTION, $overrides );

	wp_safe_redirect( add_query_arg( 'lynbro_cc_lang_saved', '1', $redirect ) . '#lynbro-cc-tab-languages' );
	exit;
}
add_action( 'admin_post_lynbro_cookie_consent_save_languages', 'lynbro_cookie_consent_save_languages' );

/**
 * Admin notice after saving languages.
 *
 * @return void
 */
function lynbro_cookie_consent_languages_notice() {
	if ( empty( $_GET['lynbro_cc_lang_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag.
		return;
	}
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html__( 'Language settings saved.', 'lynbro-cookie-consent' )
	);
}
add_action( 'admin_notices', 'lynbro_cookie_consent_languages_notice' );
