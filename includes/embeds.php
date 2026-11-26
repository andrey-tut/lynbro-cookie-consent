<?php
/**
 * Embed / iframe consent placeholders (A1).
 *
 * Blocks third-party iframes (YouTube, Vimeo, Google Maps, social embeds, etc.)
 * until the visitor consents to the configured category. Until then a tidy,
 * translatable placeholder is shown with the service name and an "allow" button
 * (plus an optional "always allow this service" choice).
 *
 * The PHP side neutralises iframes by:
 *   - filtering `the_content` and `widget_text_content`,
 *   - filtering `embed_oembed_html` and `video_embed_html`.
 * The front-end JS (banner.js) swaps the placeholder back to a live iframe once
 * the category is granted or the visitor clicks "allow". Everything is local —
 * no external request is made to detect or block embeds. Zero CLS: placeholders
 * reserve the iframe's aspect ratio or height (see embed_box_style).
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether embed gating is active for the current request.
 *
 * @return bool
 */
function lynbro_cookie_consent_embeds_active() {
	// Not in wp-admin, the block editor's REST previews or feeds: only pages a
	// visitor opens in a browser, where banner.js can bring the iframe back.
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'is_feed' ) && did_action( 'wp' ) && is_feed() ) ) {
		return false;
	}
	if ( ! lynbro_cookie_consent_should_show() ) {
		return false;
	}
	return ! empty( lynbro_cookie_consent_get_option( 'embeds_enabled', 1 ) );
}

/**
 * The consent category embeds are gated behind.
 *
 * @return string
 */
function lynbro_cookie_consent_embed_category() {
	$category = sanitize_key( lynbro_cookie_consent_get_option( 'embed_category', 'marketing' ) );
	if ( ! lynbro_cookie_consent_category_is_active( $category ) ) {
		$category = 'marketing';
	}
	return $category;
}

/**
 * Wrap every known-provider iframe in an HTML fragment in a consent placeholder.
 *
 * Safe to run more than once on the same markup (a block inside post content is
 * seen by render_block and again by the_content): an iframe that already sits in
 * a placeholder carries data-lynbro-cc-wrapped and is left alone.
 *
 * @param string $html HTML fragment.
 * @return string
 */
function lynbro_cookie_consent_wrap_embeds( $html ) {
	if ( ! is_string( $html ) || '' === trim( $html ) || false === stripos( $html, '<iframe' ) ) {
		return $html;
	}
	if ( ! lynbro_cookie_consent_embeds_active() ) {
		return $html;
	}

	$catalog  = lynbro_cookie_consent_embed_catalog();
	$filtered = preg_replace_callback(
		'#<iframe\b[^>]*>.*?</iframe>#is',
		function ( $m ) use ( $catalog ) {
			if ( preg_match( '#^<iframe\b[^>]*\sdata-lynbro-cc-wrapped\b#i', $m[0] ) ) {
				return $m[0];
			}
			return lynbro_cookie_consent_maybe_wrap_iframe( $m[0], $catalog );
		},
		$html
	);

	return ( null === $filtered ) ? $html : $filtered;
}

/**
 * Filter content to wrap matched iframes in consent placeholders.
 *
 * @param string $content Post/widget content HTML.
 * @return string
 */
function lynbro_cookie_consent_filter_embeds_content( $content ) {
	return lynbro_cookie_consent_wrap_embeds( $content );
}
add_filter( 'the_content', 'lynbro_cookie_consent_filter_embeds_content', 99 );
add_filter( 'widget_text_content', 'lynbro_cookie_consent_filter_embeds_content', 99 );
// Block widgets (WordPress 5.8+) do not pass through widget_text_content.
add_filter( 'widget_block_content', 'lynbro_cookie_consent_filter_embeds_content', 99 );

/**
 * Blocks rendered outside post content: block-theme templates and template
 * parts (a map in the footer), synced patterns, query loops.
 *
 * @param string $block_content Rendered block HTML.
 * @return string
 */
