<?php
/**
 * POST /aralia/v1/payment-attempt: pista del callback del widget (contracts/rest-api.md §8b, T062).
 *
 * @package Aralia
 */

namespace Aralia\Rest;

use Aralia\Orders\Attempts;
use Aralia\Orders\OrderLock;
use Aralia\Orders\OrderStore;

defined( 'ABSPATH' ) || exit;

/**
 * Solo guarda el `transaction_id` en el intento del pedido y pide una consulta inmediata a Wompi. NUNCA cambia estados: quien paga un
 * pedido es el guard, tras verificar con la API.
 */
final class PaymentAttempt {

	public const ROUTE       = '/payment-attempt';
	public const MAX_PER_MIN = 10;
	/** Tope por pedido sin importar la IP. */
	public const MAX_PER_MIN_ORDER = 30;
	public const HOOK              = 'aralia_reconcile_order';

	/**
	 * Engancha la ruta.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Registra la ruta.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route(
			'aralia/v1',
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				// La autorización es la clave del pedido (no hay usuario): se valida dentro, con respuestas uniformes.
				'permission_callback' => '__return_true',
				'args'                => array(
					'order_id'       => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'order_key'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'reference'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'transaction_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Atiende la petición.
	 *
	 * @param \WP_REST_Request $request Petición.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( \WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		// Primero un límite grueso por IP (acota la enumeración de pedidos y las escrituras del limitador); luego la clave; y solo con
		// la clave válida el límite por pedido (quien solo conoce el id no puede agotar el cupo de otra clienta).
		$limited = OrderAccess::limit( 'payment-attempt-ip', OrderAccess::ip(), 60 );
		if ( null !== $limited ) {
			return $limited;
		}
		$order = OrderAccess::order_with_key( $order_id, (string) $request->get_param( 'order_key' ) );
		if ( $order instanceof \WP_Error ) {
			return $order;
		}
		$limited = OrderAccess::limit( 'payment-attempt', $order_id . '|' . OrderAccess::ip(), self::MAX_PER_MIN );
		if ( null === $limited ) {
			// Tope por pedido independiente de la IP (rotar de IP, p. ej. dentro de un /64 de IPv6, no lo evade).
			$limited = OrderAccess::limit( 'payment-attempt-order', (string) $order_id, self::MAX_PER_MIN_ORDER );
		}
		if ( null !== $limited ) {
			return $limited;
		}
		$reference = (string) $request->get_param( 'reference' );
		$tx_id     = (string) $request->get_param( 'transaction_id' );
		if ( 1 !== preg_match( '/^(\d+)-(\d{1,3})$/', $reference, $m ) || (int) $m[1] !== $order->get_id() || 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $tx_id ) || 1 !== preg_match( '/[A-Za-z0-9]/', $tx_id ) || ! self::is_attempt( $order, $reference ) ) {
			return OrderAccess::error( 'aralia_invalid_attempt', 'Los datos del pago no son válidos.', 400 );
		}
		if ( 'pending' !== \Aralia\Orders\StateMachine::stored_status( $order->get_id() ) ) {
			// Pedido ya pagado, cancelado o vencido: no se gastan consultas a la API ni se escriben metadatos. Respuesta igual a la normal.
			return self::accepted();
		}
		$known = $order->get_meta( '_ara_attempts', true );
		if ( Attempts::total_hints( is_array( $known ) ? $known : array() ) >= Attempts::MAX_HINTS ) {
			return self::accepted(); // Lista llena: la pista nueva se descartaría; no se gasta una consulta a la API.
		}
		/**
		 * El guard verifica la pista contra la API de Wompi: `valid` (la transacción existe y es de este pedido: referencia, monto y COP),
		 * `invalid` (existe pero es de otro pedido o monto) o `unknown` (la API no la conoce todavía o no responde).
		 *
		 * @param string    $verdict   Veredicto previo.
		 * @param \WC_Order $order     Pedido.
		 * @param string    $reference Referencia del intento.
		 * @param string    $tx_id     Transacción.
		 */
		$verdict = (string) apply_filters( 'aralia_verify_payment_hint', 'unknown', $order, $reference, $tx_id );
		if ( 'invalid' === $verdict ) {
			// Respuesta uniforme (202) sin guardar: así quien tiene la clave no usa el endpoint para sondear si un id existe en Wompi.
			return self::accepted();
		}
		self::annotate( $order->get_id(), $reference, $tx_id );
		/**
		 * Hay una pista de transacción para el pedido: la conciliación (T063) la consulta de inmediato.
		 *
		 * @param int    $order_id  Pedido.
		 * @param string $reference Referencia del intento.
		 * @param string $tx_id     Transacción.
		 */
		do_action( 'aralia_payment_attempt_hint', $order->get_id(), $reference, $tx_id );
		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK, array( $order->get_id() ), 'aralia' ) ) {
			as_enqueue_async_action( self::HOOK, array( $order->get_id() ), 'aralia' );
		}
		return self::accepted();
	}

	/**
	 * Respuesta uniforme.
	 *
	 * @return \WP_REST_Response
	 */
	private static function accepted(): \WP_REST_Response {
		$response = new \WP_REST_Response( array( 'accepted' => true ), 202 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * ¿La referencia es un intento del pedido? (lectura sin candado).
	 *
	 * @param \WC_Order $order     Pedido.
	 * @param string    $reference Referencia.
	 * @return bool
	 */
	private static function is_attempt( \WC_Order $order, string $reference ): bool {
		$attempts = $order->get_meta( '_ara_attempts', true );
		foreach ( is_array( $attempts ) ? $attempts : array() as $attempt ) {
			if ( is_array( $attempt ) && ( $attempt['reference'] ?? '' ) === $reference ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Guarda la pista en el intento, bajo el candado y sobre el pedido recargado de la base de datos.
	 * NUNCA se promueve a `transaction_id`, ni siquiera si la API la reconoce (`valid`): la consulta pública de una transacción en Wompi es global, y quien
	 * tiene la clave del pedido podría traer una transacción de su propio comercio con la misma referencia y monto. El `transaction_id` verificado
	 * lo fija solo el evento firmado de Wompi (o la conciliación, T063). Hasta 3 pistas por PEDIDO; con 3 llenas, la nueva se descarta
	 * (nada se expulsa), y una pista repetida no cambia nada.
	 *
	 * @param int    $order_id  Pedido.
	 * @param string $reference Referencia.
	 * @param string $tx_id     Transacción.
	 * @return void
	 */
	private static function annotate( int $order_id, string $reference, string $tx_id ): void {
		OrderLock::run(
			$order_id,
			static function () use ( $order_id, $reference, $tx_id ): void {
				$fresh = OrderStore::fresh( $order_id );
				if ( ! $fresh instanceof \WC_Order ) {
					return;
				}
				$attempts = $fresh->get_meta( '_ara_attempts', true );
				$attempts = is_array( $attempts ) ? $attempts : array();
				if ( Attempts::total_hints( $attempts ) >= Attempts::MAX_HINTS ) {
					return;
				}
				$changed = false;
				foreach ( $attempts as $i => $attempt ) {
					if ( ! is_array( $attempt ) || ( $attempt['reference'] ?? '' ) !== $reference || ! empty( $attempt['transaction_id'] ) ) {
						continue;
					}
					$updated = Attempts::add_hint( $attempt, $tx_id );
					if ( $updated !== $attempt ) {
						$attempts[ $i ] = $updated;
						$changed        = true;
					}
				}
				if ( $changed ) {
					$fresh->update_meta_data( '_ara_attempts', $attempts );
					$fresh->save();
				}
			}
		);
		// Si no se pudo tomar el candado, la pista se pierde: es opcional (el evento de Wompi y la conciliación la repiten).
	}
}
