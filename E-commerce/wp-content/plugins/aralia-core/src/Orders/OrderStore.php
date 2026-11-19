<?php
/**
 * Lectura de un pedido sin la copia de la caché de la petición.
 *
 * @package Aralia
 */

namespace Aralia\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Lo que decide un pago (guard, order-pay, pistas del widget) debe partir de la base de datos: la copia de la caché de objetos puede ir
 * por detrás de un webhook o de otro proceso, y guardarla de nuevo pisaría lo que ellos escribieron (`_ara_attempts`).
 */
final class OrderStore {

	/**
	 * Pedido recargado de la base de datos.
	 *
	 * @param int $order_id Pedido.
	 * @return \WC_Order|null
	 */
	public static function fresh( int $order_id ): ?\WC_Order {
		clean_post_cache( $order_id );
		wp_cache_delete( $order_id, 'orders' );
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caching\OrderCache' ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caching\OrderCache::class )->remove( $order_id );
			} catch ( \Throwable $e ) {
				unset( $e ); // Si la caché persistente no responde se sigue con lo que devuelva WooCommerce (ya se limpiaron las cachés de entradas).
			}
		}
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order ? $order : null;
	}
}
