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
 * reserve the iframe's exact aspect ratio.
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
	if ( is_admin() ) {
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
 * Filter content to wrap matched iframes in consent placeholders.
 *
 * @param string $content Post/widget content HTML.
 * @return string
 */
function lynbro_cookie_consent_filter_embeds_content( $content ) {
	if ( ! lynbro_cookie_consent_embeds_active() || '' === trim( (string) $content ) ) {
		return $content;
	}
	if ( false === stripos( $content, '<iframe' ) ) {
		return $content;
	}

	$catalog = lynbro_cookie_consent_embed_catalog();

	$filtered = preg_replace_callback(
		'#<iframe\b[^>]*>.*?</iframe>#is',
		function ( $m ) use ( $catalog ) {
			return lynbro_cookie_consent_maybe_wrap_iframe( $m[0], $catalog );
		},
		$content
	);

	return ( null === $filtered ) ? $content : $filtered;
}
add_filter( 'the_content', 'lynbro_cookie_consent_filter_embeds_content', 99 );
add_filter( 'widget_text_content', 'lynbro_cookie_consent_filter_embeds_content', 99 );

/**
 * Filter oEmbed HTML (e.g. auto-embedded YouTube/Vimeo URLs).
 *
 * @param string $html The oEmbed HTML.
 * @return string
 */
function lynbro_cookie_consent_filter_oembed_html( $html ) {
	if ( ! lynbro_cookie_consent_embeds_active() || '' === trim( (string) $html ) ) {
		return $html;
	}
	if ( false === stripos( $html, '<iframe' ) ) {
		return $html;
	}

	$catalog  = lynbro_cookie_consent_embed_catalog();
	$filtered = preg_replace_callback(
		'#<iframe\b[^>]*>.*?</iframe>#is',
		function ( $m ) use ( $catalog ) {
			return lynbro_cookie_consent_maybe_wrap_iframe( $m[0], $catalog );
		},
		$html
	);

	return ( null === $filtered ) ? $html : $filtered;
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

	// Try to read width/height for an exact aspect-ratio reservation (zero CLS).
	$width  = 0;
	$height = 0;
	if ( preg_match( '#\bwidth\s*=\s*(["\'])?(\d+)#i', $iframe, $wm ) ) {
		$width = (int) $wm[2];
	}
	if ( preg_match( '#\bheight\s*=\s*(["\'])?(\d+)#i', $iframe, $hm ) ) {
		$height = (int) $hm[2];
	}
	$ratio = ( $width > 0 && $height > 0 ) ? ( $width . ' / ' . $height ) : '16 / 9';

	return lynbro_cookie_consent_embed_placeholder_html( $iframe, $slug, $service_name, $category, $ratio );
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
 * @param string $ratio        CSS aspect-ratio value (e.g. "16 / 9").
 * @return string
 */
function lynbro_cookie_consent_embed_placeholder_html( $iframe, $slug, $service_name, $category, $ratio ) {
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
		),
	);

	ob_start();
	?>
	<div class="lynbro-cc-embed"
		data-lynbro-cc-embed="<?php echo esc_attr( $slug ); ?>"
		data-lynbro-cc-category="<?php echo esc_attr( $category ); ?>"
		style="aspect-ratio: <?php echo esc_attr( $ratio ); ?>;">
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
