<?php
/**
 * Order-pay propio del guard (T061): intento creado por POST bajo el candado, firma en el servidor, topes y falla cerrado.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration\Wompi;

use Aralia\Guard\Keys;
use Aralia\Guard\OrderPay;
use Aralia\Orders\Expiry;
use Aralia\Orders\OrderStore;
use Aralia\Orders\StateMachine;
use Aralia\Orders\StockOnCreate;
use Aralia\Security\AbuseLimits;
use Aralia\Security\RateLimiter;
use Aralia\Support\Clock;
use Aralia\Tests\Support\IsolatedTestCase;
use Aralia\Tests\Support\OrderStates;
use Aralia\Tests\Support\WompiFixtures;

final class OrderPayTest extends IsolatedTestCase {

	/**
	 * @var int[]
	 */
	private array $orders = array();

	/**
	 * @var int[]
	 */
	private array $products = array();

	private string $currency = 'USD';

	protected function set_up() {
		parent::set_up();
		update_option( Keys::OPTION, array( 'testmode' => 'yes' ) );
		$this->currency = (string) get_option( 'woocommerce_currency' );
		update_option( 'woocommerce_currency', 'COP' );
		remove_all_actions( 'aralia_alert' );
		remove_all_filters( 'pre_http_request' );
		Clock::reset();
		$this->clear_limits();
	}

	protected function tear_down() {
		Clock::reset();
		update_option( 'woocommerce_currency', $this->currency );
		delete_option( Keys::OPTION );
		foreach ( $this->orders as $id ) {
			delete_option( 'aralia_exp_lock_' . $id );
			as_unschedule_all_actions( Expiry::HOOK, array( $id ), Expiry::GROUP );
			$o = wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
		foreach ( $this->products as $id ) {
			wp_delete_post( $id, true );
		}
		$this->clear_limits();
		unset( $_GET['key'], $_GET['pay_for_order'] );
		$GLOBALS['wp']->query_vars = array();
		$this->orders              = array();
		$this->products            = array();
		parent::tear_down();
	}

	private function clear_limits(): void {
		global $wpdb;
		$t = $wpdb->prefix . 'aralia_rate_limits';
		$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore WordPress.DB
	}

	private function order( array $attempts = array() ): \WC_Order {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Pieza ' . wp_rand() );
		$p->set_sku( 'OP-' . strtoupper( substr( uniqid( '', true ), -8 ) ) . wp_rand( 10, 99 ) );
		$p->set_regular_price( '35000' );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 5 );
		$p->save();
		$this->products[] = $p->get_id();
		$order            = wc_create_order();
		$order->add_product( $p, 1 );
		$order->set_payment_method( 'wompi' );
		$order->calculate_totals();
		if ( array() !== $attempts ) {
			$order->update_meta_data( '_ara_attempts', $attempts );
		}
		$order->save();
		$this->orders[] = $order->get_id();
		StockOnCreate::handle( $order );
		return wc_get_order( $order->get_id() );
	}

	private function attempts( \WC_Order $o ): array {
		$a = OrderStore::fresh( $o->get_id() )->get_meta( '_ara_attempts', true );
		return is_array( $a ) ? $a : array();
	}

	private function mock( array $map ): void {
		remove_all_filters( 'pre_http_request' );
		WompiFixtures::mock_api( $map );
	}

	private function ago( int $seconds ): string {
		return gmdate( 'c', time() - $seconds );
	}

	// ---- apertura ---------------------------------------------------------------------------------------------------------------

	public function test_opening_creates_the_attempt_signs_on_the_server_and_each_opening_after_a_minute_is_a_new_reference(): void {
		$o    = $this->order();
		$view = OrderPay::open( $o );

		$this->assertSame( 'open', $view['state'] );
		$this->assertSame( $o->get_id() . '-1', $view['reference'] );
		$this->assertCount( 1, $this->attempts( $o ), 'El intento ya está guardado cuando se devuelve la configuración' );
		$this->assertSame( 3500000, $view['cents'] );
		$this->assertSame( hash( 'sha256', $o->get_id() . '-1' . '3500000' . 'COP' . ARALIA_WOMPI_INTEGRITY_TEST ), $view['signature'] );
		$this->assertSame( ARALIA_WOMPI_PUB_TEST, $view['public'] );

		$again = OrderPay::open( $o );
		$this->assertSame( $o->get_id() . '-1', $again['reference'], 'Doble clic, dos pestañas o reintento del JS: se reutiliza el intento de hace menos de 60 s' );
		$this->assertCount( 1, $this->attempts( $o ) );

		$att               = $this->attempts( $o );
		$att[0]['at']      = $this->ago( 120 );
		$fresh             = OrderStore::fresh( $o->get_id() );
		$fresh->update_meta_data( '_ara_attempts', $att );
		$fresh->save();
		$next = OrderPay::open( $o );
		$this->assertSame( $o->get_id() . '-2', $next['reference'], 'Pasado el minuto, cada apertura es un intento nuevo' );
	}

	public function test_n_is_the_highest_existing_plus_one_even_with_gaps(): void {
		$o = $this->order( array( array( 'n' => 7, 'reference' => '0-7', 'status' => 'DECLINED', 'at' => $this->ago( 7200 ), 'declined_at' => $this->ago( 7200 ) ) ) );
		$this->assertSame( $o->get_id() . '-8', OrderPay::open( $o )['reference'], 'Nunca se repite una referencia' );
	}

	public function test_the_get_page_has_no_effects_and_prints_only_a_container(): void {
		$o = $this->order();
		for ( $i = 0; $i < 3; $i++ ) {
			ob_start();
			OrderPay::render( $o->get_id() );
			$html = (string) ob_get_clean();
		}
		$this->assertEmpty( $this->attempts( $o ), 'Un GET (escáner de correo, vista previa) no crea intentos ni gasta la extensión' );
		$dom = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR );
		$root = $dom->getElementById( 'aralia-wompi-pay' );
		$this->assertNotNull( $root );
		foreach ( array( 'data-order', 'data-key', 'data-open', 'data-endpoint', 'data-redirect' ) as $attr ) {
			$this->assertTrue( $root->hasAttribute( $attr ), "Sobrevive $attr" );
		}
		$this->assertSame( $o->get_order_key(), $root->getAttribute( 'data-key' ) );
		$this->assertStringContainsString( '/aralia/v1/order-pay/open', $root->getAttribute( 'data-open' ) );
		$this->assertStringNotContainsString( '<script', $html, 'CSP estricta: sin JavaScript inline' );
		$this->assertStringNotContainsString( 'data-signature', $html, 'La firma solo viaja en la respuesta del POST' );
		foreach ( array( ARALIA_WOMPI_INTEGRITY_TEST, ARALIA_WOMPI_PRV_TEST, ARALIA_WOMPI_EVENTS_TEST ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $html );
		}
		$this->assertTrue( wp_script_is( 'wompi-widget', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'aralia-wompi-order-pay', 'enqueued' ) );
		$this->assertSame( 'https://checkout.wompi.co/widget.js', wp_scripts()->registered['wompi-widget']->src );
		$this->assertTrue( \Aralia\Security\Csp::handle_may_have_nonce( 'aralia-wompi-order-pay' ) && \Aralia\Security\Csp::handle_may_have_nonce( 'wompi-widget' ) );
	}

	// ---- estados, bajo el candado y con el pedido recargado ----------------------------------------------------------------------

	public function test_a_stale_copy_cannot_overwrite_what_another_process_wrote_and_the_status_is_rechecked_inside_the_lock(): void {
		$o     = $this->order();
		$stale = wc_get_order( $o->get_id() ); // La página cargó el pedido...

		// ...y mientras tanto el guard anotó la transacción de otro intento.
		$fresh = OrderStore::fresh( $o->get_id() );
		$fresh->update_meta_data( '_ara_attempts', array( array( 'n' => 1, 'reference' => $o->get_id() . '-1', 'status' => 'DECLINED', 'transaction_id' => 'TX-REAL', 'at' => $this->ago( 7200 ), 'declined_at' => $this->ago( 7200 ) ) ) );
		$fresh->save();
		$this->mock( array( 'TX-REAL' => array( 'DECLINED', 3500000, 'COP', $o->get_id() . '-1' ) ) );

		$view = OrderPay::open( $stale );
		$this->assertSame( 'open', $view['state'] );
		$stored = $this->attempts( $o );
		$this->assertCount( 2, $stored );
		$this->assertSame( 'TX-REAL', $stored[0]['transaction_id'], 'No se pierde lo que escribió el otro proceso' );
		$this->assertSame( $o->get_id() . '-2', $stored[1]['reference'] );

		// Y si el vencimiento canceló el pedido entre la carga y el candado, NO se abre un widget sobre un pedido cancelado.
		$late  = $this->order();
		$stale = wc_get_order( $late->get_id() );
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $late->get_id() );
		Clock::reset();
		remove_all_filters( 'aralia_wompi_lookup' );
		$this->assertSame( 'expired', OrderPay::open( $stale )['state'] );
		$this->assertEmpty( $this->attempts( $late ), 'Y no se creó intento' );
	}

	public function test_a_reservation_that_already_expired_is_never_opened(): void {
		$o = $this->order();
		Clock::advance( 36 * MINUTE_IN_SECONDS ); // Pasados los 35 min no se puede extender, y Action Scheduler aún no canceló.
		$this->assertSame( 'expired', OrderPay::open( $o )['state'] );
		$this->assertEmpty( $this->attempts( $o ) );
	}

	public function test_paid_sentinel_and_foreign_currency_orders_never_open(): void {
		$p = $this->order();
		OrderStates::walk( $p, 'processing' );
		$this->assertSame( 'paid', OrderPay::open( $p )['state'] );

		$s = $this->order();
		$s->set_created_via( 'aralia-sentinel' );
		$s->save();
		$this->assertSame( 'other', OrderPay::open( wc_get_order( $s->get_id() ) )['state'] );
		$this->assertEmpty( $this->attempts( $s ) );

		update_option( 'woocommerce_currency', 'USD' );
		$this->assertSame( 'unavailable', OrderPay::open( $this->order() )['state'], 'Solo COP' );
		update_option( 'woocommerce_currency', 'COP' );
	}

	public function test_the_hold_is_extended_inside_the_open_and_the_countdown_is_returned(): void {
		$o      = $this->order();
		$before = (int) OrderStore::fresh( $o->get_id() )->get_meta( '_ara_expires_at', true );
		Clock::advance( 28 * MINUTE_IN_SECONDS );
		$view  = OrderPay::open( $o );
		$after = (int) OrderStore::fresh( $o->get_id() )->get_meta( '_ara_expires_at', true );
		$this->assertGreaterThan( $before, $after, 'Widget abierto cerca del vencimiento: +5 min' );
		$created = OrderStore::fresh( $o->get_id() )->get_date_created()->getTimestamp();
		$this->assertLessThanOrEqual( $created + 35 * MINUTE_IN_SECONDS, $after );
		$this->assertGreaterThan( 0, $view['expires_in'], 'Segundos que quedan según el servidor (no una hora del reloj del teléfono)' );
		$this->assertLessThanOrEqual( 35 * MINUTE_IN_SECONDS, $view['expires_in'] );
		Clock::reset();
	}

	// ---- topes -------------------------------------------------------------------------------------------------------------------

	public function test_thirty_opens_in_the_last_hour_block_and_old_ones_do_not_count(): void {
		$recent = array();
		for ( $i = 1; $i <= OrderPay::MAX_OPENS; $i++ ) {
			$recent[] = array( 'n' => $i, 'reference' => "0-$i", 'status' => 'OPENED', 'at' => $this->ago( 600 ) );
		}
		$o = $this->order( $recent );
		$this->assertSame( 'blocked', OrderPay::open( $o )['state'] );
		$this->assertCount( 30, $this->attempts( $o ), 'Ya no crece' );

		$old = array();
		for ( $i = 1; $i <= OrderPay::MAX_OPENS; $i++ ) {
			$old[] = array( 'n' => $i, 'reference' => "0-$i", 'status' => 'OPENED', 'at' => $this->ago( 7200 ) );
		}
		$this->assertSame( 'open', OrderPay::open( $this->order( $old ) )['state'], 'La ventana de 30 es de una hora' );
	}

	public function test_five_declines_in_the_last_hour_block_and_old_ones_or_unmarked_old_attempts_do_not(): void {
		$now = array();
		for ( $i = 1; $i <= OrderPay::MAX_DECLINES; $i++ ) {
			$now[] = array( 'n' => $i, 'reference' => "0-$i", 'status' => 'DECLINED', 'at' => $this->ago( 300 ), 'declined_at' => $this->ago( 300 ) );
		}
		$this->assertSame( 'blocked', OrderPay::open( $this->order( $now ) )['state'] );

		$old = array();
		for ( $i = 1; $i <= OrderPay::MAX_DECLINES; $i++ ) {
			$old[] = array( 'n' => $i, 'reference' => "0-$i", 'status' => 'DECLINED', 'at' => $this->ago( 3 * HOUR_IN_SECONDS ), 'declined_at' => $this->ago( 2 * HOUR_IN_SECONDS ) );
		}
		$this->assertSame( 'open', OrderPay::open( $this->order( $old ) )['state'] );

		$unmarked = array();
		for ( $i = 1; $i <= OrderPay::MAX_DECLINES; $i++ ) {
			$unmarked[] = array( 'n' => $i, 'reference' => "0-$i", 'status' => 'DECLINED', 'at' => $this->ago( 3 * HOUR_IN_SECONDS ) );
		}
		$this->assertSame( 'open', OrderPay::open( $this->order( $unmarked ) )['state'], 'Sin declined_at se usa la hora del intento: no queda bloqueado para siempre' );
	}

	public function test_an_origin_with_more_than_ten_declines_per_hour_is_blocked_on_every_order(): void {
		$o = $this->order();
		$o->set_customer_ip_address( '203.0.113.77' );
		$o->save();
		$limits = new AbuseLimits( new RateLimiter(), \Aralia\Privacy\Crypto::from_constants() );
		for ( $i = 0; $i <= AbuseLimits::DECLINES_BLOCK; $i++ ) {
			$limits->record_decline( '203.0.113.77' );
		}
		$this->assertSame( 'blocked', OrderPay::open( wc_get_order( $o->get_id() ) )['state'], 'Tope por origen (D-2), aunque este pedido no tenga rechazos propios' );
		$this->assertSame( 'open', OrderPay::open( $this->order() )['state'], 'Otro origen no se ve afectado' );
	}

	// ---- doble cobro -------------------------------------------------------------------------------------------------------------

	public function test_a_pending_or_approved_payment_of_this_attempt_blocks_a_new_widget(): void {
		$o = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'transaction_id' => 'TX-P', 'status' => 'OPENED', 'at' => $this->ago( 3600 ) ) ) );
		$ref = '0-1';
		foreach ( array( 'PENDING', 'APPROVED' ) as $remote ) {
			$this->mock( array( 'TX-P' => array( $remote, 3500000, 'COP', $ref ) ) );
			$this->assertSame( 'processing', OrderPay::open( $o )['state'], $remote );
		}
		$this->mock( array( 'TX-P' => 'down' ) );
		$this->assertSame( 'processing', OrderPay::open( $o )['state'], 'Sin poder verificar el pago anterior no se abre otro' );
		$this->assertCount( 1, $this->attempts( $o ) );

		$this->mock( array( 'TX-P' => array( 'DECLINED', 3500000, 'COP', $ref ) ) );
		$this->assertSame( 'open', OrderPay::open( $o )['state'], 'Rechazado: se puede reintentar' );
	}

	public function test_a_just_created_transaction_the_api_does_not_know_yet_blocks_but_an_old_unknown_one_does_not(): void {
		$recent = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'transaction_id' => 'TX-NEW', 'status' => 'OPENED', 'at' => $this->ago( 120 ) ) ) );
		$this->mock( array() ); // 404.
		$this->assertSame( 'processing', OrderPay::open( $recent )['state'], 'La API puede tardar en reflejar la transacción: no se abre otro widget' );

		$old = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'transaction_id' => 'TX-OLD', 'status' => 'OPENED', 'at' => $this->ago( 3600 ) ) ) );
		$this->assertSame( 'open', OrderPay::open( $old )['state'] );
	}

	public function test_a_seeded_or_foreign_transaction_id_neither_blocks_nor_lifts_the_guard(): void {
		// Pista sembrada por quien solo tiene la clave: la API no la conoce y el intento es viejo → se ignora.
		$o = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_id' => 'zzz', 'status' => 'OPENED', 'at' => $this->ago( 3600 ) ) ) );
		$this->mock( array() );
		$this->assertSame( 'open', OrderPay::open( $o )['state'], 'Una pista falsa no bloquea el pedido' );

		// Una transacción PENDING de OTRO pedido (otra referencia): tampoco cuenta.
		$f = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'transaction_id' => 'TX-FOREIGN', 'status' => 'OPENED', 'at' => $this->ago( 3600 ) ) ) );
		$this->mock( array( 'TX-FOREIGN' => array( 'PENDING', 3500000, 'COP', '99999-1' ) ) );
		$this->assertSame( 'open', OrderPay::open( $f )['state'], 'Otra referencia: no es de este pedido' );
		$this->mock( array( 'TX-FOREIGN' => array( 'PENDING', 100, 'COP', '0-1' ) ) );
		$this->assertSame( 'open', OrderPay::open( $f )['state'], 'Otro monto: no es de este pedido' );
	}

	// ---- pantalla nativa y redirecciones -------------------------------------------------------------------------------------------

	private function at_order_pay( \WC_Order $o, bool $pay_for_order = false, ?string $key = null ): void {
		$GLOBALS['wp']->query_vars['order-pay'] = (string) $o->get_id();
		$_GET['key']                            = $key ?? $o->get_order_key();
		unset( $_GET['pay_for_order'] );
		if ( $pay_for_order ) {
			$_GET['pay_for_order'] = 'true';
		}
	}

	public function test_native_pay_screen_and_non_pending_orders_redirect_to_the_right_place(): void {
		$o = $this->order();
		$GLOBALS['wp']->query_vars = array();
		$_GET['key']               = $o->get_order_key();
		$_GET['pay_for_order']     = 'true';
		$this->assertNull( OrderPay::native_redirect_target(), 'Fuera del endpoint order-pay no se redirige nada' );
		$this->at_order_pay( $o, true );
		$this->assertSame( OrderPay::receipt_url( $o ), OrderPay::native_redirect_target(), 'pay_for_order con clave: a esta página' );
		$this->assertStringNotContainsString( 'pay_for_order', (string) OrderPay::native_redirect_target() );

		$this->at_order_pay( $o, false );
		$this->assertNull( OrderPay::native_redirect_target(), 'Sin pay_for_order no hay redirección (sin bucle)' );

		$this->at_order_pay( $o, true, 'wc_order_wrongkey' );
		$this->assertNull( OrderPay::native_redirect_target(), 'Clave incorrecta: no revela nada ni redirige' );

		$other = $this->order();
		$other->set_payment_method( 'bacs' );
		$other->save();
		$this->at_order_pay( $other, true );
		$this->assertNull( OrderPay::native_redirect_target(), 'Pedido de otro método: no se toca' );

		$paid = $this->order();
		OrderStates::walk( $paid, 'processing' );
		$this->at_order_pay( $paid, false );
		$this->assertSame( $paid->get_checkout_order_received_url(), OrderPay::native_redirect_target(), 'Pagado: a «Gracias» (no al aviso genérico de WooCommerce)' );

		$expired = $this->order();
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $expired->get_id() );
		Clock::reset();
		remove_all_filters( 'aralia_wompi_lookup' );
		$this->at_order_pay( $expired, false );
		$this->assertSame( $expired->get_checkout_order_received_url(), OrderPay::native_redirect_target(), 'Reserva vencida: a «Gracias»' );
	}

	public function test_the_native_pay_action_is_redirected_with_303_and_other_gateways_are_left_alone(): void {
		$o        = $this->order();
		$captured = array();
		$cb       = static function ( $location, $status ) use ( &$captured ) {
			$captured = array( $location, $status );
			throw new \RuntimeException( 'redirect' );
		};
		add_filter( 'wp_redirect', $cb, 10, 2 );
		try {
			try {
				OrderPay::block_native_pay_action( $o );
				$this->fail( 'Debía redirigir' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'redirect', $e->getMessage() );
			}
			$this->assertSame( array( OrderPay::receipt_url( $o ), 303 ), $captured );

			$captured = array();
			$bacs     = $this->order();
			$bacs->set_payment_method( 'bacs' );
			$bacs->save();
			OrderPay::block_native_pay_action( $bacs ); // No lanza: no redirige.
			$this->assertSame( array(), $captured );
		} finally {
			remove_filter( 'wp_redirect', $cb, 10 );
		}
	}

	public function test_the_checkout_only_offers_wompi_while_the_order_pay_is_hooked(): void {
		$g = array( 'wompi' => new \stdClass() );
		$this->assertArrayHasKey( 'wompi', Keys::require_keys( $g ) );
		remove_action( 'woocommerce_receipt_wompi', array( OrderPay::class, 'render' ), 10 );
		$this->assertArrayNotHasKey( 'wompi', Keys::require_keys( $g ), 'Sin el order-pay enganchado, falla cerrado aunque la clase exista' );
	}

	// ---- REST -------------------------------------------------------------------------------------------------------------------------

	private function post_open( array $body ): \WP_REST_Response {
		do_action( 'rest_api_init' );
		$request = new \WP_REST_Request( 'POST', '/aralia/v1/order-pay/open' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	public function test_the_open_endpoint_returns_the_widget_config_only_with_the_order_key(): void {
		$o   = $this->order();
		$res = $this->post_open( array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key() ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'open', $res->get_data()['state'] );
		$this->assertSame( $o->get_id() . '-1', $res->get_data()['reference'] );
		$this->assertStringContainsString( 'no-store', (string) $res->get_headers()['Cache-Control'] );
		foreach ( array( ARALIA_WOMPI_INTEGRITY_TEST, ARALIA_WOMPI_PRV_TEST, ARALIA_WOMPI_EVENTS_TEST ) as $secret ) {
			$this->assertStringNotContainsString( $secret, (string) wp_json_encode( $res->get_data() ) );
		}
		$this->assertSame( 403, $this->post_open( array( 'order_id' => $o->get_id(), 'order_key' => 'wc_order_nope' ) )->get_status() );
		$this->assertSame( 403, $this->post_open( array( 'order_id' => 999999, 'order_key' => 'x' ) )->get_status(), 'Pedido inexistente: misma respuesta que clave mala' );
		$bacs = $this->order();
		$bacs->set_payment_method( 'bacs' );
		$bacs->save();
		$this->assertSame( 403, $this->post_open( array( 'order_id' => $bacs->get_id(), 'order_key' => $bacs->get_order_key() ) )->get_status() );
	}

	public function test_the_open_endpoint_is_rate_limited_per_order_only_after_a_valid_key(): void {
		$o = $this->order();
		for ( $i = 0; $i < 15; $i++ ) {
			$this->post_open( array( 'order_id' => $o->get_id(), 'order_key' => 'wc_order_bad' ) ); // Sin clave: no gasta el cupo del pedido.
		}
		$this->assertSame( 200, $this->post_open( array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key() ) )->get_status() );
		$last = null;
		for ( $i = 0; $i < 11; $i++ ) {
			$last = $this->post_open( array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key() ) );
		}
		$this->assertSame( 429, $last->get_status() );
		$this->assertSame( '60', (string) ( $last->get_headers()['Retry-After'] ?? '' ) );
	}

	public function test_expiry_reacts_to_unverified_hints_consistently_with_order_pay(): void {
		// 1. Pista falsa, API funcionando (NOT_FOUND) y intento viejo: se ignora → NONE → el vencimiento cancela a tiempo.
		$fake = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_id' => 'zzz', 'status' => 'OPENED', 'at' => $this->ago( 3600 ) ) ) );
		$this->mock( array() );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $fake->get_id() );
		$this->assertSame( 'cancelled', StateMachine::stored_status( $fake->get_id() ), 'Una pista falsa no retiene el stock' );
		Clock::reset();

		// 2. Pista y API caída: no se sabe → se aplaza, NO se cancela.
		$down = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_id' => 'TX-H', 'status' => 'OPENED', 'at' => $this->ago( 3600 ) ) ) );
		$this->mock( array( 'TX-H' => 'down' ) );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $down->get_id() );
		$this->assertSame( 'pending', StateMachine::stored_status( $down->get_id() ), 'Con la API caída una pista puede ser un pago real: se aplaza' );
		Clock::reset();

		// 3. Pista reciente que la API aún no conoce: tampoco se cancela.
		$recent = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_id' => 'TX-NEW', 'status' => 'OPENED', 'at' => $this->ago( 60 ) ) ) );
		$this->mock( array() );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $recent->get_id() );
		$this->assertSame( 'pending', StateMachine::stored_status( $recent->get_id() ) );
		Clock::reset();
	}

	public function test_expiry_reschedules_itself_when_another_process_holds_the_order_lock(): void {
		global $wpdb;
		$o = $this->order();
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		$key = 'aralia_exp_lock_' . $o->get_id();
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, time() . ':otro' ) ); // phpcs:ignore WordPress.DB
		Expiry::run( $o->get_id() );
		$this->assertSame( 'pending', StateMachine::stored_status( $o->get_id() ) );
		$next = as_next_scheduled_action( Expiry::HOOK, array( $o->get_id() ), Expiry::GROUP );
		$this->assertNotFalse( $next );
		$this->assertLessThanOrEqual( time() + 90, (int) $next, 'Se reprogramó para en ~1 minuto (la acción original estaba a +30 min)' );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( $key, 'options' );
		Clock::reset();
	}

	public function test_an_unverified_hint_never_blocks_a_new_widget_even_a_recent_one(): void {
		$hint = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_id' => 'TX-H', 'status' => 'OPENED', 'at' => $this->ago( 120 ) ) ) );
		$this->mock( array() ); // La API no la conoce todavía y el intento es reciente.
		$this->assertSame( 'open', OrderPay::open( $hint )['state'], 'Una pista sin verificar no puede impedir el pago de la clienta' );
	}

	public function test_a_real_hint_survives_a_fake_one_and_keeps_the_hold_alive(): void {
		// La pista real llegó mientras la API no la conocía; quien tiene la clave añadió una falsa: ambas quedan (la real no se pierde).
		$o = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_ids' => array( 'TX-REAL', 'fake' ), 'hint_at' => $this->ago( 700 ), 'status' => 'OPENED', 'at' => $this->ago( 2400 ) ) ) );
		$this->mock( array( 'TX-REAL' => array( 'PENDING', 3500000, 'COP', '0-1' ) ) ); // La API ya conoce la real (PENDING) y no 'fake'.
		$this->assertSame( 'PENDING', \Aralia\Guard\Lookup::for_order( null, wc_get_order( $o->get_id() ) ), 'La pista real cuenta aunque haya otra falsa' );

		// Una pista nueva que la API no conoce da «no se sabe» mientras es reciente (se mide desde que LLEGÓ la pista, no desde la apertura).
		$late = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_ids' => array( 'TX-LATE' ), 'hint_at' => $this->ago( 60 ), 'status' => 'OPENED', 'at' => $this->ago( 3000 ) ) ) );
		$this->mock( array() );
		$this->assertNull( \Aralia\Guard\Lookup::for_order( null, wc_get_order( $late->get_id() ) ) );
	}

	public function test_a_verified_id_the_api_does_not_reflect_yet_blocks_but_is_rechecked_inside_the_lock(): void {
		$o = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'transaction_id' => 'TX-V', 'status' => 'OPENED', 'at' => $this->ago( 60 ) ) ) );
		$this->mock( array() ); // La API aún no la refleja: bloquea (verificado y reciente).
		$this->assertSame( 'processing', OrderPay::open( $o )['state'] );
		$this->mock( array( 'TX-V' => array( 'PENDING', 3500000, 'COP', '0-1' ) ) ); // Y cuando la API ya la refleja como PENDING, sigue bloqueando.
		$this->assertSame( 'processing', OrderPay::open( $o )['state'] );
	}

	public function test_an_attempt_with_a_hint_is_not_reused_even_within_a_minute(): void {
		$o   = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_ids' => array( 'TX-H' ), 'status' => 'OPENED', 'at' => $this->ago( 10 ) ) ) );
		$this->mock( array() );
		$cfg = OrderPay::open( $o );
		$this->assertSame( 'open', $cfg['state'] );
		$this->assertStringEndsWith( '-2', $cfg['reference'], 'Con una pista asociada a la referencia, esa referencia no se reutiliza' );
	}

	public function test_old_fake_hints_are_pruned_and_never_block_while_recent_or_verified_ones_beyond_the_cap_do(): void {
		$rows = array();
		foreach ( array( 1, 2 ) as $n ) {
			$rows[] = array( 'n' => $n, 'reference' => '0-' . $n, 'hint_transaction_ids' => array( 'H' . $n . 'a', 'H' . $n . 'b', 'H' . $n . 'c' ), 'hint_at' => $this->ago( 7200 ), 'status' => 'OPENED', 'at' => $this->ago( 7200 ) );
		}
		$old = $this->order( $rows ); // 6 pistas viejas que la API no conoce: no bloquean (y se podan).
		$this->mock( array() );
		$this->assertSame( 'open', OrderPay::open( $old )['state'], 'Quien tiene la clave no puede dejar «en proceso» el pago con pistas falsas' );
		$left = 0;
		foreach ( $this->attempts( $old ) as $row ) {
			$left += count( \Aralia\Orders\Attempts::hints( $row ) );
		}
		$this->assertLessThan( 6, $left, 'Las pistas descartadas por la API se podaron' );

		$recent = array();
		foreach ( array( 1, 2 ) as $n ) {
			$recent[] = array( 'n' => $n, 'reference' => '0-' . $n, 'hint_transaction_ids' => array( 'R' . $n . 'a', 'R' . $n . 'b', 'R' . $n . 'c' ), 'hint_at' => $this->ago( 30 ), 'status' => 'OPENED', 'at' => $this->ago( 30 ) );
		}
		$new = $this->order( $recent ); // 6 pistas RECIENTES > tope de 5: no se puede descartar un pago en curso.
		$this->mock( array() );
		$this->assertSame( 'processing', OrderPay::open( $new )['state'] );
	}

	public function test_a_foreign_hint_is_pruned_and_a_verified_id_hidden_by_a_newer_hint_is_still_checked(): void {
		$foreign = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_ids' => array( 'TX-OTHER' ), 'hint_at' => $this->ago( 60 ), 'status' => 'OPENED', 'at' => $this->ago( 3000 ) ) ) );
		$this->mock( array( 'TX-OTHER' => array( 'PENDING', 100, 'COP', '999-1' ) ) ); // De otro pedido.
		$this->assertSame( 'open', OrderPay::open( $foreign )['state'] );
		$this->assertSame( array(), \Aralia\Orders\Attempts::hints( $this->attempts( $foreign )[0] ), 'Una pista de otro pedido se poda' );

		$hidden = $this->order(
			array(
				array( 'n' => 1, 'reference' => '0-1', 'transaction_id' => 'TX-X', 'status' => 'PENDING', 'at' => $this->ago( 3000 ) ),
				array( 'n' => 2, 'reference' => '0-2', 'hint_transaction_ids' => array( 'TX-X' ), 'status' => 'OPENED', 'at' => $this->ago( 100 ) ),
			)
		);
		$c = \Aralia\Orders\Attempts::candidates( $this->attempts( $hidden ) );
		$this->assertCount( 1, $c );
		$this->assertTrue( $c[0]['verified'], 'El id verificado no queda escondido por una pista repetida de un intento más nuevo' );
		$this->mock( array( 'TX-X' => array( 'PENDING', 3500000, 'COP', '0-1' ) ) );
		$this->assertSame( 'processing', OrderPay::open( $hidden )['state'] );
	}

	public function test_an_approved_hint_alone_never_returns_approved_nor_postpones_forever(): void {
		$o = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_ids' => array( 'TX-FOREIGN-MERCHANT' ), 'hint_at' => $this->ago( 60 ), 'status' => 'OPENED', 'at' => $this->ago( 60 ) ) ) );
		$this->mock( array( 'TX-FOREIGN-MERCHANT' => array( 'APPROVED', 3500000, 'COP', '0-1' ) ) );
		$this->assertSame( 'PENDING', \Aralia\Guard\Lookup::for_order( null, wc_get_order( $o->get_id() ) ), 'Solo el evento firmado fija un id verificado; una pista aprobada cuenta como «en proceso» (con tope de 2 h)' );
		$v = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'transaction_id' => 'TX-SIGNED', 'status' => 'PENDING', 'at' => $this->ago( 60 ) ) ) );
		$this->mock( array( 'TX-SIGNED' => array( 'APPROVED', 3500000, 'COP', '0-1' ) ) );
		$this->assertSame( 'APPROVED', \Aralia\Guard\Lookup::for_order( null, wc_get_order( $v->get_id() ) ) );
	}

	public function test_an_unreachable_api_while_checking_a_previous_payment_does_not_open_another_widget(): void {
		$o = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_ids' => array( 'TX-H' ), 'status' => 'OPENED', 'at' => $this->ago( 3600 ) ) ) );
		$this->mock( array( 'TX-H' => 'down' ) );
		$this->assertSame( 'processing', OrderPay::open( $o )['state'] );
	}

	public function test_a_busy_order_lock_answers_unavailable(): void {
		global $wpdb;
		$o   = $this->order();
		$key = 'aralia_exp_lock_' . $o->get_id();
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, time() . ':otro' ) ); // phpcs:ignore WordPress.DB
		$this->mock( array() );
		$this->assertSame( 'unavailable', OrderPay::open( $o )['state'] );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( $key, 'options' );
	}

	public function test_lookup_caps_its_queries_and_stops_at_the_first_unreachable_answer(): void {
		$old  = $this->ago( 7200 );
		$rows = array();
		foreach ( array( 1, 2, 3 ) as $n ) {
			$rows[] = array( 'n' => $n, 'reference' => '0-' . $n, 'hint_transaction_ids' => array( 'A' . $n, 'B' . $n, 'C' . $n ), 'hint_at' => $old, 'status' => 'OPENED', 'at' => $old );
		}
		$many = $this->order( $rows ); // 9 pistas viejas > 8: las que quedan sin consultar son viejas, no retienen el pedido.
		$this->mock( array() );
		$this->assertSame( 'NONE', \Aralia\Guard\Lookup::for_order( null, wc_get_order( $many->get_id() ) ) );
		$rows[2]['hint_at'] = $this->ago( 30 );
		$rows[2]['at']      = $this->ago( 30 ); // Si entre las no consultadas hay una RECIENTE, no se sabe.
		$recent             = $this->order( $rows );
		$this->mock( array() );
		$this->assertNull( \Aralia\Guard\Lookup::for_order( null, wc_get_order( $recent->get_id() ) ) );

		$down = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_ids' => array( 'D1', 'D2' ), 'hint_at' => $old, 'status' => 'OPENED', 'at' => $old ) ) );
		$this->mock( array( 'D1' => 'down', 'D2' => 'down' ) );
		$this->assertNull( \Aralia\Guard\Lookup::for_order( null, wc_get_order( $down->get_id() ) ) );
	}

	public function test_verified_ids_are_checked_before_hints_when_the_cap_is_hit(): void {
		$old  = $this->ago( 7200 );
		$rows = array( array( 'n' => 1, 'reference' => '0-1', 'transaction_id' => 'TX-REAL', 'status' => 'PENDING', 'at' => $old ) );
		foreach ( array( 2, 3, 4 ) as $n ) {
			$rows[] = array( 'n' => $n, 'reference' => '0-' . $n, 'hint_transaction_ids' => array( 'X' . $n . 'a', 'X' . $n . 'b', 'X' . $n . 'c' ), 'hint_at' => $old, 'status' => 'OPENED', 'at' => $old );
		}
		$o = $this->order( $rows ); // El verificado es el más VIEJO y hay 9 pistas más nuevas: aun así se consulta primero.
		$this->mock( array( 'TX-REAL' => array( 'APPROVED', 3500000, 'COP', '0-1' ) ) );
		$this->assertSame( 'APPROVED', \Aralia\Guard\Lookup::for_order( null, wc_get_order( $o->get_id() ) ) );
	}

	public function test_expiry_stops_rescheduling_after_the_cap_when_the_lock_stays_busy(): void {
		global $wpdb;
		$o = $this->order();
		as_unschedule_all_actions( Expiry::HOOK, array( $o->get_id() ), Expiry::GROUP );
		$key = 'aralia_exp_lock_' . $o->get_id();
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, time() . ':otro' ) ); // phpcs:ignore WordPress.DB
		Clock::advance( 3 * HOUR_IN_SECONDS );
		Expiry::run( $o->get_id() );
		$this->assertFalse( as_next_scheduled_action( Expiry::HOOK, array( $o->get_id() ), Expiry::GROUP ), 'Pasado el tope de 2 h no se reprograma sin fin (lo recoge el vigilante)' );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( $key, 'options' );
		Clock::reset();
	}

	public function test_cancelling_at_the_cap_with_an_approved_hint_and_no_signed_event_raises_an_alert(): void {
		$o = $this->order( array( array( 'n' => 1, 'reference' => '0-1', 'hint_transaction_ids' => array( 'TX-HINT' ), 'hint_at' => $this->ago( 60 ), 'status' => 'OPENED', 'at' => $this->ago( 60 ) ) ) );
		$this->mock( array( 'TX-HINT' => array( 'APPROVED', 3500000, 'COP', '0-1' ) ) );
		$alerts = array();
		add_action(
			'aralia_alert',
			static function ( $event, $ref ) use ( &$alerts ): void {
				$alerts[] = array( $event, $ref );
			},
			10,
			2
		);
		Clock::advance( 3 * HOUR_IN_SECONDS );
		Expiry::run( $o->get_id() );
		Clock::reset();
		$this->assertSame( 'cancelled', StateMachine::stored_status( $o->get_id() ), 'Sin evento firmado, el tope de 2 h cancela' );
		$this->assertContains( array( 'pending_unconfirmed_at_cap', 'pedido ' . $o->get_id() ), $alerts, 'Y Sara se entera para revisar el pago en Wompi' );
	}
}
