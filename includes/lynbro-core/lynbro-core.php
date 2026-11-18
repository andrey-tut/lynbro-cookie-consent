<?php
/**
 * Lynbro shared admin shell — implementation.
 *
 * Loaded by lynbro-shell.php, and only ever from the ONE copy that won the
 * version election. Everything here may therefore change freely between
 * versions: unlike the registry in the bootstrap, no two versions of this file
 * are ever in memory at the same time.
 *
 * What it provides:
 *   - Lynbro_Admin_Menu: deferred registration of the shared "Lynbro" menu.
 *   - The suite home ("Our plugins") built from data, not from a hard-coded list.
 *   - The brand stylesheet.
 *   - The Tools (export / reset) panel each plugin can drop into its own screen.
 *   - Bundled translations of every suite plugin found on admin screens.
 *   - Lynbro_Core_Feedback: the review request (after real use, on the
 *     plugin's own screen, once) and "Suggested for this site".
 *
 * @package lynbro-core
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LYNBRO_CORE_VERSION' ) ) {
	define( 'LYNBRO_CORE_VERSION', '1.1.3' );
}

if ( ! function_exists( 'lynbro_core_file_version' ) ) {
	/**
	 * Cache-busting version for a shell asset: its modification time.
	 *
	 * The shell is vendored into every plugin, so a hard-coded version meant the
	 * winning copy's stylesheet stayed in browser caches after every edit.
	 *
	 * @param string $file Absolute path to the asset.
	 * @return string
	 */
	function lynbro_core_file_version( $file ) {
		$mtime = is_readable( $file ) ? (int) filemtime( $file ) : 0;
		return $mtime ? (string) $mtime : LYNBRO_CORE_VERSION;
	}
}

/* -------------------------------------------------------------------------
 * Bundled translations for every suite plugin.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'lynbro_core_bundled_lang_dir' ) ) {
	/**
	 * Let WordPress find a suite plugin's own languages/ folder.
	 *
	 * Since 4.6 WordPress loads plugin translations just in time, but only from
	 * wp-content/languages/plugins/ — where wordpress.org language packs land.
	 * Most Lynbro plugins are deployed straight to our sites and ship their
	 * catalogues in languages/ instead, and almost none of them call
	 * load_plugin_textdomain(), so those catalogues were never read: a Danish
	 * admin saw English. That includes every string of this shell, which
	 * speaks in the text domain of whichever plugin won the election.
	 *
	 * The filter only answers when WordPress found nothing itself, so a
	 * language pack from translate.wordpress.org always wins, and only for the
	 * suite's own text domains (text domain == plugin folder == suite slug).
	 *
	 * @param string|false $path   Folder WordPress found, or false.
	 * @param string       $domain Text domain.
	 * @param string       $locale Locale.
	 * @return string|false
	 */
	function lynbro_core_bundled_lang_dir( $path, $domain, $locale ) {
		if ( ! empty( $path ) || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return $path;
		}
		static $suite = null;
		if ( null === $suite ) {
			$suite = array();
			foreach ( lynbro_core_suite_data() as $module ) {
				if ( ! empty( $module['slug'] ) ) {
					$suite[ (string) $module['slug'] ] = true;
				}
			}
		}
		if ( ! isset( $suite[ $domain ] ) ) {
			return $path;
		}
		$dir = rtrim( WP_PLUGIN_DIR, '/\\' ) . '/' . $domain . '/languages/';
		return is_readable( $dir . $domain . '-' . $locale . '.mo' ) ? $dir : $path;
	}
	add_filter( 'lang_dir_for_domain', 'lynbro_core_bundled_lang_dir', 10, 3 );
}

/* -------------------------------------------------------------------------
 * The shared "Lynbro" menu.
 * ---------------------------------------------------------------------- */

