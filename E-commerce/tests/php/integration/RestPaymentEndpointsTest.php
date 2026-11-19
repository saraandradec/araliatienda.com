<?php
/**
 * POST /aralia/v1/payment-attempt y /order-status (T062, contracts/rest-api.md §8 y §8b).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Orders\Expiry;
use Aralia\Orders\StockOnCreate;
use Aralia\Rest\OrderAccess;
use Aralia\Tests\Support\IsolatedTestCase;
use Aralia\Tests\Support\OrderStates;

final class RestPaymentEndpointsTest extends IsolatedTestCase {

	/**
	 * @var int[]
	 */
	private array $orders = array();

	/**
	 * @var int[]
	 */
	private array $products = array();

	/**
	 * Sesión de WooCommerce original (se restaura: un doble global rompería otras pruebas).
	 *
	 * @var mixed
	 */
	private $session_before;

	protected function set_up() {
		parent::set_up();
		$this->session_before = WC()->session;
		$this->reset_limits();
		do_action( 'rest_api_init' );
	}

	protected function tear_down() {
		foreach ( $this->orders as $id ) {
			delete_option( 'aralia_exp_lock_' . $id );
			as_unschedule_all_actions( Expiry::HOOK, array( $id ), Expiry::GROUP );
			as_unschedule_all_actions( 'aralia_reconcile_order', array( $id ), 'aralia' );
			$o = wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
		foreach ( $this->products as $id ) {
			wp_delete_post( $id, true );
		}
		$this->reset_limits();
		WC()->session   = $this->session_before;
		$cookie         = (string) apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH );
		unset( $_COOKIE[ $cookie ] );
		$this->orders   = array();
		$this->products = array();
		parent::tear_down();
	}

	private function reset_limits(): void {
		global $wpdb;
		$t = $wpdb->prefix . 'aralia_rate_limits';
		$wpdb->query( "DELETE FROM {$t} WHERE bucket LIKE 'rest_%'" ); // phpcs:ignore WordPress.DB
	}

	private function order( string $session = 'guest-123' ): \WC_Order {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Pieza ' . wp_rand() );
		$p->set_sku( 'RT-' . strtoupper( substr( uniqid( '', true ), -8 ) ) . wp_rand( 10, 99 ) );
		$p->set_regular_price( '35000' );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 5 );
		$p->save();
		$this->products[] = $p->get_id();
		$order            = wc_create_order();
		$order->add_product( $p, 1 );
		$order->set_payment_method( 'wompi' );
		$order->calculate_totals();
		$order->update_meta_data( '_ara_attempts', array( array( 'n' => 1, 'reference' => $order->get_id() . '-1', 'status' => 'OPENED' ) ) );
		$order->update_meta_data( '_ara_session_hash', OrderAccess::session_hash( $session ) );
		$order->save();
		$this->orders[] = $order->get_id();
		StockOnCreate::handle( $order );
		return wc_get_order( $order->get_id() );
	}

	private function post( string $route, array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/aralia/v1' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	/**
	 * Cookie de sesión de WooCommerce en el formato ACTUAL (`id|vencimiento|vence-pronto|hash`), con el hash calculado por el manejador
	 * real de WooCommerce (no con la fórmula de nuestro código), y comprobada con su propio lector.
	 */
	private function with_session( string $customer_id, string $separator = '|' ): void {
		$handler = new \WC_Session_Handler();
		$exp     = time() + DAY_IN_SECONDS;
		$hash    = ( new \ReflectionMethod( $handler, 'hash' ) )->invoke( $handler, $customer_id . '|' . $exp );
		$name    = (string) apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH );
		$cookie  = implode( $separator, array( $customer_id, (string) $exp, (string) ( $exp - HOUR_IN_SECONDS ), $hash ) );
		$_COOKIE[ $name ] = $cookie;
		// El lector de WooCommerce acepta esa misma cookie (así la prueba no es circular).
		$parsed = $handler->get_session_cookie();
		$this->assertIsArray( $parsed, 'La cookie de la prueba debe ser válida para el manejador real de WooCommerce' );
		$this->assertSame( $customer_id, $parsed[0] );
	}

	/**
	 * Sesión de WooCommerce de la petición de checkout (la que lee StockOnCreate al crear el pedido).
	 */
	private function with_wc_session_object( string $customer_id ): void {
		WC()->session = new class( $customer_id ) {
			private string $id;
			public function __construct( string $id ) {
				$this->id = $id;
			}
			public function get_customer_id() {
				return $this->id;
			}
		};
	}

	// ---- payment-attempt -------------------------------------------------------------------------------------------------------

	public function test_payment_attempt_stores_the_transaction_id_asks_for_a_check_and_never_changes_state(): void {
		$this->verdict( 'valid' ); // El guard verificó la pista contra la API.
		$o    = $this->order();
		$fired = array();
		add_action(
			'aralia_payment_attempt_hint',
			function ( $id, $ref, $tx ) use ( &$fired ) {
				$fired[] = array( $id, $ref, $tx );
			},
			10,
			3
		);
		$res = $this->post( '/payment-attempt', array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $o->get_id() . '-1', 'transaction_id' => '11111-1700000000-12345' ) );

		$this->assertSame( 202, $res->get_status() );
		$this->assertSame( array( 'accepted' => true ), $res->get_data() );
		$fresh = wc_get_order( $o->get_id() );
		$row = $fresh->get_meta( '_ara_attempts', true )[0];
		$this->assertSame( array( '11111-1700000000-12345' ), \Aralia\Orders\Attempts::hints( $row ) );
		$this->assertArrayNotHasKey( 'transaction_id', $row, 'Ni siquiera una pista que la API reconoce se promueve a id verificado (solo el evento firmado lo fija)' );
		$this->assertSame( 'pending', \Aralia\Orders\StateMachine::stored_status( $o->get_id() ), 'Una pista nunca cambia el estado' );
		$this->assertSame( array( array( $o->get_id(), $o->get_id() . '-1', '11111-1700000000-12345' ) ), $fired );
		$this->assertNotFalse( as_has_scheduled_action( 'aralia_reconcile_order', array( $o->get_id() ), 'aralia' ), 'Consulta inmediata a Wompi encolada' );
		$this->assertStringContainsString( 'no-store', (string) $res->get_headers()['Cache-Control'] );
	}

	public function test_payment_attempt_keeps_every_hint_and_never_sets_the_verified_id(): void {
		$this->verdict( 'valid' );
		$o = $this->order();
		$this->post( '/payment-attempt', array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $o->get_id() . '-1', 'transaction_id' => 'TX-FIRST' ) );
		$this->post( '/payment-attempt', array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $o->get_id() . '-1', 'transaction_id' => 'TX-OTHER' ) );
		$row = wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0];
		$this->assertArrayNotHasKey( 'transaction_id', $row );
		$this->assertSame( array( 'TX-FIRST', 'TX-OTHER' ), \Aralia\Orders\Attempts::hints( $row ), 'Las pistas se acumulan sin sustituirse' );
	}

	/**
	 * @dataProvider bad_attempts
	 */
	public function test_payment_attempt_rejects_bad_keys_references_and_ids( string $case, int $expected ): void {
		$o    = $this->order();
		$other = $this->order();
		$body = array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $o->get_id() . '-1', 'transaction_id' => 'TX-1' );
		switch ( $case ) {
			case 'wrong_key':
				$body['order_key'] = 'wc_order_' . str_repeat( 'x', 13 );
				break;
			case 'foreign_order_key':
				$body['order_key'] = $other->get_order_key();
				break;
			case 'unknown_order':
				$body['order_id'] = 999999;
				break;
			case 'reference_of_another_order':
				$body['reference'] = $other->get_id() . '-1';
				break;
			case 'reference_not_an_attempt':
				$body['reference'] = $o->get_id() . '-9';
				break;
			case 'malformed_reference':
				$body['reference'] = $o->get_id() . '-1; DROP';
				break;
			case 'malformed_tx':
				$body['transaction_id'] = "../../etc/passwd'";
				break;
			case 'dots_only_tx':
				$body['transaction_id'] = '...';
				break;
			case 'empty_key':
				$body['order_key'] = '';
				break;
		}
		$res = $this->post( '/payment-attempt', $body );
		$this->assertSame( $expected, $res->get_status(), $case );
		$this->assertArrayNotHasKey( 'transaction_id', wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0], 'Nada se guarda' );
		if ( $expected >= 400 ) {
			$data = $res->get_data();
			$this->assertArrayHasKey( 'code', $data );
			$this->assertArrayHasKey( 'message', $data );
		}
	}

	/**
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function bad_attempts(): array {
		return array(
			'clave equivocada'             => array( 'wrong_key', 403 ),
			'clave de otro pedido'         => array( 'foreign_order_key', 403 ),
			'pedido inexistente'           => array( 'unknown_order', 403 ),
			'clave vacía'                  => array( 'empty_key', 403 ),
			'referencia de otro pedido'    => array( 'reference_of_another_order', 400 ),
			'referencia que no es intento' => array( 'reference_not_an_attempt', 400 ),
			'referencia mal formada'       => array( 'malformed_reference', 400 ),
			'id de transacción malo'       => array( 'malformed_tx', 400 ),
			'id solo puntos'               => array( 'dots_only_tx', 400 ),
		);
	}

	public function test_payment_attempt_is_rate_limited_per_order_only_after_a_valid_key(): void {
		$o = $this->order();
		for ( $i = 0; $i < 15; $i++ ) {
			$this->post( '/payment-attempt', array( 'order_id' => $o->get_id(), 'order_key' => 'wc_order_bad', 'reference' => $o->get_id() . '-1', 'transaction_id' => 'TX-1' ) );
		}
		$ok = $this->post( '/payment-attempt', array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $o->get_id() . '-1', 'transaction_id' => 'TX-1' ) );
		$this->assertSame( 202, $ok->get_status(), 'Quien solo conoce el id no puede agotar el cupo de la clienta' );
		$last = null;
		for ( $i = 0; $i <= \Aralia\Rest\PaymentAttempt::MAX_PER_MIN; $i++ ) {
			$last = $this->post( '/payment-attempt', array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $o->get_id() . '-1', 'transaction_id' => 'TX-1' ) );
		}
		$this->assertSame( 429, $last->get_status() );
		$this->assertSame( '60', (string) ( $last->get_headers()['Retry-After'] ?? '' ) );
		$this->assertSame( 'aralia_rate_limited', $last->get_data()['code'] );
		$other = $this->order();
		$this->assertSame( 202, $this->post( '/payment-attempt', array( 'order_id' => $other->get_id(), 'order_key' => $other->get_order_key(), 'reference' => $other->get_id() . '-1', 'transaction_id' => 'TX-2' ) )->get_status(), 'El límite es por pedido' );
	}

	// ---- pistas: verificadas o sin verificar ----------------------------------------------------------------------------------------

	private function verdict( string $v ): void {
		remove_all_filters( 'aralia_verify_payment_hint' );
		add_filter( 'aralia_verify_payment_hint', static fn() => $v );
	}

	public function test_a_hint_never_becomes_the_verified_id_and_never_displaces_it(): void {
		$o   = $this->order();
		$ref = $o->get_id() . '-1';
		$body = static fn( string $tx ) => array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $ref, 'transaction_id' => $tx );
		$attempt = fn() => wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0];

		// 1. Quien tiene la clave siembra un id que la API aún no conoce: queda como PISTA, no como id del intento.
		$this->verdict( 'unknown' );
		$this->assertSame( 202, $this->post( '/payment-attempt', $body( 'zzz' ) )->get_status() );
		$this->assertSame( array( 'zzz' ), \Aralia\Orders\Attempts::hints( $attempt() ) );
		$this->assertArrayNotHasKey( 'transaction_id', $attempt(), 'Una pista sin verificar nunca ocupa el lugar del id real' );

		// 2. Una pista que la API reconoce (`valid`) tampoco se promueve: sigue siendo pista.
		$this->verdict( 'valid' );
		$this->post( '/payment-attempt', $body( 'TX-REAL' ) );
		$this->assertArrayNotHasKey( 'transaction_id', $attempt() );
		$this->assertSame( array( 'zzz', 'TX-REAL' ), \Aralia\Orders\Attempts::hints( $attempt() ) );

		// 3. El id verificado lo fija el evento firmado (aquí, simulado); desde entonces ninguna pista lo reescribe ni se acumula.
		$f    = \Aralia\Orders\OrderStore::fresh( $o->get_id() );
		$rows = $f->get_meta( '_ara_attempts', true );
		$rows[0] = \Aralia\Orders\Attempts::clear_hints( $rows[0] );
		$rows[0]['transaction_id'] = 'TX-SIGNED';
		$f->update_meta_data( '_ara_attempts', $rows );
		$f->save();
		$this->verdict( 'unknown' );
		$this->post( '/payment-attempt', $body( 'later-hint' ) );
		$this->verdict( 'valid' );
		$this->post( '/payment-attempt', $body( 'TX-OTHER' ) );
		$this->assertSame( 'TX-SIGNED', $attempt()['transaction_id'] );
		$this->assertSame( array(), \Aralia\Orders\Attempts::hints( $attempt() ) );
	}

	public function test_an_unverified_hint_never_replaces_another_real_one_and_an_invalid_one_is_refused(): void {
		$o   = $this->order();
		$ref = $o->get_id() . '-1';
		$body = static fn( string $tx ) => array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $ref, 'transaction_id' => $tx );
		$this->verdict( 'unknown' );
		$this->post( '/payment-attempt', $body( 'first-hint' ) );
		$this->post( '/payment-attempt', $body( 'second-hint' ) );
		$this->assertSame( array( 'first-hint', 'second-hint' ), \Aralia\Orders\Attempts::hints( wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0] ), 'Una pista nueva no desplaza a la anterior (podría ser la real)' );
		foreach ( array( 'h3', 'h4' ) as $more ) {
			$this->post( '/payment-attempt', $body( $more ) );
		}
		$this->assertSame( array( 'first-hint', 'second-hint', 'h3' ), \Aralia\Orders\Attempts::hints( wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0] ), 'Máximo 3: con 3 llenas la nueva se descarta, nunca sale una anterior (podría ser la real)' );

		$this->verdict( 'invalid' ); // Existe en Wompi pero es de otro pedido o monto.
		$res = $this->post( '/payment-attempt', $body( 'TX-ELSEWHERE' ) );
		$this->assertSame( 202, $res->get_status(), 'Respuesta uniforme: no sirve para sondear si un id existe' );
		$this->assertNotContains( 'TX-ELSEWHERE', \Aralia\Orders\Attempts::hints( wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0] ), 'Lo inválido no se guarda' );
	}

	public function test_a_stale_copy_cannot_erase_what_the_guard_wrote_while_the_hint_was_processed(): void {
		$o = $this->order();
		$this->verdict( 'unknown' );
		add_filter(
			'aralia_verify_payment_hint',
			function ( $v ) use ( $o ) {
				// Mientras se verifica la pista, el guard anota otro intento con su transacción real.
				$f = \Aralia\Orders\OrderStore::fresh( $o->get_id() );
				$f->update_meta_data( '_ara_attempts', array( array( 'n' => 1, 'reference' => $o->get_id() . '-1', 'status' => 'OPENED' ), array( 'n' => 2, 'reference' => $o->get_id() . '-2', 'transaction_id' => 'TX-GUARD', 'status' => 'PENDING' ) ) );
				$f->save();
				return $v;
			},
			20
		);
		$this->post( '/payment-attempt', array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $o->get_id() . '-1', 'transaction_id' => 'hint-1' ) );
		$attempts = wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true );
		$this->assertSame( 'TX-GUARD', $attempts[1]['transaction_id'], 'La escritura del guard sobrevive (se parte de la base de datos, no de la copia vieja)' );
		$this->assertSame( array( 'hint-1' ), \Aralia\Orders\Attempts::hints( $attempts[0] ) );
	}

	public function test_the_cors_guard_is_registered_and_does_not_alter_the_served_flag_and_the_reconcile_job_does_not_fail(): void {
		$req = new \WP_REST_Request( 'POST', '/aralia/v1/order-status' );
		$this->assertTrue( \Aralia\Rest\OrderAccess::strip_cors( true, null, $req ), 'No altera si se sirvió o no' );
		$this->assertTrue( \Aralia\Rest\OrderAccess::strip_cors( true, null, new \WP_REST_Request( 'GET', '/wp/v2/posts' ) ) );
		$this->assertNotFalse( has_filter( 'rest_pre_serve_request', array( \Aralia\Rest\OrderAccess::class, 'strip_cors' ) ) );
		$this->assertNotFalse( has_action( 'aralia_reconcile_order' ), 'El trabajo encolado por payment-attempt tiene manejador (vacío hasta T063)' );
		do_action( 'aralia_reconcile_order', 123 );
	}

	public function test_payment_attempt_requires_a_wompi_order(): void {
		$o = $this->order();
		$o->set_payment_method( 'bacs' );
		$o->save();
		$this->assertSame( 403, $this->post( '/payment-attempt', array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $o->get_id() . '-1', 'transaction_id' => 'TX-1' ) )->get_status() );
	}

	// ---- order-status ----------------------------------------------------------------------------------------------------------

	public function test_order_status_maps_every_state(): void {
		$this->with_session( 'guest-123' );
		$cases = array();

		$pending = $this->order();
		$cases[] = array( $pending, 'pending' );

		$declined = $this->order();
		$declined->update_meta_data( '_ara_attempts', array( array( 'n' => 1, 'reference' => $declined->get_id() . '-1', 'status' => 'DECLINED' ) ) );
		$declined->save();
		$cases[] = array( $declined, 'declined_retry' );

		$inflight = $this->order();
		$inflight->update_meta_data( '_ara_attempts', array( array( 'n' => 1, 'reference' => $inflight->get_id() . '-1', 'status' => 'DECLINED' ), array( 'n' => 2, 'reference' => $inflight->get_id() . '-2', 'status' => 'PENDING' ) ) );
		$inflight->save();
		$cases[] = array( $inflight, 'pending' );

		$paid = $this->order();
		OrderStates::walk( $paid, 'processing' );
		$cases[] = array( $paid, 'paid' );

		$expired = $this->order();
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
		\Aralia\Support\Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $expired->get_id() );
		\Aralia\Support\Clock::reset();
		remove_all_filters( 'aralia_wompi_lookup' );
		$cases[] = array( $expired, 'expired' );

		$nostock = $this->order();
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
		\Aralia\Support\Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $nostock->get_id() );
		\Aralia\Support\Clock::reset();
		remove_all_filters( 'aralia_wompi_lookup' );
		$item = array_values( wc_get_order( $nostock->get_id() )->get_items() )[0];
		wc_update_product_stock( wc_get_product( $item->get_product_id() ), 0, 'set' );
		$this->assertSame( 'ara-nostock', \Aralia\Orders\PartialStock::settle_late( wc_get_order( $nostock->get_id() ) ) );
		$cases[] = array( $nostock, 'nostock' );

		$refunded = $this->order();
		OrderStates::walk( $refunded, 'processing' );
		\Aralia\Orders\StateMachine::run_as( \Aralia\Orders\StateMachine::STAFF, static fn() => wc_get_order( $refunded->get_id() )->update_status( 'refunded' ) );
		$this->assertSame( 'refunded', \Aralia\Orders\StateMachine::stored_status( $refunded->get_id() ) );
		$cases[] = array( $refunded, 'paid' );

		foreach ( $cases as [ $order, $expected ] ) {
			$res = $this->post( '/order-status', array( 'order' => $order->get_id(), 'key' => $order->get_order_key() ) );
			$this->assertSame( 200, $res->get_status() );
			$this->assertSame( array( 'status' => $expected ), $res->get_data(), 'Pedido ' . $order->get_id() );
			$this->assertStringContainsString( 'no-store', (string) $res->get_headers()['Cache-Control'] );
		}
	}

	public function test_order_status_never_changes_the_order(): void {
		$this->with_session( 'guest-123' );
		$o   = $this->order();
		$snapshot = fn() => wp_json_encode( array( \Aralia\Orders\StateMachine::stored_status( $o->get_id() ), wc_get_order( $o->get_id() )->get_meta_data() ? array_map( static fn( $m ) => array( $m->key, $m->value ), wc_get_order( $o->get_id() )->get_meta_data() ) : array() ) );
		$before   = $snapshot();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->post( '/order-status', array( 'order' => $o->get_id(), 'key' => $o->get_order_key() ) );
		}
		$this->assertSame( 'pending', \Aralia\Orders\StateMachine::stored_status( $o->get_id() ) );
		$this->assertSame( $before, $snapshot(), 'Estado y metadatos idénticos: order-status solo lee' );
	}

	public function test_order_status_needs_the_key_and_the_session_that_created_the_order(): void {
		$this->with_session( 'guest-123' ); // El pedido se crea desde la sesión de la clienta (StockOnCreate guarda su huella).
		$o     = $this->order( 'guest-123' );
		$other = $this->order( 'guest-123' );
		$this->assertSame( 200, $this->post( '/order-status', array( 'order' => $o->get_id(), 'key' => $o->get_order_key() ) )->get_status(), 'Misma sesión y clave' );
		$this->assertSame( 403, $this->post( '/order-status', array( 'order' => $o->get_id(), 'key' => $other->get_order_key() ) )->get_status(), 'Clave de otro pedido' );
		$this->assertSame( 403, $this->post( '/order-status', array( 'order' => $o->get_id(), 'key' => 'x' ) )->get_status() );
		$this->assertSame( 403, $this->post( '/order-status', array( 'order' => 999999, 'key' => 'x' ) )->get_status(), 'Pedido inexistente: misma respuesta que clave mala' );

		$this->with_session( 'another-guest' );
		$res = $this->post( '/order-status', array( 'order' => $o->get_id(), 'key' => $o->get_order_key() ) );
		$this->assertSame( 403, $res->get_status(), 'Con la clave pero desde OTRA sesión' );
		$this->assertSame( OrderAccess::MESSAGE_DENIED, $res->get_data()['message'] );

		// Pedido sin huella de sesión (creado a mano): falla cerrado.
		$this->with_session( 'guest-123' );
		$bare = $this->order();
		$bare->delete_meta_data( '_ara_session_hash' );
		$bare->save();
		$this->assertSame( 403, $this->post( '/order-status', array( 'order' => $bare->get_id(), 'key' => $bare->get_order_key() ) )->get_status() );
	}

	public function test_order_status_is_rate_limited_per_ip(): void {
		$this->with_session( 'guest-123' );
		$o = $this->order();
		$last = null;
		for ( $i = 0; $i <= \Aralia\Rest\OrderStatus::MAX_PER_MIN; $i++ ) {
			$last = $this->post( '/order-status', array( 'order' => $o->get_id(), 'key' => $o->get_order_key() ) );
		}
		$this->assertSame( 429, $last->get_status() );
		$this->assertArrayHasKey( 'Retry-After', $last->get_headers() );
	}

	public function test_the_checkout_stores_the_session_fingerprint_not_the_session_id(): void {
		$this->with_wc_session_object( 'guest-777' );
		$o = $this->order( 'ignored' );
		// StockOnCreate la escribe al crear el pedido desde el checkout (aquí el helper ya la puso; se comprueba el formato).
		$o->delete_meta_data( '_ara_session_hash' );
		$o->delete_meta_data( '_ara_created_handled' );
		$o->save();
		StockOnCreate::handle( wc_get_order( $o->get_id() ) );
		$hash = (string) wc_get_order( $o->get_id() )->get_meta( '_ara_session_hash', true );
		$this->assertSame( OrderAccess::session_hash( 'guest-777' ), $hash );
		$this->assertStringNotContainsString( 'guest-777', $hash );
		$this->assertSame( 64, strlen( $hash ) );
	}

	public function test_order_status_works_for_a_logged_in_customer_without_destroying_their_session(): void {
		$uid = (int) wp_insert_user( array( 'user_login' => 'cl' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'cl' . wp_rand() . '@example.com', 'role' => 'customer' ) );
		$this->with_session( (string) $uid ); // La cookie de una usuaria registrada: su id de cliente es su id de usuario.
		$o = $this->order( (string) $uid );
		wp_set_current_user( 0 ); // En REST sin X-WP-Nonce el usuario es 0.
		$res = $this->post( '/order-status', array( 'order' => $o->get_id(), 'key' => $o->get_order_key() ) );
		$this->assertSame( 200, $res->get_status(), 'La sesión se lee de la cookie firmada, sin cargar el manejador de WooCommerce' );
		$this->assertSame( $this->session_before, WC()->session, 'No se cargó ni destruyó otra sesión' );
		wp_delete_user( $uid );
	}

	public function test_a_forged_or_expired_session_cookie_is_not_accepted(): void {
		$o = $this->order( 'guest-123' );
		$name = (string) apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH );
		$exp  = time() + DAY_IN_SECONDS;
		$_COOKIE[ $name ] = implode( '|', array( 'guest-123', (string) $exp, (string) ( $exp - 60 ), str_repeat( 'a', 32 ) ) ); // Firma falsa con el id correcto.
		$this->assertSame( 403, $this->post( '/order-status', array( 'order' => $o->get_id(), 'key' => $o->get_order_key() ) )->get_status() );
		$exp = time() - 10; // Vencida (aunque bien firmada).
		$handler = new \WC_Session_Handler();
		$_COOKIE[ $name ] = implode( '|', array( 'guest-123', (string) $exp, (string) ( $exp - 60 ), ( new \ReflectionMethod( $handler, 'hash' ) )->invoke( $handler, 'guest-123|' . $exp ) ) );
		$this->assertSame( 403, $this->post( '/order-status', array( 'order' => $o->get_id(), 'key' => $o->get_order_key() ) )->get_status() );
		$_COOKIE[ $name ] = 'basura';
		$this->assertSame( 403, $this->post( '/order-status', array( 'order' => $o->get_id(), 'key' => $o->get_order_key() ) )->get_status() );
		unset( $_COOKIE[ $name ] );
		$this->assertSame( 403, $this->post( '/order-status', array( 'order' => $o->get_id(), 'key' => $o->get_order_key() ) )->get_status(), 'Sin cookie' );
	}

	public function test_the_legacy_double_pipe_cookie_format_is_still_read(): void {
		$o = $this->order( 'guest-123' );
		$handler = new \WC_Session_Handler();
		$exp     = time() + DAY_IN_SECONDS;
		$hash    = ( new \ReflectionMethod( $handler, 'hash' ) )->invoke( $handler, 'guest-123|' . $exp );
		$name    = (string) apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH );
		$_COOKIE[ $name ] = implode( '||', array( 'guest-123', (string) $exp, (string) ( $exp - 60 ), $hash ) );
		$this->assertSame( 200, $this->post( '/order-status', array( 'order' => $o->get_id(), 'key' => $o->get_order_key() ) )->get_status(), 'Formato antiguo, como lo acepta WooCommerce' );
	}

	public function test_a_repeated_hint_does_not_renew_the_hint_clock_and_a_full_list_drops_the_new_one(): void {
		$o    = $this->order();
		$ref  = $o->get_id() . '-1';
		$body = static fn( string $tx ) => array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $ref, 'transaction_id' => $tx );
		$this->verdict( 'unknown' );
		$this->post( '/payment-attempt', $body( 'same' ) );
		$first = wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0]['hint_at'];
		$f     = \Aralia\Orders\OrderStore::fresh( $o->get_id() );
		$rows  = $f->get_meta( '_ara_attempts', true );
		$rows[0]['hint_at'] = gmdate( 'c', time() - 900 ); // La primera pista llegó hace 15 min.
		$f->update_meta_data( '_ara_attempts', $rows );
		$f->save();
		$this->post( '/payment-attempt', $body( 'same' ) );
		$this->post( '/payment-attempt', $body( 'other' ) );
		$row = wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0];
		$this->assertLessThan( time() - 800, strtotime( $row['hint_at'] ), 'Repetir u añadir pistas no renueva el plazo (aplazaría el vencimiento sin fin)' );
		$this->assertNotSame( '', $first );
	}

	public function test_an_order_that_is_no_longer_pending_is_not_looked_up_and_stays_untouched(): void {
		$o = $this->order();
		\Aralia\Orders\StateMachine::run_as(
			\Aralia\Orders\StateMachine::SYSTEM,
			static function () use ( $o ): void {
				$o->update_status( 'cancelled' );
			}
		);
		$this->assertSame( 'cancelled', \Aralia\Orders\StateMachine::stored_status( $o->get_id() ) );
		$calls = 0;
		add_filter(
			'aralia_verify_payment_hint',
			static function ( $v ) use ( &$calls ) {
				++$calls;
				return $v;
			},
			20
		);
		$res = $this->post( '/payment-attempt', array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $o->get_id() . '-1', 'transaction_id' => 'zzz' ) );
		$this->assertSame( 202, $res->get_status() );
		$this->assertSame( 0, $calls, 'No se gastan consultas a la API en pedidos cerrados' );
		$this->assertSame( array(), \Aralia\Orders\Attempts::hints( wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0] ) );
	}

	public function test_hints_are_capped_per_order_not_per_attempt_and_ipv6_is_limited_per_64(): void {
		$o = $this->order();
		$f = \Aralia\Orders\OrderStore::fresh( $o->get_id() );
		$f->update_meta_data( '_ara_attempts', array( array( 'n' => 1, 'reference' => $o->get_id() . '-1', 'status' => 'OPENED' ), array( 'n' => 2, 'reference' => $o->get_id() . '-2', 'status' => 'OPENED' ) ) );
		$f->save();
		$this->verdict( 'unknown' );
		foreach ( array( '1-a', '1-b', '2-a', '2-b' ) as $k ) {
			$this->post( '/payment-attempt', array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $o->get_id() . '-' . $k[0], 'transaction_id' => 'T' . $k ) );
		}
		$this->assertSame( 3, \Aralia\Orders\Attempts::total_hints( wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true ) ), 'Máximo 3 pistas por pedido; las nuevas se descartan' );

		$prev = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:aaaa:bbbb:cccc:dddd';
		$a                      = \Aralia\Rest\OrderAccess::ip();
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:1111:2222:3333:4444';
		$b                      = \Aralia\Rest\OrderAccess::ip();
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:3::1';
		$c                      = \Aralia\Rest\OrderAccess::ip();
		if ( null === $prev ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $prev;
		}
		$this->assertSame( $a, $b, 'Dos IPs del mismo /64 comparten límite' );
		$this->assertNotSame( $a, $c );
	}

	public function test_a_full_hint_list_answers_without_asking_the_api_and_pruning_frees_the_quota(): void {
		$o = $this->order();
		$f = \Aralia\Orders\OrderStore::fresh( $o->get_id() );
		$f->update_meta_data( '_ara_attempts', array( array( 'n' => 1, 'reference' => $o->get_id() . '-1', 'status' => 'OPENED', 'hint_transaction_ids' => array( 'a', 'b', 'c' ), 'hint_at' => gmdate( 'c' ) ) ) );
		$f->save();
		$calls = 0;
		add_filter(
			'aralia_verify_payment_hint',
			static function ( $v ) use ( &$calls ) {
				++$calls;
				return $v;
			},
			20
		);
		$body = array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $o->get_id() . '-1', 'transaction_id' => 'd' );
		$this->assertSame( 202, $this->post( '/payment-attempt', $body )->get_status() );
		$this->assertSame( 0, $calls, 'Con 3 pistas ya guardadas no se consulta a la API' );

		$rows = \Aralia\Orders\Attempts::drop_hints( wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true ), array( 'a' ) ); // La poda libera cupo.
		$f    = \Aralia\Orders\OrderStore::fresh( $o->get_id() );
		$f->update_meta_data( '_ara_attempts', $rows );
		$f->save();
		$this->post( '/payment-attempt', $body );
		$this->assertContains( 'd', \Aralia\Orders\Attempts::hints( wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0] ) );
	}

	public function test_a_hint_for_an_attempt_that_already_has_its_verified_transaction_is_not_looked_up(): void {
		$o = $this->order();
		$f = \Aralia\Orders\OrderStore::fresh( $o->get_id() );
		$f->update_meta_data( '_ara_attempts', array( array( 'n' => 1, 'reference' => $o->get_id() . '-1', 'status' => 'PENDING', 'transaction_id' => 'TX-SIGNED' ) ) );
		$f->save();
		$calls = 0;
		add_filter(
			'aralia_verify_payment_hint',
			static function ( $v ) use ( &$calls ) {
				++$calls;
				return $v;
			},
			20
		);
		$res = $this->post( '/payment-attempt', array( 'order_id' => $o->get_id(), 'order_key' => $o->get_order_key(), 'reference' => $o->get_id() . '-1', 'transaction_id' => 'zzz' ) );
		$this->assertSame( 202, $res->get_status() );
		$this->assertSame( 0, $calls, 'Sin intento abierto para esa referencia no se gasta una consulta' );
	}
}
