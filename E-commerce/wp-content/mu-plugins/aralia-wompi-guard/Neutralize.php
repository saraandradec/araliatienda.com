<?php
/**
 * Neutraliza lo del plugin oficial que el guard reemplaza (T052, H7, H13).
 *
 * @package Aralia
 */

namespace Aralia\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * - El plugin no recibe eventos: el guard responde primero y termina la petición (se quita además su manejador).
 * - Sin estado "Anulado" (`wc-voided`) ni anulación desde el panel (`woocommerce_process_shop_order_meta` del plugin).
 * - Sin reembolsos desde la tienda: Sara los hace en el panel de Wompi y la tienda solo los registra.
 */
final class Neutralize {

	/**
	 * Engancha todo.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wc_order_statuses', array( self::class, 'drop_voided' ), PHP_INT_MAX );
		add_filter( 'woocommerce_admin_order_should_render_refunds', '__return_false', PHP_INT_MAX );
		add_filter( 'woocommerce_order_actions', array( self::class, 'drop_actions' ), PHP_INT_MAX );
		// Los ganchos del plugin se agregan al construirlo (plugins_loaded:0 / init): se quitan después y otra vez más tarde.
		add_action( 'init', array( self::class, 'remove_plugin_hooks' ), PHP_INT_MAX );
		add_action( 'woocommerce_init', array( self::class, 'remove_plugin_hooks' ), PHP_INT_MAX );
		add_action( 'wp_loaded', array( self::class, 'remove_plugin_hooks' ), PHP_INT_MAX );
		// El widget del plugin (referencia sin intento, sin firma por intento) se desactiva DENTRO de su propio gancho: la pasarela se
		// construye de forma perezosa y puede registrarlo después de wp_loaded. Hasta T061 no hay order-pay del guard: falla cerrado.
		add_action( 'woocommerce_receipt_wompi', array( self::class, 'take_over_receipt' ), 0 );
	}

	/**
	 * Quita el widget del plugin del gancho de order-pay y, mientras no exista el order-pay del guard, avisa en lugar de cobrar.
	 *
	 * @return void
	 */
	public static function take_over_receipt(): void {
		self::remove_class_action( 'woocommerce_receipt_wompi', 'Wompi_Portal_Pagos_Gateway_Custom' );
		self::remove_class_action( 'woocommerce_receipt_wompi', 'WompiPortalPagos_Gateway' );
		if ( ! class_exists( '\\Aralia\\Guard\\OrderPay' ) ) {
			echo '<p class="aralia-pay-unavailable">El pago con Wompi no está disponible en este momento. Intenta de nuevo más tarde.</p>';
		}
	}

	/**
	 * Quita `wc-voided` de la lista de estados.
	 *
	 * @param mixed $statuses Estados.
	 * @return array<string,string>
	 */
	public static function drop_voided( $statuses ): array {
		$statuses = is_array( $statuses ) ? $statuses : array();
		unset( $statuses['wc-voided'] );
		return $statuses;
	}

	/**
	 * Quita de las acciones del pedido las que añade el plugin de Wompi (anular, reembolsar).
	 *
	 * @param mixed $actions Acciones.
	 * @return array<string,string>
	 */
	public static function drop_actions( $actions ): array {
		$actions = is_array( $actions ) ? $actions : array();
		foreach ( array_keys( $actions ) as $key ) {
			if ( 0 === strpos( (string) $key, 'wompi' ) || false !== strpos( (string) $key, 'void' ) ) {
				unset( $actions[ $key ] );
			}
		}
		return $actions;
	}

	/**
	 * Quita los ganchos del plugin oficial que el guard reemplaza.
	 *
	 * @return void
	 */
	public static function remove_plugin_hooks(): void {
		self::remove_class_action( 'woocommerce_process_shop_order_meta', 'Wompi_Portal_Pagos_Order_Statuses' );
		self::remove_class_action( 'woocommerce_api_wc_wompi', 'Wompi_Portal_Pagos_Webhook_Handler' );
		// Callbacks ESTÁTICOS del plugin (clase como texto): "Gracias" con la clave real del pedido sin verificarla (I-2), texto que falla
		// si el pedido no existe y datos de pedido con get_post_meta (no funciona con HPOS). La validación de campos del checkout se conserva.
		self::remove_class_action( 'woocommerce_thankyou_order_key', 'Wompi_Portal_Pagos_Gateway_Custom' );
		self::remove_class_action( 'woocommerce_thankyou_order_received_text', 'Wompi_Portal_Pagos_Gateway_Custom' );
		self::remove_class_action( 'woocommerce_admin_order_data_after_order_details', 'Wompi_Portal_Pagos_Gateway_Custom' );
	}

	/**
	 * Quita de un gancho todo callback cuyo objeto sea de la clase dada (WordPress no permite quitar métodos de instancias ajenas
	 * sin tener la instancia).
	 *
	 * @param string $hook       Gancho.
	 * @param string $class_name Clase del plugin.
	 * @return int Callbacks quitados.
	 */
	public static function remove_class_action( string $hook, string $class_name ): int {
		global $wp_filter;
		if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return 0;
		}
		$removed = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( ! is_array( $function ) || ! isset( $function[0] ) ) {
					continue;
				}
				// Método de instancia (objeto) o estático (nombre de clase como texto).
				$owner = $function[0];
				if ( ( is_object( $owner ) && $owner instanceof $class_name ) || ( is_string( $owner ) && ltrim( $owner, '\\' ) === $class_name ) ) {
					remove_action( $hook, $function, (int) $priority );
					++$removed;
				}
			}
		}
		return $removed;
	}
}
