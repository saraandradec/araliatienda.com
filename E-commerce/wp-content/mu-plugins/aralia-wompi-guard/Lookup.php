<?php
/**
 * Consulta de Wompi para el vencimiento de pedidos (T058 ↔ T059).
 *
 * @package Aralia
 */

namespace Aralia\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Conecta `aralia_wompi_lookup` (que usa Aralia\Orders\Expiry) con la API: revisa TODOS los intentos con transacción del pedido.
 * APPROVED de cualquiera gana; si alguna consulta falla y ninguna es APPROVED, "no se sabe" (null); si no, PENDING si alguna lo está.
 */
final class Lookup {

	/**
	 * Engancha el filtro.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'aralia_wompi_lookup', array( self::class, 'for_order' ), 10, 2 );
	}

	/**
	 * Estado agregado de los intentos del pedido.
	 *
	 * @param mixed $current Valor previo del filtro.
	 * @param mixed $order   Pedido.
	 * @return string|null `APPROVED`, `PENDING`, `DECLINED`, o null si no se pudo saber o no hay transacciones.
	 */
	public static function for_order( $current, $order = null ): ?string {
		if ( ! $order instanceof \WC_Order ) {
			return is_string( $current ) ? $current : null;
		}
		$attempts = $order->get_meta( '_ara_attempts', true );
		$client   = new WompiClient();
		$pending  = false;
		$unknown  = false;
		$seen     = false;
		foreach ( is_array( $attempts ) ? $attempts : array() as $attempt ) {
			if ( ! is_array( $attempt ) || empty( $attempt['transaction_id'] ) || ! is_string( $attempt['transaction_id'] ) ) {
				continue;
			}
			$seen = true;
			$tx   = $client->transaction( $attempt['transaction_id'] );
			if ( null === $tx ) {
				$unknown = true;
			} elseif ( 'NOT_FOUND' === $tx['status'] && self::is_recent( $attempt ) ) {
				$unknown = true; // La API puede tardar en reflejar una transacción recién creada: no se cancela por eso.
			} elseif ( ! self::belongs( $tx, $attempt, $order ) ) {
				continue; // La transacción no es de este pedido (otra referencia, otro monto u otra moneda): no cuenta.
			} elseif ( 'APPROVED' === $tx['status'] ) {
				return 'APPROVED';
			} elseif ( 'PENDING' === $tx['status'] ) {
				$pending = true;
			}
		}
		if ( ! $seen || $unknown ) {
			return null;
		}
		return $pending ? 'PENDING' : 'DECLINED';
	}

	/**
	 * ¿La transacción consultada es la de este intento y de este pedido? (referencia, monto y moneda).
	 *
	 * @param array<string,mixed> $tx      Datos de la API.
	 * @param array<string,mixed> $attempt Intento.
	 * @param \WC_Order           $order   Pedido.
	 * @return bool
	 */
	private static function belongs( array $tx, array $attempt, \WC_Order $order ): bool {
		return 'NOT_FOUND' !== $tx['status']
			&& ( $attempt['reference'] ?? '' ) === $tx['reference']
			&& 'COP' === $tx['currency']
			&& (int) round( (float) $order->get_total() * 100 ) === (int) $tx['amount_in_cents'];
	}

	/**
	 * ¿El intento se anotó hace menos de 10 minutos?
	 *
	 * @param array<string,mixed> $attempt Intento.
	 * @return bool
	 */
	private static function is_recent( array $attempt ): bool {
		$at = isset( $attempt['at'] ) && is_string( $attempt['at'] ) ? strtotime( $attempt['at'] ) : false;
		return false !== $at && time() - $at < 10 * MINUTE_IN_SECONDS;
	}
}