function lynbro_cookie_consent_filter_embeds_block( $block_content ) {
	return lynbro_cookie_consent_wrap_embeds( $block_content );
}
add_filter( 'render_block', 'lynbro_cookie_consent_filter_embeds_block', 99 );

/**
 * Elementor widgets, including Theme Builder headers and footers, which never
 * pass through the_content.
 *
 * @param string $widget_content Widget HTML.
 * @return string
 */
function lynbro_cookie_consent_filter_embeds_elementor( $widget_content ) {
	return lynbro_cookie_consent_wrap_embeds( $widget_content );
}
add_filter( 'elementor/widget/render_content', 'lynbro_cookie_consent_filter_embeds_elementor', 99 );

/**
 * Filter oEmbed HTML (e.g. auto-embedded YouTube/Vimeo URLs).
 *
 * @param string $html The oEmbed HTML.
 * @return string
 */
function lynbro_cookie_consent_filter_oembed_html( $html ) {
	return lynbro_cookie_consent_wrap_embeds( $html );
}
add_filter( 'embed_oembed_html', 'lynbro_cookie_consent_filter_oembed_html', 99 );
add_filter( 'video_embed_html', 'lynbro_cookie_consent_filter_oembed_html', 99 );

/**
 * Wrap a single iframe in a consent placeholder if it matches a known provider.
 *
 * @param string $iframe  The full <iframe ...></iframe> markup.
 * @param array  $catalog Embed provider catalog.
 * @return string Original iframe, or placeholder markup.
 */
function lynbro_cookie_consent_maybe_wrap_iframe( $iframe, $catalog ) {
	// Extract the src.
	if ( ! preg_match( '#\bsrc\s*=\s*(["\'])(.*?)\1#i', $iframe, $src_m ) ) {
		return $iframe;
	}
	$src  = html_entity_decode( $src_m[2], ENT_QUOTES, 'UTF-8' );
	$slug = lynbro_cookie_consent_catalog_match( $src, $catalog );
	if ( false === $slug ) {
		return $iframe;
	}

	$service_name = isset( $catalog[ $slug ]['name'] ) ? $catalog[ $slug ]['name'] : ucfirst( $slug );
	$category     = lynbro_cookie_consent_embed_category();

	$box_style = lynbro_cookie_consent_embed_box_style( $iframe );

	// Mark the stored iframe, so a second pass over the same markup skips it.
	$marked = preg_replace( '#^<iframe\b#i', '<iframe data-lynbro-cc-wrapped="1"', $iframe, 1 );
	return lynbro_cookie_consent_embed_placeholder_html( null === $marked ? $iframe : $marked, $slug, $service_name, $category, $box_style );
}

/**
 * Inline style that reserves the blocked iframe's space (zero CLS).
 *
 * - width + height attributes: exact aspect ratio.
 * - only a pixel height (attribute or inline style): that fixed height.
 * - nothing usable (e.g. page builders that size the iframe from a stylesheet,
 *   like Elementor's Google Maps widget): a modest minimum height instead of a
 *   16:9 box, which on a wide column would be far taller than the real map.
 *
 * Only real attributes are read: values inside the src URL (e.g. "&width=600")
 * are ignored.
 *
 * @param string $iframe The full <iframe ...></iframe> markup.
 * @return string CSS declarations for the placeholder's style attribute.
 */
