<?php
/**
 * Consulta de Wompi para el vencimiento de pedidos (T058 ↔ T059).
 *
 * @package Aralia
 */

namespace Aralia\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Conecta `aralia_wompi_lookup` (que usa Aralia\Orders\Expiry) con la API: revisa los intentos con transacción del pedido (hasta MAX_QUERIES ids; con más, «no se sabe» solo si quedan sin consultar un verificado o una pista reciente).
 * APPROVED de cualquiera gana; si alguna consulta falla y ninguna es APPROVED, "no se sabe" (null); si no, PENDING si alguna lo está.
 */
final class Lookup {

	/**
	 * Máximo de transacciones que se consultan por pedido en una pasada.
	 */
	public const MAX_QUERIES = 8;

	/**
	 * Engancha el filtro.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'aralia_wompi_lookup', array( self::class, 'for_order' ), 10, 2 );
		add_filter( 'aralia_verify_payment_hint', array( self::class, 'verify_hint' ), 10, 4 );
	}

	/**
	 * Estado agregado de los intentos del pedido.
	 *
	 * @param mixed $current Valor previo del filtro.
	 * @param mixed $order   Pedido.
	 * @return string|null `APPROVED`, `PENDING`, `DECLINED`, `NONE` (todas las pistas se ignoraron), o null si no se pudo saber o no hay transacciones.
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
		$ignored  = 0;
		$queried  = 0;
		// Primero los ids verificados y luego las pistas (más nuevo primero). El id verificado manda; sin él se usan las pistas (sin
		// verificar): una que la API no conoce o que es de otro pedido se IGNORA (no concluye «rechazado»), para que quien siembre ids falsos
		// no pueda cancelar el pedido ni retener el stock.
		$cands = \Aralia\Orders\Attempts::candidates( is_array( $attempts ) ? $attempts : array() );
		foreach ( $cands as $idx => $cand ) {
			$attempt  = $cand['attempt'];
			$verified = $cand['verified'];
			if ( ++$queried > self::MAX_QUERIES ) {
				// Quedan ids sin consultar: «no se sabe» solo si entre ellos hay uno verificado o una pista reciente (se reintenta; el tope de
				// 2 h del vencimiento acota el aplazamiento). Pistas viejas sin consultar no retienen el pedido.
				foreach ( array_slice( $cands, $idx ) as $rest ) {
					if ( $rest['verified'] || \Aralia\Orders\Attempts::hint_age( $rest['attempt'] ) < 10 * MINUTE_IN_SECONDS ) {
						$unknown = true;
						break;
					}
				}
				break;
			}
			$tx = $client->transaction( $cand['id'] );
			if ( null === $tx ) {
				return null; // API caída: las demás consultas fallarían igual (8 s cada una). No se sabe.
			}
			if ( ! $verified && ( 'NOT_FOUND' === $tx['status'] || ! self::belongs( $tx, $attempt, $order ) ) ) {
				// Una pista reciente que la API aún no conoce: puede ser un pago real en camino ("no se sabe"); una vieja o ajena se ignora.
				if ( 'NOT_FOUND' === $tx['status'] && \Aralia\Orders\Attempts::hint_age( $attempt ) < 10 * MINUTE_IN_SECONDS ) {
					$unknown = true;
				}
				++$ignored;
				continue;
			}
			$seen = true;
			if ( 'NOT_FOUND' === $tx['status'] && self::is_recent( $attempt ) ) {
				$unknown = true; // La API puede tardar en reflejar una transacción recién creada: no se cancela por eso.
			} elseif ( ! self::belongs( $tx, $attempt, $order ) ) {
				continue; // La transacción no es de este pedido (otra referencia, otro monto u otra moneda): no cuenta.
			} elseif ( 'APPROVED' === $tx['status'] && $verified ) {
				return 'APPROVED';
			} elseif ( 'APPROVED' === $tx['status'] ) {
				// Una pista sin verificar (el widget) no concede ni aplaza sin tope: cuenta como «en proceso» (tope de 2 h); el evento firmado
				// o la conciliación son quienes confirman el pago.
				$pending = true;
			} elseif ( 'PENDING' === $tx['status'] ) {
				$pending = true;
			}
		}
		if ( $unknown ) {
			return null; // No se pudo saber (API caída, o una transacción que la API aún no refleja): se reintenta, no se cancela.
		}
		if ( ! $seen ) {
			// Si TODAS las pistas se revisaron y se ignoraron (falsas, viejas o de otro pedido) y la API funciona, "no hay pago": el vencimiento
			// cancela a tiempo (NONE). Sin ningún id, null (el vencimiento decide por los intentos con transacción).
			return $ignored > 0 ? 'NONE' : null;
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
	public static function belongs( array $tx, array $attempt, \WC_Order $order ): bool {
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

	/**
	 * Verifica una pista del widget contra la API de Wompi (filtro `aralia_verify_payment_hint`).
	 *
	 * @param mixed $verdict   Veredicto previo.
	 * @param mixed $order     Pedido.
	 * @param mixed $reference Referencia del intento.
	 * @param mixed $tx_id     Transacción.
	 * @return string `valid` | `invalid` | `unknown`
	 */
	public static function verify_hint( $verdict, $order = null, $reference = '', $tx_id = '' ): string {
		if ( ! $order instanceof \WC_Order || ! is_string( $tx_id ) || ! is_string( $reference ) ) {
			return is_string( $verdict ) ? $verdict : 'unknown';
		}
		$tx = ( new WompiClient() )->transaction( $tx_id );
		if ( null === $tx || 'NOT_FOUND' === $tx['status'] ) {
			return 'unknown'; // La API no responde o aún no la conoce: la pista queda sin verificar.
		}
		return self::belongs( $tx, array( 'reference' => $reference ), $order ) ? 'valid' : 'invalid';
	}
}