if ( ! class_exists( 'Lynbro_Admin_Menu', false ) ) {

	/**
	 * Deferred registration of the shared menu — buffer first, register once.
	 *
	 * The problem this solves: eleven plugins all calling add_submenu_page(
	 * 'lynbro', ... ) means eleven plugins racing to be loaded after whoever
	 * created the parent, and add_menu_page() does not deduplicate slugs, so
	 * losing that race produces a second identical "Lynbro" menu rather than an
	 * error. The shape below is Jetpack's (automattic/jetpack-admin-ui): add()
	 * only writes to a static array and hands back the hook name the page WILL
	 * have, so a caller can attach to load-{$hook} straight away. One single
	 * registration then happens late, at admin_menu priority 1000, with the
	 * buffer sorted first — because add_submenu_page ignores $position when the
	 * parent is a plugin menu (core trac #52035), the order has to be decided
	 * before the calls, not by them.
	 */
	class Lynbro_Admin_Menu {

		/**
		 * Slug of the shared parent menu.
		 */
		const PARENT = 'lynbro';

		/**
		 * Buffered items, slug => args.
		 *
		 * @var array<string,array<string,mixed>>
		 */
		private static $items = array();

		/**
		 * Whether the buffer has already been flushed into wp-admin.
		 *
		 * @var bool
		 */
		private static $registered = false;

		/**
		 * Capability the parent entry and the suite home were registered with.
		 *
		 * @var string
		 */
		private static $parent_cap = 'manage_options';

		/**
		 * Slugs registered but kept out of the menu listing.
		 *
		 * @var array<int,string>
		 */
		private static $hidden = array();

		/**
		 * Queue one page under the shared Lynbro menu.
		 *
		 * @param array<string,mixed> $args {
		 *     @type string   $slug       Page slug. Required.
		 *     @type string   $page_title <title> of the page.
		 *     @type string   $menu_title Menu label. WooCommerce's UX guidelines
		 *                                ask for <= 20 characters, functional
		 *                                rather than promotional.
		 *     @type string   $capability Capability required. Default manage_options.
		 *     @type callable $callback   Renderer.
		 *     @type int      $position   Sort key inside the Lynbro menu. Leave
		 *                                it out: the canonical order lives in
		 *                                lynbro-suite.json, so it can be changed
		 *                                by syncing the shell instead of by
		 *                                releasing fourteen plugins.
		 *     @type bool     $hidden     Register the page but keep it out of the
		 *                                menu — for a plugin's own inner screens,
		 *                                reached from its tab strip. With a dozen
		 *                                plugins in one menu, only the plugin's
		 *                                main screen belongs in the list.
		 * }
		 * @return string The hook suffix this page will have, e.g. lynbro_page_lynbro-antispam.
		 */
		public static function add( $args ) {
			$args = wp_parse_args(
				(array) $args,
				array(
					'slug'       => '',
					'page_title' => '',
					'menu_title' => '',
					'capability' => 'manage_options',
					'callback'   => null,
					'position'   => null,
					'hidden'     => false,
				)
			);

			$slug = sanitize_key( $args['slug'] );
			if ( '' === $slug ) {
				return '';
			}

			$args['slug']       = $slug;
			$args['position']   = null === $args['position'] ? lynbro_core_menu_position( $slug ) : (int) $args['position'];
			$args['hidden']     = (bool) $args['hidden'];
			$args['menu_title'] = '' !== $args['menu_title'] ? $args['menu_title'] : $args['page_title'];
			$args['page_title'] = '' !== $args['page_title'] ? $args['page_title'] : $args['menu_title'];
			// Stable tie-break so two pages sharing a position keep the order
			// they were added in rather than shuffling between requests.
			$args['seq'] = count( self::$items );

			self::$items[ $slug ] = $args;

			// Added after the flush (a plugin hooking admin_menu later than we
			// do): register it on the spot rather than dropping it.
			if ( self::$registered ) {
				self::add_page( $args );
			}

			return self::hook_suffix( $slug );
		}

		/**
		 * The capability the shared parent ended up requiring.
		 *
		 * It is the capability of whichever page sorts first, which is not
		 * always manage_options — Image Optimizer, for one, has its own. The
		 * suite home is registered with it, so the suite home has to check it:
		 * hard-coding manage_options there would put an item in the menu that
		 * refuses to open.
		 *
		 * @return string
		 */
		public static function parent_capability() {
			return self::$parent_cap;
		}

		/**
		 * The hook suffix a Lynbro page has, known before it is registered.
		 *
		 * @param string $slug Page slug.
		 * @return string
		 */
		public static function hook_suffix( $slug ) {
			return 'lynbro_page_' . sanitize_key( $slug );
		}

		/**
		 * Everything queued so far, sorted for display.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public static function get_items() {
			$items = array_values( self::$items );
			usort(
				$items,
				static function ( $a, $b ) {
					if ( $a['position'] === $b['position'] ) {
						return $a['seq'] <=> $b['seq'];
					}
					return $a['position'] <=> $b['position'];
				}
			);
			return $items;
		}

		/**
		 * Build the menu. One pass, late, after every plugin has had its say.
		 *
		 * @return void
		 */
		public static function register() {
			if ( self::$registered ) {
				return;
			}
			self::$registered = true;

			$items = array();
			foreach ( self::get_items() as $item ) {
				if ( current_user_can( $item['capability'] ) ) {
					$items[] = $item;
				}
			}

			if ( empty( $items ) ) {
				// Nothing this user may open. Do not leave an orphaned parent
				// behind — an editor should not see an empty "Lynbro" menu.
				if ( isset( $GLOBALS['admin_page_hooks'][ self::PARENT ] ) && empty( $GLOBALS['submenu'][ self::PARENT ] ) ) {
					remove_menu_page( self::PARENT );
				}
				return;
			}

			// The parent has to be visible to anyone who can open at least one
			// child; every item left in $items passed its own check, so the
			// first one's capability is a capability this user holds.
			self::$parent_cap = $items[0]['capability'];
			$parent_cap       = self::$parent_cap;
			$owned            = self::ensure_parent( $parent_cap );

			foreach ( $items as $item ) {
				self::add_page( $item );
			}

			if ( ! $owned ) {
				// An older vendored copy built the parent at admin_menu:9 and
				// owns the suite home that goes with it. Adding ours here would
				// register a second callback on the same hook and print the page
				// twice. Our submenus are attached; that is enough.
				return;
			}

			// Core inserts a back-link to the parent as the first submenu item
			// whenever the first child does not reuse the parent slug. Drop it
			// (and anything else claiming the parent slug) and put the suite
			// home at the end, where a cross-promo screen belongs.
			while ( remove_submenu_page( self::PARENT, self::PARENT ) ) {
				continue;
			}

			add_submenu_page(
				self::PARENT,
				__( 'Our plugins', 'lynbro-cookie-consent' ),
				__( 'Our plugins', 'lynbro-cookie-consent' ),
				$parent_cap,
				self::PARENT,
				'lynbro_core_render_suite_home'
			);
		}

		/**
		 * Create the shared parent menu unless something already did.
		 *
		 * @param string $capability Capability for the parent entry.
		 * @return bool True when this copy created it.
		 */
		private static function ensure_parent( $capability ) {
			if ( isset( $GLOBALS['admin_page_hooks'][ self::PARENT ] ) ) {
				return false;
			}

			add_menu_page(
				'Lynbro',
				'Lynbro',
				$capability,
				self::PARENT,
				'lynbro_core_render_suite_home',
				self::icon(),
				58
			);

			// Pin the hook prefix. Core derives it from sanitize_title() of the
			// MENU TITLE, so on a translated site load-{$hook} would fire under
			// a different name in every locale — a bug WooCommerce and Yoast
			// both carry this same line to avoid.
			$GLOBALS['admin_page_hooks'][ self::PARENT ] = self::PARENT;

			return true;
		}

		/**
		 * Register one queued page.
		 *
		 * @param array<string,mixed> $item Page args.
		 * @return void
		 */
		private static function add_page( $item ) {
			add_submenu_page(
				self::PARENT,
				$item['page_title'],
				$item['menu_title'],
				$item['capability'],
				$item['slug'],
				$item['callback']
			);

			if ( $item['hidden'] ) {
				// NOT removed here. wp-admin/includes/menu.php runs
				// user_can_access_admin_page() right after admin_menu, and that
				// function decides access by looking the slug up in $submenu —
				// so a page removed during admin_menu answers "you are not
				// allowed to access this page" when you open it. Verified on
				// test-plugins.lynbro.dk, 09.09.2026, on a whole suite of
				// hidden screens. The removal happens on admin_head instead:
				// after the access check, before menu-header.php draws the
				// sidebar.
				self::$hidden[] = $item['slug'];
			}
		}

		/**
		 * Take the inner screens out of the listing, once the access check that
		 * needs them has already run.
		 *
		 * @return void
		 */
		public static function hide_inner_pages() {
			foreach ( self::$hidden as $slug ) {
				remove_submenu_page( self::PARENT, $slug );
			}
		}

		/**
		 * The menu icon, inline so it needs no HTTP request and no asset URL.
		 *
		 * @return string data: URI.
		 */
		private static function icon() {
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="#a7aaad">'
				. '<path d="M10 2 L4 5 L4 11 C4 14.5 6.5 17 10 18 C13.5 17 16 14.5 16 11 L16 5 Z" /></svg>';

			return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- inline SVG icon, not obfuscation.
		}
	}

	add_action( 'admin_menu', array( 'Lynbro_Admin_Menu', 'register' ), 1000 );
	add_action( 'admin_head', array( 'Lynbro_Admin_Menu', 'hide_inner_pages' ) );
}

