<?php
/**
 * Lynbro shared admin shell — bootstrap and version election.
 *
 * Canonical source: plugins/_shared/lynbro-core/. Every Lynbro plugin ships its
 * OWN copy of this directory. To be precise about why: wordpress.org does not
 * forbid plugins from sharing code — it refuses to host a *framework plugin*
 * that exists only to be depended on, and tells authors to package shared
 * libraries with each plugin instead. Vendoring is that instruction followed.
 *
 * This file registers the copy it belongs to and then steps aside. It never
 * defines a single line of the shell itself: the implementation lives in
 * lynbro-core.php next to it, and only ONE copy of it — the newest version
 * found on the site — is ever loaded.
 *
 * Why an election and not function_exists():
 *   A plain function_exists() guard hands the shell to whichever plugin loaded
 *   first, which in practice means whichever plugin folder sorts first
 *   alphabetically. On a site with a dozen Lynbro plugins that guarantees two
 *   bugs: a fix shipped in one plugin does nothing until the neighbours are
 *   updated too, and a new plugin calling a function the stale winner does not
 *   have is a fatal error. The pattern below is Action Scheduler's, which is
 *   vendored into WooCommerce, AIOSEO and SureCart for exactly this reason:
 *   the guard sits on a VERSION-QUALIFIED function name, so two versions never
 *   collide; at plugins_loaded priority 0 every copy registers itself; at
 *   priority 1 version_compare picks the newest and loads only that one.
 *
 * ⚠️ The token lynbro-cookie-consent below is replaced with the plugin's text domain, and
 * 1.1.3 / 1_1_3 with this shell's version, by
 * tools/sync_lynbro_core.py when the directory is copied into a plugin.
 *
 * @package lynbro-core
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lynbro_Core_Versions', false ) ) {

	/**
	 * The registry every vendored copy of the shell registers itself with.
	 *
	 * ⚠️⚠️ APPEND-ONLY FOREVER. Never add a method to this class, never remove
	 * one, never change a signature. ⚠️⚠️
	 *
	 * This class is the one piece that is NOT version-elected: it is guarded by
	 * a plain class_exists(), so the copy that happens to load first wins it —
	 * even when a newer copy wins the election and provides everything else.
	 * That is not a flaw to be fixed; it is why the class must never grow.
	 *
	 * The bill for forgetting this has already been paid once, publicly:
	 * Action Scheduler 3.9.1 added a method to its own registry class, and on
	 * sites where 3.8.2 also lived — inside a plugin whose folder name sorted
	 * earlier — the 3.8.2 class was the one in memory while the 3.9.1 library
	 * ran on top of it. The Scheduled Actions screen died with "call to
	 * undefined method". Their fix was to move the new API into a NEW class and
	 * leave the registry frozen.
	 *
	 * So: new shared API goes into lynbro-core.php (elected, safe to change) or
	 * into a brand-new class with a new name. Never here.
	 */
	class Lynbro_Core_Versions {

		/**
		 * Singleton.
		 *
		 * @var Lynbro_Core_Versions|null
		 */
		private static $instance = null;

		/**
		 * version => absolute path of that copy's implementation file.
		 *
		 * @var array<string,string>
		 */
		private $versions = array();

		/**
		 * Whether a version has already been loaded this request.
		 *
		 * @var bool
		 */
		private $initialized = false;

		/**
		 * The registry shared by every copy on the site.
		 *
		 * @return Lynbro_Core_Versions
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Announce one vendored copy.
		 *
		 * Two plugins shipping the same version are the same code, so the first
		 * path registered for a version is kept and the rest are ignored.
		 *
		 * @param string $version Semantic version of that copy.
		 * @param string $file    Absolute path to its implementation file.
		 * @return void
		 */
		public function register( $version, $file ) {
			$version = (string) $version;
			if ( '' === $version || isset( $this->versions[ $version ] ) ) {
				return;
			}
			$this->versions[ $version ] = (string) $file;
		}

		/**
		 * Every copy that announced itself, for diagnostics.
		 *
		 * @return array<string,string> version => file.
		 */
		public function get_versions() {
			return $this->versions;
		}

		/**
		 * Highest registered version, by version_compare.
		 *
		 * @return string Empty string when nothing registered.
		 */
		public function latest_version() {
			$versions = array_keys( $this->versions );
			if ( empty( $versions ) ) {
				return '';
			}
			usort( $versions, 'version_compare' );
			return (string) end( $versions );
		}

		/**
		 * The file the election chose. Recorded so a support answer can name the
		 * plugin that is actually providing the shell, not guess at it.
		 *
		 * @return string Empty string when nothing registered.
		 */
		public function latest_version_file() {
			$latest = $this->latest_version();
			return '' !== $latest ? $this->versions[ $latest ] : '';
		}

		/**
		 * Load the winning copy. Runs once per request.
		 *
		 * @return void
		 */
		public static function initialize_latest_version() {
			$registry = self::instance();
			if ( $registry->initialized ) {
				return;
			}
			$file = $registry->latest_version_file();
			if ( '' === $file || ! is_readable( $file ) ) {
				return;
			}
			$registry->initialized = true;
			require_once $file;
		}
	}
}

