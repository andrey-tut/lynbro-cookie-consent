<?php
/**
 * Automatic blocking of well-known tracker scripts before consent (B10).
 *
 * Enqueued scripts: filter `script_loader_tag` to neutralise registered handles
 * whose source matches the tracker catalog, turning them into inert
 * `type="text/plain"` tags re-activated by the front-end JS after the matching
 * consent category is granted. This is the standard, fully local approach and
 * does not buffer or rewrite the whole page.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether automatic tracker blocking is active for the current request.
 *
 * @return bool
 */
function lynbro_cookie_consent_auto_block_active() {
	if ( is_admin() ) {
		return false;
	}
	if ( ! lynbro_cookie_consent_should_show() ) {
		return false;
	}
	return ! empty( lynbro_cookie_consent_get_option( 'auto_block', 1 ) );
}

/**
 * Neutralise enqueued tracker scripts by handle/source via script_loader_tag.
 *
 * @param string $tag    The full <script> tag.
 * @param string $handle The script handle.
 * @param string $src    The script source URL.
 * @return string Possibly rewritten tag.
 */
function lynbro_cookie_consent_auto_block_tag( $tag, $handle, $src ) {
	if ( ! lynbro_cookie_consent_auto_block_active() ) {
		return $tag;
	}

	$catalog = lynbro_cookie_consent_tracker_catalog();
	$match   = lynbro_cookie_consent_catalog_match( $src, $catalog );
	if ( false === $match ) {
		// Also try matching by handle name (some snippets register descriptive handles).
		$match = lynbro_cookie_consent_catalog_match( $handle, $catalog );
	}
	if ( false === $match ) {
		return $tag;
	}

	$category = isset( $catalog[ $match ]['category'] ) ? sanitize_key( $catalog[ $match ]['category'] ) : 'marketing';

	// Only gate categories the visitor actually controls.
	if ( ! lynbro_cookie_consent_category_is_active( $category ) ) {
		return $tag;
	}

	// Neutralise the enqueued tracker tag IN PLACE (we modify the existing tag
	// WordPress built, we do not emit a new script): rename `src` to `data-src`
	// so the browser never fetches it, drop the executable `type`, and mark it
	// with the consent category. The front-end JS re-activates it after the
	// matching category is granted. WordPress has no enqueue function for an
	// inert placeholder, so the script_loader_tag filter is the correct hook.
	$marker      = ' type="text/plain" data-lynbro-cc="' . esc_attr( $category ) . '"';
	$neutralised = preg_replace( '#\stype=(["\']).*?\1#i', '', $tag );
	$neutralised = preg_replace( '#\ssrc=#i', ' data-src=', (string) $neutralised, 1 );
	$neutralised = preg_replace_callback(
		'#<\s*script\b#i',
		static function ( $m ) use ( $marker ) {
			return $m[0] . $marker;
		},
		(string) $neutralised,
		1
	);

	return ( null === $neutralised || '' === $neutralised ) ? $tag : $neutralised;
}
add_filter( 'script_loader_tag', 'lynbro_cookie_consent_auto_block_tag', 20, 3 );

/**
 * Tracker patterns per consent category, for the categories the visitor controls.
 *
 * @return array<string,array<int,string>>
 */
function lynbro_cookie_consent_gatekeeper_patterns() {
	$out = array();
	foreach ( lynbro_cookie_consent_tracker_catalog() as $entry ) {
		$category = isset( $entry['category'] ) ? sanitize_key( $entry['category'] ) : 'marketing';
		if ( 'necessary' === $category || ! lynbro_cookie_consent_category_is_active( $category ) ) {
			continue;
		}
		foreach ( isset( $entry['patterns'] ) ? (array) $entry['patterns'] : array() as $pattern ) {
			$out[ $category ][] = (string) $pattern;
		}
	}
	foreach ( $out as $category => $patterns ) {
		$out[ $category ] = array_values( array_unique( $patterns ) );
	}
	return $out;
}

