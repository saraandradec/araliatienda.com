<?php
/**
 * POST /aralia/v1/order-status: estado del pedido para la página «Gracias» (contracts/rest-api.md §8, T062).
 *
 * @package Aralia
 */

namespace Aralia\Rest;

use Aralia\Orders\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Solo LEE. Exige la clave del pedido Y la sesión de WooCommerce con la que se creó (huella `_ara_session_hash`): quien tenga la clave
 * pero otra sesión (un enlace reenviado) no ve el estado.
 */
final class OrderStatus {

	public const ROUTE       = '/order-status';
	public const MAX_PER_MIN = 20;

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
				'permission_callback' => '__return_true',
				'args'                => array(
					'order' => array(
						'type'              => 'integer',
						'minimum'           => 1,
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'key'   => array(
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
		$limited = OrderAccess::limit( 'order-status', OrderAccess::ip(), self::MAX_PER_MIN );
		if ( null !== $limited ) {
			return $limited;
		}
		$order = OrderAccess::order_with_key( absint( $request->get_param( 'order' ) ), (string) $request->get_param( 'key' ) );
		if ( $order instanceof \WP_Error ) {
			return $order;
		}
		if ( ! OrderAccess::same_session( $order ) ) {
			return OrderAccess::error( 'aralia_order_denied', OrderAccess::MESSAGE_DENIED, 403 );
		}
		$response = new \WP_REST_Response( array( 'status' => self::status_for( $order ) ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Estado público: pending | paid | declined_retry | expired | nostock.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return string
	 */
	public static function status_for( \WC_Order $order ): string {
		$status = \Aralia\Orders\StateMachine::stored_status( $order->get_id() ); // Sin caché.
		if ( 'cancelled' === $status ) {
			return 'expired';
		}
		if ( 'ara-nostock' === $status ) {
			return 'nostock';
		}
		if ( 'pending' === $status ) {
			return self::last_attempt_declined( $order ) ? 'declined_retry' : 'pending';
		}
		if ( in_array( $status, Statuses::PAID, true ) || in_array( $status, array( 'refunded', 'ara-reversed' ), true ) ) {
			return 'paid';
		}
		return 'pending';
	}

	/**
	 * ¿El último intento fue rechazado y ninguno está PENDING?
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	private static function last_attempt_declined( \WC_Order $order ): bool {
		$attempts = $order->get_meta( '_ara_attempts', true );
		$attempts = is_array( $attempts ) ? array_values( $attempts ) : array();
		if ( array() === $attempts ) {
			return false;
		}
		foreach ( $attempts as $attempt ) {
			if ( is_array( $attempt ) && 'PENDING' === ( $attempt['status'] ?? '' ) ) {
				return false;
			}
		}
		$last = $attempts[ count( $attempts ) - 1 ];
		return is_array( $last ) && in_array( $last['status'] ?? '', array( 'DECLINED', 'ERROR', 'VOIDED' ), true );
	}
}