if ( ! function_exists( 'lynbro_core_note_success' ) ) {

	/**
	 * Tell the suite that a plugin just did its job for this site.
	 *
	 * ⚠️ FROZEN FOREVER, like the registry above: this function is guarded by a
	 * plain function_exists(), so whichever copy loads first defines it for
	 * everybody. It therefore does nothing but fire an action — the code that
	 * listens to the action is version-qualified below and free to change.
	 *
	 * Safe to call from anywhere: the front end, cron, REST, a form handler.
	 * The admin implementation (lynbro-core.php) does not need to be loaded.
	 * A plugin calls it on the first real success it sees — a spam message
	 * held, a backup written, a mail delivered — and the shared review request
	 * waits for that signal (plus two weeks of use) before it ever asks.
	 *
	 *     if ( function_exists( 'lynbro_core_note_success' ) ) {
	 *         lynbro_core_note_success( 'lynbro-antispam-guard' );
	 *     }
	 *
	 * @param string $plugin Plugin slug (folder name), as in lynbro-suite.json.
	 * @return void
	 */
	function lynbro_core_note_success( $plugin ) {
		do_action( 'lynbro_core_success', (string) $plugin );
	}
}

if ( ! function_exists( 'lynbro_core_register_1_1_3' ) ) {

	/**
	 * Record a plugin's first success. Listens to lynbro_core_success.
	 *
	 * Every version of the shell from 1.1.0 on adds its own listener (the name
	 * is version-qualified), so on a site with two versions it runs twice — and
	 * it is idempotent for exactly that reason: once a plugin has a success
	 * time, nothing is written again. That also keeps it to one database write
	 * per plugin, ever, however often the plugin reports.
	 *
	 * The option's shape is shared by every version and may only grow:
	 * lynbro_core_usage = array( slug => array( 'first_seen' => int, 'success' => int ) ).
	 *
	 * @param string $plugin Plugin slug.
	 * @return void
	 */
	function lynbro_core_record_success_1_1_3( $plugin ) {
		$plugin = sanitize_key( (string) $plugin );
		if ( '' === $plugin ) {
			return;
		}
		$usage = get_option( 'lynbro_core_usage', array() );
		$usage = is_array( $usage ) ? $usage : array();
		if ( ! empty( $usage[ $plugin ]['success'] ) ) {
			return;
		}
		$row             = isset( $usage[ $plugin ] ) && is_array( $usage[ $plugin ] ) ? $usage[ $plugin ] : array();
		$row['success']  = time();
		$usage[ $plugin ] = $row;
		update_option( 'lynbro_core_usage', $usage, true );
	}
	add_action( 'lynbro_core_success', 'lynbro_core_record_success_1_1_3' );

	/**
	 * Announce this copy of the shell.
	 *
	 * The version is part of the function name on purpose: it is what lets two
	 * different versions of this bootstrap coexist without redeclaring anything.
	 *
	 * @return void
	 */
	function lynbro_core_register_1_1_3() {
		Lynbro_Core_Versions::instance()->register( '1.1.3', __DIR__ . '/lynbro-core.php' );
	}

	if ( did_action( 'plugins_loaded' ) ) {
		// A plugin that pulls the shell in late (inside an is_admin() branch on
		// init, say) still gets a working shell — it just cannot influence an
		// election that has already been decided. Loading the shell at file
		// scope keeps a plugin in the running; that is why every Lynbro plugin
		// requires it from the top level of its main file.
		lynbro_core_register_1_1_3();
		if ( is_admin() ) {
			Lynbro_Core_Versions::initialize_latest_version();
		}
	} else {
		add_action( 'plugins_loaded', 'lynbro_core_register_1_1_3', 0 );

		// Everything the shell provides is a wp-admin screen, and it is carried
		// by a dozen plugins at once, so a front-end request should not pay for
		// parsing it. Registration still happens everywhere — it is a few lines
		// — and any copy asking for the load is enough, because add_action()
		// deduplicates an identical callback at an identical priority.
		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( 'Lynbro_Core_Versions', 'initialize_latest_version' ), 1 );
		}
	}
}

/*
 * Bundled translations on EVERY request, for this copy's own plugin.
 *
 * The suite-wide lang_dir_for_domain filter lives in the elected lynbro-core.php,
 * which loads on admin screens only. Plugins that rely on it (and most skip
 * load_plugin_textdomain()) therefore showed English to visitors on sites that
 * get no wordpress.org language pack — found by lynbro-forms 0.6.1, 2026-11-17.
 * This file runs once per vendored copy on every request, so each plugin answers
 * for its own text domain here. A language pack from translate.wordpress.org still
 * wins: the filter only answers when WordPress found nothing itself.
 */
add_filter(
	'lang_dir_for_domain',
	static function ( $path, $domain, $locale ) {
		if ( ! empty( $path ) || 'lynbro-cookie-consent' !== $domain ) {
			return $path;
		}
		$dir = dirname( __DIR__, 2 ) . '/languages/';
		return is_readable( $dir . $domain . '-' . $locale . '.mo' ) ? $dir : $path;
	},
	10,
	3
);
