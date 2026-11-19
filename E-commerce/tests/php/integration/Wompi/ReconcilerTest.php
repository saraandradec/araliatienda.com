<?php
/**
 * Conciliación (T063): aplica los pagos que el evento firmado no pudo aplicar, solo con la API de NUESTRO comercio, sin `idem_key`.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration\Wompi;

use Aralia\Guard\Keys;
use Aralia\Guard\PaymentEvents;
use Aralia\Guard\Reconciler;
use Aralia\Orders\Expiry;
use Aralia\Orders\OrderStore;
use Aralia\Orders\StateMachine;
use Aralia\Orders\StockOnCreate;
use Aralia\Tests\Support\IsolatedTestCase;
use Aralia\Tests\Support\WompiFixtures;

final class ReconcilerTest extends IsolatedTestCase {

	/**
	 * @var int[]
	 */
	private array $orders = array();

	/**
	 * @var int[]
	 */
	private array $products = array();

	private string $currency = 'USD';

	/**
	 * Alertas levantadas.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $alerts = array();

	protected function set_up() {
		parent::set_up();
		update_option( Keys::OPTION, array( 'testmode' => 'yes' ) );
		$this->currency = (string) get_option( 'woocommerce_currency' );
		update_option( 'woocommerce_currency', 'COP' );
		remove_all_actions( 'aralia_alert' );
		remove_all_filters( 'pre_http_request' );
		delete_transient( 'aralia_wompi_recon_alert' );
		$this->alerts = array();
		add_action(
			'aralia_alert',
			function ( $event, $ref ): void {
				$this->alerts[] = array( (string) $event, (string) $ref );
			},
			10,
			2
		);
	}

	protected function tear_down() {
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
	 * @param array<int,array<string,mixed>> $attempts Intentos.
	 * @param int                            $age      Antigüedad del pedido en segundos.
	 */
	private function order( array $attempts, int $age = 0 ): \WC_Order {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Pieza ' . wp_rand() );
		$p->set_sku( 'RC-' . strtoupper( substr( uniqid( '', true ), -8 ) ) . wp_rand( 10, 99 ) );
		$p->set_regular_price( '35000' );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 5 );
		$p->save();
		$this->products[] = $p->get_id();
		$order            = wc_create_order();
		$order->add_product( $p, 1 );
		$order->set_payment_method( 'wompi' );
		$order->calculate_totals();
		if ( $age > 0 ) {
			$order->set_date_created( time() - $age );
		}
		$order->save();
		$this->orders[] = $order->get_id();
		StockOnCreate::handle( $order );
		$rows = array();
		foreach ( $attempts as $a ) {
			$a['reference'] = $order->get_id() . '-' . $a['n'];
			$rows[]         = $a;
		}
		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( '_ara_attempts', $rows );
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	private function cancel( \WC_Order $o ): void {
		StateMachine::run_as(
			StateMachine::SYSTEM,
			static function () use ( $o ): void {
				$o->update_status( 'cancelled' );
			}
		);
	}

	private function events_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PaymentEvents::table() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return array<int,mixed> Una transacción de nuestro comercio para el intento n.
	 */
	private function tx( \WC_Order $o, string $id, string $status, int $n = 1, int $cents = 3500000 ): array {
		return array( $id, $status, $cents, 'COP', $o->get_id() . '-' . $n );
	}

	public function test_an_approved_payment_of_our_merchant_without_an_event_is_applied_once_without_idem_key(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ), 'hint_transaction_ids' => array( 'TX-A' ) ) ) );
		WompiFixtures::mock_by_reference( array( $o->get_id() . '-1' => array( $this->tx( $o, 'TX-A', 'APPROVED' ) ) ) );
		$before = $this->events_rows();
		$this->assertSame( 'to_processing', Reconciler::reconcile( $o->get_id() ) );
		$this->assertSame( 'processing', StateMachine::stored_status( $o->get_id() ) );
		$fresh = OrderStore::fresh( $o->get_id() );
		$this->assertSame( 'TX-A', $fresh->get_transaction_id() );
		$row = $fresh->get_meta( '_ara_attempts', true )[0];
		$this->assertSame( 'TX-A', $row['transaction_id'], 'El id verificado lo fija la conciliación, no la pista' );
		$this->assertSame( array(), \Aralia\Orders\Attempts::hints( $row ) );
		$this->assertSame( $before, $this->events_rows(), 'No ocupa ni escribe idem_key: no toca la tabla de eventos' );
		$this->assertSame( 'skip', Reconciler::reconcile( $o->get_id() ), 'Ya no está pending ni cancelled' );
	}

	public function test_a_transaction_the_merchant_does_not_have_is_never_granted_even_if_the_public_lookup_would_approve_it(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ), 'hint_transaction_ids' => array( 'TX-FOREIGN' ) ) ) );
		WompiFixtures::mock_api( array( 'TX-FOREIGN' => $this->tx( $o, 'TX-FOREIGN', 'APPROVED' ) ) ); // La consulta pública global la daría por buena.
		WompiFixtures::mock_by_reference( array() ); // Pero nuestro comercio no tiene ninguna con esa referencia.
		$this->assertSame( 'nothing', Reconciler::reconcile( $o->get_id() ) );
		$this->assertSame( 'pending', StateMachine::stored_status( $o->get_id() ) );
	}

	public function test_without_a_usable_private_key_nothing_is_granted_and_one_alert_per_hour_is_raised(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		WompiFixtures::mock_by_reference( array( $o->get_id() . '-1' => 401 ) );
		$this->assertSame( 'api_unavailable', Reconciler::reconcile( $o->get_id() ) );
		$this->assertSame( 'api_unavailable', Reconciler::reconcile( $o->get_id() ) );
		$this->assertSame( 'pending', StateMachine::stored_status( $o->get_id() ) );
		$this->assertCount( 1, array_filter( $this->alerts, static fn( $a ) => 'payment_mismatch' === $a[0] ), 'Una sola alerta por hora' );
		WompiFixtures::mock_by_reference( array( $o->get_id() . '-1' => 'down' ) );
		$this->assertSame( 'api_down', Reconciler::reconcile( $o->get_id() ), 'API caída: no se sabe, no se concede' ); // Fallo de red: es global (el barrido se cortaría).
	}

	public function test_a_payment_for_a_cancelled_order_within_a_day_is_settled_late(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		$this->cancel( $o );
		$this->assertSame( 'cancelled', StateMachine::stored_status( $o->get_id() ) );
		WompiFixtures::mock_by_reference( array( $o->get_id() . '-1' => array( $this->tx( $o, 'TX-LATE', 'APPROVED' ) ) ) );
		$this->assertSame( 'late_processing', Reconciler::reconcile( $o->get_id() ) );
		$this->assertSame( 'processing', StateMachine::stored_status( $o->get_id() ) );
	}

	public function test_an_approved_payment_older_than_a_day_is_only_alerted_once_and_not_applied(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ), 2 * DAY_IN_SECONDS );
		$this->cancel( $o );
		WompiFixtures::mock_by_reference( array( $o->get_id() . '-1' => array( $this->tx( $o, 'TX-OLD', 'APPROVED' ) ) ) );
		$this->assertSame( 'old_alerted', Reconciler::reconcile( $o->get_id() ) );
		Reconciler::reconcile( $o->get_id() );
		$this->assertSame( 'cancelled', StateMachine::stored_status( $o->get_id() ) );
		$this->assertCount( 1, array_filter( $this->alerts, static fn( $a ) => 'late_payment' === $a[0] ), 'Alerta una vez por pedido' );
	}

	public function test_wrong_amount_currency_or_reference_never_counts(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		$ref = $o->get_id() . '-1';
		WompiFixtures::mock_by_reference(
			array(
				$ref => array(
					array( 'TX-LOW', 'APPROVED', 100, 'COP', $ref ),
					array( 'TX-USD', 'APPROVED', 3500000, 'USD', $ref ),
					array( 'TX-REF', 'APPROVED', 3500000, 'COP', '999-1' ),
				),
			)
		);
		$this->assertSame( 'nothing', Reconciler::reconcile( $o->get_id() ) );
		$this->assertSame( 'pending', StateMachine::stored_status( $o->get_id() ) );
	}

	public function test_a_pending_transaction_is_recorded_without_changing_the_order_and_a_second_approval_is_a_double_charge(): void {
		$o = $this->order(
			array(
				array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ),
				array( 'n' => 2, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ),
			)
		);
		WompiFixtures::mock_by_reference( array( $o->get_id() . '-1' => array( $this->tx( $o, 'TX-P', 'PENDING' ) ) ) );
		$this->assertSame( 'pending_recorded', Reconciler::reconcile( $o->get_id() ) );
		$this->assertSame( 'pending', StateMachine::stored_status( $o->get_id() ) );
		$this->assertSame( 'nothing', Reconciler::reconcile( $o->get_id() ), 'Ya anotada: no se repite' );

		WompiFixtures::mock_by_reference( array( $o->get_id() . '-2' => array( $this->tx( $o, 'TX-Q', 'APPROVED', 2 ) ) ) );
		WompiFixtures::mock_api( array( 'TX-P' => array( 'APPROVED', 3500000, 'COP', $o->get_id() . '-1' ) ) ); // El id ya firmado se consulta por id.
		$result = Reconciler::reconcile( $o->get_id() );
		$this->assertStringContainsString( 'to_processing', $result );
		$this->assertSame( 'processing', StateMachine::stored_status( $o->get_id() ) );
		$this->assertContains( 'duplicate_charge', array_column( $this->alerts, 0 ), 'La segunda aprobación de otro intento es un doble cobro' );
	}

	public function test_the_sweep_reconciles_recent_orders_and_alerts_deferred_events_once(): void {
		global $wpdb;
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		WompiFixtures::mock_by_reference( array( $o->get_id() . '-1' => array( $this->tx( $o, 'TX-S', 'APPROVED' ) ) ) );
		$d = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			PaymentEvents::table(),
			array(
				'transaction_id'  => 'TX-D',
				'reference'       => $d->get_id() . '-1',
				'status_received' => 'APPROVED',
				'amount_in_cents' => 3500000,
				'currency'        => 'COP',
				'checksum_valid'  => 1,
				'order_id'        => $d->get_id(),
				'action_taken'    => 'deferred',
				'received_at'     => gmdate( 'Y-m-d H:i:s', time() - 1200 ),
			)
		);
		Reconciler::sweep();
		Reconciler::sweep();
		$this->assertSame( 'processing', StateMachine::stored_status( $o->get_id() ), 'El barrido aplicó el pago' );
		$this->assertCount( 1, array_filter( $this->alerts, static fn( $a ) => 'deferred_unresolved' === $a[0] && str_contains( $a[1], (string) $d->get_id() ) ), 'Evento diferido > 15 min: una alerta por pedido' );
		$wpdb->delete( PaymentEvents::table(), array( 'transaction_id' => 'TX-D' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_the_immediate_hook_and_the_recurring_sweep_are_registered(): void {
		$this->assertNotFalse( has_action( 'aralia_reconcile_order', array( Reconciler::class, 'reconcile_order' ) ) );
		$this->assertNotFalse( has_action( Reconciler::SWEEP_HOOK, array( Reconciler::class, 'sweep' ) ) );
		Reconciler::schedule();
		$this->assertNotFalse( as_has_scheduled_action( Reconciler::SWEEP_HOOK, array(), Reconciler::GROUP ) );
	}

	public function test_one_order_that_throws_does_not_stop_the_sweep_and_raises_one_alert(): void {
		$a = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		$b = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		WompiFixtures::mock_by_reference(
			array(
				$a->get_id() . '-1' => array( $this->tx( $a, 'TX-BOOM', 'APPROVED' ) ),
				$b->get_id() . '-1' => array( $this->tx( $b, 'TX-OK', 'APPROVED' ) ),
			)
		);
		$boom = $a->get_id();
		add_action(
			'woocommerce_payment_complete',
			static function ( $id ) use ( $boom ): void {
				if ( (int) $id === $boom ) {
					throw new \TypeError( 'hook de tercero' ); // WooCommerce captura \Exception en payment_complete, no \Error.
				}
			}
		);
		Reconciler::sweep();
		Reconciler::sweep();
		$this->assertSame( 'processing', StateMachine::stored_status( $b->get_id() ), 'El otro pedido se concilió' );
		$this->assertCount( 1, array_filter( $this->alerts, static fn( $x ) => 'payment_mismatch' === $x[0] && str_contains( $x[1], (string) $boom ) ), 'Una alerta por hora por pedido' );
	}

	public function test_an_approved_attempt_noted_but_never_applied_is_repaired_for_pending_and_cancelled_orders(): void {
		foreach ( array( 'pending', 'cancelled' ) as $state ) {
			$o = $this->order( array( array( 'n' => 1, 'status' => 'APPROVED', 'transaction_id' => 'TX-HALF-' . $state, 'at' => gmdate( 'c' ) ) ) );
			if ( 'cancelled' === $state ) {
				$this->cancel( $o );
			}
			WompiFixtures::mock_api( array( 'TX-HALF-' . $state => array( 'APPROVED', 3500000, 'COP', $o->get_id() . '-1' ) ) );
			$this->assertNotSame( 'nothing', Reconciler::reconcile( $o->get_id() ), $state );
			$this->assertSame( 'processing', StateMachine::stored_status( $o->get_id() ), 'El proceso cayó tras anotar y antes de aplicar: la conciliación lo repara (' . $state . ')' );
			remove_all_filters( 'pre_http_request' );
		}
	}

	public function test_a_stale_pending_never_downgrades_an_attempt_the_event_already_paid(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		WompiFixtures::mock_by_reference( array( $o->get_id() . '-1' => array( $this->tx( $o, 'TX-RACE', 'PENDING' ) ) ) );
		$id = $o->get_id();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $id ) {
				if ( false !== strpos( (string) $url, 'transactions?reference=' ) ) {
					// Mientras se consulta la lista, el evento firmado paga el pedido.
					$f = \Aralia\Orders\OrderStore::fresh( $id );
					StateMachine::run_as(
						StateMachine::PAYMENT,
						static function () use ( $f ): void {
							$f->payment_complete( 'TX-RACE' );
						}
					);
				}
				return $pre;
			},
			5,
			3
		);
		Reconciler::reconcile( $id );
		$this->assertSame( 'processing', StateMachine::stored_status( $id ) );
		$this->assertNotSame( 'PENDING', ( OrderStore::fresh( $id )->get_meta( '_ara_attempts', true )[0]['status'] ?? '' ), 'El PENDING viejo no rebaja nada' );
	}

	public function test_the_immediate_path_has_a_cooldown_and_the_sweep_backs_off_per_order(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ), 2 * HOUR_IN_SECONDS );
		WompiFixtures::mock_by_reference( array() );
		Reconciler::reconcile_order( $o->get_id() );
		Reconciler::reconcile_order( $o->get_id() );
		$this->assertCount( 1, WompiFixtures::$requests, 'Enfriamiento de 60 s por pedido' );
		WompiFixtures::$requests = array();
		Reconciler::sweep(); // Se revisó hace segundos y tiene > 1 h: toca cada 30 min.
		$this->assertCount( 0, WompiFixtures::$requests, 'Backoff por antigüedad del pedido' );
	}

	public function test_a_failing_order_does_not_block_the_sweep_for_the_rest_and_a_server_error_alerts_once(): void {
		$b = $this->order( array( array( 'n' => 1, 'status' => 'APPROVED', 'transaction_id' => 'TX-SIGNED', 'at' => gmdate( 'c' ) ) ) );
		$a = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) ); // El más reciente: va primero y su búsqueda por referencia falla.
		WompiFixtures::mock_by_reference( array( $a->get_id() . '-1' => 401 ) );
		WompiFixtures::mock_api( array( 'TX-SIGNED' => array( 'APPROVED', 3500000, 'COP', $b->get_id() . '-1' ) ) );
		Reconciler::sweep();
		Reconciler::sweep();
		$this->assertSame( 'processing', StateMachine::stored_status( $b->get_id() ), 'Un pedido sin llave/401 no frena el nivel de id de los demás' );
		$this->assertSame( 'pending', StateMachine::stored_status( $a->get_id() ), 'Y el suyo no se concede sin poder consultar nuestro comercio' );
		$this->assertCount( 1, array_filter( $this->alerts, static fn( $x ) => 'payment_mismatch' === $x[0] ), 'Una alerta por hora' );
	}

	public function test_the_network_failure_opens_the_breaker_but_an_exhausted_budget_neither_alerts_nor_marks_the_order(): void {
		$a = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		$b = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		WompiFixtures::mock_by_reference( array( $a->get_id() . '-1' => 'down', $b->get_id() . '-1' => 'down' ) );
		Reconciler::sweep();
		$this->assertCount( 1, WompiFixtures::$requests, 'Fallo de red: el resto fallaría igual, no se consulta' );

		$c = $this->order( array( array( 'n' => 1, 'status' => 'APPROVED', 'transaction_id' => 'TX-T', 'at' => gmdate( 'c' ) ) ) );
		$this->alerts = array();
		$this->assertSame( 'budget', Reconciler::reconcile( $c->get_id(), time() - 1 ) );
		$this->assertSame( array(), $this->alerts, 'Agotar el tiempo no es un fallo de Wompi' );
		$this->assertSame( '', (string) OrderStore::fresh( $c->get_id() )->get_meta( '_ara_recon_at', true ), 'Y no se marca como revisado' );
	}

	public function test_a_declined_signed_transaction_is_recorded_once(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'PENDING', 'transaction_id' => 'TX-DEC', 'at' => gmdate( 'c' ) ) ) );
		WompiFixtures::mock_api( array( 'TX-DEC' => array( 'DECLINED', 3500000, 'COP', $o->get_id() . '-1' ) ) );
		$this->assertSame( 'declined_recorded', Reconciler::reconcile( $o->get_id() ) );
		$this->assertSame( 'DECLINED', OrderStore::fresh( $o->get_id() )->get_meta( '_ara_attempts', true )[0]['status'] );
		$this->assertSame( 'nothing', Reconciler::reconcile( $o->get_id() ), 'Terminal: no se vuelve a consultar ni a anotar' );
	}

	public function test_a_busy_lock_defers_the_payment_to_the_next_run(): void {
		global $wpdb;
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		WompiFixtures::mock_by_reference( array( $o->get_id() . '-1' => array( $this->tx( $o, 'TX-BUSY', 'APPROVED' ) ) ) );
		$key = 'aralia_exp_lock_' . $o->get_id();
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, time() . ':otro' ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'locked', Reconciler::reconcile( $o->get_id() ) );
		$this->assertSame( 'pending', StateMachine::stored_status( $o->get_id() ) );
		$this->assertSame( '', (string) OrderStore::fresh( $o->get_id() )->get_meta( '_ara_recon_at', true ), 'Con el candado ocupado no se marca como revisado' );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( $key, 'options' );
		$this->assertSame( 'to_processing', Reconciler::reconcile( $o->get_id() ) );
	}

	public function test_other_currency_or_method_orders_are_skipped_and_by_reference_rejects_bad_references(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		$o->set_payment_method( 'cod' );
		$o->save();
		$this->assertSame( 'skip', Reconciler::reconcile( $o->get_id() ) );
		$client = new \Aralia\Guard\WompiClient( 'test' );
		$this->assertSame( array(), $client->by_reference( "12-1\n" ), 'El salto de línea final no pasa' );
		$this->assertSame( array(), $client->by_reference( '../x' ) );
		WompiFixtures::mock_by_reference( array() );
		$this->assertSame( array(), WompiFixtures::$requests );
	}

	public function test_a_decline_recorded_by_the_sweep_is_not_counted_again_by_the_later_signed_event(): void {
		$o   = $this->order( array( array( 'n' => 1, 'status' => 'PENDING', 'transaction_id' => 'TX-PSE', 'at' => gmdate( 'c' ) ) ) );
		$ref = $o->get_id() . '-1';
		WompiFixtures::mock_api( array( 'TX-PSE' => array( 'DECLINED', 3500000, 'COP', $ref ) ) );
		$this->assertSame( 'declined_recorded', Reconciler::reconcile( $o->get_id() ) );
		$res = ( new \Aralia\Guard\EventHandler() )->handle( WompiFixtures::json( WompiFixtures::event( $ref, 'TX-PSE', 'DECLINED', 3500000 ) ) );
		$this->assertSame( 200, $res['status'] );
		$this->assertCount( 1, array_filter( $this->alerts, static fn( $x ) => 'payment_declines' === $x[0] ), 'Un solo rechazo contado y una sola alerta' );
	}

	public function test_a_voided_signed_transaction_on_a_pending_order_is_recorded_as_a_decline(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'PENDING', 'transaction_id' => 'TX-VOID', 'at' => gmdate( 'c' ) ) ) );
		WompiFixtures::mock_api( array( 'TX-VOID' => array( 'VOIDED', 3500000, 'COP', $o->get_id() . '-1' ) ) );
		$this->assertSame( 'declined_recorded', Reconciler::reconcile( $o->get_id() ) );
		$this->assertSame( 'pending', StateMachine::stored_status( $o->get_id() ), 'Anotar un rechazo no cambia el pedido' );
	}

	public function test_a_blocked_reference_search_skips_only_the_reference_level_for_the_rest_of_the_sweep(): void {
		$b = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		$a = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		WompiFixtures::mock_by_reference( array( $a->get_id() . '-1' => 401, $b->get_id() . '-1' => array( $this->tx( $b, 'TX-X', 'APPROVED' ) ) ) );
		Reconciler::sweep();
		$this->assertCount( 1, WompiFixtures::$requests, 'Tras el 401 el segundo pedido no consulta por referencia' );
		$this->assertSame( 'pending', StateMachine::stored_status( $b->get_id() ) );
	}

	public function test_an_http_error_on_one_signed_id_does_not_stop_the_next_order_but_a_429_opens_the_breaker(): void {
		$b = $this->order( array( array( 'n' => 1, 'status' => 'APPROVED', 'transaction_id' => 'TX-GOOD', 'at' => gmdate( 'c' ) ) ) );
		$a = $this->order( array( array( 'n' => 1, 'status' => 'PENDING', 'transaction_id' => 'TX-422', 'at' => gmdate( 'c' ) ) ) );
		WompiFixtures::mock_api( array( 'TX-422' => 422, 'TX-GOOD' => array( 'APPROVED', 3500000, 'COP', $b->get_id() . '-1' ) ) );
		Reconciler::sweep();
		$this->assertSame( 'processing', StateMachine::stored_status( $b->get_id() ), 'Un error propio de un id no frena a los demás' );

		remove_all_filters( 'pre_http_request' );
		$c = $this->order( array( array( 'n' => 1, 'status' => 'PENDING', 'transaction_id' => 'TX-OK2', 'at' => gmdate( 'c' ) ) ) );
		$d = $this->order( array( array( 'n' => 1, 'status' => 'PENDING', 'transaction_id' => 'TX-429', 'at' => gmdate( 'c' ) ) ) ); // El más reciente: va primero.
		WompiFixtures::mock_api( array( 'TX-429' => 429, 'TX-OK2' => array( 'PENDING', 3500000, 'COP', $c->get_id() . '-1' ) ) );
		Reconciler::sweep();
		$this->assertCount( 1, WompiFixtures::$requests, '429: fallo global, se corta el barrido' );
		unset( $d );
	}

	public function test_an_exception_marks_the_order_as_checked_and_alerts_are_capped(): void {
		$a = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		WompiFixtures::mock_by_reference( array( $a->get_id() . '-1' => array( $this->tx( $a, 'TX-BOOM2', 'APPROVED' ) ) ) );
		$boom = $a->get_id();
		add_action(
			'woocommerce_payment_complete',
			static function ( $id ) use ( $boom ): void {
				if ( (int) $id === $boom ) {
					throw new \TypeError( 'hook de tercero' );
				}
			}
		);
		Reconciler::sweep();
		$this->assertNotSame( '', (string) OrderStore::fresh( $a->get_id() )->get_meta( '_ara_recon_at', true ), 'Marcado como revisado: no ocupa siempre el primer puesto' );
	}

	public function test_an_alerted_old_payment_does_not_use_sweep_slots_or_queries(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ), 2 * DAY_IN_SECONDS );
		$f = OrderStore::fresh( $o->get_id() );
		$f->update_meta_data( '_ara_old_payment_alerted', 'yes' );
		$f->save();
		WompiFixtures::mock_by_reference( array() );
		Reconciler::sweep();
		$this->assertCount( 0, WompiFixtures::$requests );
		$this->assertSame( 'skip', Reconciler::reconcile( $o->get_id() ) );
	}

	public function test_at_most_ten_references_are_checked_and_those_with_hints_go_first(): void {
		$rows = array();
		for ( $n = 1; $n <= 12; $n++ ) {
			$rows[] = array( 'n' => $n, 'status' => 'OPENED', 'at' => gmdate( 'c' ) );
		}
		$rows[0]['hint_transaction_ids'] = array( 'H1' ); // El intento MÁS VIEJO tiene una pista.
		$o   = $this->order( $rows );
		WompiFixtures::mock_by_reference( array() );
		Reconciler::reconcile( $o->get_id() );
		$this->assertCount( 10, WompiFixtures::$requests );
		$this->assertStringContainsString( 'reference=' . $o->get_id() . '-1', WompiFixtures::$requests[0]['url'], 'Primero los intentos con pista' );
	}

	public function test_a_deferred_pending_event_already_noted_does_not_alert_but_an_unnoted_one_does(): void {
		global $wpdb;
		$noted   = $this->order( array( array( 'n' => 1, 'status' => 'PENDING', 'transaction_id' => 'TX-N', 'at' => gmdate( 'c' ) ) ) );
		$unnoted = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		foreach ( array( array( $noted, 'TX-N' ), array( $unnoted, 'TX-U' ) ) as $pair ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				PaymentEvents::table(),
				array(
					'transaction_id'  => $pair[1],
					'reference'       => $pair[0]->get_id() . '-1',
					'status_received' => 'PENDING',
					'amount_in_cents' => 3500000,
					'currency'        => 'COP',
					'checksum_valid'  => 1,
					'order_id'        => $pair[0]->get_id(),
					'action_taken'    => 'deferred',
					'received_at'     => gmdate( 'Y-m-d H:i:s', time() - 1200 ),
				)
			);
		}
		WompiFixtures::mock_by_reference( array() );
		WompiFixtures::mock_api( array( 'TX-N' => array( 'PENDING', 3500000, 'COP', $noted->get_id() . '-1' ) ) );
		Reconciler::sweep();
		$refs = array_column( array_filter( $this->alerts, static fn( $x ) => 'deferred_unresolved' === $x[0] ), 1 );
		$this->assertContains( 'pedido ' . $unnoted->get_id(), $refs );
		$this->assertNotContains( 'pedido ' . $noted->get_id(), $refs );
		$wpdb->query( 'DELETE FROM ' . PaymentEvents::table() . " WHERE transaction_id IN ('TX-N','TX-U')" ); // phpcs:ignore WordPress.DB
	}

	public function test_by_reference_maps_429_to_server_and_drops_rows_without_id(): void {
		$o = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		$ref = $o->get_id() . '-1';
		WompiFixtures::mock_by_reference( array( $ref => 429 ) );
		$client = new \Aralia\Guard\WompiClient( 'test' );
		$this->assertNull( $client->by_reference( $ref ) );
		$this->assertSame( 'server', $client->last_error );
		remove_all_filters( 'pre_http_request' );
		WompiFixtures::mock_by_reference( array( $ref => array( array( '', 'APPROVED', 3500000, 'COP', $ref ), array( 'T-1', 'APPROVED', 3500000, 'COP', $ref ) ) ) );
		$list = ( new \Aralia\Guard\WompiClient( 'test' ) )->by_reference( $ref );
		$this->assertCount( 1, $list );
		$this->assertSame( 'T-1', $list[0]['id'] );
	}

	public function test_what_was_already_found_is_applied_even_if_a_later_query_fails_and_the_order_rotates_to_the_end(): void {
		$o   = $this->order(
			array(
				array( 'n' => 1, 'status' => 'PENDING', 'transaction_id' => 'TX-OK', 'at' => gmdate( 'c' ) ),
				array( 'n' => 2, 'status' => 'PENDING', 'transaction_id' => 'TX-DOWN', 'at' => gmdate( 'c' ) ),
			)
		);
		// Se recorre del intento más nuevo al más viejo: el 2 falla (5xx) y corta; el 1 no llega a consultarse, así que el resultado es el corte.
		WompiFixtures::mock_api( array( 'TX-OK' => array( 'APPROVED', 3500000, 'COP', $o->get_id() . '-1' ), 'TX-DOWN' => 503 ) );
		$this->assertSame( 'api_down', Reconciler::reconcile( $o->get_id() ) );
		$this->assertNotSame( '', (string) OrderStore::fresh( $o->get_id() )->get_meta( '_ara_recon_at', true ), 'Tras un fallo de la API rota al final de la cola' );
	}

	public function test_a_second_rejected_transaction_with_the_same_reference_is_counted_again(): void {
		$o   = $this->order( array( array( 'n' => 1, 'status' => 'OPENED', 'at' => gmdate( 'c' ) ) ) );
		$ref = $o->get_id() . '-1';
		$h   = new \Aralia\Guard\EventHandler();
		WompiFixtures::mock_api( array( 'TX-A' => array( 'DECLINED', 3500000, 'COP', $ref ), 'TX-B' => array( 'DECLINED', 3500000, 'COP', $ref ) ) );
		$h->handle( WompiFixtures::json( WompiFixtures::event( $ref, 'TX-A', 'DECLINED', 3500000 ) ) );
		$h->handle( WompiFixtures::json( WompiFixtures::event( $ref, 'TX-B', 'DECLINED', 3500000 ) ) );
		$this->assertCount( 2, array_filter( $this->alerts, static fn( $x ) => 'payment_declines' === $x[0] ), 'Dos transacciones distintas, dos rechazos' );
	}
}