/* -------------------------------------------------------------------------
 * The suite list — data, not code.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'lynbro_core_suite_data' ) ) {
	/**
	 * The bundled suite list.
	 *
	 * This used to be a PHP array in the middle of this file, and it went stale
	 * exactly the way a hard-coded list does: the winning copy's array was the
	 * one everybody saw, so a plugin published on wordpress.org kept being
	 * advertised as "coming soon" until every neighbour shipped a release. The
	 * list is now a JSON file — data the sync tool rewrites, not code someone
	 * has to remember to edit — and it is only the offline fallback for the
	 * live check below.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	function lynbro_core_suite_data() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$cache = array();
		$file  = __DIR__ . '/lynbro-suite.json';
		if ( is_readable( $file ) ) {
			$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled file, not a remote request.
			$data = json_decode( (string) $raw, true );
			if ( isset( $data['plugins'] ) && is_array( $data['plugins'] ) ) {
				$cache = $data['plugins'];
			}
		}

		return $cache;
	}
}

if ( ! function_exists( 'lynbro_core_suite_strings' ) ) {
	/**
	 * Translated names and taglines of the suite cards.
	 *
	 * lynbro-suite.json is data, and data cannot be translated: gettext only
	 * sees string literals in code. So the visible text lives here as
	 * literals, in the text domain of whichever plugin carries this copy, and
	 * tests/test-shell.php fails the moment this map and the JSON disagree.
	 * After editing a name or tagline in the JSON, regenerate this map with
	 * `python tools/gen_suite_strings.py` in plugins_wp, sync, and run the
	 * incremental translations.
	 *
	 * Called at render time only — never from lynbro_core_suite_data(), which
	 * also runs inside the translation loader (lang_dir_for_domain) where a
	 * gettext call would recurse.
	 *
	 * @return array<string,array<string,string>> slug => name, tagline.
	 */
	function lynbro_core_suite_strings() {
		return array(
			// BEGIN generated from lynbro-suite.json by tools/gen_suite_strings.py — do not edit by hand.
			'lynbro-image-optimizer' => array(
				'name'    => __( 'Image Optimizer', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Convert to WebP and AVIF, right-size oversized images and lazy-load them — all on your own server.', 'lynbro-cookie-consent' ),
			),
			'lynbro-ai-studio' => array(
				'name'    => __( 'AI Studio', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Write and illustrate posts with AI on your own OpenAI key — at cost, with no credits in between.', 'lynbro-cookie-consent' ),
			),
			'lynbro-analytics' => array(
				'name'    => __( 'Analytics', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Self-hosted, cookieless analytics with funnels. Visitor data never leaves your database.', 'lynbro-cookie-consent' ),
			),
			'lynbro-tags' => array(
				'name'    => __( 'Tags', 'lynbro-cookie-consent' ),
				'tagline' => __( 'GA4, Tag Manager, Google Ads, Meta Pixel and more without code — each tag waits for consent.', 'lynbro-cookie-consent' ),
			),
			'lynbro-forms' => array(
				'name'    => __( 'Forms', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Forms with entries saved for free and no cap, conditional logic checked server-side, and signed webhooks.', 'lynbro-cookie-consent' ),
			),
			'lynbro-verify' => array(
				'name'    => __( 'Verify', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Catch mistyped e-mail domains and bad phone numbers, and confirm them with a one-time code by e-mail or SMS.', 'lynbro-cookie-consent' ),
			),
			'lynbro-cache' => array(
				'name'    => __( 'Cache', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Reliable purging for an nginx FastCGI cache and a Redis object cache, with every purge verified against the cache itself.', 'lynbro-cookie-consent' ),
			),
			'lynbro-booking' => array(
				'name'    => __( 'Booking', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Appointment booking for one business on your own server: services, staff, schedules, reminders and a one-click move from Amelia.', 'lynbro-cookie-consent' ),
			),
			'lynbro-translate' => array(
				'name'    => __( 'Translate', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Run your site in several languages, with machine translation on your own OpenAI key — nothing metered, nothing sold by the word.', 'lynbro-cookie-consent' ),
			),
			'lynbro-floating-buttons' => array(
				'name'    => __( 'Contact Buttons', 'lynbro-cookie-consent' ),
				'tagline' => __( 'WhatsApp, Messenger, phone and live chat in one fast button — chat scripts load only when a visitor asks.', 'lynbro-cookie-consent' ),
			),
			'lynbro-shield' => array(
				'name'    => __( 'Shield', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Security headers, HTTPS with HSTS, a small firewall against injection probes and the hardening switches every site needs.', 'lynbro-cookie-consent' ),
			),
			'lynbro-antispam-guard' => array(
				'name'    => __( 'Anti-Spam', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Spam checks that run on your own server. No account, no subscription, every block says why — and a held message is never lost.', 'lynbro-cookie-consent' ),
			),
			'lynbro-login-guard' => array(
				'name'    => __( 'Login Guard', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Move wp-login.php, limit failed sign-ins and add app-based two-factor — with three ways back in.', 'lynbro-cookie-consent' ),
			),
			'lynbro-cookie-consent' => array(
				'name'    => __( 'Cookie Consent', 'lynbro-cookie-consent' ),
				'tagline' => __( 'A GDPR and ePrivacy banner with Consent Mode v2, an equally prominent Reject button and 35 languages.', 'lynbro-cookie-consent' ),
			),
			'lynbro-activity-log' => array(
				'name'    => __( 'Activity Log', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Who did what, when and from where — written down on your own server, searchable, exportable, and deleted on your schedule.', 'lynbro-cookie-consent' ),
			),
			'lynbro-data-retention' => array(
				'name'    => __( 'Data Retention', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Find where personal data actually sits in your database, then delete it on a schedule — with a dry run before every deletion.', 'lynbro-cookie-consent' ),
			),
			'lynbro-accessibility-checker' => array(
				'name'    => __( 'Accessibility', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Scan every page against WCAG 2.2 AA and fix what can be fixed safely. No overlay, no page limit.', 'lynbro-cookie-consent' ),
			),
			'lynbro-site-check' => array(
				'name'    => __( 'Site Check', 'lynbro-cookie-consent' ),
				'tagline' => __( 'A free website check for your visitors: HTTPS, headers, cookies before consent, accessibility, SPF/DKIM/DMARC — report on screen.', 'lynbro-cookie-consent' ),
			),
			'lynbro-seo' => array(
				'name'    => __( 'SEO', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Lean SEO with free LocalBusiness schema, sitemaps and social cards. Nothing phones home.', 'lynbro-cookie-consent' ),
			),
			'lynbro-smtp-mailer' => array(
				'name'    => __( 'SMTP Mailer', 'lynbro-cookie-consent' ),
				'tagline' => __( 'WordPress email through your own SMTP server — with an email log, resend, a backup connection and failure alerts.', 'lynbro-cookie-consent' ),
			),
			'lynbro-newsletter' => array(
				'name'    => __( 'Newsletter', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Self-hosted newsletters with safe sending: consent records, a test after the last edit and approval by typing the exact recipient count.', 'lynbro-cookie-consent' ),
			),
			'lynbro-social-feed' => array(
				'name'    => __( 'Social Feed', 'lynbro-cookie-consent' ),
				'tagline' => __( 'An Instagram feed on your site without a Meta token stored in WordPress.', 'lynbro-cookie-consent' ),
			),
			'lynbro-reviews-cache' => array(
				'name'    => __( 'Reviews Cache', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Show your Google reviews without putting an API key in WordPress.', 'lynbro-cookie-consent' ),
			),
			'lynbro-links' => array(
				'name'    => __( 'Links', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Find broken links and manage redirects on your own server, with the reason for every verdict.', 'lynbro-cookie-consent' ),
			),
			'lynbro-danadresse' => array(
				'name'    => __( 'Danadresse Autofill', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Danish address autocomplete and validation for WooCommerce, WPForms, Gravity, Fluent, Formidable and EDD.', 'lynbro-cookie-consent' ),
			),
			'lynbro-soge-lookup' => array(
				'name'    => __( 'Soge Lookup', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Look up Danish vehicles (DMR) and companies (CVR) by plate, VIN or CVR number.', 'lynbro-cookie-consent' ),
			),
			'dk-accounting' => array(
				'name'    => __( 'CVR Checkout', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Danish B2B checkout for WooCommerce: CVR company autofill, CVR/EU-VAT (VIES) validation and an EAN/GLN field.', 'lynbro-cookie-consent' ),
			),
			'dk-shipping' => array(
				'name'    => __( 'Danish Shipping', 'lynbro-cookie-consent' ),
				'tagline' => __( 'Free pickup point selection for PostNord and GLS Denmark at the WooCommerce checkout, plus clean shipping methods.', 'lynbro-cookie-consent' ),
			),
			'lynbro-api-auth' => array(
				'name'    => __( 'API Auth', 'lynbro-cookie-consent' ),
				'tagline' => __( 'JSON Web Tokens for the REST API that you can actually revoke, with scopes and a journal of refusals.', 'lynbro-cookie-consent' ),
			),
			// END generated.
		);
	}
}

if ( ! function_exists( 'lynbro_core_localize_module' ) ) {
	/**
	 * A suite card in the admin's language. Plugins the bundled list does not
	 * know (published on wordpress.org later) keep the directory's own text.
	 *
	 * @param array<string,mixed> $module Card data.
	 * @return array<string,mixed>
	 */
	function lynbro_core_localize_module( $module ) {
		static $strings = null;
		if ( null === $strings ) {
			$strings = lynbro_core_suite_strings();
		}
		$slug = isset( $module['slug'] ) ? (string) $module['slug'] : '';
		if ( isset( $strings[ $slug ] ) ) {
			$module['name']    = $strings[ $slug ]['name'];
			$module['tagline'] = $strings[ $slug ]['tagline'];
		}
		return $module;
	}
}

if ( ! function_exists( 'lynbro_core_menu_position' ) ) {
	/**
	 * Where a plugin sits in the shared menu, according to the suite data.
	 *
	 * @param string $slug Plugin slug.
	 * @return int Sort key; anything unknown goes to the bottom.
	 */
	function lynbro_core_menu_position( $slug ) {
		foreach ( lynbro_core_suite_data() as $module ) {
			if ( ! isset( $module['menu_position'] ) ) {
				continue;
			}
			// Most plugins register a page under their own slug. Three do not
			// (soge-lookup, lynbro-reviews, lynbro-instagram), and rather than
			// change a page slug people have bookmarked, the data says so.
			$names = array( $module['slug'] ?? '', $module['menu_slug'] ?? '' );
			if ( in_array( $slug, array_filter( $names ), true ) ) {
				return (int) $module['menu_position'];
			}
		}
		return 500;
	}
}

if ( ! function_exists( 'lynbro_core_wporg_suite' ) ) {
	/**
	 * What wordpress.org currently lists under our author account.
	 *
	 * Asked once a day, through core's own plugins_api() — the same call the
	 * Add Plugins screen makes, to the same host that distributes the plugin
	 * asking. Only ever from our own screen, and only for a user who could
	 * install a plugin anyway.
	 *
	 * @return array<string,array<string,string>> slug => plugin data.
	 */
	function lynbro_core_wporg_suite() {
		$cached = get_transient( 'lynbro_core_wporg_suite' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$response = plugins_api(
			'query_plugins',
			array(
				'author'   => 'lynbro',
				'per_page' => 100,
				'fields'   => array(
					'short_description' => true,
					'icons'             => false,
					'banners'           => false,
					'sections'          => false,
					'reviews'           => false,
					'ratings'           => false,
					'compatibility'     => false,
					'downloadlink'      => false,
				),
			)
		);

		if ( is_wp_error( $response ) || empty( $response->plugins ) ) {
			// Short retry window on failure; never cache "nothing is published"
			// for a day just because the network hiccuped.
			set_transient( 'lynbro_core_wporg_suite', array(), HOUR_IN_SECONDS );
			return array();
		}

		$out = array();
		foreach ( (array) $response->plugins as $plugin ) {
			$plugin = (array) $plugin;
			if ( empty( $plugin['slug'] ) ) {
				continue;
			}
			$out[ (string) $plugin['slug'] ] = array(
				'name'    => isset( $plugin['name'] ) ? wp_strip_all_tags( (string) $plugin['name'] ) : '',
				'tagline' => isset( $plugin['short_description'] ) ? wp_strip_all_tags( (string) $plugin['short_description'] ) : '',
			);
		}

		set_transient( 'lynbro_core_wporg_suite', $out, DAY_IN_SECONDS );

		return $out;
	}
}

if ( ! function_exists( 'lynbro_core_suite_modules' ) ) {
	/**
	 * The suite as it should be shown right now: the bundled list, corrected by
	 * what wordpress.org actually lists today, plus anything published since
	 * this copy was built.
	 *
	 * @param bool $live Whether to consult wordpress.org.
	 * @return array<int,array<string,mixed>>
	 */
	function lynbro_core_suite_modules( $live = true ) {
		$modules = lynbro_core_suite_data();
		$wporg   = $live ? lynbro_core_wporg_suite() : array();

		$known = array();
		foreach ( $modules as $i => $module ) {
			$slug           = isset( $module['slug'] ) ? (string) $module['slug'] : '';
			$known[ $slug ] = true;
			if ( ! empty( $wporg ) ) {
				// The live answer decides. A plugin listed today is listed even
				// if this copy predates its release, and one that is not listed
				// never gets a link to a catalogue page that would 301 to search.
				$modules[ $i ]['wporg'] = isset( $wporg[ $slug ] );
			}
		}

		foreach ( $wporg as $slug => $data ) {
			if ( isset( $known[ $slug ] ) ) {
				continue;
			}
			$modules[] = array(
				'slug'    => $slug,
				'name'    => '' !== $data['name'] ? $data['name'] : $slug,
				'icon'    => '',
				'tagline' => $data['tagline'],
				'wporg'   => true,
			);
		}

		return $modules;
	}
}

if ( ! function_exists( 'lynbro_core_installed_plugins' ) ) {
	/**
	 * Folder slug => plugin file, for everything installed on this site.
	 *
	 * @return array<string,string>
	 */
	function lynbro_core_installed_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			$out[ strtok( (string) $file, '/' ) ] = (string) $file;
		}

		return $out;
	}
}

if ( ! function_exists( 'lynbro_core_render_suite_home' ) ) {
	/**
	 * The "Our plugins" screen: what else the suite contains, and where each
	 * item stands on this site.
	 *
	 * Cross-promotion between one author's plugins is explicitly allowed —
	 * wordpress.org published guidance for "plugin ecosystems" in September
	 * 2024 — under conditions this screen keeps to deliberately:
	 *   - nothing is pre-selected and nothing installs itself; every install is
	 *     one click, on one plugin, made by the person reading the page;
	 *   - every listed plugin links to its page in the directory;
	 *   - the buttons only appear for a user who holds install_plugins /
	 *     activate_plugins;
	 *   - installation goes through core's own installer, which downloads from
	 *     downloads.wordpress.org. Guideline 8 forbids serving plugin code from
	 *     our own servers, so nothing here ever points at one.
	 *
	 * @return void
	 */
	function lynbro_core_render_suite_home() {
		if ( ! current_user_can( Lynbro_Admin_Menu::parent_capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lynbro-cookie-consent' ) );
		}

		$can_install  = current_user_can( 'install_plugins' );
		$can_activate = current_user_can( 'activate_plugins' );
		$installed    = lynbro_core_installed_plugins();
		$modules      = lynbro_core_suite_modules( $can_install );
		?>
		<div class="wrap lynbro-wrap">
			<div class="lynbro-hero">
				<div>
					<h1><?php esc_html_e( 'Lynbro plugins', 'lynbro-cookie-consent' ); ?></h1>
					<p><?php esc_html_e( 'Generous, privacy-first WordPress plugins — built in Denmark. Free cores, no credits, your data stays on your site.', 'lynbro-cookie-consent' ); ?></p>
				</div>
			</div>

			<?php Lynbro_Core_Feedback::render_home_suggestions(); ?>

			<div class="lynbro-section">
				<h2><?php esc_html_e( 'More from Lynbro', 'lynbro-cookie-consent' ); ?></h2>
				<p class="desc"><?php esc_html_e( 'The rest of the suite. What is already on this site is marked; nothing installs without you asking for it.', 'lynbro-cookie-consent' ); ?></p>
				<div class="lynbro-grid">
					<?php
					foreach ( $modules as $module ) :
						$module = lynbro_core_localize_module( $module );
						$slug   = isset( $module['slug'] ) ? (string) $module['slug'] : '';
						$name   = isset( $module['name'] ) ? (string) $module['name'] : $slug;
						$icon   = isset( $module['icon'] ) ? (string) $module['icon'] : '';
						$pitch  = isset( $module['tagline'] ) ? (string) $module['tagline'] : '';
						$listed = ! empty( $module['wporg'] );
						$file   = isset( $installed[ $slug ] ) ? $installed[ $slug ] : '';
						$active = '' !== $file && is_plugin_active( $file );
						if ( '' === $slug ) {
							continue;
						}
						?>
						<div class="lynbro-card">
							<div class="head">
								<?php if ( '' !== $icon ) : ?>
									<span class="ic" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
								<?php endif; ?>
								<div>
									<h3><?php echo esc_html( $name ); ?></h3>
									<?php if ( $active ) : ?>
										<span class="lynbro-badge installed"><?php esc_html_e( 'Active', 'lynbro-cookie-consent' ); ?></span>
									<?php elseif ( '' !== $file ) : ?>
										<span class="lynbro-badge"><?php esc_html_e( 'Installed', 'lynbro-cookie-consent' ); ?></span>
									<?php elseif ( $listed ) : ?>
										<span class="lynbro-badge"><?php esc_html_e( 'On WordPress.org', 'lynbro-cookie-consent' ); ?></span>
									<?php else : ?>
										<span class="lynbro-badge soon"><?php esc_html_e( 'Coming soon', 'lynbro-cookie-consent' ); ?></span>
									<?php endif; ?>
								</div>
							</div>
							<p><?php echo esc_html( $pitch ); ?></p>
							<div class="actions">
								<?php if ( ! $active && '' !== $file && $can_activate ) : ?>
									<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ), 'activate-plugin_' . $file ) ); ?>"><?php esc_html_e( 'Activate', 'lynbro-cookie-consent' ); ?></a>
								<?php elseif ( '' === $file && $listed && $can_install ) : ?>
									<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $slug ) ), 'install-plugin_' . $slug ) ); ?>"><?php esc_html_e( 'Install now', 'lynbro-cookie-consent' ); ?></a>
								<?php endif; ?>
								<?php if ( $listed ) : ?>
									<a class="button" href="<?php echo esc_url( 'https://wordpress.org/plugins/' . $slug . '/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View on WordPress.org', 'lynbro-cookie-consent' ); ?></a>
								<?php else : ?>
									<a class="button" href="<?php echo esc_url( 'https://plugins.lynbro.dk/' . $slug ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'lynbro-cookie-consent' ); ?></a>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="lynbro-section">
				<h2><?php esc_html_e( 'About Lynbro', 'lynbro-cookie-consent' ); ?></h2>
				<p class="desc"><?php esc_html_e( 'Lynbro builds genuinely free, privacy-first WordPress plugins with generous free cores — no credits, no paywalls on the essentials, your data on your own site. Made in Denmark.', 'lynbro-cookie-consent' ); ?></p>
				<p class="lynbro-tools-row">
					<a class="button button-primary" href="https://lynbro.dk" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Visit Lynbro', 'lynbro-cookie-consent' ); ?></a>
					<a class="button" href="https://plugins.lynbro.dk/roadmap" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Suggest a feature or report a bug', 'lynbro-cookie-consent' ); ?></a>
				</p>
				<p class="lynbro-muted"><?php esc_html_e( 'Feedback opens our public roadmap on plugins.lynbro.dk in a new tab — nothing is sent from your site.', 'lynbro-cookie-consent' ); ?></p>
				<p class="lynbro-muted lynbro-provenance"><?php echo esc_html( lynbro_core_provenance() ); ?></p>
			</div>
		</div>
		<?php
	}
}

if ( ! function_exists( 'lynbro_core_provenance' ) ) {
	/**
	 * One line naming which plugin is actually providing the shared components.
	 *
	 * Worth a line of screen real estate: when several plugins each carry a copy
	 * and the newest wins, "which copy am I looking at" is the first question a
	 * support answer needs, and guessing at it wastes a round trip.
	 *
	 * @return string
	 */
	function lynbro_core_provenance() {
		$registry = Lynbro_Core_Versions::instance();
		$file     = $registry->latest_version_file();
		$owner    = '' !== $file ? basename( dirname( dirname( dirname( $file ) ) ) ) : '';
		$count    = count( $registry->get_versions() );

		return sprintf(
			/* translators: 1: version number, 2: plugin folder name, 3: number of distinct versions found. */
			__( 'Shared Lynbro components v%1$s, provided by %2$s (newest of %3$d versions on this site).', 'lynbro-cookie-consent' ),
			LYNBRO_CORE_VERSION,
			'' !== $owner ? $owner : '?',
			$count
		);
	}
}

/* -------------------------------------------------------------------------
 * Review request and suite suggestions.
 *
 * Research and the MUST / NOT-DOING list behind every rule below:
 * plugins_wp/research/lynbro-core-review-and-recommendations.md. The short
 * version: wordpress.org Guideline 11 allows prompts only "contextually or
 * only on the plugin's setting page", Guideline 9 forbids pressuring anyone
 * for a review, and for a suite of two dozen plugins one nag puts the whole
 * author account at risk. So the shell asks quietly, late, once, and only
 * where the plugin lives.
 * ---------------------------------------------------------------------- */

if ( ! class_exists( 'Lynbro_Core_Feedback', false ) ) {

	/**
	 * The review request and the "Suggested for this site" block.
	 *
	 * Decisions are pure static functions taking the clock as an argument, so
	 * tests/test-shell.php can walk through weeks without waiting for them.
	 */
	class Lynbro_Core_Feedback {

		/**
		 * Site option: slug => array( 'first_seen' => int, 'success' => int ).
		 * Shared with the listener in lynbro-shell.php; the shape may only grow.
		 */
		const USAGE_OPTION = 'lynbro_core_usage';

		/**
		 * User meta holding this user's answers (per user — one admin's "no"
		 * says nothing about another admin's opinion).
		 */
		const USER_META = 'lynbro_core_prompts';

		/** Days of use before a plugin may ask. */
		const DELAY_DAYS = 14;

		/** "Maybe later" puts the question off this long. */
		const SNOOZE_DAYS = 30;

		/** After any Lynbro plugin asked, no other one asks the same user for this long. */
		const COOLDOWN_DAYS = 30;

		/** The third "Maybe later" is taken as "no". */
		const MAX_LATER = 2;

		/** Suggestions shown under a plugin's own screen. */
		const INLINE_LIMIT = 3;

		/** admin-post action for every answer. */
		const ACTION = 'lynbro_core_prompt';

		/**
		 * Whether a plugin may ask this user for a review now.
		 *
		 * @param array<string,int>   $usage The plugin's row from USAGE_OPTION.
		 * @param array<string,mixed> $state This user's USER_META array.
		 * @param string              $slug  Plugin slug.
		 * @param int                 $now   Unix time.
		 * @return bool
		 */
		public static function review_due( $usage, $state, $slug, $now ) {
			$usage = is_array( $usage ) ? $usage : array();
			$state = is_array( $state ) ? $state : array();

			if ( empty( $usage['first_seen'] ) || empty( $usage['success'] ) ) {
				return false; // No sign yet that the plugin has done anything for this site.
			}
			if ( $now - (int) $usage['first_seen'] < self::DELAY_DAYS * DAY_IN_SECONDS ) {
				return false;
			}
			if ( ! empty( $state['cooldown_until'] ) && $now < (int) $state['cooldown_until'] ) {
				return false;
			}

			$mine = isset( $state['review'][ $slug ] ) && is_array( $state['review'][ $slug ] ) ? $state['review'][ $slug ] : array();
			$said = isset( $mine['state'] ) ? (string) $mine['state'] : '';
			if ( 'never' === $said || 'reviewed' === $said ) {
				return false;
			}
			if ( 'later' === $said && $now < (int) ( $mine['until'] ?? 0 ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Apply one answer to this user's state.
		 *
		 * @param array<string,mixed> $state  This user's USER_META array.
		 * @param string              $slug   Plugin slug ('' for suite-wide answers).
		 * @param string              $answer later | never | reviewed | hide_suggestions.
		 * @param int                 $now    Unix time.
		 * @return array<string,mixed> New state.
		 */
		public static function apply_answer( $state, $slug, $answer, $now ) {
			$state = is_array( $state ) ? $state : array();

			if ( 'hide_suggestions' === $answer ) {
				$state['hide_suggestions'] = true;
				return $state;
			}
			if ( ! in_array( $answer, array( 'later', 'never', 'reviewed' ), true ) || '' === $slug ) {
				return $state;
			}

			$mine = isset( $state['review'][ $slug ] ) && is_array( $state['review'][ $slug ] ) ? $state['review'][ $slug ] : array();

			if ( 'later' === $answer ) {
				$mine['later'] = (int) ( $mine['later'] ?? 0 ) + 1;
				if ( $mine['later'] > self::MAX_LATER ) {
					$mine['state'] = 'never';
					unset( $mine['until'] );
				} else {
					$mine['state'] = 'later';
					$mine['until'] = $now + self::SNOOZE_DAYS * DAY_IN_SECONDS;
				}
			} else {
				$mine['state'] = $answer;
				unset( $mine['until'] );
			}

			$state['review'][ $slug ] = $mine;
			// Whatever the answer, the next Lynbro plugin waits its turn.
			$state['cooldown_until'] = $now + self::COOLDOWN_DAYS * DAY_IN_SECONDS;

			return $state;
		}

		/**
		 * Admin-side alias of lynbro_core_note_success().
		 *
		 * @param string $slug Plugin slug.
		 * @return void
		 */
		public static function note_success( $slug ) {
			do_action( 'lynbro_core_success', (string) $slug );
		}

		/**
		 * Which suite plugin owns an admin page.
		 *
		 * Exact slug or menu_slug first; then the longest plugin slug the page
		 * slug starts with, because inner screens are named after their plugin
		 * (lynbro-cache-settings belongs to lynbro-cache).
		 *
		 * @param string                          $page    Page slug.
		 * @param array<int,array<string,mixed>>  $modules Suite data.
		 * @return string Plugin slug, or '' for a page no suite plugin owns.
		 */
		public static function plugin_for_page( $page, $modules ) {
			$page = (string) $page;
			if ( '' === $page || Lynbro_Admin_Menu::PARENT === $page ) {
				return '';
			}
			$best = '';
			foreach ( (array) $modules as $module ) {
				$slug = isset( $module['slug'] ) ? (string) $module['slug'] : '';
				if ( '' === $slug ) {
					continue;
				}
				$menu = isset( $module['menu_slug'] ) ? (string) $module['menu_slug'] : '';
				if ( $page === $slug || ( '' !== $menu && $page === $menu ) ) {
					return $slug;
				}
				foreach ( array_filter( array( $slug, $menu ) ) as $prefix ) {
					if ( 0 === strpos( $page, $prefix . '-' ) && strlen( $prefix ) > strlen( $best ) ) {
						$best = $slug;
					}
				}
			}
			return $best;
		}

		/**
		 * What this site uses, as the signal names lynbro-suite.json speaks
		 * ("fits"). Read from local state only — no request leaves the site.
		 *
		 * @param array<int,string> $active_folders Folder names of active plugins.
		 * @param array<string,mixed> $site         locale, country, comments, registration.
		 * @return array<string,bool>
		 */
		public static function site_signals( $active_folders, $site ) {
			$active = array_flip( (array) $active_folders );
			$any    = static function ( $folders ) use ( $active ) {
				foreach ( $folders as $folder ) {
					if ( isset( $active[ $folder ] ) ) {
						return true;
					}
				}
				return false;
			};

			$woo     = $any( array( 'woocommerce' ) );
			$locale  = isset( $site['locale'] ) ? (string) $site['locale'] : '';
			$country = isset( $site['country'] ) ? (string) $site['country'] : '';

			return array(
				'woocommerce' => $woo,
				'forms'       => $any( array( 'contact-form-7', 'wpforms-lite', 'wpforms', 'gravityforms', 'fluentform', 'ninja-forms', 'formidable', 'elementor-pro', 'lynbro-forms' ) ),
				'danish'      => 0 === strpos( $locale, 'da' ) || 0 === strpos( $country, 'DK' ),
				'comments'    => ! empty( $site['comments'] ),
				'tracking'    => $any( array( 'google-site-kit', 'duracelltomi-google-tag-manager', 'pixelyoursite', 'ga-google-analytics', 'facebook-for-woocommerce', 'woocommerce-google-analytics-integration', 'lynbro-tags' ) ),
				'accounts'    => $woo || ! empty( $site['registration'] ),
			);
		}

		/**
		 * Rank the suite for this site.
		 *
		 * A plugin qualifies when it is not installed, is not $exclude, and one
		 * of its "fits" rules holds. A rule is a signal name, or several joined
		 * with "+" that must all hold. More specific matches (more signals) come
		 * first, then the suite's own menu order.
		 *
		 * @param array<int,array<string,mixed>> $modules   Suite data.
		 * @param array<string,bool>             $signals   From site_signals().
		 * @param array<string,string>           $installed Folder => plugin file.
		 * @param string                         $exclude   Slug never to suggest (the current plugin).
		 * @param int                            $limit     How many.
		 * @return array<int,array<string,mixed>> Modules, each with 'because' => signal names.
		 */
		public static function rank_suggestions( $modules, $signals, $installed, $exclude, $limit ) {
			$out = array();
			foreach ( (array) $modules as $module ) {
				$slug = isset( $module['slug'] ) ? (string) $module['slug'] : '';
				if ( '' === $slug || $slug === $exclude || isset( $installed[ $slug ] ) || empty( $module['fits'] ) ) {
					continue;
				}
				$best = array();
				foreach ( (array) $module['fits'] as $rule ) {
					$need = array_filter( explode( '+', (string) $rule ) );
					$hold = ! empty( $need );
					foreach ( $need as $signal ) {
						$hold = $hold && ! empty( $signals[ $signal ] );
					}
					if ( $hold && count( $need ) > count( $best ) ) {
						$best = array_values( $need );
					}
				}
				if ( empty( $best ) ) {
					continue;
				}
				$module['because'] = $best;
				$out[]             = $module;
			}

			usort(
				$out,
				static function ( $a, $b ) {
					$by_fit = count( $b['because'] ) <=> count( $a['because'] );
					if ( 0 !== $by_fit ) {
						return $by_fit;
					}
					return (int) ( $a['menu_position'] ?? 500 ) <=> (int) ( $b['menu_position'] ?? 500 );
				}
			);

			return array_slice( $out, 0, max( 0, (int) $limit ) );
		}

		/* ---- WordPress glue ------------------------------------------- */

		/**
		 * Hook everything up. Called once, below the class.
		 *
		 * @return void
		 */
		public static function boot() {
			add_action( 'admin_menu', array( __CLASS__, 'track_first_seen' ), 1001 );
			add_action( 'current_screen', array( __CLASS__, 'on_screen' ) );
			add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_answer' ) );
		}

		/**
		 * Remember when the shell first saw each plugin. The two-week clock
		 * starts here — on an existing site that is the day this version
		 * arrived, which errs on the quiet side.
		 *
		 * @return void
		 */
		public static function track_first_seen() {
			$modules = lynbro_core_suite_data();
			$usage   = get_option( self::USAGE_OPTION, array() );
			$usage   = is_array( $usage ) ? $usage : array();
			$changed = false;

			foreach ( Lynbro_Admin_Menu::get_items() as $item ) {
				$slug = self::plugin_for_page( (string) $item['slug'], $modules );
				if ( '' === $slug || ! empty( $usage[ $slug ]['first_seen'] ) ) {
					continue;
				}
				$row               = isset( $usage[ $slug ] ) && is_array( $usage[ $slug ] ) ? $usage[ $slug ] : array();
				$row['first_seen'] = time();
				$usage[ $slug ]    = $row;
				$changed           = true;
			}

			if ( $changed ) {
				update_option( self::USAGE_OPTION, $usage, true );
			}
		}

		/**
		 * On a Lynbro page: record the generic success signal and queue what
		 * this screen should show, before and after the plugin's own output.
		 *
		 * @param WP_Screen $screen Current screen.
		 * @return void
		 */
		public static function on_screen( $screen ) {
			$page = isset( $GLOBALS['plugin_page'] ) ? (string) $GLOBALS['plugin_page'] : '';
			$hook = isset( $screen->id ) ? (string) $screen->id : '';
			if ( '' === $page || Lynbro_Admin_Menu::hook_suffix( $page ) !== $hook ) {
				return;
			}

			$slug = self::plugin_for_page( $page, lynbro_core_suite_data() );
			if ( '' === $slug ) {
				return;
			}

			// Settings saved through the Settings API on the plugin's own screen
			// is a success every configuration plugin has, without a line of
			// code in the plugin. Read-only flag core puts on the redirect.
			if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by options.php on its redirect.
				self::note_success( $slug );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$usage = get_option( self::USAGE_OPTION, array() );
			$usage = is_array( $usage ) && isset( $usage[ $slug ] ) ? $usage[ $slug ] : array();
			$state = get_user_meta( get_current_user_id(), self::USER_META, true );

			if ( self::review_due( $usage, $state, $slug, time() ) ) {
				add_action(
					$hook,
					static function () use ( $slug ) {
						Lynbro_Core_Feedback::render_review( $slug );
					},
					5
				);
			}

			// Suggestions only under the plugin's main screen — the one in the
			// menu — not on every inner tab.
			$main = false;
			foreach ( Lynbro_Admin_Menu::get_items() as $item ) {
				if ( $item['slug'] === $page && empty( $item['hidden'] ) ) {
					$main = true;
				}
			}
			if ( $main && empty( $state['hide_suggestions'] ) ) {
				add_action(
					$hook,
					static function () use ( $slug ) {
						Lynbro_Core_Feedback::render_inline_suggestions( $slug );
					},
					20
				);
			}
		}

		/**
		 * The plugin's display name, from its header when it is installed.
		 *
		 * @param string $slug Plugin slug.
		 * @return string
		 */
		public static function plugin_name( $slug ) {
			$installed = lynbro_core_installed_plugins();
			if ( isset( $installed[ $slug ] ) ) {
				$all = get_plugins();
				if ( ! empty( $all[ $installed[ $slug ] ]['Name'] ) ) {
					return (string) $all[ $installed[ $slug ] ]['Name'];
				}
			}
			foreach ( lynbro_core_suite_data() as $module ) {
				if ( ( $module['slug'] ?? '' ) === $slug ) {
					return 'Lynbro ' . (string) lynbro_core_localize_module( $module )['name'];
				}
			}
			return $slug;
		}

		/**
		 * Whether a plugin is listed on wordpress.org: the bundled flag,
		 * corrected by the daily listing when the suite home has already
		 * fetched it. Never fetches anything itself.
		 *
		 * @param string $slug Plugin slug.
		 * @return bool
		 */
		public static function on_wporg( $slug ) {
			$live = get_transient( 'lynbro_core_wporg_suite' );
			if ( is_array( $live ) && ! empty( $live ) ) {
				return isset( $live[ $slug ] );
			}
			foreach ( lynbro_core_suite_data() as $module ) {
				if ( ( $module['slug'] ?? '' ) === $slug ) {
					return ! empty( $module['wporg'] );
				}
			}
			return false;
		}

		/**
		 * Public feedback page for a plugin that is not on wordpress.org.
		 *
		 * @return string
		 */
		public static function feedback_url() {
			return 'https://plugins.lynbro.dk/roadmap';
		}

		/**
		 * A nonce'd link that records one answer. Works without JavaScript —
		 * a click goes through admin-post and comes back — and the script
		 * only makes it instant.
		 *
		 * @param string $slug   Plugin slug.
		 * @param string $answer Answer.
		 * @return string
		 */
		public static function answer_url( $slug, $answer ) {
			return wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::ACTION,
						'plugin' => $slug,
						'answer' => $answer,
					),
					admin_url( 'admin-post.php' )
				),
				self::ACTION . '_' . $answer . '_' . $slug
			);
		}

		/**
		 * The review request. One notice, the plugin's own screen, no stars.
		 *
		 * @param string $slug Plugin slug.
		 * @return void
		 */
		public static function render_review( $slug ) {
			$name   = self::plugin_name( $slug );
			$listed = self::on_wporg( $slug );
			?>
			<div class="notice notice-info is-dismissible lynbro-prompt lynbro-review" data-lynbro-later="<?php echo esc_url( self::answer_url( $slug, 'later' ) ); ?>">
				<p>
					<strong>
						<?php
						/* translators: %s: plugin name. */
						echo esc_html( sprintf( __( 'Is %s doing its job?', 'lynbro-cookie-consent' ), $name ) );
						?>
					</strong>
					<?php if ( $listed ) : ?>
						<?php esc_html_e( 'You have used it for a couple of weeks now. If it works for you, a short review on WordPress.org helps other site owners find it — honest ones, critical ones included.', 'lynbro-cookie-consent' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'You have used it for a couple of weeks now. If it works for you — or if something is missing — tell us on our public roadmap. That is how we decide what to build next.', 'lynbro-cookie-consent' ); ?>
					<?php endif; ?>
				</p>
				<p class="lynbro-prompt-actions">
					<?php if ( $listed ) : ?>
						<a class="button button-primary" data-lynbro-answer="done" href="<?php echo esc_url( self::answer_url( $slug, 'reviewed' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Write a review', 'lynbro-cookie-consent' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'lynbro-cookie-consent' ); ?></span></a>
					<?php else : ?>
						<a class="button button-primary" data-lynbro-answer="done" href="<?php echo esc_url( self::answer_url( $slug, 'reviewed' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Share feedback', 'lynbro-cookie-consent' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'lynbro-cookie-consent' ); ?></span></a>
					<?php endif; ?>
					<a class="button" data-lynbro-answer="later" href="<?php echo esc_url( self::answer_url( $slug, 'later' ) ); ?>"><?php esc_html_e( 'Maybe later', 'lynbro-cookie-consent' ); ?></a>
					<a class="button-link" data-lynbro-answer="never" href="<?php echo esc_url( self::answer_url( $slug, 'never' ) ); ?>"><?php esc_html_e( 'Don\'t ask again', 'lynbro-cookie-consent' ); ?></a>
					<?php if ( $listed ) : ?>
						<span class="lynbro-prompt-help">
							<a href="<?php echo esc_url( 'https://wordpress.org/support/plugin/' . $slug . '/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Something not working? Ask for help', 'lynbro-cookie-consent' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'lynbro-cookie-consent' ); ?></span></a>
						</span>
					<?php endif; ?>
				</p>
			</div>
			<?php
		}

		/**
		 * Suggestions for this site, ready to render.
		 *
		 * @param string $exclude Plugin slug to leave out.
		 * @param int    $limit   How many.
		 * @return array<int,array<string,mixed>>
		 */
		public static function suggestions( $exclude, $limit ) {
			$installed = lynbro_core_installed_plugins();
			$active    = array();
			foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
				$active[] = strtok( (string) $file, '/' );
			}
			if ( is_multisite() ) {
				foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $file ) {
					$active[] = strtok( (string) $file, '/' );
				}
			}

			$signals = self::site_signals(
				$active,
				array(
					'locale'       => get_locale(),
					'country'      => (string) get_option( 'woocommerce_default_country', '' ),
					'comments'     => 'open' === get_option( 'default_comment_status' ),
					'registration' => (bool) get_option( 'users_can_register' ),
				)
			);

			$modules = lynbro_core_suite_data();
			foreach ( $modules as $i => $module ) {
				$modules[ $i ]          = lynbro_core_localize_module( $module );
				$modules[ $i ]['wporg'] = self::on_wporg( (string) ( $module['slug'] ?? '' ) );
			}

			return self::rank_suggestions( $modules, $signals, $installed, (string) $exclude, $limit );
		}

		/**
		 * Why a plugin is suggested, in words.
		 *
		 * @param array<int,string> $because Signal names.
		 * @return string
		 */
		public static function reason( $because ) {
			$words = array(
				'woocommerce' => __( 'WooCommerce is active', 'lynbro-cookie-consent' ),
				'forms'       => __( 'a form plugin is active', 'lynbro-cookie-consent' ),
				'danish'      => __( 'the site is set up for Denmark', 'lynbro-cookie-consent' ),
				'comments'    => __( 'comments are open', 'lynbro-cookie-consent' ),
				'tracking'    => __( 'tracking tags are active', 'lynbro-cookie-consent' ),
				'accounts'    => __( 'visitors can create accounts', 'lynbro-cookie-consent' ),
			);
			$parts = array();
			foreach ( (array) $because as $signal ) {
				if ( isset( $words[ $signal ] ) ) {
					$parts[] = $words[ $signal ];
				}
			}
			/* translators: %l: list of reasons, e.g. "WooCommerce is active and the site is set up for Denmark". */
			return wp_sprintf( __( 'Suggested because %l.', 'lynbro-cookie-consent' ), $parts );
		}

		/**
		 * One suggestion card. Shared by the inline block and the suite home.
		 *
		 * @param array<string,mixed> $module Suggested module, with 'because'.
		 * @return void
		 */
		public static function render_card( $module ) {
			$slug   = (string) $module['slug'];
			$listed = ! empty( $module['wporg'] );
			$icon   = isset( $module['icon'] ) ? (string) $module['icon'] : '';
			?>
			<div class="lynbro-card lynbro-suggestion">
				<div class="head">
					<?php if ( '' !== $icon ) : ?>
						<span class="ic" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
					<?php endif; ?>
					<h3><?php echo esc_html( (string) ( $module['name'] ?? $slug ) ); ?></h3>
				</div>
				<p><?php echo esc_html( (string) ( $module['tagline'] ?? '' ) ); ?></p>
				<p class="lynbro-why"><?php echo esc_html( self::reason( $module['because'] ) ); ?></p>
				<div class="actions">
					<?php if ( $listed && current_user_can( 'install_plugins' ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $slug ) ), 'install-plugin_' . $slug ) ); ?>">
							<?php esc_html_e( 'Install now', 'lynbro-cookie-consent' ); ?><span class="screen-reader-text"> <?php echo esc_html( (string) ( $module['name'] ?? $slug ) ); ?></span>
						</a>
					<?php endif; ?>
					<?php if ( $listed ) : ?>
						<a class="button" href="<?php echo esc_url( 'https://wordpress.org/plugins/' . $slug . '/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View on WordPress.org', 'lynbro-cookie-consent' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'lynbro-cookie-consent' ); ?></span></a>
					<?php else : ?>
						<a class="button" href="<?php echo esc_url( 'https://plugins.lynbro.dk/' . $slug ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'lynbro-cookie-consent' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'lynbro-cookie-consent' ); ?></span></a>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * The small block under a plugin's own main screen.
		 *
		 * @param string $current Plugin whose screen this is.
		 * @return void
		 */
		public static function render_inline_suggestions( $current ) {
			$list = self::suggestions( $current, self::INLINE_LIMIT );
			if ( empty( $list ) ) {
				return;
			}
			?>
			<div class="wrap lynbro-suggest-wrap">
				<div class="lynbro-section lynbro-prompt lynbro-suggest">
					<div class="lynbro-suggest-head">
						<h2><?php esc_html_e( 'Suggested for this site', 'lynbro-cookie-consent' ); ?></h2>
						<a class="button-link" data-lynbro-answer="hide" href="<?php echo esc_url( self::answer_url( '', 'hide_suggestions' ) ); ?>"><?php esc_html_e( 'Hide suggestions', 'lynbro-cookie-consent' ); ?></a>
					</div>
					<p class="desc"><?php esc_html_e( 'Other Lynbro plugins that fit what this site uses. Nothing is installed unless you click.', 'lynbro-cookie-consent' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Lynbro_Admin_Menu::PARENT ) ); ?>"><?php esc_html_e( 'All Lynbro plugins', 'lynbro-cookie-consent' ); ?></a>
					</p>
					<div class="lynbro-grid">
						<?php
						foreach ( $list as $module ) {
							self::render_card( $module );
						}
						?>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * The same suggestions, unlimited and not dismissible, at the top of
		 * the suite home — the page the user opened to see exactly this.
		 *
		 * @return void
		 */
		public static function render_home_suggestions() {
			$list = self::suggestions( '', 6 );
			if ( empty( $list ) ) {
				return;
			}
			?>
			<div class="lynbro-section">
				<h2><?php esc_html_e( 'Suggested for this site', 'lynbro-cookie-consent' ); ?></h2>
				<p class="desc"><?php esc_html_e( 'Picked from what this site already uses — read on this site, nothing is sent anywhere.', 'lynbro-cookie-consent' ); ?></p>
				<div class="lynbro-grid">
					<?php
					foreach ( $list as $module ) {
						self::render_card( $module );
					}
					?>
				</div>
			</div>
			<?php
		}

		/**
		 * admin-post: record an answer, then go back — or, for "Write a
		 * review", on to the review page.
		 *
		 * @return void
		 */
		public static function handle_answer() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is checked right below, and it names this exact answer and plugin.
			$answer = isset( $_GET['answer'] ) ? sanitize_key( wp_unslash( $_GET['answer'] ) ) : '';
			$slug   = isset( $_GET['plugin'] ) ? sanitize_key( wp_unslash( $_GET['plugin'] ) ) : '';
			$ajax   = ! empty( $_GET['lynbro_ajax'] );
			// phpcs:enable

			check_admin_referer( self::ACTION . '_' . $answer . '_' . $slug );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'lynbro-cookie-consent' ), 403 );
			}

			$user  = get_current_user_id();
			$state = get_user_meta( $user, self::USER_META, true );
			update_user_meta( $user, self::USER_META, self::apply_answer( $state, $slug, $answer, time() ) );

			if ( $ajax ) {
				wp_send_json_success();
			}

			if ( 'reviewed' === $answer ) {
				$target = self::on_wporg( $slug )
					? 'https://wordpress.org/support/plugin/' . $slug . '/reviews/#new-post'
					: self::feedback_url();
				add_filter(
					'allowed_redirect_hosts',
					static function ( $hosts ) {
						$hosts[] = 'wordpress.org';
						$hosts[] = 'plugins.lynbro.dk';
						return $hosts;
					}
				);
				wp_safe_redirect( $target );
				exit;
			}

			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . Lynbro_Admin_Menu::PARENT ) );
			exit;
		}
	}

	Lynbro_Core_Feedback::boot();
}

/* -------------------------------------------------------------------------
 * Brand stylesheet — enqueued on any Lynbro admin screen.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'lynbro_core_enqueue_admin_assets' ) ) {
	/**
	 * Enqueue the shared brand CSS on Lynbro admin screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	function lynbro_core_enqueue_admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'lynbro' ) ) {
			return;
		}
		wp_enqueue_style(
			'lynbro-core-admin',
			plugin_dir_url( __FILE__ ) . 'lynbro-admin.css',
			array(),
			lynbro_core_file_version( __DIR__ . '/lynbro-admin.css' )
		);
		wp_enqueue_script(
			'lynbro-core-admin',
			plugin_dir_url( __FILE__ ) . 'lynbro-admin.js',
			array(),
			lynbro_core_file_version( __DIR__ . '/lynbro-admin.js' ),
			true
		);
	}
	add_action( 'admin_enqueue_scripts', 'lynbro_core_enqueue_admin_assets' );
}

/* -------------------------------------------------------------------------
 * Tools section — export / reset a plugin's own options (100% local).
 *
 * These keep the names they have always had. Plugins already shipped call them
 * through function_exists(), and an older vendored copy that wins the
 * class_exists() race for them behaves identically, so there is nothing to gain
 * from renaming and a transition to break.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'lynbro_render_tools_section' ) ) {
	/**
	 * Render an Export / Reset settings panel for a plugin.
	 *
	 * @param array<int,string> $option_keys Option names to export/reset (lynbro*).
	 * @param string            $page_slug   Admin page slug to return to after reset.
	 * @return void
	 */
	function lynbro_render_tools_section( $option_keys, $page_slug ) {
		$page_slug = sanitize_key( $page_slug );
		$action    = admin_url( 'admin-post.php' );
		// Registered here as well as at load time, so the screen shows what it
		// will actually act on even if a plugin only declares its keys on render.
		lynbro_tools_register_keys( $page_slug, (array) $option_keys );
		?>
		<div class="lynbro-section">
			<h2><?php esc_html_e( 'Tools', 'lynbro-cookie-consent' ); ?></h2>
			<p class="desc"><?php esc_html_e( 'Export this plugin&#8217;s settings as a JSON backup, or reset them to defaults. 100% local — nothing leaves your site.', 'lynbro-cookie-consent' ); ?></p>
			<div class="lynbro-tools-row">
				<form method="post" action="<?php echo esc_url( $action ); ?>">
					<?php wp_nonce_field( 'lynbro_tools_export_' . $page_slug ); ?>
					<input type="hidden" name="action" value="lynbro_tools_export">
					<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Export settings (JSON)', 'lynbro-cookie-consent' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( $action ); ?>" data-lynbro-confirm="<?php echo esc_attr__( 'Reset all settings to defaults?', 'lynbro-cookie-consent' ); ?>">
					<?php wp_nonce_field( 'lynbro_tools_reset_' . $page_slug ); ?>
					<input type="hidden" name="action" value="lynbro_tools_reset">
					<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Reset to defaults', 'lynbro-cookie-consent' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}
}

if ( ! function_exists( 'lynbro_is_safe_option_key' ) ) {
	/**
	 * Shape guard for an option name. Not an authorisation check on its own:
	 * every Lynbro plugin's options share this prefix, so matching it says
	 * nothing about who owns the option.
	 *
	 * @param string $key Option name.
	 * @return bool
	 */
	function lynbro_is_safe_option_key( $key ) {
		return (bool) preg_match( '/^lynbro[a-z0-9_]+$/', (string) $key );
	}
}

if ( ! function_exists( 'lynbro_tools_register_keys' ) ) {
	/**
	 * Declare which options a given screen owns.
	 *
	 * @param string            $page_slug Admin page slug.
	 * @param array<int,string> $keys      Option names owned by that screen.
	 * @return void
	 */
	function lynbro_tools_register_keys( $page_slug, $keys ) {
		$registry = lynbro_tools_registry();
		$slug     = sanitize_key( $page_slug );

		$registry[ $slug ] = array_values(
			array_unique(
				array_merge(
					isset( $registry[ $slug ] ) ? $registry[ $slug ] : array(),
					array_filter( (array) $keys, 'lynbro_is_safe_option_key' )
				)
			)
		);
		lynbro_tools_registry( $registry );
	}
}

if ( ! function_exists( 'lynbro_tools_registry' ) ) {
	/**
	 * Static store of page => owned option names.
	 *
	 * @param array<string,array<int,string>>|null $set Replacement registry.
	 * @return array<string,array<int,string>>
	 */
	function lynbro_tools_registry( $set = null ) {
		static $registry = array();
		if ( is_array( $set ) ) {
			$registry = $set;
		}
		return $registry;
	}
}

if ( ! function_exists( 'lynbro_tools_keys_for_page' ) ) {
	/**
	 * Options a screen is allowed to export or reset.
	 *
	 * The list is never taken from the request. A request can only ask *which
	 * screen* is acting, and it must carry that screen's own nonce to do so;
	 * what that screen owns is decided here, in code. Otherwise any Lynbro
	 * plugin's Tools form could be pointed at another Lynbro plugin's settings,
	 * since they all share the option prefix.
	 *
	 * @param string $page_slug Admin page slug.
	 * @return array<int,string>
	 */
	function lynbro_tools_keys_for_page( $page_slug ) {
		$slug     = sanitize_key( $page_slug );
		$registry = lynbro_tools_registry();
		$keys     = isset( $registry[ $slug ] ) ? $registry[ $slug ] : array();

		/**
		 * Filter the options owned by a Lynbro screen.
		 *
		 * @param array<int,string> $keys Option names.
		 * @param string            $slug Page slug being acted on.
		 */
		$keys = (array) apply_filters( 'lynbro_tools_option_keys', $keys, $slug );

		return array_values( array_unique( array_filter( $keys, 'lynbro_is_safe_option_key' ) ) );
	}
}

if ( ! function_exists( 'lynbro_tools_export_handler' ) ) {
	/**
	 * admin-post: stream the requested Lynbro options as a JSON download.
	 *
	 * @return void
	 */
	function lynbro_tools_export_handler() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lynbro-cookie-consent' ) );
		}
		$page = isset( $_POST['page'] ) ? sanitize_key( wp_unslash( $_POST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce for this exact page checked next.
		check_admin_referer( 'lynbro_tools_export_' . $page );

		$out = array();
		foreach ( lynbro_tools_keys_for_page( $page ) as $k ) {
			$out[ $k ] = get_option( $k );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=lynbro-settings-' . gmdate( 'Ymd' ) . '.json' );
		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}
	add_action( 'admin_post_lynbro_tools_export', 'lynbro_tools_export_handler' );
}

if ( ! function_exists( 'lynbro_tools_reset_handler' ) ) {
	/**
	 * admin-post: delete the requested Lynbro options (reset to defaults).
	 *
	 * @return void
	 */
	function lynbro_tools_reset_handler() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lynbro-cookie-consent' ) );
		}
		$page = isset( $_POST['page'] ) ? sanitize_key( wp_unslash( $_POST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce for this exact page checked next.
		check_admin_referer( 'lynbro_tools_reset_' . $page );

		foreach ( lynbro_tools_keys_for_page( $page ) as $k ) {
			delete_option( $k );
		}
		$page = '' !== $page ? $page : 'lynbro';
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => $page,
					'lynbro_reset' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
	add_action( 'admin_post_lynbro_tools_reset', 'lynbro_tools_reset_handler' );
}