/**
 * The gatekeeper: the first script in <head>.
 *
 * script_loader_tag only sees scripts added with wp_enqueue_script(). The usual
 * way a site gets Google Analytics or a Meta Pixel is a pasted snippet — in the
 * theme, in a header/footer code plugin, in a page builder — printed straight
 * into the page, and those ran before any choice (found 2026-11-18: GA4 sent a
 * hit and set _ga, the Pixel sent PageView, on a fresh install). Rewriting the
 * whole page in an output buffer is what the plugin review rejected in 2026-06,
 * so the blocking happens in the browser instead: a MutationObserver catches
 * <script src> tags from the catalog as the parser adds them, and a patched
 * document.createElement catches the ones a snippet injects (the Pixel loader,
 * GTM). Either way the tag becomes the plugin's inert
 * type="text/plain" data-lynbro-cc="<category>" data-src="…" form, which the
 * banner already activates once the category is granted.
 *
 * Tags printed above wp_head() in a theme's header.php run before any plugin
 * can act; the readme says so.
 *
 * @return string JavaScript, or '' when automatic blocking is off.
 */
function lynbro_cookie_consent_gatekeeper_js() {
	if ( ! lynbro_cookie_consent_auto_block_active() ) {
		return '';
	}
	$patterns = lynbro_cookie_consent_gatekeeper_patterns();
	if ( ! $patterns ) {
		return '';
	}
	$config = wp_json_encode(
		array(
			'p' => $patterns,
			'c' => LYNBRO_COOKIE_CONSENT_COOKIE,
			'v' => (int) lynbro_cookie_consent_effective_consent_version(),
		)
	);
	if ( false === $config ) {
		return '';
	}

	return '(function(K){'
		. 'var P=K.p,d=document;'
		. 'function stored(){var m=d.cookie.match(new RegExp("(?:^|; )"+K.c+"=([^;]*)"));if(!m){return null;}try{var x=JSON.parse(decodeURIComponent(m[1]));return x&&x.v===K.v?x.c:null;}catch(e){return null;}}'
		. 'function cat(u){if(!u){return "";}u=String(u);for(var c in P){if(P.hasOwnProperty(c)){for(var i=0;i<P[c].length;i++){if(u.indexOf(P[c][i])!==-1){return c;}}}}return "";}'
		. 'function ok(c){var g=window.lynbroCcGranted||stored()||{};return !!g[c];}'
		. 'function inert(s,c,u){s.setAttribute("type","text/plain");s.setAttribute("data-lynbro-cc",c);if(u){s.setAttribute("data-src",u);}}'
		. 'new MutationObserver(function(ms){for(var i=0;i<ms.length;i++){var n=ms[i].addedNodes;for(var j=0;j<n.length;j++){var s=n[j];if(s.nodeType!==1||s.tagName!=="SCRIPT"||s.getAttribute("type")==="text/plain"){continue;}var u=s.getAttribute("src"),c=cat(u);if(c&&!ok(c)){inert(s,c,u);s.removeAttribute("src");s.addEventListener("beforescriptexecute",function(e){e.preventDefault();},{once:true});}}}}).observe(d.documentElement,{childList:true,subtree:true});'
		. 'var D=Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype,"src"),A=Element.prototype.setAttribute,mk=d.createElement;'
		. 'if(D&&D.set){d.createElement=function(t){var e=mk.apply(d,arguments);if(String(t).toLowerCase()==="script"){'
		. 'Object.defineProperty(e,"src",{configurable:true,get:function(){return D.get.call(e);},set:function(u){var c=cat(u);if(c&&!ok(c)){inert(e,c,u);}else{D.set.call(e,u);}}});'
		. 'e.setAttribute=function(a,v){if(String(a).toLowerCase()==="src"){e.src=v;return;}return A.call(e,a,v);};}return e;};}'
		. '})(' . $config . ');';
}

/**
 * Print the gatekeeper and the Consent Mode defaults before anything else in
 * <head>: priority -10000 puts them ahead of tags other plugins print at 0 or 1.
 *
 * @return void
 */
function lynbro_cookie_consent_print_gatekeeper() {
	$js = lynbro_cookie_consent_gatekeeper_js();
	if ( '' !== $js ) {
		wp_print_inline_script_tag( $js, array( 'id' => 'lynbro-cookie-consent-gatekeeper' ) );
	}
}
add_action( 'wp_head', 'lynbro_cookie_consent_print_gatekeeper', -10000 );
