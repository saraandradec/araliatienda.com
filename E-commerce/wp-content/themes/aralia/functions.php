<?php
/**
 * Tema Aralía: funciones.
 *
 * @package Aralia
 */

defined( 'ABSPATH' ) || exit;

/**
 * ¿Puede cargarse GSAP y el movimiento en esta página? (R-04)
 *
 * Nunca en checkout, order-pay ni Gracias (no se carga código de terceros ni animaciones en el pago), ni en el panel.
 *
 * @param array<string,bool>|null $context Contexto (pruebas); por defecto, el de la petición actual.
 * @return bool
 */
function aralia_theme_motion_allowed( ?array $context = null ): bool {
	if ( null === $context ) {
		$context = array(
			'admin'          => is_admin(),
			'checkout'       => function_exists( 'is_checkout' ) && is_checkout(),
			'order_pay'      => ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ),
			'order_received' => ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ),
			'account'        => function_exists( 'is_account_page' ) && is_account_page(),
		);
	}
	foreach ( array( 'admin', 'checkout', 'order_pay', 'order_received', 'account' ) as $blocked ) {
		if ( ! empty( $context[ $blocked ] ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Hoja de estilos del tema: en TODAS las páginas (también checkout), porque un tema de bloques no carga `style.css` por sí solo.
 *
 * @return void
 */
function aralia_theme_enqueue_style(): void {
	wp_enqueue_style( 'aralia-style', get_stylesheet_uri(), array(), (string) wp_get_theme()->get( 'Version' ) );
}
add_action( 'wp_enqueue_scripts', 'aralia_theme_enqueue_style' );

/**
 * Encola GSAP auto-alojado y el script de movimiento (siempre archivos propios: sin scripts inline).
 *
 * @return void
 */
function aralia_theme_enqueue_motion(): void {
	if ( ! aralia_theme_motion_allowed() ) {
		return;
	}
	$dir = get_template_directory_uri() . '/assets/js/';
	$ver = (string) wp_get_theme()->get( 'Version' );
	wp_enqueue_script(
		'aralia-gsap',
		$dir . 'gsap.min.js',
		array(),
		'3.12.5',
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
	wp_enqueue_script(
		'aralia-scrolltrigger',
		$dir . 'ScrollTrigger.min.js',
		array( 'aralia-gsap' ),
		'3.12.5',
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
	wp_enqueue_script(
		'aralia-motion',
		$dir . 'motion.js',
		array( 'aralia-gsap', 'aralia-scrolltrigger' ),
		$ver,
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}

/**
 * GSAP solo donde se usa (R-04): se encola al renderizar el primer bloque con la clase `ara-reveal`, y nunca donde el movimiento no está permitido.
 *
 * @param mixed $content Contenido renderizado del bloque.
 * @return mixed
 */
function aralia_theme_maybe_enqueue_motion( $content ) {
	if ( is_string( $content ) && false !== strpos( $content, 'ara-reveal' ) ) {
		aralia_theme_enqueue_motion();
	}
	return $content;
}
add_filter( 'render_block', 'aralia_theme_maybe_enqueue_motion' );

/**
 * Soporte del tema.
 *
 * @return void
 */
function aralia_theme_setup(): void {
	add_theme_support( 'woocommerce' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'wp-block-styles' );
}
add_action( 'after_setup_theme', 'aralia_theme_setup' );
