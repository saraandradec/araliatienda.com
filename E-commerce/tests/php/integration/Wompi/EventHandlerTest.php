<?php
/**
 * Manejador de eventos de Wompi con WordPress y WooCommerce reales (T060, T068; contracts/wompi §6).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration\Wompi;

use Aralia\Database\Migrator;
use Aralia\Guard\EventHandler;
use Aralia\Guard\Keys;
use Aralia\Guard\PaymentEvents;
use Aralia\Orders\Expiry;
use Aralia\Orders\StateMachine;
use Aralia\Orders\StockOnCreate;
use Aralia\Support\Clock;
use Aralia\Tests\Support\DatabaseTestCase;
use Aralia\Tests\Support\OrderStates;
use Aralia\Tests\Support\WompiFixtures;

final class EventHandlerTest extends DatabaseTestCase {

	private const PRICE = 35000;

	/**
	 * @var int[]
	 */
	private array $orders = array();

	/**
	 * @var int[]
	 */
	private array $products = array();

	/**
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $alerts = array();

	/**
	 * Moneda de la tienda antes de la prueba.
	 *
	 * @var string
	 */
	private string $currency = 'USD';

	protected function set_up() {
		parent::set_up();
		( new Migrator() )->run();
		remove_all_actions( 'aralia_alert' );
		add_action(
			'aralia_alert',
			function ( $event, $ref = '' ) {
				$this->alerts[] = array( (string) $event, (string) $ref );
			},
			10,
			2
		);
		remove_all_filters( 'aralia_wompi_lookup' );
		update_option( Keys::OPTION, array( 'testmode' => 'yes' ) );
		$this->currency = (string) get_option( 'woocommerce_currency' );
		update_option( 'woocommerce_currency', 'COP' ); // La tienda cobra en COP (StoreConfig); los pedidos heredan la moneda.
		Clock::reset();
		$this->alerts = array();
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
		$this->orders   = array();
		$this->products = array();
		parent::tear_down();
	}

	/**
	 * Pedido pendiente de 35.000 COP con el intento 1, stock descontado y vencimiento programado.
	 */
	private function order( int $stock = 5 ): \WC_Order {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Pieza ' . wp_rand() );
		$p->set_sku( 'WP-' . strtoupper( substr( uniqid( '', true ), -8 ) ) . wp_rand( 10, 99 ) );
		$p->set_regular_price( (string) self::PRICE );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $stock );
		$p->save();
		$this->products[] = $p->get_id();
		$order            = wc_create_order();
		$order->add_product( $p, 1 );
		$order->set_payment_method( 'wompi' );
		$order->calculate_totals();
		$order->save();
		$order->update_meta_data( '_ara_attempts', array( array( 'n' => 1, 'reference' => $order->get_id() . '-1', 'status' => 'CREATED' ) ) );
		$order->save();
		$this->orders[] = $order->get_id();
		StockOnCreate::handle( $order );
		return wc_get_order( $order->get_id() );
	}

	private function ref( \WC_Order $o, int $n = 1 ): string {
		return $o->get_id() . '-' . $n;
	}

	private function cents(): int {
		return self::PRICE * 100;
	}

	private function status( \WC_Order $o ): string {
		return StateMachine::stored_status( $o->get_id() );
	}

	private function send( array $event, string $header = '' ): array {
		return ( new EventHandler() )->handle( WompiFixtures::json( $event ), $header );
	}

	private function last_row(): array {
		global $wpdb;
		$t = PaymentEvents::table();
		return (array) $wpdb->get_row( "SELECT * FROM {$t} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	private function stock( \WC_Order $o ): int {
		$item = array_values( $o->get_items() )[0];
		return (int) wc_get_product( $item->get_product_id() )->get_stock_quantity();
	}

	public function test_approved_event_moves_the_order_to_processing_and_logs_everything(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );

		$res = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) );

		$this->assertSame( 200, $res['status'] );
		$this->assertSame( 'processing', $this->status( $o ) );
		$fresh = wc_get_order( $o->get_id() );
		$this->assertSame( 'TX-1', $fresh->get_transaction_id() );
		$this->assertSame( 'TX-1', $fresh->get_meta( '_ara_attempts', true )[0]['transaction_id'] );
		$row = $this->last_row();
		$this->assertSame( '1', (string) $row['checksum_valid'] );
		$this->assertSame( 'yes', $row['api_verified'] );
		$this->assertSame( 'TX-1|APPROVED', $row['idem_key'] );
		$this->assertSame( 'to_processing', $row['action_taken'] );
		$this->assertSame( array(), $this->alerts );
	}

	public function test_event_log_keeps_only_the_whitelisted_fields(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) );
		$raw = (string) $this->last_row()['raw_sanitized'];
		foreach ( array( 'clienta@example.com', 'Ana', '3001234567', '424242', 'PEREZ', 'customer_email', 'customer_data' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $raw, "El registro no debe guardar: $leak" );
		}
		$this->assertStringContainsString( '4242', $raw, 'Solo marca y últimos 4' );
		$this->assertStringContainsString( 'VISA', $raw );
	}

	public function test_forged_checksum_is_rejected_with_401_and_changes_nothing(): void {
		$o     = $this->order();
		$event = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$event['signature']['checksum'] = str_repeat( 'a', 64 );
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );

		$res = $this->send( $event );

		$this->assertSame( 401, $res['status'] );
		$this->assertSame( 'pending', $this->status( $o ) );
		$row = $this->last_row();
		$this->assertSame( '0', (string) $row['checksum_valid'] );
		$this->assertSame( 'checksum_invalid', $row['action_taken'] );
		$this->assertNull( $row['raw_sanitized'], 'Un POST no autenticado no deja su cuerpo en la base de datos' );
		$this->assertContains( 'checksum_invalid', array_column( $this->alerts, 0 ) );
		$this->assertSame( array(), WompiFixtures::$requests, 'Ni siquiera se consulta la API' );
	}

	public function test_checksum_is_case_insensitive_and_the_header_must_match_when_present(): void {
		$o     = $this->order();
		$event = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$upper = strtoupper( $event['signature']['checksum'] );

		$this->assertSame( 401, $this->send( $event, str_repeat( 'b', 64 ) )['status'], 'Cabecera distinta: rechazado' );
		$this->assertSame( 'pending', $this->status( $o ) );

		$event['signature']['checksum'] = $upper; // La documentación de Wompi lo muestra en mayúsculas.
		$this->assertSame( 200, $this->send( $event, $upper )['status'] );
		$this->assertSame( 'processing', $this->status( $o ) );
	}

	public function test_amount_mismatch_in_the_event_or_in_the_api_changes_nothing_and_alerts(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', 100, 'COP', $this->ref( $o ) ) ) ); // La API dice otro monto.
		$this->assertSame( 'mismatch', $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) )['body']['result'] );
		$this->assertSame( 'pending', $this->status( $o ) );

		WompiFixtures::mock_api( array( 'TX-2' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) ); // El evento dice otro monto (firmado).
		$this->assertSame( 'mismatch', $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-2', 'APPROVED', 100 ) )['body']['result'] );
		$this->assertSame( 'pending', $this->status( $o ) );
		$this->assertSame( 2, count( array_filter( $this->alerts, static fn( $a ) => 'payment_mismatch' === $a[0] ) ) );
		$this->assertSame( '0', (string) $this->last_row()['amount_match'] );
	}

	public function test_currency_other_than_cop_changes_nothing(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'USD', $this->ref( $o ) ) ) );
		$this->assertSame( 'mismatch', $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents(), array( 'currency' => 'USD' ) ) )['body']['result'] );
		$this->assertSame( 'pending', $this->status( $o ) );
		$this->assertContains( 'payment_mismatch', array_column( $this->alerts, 0 ) );
	}

	public function test_unknown_foreign_or_unregistered_references_answer_200_without_changes(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array() );
		foreach ( array( '99999999-1', 'abc', $o->get_id() . '-7', $o->get_id() . '-1; DROP' ) as $ref ) {
			$res = $this->send( WompiFixtures::event( $ref, 'TX-X', 'APPROVED', $this->cents() ) );
			$this->assertSame( 200, $res['status'], $ref );
			$this->assertSame( 'unknown_reference', $res['body']['result'], $ref );
		}
		$o->set_payment_method( 'bacs' );
		$o->save();
		$this->assertSame( 'unknown_reference', $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-Y', 'APPROVED', $this->cents() ) )['body']['result'], 'Pedido de otro gateway' );
		$this->assertSame( 'pending', $this->status( $o ) );
		$this->assertContains( 'payment_mismatch', array_column( $this->alerts, 0 ) );
	}

	public function test_the_same_event_twice_changes_the_order_once(): void {
		$o       = $this->order();
		$changes = 0;
		add_action(
			'woocommerce_order_status_changed',
			function () use ( &$changes ) {
				++$changes;
			}
		);
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$event = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$this->assertSame( 'to_processing', $this->send( $event )['body']['result'] );
		$this->assertSame( 'duplicate_ignored', $this->send( $event )['body']['result'] );
		$this->assertSame( 1, $changes );
		$this->assertSame( array(), $this->alerts );
	}

	public function test_idem_key_is_claimed_once_and_the_unique_index_decides(): void {
		$a = PaymentEvents::insert( array( 'status_received' => 'APPROVED' ) );
		$b = PaymentEvents::insert( array( 'status_received' => 'APPROVED' ) );
		$this->assertSame( 'claimed', PaymentEvents::claim_idem( $a, 'TX|APPROVED' ) );
		$this->assertSame( 'error', PaymentEvents::claim_idem( $b, 'TX|APPROVED' ), 'Mientras la primera sigue en curso, el índice único impide la segunda (Wompi reintenta)' );
		PaymentEvents::update( $a, array( 'action_taken' => 'to_processing' ) );
		$this->assertSame( 'duplicate', PaymentEvents::claim_idem( $b, 'TX|APPROVED' ), 'Terminada la primera, la segunda es un duplicado' );
		$this->assertSame( 'error', PaymentEvents::claim_idem( 0, 'TX|OTHER' ), 'Sin fila no es un duplicado: es un error' );
	}

	public function test_declined_after_approved_is_blocked_and_alerts(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) );
		$this->alerts = array();
		remove_all_filters( 'pre_http_request' );
		WompiFixtures::mock_api( array( 'TX-1' => array( 'DECLINED', $this->cents(), 'COP', $this->ref( $o ) ) ) ); // La MISMA transacción pasa a DECLINED.
		$res = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'DECLINED', $this->cents() ) );
		$this->assertSame( 'blocked_regression', $res['body']['result'] );
		$this->assertSame( 'processing', $this->status( $o ) );
		$this->assertContains( 'blocked_transition', array_column( $this->alerts, 0 ) );
	}

	public function test_api_down_defers_then_the_retry_is_processed(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => 'down' ) );
		$event = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$res   = $this->send( $event );
		$this->assertSame( 200, $res['status'] );
		$this->assertSame( 'deferred', $res['body']['result'] );
		$this->assertSame( 'pending', $this->status( $o ) );
		$this->assertSame( 'TX-1', wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0]['transaction_id'], 'El id queda guardado aunque el evento se difiera' );
		$row = $this->last_row();
		$this->assertSame( 'deferred', $row['api_verified'] );
		$this->assertNull( $row['idem_key'], 'Un evento diferido no ocupa idem_key' );

		remove_all_filters( 'pre_http_request' );
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$this->assertSame( 'to_processing', $this->send( $event )['body']['result'], 'El reintento de Wompi sí se procesa' );
		$this->assertSame( 'processing', $this->status( $o ) );
	}

	public function test_late_approval_goes_back_to_processing_or_to_nostock(): void {
		foreach ( array( 5 => 'processing', 0 => 'ara-nostock' ) as $left => $expected ) {
			$o = $this->order();
			add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
			Clock::advance( 31 * MINUTE_IN_SECONDS );
			Expiry::run( $o->get_id() );
			remove_all_filters( 'aralia_wompi_lookup' );
			$this->assertSame( 'cancelled', $this->status( $o ), 'Premisa: venció y devolvió el stock' );
			$item = array_values( wc_get_order( $o->get_id() )->get_items() )[0];
			wc_update_product_stock( wc_get_product( $item->get_product_id() ), $left, 'set' );
			Clock::reset();

			$tx = 'TX-L' . $left;
			WompiFixtures::mock_api( array( $tx => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
			$res = $this->send( WompiFixtures::event( $this->ref( $o ), $tx, 'APPROVED', $this->cents() ) );
			$this->assertSame( $expected, $this->status( $o ), $res['body']['result'] );
			remove_all_filters( 'pre_http_request' );
		}
		$this->assertContains( 'nostock', array_column( $this->alerts, 0 ) );
	}

	public function test_a_second_approved_attempt_on_a_paid_order_raises_a_double_charge_alert(): void {
		$o = $this->order();
		$a = $o->get_meta( '_ara_attempts', true );
		$a[] = array( 'n' => 2, 'reference' => $this->ref( $o, 2 ), 'status' => 'CREATED' );
		$o->update_meta_data( '_ara_attempts', $a );
		$o->save();
		WompiFixtures::mock_api(
			array(
				'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o, 1 ) ),
				'TX-2' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o, 2 ) ),
			)
		);
		$this->send( WompiFixtures::event( $this->ref( $o, 1 ), 'TX-1', 'APPROVED', $this->cents() ) );
		$res = $this->send( WompiFixtures::event( $this->ref( $o, 2 ), 'TX-2', 'APPROVED', $this->cents() ) );
		$this->assertSame( 'double_charge', $res['body']['result'] );
		$this->assertSame( 'processing', $this->status( $o ) );
		$this->assertContains( 'duplicate_charge', array_column( $this->alerts, 0 ) );
	}

	public function test_voided_event_refunds_a_paid_order_but_not_a_shipped_one(): void {
		$o = $this->order();
		WompiFixtures::mock_api(
			array(
				'TX-1' => array( 'VOIDED', $this->cents(), 'COP', $this->ref( $o ) ),
			)
		);
		OrderStates::walk( $o, 'processing' );
		$res = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'VOIDED', $this->cents() ) );
		$this->assertSame( 'voided_refunded', $res['body']['result'] );
		$this->assertSame( 'refunded', $this->status( $o ) );
		$this->assertSame( 'reversal', wc_get_order( $o->get_id() )->get_meta( '_ara_refund_log', true )[0]['tipo'] );
		$this->assertContains( 'payment_voided', array_column( $this->alerts, 0 ) );

		$s = $this->order();
		WompiFixtures::mock_api( array( 'TX-9' => array( 'VOIDED', $this->cents(), 'COP', $this->ref( $s ) ) ) );
		OrderStates::walk( $s, 'processing', 'ara-packed', 'ara-shipped' );
		$this->assertSame( 'blocked_void', $this->send( WompiFixtures::event( $this->ref( $s ), 'TX-9', 'VOIDED', $this->cents() ) )['body']['result'] );
		$this->assertSame( 'ara-shipped', $this->status( $s ) );
	}

	public function test_declined_on_a_pending_order_records_the_attempt_and_keeps_the_hold(): void {
		$o      = $this->order();
		$hooked = array();
		add_action(
			'aralia_payment_declined',
			function ( $id, $ref ) use ( &$hooked ) {
				$hooked[] = array( $id, $ref );
			},
			10,
			2
		);
		WompiFixtures::mock_api( array( 'TX-1' => array( 'DECLINED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$res = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'DECLINED', $this->cents() ) );
		$this->assertSame( 'declined_recorded', $res['body']['result'] );
		$this->assertSame( 'pending', $this->status( $o ) );
		$this->assertSame( 'DECLINED', wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0]['status'] );
		$this->assertSame( array( array( $o->get_id(), $this->ref( $o ) ) ), $hooked );
		$this->assertContains( 'payment_declines', array_column( $this->alerts, 0 ) );
	}

	public function test_pending_event_is_recorded_without_changing_the_order(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'PENDING', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$this->assertSame( 'pending_recorded', $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'PENDING', $this->cents() ) )['body']['result'] );
		$this->assertSame( 'pending', $this->status( $o ) );
		$this->assertSame( 'PENDING', wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0]['status'] );
	}

	public function test_malformed_oversized_and_foreign_events_are_handled(): void {
		$h = new EventHandler();
		$this->assertSame( 400, $h->handle( 'no es json' )['status'] );
		$this->assertSame( 400, $h->handle( '{"event":"transaction.updated"}' )['status'] );
		$this->assertSame( 400, $h->handle( str_repeat( 'a', EventHandler::MAX_BODY + 1 ) )['status'] );
		$o     = $this->order();
		$event = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$event['event'] = 'nequi_token.updated';
		$this->assertSame( 'ignored_event', $this->send( $event )['body']['result'] );
		$this->assertSame( 'pending', $this->status( $o ) );
		$bad = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$bad['signature']['properties'][] = 'transaction.nope';
		$this->assertSame( 401, $this->send( $bad )['status'], 'Una propiedad firmada inexistente no valida' );
	}

	public function test_a_busy_order_answers_503_without_burning_the_idem_key(): void {
		global $wpdb;
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$key = 'aralia_exp_lock_' . $o->get_id();
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, time() . ':otro' ) ); // phpcs:ignore WordPress.DB
		$event = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$this->assertSame( 503, $this->send( $event )['status'] );
		$this->assertSame( 'pending', $this->status( $o ) );
		$this->assertNull( $this->last_row()['idem_key'] );

		$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( $key, 'options' );
		$this->assertSame( 'to_processing', $this->send( $event )['body']['result'] );
	}

	public function test_the_api_is_always_queried_with_verified_tls_and_8_seconds(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) );
		$this->assertNotEmpty( WompiFixtures::$requests );
		$this->assertTrue( WompiFixtures::$requests[0]['args']['sslverify'] );
		$this->assertSame( 8, (int) WompiFixtures::$requests[0]['args']['timeout'] );
		$this->assertStringContainsString( 'sandbox.wompi.co', WompiFixtures::$requests[0]['url'], 'Modo de prueba: sandbox' );
	}

	public function test_a_decline_from_another_attempt_never_touches_a_paid_order(): void {
		$o = $this->order();
		$a = $o->get_meta( '_ara_attempts', true );
		$a[] = array( 'n' => 2, 'reference' => $this->ref( $o, 2 ), 'status' => 'CREATED' );
		$o->update_meta_data( '_ara_attempts', $a );
		$o->save();
		WompiFixtures::mock_api(
			array(
				'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o, 1 ) ),
				'TX-2' => array( 'DECLINED', $this->cents(), 'COP', $this->ref( $o, 2 ) ),
			)
		);
		$this->send( WompiFixtures::event( $this->ref( $o, 1 ), 'TX-1', 'APPROVED', $this->cents() ) );
		$this->alerts = array();
		$this->assertSame( 'declined_other_attempt', $this->send( WompiFixtures::event( $this->ref( $o, 2 ), 'TX-2', 'DECLINED', $this->cents() ) )['body']['result'] );
		$this->assertSame( 'processing', $this->status( $o ) );
		$this->assertSame( array(), $this->alerts, 'Sin falsas alarmas' );
	}

	public function test_a_failed_registry_insert_answers_503_and_changes_nothing(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$cb = static fn( $q ) => 0 === strpos( ltrim( (string) $q ), 'INSERT INTO `' . PaymentEvents::table() ) ? 'SELECT invalid_sql_to_fail' : $q;
		add_filter( 'query', $cb );
		try {
			$res = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) );
		} finally {
			remove_filter( 'query', $cb );
		}
		$this->assertSame( 503, $res['status'], 'Si no se puede registrar, Wompi debe reintentar (no 200)' );
		$this->assertSame( 'log_failed', $res['body']['result'] );
		$this->assertSame( 'pending', $this->status( $o ) );
	}

	public function test_an_exception_while_applying_frees_the_idem_key_and_the_retry_is_processed(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$boom = static function () {
			throw new \RuntimeException( 'falla al guardar el pedido' );
		};
		add_action( 'woocommerce_before_order_object_save', $boom, 5 );
		$event = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$res   = $this->send( $event );
		remove_action( 'woocommerce_before_order_object_save', $boom, 5 );

		$this->assertSame( 500, $res['status'] );
		// WooCommerce traga la excepción dentro de payment_complete(): el pedido no avanza ('blocked_payment'); cualquier otra excepción es 'apply_failed'.
		$this->assertContains( $res['body']['result'], array( 'apply_failed', 'blocked_payment' ) );
		$row = $this->last_row();
		$this->assertNull( $row['idem_key'], 'La clave se libera: el reintento de Wompi no se descarta como duplicado' );
		$this->assertSame( $res['body']['result'], $row['action_taken'] );
		$this->assertContains( 'payment_mismatch', array_column( $this->alerts, 0 ) );
		$this->assertSame( 'pending', $this->status( $o ) );

		$this->assertSame( 'to_processing', $this->send( $event )['body']['result'], 'El reintento sí se aplica' );
		$this->assertSame( 'processing', $this->status( $o ) );
	}

	public function test_the_same_transaction_after_it_paid_is_recognised_without_a_double_charge_alert(): void {
		global $wpdb;
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$event = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$this->send( $event );
		$t = PaymentEvents::table();
		$wpdb->query( "UPDATE {$t} SET idem_key = NULL" ); // phpcs:ignore WordPress.DB -- Simula una clave que no llegó a guardarse (proceso caído).
		$this->alerts = array();
		$this->assertSame( 'duplicate_ignored', $this->send( $event )['body']['result'], 'already_applied: la misma transacción que ya pagó' );
		$this->assertSame( array(), $this->alerts );
	}

	public function test_a_status_that_only_changed_between_the_event_and_the_api_is_a_stale_event_without_alert(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'DECLINED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$res = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) );
		$this->assertSame( 'stale_event', $res['body']['result'] );
		$this->assertSame( 'pending', $this->status( $o ), 'Un evento atrasado nunca paga' );
		$this->assertSame( 'TX-1', wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0]['transaction_id'], 'La transacción queda anotada' );
		$this->assertSame( array(), $this->alerts );
	}

	public function test_unauthenticated_junk_cannot_fill_the_events_table(): void {
		global $wpdb;
		$o = $this->order();
		$e = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$e['signature']['checksum'] = str_repeat( 'c', 64 );
		$h = new EventHandler();
		for ( $i = 0; $i < 40; $i++ ) {
			$h->handle( WompiFixtures::json( $e ) );
			$h->handle( 'basura ' . $i );
		}
		$t = PaymentEvents::table();
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB
		$this->assertLessThanOrEqual( EventHandler::REJECT_LOG_PER_IP, $n, 'El rastro de lo no autenticado tiene tope por hora' );
		$this->assertGreaterThan( 0, $n );
	}

	public function test_old_unauthenticated_rows_are_purged_but_authenticated_ones_are_kept(): void {
		global $wpdb;
		$old = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
		$bad = PaymentEvents::insert( array( 'status_received' => 'ERROR', 'checksum_valid' => 0, 'received_at' => $old ) );
		$ok  = PaymentEvents::insert( array( 'status_received' => 'APPROVED', 'checksum_valid' => 1, 'received_at' => $old ) );
		$this->assertGreaterThanOrEqual( 1, PaymentEvents::purge_unauthenticated( 30 ) );
		$t = PaymentEvents::table();
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE id = %d", $bad ) ) ); // phpcs:ignore WordPress.DB
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE id = %d", $ok ) ) ); // phpcs:ignore WordPress.DB
	}

	public function test_signature_must_cover_id_status_and_amount_and_the_timestamp_must_be_digits(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$weak = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$weak['signature']['properties'] = array( 'transaction.id', 'transaction.status' ); // Sin el monto firmado.
		$this->assertSame( 400, $this->send( $weak )['status'] );
		foreach ( array( '1.5e9', '-5', 'abc', '12345678901234' ) as $ts ) {
			$bad              = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
			$bad['timestamp'] = $ts;
			$this->assertSame( 400, $this->send( $bad )['status'], "timestamp $ts" );
		}
		$ok              = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$ok['timestamp'] = (string) $ok['timestamp']; // Como texto con solo dígitos, vale.
		$this->assertSame( 200, $this->send( $ok )['status'] );
		$this->assertSame( 'processing', $this->status( $o ) );
	}

	public function test_references_from_other_channels_do_not_page_sara_but_ours_that_do_not_match_do(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array() );
		$this->send( WompiFixtures::event( 'LINK-DE-PAGO-ABC', 'TX-X', 'APPROVED', $this->cents() ) );
		$this->assertSame( array(), $this->alerts, 'Otro canal de Wompi: sin SMS' );
		$this->send( WompiFixtures::event( $o->get_id() . '-9', 'TX-Y', 'APPROVED', $this->cents() ) );
		$this->assertContains( 'payment_mismatch', array_column( $this->alerts, 0 ) );
	}

	public function test_a_late_approval_stores_the_transaction_id_so_the_reconciliation_does_not_cry_double_charge(): void {
		$o = $this->order();
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $o->get_id() );
		remove_all_filters( 'aralia_wompi_lookup' );
		Clock::reset();
		WompiFixtures::mock_api( array( 'TX-L' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$this->send( WompiFixtures::event( $this->ref( $o ), 'TX-L', 'APPROVED', $this->cents() ) );
		$this->assertSame( 'TX-L', wc_get_order( $o->get_id() )->get_transaction_id() );
	}

	public function test_a_deferred_event_writes_under_the_order_lock(): void {
		global $wpdb;
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => 'down' ) );
		$key = 'aralia_exp_lock_' . $o->get_id();
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, time() . ':otro' ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 503, $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) )['status'] );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( $key, 'options' );
	}

	public function test_an_api_authorization_error_is_not_treated_as_a_quiet_outage(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => 401 ) );
		$res = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) );
		$this->assertSame( 'deferred', $res['body']['result'] );
		$this->assertContains( 'payment_mismatch', array_column( $this->alerts, 0 ), 'T-API-AUTH mal configurada: debe avisar' );
	}

	public function test_voided_on_a_packed_order_is_refunded_and_on_an_unpaid_one_it_is_just_recorded(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'VOIDED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		OrderStates::walk( $o, 'processing', 'ara-packed' );
		$this->assertSame( 'voided_refunded', $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'VOIDED', $this->cents() ) )['body']['result'] );
		$this->assertSame( 'refunded', $this->status( $o ) );

		$u = $this->order();
		WompiFixtures::mock_api( array( 'TX-U' => array( 'VOIDED', $this->cents(), 'COP', $this->ref( $u ) ) ) );
		$this->send( WompiFixtures::event( $this->ref( $u ), 'TX-U', 'VOIDED', $this->cents() ) );
		$this->assertSame( 'pending', $this->status( $u ), 'VOIDED de un intento sin pago no cambia el pedido' );
	}

	public function test_an_event_ahead_of_the_api_gets_503_so_wompi_retries_and_the_transaction_is_noted(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'PENDING', $this->cents(), 'COP', $this->ref( $o ) ) ) ); // La API aún no refleja el APPROVED.
		$res = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) );
		$this->assertSame( 503, $res['status'] );
		$this->assertSame( 'stale_retry', $res['body']['result'] );
		$this->assertSame( 'pending', $this->status( $o ) );
		$this->assertSame( 'TX-1', wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0]['transaction_id'], 'El cobro queda anotado en el pedido (vencimiento y conciliación lo encuentran)' );
		$this->assertNull( $this->last_row()['idem_key'] );

		remove_all_filters( 'pre_http_request' );
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$this->assertSame( 'to_processing', $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) )['body']['result'], 'El reintento ya se aplica' );
	}

	public function test_a_real_exception_while_applying_is_caught_frees_the_key_and_alerts(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'DECLINED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$boom = static function () {
			throw new \RuntimeException( 'falla un gancho de correo' ); // aralia_payment_declined corre FUERA del try interno de WooCommerce.
		};
		add_action( 'aralia_payment_declined', $boom );
		$event = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'DECLINED', $this->cents() );
		$res   = $this->send( $event );
		remove_action( 'aralia_payment_declined', $boom );
		$this->assertSame( 500, $res['status'] );
		$this->assertSame( 'apply_failed', $res['body']['result'] );
		$row = $this->last_row();
		$this->assertNull( $row['idem_key'], 'La clave se libera' );
		$this->assertSame( 'apply_failed', $row['action_taken'] );
		$this->assertContains( 'payment_mismatch', array_column( $this->alerts, 0 ) );
		$this->assertSame( 'declined_recorded', $this->send( $event )['body']['result'], 'El reintento se procesa' );
	}

	public function test_a_deferred_event_on_a_busy_order_leaves_no_trace_but_the_row(): void {
		global $wpdb;
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => 'down' ) );
		$key = 'aralia_exp_lock_' . $o->get_id();
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, time() . ':otro' ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 503, $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) )['status'] );
		$this->assertArrayNotHasKey( 'transaction_id', wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0], 'Sin candado no se escribe el pedido' );
		$this->assertSame( 'lock_busy', $this->last_row()['action_taken'] );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( $key, 'options' );
	}

	public function test_the_api_authorization_alert_is_sent_once_per_hour(): void {
		delete_transient( 'aralia_wompi_auth_alert' );
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => 401, 'TX-2' => 401 ) );
		$this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) );
		$this->send( WompiFixtures::event( $this->ref( $o ), 'TX-2', 'APPROVED', $this->cents() ) );
		$this->assertSame( 1, count( array_filter( $this->alerts, static fn( $a ) => 'payment_mismatch' === $a[0] ) ) );
		delete_transient( 'aralia_wompi_auth_alert' );
	}

	public function test_strict_types_for_timestamp_and_amount(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array() );
		foreach ( array( true, 1700000000.5, array( 1 ) ) as $ts ) {
			$e              = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
			$e['timestamp'] = $ts;
			$this->assertSame( 400, $this->send( $e )['status'] );
		}
		$e = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$e['data']['transaction']['amount_in_cents'] = 3500000.5;
		$this->assertSame( 400, $this->send( $e )['status'], 'Monto con decimales' );
	}

	public function test_rejecting_junk_stops_creating_per_ip_counters_once_the_global_cap_is_passed(): void {
		global $wpdb;
		$before = $_SERVER['REMOTE_ADDR'] ?? null;
		$h      = new EventHandler();
		try {
			for ( $i = 0; $i < EventHandler::REJECT_LOG_GLOBAL + 5; $i++ ) {
				$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( $i % 250 ); // Rotando de IP.
				$h->handle( 'basura' );
			}
		} finally {
			if ( null === $before ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $before;
			}
		}
		$t     = $wpdb->prefix . 'aralia_rate_limits';
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE bucket LIKE 'wompi_rej%'" ); // phpcs:ignore WordPress.DB
		$this->assertSame( '', (string) $wpdb->last_error, 'La consulta debe ser válida' );
		$this->assertGreaterThanOrEqual( EventHandler::REJECT_LOG_GLOBAL, $count, 'Se crearon claves hasta el tope' );
		$this->assertLessThanOrEqual( EventHandler::REJECT_LOG_GLOBAL + 2, $count, 'Superado el tope global no se crea una clave por IP más (con el orden antiguo serían ~106)' );
	}

	public function test_an_unexpected_exception_outside_the_apply_step_answers_500_and_alerts_once_per_hour(): void {
		delete_transient( 'aralia_wompi_error_alert' );
		$o = $this->order();
		$boom = static function () {
			throw new \RuntimeException( 'cualquier cosa con datos personales ana@example.com' );
		};
		add_filter( 'pre_http_request', $boom );
		$e1 = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) );
		$e2 = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-2', 'APPROVED', $this->cents() ) );
		remove_filter( 'pre_http_request', $boom );
		$this->assertSame( 500, $e1['status'] );
		$this->assertSame( 'error', $e1['body']['result'] );
		$this->assertSame( 500, $e2['status'] );
		$this->assertSame( 1, count( array_filter( $this->alerts, static fn( $a ) => 'payment_mismatch' === $a[0] ) ), 'Una alerta por hora' );
		$this->assertStringNotContainsString( 'ana@example.com', (string) wp_json_encode( $this->alerts ), 'Sin el mensaje de la excepción en la alerta' );
		delete_transient( 'aralia_wompi_error_alert' );
	}

	public function test_the_shutdown_release_only_frees_an_unfinished_claim(): void {
		$a = PaymentEvents::insert( array( 'status_received' => 'APPROVED' ) );
		PaymentEvents::claim_idem( $a, 'TX|APPROVED' );
		EventHandler::release_if_unfinished( (object) array( 'finished' => true ), $a );
		$this->assertSame( 'TX|APPROVED', $this->row_by_id( $a )['idem_key'], 'Terminado: no se toca' );
		EventHandler::release_if_unfinished( (object) array( 'finished' => false ), $a );
		$this->assertNull( $this->row_by_id( $a )['idem_key'], 'Sin terminar: se libera' );
	}

	public function test_a_claim_abandoned_by_a_killed_process_is_recovered_after_fifteen_minutes(): void {
		global $wpdb;
		$t    = PaymentEvents::table();
		$dead = PaymentEvents::insert( array( 'status_received' => 'APPROVED', 'received_at' => gmdate( 'Y-m-d H:i:s', time() - 1200 ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET idem_key = %s WHERE id = %d", 'TX|APPROVED', $dead ) ); // phpcs:ignore WordPress.DB -- Un proceso que ocupó la clave y murió (SIGKILL).
		$this->assertFalse( PaymentEvents::has_idem( 'TX|APPROVED' ), 'Sin action_taken no cuenta como duplicado' );
		$retry = PaymentEvents::insert( array( 'status_received' => 'APPROVED' ) );
		$this->assertSame( 'claimed', PaymentEvents::claim_idem( $retry, 'TX|APPROVED' ), 'La clave abandonada se recupera' );

		$live  = PaymentEvents::insert( array( 'status_received' => 'APPROVED', 'received_at' => gmdate( 'Y-m-d H:i:s', time() - 300 ) ) ); // Más de 120 s: el candado ya pudo vencer, la clave NO.
		$other = PaymentEvents::insert( array( 'status_received' => 'APPROVED' ) );
		PaymentEvents::claim_idem( $live, 'TX2|APPROVED' ); // En curso, reciente.
		$this->assertSame( 'error', PaymentEvents::claim_idem( $other, 'TX2|APPROVED' ), 'Una en curso (aunque lleve más que el candado) no se roba antes de 15 min' );
		PaymentEvents::update( $live, array( 'action_taken' => 'to_processing' ) );
		$this->assertSame( 'duplicate', PaymentEvents::claim_idem( $other, 'TX2|APPROVED' ), 'Terminada: duplicado' );
	}

	public function test_a_replayed_valid_event_does_not_add_rows(): void {
		global $wpdb;
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$event = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() );
		$this->send( $event );
		$t      = PaymentEvents::table();
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 'duplicate_ignored', $this->send( $event )['body']['result'] );
		}
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ), 'Repetir un evento firmado no llena la tabla' ); // phpcs:ignore WordPress.DB
	}

	public function test_a_transaction_the_api_does_not_know_yet_is_retried_not_alerted_when_the_attempt_is_recent(): void {
		$o = $this->order();
		WompiFixtures::mock_api( array() ); // 404 para todo.
		$res = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-NEW', 'APPROVED', $this->cents() ) );
		$this->assertSame( 503, $res['status'] );
		$this->assertSame( 'api_lag', $res['body']['result'] );
		$this->assertSame( array(), $this->alerts, 'Sin falsa alarma' );
		$this->assertSame( 'TX-NEW', wc_get_order( $o->get_id() )->get_meta( '_ara_attempts', true )[0]['transaction_id'] );
	}

	private function row_by_id( int $id ): array {
		global $wpdb;
		$t = PaymentEvents::table();
		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public function test_an_unfinished_claim_is_not_a_duplicate_for_the_shortcut(): void {
		global $wpdb;
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$id = PaymentEvents::insert( array( 'status_received' => 'APPROVED', 'transaction_id' => 'TX-1', 'checksum_valid' => 1 ) );
		PaymentEvents::claim_idem( $id, 'TX-1|APPROVED' ); // Otro procesamiento en curso: clave ocupada, sin action_taken.
		$res = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) );
		$this->assertSame( 503, $res['status'], 'No es un duplicado: Wompi reintenta cuando el otro termine' );
		$this->assertSame( 'idem_busy', $res['body']['result'] );
		$this->assertSame( 'pending', $this->status( $o ) );
	}

	public function test_replays_that_never_claim_the_key_reuse_one_row(): void {
		global $wpdb;
		$o = $this->order();
		WompiFixtures::mock_api( array( 'TX-1' => array( 'PENDING', $this->cents(), 'COP', $this->ref( $o ) ) ) );
		$event = WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ); // Evento por delante de la API: 503 stale_retry, sin idem_key.
		for ( $i = 0; $i < 6; $i++ ) {
			$this->assertSame( 503, $this->send( $event )['status'] );
		}
		$t = PaymentEvents::table();
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE transaction_id = %s", 'TX-1' ) ), 'Una sola fila por transacción y estado' ); // phpcs:ignore WordPress.DB
	}

	public function test_the_attempt_creation_time_is_not_renewed_by_every_note(): void {
		$o = $this->order();
		$a = $o->get_meta( '_ara_attempts', true );
		$a[0]['at'] = gmdate( 'c', time() - HOUR_IN_SECONDS );
		$o->update_meta_data( '_ara_attempts', $a );
		$o->save();
		WompiFixtures::mock_api( array() ); // NOT_FOUND para todo.
		$res = $this->send( WompiFixtures::event( $this->ref( $o ), 'TX-NEW', 'APPROVED', $this->cents() ) );
		$this->assertSame( 'mismatch', $res['body']['result'], 'Intento de hace 1 h: una transacción desconocida YA no es un desfase de la API' );
		$this->assertContains( 'payment_mismatch', array_column( $this->alerts, 0 ) );

		// Intento de hace 5 min: reciente → api_lag (anota); la hora de creación NO se reescribe en cada nota.
		$o2   = $this->order();
		$seed = gmdate( 'c', time() - 5 * MINUTE_IN_SECONDS );
		$att  = $o2->get_meta( '_ara_attempts', true );
		$att[0]['at'] = $seed;
		$o2->update_meta_data( '_ara_attempts', $att );
		$o2->save();
		$this->assertSame( 'api_lag', $this->send( WompiFixtures::event( $this->ref( $o2 ), 'TX-NEW2', 'APPROVED', $this->cents() ) )['body']['result'] );
		$after = wc_get_order( $o2->get_id() )->get_meta( '_ara_attempts', true )[0];
		$this->assertSame( 'TX-NEW2', $after['transaction_id'], 'Se anotó' );
		$this->assertSame( $seed, $after['at'], 'La hora de creación no se reescribe (con el código anterior sería «ahora»)' );
	}

	public function test_a_reused_row_is_reset_and_a_claimed_or_foreign_row_is_never_reused(): void {
		global $wpdb;
		$t   = PaymentEvents::table();
		$old = array( 'transaction_id' => 'TX-R', 'status_received' => 'APPROVED', 'checksum_valid' => 1 );

		$stale = PaymentEvents::insert( array_merge( $old, array( 'action_taken' => 'deferred', 'api_verified' => 'yes', 'amount_match' => 0, 'order_id' => 77 ) ) );
		$this->assertSame( $stale, PaymentEvents::reusable_row( 'TX-R', 'APPROVED' ) );
		$this->assertTrue( PaymentEvents::reuse( $stale, array_merge( $old, array( 'amount_in_cents' => 5 ) ) ) );
		$row = $this->row_by_id( $stale );
		$this->assertSame( '', $row['action_taken'] );
		$this->assertSame( 'n/a', $row['api_verified'] );
		$this->assertNull( $row['amount_match'], 'Sin banderas del intento anterior' );
		$this->assertSame( '0', (string) $row['order_id'] );

		// Ya reclamada (otro procesamiento): el UPDATE condicional NO la pisa.
		PaymentEvents::claim_idem( $stale, 'TX-R|APPROVED' );
		PaymentEvents::update( $stale, array( 'action_taken' => 'to_processing' ) );
		$this->assertFalse( PaymentEvents::reuse( $stale, $old ), 'No se pisa el resultado de otro procesamiento' );
		$this->assertSame( 'to_processing', $this->row_by_id( $stale )['action_taken'] );
		$this->assertSame( 0, PaymentEvents::reusable_row( 'TX-R', 'APPROVED' ), 'Con idem_key no es reutilizable' );

		// En curso (sin action_taken) o con alerta (mismatch...): evidencia / procesamiento vivo, nunca se pisan.
		$inflight = PaymentEvents::insert( array_merge( $old, array( 'transaction_id' => 'TX-F' ) ) );
		$alerted  = PaymentEvents::insert( array_merge( $old, array( 'transaction_id' => 'TX-M', 'action_taken' => 'mismatch' ) ) );
		$this->assertSame( 0, PaymentEvents::reusable_row( 'TX-F', 'APPROVED' ), 'En curso' );
		$this->assertSame( 0, PaymentEvents::reusable_row( 'TX-M', 'APPROVED' ), 'Con alerta' );
		$this->assertFalse( PaymentEvents::reuse( $inflight, $old ), 'El UPDATE atómico tampoco pisa una fila en curso' );
		$this->assertFalse( PaymentEvents::reuse( $alerted, $old ) );
		$this->assertSame( 'mismatch', $this->row_by_id( $alerted )['action_taken'], 'La evidencia queda' );

		// Otro estado, no autenticada o de hace más de 1 h: nunca se reutilizan.
		PaymentEvents::insert( array_merge( $old, array( 'status_received' => 'DECLINED' ) ) );
		PaymentEvents::insert( array( 'transaction_id' => 'TX-U', 'status_received' => 'APPROVED', 'checksum_valid' => 0 ) );
		PaymentEvents::insert( array_merge( $old, array( 'transaction_id' => 'TX-OLD', 'received_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ) ) );
		$this->assertSame( 0, PaymentEvents::reusable_row( 'TX-U', 'APPROVED' ), 'No autenticada' );
		$this->assertSame( 0, PaymentEvents::reusable_row( 'TX-OLD', 'APPROVED' ), 'Más de 1 h' );
		$this->assertSame( 0, PaymentEvents::reusable_row( 'TX-R', 'VOIDED' ), 'Otro estado' );
	}

	public function test_the_shutdown_also_frees_the_order_lock(): void {
		global $wpdb;
		$o   = $this->order();
		$key = 'aralia_exp_lock_' . $o->get_id();
		$this->assertTrue( Expiry::lock( $o->get_id() ) );
		$id = PaymentEvents::insert( array( 'status_received' => 'APPROVED' ) );
		EventHandler::release_if_unfinished( (object) array( 'finished' => false ), $id, $o->get_id() );
		wp_cache_delete( $key, 'options' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ), 'El candado del pedido queda libre' ); // phpcs:ignore WordPress.DB
	}

	public function test_the_global_catch_logs_the_class_but_not_the_message(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ara' );
		$old = ini_set( 'error_log', $tmp );
		delete_transient( 'aralia_wompi_error_alert' );
		$o    = $this->order();
		$boom = static function () {
			throw new \RuntimeException( 'mensaje con ana@example.com' );
		};
		add_filter( 'pre_http_request', $boom );
		try {
			$this->send( WompiFixtures::event( $this->ref( $o ), 'TX-1', 'APPROVED', $this->cents() ) );
		} finally {
			remove_filter( 'pre_http_request', $boom );
			ini_set( 'error_log', (string) $old );
		}
		$log = (string) file_get_contents( $tmp );
		unlink( $tmp );
		$this->assertStringContainsString( 'RuntimeException', $log );
		$this->assertStringNotContainsString( 'ana@example.com', $log );
		delete_transient( 'aralia_wompi_error_alert' );
	}
}
