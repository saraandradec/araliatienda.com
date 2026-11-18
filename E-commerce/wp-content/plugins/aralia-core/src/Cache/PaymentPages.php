<?php
/**
 * Las páginas de pago nunca se cachean (R-21), con independencia de la CSP.
 *
 * @package Aralia
 */

namespace Aralia\Cache;

use Aralia\Security\Csp;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout, order-pay y Gracias: cabeceras sin caché y `DONOTCACHEPAGE`. Antes esto lo hacía la CSP, de modo que con la CSP apagada la
 * política de caché se perdía (gate del bloque C, B-5).
 */
class PaymentPages {

	/**
	 * Engancha la regla.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'on_template_redirect' ), 0 );
	}

	/**
	 * Callback de la acción (no devuelve nada).
	 *
	 * @return void
	 */
	public static function on_template_redirect(): void {
		self::apply();
	}

	/**
	 * Marca la respuesta como no cacheable si es una página de pago.
	 *
	 * @return bool Verdadero si se marcó.
	 */
	public static function apply(): bool {
		if ( ! Csp::is_payment_type( Csp::page_type() ) ) {
			return false;
		}
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante estándar de los plugins de caché.
		}
		do_action( 'litespeed_control_set_nocache', 'aralia payment page' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción de LiteSpeed Cache.
		return true;
	}
}
