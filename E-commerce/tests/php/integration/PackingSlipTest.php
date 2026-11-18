<?php
/**
 * Hoja de empaque con pedidos reales (T048).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Orders\PackingSlip;
use Aralia\Tests\Support\IsolatedTestCase;

/**
 * Acceso protegido (permiso, nonce, 404) y datos reales del pedido.
 */
final class PackingSlipTest extends IsolatedTestCase {

	/**
	 * SKU único del producto de la prueba.
	 *
	 * @var string
	 */
	private string $sku = '';

	private function user( string $role ): int {
		return (int) wp_insert_user( array( 'user_login' => $role . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => $role . wp_rand() . '@example.com', 'role' => $role ) );
	}

	private function order(): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Labial hoja' );
		$this->sku = 'HOJA-' . strtoupper( substr( uniqid( '', true ), -8 ) . wp_rand( 10, 99 ) );
		$product->set_sku( $this->sku );
		$product->set_regular_price( '10000' );
		$pid   = $product->save();
		$order = wc_create_order();
		$order->add_product( wc_get_product( $pid ), 2 );
		foreach ( $order->get_items() as $item ) {
			$item->add_meta_data( '_ara_group_id', 'g-77', true );
			$item->add_meta_data( '_ara_group_label', 'Set de prueba', true );
			$item->save();
		}
		$order->set_shipping_first_name( 'Ana' );
		$order->set_shipping_last_name( 'Pérez' );
		$order->set_shipping_address_1( 'Cra 7 # 10-20' );
		$order->set_shipping_city( 'Bogotá' );
		$order->set_billing_phone( '3001234567' );
		$order->set_customer_note( 'Timbre 3' );
		$order->save();
		return $order;
	}

	public function test_from_order_reads_pieces_group_and_shipping(): void {
		$order = $this->order();
		$data  = PackingSlip::from_order( $order );
		$this->assertSame( $this->sku, $data['lines'][0]['sku'] );
		$this->assertSame( 2, $data['lines'][0]['qty'] );
		$this->assertSame( 'g-77', $data['lines'][0]['group_id'] );
		$this->assertSame( 'Ana Pérez', $data['shipping']['name'] );
		$this->assertSame( '3001234567', $data['shipping']['phone'], 'Sin teléfono de envío, usa el de facturación' );
		$this->assertSame( 'Timbre 3', $data['shipping']['notes'] );
		$order->delete( true );
	}

	public function test_handle_requires_permission_nonce_and_an_existing_order(): void {
		$order = $this->order();
		$id    = $order->get_id();

		wp_set_current_user( $this->user( 'customer' ) );
		$err = PackingSlip::handle( $id, wp_create_nonce( PackingSlip::ACTION . '_' . $id ) );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 403, $err->get_error_data(), 'Una clienta no ve la hoja, ni de su propio pedido' );

		wp_set_current_user( $this->user( 'administrator' ) );
		$this->assertSame( 403, PackingSlip::handle( $id, 'mal' )->get_error_data(), 'Sin nonce válido' );
		$this->assertSame( 403, PackingSlip::handle( $id, wp_create_nonce( PackingSlip::ACTION . '_999999' ) )->get_error_data(), 'Un nonce de otro pedido no sirve' );
		$this->assertSame( 404, PackingSlip::handle( 999999, wp_create_nonce( PackingSlip::ACTION . '_999999' ) )->get_error_data() );

		$html = PackingSlip::handle( $id, wp_create_nonce( PackingSlip::ACTION . '_' . $id ) );
		$this->assertIsString( $html );
		$this->assertStringContainsString( 'Labial hoja', $html );
		$this->assertStringContainsString( 'Set de prueba', $html );
		$order->delete( true );
	}

	public function test_the_order_list_gets_a_signed_button_only_for_managers_and_for_paid_orders(): void {
		$order = $this->order();
		$order->set_status( 'processing' );
		$order->save();
		wp_set_current_user( $this->user( 'administrator' ) );
		$actions = PackingSlip::order_action( array(), $order );
		$this->assertArrayHasKey( 'aralia_packing_slip', $actions );
		$this->assertStringContainsString( '_wpnonce=', $actions['aralia_packing_slip']['url'] );
		$order->set_status( 'pending' );
		$order->save();
		$this->assertArrayNotHasKey( 'aralia_packing_slip', PackingSlip::order_action( array(), $order ), 'Un pedido sin pagar no se empaca' );
		wp_set_current_user( $this->user( 'customer' ) );
		$order->set_status( 'processing' );
		$this->assertArrayNotHasKey( 'aralia_packing_slip', PackingSlip::order_action( array(), $order ) );
		$order->delete( true );
	}

	public function test_cost_payment_and_billing_data_never_reach_the_slip(): void {
		$order = $this->order();
		foreach ( $order->get_items() as $item ) {
			$item->add_meta_data( '_ara_cost', '987654', true );
			$item->save();
		}
		wc_get_product( wc_get_product_id_by_sku( $this->sku ) )->update_meta_data( '_ara_cost', '987654' );
		$order->set_payment_method( 'wompi' );
		$order->set_transaction_id( 'TX-SECRETO-123' );
		$order->set_billing_email( 'privado@example.com' );
		$order->set_billing_first_name( 'FacturaNombre' );
		$order->save();
		wp_set_current_user( $this->user( 'administrator' ) );
		$html = PackingSlip::handle( $order->get_id(), wp_create_nonce( PackingSlip::ACTION . '_' . $order->get_id() ) );
		foreach ( array( '987654', 'TX-SECRETO-123', 'privado@example.com', 'FacturaNombre', 'wompi', '_ara_cost' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, (string) $html, "La hoja no expone $secret" );
		}
		$order->delete( true );
	}

	public function test_refunded_quantities_are_not_packed_and_completed_orders_can_be_reprinted(): void {
		$order = $this->order();
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();
		$item = array_values( $order->get_items() )[0];
		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 10000, 'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => 10000 ) ) ) );
		$this->assertNotInstanceOf( \WP_Error::class, $refund, 'Premisa: el reembolso se creó' );
		$order = wc_get_order( $order->get_id() );
		$data  = PackingSlip::from_order( $order );
		$this->assertSame( 1, $data['lines'][0]['qty'], '2 comprados − 1 reembolsado = 1 a empacar' );
		wp_set_current_user( $this->user( 'administrator' ) );
		$this->assertArrayHasKey( 'aralia_packing_slip', PackingSlip::order_action( array(), $order ), 'Un pedido completado se puede reimprimir' );
		$order->delete( true );
	}
}
