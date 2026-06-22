<?php
/**
 * Shortcode: [lynbro_cookie_settings]
 *
 * Renders a "Cookie settings" button that re-opens the consent banner so the
 * visitor can change their choice at any time.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the shortcode.
 *
 * @return void
 */
function lynbro_cookie_consent_register_shortcode() {
	add_shortcode( 'lynbro_cookie_settings', 'lynbro_cookie_consent_shortcode' );
}
add_action( 'init', 'lynbro_cookie_consent_register_shortcode' );

/**
 * Shortcode callback.
 *
 * @param array $atts Shortcode attributes.
 * @return string Safe HTML.
 */
function lynbro_cookie_consent_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'label' => __( 'Cookie settings', 'lynbro-cookie-consent' ),
			'class' => '',
		),
		$atts,
		'lynbro_cookie_settings'
	);

	$class = 'lynbro-cc-settings-btn';
	if ( ! empty( $atts['class'] ) ) {
		$class .= ' ' . sanitize_html_class( $atts['class'] );
	}

	return sprintf(
		'<button type="button" class="%1$s" data-lynbro-cc-open="1">%2$s</button>',
		esc_attr( $class ),
		esc_html( $atts['label'] )
	);
}
