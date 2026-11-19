<?php
/**
 * Ayudas sobre un intento de pago (`_ara_attempts`, data-model §3).
 *
 * @package Aralia
 */

namespace Aralia\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Un intento guarda a lo sumo UN id verificado (`transaction_id`) y hasta 3 pistas sin verificar del widget (`hint_transaction_ids`); con las
 * 3 llenas se descartan las nuevas (nunca se expulsa una anterior), para que una pista falsa no desplace a una real.
 */
final class Attempts {

	public const MAX_HINTS = 3;

	/**
	 * Pistas sin verificar de un intento (admite la clave antigua `hint_transaction_id`).
	 *
	 * @param array<string,mixed> $attempt Intento.
	 * @return string[]
	 */
	public static function hints( array $attempt ): array {
		$out = array();
		if ( isset( $attempt['hint_transaction_ids'] ) && is_array( $attempt['hint_transaction_ids'] ) ) {
			foreach ( $attempt['hint_transaction_ids'] as $id ) {
				if ( is_string( $id ) && '' !== $id ) {
					$out[] = $id;
				}
			}
		}
		if ( isset( $attempt['hint_transaction_id'] ) && is_string( $attempt['hint_transaction_id'] ) && '' !== $attempt['hint_transaction_id'] ) {
			$out[] = $attempt['hint_transaction_id'];
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Añade una pista. NUNCA expulsa una anterior: con MAX_HINTS llenas, la nueva se descarta (quien solo tiene la clave del pedido no
	 * puede sacar una pista real sembrando falsas; el evento firmado de Wompi y la conciliación son la vía autoritativa). Una pista repetida
	 * no cambia nada, y `hint_at` (el inicio del plazo de 10 min en que una pista que la API aún no conoce cuenta como «no se sabe») se
	 * fija con la PRIMERA pista: renovarla permitiría aplazar el vencimiento sin fin.
	 *
	 * @param array<string,mixed> $attempt Intento.
	 * @param string              $tx_id   Pista.
	 * @return array<string,mixed> Intento actualizado (igual si no hubo cambio).
	 */
	public static function add_hint( array $attempt, string $tx_id ): array {
		$hints = self::hints( $attempt );
		if ( in_array( $tx_id, $hints, true ) || count( $hints ) >= self::MAX_HINTS ) {
			return $attempt;
		}
		$hints[]                         = $tx_id;
		$attempt['hint_transaction_ids'] = $hints;
		if ( empty( $attempt['hint_at'] ) ) {
			$attempt['hint_at'] = gmdate( 'c' );
		}
		unset( $attempt['hint_transaction_id'] );
		return $attempt;
	}

	/**
	 * Ids a consultar de todos los intentos, en orden de importancia: primero los VERIFICADOS (más nuevo primero), luego las pistas (más
	 * nuevo primero); sin repetidos. Así un tope de consultas deja fuera pistas, no pagos verificados.
	 *
	 * @param array<int,mixed> $attempts Intentos.
	 * @return array<int,array{attempt:array<string,mixed>,id:string,verified:bool}>
	 */
	public static function candidates( array $attempts ): array {
		$verified = array();
		$hints    = array();
		$seen     = array();
		$ordered  = array_reverse( array_values( array_filter( $attempts, 'is_array' ) ) );
		// Pasada 1: los ids verificados (llenan `$seen`, para que una pista repetida de otro intento no los esconda).
		foreach ( $ordered as $attempt ) {
			$tx = isset( $attempt['transaction_id'] ) && is_string( $attempt['transaction_id'] ) ? $attempt['transaction_id'] : '';
			if ( '' !== $tx && ! isset( $seen[ $tx ] ) ) {
				$seen[ $tx ] = true;
				$verified[]  = array(
					'attempt'  => $attempt,
					'id'       => $tx,
					'verified' => true,
				);
			}
		}
		// Pasada 2: las pistas de los intentos sin id verificado, sin repetir ni repetir un verificado.
		foreach ( $ordered as $attempt ) {
			if ( ! empty( $attempt['transaction_id'] ) ) {
				continue;
			}
			foreach ( self::hints( $attempt ) as $id ) {
				if ( ! isset( $seen[ $id ] ) ) {
					$seen[ $id ] = true;
					$hints[]     = array(
						'attempt'  => $attempt,
						'id'       => $id,
						'verified' => false,
					);
				}
			}
		}
		return array_merge( $verified, $hints );
	}

	/**
	 * Cuántas pistas sin verificar hay en todo el pedido.
	 *
	 * @param array<int,mixed> $attempts Intentos.
	 * @return int
	 */
	public static function total_hints( array $attempts ): int {
		$n = 0;
		foreach ( $attempts as $attempt ) {
			if ( is_array( $attempt ) && empty( $attempt['transaction_id'] ) ) {
				$n += count( self::hints( $attempt ) );
			}
		}
		return $n;
	}

	/**
	 * Quita pistas concretas (las que la API ya descartó: NOT_FOUND y viejas, o de otro pedido).
	 *
	 * @param array<int,mixed> $attempts Intentos.
	 * @param string[]         $ids      Ids a quitar.
	 * @return array<int,mixed>
	 */
	public static function drop_hints( array $attempts, array $ids ): array {
		foreach ( $attempts as $i => $attempt ) {
			if ( ! is_array( $attempt ) || ! empty( $attempt['transaction_id'] ) ) {
				continue;
			}
			$hints = self::hints( $attempt );
			$left  = array_values( array_diff( $hints, $ids ) );
			if ( count( $left ) === count( $hints ) ) {
				continue;
			}
			unset( $attempt['hint_transaction_ids'], $attempt['hint_transaction_id'] );
			if ( array() === $left ) {
				unset( $attempt['hint_at'] );
			} else {
				$attempt['hint_transaction_ids'] = $left;
			}
			$attempts[ $i ] = $attempt;
		}
		return $attempts;
	}

	/**
	 * Quita todas las pistas (llegó el id verificado).
	 *
	 * @param array<string,mixed> $attempt Intento.
	 * @return array<string,mixed>
	 */
	public static function clear_hints( array $attempt ): array {
		unset( $attempt['hint_transaction_ids'], $attempt['hint_transaction_id'], $attempt['hint_at'] );
		return $attempt;
	}

	/**
	 * Segundos desde que llegó la última pista (o, sin ella, desde que se abrió el intento).
	 *
	 * @param array<string,mixed> $attempt Intento.
	 * @return int
	 */
	public static function hint_age( array $attempt ): int {
		foreach ( array( 'hint_at', 'at' ) as $key ) {
			$t = isset( $attempt[ $key ] ) && is_string( $attempt[ $key ] ) ? strtotime( $attempt[ $key ] ) : false;
			if ( false !== $t ) {
				return max( 0, time() - $t );
			}
		}
		return PHP_INT_MAX;
	}
}
