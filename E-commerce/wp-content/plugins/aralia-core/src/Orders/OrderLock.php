<?php
/**
 * Candado por pedido con reintentos breves (una sola política para el guard, order-pay y las pistas del widget).
 *
 * @package Aralia
 */

namespace Aralia\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Es el mismo candado que usa `Expiry` (opción `aralia_exp_lock_<id>`): el vencimiento, el manejador de eventos, order-pay y
 * `payment-attempt` se excluyen entre sí. No es reentrante: nada de lo que corre dentro vuelve a tomarlo.
 */
final class OrderLock {

	public const TRIES     = 5;
	public const WAIT_USEC = 200000;

	/**
	 * Toma el candado (reintenta hasta 1 s).
	 *
	 * @param int $order_id Pedido.
	 * @param int $tries    Intentos.
	 * @return bool
	 */
	public static function acquire( int $order_id, int $tries = self::TRIES ): bool {
		for ( $i = 0; $i < $tries; $i++ ) {
			if ( Expiry::lock( $order_id ) ) {
				return true;
			}
			usleep( self::WAIT_USEC );
		}
		return false;
	}

	/**
	 * Suelta el candado (solo el propio).
	 *
	 * @param int $order_id Pedido.
	 * @return void
	 */
	public static function release( int $order_id ): void {
		Expiry::unlock( $order_id );
	}

	/**
	 * Ejecuta algo bajo el candado, con `finally`.
	 *
	 * @param int      $order_id Pedido.
	 * @param callable $callback Código a ejecutar.
	 * @return array{ok:bool,value:mixed} `ok` false si no se pudo tomar el candado.
	 */
	public static function run( int $order_id, callable $callback ): array {
		if ( ! self::acquire( $order_id ) ) {
			return array(
				'ok'    => false,
				'value' => null,
			);
		}
		try {
			return array(
				'ok'    => true,
				'value' => $callback(),
			);
		} finally {
			self::release( $order_id );
		}
	}
}
