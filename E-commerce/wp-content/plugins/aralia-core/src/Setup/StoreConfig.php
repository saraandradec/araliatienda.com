<?php
/**
 * Configuración base de WooCommerce para Aralía (T023).
 *
 * @package Aralia
 */

namespace Aralia\Setup;

use Aralia\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Aplica y verifica las opciones de la tienda. Idempotente.
 */
class StoreConfig {

	/**
	 * Opciones que se fijan (opción => valor esperado).
	 *
	 * @param string|null $env Entorno (por defecto, ARALIA_ENV; el parámetro existe para probar).
	 * @return array<string,string>
	 */
	public static function expected( ?string $env = null ): array {
		$options = array(
			// Pesos colombianos sin decimales: el monto en centavos de Wompi sale de aquí (R-04).
			'woocommerce_currency'                      => 'COP',
			'woocommerce_currency_pos'                  => 'left',
			'woocommerce_price_thousand_sep'            => '.',
			'woocommerce_price_decimal_sep'             => ',',
			'woocommerce_price_num_decimals'            => '0',
			'woocommerce_default_country'               => 'CO',
			// Stock gestionado y reserva de 30 minutos (el vencimiento propio, T058, usa aralia_hold_minutes).
			'woocommerce_manage_stock'                  => 'yes',
			'woocommerce_hold_stock_minutes'            => '30',
			// HPOS (la sincronización se añade más abajo según el entorno).
			'woocommerce_custom_orders_table_enabled'   => 'yes',
			// Compra como invitada; la creación de cuenta no se ofrece desde el checkout.
			'woocommerce_enable_guest_checkout'         => 'yes',
			'woocommerce_enable_signup_and_login_from_checkout' => 'no',
			// Sin alta pública desde Mi cuenta (independiente de users_can_register).
			'woocommerce_enable_myaccount_registration' => 'no',
			// Nadie se registra con un rol distinto de cliente.
			'default_role'                              => 'customer',
			'users_can_register'                        => '0',
		);
		// HPOS con sincronización hasta la apertura; la Fase 5 la apaga y `setup` no debe volver a encenderla en producción.
		// Lista blanca: con ARALIA_ENV sin definir o desconocido se trata como producción (no se toca la sincronización).
		if ( Environment::allows_replay( $env ) ) {
			$options['woocommerce_custom_orders_table_data_sync_enabled'] = 'yes';
		}
		return $options;
	}