function lynbro_cookie_consent_embed_box_style( $iframe ) {
	$tag = preg_match( '#<iframe\b[^>]*>#i', $iframe, $tm ) ? $tm[0] : $iframe;
	// Drop quoted attribute values of src so query strings can't pose as attributes.
	$tag = preg_replace( '#\ssrc\s*=\s*(["\'])[^"\']*\1#i', ' ', $tag );

	$width  = 0;
	$height = 0;
	if ( preg_match( '#\swidth\s*=\s*["\']?\s*(\d+)(?:px)?\s*["\'\s/>]#i', $tag, $wm ) ) {
		$width = (int) $wm[1];
	}
	if ( preg_match( '#\sheight\s*=\s*["\']?\s*(\d+)(?:px)?\s*["\'\s/>]#i', $tag, $hm ) ) {
		$height = (int) $hm[1];
	}
	if ( $width > 0 && $height > 0 ) {
		return 'aspect-ratio: ' . $width . ' / ' . $height . ';';
	}

	if ( 0 === $height && preg_match( '#\sstyle\s*=\s*(["\'])(.*?)\1#is', $tag, $sm )
		&& preg_match( '#(?:^|;)\s*height\s*:\s*(\d+)px#i', $sm[2], $shm ) ) {
		$height = (int) $shm[1];
	}
	if ( $height > 0 ) {
		return 'height: ' . min( $height, 2000 ) . 'px;';
	}

	return 'min-height: 300px;';
}

/**
 * Build the placeholder markup for a blocked embed.
 *
 * The original iframe HTML is stored inside an inert <template> element so the
 * front-end JS can clone it back into the page on consent. A template's content
 * is not rendered or fetched by the browser, which gives us a clean, escape-free
 * way to carry the original markup with zero side effects until consent.
 *
 * @param string $iframe       Original iframe HTML.
 * @param string $slug         Provider slug.
 * @param string $service_name Provider display name.
 * @param string $category     Consent category.
 * @param string $box_style    CSS declarations reserving the iframe's space.
 * @return string
 */
function lynbro_cookie_consent_embed_placeholder_html( $iframe, $slug, $service_name, $category, $box_style ) {
	/* translators: %s: name of the embedded service, e.g. YouTube. */
	$heading = sprintf( __( 'Content from %s is blocked', 'lynbro-cookie-consent' ), $service_name );
	/* translators: %s: name of the embedded service, e.g. YouTube. */
	$body = sprintf( __( 'To view this content from %s, you need to accept the relevant cookies. The provider may set cookies and collect personal data.', 'lynbro-cookie-consent' ), $service_name );
	/* translators: %s: name of the embedded service, e.g. YouTube. */
	$load_label = sprintf( __( 'Load content from %s', 'lynbro-cookie-consent' ), $service_name );
	/* translators: %s: name of the embedded service, e.g. YouTube. */
	$always_label = sprintf( __( 'Always allow %s', 'lynbro-cookie-consent' ), $service_name );

	$allowed_iframe = array(
		'iframe' => array(
			'src'             => true,
			'width'           => true,
			'height'          => true,
			'title'           => true,
			'frameborder'     => true,
			'allow'           => true,
			'allowfullscreen' => true,
			'loading'         => true,
			'referrerpolicy'  => true,
			'sandbox'         => true,
			'class'           => true,
			'style'           => true,
			'name'            => true,
			'scrolling'       => true,
			'data-lynbro-cc-wrapped' => true,
		),
	);

	ob_start();
	?>
	<div class="lynbro-cc-embed"
		data-lynbro-cc-embed="<?php echo esc_attr( $slug ); ?>"
		data-lynbro-cc-category="<?php echo esc_attr( $category ); ?>"
		style="<?php echo esc_attr( $box_style ); ?>">
		<template class="lynbro-cc-embed__frame"><?php echo wp_kses( $iframe, $allowed_iframe ); ?></template>
		<div class="lynbro-cc-embed__inner">
			<p class="lynbro-cc-embed__title"><?php echo esc_html( $heading ); ?></p>
			<p class="lynbro-cc-embed__text"><?php echo esc_html( $body ); ?></p>
			<div class="lynbro-cc-embed__actions">
				<button type="button" class="lynbro-cc-embed__btn lynbro-cc-embed__btn--load" data-lynbro-cc-embed-load="1">
					<?php echo esc_html( $load_label ); ?>
				</button>
				<label class="lynbro-cc-embed__always">
					<input type="checkbox" data-lynbro-cc-embed-always="1" />
					<?php echo esc_html( $always_label ); ?>
				</label>
			</div>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}
