<?php
/**
 * Doble de la clase del plugin oficial de Wompi con los callbacks ESTÁTICOS que el guard neutraliza.
 *
 * @package Aralia
 */

class Wompi_Portal_Pagos_Gateway_Custom {

	public static int $called = 0;

	public static function thankyou_order_key( $key ) {
		return $key;
	}

	public static function thankyou_order_received_text( $text ) {
		return $text;
	}

	public static function admin_order_data_after_order_details( $order ): void {}

	public static function generate_wompi_widget(): void {
		++self::$called;
	}
}
