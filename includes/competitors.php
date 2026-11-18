<?php
/**
 * Other consent plugins: never two banners.
 *
 * When Complianz, CookieYes (Cookie Law Info), Cookiebot or Borlabs Cookie is
 * active, this plugin stays silent on the front end — no banner, no script
 * blocking, no Consent Mode defaults, no consent records — so the visitor sees
 * one banner and one set of choices is in force. The owner sees which plugin is
 * active and can bring its texts and categories over before switching.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * The consent plugins we step aside for.
 *
 * @return array<string,array{name:string,files:string[]}>
 */
function lynbro_cookie_consent_competitor_catalog() {
	return array(
		'complianz' => array(
			'name'  => 'Complianz',
			'files' => array( 'complianz-gdpr/complianz-gpdr.php', 'complianz-gdpr-premium/complianz-gpdr-premium.php' ),
		),
		'cookieyes' => array(
			'name'  => 'CookieYes',
			'files' => array( 'cookie-law-info/cookie-law-info.php', 'cookieyes/cookieyes.php' ),
		),
		'cookiebot' => array(
			'name'  => 'Cookiebot',
			'files' => array( 'cookiebot/cookiebot.php' ),
		),
		'borlabs'   => array(
			'name'  => 'Borlabs Cookie',
			'files' => array( 'borlabs-cookie/borlabs-cookie.php' ),
		),
	);
}

/**
 * Which other consent plugin is active on this site (or network-wide), if any.
 *
 * Read from the active-plugin lists, not from classes, so the answer is the
 * same whatever order the plugins load in.
 *
 * @return string Catalog id, or '' when none is active.
 */
function lynbro_cookie_consent_active_competitor() {
	static $found = null;
	if ( null !== $found && ! defined( 'LYNBRO_COOKIE_CONSENT_TESTING' ) ) {
		return $found;
	}
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	$found = '';
	foreach ( lynbro_cookie_consent_competitor_catalog() as $id => $item ) {
		if ( array_intersect( $item['files'], $active ) ) {
			$found = $id;
			break;
		}
	}
	/**
	 * Filter the detected competing consent plugin ('' = none).
	 *
	 * @param string $found Catalog id.
	 */
	$found = (string) apply_filters( 'lynbro_cookie_consent_active_competitor', $found );
	return $found;
}

/**
 * Front end: stay silent while another consent plugin runs the show.
 *
 * @param bool $show Whether to show.
 * @return bool
 */
function lynbro_cookie_consent_competitor_silence( $show ) {
	return '' !== lynbro_cookie_consent_active_competitor() ? false : $show;
}
add_filter( 'lynbro_cookie_consent_should_show', 'lynbro_cookie_consent_competitor_silence', 1 );

/**
 * What would be brought over from the active plugin.
 *
 * @param string $id Catalog id.
 * @return array{overrides:array<string,array<string,string>>,categories:string[],policy_url:string,note:string}
 */
