<?php
/**
 * Hoja de empaque base imprimible (T048).
 *
 * @package Aralia
 */

namespace Aralia\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Piezas, tonos, cantidades, agrupación por `_ara_group_id` y datos de envío. US4 y US5 la extienden (sets, neceser, regalos).
 * `build()` y `render()` son puras (sin WordPress) para probarse sin base de datos; `from_order()` lee el pedido y `handle()` es el acceso protegido.
 * Contiene datos personales de la compradora: solo la ve quien tiene `manage_woocommerce`, con nonce, sin caché y sin indexar.
 */
final class PackingSlip {

	public const ACTION = 'aralia_packing_slip';

	/**
	 * Engancha la acción de admin y el botón del listado de pedidos.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'serve' ) );
		add_filter( 'woocommerce_admin_order_actions', array( self::class, 'order_action' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'admin_css' ) );
	}

	/**
	 * Icono del botón en el listado de pedidos (clásico y HPOS).
	 *
	 * @param string $hook Pantalla.
	 * @return void
	 */
	public static function admin_css( $hook ): void {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo decide si se carga una hoja de estilos.
		$orders    = 'woocommerce_page_wc-orders' === (string) $hook || ( 'edit.php' === (string) $hook && 'shop_order' === $post_type );
		if ( $orders && defined( 'ARALIA_CORE_URL' ) ) {
			wp_enqueue_style( 'aralia-admin-orders', ARALIA_CORE_URL . 'assets/admin-orders.css', array(), ARALIA_CORE_VERSION );
		}
	}

	/**
	 * Botón «Hoja de empaque» en el listado de pedidos (solo pedidos pagados o en preparación).
	 *
	 * @param array<string,mixed> $actions Acciones.
	 * @param \WC_Order           $order   Pedido.
	 * @return array<string,mixed>
	 */
	public static function order_action( $actions, $order ): array {
		$actions = is_array( $actions ) ? $actions : array();
		if ( $order instanceof \WC_Order && current_user_can( 'manage_woocommerce' ) && in_array( $order->get_status(), array( 'processing', 'on-hold', 'ara-nostock', 'ara-packed', 'ara-shipped', 'completed' ), true ) ) {
			$actions['aralia_packing_slip'] = array(
				'url'    => self::url( $order->get_id() ),
				'name'   => 'Hoja de empaque',
				'action' => 'aralia-packing-slip',
			);
		}
		return $actions;
	}