	/**
	 * Con pedidos existentes no se cambia HPOS con `update_option` (hay que migrar con el controlador de WooCommerce).
	 *
	 * @return bool
	 */
	public static function hpos_switch_is_safe(): bool {
		if ( 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) ) {
			return true;
		}
		$orders = function_exists( 'wc_get_orders' ) ? wc_get_orders(
			array(
				'limit'  => 1,
				'return' => 'ids',
				'status' => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ),
			)
		) : array();
		return array() === $orders;
	}

	/**
	 * Diferencias entre lo esperado y lo actual.
	 *
	 * @return array<string,array{from:string,to:string}>
	 */
	public static function diff(): array {
		$out       = array();
		$hpos_safe = self::hpos_switch_is_safe();
		foreach ( self::expected() as $option => $value ) {
			if ( ! $hpos_safe && str_starts_with( $option, 'woocommerce_custom_orders_table' ) ) {
				continue;
			}
			$current = (string) get_option( $option, '' );
			if ( $current !== $value ) {
				$out[ $option ] = array(
					'from' => $current,
					'to'   => $value,
				);
			}
		}
		if ( ! self::checkout_page_exists() ) {
			$out['checkout_page_content'] = array(
				'from' => 'sin página',
				'to'   => 'página con el bloque woocommerce/checkout',
			);
		} elseif ( ! self::checkout_is_block() ) {
			$out['checkout_page_content'] = array(
				'from' => 'shortcode',
				'to'   => 'bloque woocommerce/checkout',
			);
		}
		return $out;
	}

	/**
	 * Aplica la configuración.
	 *
	 * @param callable|null $checkout_provider Devuelve el contenido del checkout (por defecto, el de WooCommerce; se inyecta en pruebas).
	 * @return array<string,array{from:string,to:string}> Lo que cambió.
	 * @throws \RuntimeException Si no se puede preparar el checkout (no se escribe nada a medias).
	 */
	public static function apply( ?callable $checkout_provider = null ): array {
		$changes = self::diff();
		// El contenido del checkout se obtiene primero: si falla, no se cambia ninguna opción.
		$checkout = '';
		if ( isset( $changes['checkout_page_content'] ) ) {
			$checkout = null !== $checkout_provider ? (string) $checkout_provider() : self::default_checkout_content();
		}
		foreach ( $changes as $option => $change ) {
			if ( 'checkout_page_content' === $option ) {
				continue;
			}
			update_option( $option, $change['to'] );
		}
		if ( isset( $changes['checkout_page_content'] ) ) {
			self::ensure_checkout_block( $checkout );
		}
		return $changes;
	}

	/**
	 * Página de checkout (ID) o 0.
	 *
	 * @return int
	 */
	private static function checkout_page_id(): int {
		return function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
	}

	/**
	 * Existe la página de checkout.
	 *
	 * @return bool
	 */
	public static function checkout_page_exists(): bool {
		$id   = self::checkout_page_id();
		$post = $id > 0 ? get_post( $id ) : null;
		// Una página en la papelera no cuenta.
		return $post && 'trash' !== $post->post_status;
	}

	/**
	 * La página de checkout usa el bloque nativo.
	 *
	 * @return bool
	 */
	public static function checkout_is_block(): bool {
		$id   = self::checkout_page_id();
		$post = $id > 0 ? get_post( $id ) : null;
		return $post && false !== strpos( (string) $post->post_content, '<!-- wp:woocommerce/checkout' );
	}

	/**
	 * Contenido por defecto del bloque de checkout de WooCommerce (con sus bloques internos).
	 *
	 * Usa un método interno de WooCommerce (WC_Install::get_checkout_block_content). Si WooCommerce lo cambia, FALLA de forma visible:
	 * un contenedor vacío dejaría el checkout roto dando la configuración por buena.
	 *
	 * @return string
	 * @throws \RuntimeException Si no se puede obtener el contenido por defecto.
	 */
	private static function default_checkout_content(): string {
		try {
			if ( ! class_exists( 'WC_Install' ) ) {
				throw new \RuntimeException( 'WooCommerce no está cargado.' );
			}
			$method  = new \ReflectionMethod( 'WC_Install', 'get_checkout_block_content' );
			$content = $method->invoke( null );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'No se pudo obtener el bloque de checkout por defecto de WooCommerce (¿cambió su API interna?): ' . esc_html( $e->getMessage() ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( ! is_string( $content ) || false === strpos( $content, 'wp:woocommerce/checkout-fields-block' ) ) {
			throw new \RuntimeException( 'El bloque de checkout por defecto de WooCommerce no trae sus bloques internos.' );
		}
		return $content;
	}

	/**
	 * Crea la página de checkout con el bloque nativo, o sustituye el shortcode clásico por el bloque.
	 *
	 * @param string $content Contenido del bloque.
	 * @return void
	 */
	private static function ensure_checkout_block( string $content ): void {
		if ( ! self::checkout_page_exists() ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Finalizar compra',
					'post_name'    => 'checkout',
					'post_content' => $content,
				)
			);
			if ( is_int( $id ) && $id > 0 ) {
				update_option( 'woocommerce_checkout_page_id', $id );
			}
			return;
		}
		wp_update_post(
			array(
				'ID'           => self::checkout_page_id(),
				'post_content' => $content,
			)
		);
	}
}