function lynbro_cookie_consent_competitor_import_preview( $id ) {
	global $wpdb;
	$out = array(
		'overrides'  => array(),
		'categories' => array(),
		'policy_url' => '',
		'note'       => '',
	);
	$site = lynbro_cookie_consent_sanitize_locale( get_locale() );

	if ( 'cookieyes' === $id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- CookieYes keeps its banner in its own table (prefix + fixed name, no input); read once, on an admin screen. %i needs WP 6.2.
		$row = $wpdb->get_row( "SELECT contents FROM `{$wpdb->prefix}cky_banners` WHERE status = 1 ORDER BY banner_default DESC, banner_id ASC LIMIT 1", ARRAY_A );
		$all = $row ? json_decode( (string) $row['contents'], true ) : array();
		foreach ( (array) $all as $lang => $c ) {
			$notice = $c['notice']['elements'] ?? array();
			$btn    = $notice['buttons']['elements'] ?? array();
			$locale = lynbro_cookie_consent_locale_for_language( (string) $lang, $site );
			$out['overrides'][ $locale ] = array_filter(
				array(
					'title'       => (string) ( $notice['title'] ?? '' ),
					'description' => (string) ( $notice['description'] ?? '' ),
					'accept'      => (string) ( $btn['accept'] ?? '' ),
					'reject'      => (string) ( $btn['reject'] ?? '' ),
					'prefs'       => (string) ( $btn['settings'] ?? '' ),
					'save'        => (string) ( $c['categoryPreview']['elements']['buttons']['elements']['save'] ?? '' ),
				)
			);
			if ( '' === $out['policy_url'] && ! empty( $notice['privacyLink'] ) ) {
				$out['policy_url'] = (string) $notice['privacyLink'];
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
		$slugs = (array) $wpdb->get_col( "SELECT slug FROM `{$wpdb->prefix}cky_cookie_categories` WHERE visibility = 1" );
		$map   = array( 'functional' => 'preferences', 'analytics' => 'statistics', 'performance' => 'statistics', 'advertisement' => 'marketing' );
		foreach ( $slugs as $slug ) {
			if ( isset( $map[ $slug ] ) ) {
				$out['categories'][] = $map[ $slug ];
			}
		}
	} elseif ( 'complianz' === $id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Complianz keeps its banner in its own table (prefix + fixed name, no input); read once, on an admin screen. %i needs WP 6.2.
		$row = $wpdb->get_row( "SELECT * FROM `{$wpdb->prefix}cmplz_cookiebanners` ORDER BY `default` DESC, ID ASC LIMIT 1", ARRAY_A );
		if ( $row ) {
			$text = static function ( $value ) {
				$value = maybe_unserialize( $value );
				return is_array( $value ) ? (string) ( $value['text'] ?? '' ) : (string) $value;
			};
			$shown = static function ( $value ) {
				$value = maybe_unserialize( $value );
				return ! is_array( $value ) || ! empty( $value['show'] );
			};
			$out['overrides'][ $site ] = array_filter(
				array(
					'title'       => $text( $row['header'] ?? '' ),
					'description' => (string) ( $row['message_optin'] ?? '' ),
					'accept'      => $text( $row['accept'] ?? '' ),
					'reject'      => $text( $row['dismiss'] ?? '' ),
					'prefs'       => $text( $row['view_preferences'] ?? '' ),
					'save'        => $text( $row['save_preferences'] ?? '' ),
				)
			);
			foreach ( array( 'category_prefs' => 'preferences', 'category_stats' => 'statistics', 'category_all' => 'marketing' ) as $col => $ours ) {
				if ( isset( $row[ $col ] ) && $shown( $row[ $col ] ) ) {
					$out['categories'][] = $ours;
				}
			}
		}
		if ( function_exists( 'cmplz_get_document_url' ) ) {
			$out['policy_url'] = (string) cmplz_get_document_url( 'cookie-statement', 'eu' );
		}
	} elseif ( 'cookiebot' === $id ) {
		// Cookiebot's texts live in its cloud account; its categories are fixed.
		$out['categories'] = array( 'preferences', 'statistics', 'marketing' );
		$out['note']       = __( 'Cookiebot keeps its banner texts in your Cookiebot account, so only its categories can be brought over. The banner texts here start from Lynbro\'s own, translated defaults.', 'lynbro-cookie-consent' );
	} else {
		$out['note'] = __( 'Borlabs Cookie keeps its settings in its own format, which is not read. Its categories map to Preferences, Statistics and Marketing; set your texts on the Languages tab.', 'lynbro-cookie-consent' );
	}

	$out['categories'] = array_values( array_unique( $out['categories'] ) );
	return $out;
}

/**
 * A WordPress locale for a language code another plugin uses ("da" -> da_DK).
 *
 * @param string $lang Language code.
 * @param string $site Site locale, used when the code matches it.
 * @return string
 */
function lynbro_cookie_consent_locale_for_language( $lang, $site ) {
	$lang = strtolower( str_replace( '-', '_', $lang ) );
	if ( 0 === strpos( strtolower( $site ), $lang ) ) {
		return $site;
	}
	$known = array( 'en' => 'en_US', 'da' => 'da_DK', 'uk' => 'uk', 'de' => 'de_DE', 'sv' => 'sv_SE', 'nb' => 'nb_NO', 'no' => 'nb_NO', 'fr' => 'fr_FR', 'es' => 'es_ES', 'nl' => 'nl_NL', 'pl' => 'pl_PL', 'fi' => 'fi' );
	$locale = $known[ $lang ] ?? $lang;
	return '' !== lynbro_cookie_consent_sanitize_locale( $locale ) ? $locale : $site;
}

/**
 * Bring the active plugin's texts and categories over (admin-post).
 *
 * Fills in: our texts per language (theirs win where they have one), the
 * enabled categories, and the policy link if ours is empty. Nothing of theirs
 * is changed. Their banner stays the one visitors see until it is deactivated.
 *
 * @return void
 */
function lynbro_cookie_consent_competitor_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'lynbro-cookie-consent' ) );
	}
	check_admin_referer( 'lynbro_cookie_consent_competitor_import' );
	$id = lynbro_cookie_consent_active_competitor();
	if ( '' !== $id ) {
		$data = lynbro_cookie_consent_competitor_import_preview( $id );
		if ( $data['overrides'] ) {
			$merged = lynbro_cookie_consent_all_overrides();
			foreach ( $data['overrides'] as $locale => $fields ) {
				$merged[ $locale ] = array_merge( isset( $merged[ $locale ] ) ? (array) $merged[ $locale ] : array(), $fields );
			}
			update_option( LYNBRO_COOKIE_CONSENT_LANG_OPTION, lynbro_cookie_consent_sanitize_overrides( $merged ) );
		}
		$options = lynbro_cookie_consent_get_options();
		if ( $data['categories'] ) {
			$options['categories'] = $data['categories'];
		}
		if ( '' === (string) $options['policy_url'] && '' !== $data['policy_url'] ) {
			$options['policy_url'] = esc_url_raw( $data['policy_url'] );
		}
		update_option( LYNBRO_COOKIE_CONSENT_OPTION, $options );
		update_option( 'lynbro_cookie_consent_imported_from', $id, false );
	}
	wp_safe_redirect( add_query_arg( 'lynbro-cc-imported', $id, admin_url( 'admin.php?page=lynbro-cookie-consent' ) ) );
	exit;
}
add_action( 'admin_post_lynbro_cookie_consent_competitor_import', 'lynbro_cookie_consent_competitor_import' );

