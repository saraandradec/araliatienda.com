<?php
/**
 * T037: cupones porcentuales a peso entero con price_num_decimals = 0 y su efecto en amount_in_cents.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Tests\Support\IsolatedTestCase;

final class CouponRoundingTest extends IsolatedTestCase {

	/**
	 * @var array<int,\WC_Coupon|\WC_Order|\WC_Product>
	 */
	private array $objects = array();

	/**
	 * @var array<string,mixed>
	 */
	private array $saved = array();

	protected function set_up() {
		parent::set_up();
		foreach ( array( 'woocommerce_currency', 'woocommerce_price_num_decimals', 'woocommerce_prices_include_tax', 'woocommerce_calc_taxes' ) as $o ) {
			$this->saved[ $o ] = get_option( $o, null );
		}
		update_option( 'woocommerce_currency', 'COP' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		update_option( 'woocommerce_calc_taxes', 'no' );
	}

	protected function tear_down() {
		foreach ( $this->objects as $o ) {
			$o->delete( true );
		}
		foreach ( $this->saved as $o => $v ) {
			null === $v ? delete_option( $o ) : update_option( $o, $v );
		}
		parent::tear_down();
	}

	/**
	 * Total del pedido de un producto con un cupón porcentual.
	 */
	private function total_with_percent_coupon( int $unit_price, int $qty, int $percent ): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Redondeo ' . uniqid() );
		$product->set_regular_price( (string) $unit_price );
		$product->save();
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'pct' . $percent . '-' . uniqid() );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( $percent );
		$coupon->save();
		$order = wc_create_order();
		$order->add_product( $product, $qty );
		$order->apply_coupon( $coupon );
		$order->calculate_totals( true );
		$order->save();
		array_push( $this->objects, $product, $coupon, $order );
		return $order;
	}

	/**
	 * Casos con el resultado EXACTO que debe dar WooCommerce con `price_num_decimals = 0` (verificados contra la instalación fijada).
	 *
	 * @return array<string,array{int,int,int,int,int}> [precio, cantidad, porcentaje, descuento, total].
	 */
	public static function provide_cases(): array {
		return array(
			'7 % de 35.000'     => array( 35000, 1, 7, 2450, 32550 ),
			'13 % de 28.990'    => array( 28990, 1, 13, 3769, 25221 ),
			'33 % de 41.990'    => array( 41990, 1, 33, 13857, 28133 ),
			'33 % de 9.999 x 2' => array( 9999, 2, 33, 6599, 13399 ),
			'7 % de 12.345 x 3' => array( 12345, 3, 7, 2592, 34443 ),
		);
	}

	/**
	 * Valores exactos de descuento y total, y el monto en centavos que viajará a Wompi: múltiplo de 100 y sin fracciones.
	 * El cálculo de centavos es el mismo que usará el guard (T060): (int) round( total * 100 ).
	 *
	 * @dataProvider provide_cases
	 */
	public function test_percent_coupon_discount_total_and_cents_are_exact( int $price, int $qty, int $percent, int $discount, int $total ): void {
		$order = $this->total_with_percent_coupon( $price, $qty, $percent );
		$this->assertSame( 0, wc_get_price_decimals() );
		$this->assertSame( (float) $discount, (float) $order->get_discount_total(), "Descuento de $percent % sobre " . ( $price * $qty ) );
		$this->assertSame( (float) $total, (float) $order->get_total(), 'Total del pedido' );
		$this->assertSame( (float) ( $price * $qty ), (float) $order->get_total() + (float) $order->get_discount_total(), 'Total + descuento = bruto: no se pierde ni se inventa un peso' );

		$cents = (int) round( (float) $order->get_total() * 100 );
		$this->assertSame( $total * 100, $cents );
		$this->assertSame( 0, $cents % 100 );
	}

	public function test_several_lines_and_a_second_coupon_still_give_whole_pesos(): void {
		$p1 = new \WC_Product_Simple();
		$p1->set_name( 'Línea 1 ' . uniqid() );
		$p1->set_regular_price( '12345' );
		$p1->save();
		$p2 = new \WC_Product_Simple();
		$p2->set_name( 'Línea 2 ' . uniqid() );
		$p2->set_regular_price( '9999' );
		$p2->save();
		$c = new \WC_Coupon();
		$c->set_code( 'multi-' . uniqid() );
		$c->set_discount_type( 'percent' );
		$c->set_amount( 13 );
		$c->save();
		$order = wc_create_order();
		$order->add_product( $p1, 2 );
		$order->add_product( $p2, 3 );
		$order->apply_coupon( $c );
		$order->calculate_totals( true );
		$order->save();
		array_push( $this->objects, $p1, $p2, $c, $order );
		$gross = 12345 * 2 + 9999 * 3;
		$this->assertSame( round( (float) $order->get_total() ), (float) $order->get_total() );
		$this->assertSame( (float) $gross, (float) $order->get_total() + (float) $order->get_discount_total() );
		$this->assertSame( 0, (int) round( (float) $order->get_total() * 100 ) % 100 );
	}
}
