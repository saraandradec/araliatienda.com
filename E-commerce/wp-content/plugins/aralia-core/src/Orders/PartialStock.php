<?php
/**
 * Descuento de stock línea por línea para un pago aprobado tardío (T057).
 *
 * @package Aralia
 */

namespace Aralia\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Un pago APPROVED verificado puede llegar cuando el pedido ya estaba cancelado y el stock devuelto. Cada línea se descuenta de forma
 * atómica (`wc_update_product_stock`): si alcanza, queda con `_reduced_stock`; si no, la línea se marca `_ara_missing` y no se descuenta.
 * El pedido queda `processing` si todas alcanzaron, o `ara-nostock` si faltó alguna (alerta + correo; la resuelve Sara).
 */
final class PartialStock {

	/**
	 * Descuenta lo que haya y deja el pedido en su estado final. Lo llama el guard (T060) tras verificar el pago.
	 *
	 * @param \WC_Order $order Pedido cancelado (o en el estado desde el que el guard lo permita) con pago aprobado.
	 * @return string Estado final: `processing` o `ara-nostock`; si el estado guardado no lo permite, el estado actual sin tocar el stock (ya registrado y alertado).
	 */
	public static function settle_late( \WC_Order $order ): string {
		// Si la máquina de estados no dejará pasar el pedido a pagado (reembolsado, revertido...), no se toca el stock.
		$from = StateMachine::stored_status( $order->get_id() );
		if ( ! StateMachine::is_allowed( $from, 'processing', array( StateMachine::PAYMENT ) ) && ! StateMachine::is_allowed( $from, 'ara-nostock', array( StateMachine::PAYMENT ) ) ) {
			do_action( 'aralia_blocked_transition', $order->get_id(), $from, 'processing' );
			do_action( 'aralia_alert', 'blocked_transition', 'pedido ' . $order->get_id() . ' ' . $from . ' a processing' );
			return $from;
		}
		$missing = self::reduce( $order );
		$target  = array() === $missing ? 'processing' : 'ara-nostock';
		StateMachine::run_as(
			StateMachine::PAYMENT,
			static function () use ( $order, $target, $missing ): void {
				$note = array() === $missing
					? 'Pago aprobado tardío: stock descontado de nuevo.'
					: 'Pago aprobado tardío sin stock para: ' . implode( ', ', $missing ) . '.';
				$order->update_status( $target, $note );
			}
		);
		if ( 'ara-nostock' === $order->get_status() ) {
			// La alerta `nostock` la levanta Statuses::alert_nostock al entrar en ese estado (cualquier ruta).
			do_action( 'aralia_order_nostock', $order->get_id(), $missing );
		}
		return $order->get_status();
	}

	/**
	 * Descuenta cada línea que tenga stock y marca las que no. Idempotente: una línea ya descontada no se toca.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return string[] Nombres de las líneas sin stock.
	 */
	public static function reduce( \WC_Order $order ): array {
		$missing = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$qty     = (int) $item->get_quantity();
			if ( ! $product || $qty <= 0 || ! $product->managing_stock() ) {
				continue;
			}
			if ( (int) $item->get_meta( '_reduced_stock', true ) > 0 ) {
				continue;
			}
			// Sin stock suficiente ni backorders, no se toca el producto (evita que pase un instante por cero o negativo).
			$have = $product->get_stock_quantity();
			if ( null !== $have && (int) $have < $qty && ! $product->backorders_allowed() ) {
				$item->update_meta_data( '_ara_missing', '1' );
				$item->save();
				$missing[] = $item->get_name();
				continue;
			}
			$new = wc_update_product_stock( $product, $qty, 'decrease' );
			if ( ! is_int( $new ) || ( $new < 0 && ! $product->backorders_allowed() ) ) {
				// No alcanzó: se devuelve lo que se acababa de restar y se marca la línea.
				if ( is_int( $new ) ) {
					wc_update_product_stock( $product, $qty, 'increase' );
				}
				$item->update_meta_data( '_ara_missing', '1' );
				$item->save();
				$missing[] = $item->get_name();
				continue;
			}
			$item->update_meta_data( '_reduced_stock', (string) $qty );
			$item->delete_meta_data( '_ara_missing' );
			$item->save();
		}
		// Marca de WooCommerce (no un meta propio): con ella, cancelar o reembolsar devuelve el stock y un pago posterior no lo descuenta otra vez.
		$order->get_data_store()->set_stock_reduced( $order->get_id(), true );
		return $missing;
	}
}
