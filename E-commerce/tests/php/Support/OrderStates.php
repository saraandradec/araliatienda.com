<?php
/**
 * Ayuda de pruebas: lleva un pedido por una cadena de estados permitidos por la máquina de estados (T054).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Support;

use Aralia\Orders\StateMachine;

final class OrderStates {

	/**
	 * Cambia el estado paso a paso con todos los actores (la tabla sigue mandando: un paso ilegal sigue bloqueado).
	 *
	 * @param \WC_Order $order Pedido.
	 * @param string    ...$path Estados sucesivos (sin `wc-`).
	 * @return void
	 */
	public static function walk( \WC_Order $order, string ...$path ): void {
		foreach ( $path as $status ) {
			StateMachine::run_as(
				StateMachine::PAYMENT,
				static function () use ( $order, $status ): void {
					StateMachine::run_as(
						StateMachine::STAFF,
						static function () use ( $order, $status ): void {
							StateMachine::run_as(
								StateMachine::SYSTEM,
								static function () use ( $order, $status ): void {
									$order->update_status( $status );
								}
							);
						}
					);
				}
			);
		}
	}
}