	/**
	 * URL firmada de la hoja de un pedido.
	 *
	 * @param int $order_id Pedido.
	 * @return string
	 */
	public static function url( int $order_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION,
					'order_id' => $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $order_id
		);
	}

	/**
	 * Sirve la hoja (admin-post).
	 *
	 * @return void
	 */
	public static function serve(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Se verifica en handle().
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- El nonce es justo este valor y lo verifica handle().
		$html     = self::handle( $order_id, $nonce );
		if ( $html instanceof \WP_Error ) {
			wp_die( esc_html( $html->get_error_message() ), (int) $html->get_error_data() );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'none'" );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapa cada dato.
		exit;
	}

	/**
	 * Valida el acceso y devuelve el HTML o un error con el código HTTP.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $nonce    Nonce.
	 * @return string|\WP_Error
	 */
	public static function handle( int $order_id, string $nonce ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'aralia_slip_forbidden', 'No tienes permiso.', 403 );
		}
		if ( ! wp_verify_nonce( $nonce, self::ACTION . '_' . $order_id ) ) {
			return new \WP_Error( 'aralia_slip_nonce', 'El enlace expiró o no es válido.', 403 );
		}
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'aralia_slip_missing', 'Pedido no encontrado.', 404 );
		}
		return self::render( self::from_order( $order ) );
	}

	/**
	 * Datos del pedido en la forma que espera `build()`. `_ara_tone_term` y `_ara_group_label` los escriben US4 y US5 (ver data-model §3).
	 *
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<string,mixed>
	 */
	public static function from_order( \WC_Order $order ): array {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$tone    = (string) $item->get_meta( '_ara_tone_term' );
			if ( '' === $tone && $product instanceof \WC_Product_Variation ) {
				$tone = (string) $product->get_attribute( 'pa_tono' );
			}
			$lines[] = array(
				'name'        => $item->get_name(),
				'sku'         => $product ? (string) $product->get_sku() : '',
				'tone'        => $tone,
				// Lo reembolsado no se empaca (get_qty_refunded_for_item es negativo).
				'qty'         => max( 0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item->get_id() ) ),
				'group_id'    => (string) $item->get_meta( '_ara_group_id' ),
				'group_label' => (string) $item->get_meta( '_ara_group_label' ),
				'missing'     => (bool) $item->get_meta( '_ara_missing' ),
			);
		}
		return array(
			'number'   => (string) $order->get_order_number(),
			'date'     => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
			'shipping' => array(
				'name'    => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
				'address' => trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() ),
				'city'    => $order->get_shipping_city(),
				'state'   => $order->get_shipping_state(),
				// El teléfono de envío; solo si falta, el de facturación (quien entrega necesita poder llamar). Es el único dato de facturación que sale.
				'phone'   => $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone(),
				'notes'   => $order->get_customer_note(),
			),
			'lines'    => $lines,
		);
	}

	/**
	 * Agrupa las líneas por `_ara_group_id` (set o neceser) conservando el orden de aparición; las sueltas van juntas al final.
	 *
	 * @param array<string,mixed> $data Datos (ver from_order).
	 * @return array{number:string,date:string,shipping:array<string,string>,groups:array<int,array{label:string,lines:array<int,array<string,mixed>>}>,total_units:int,has_missing:bool}
	 */
	public static function build( array $data ): array {
		$groups = array();
		$loose  = array();
		$units  = 0;
		$miss   = false;
		foreach ( (array) ( $data['lines'] ?? array() ) as $line ) {
			$line['qty'] = max( 0, (int) ( $line['qty'] ?? 0 ) );
			$units      += $line['qty'];
			$miss        = $miss || ! empty( $line['missing'] );
			$gid         = trim( (string) ( $line['group_id'] ?? '' ) );
			if ( '' === $gid ) {
				$loose[] = $line;
				continue;
			}
			if ( ! isset( $groups[ $gid ] ) ) {
				$groups[ $gid ] = array(
					'label' => '' !== trim( (string) ( $line['group_label'] ?? '' ) ) ? trim( (string) $line['group_label'] ) : 'Set',
					'lines' => array(),
				);
			}
			$groups[ $gid ]['lines'][] = $line;
		}
		$out = array_values( $groups );
		if ( $loose ) {
			$out[] = array(
				'label' => 'Piezas sueltas',
				'lines' => $loose,
			);
		}
		$ship = (array) ( $data['shipping'] ?? array() );
		return array(
			'number'      => (string) ( $data['number'] ?? '' ),
			'date'        => (string) ( $data['date'] ?? '' ),
			'shipping'    => array(
				'name'    => (string) ( $ship['name'] ?? '' ),
				'address' => (string) ( $ship['address'] ?? '' ),
				'city'    => (string) ( $ship['city'] ?? '' ),
				'state'   => (string) ( $ship['state'] ?? '' ),
				'phone'   => (string) ( $ship['phone'] ?? '' ),
				'notes'   => (string) ( $ship['notes'] ?? '' ),
			),
			'groups'      => $out,
			'total_units' => $units,
			'has_missing' => $miss,
		);
	}

	/**
	 * HTML imprimible. Cada dato se escapa; sin scripts ni recursos externos.
	 *
	 * @param array<string,mixed> $data Datos (ver from_order).
	 * @return string
	 */
	public static function render( array $data ): string {
		$slip  = self::build( $data );
		$e     = static fn( string $v ): string => htmlspecialchars( $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$html  = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Hoja de empaque #' . $e( $slip['number'] ) . '</title>'
			. '<style>body{font:14px/1.4 system-ui,sans-serif;margin:24px;color:#000}h1{font-size:20px;margin:0 0 4px}h2{font-size:15px;margin:18px 0 6px;border-bottom:1px solid #000}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #999;padding:6px 4px;text-align:left}td.q,th.q{text-align:right;width:60px}.miss{font-weight:700}.box{border:1px solid #000;padding:8px;margin:8px 0}@media print{body{margin:0}}</style></head><body>';
		$html .= '<h1>Hoja de empaque · Pedido #' . $e( $slip['number'] ) . '</h1><div>' . $e( $slip['date'] ) . ' · ' . (int) $slip['total_units'] . ' unidad(es)</div>';
		$ship  = $slip['shipping'];
		$html .= '<div class="box"><strong>Enviar a</strong><br>' . $e( $ship['name'] ) . '<br>' . $e( $ship['address'] ) . '<br>' . $e( trim( $ship['city'] . ' ' . $ship['state'] ) ) . '<br>Tel. ' . $e( $ship['phone'] );
		if ( '' !== $ship['notes'] ) {
			$html .= '<br><em>Nota de la compradora: ' . $e( $ship['notes'] ) . '</em>';
		}
		$html .= '</div>';
		if ( $slip['has_missing'] ) {
			$html .= '<div class="box miss">Atención: hay líneas sin stock (marcadas «SIN STOCK»). No empacar esas piezas.</div>';
		}
		foreach ( $slip['groups'] as $group ) {
			$html .= '<h2>' . $e( $group['label'] ) . '</h2><table><thead><tr><th>Pieza</th><th>SKU</th><th>Tono</th><th class="q">Cant.</th></tr></thead><tbody>';
			foreach ( $group['lines'] as $line ) {
				$html .= '<tr><td>' . $e( (string) ( $line['name'] ?? '' ) ) . ( ! empty( $line['missing'] ) ? ' <span class="miss">SIN STOCK</span>' : '' ) . '</td><td>' . $e( (string) ( $line['sku'] ?? '' ) ) . '</td><td>' . $e( (string) ( $line['tone'] ?? '' ) ) . '</td><td class="q">' . (int) $line['qty'] . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}
		return $html . '</body></html>';
	}
}