/**
 * The notice: on our screen with the import, on the dashboard and Plugins screen as a pointer.
 *
 * @return void
 */
function lynbro_cookie_consent_competitor_notice() {
	$id = lynbro_cookie_consent_active_competitor();
	if ( '' === $id || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$ours   = doing_action( 'lynbro_cookie_consent_page_notices' );
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $ours && ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'dashboard-network', 'plugins-network' ), true ) ) ) {
		return;
	}
	$name = lynbro_cookie_consent_competitor_catalog()[ $id ]['name'];
	echo '<div class="notice notice-warning lynbro-cc-competitor"><p><strong>';
	/* translators: %s: name of the other consent plugin, e.g. Complianz. */
	echo esc_html( sprintf( __( '%s is active, so Lynbro Cookie Consent stays silent.', 'lynbro-cookie-consent' ), $name ) );
	echo '</strong> ';
	esc_html_e( 'Visitors see one banner, not two: ours is not shown, blocks no scripts and keeps no consent records while the other plugin is active.', 'lynbro-cookie-consent' );
	echo '</p>';
	if ( ! $ours ) {
		printf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=lynbro-cookie-consent' ) ), esc_html__( 'See what can be brought over', 'lynbro-cookie-consent' ) );
		echo '</div>';
		return;
	}
	$preview = lynbro_cookie_consent_competitor_import_preview( $id );
	$labels  = array();
	foreach ( $preview['overrides'] as $locale => $fields ) {
		if ( $fields ) {
			$labels[] = lynbro_cookie_consent_locale_label( $locale );
		}
	}
	echo '<ul class="ul-disc">';
	if ( $labels ) {
		/* translators: %s: list of languages. */
		echo '<li>' . esc_html( sprintf( __( 'Banner texts in: %s', 'lynbro-cookie-consent' ), implode( ', ', $labels ) ) ) . '</li>';
	}
	if ( $preview['categories'] ) {
		/* translators: %s: list of category keys. */
		echo '<li>' . esc_html( sprintf( __( 'Categories: %s', 'lynbro-cookie-consent' ), implode( ', ', $preview['categories'] ) ) ) . '</li>';
	}
	if ( '' !== $preview['policy_url'] ) {
		/* translators: %s: address of the cookie or privacy policy. */
		echo '<li>' . esc_html( sprintf( __( 'Policy link: %s', 'lynbro-cookie-consent' ), $preview['policy_url'] ) ) . '</li>';
	}
	echo '</ul>';
	if ( '' !== $preview['note'] ) {
		echo '<p>' . esc_html( $preview['note'] ) . '</p>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
	wp_nonce_field( 'lynbro_cookie_consent_competitor_import' );
	echo '<input type="hidden" name="action" value="lynbro_cookie_consent_competitor_import" />';
	/* translators: %s: name of the other consent plugin. */
	submit_button( sprintf( __( 'Bring over the texts and categories from %s', 'lynbro-cookie-consent' ), $name ), 'secondary', 'submit', false );
	echo '</p></form>';
	echo '<p class="description">' . esc_html__( 'Nothing of the other plugin is changed. After bringing them over, check the result here, then deactivate the other plugin: our banner takes over from that moment.', 'lynbro-cookie-consent' ) . '</p>';
	echo '</div>';
}
add_action( 'admin_notices', 'lynbro_cookie_consent_competitor_notice' );
add_action( 'network_admin_notices', 'lynbro_cookie_consent_competitor_notice' );
add_action( 'lynbro_cookie_consent_page_notices', 'lynbro_cookie_consent_competitor_notice', 1 );
