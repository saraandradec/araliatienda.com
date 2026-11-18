<?php
/**
 * Estados propios de pedido y su tratamiento en WooCommerce (T053, R-05).
 *
 * @package Aralia
 */

namespace Aralia\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * `ara-nostock`, `ara-packed`, `ara-shipped` y `ara-reversed`: se registran como estados de pedido (compatibles con HPOS), y los tres
 * primeros cuentan como "pagados" para WooCommerce (informes, descargas, comprobación `is_paid()`). `ara-reversed` NO es pagado: el
 * dinero volvió a la clienta.
 */
final class Statuses {

	/**
	 * Estado => etiqueta (sin el prefijo `wc-`).
	 *
	 * @var array<string,string>
	 */
	public const CUSTOM = array(
		'ara-nostock'  => 'Pagado sin stock',
		'ara-packed'   => 'Empacado',
		'ara-shipped'  => 'Enviado',
		'ara-reversed' => 'Pago revertido',
	);

	/**
	 * Estados "pagados" (R-05), sin el prefijo `wc-`.
	 *
	 * @var string[]
	 */
	public const PAID = array( 'processing', 'ara-nostock', 'ara-packed', 'ara-shipped', 'completed' );

	/**
	 * Engancha los filtros. Idempotente.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_statuses' ), 5 );
		add_filter( 'wc_order_statuses', array( self::class, 'order_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( self::class, 'paid_statuses' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( self::class, 'report_statuses' ) );
		add_filter( 'woocommerce_cancel_unpaid_order', array( self::class, 'keep_wompi_orders' ), 10, 2 );
		// La clienta no cancela desde "Mi cuenta": el stock vuelve por el vencimiento o por "Volver a mi carrito" (T065).
		add_filter( 'woocommerce_valid_order_statuses_for_cancel', '__return_empty_array', 99 );
		// Un pago revertido no es una venta en WooCommerce Analytics.
		add_filter( 'woocommerce_analytics_excluded_order_statuses', array( self::class, 'analytics_excluded' ) );
		add_action( 'woocommerce_order_status_ara-nostock', array( self::class, 'alert_nostock' ), 10, 1 );
	}

	/**
	 * Registra los estados como post statuses (solo hace falta con almacenamiento por posts; con HPOS es inocuo).
	 *
	 * @return void
	 */
	public static function register_post_statuses(): void {
		foreach ( self::CUSTOM as $slug => $label ) {
			if ( get_post_status_object( 'wc-' . $slug ) ) {
				continue;
			}
			register_post_status(
				'wc-' . $slug,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => array(
						0          => $label . ' <span class="count">(%s)</span>',
						1          => $label . ' <span class="count">(%s)</span>',
						'singular' => $label . ' <span class="count">(%s)</span>',
						'plural'   => $label . ' <span class="count">(%s)</span>',
						'context'  => null,
						'domain'   => null,
					),
				)
			);
		}
	}

	/**
	 * Añade los estados a la lista de WooCommerce.
	 *
	 * @param mixed $statuses Estados.
	 * @return array<string,string>
	 */
	public static function order_statuses( $statuses ): array {
		$statuses = is_array( $statuses ) ? $statuses : array();
		foreach ( self::CUSTOM as $slug => $label ) {
			$statuses[ 'wc-' . $slug ] = $label;
		}
		return $statuses;
	}

	/**
	 * Estados pagados (sin prefijo).
	 *
	 * @param mixed $statuses Estados.
	 * @return string[]
	 */
	public static function paid_statuses( $statuses ): array {
		return array_values( array_unique( array_merge( is_array( $statuses ) ? $statuses : array(), self::PAID ) ) );
	}

	/**
	 * Estados de los informes de ventas: los pagados (sin prefijo; los informes los esperan así).
	 *
	 * @param mixed $statuses Estados.
	 * @return string[]
	 */
	public static function report_statuses( $statuses ): array {
		return self::paid_statuses( $statuses );
	}

	/**
	 * Excluye `ara-reversed` de WooCommerce Analytics. Se usa el filtro que WooCommerce aplica SIEMPRE a la lista final (con la opción
	 * guardada o sin ella); los estados van sin prefijo `wc-`.
	 *
	 * @param mixed $statuses Estados excluidos.
	 * @return array<int,string>
	 */
	public static function analytics_excluded( $statuses ): array {
		return array_values( array_unique( array_merge( is_array( $statuses ) ? $statuses : array(), array( 'ara-reversed' ) ) ) );
	}

	/**
	 * Alerta por cada pedido que pasa a `ara-nostock`, sea cual sea la ruta que lo lleve allí.
	 *
	 * @param mixed $order_id Pedido.
	 * @return void
	 */
	public static function alert_nostock( $order_id ): void {
		do_action( 'aralia_alert', 'nostock', 'pedido ' . (int) $order_id );
	}

	/**
	 * La cancelación nativa por falta de pago no toca los pedidos de Wompi: el vencimiento es propio (T058) y consulta antes a Wompi.
	 *
	 * @param mixed $maybe_cancel Decisión de WooCommerce.
	 * @param mixed $order        Pedido.
	 * @return bool
	 */
	public static function keep_wompi_orders( $maybe_cancel, $order ): bool {
		if ( $order instanceof \WC_Order && 'wompi' === $order->get_payment_method() ) {
			return false;
		}
		return (bool) $maybe_cancel;
	}
}
