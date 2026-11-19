<?php
/**
 * Estados, máquina de estados, stock al crear, stock parcial y vencimiento de pedidos (T053-T058).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Orders\Expiry;
use Aralia\Orders\PartialStock;
use Aralia\Orders\StateMachine;
use Aralia\Orders\Statuses;
use Aralia\Orders\StockOnCreate;
use Aralia\Support\Clock;
use Aralia\Tests\Support\IsolatedTestCase;

/**
 * Con WooCommerce real: el cambio de estado se bloquea ANTES de llegar a la base de datos.
 */
final class OrderStateStockTest extends IsolatedTestCase {

	/**
	 * Pedidos y productos creados (se borran al final).
	 *
	 * @var int[]
	 */
	private array $orders = array();

	/**
	 * @var int[]
	 */
	private array $products = array();

	/**
	 * Alertas levantadas por el código bajo prueba.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $alerts = array();

	/**
	 * Usuarios creados (se borran al final).
	 *
	 * @var int[]
	 */
	private array $users = array();

	protected function set_up() {
		parent::set_up();
		// Las alertas reales encolarían correos: aquí solo se anotan.
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
		Clock::reset();
		$this->alerts = array();
	}

	protected function tear_down() {
		Clock::reset();
		foreach ( $this->orders as $id ) {
			delete_option( StockOnCreate::TRASH_OPTION . $id );
			as_unschedule_all_actions( Expiry::HOOK, array( $id ), Expiry::GROUP );
			delete_option( 'aralia_exp_lock_' . $id );
			$o = wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
		foreach ( $this->products as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->users as $uid ) {
			wp_delete_user( $uid );
		}
		$this->orders   = array();
		$this->products = array();
		$this->users    = array();
		parent::tear_down();
	}

	private function user( string $role ): int {
		$id            = (int) wp_insert_user( array( 'user_login' => $role . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => $role . wp_rand() . '@example.com', 'role' => $role ) );
		$this->users[] = $id;
		return $id;
	}

	private function product( int $stock ): \WC_Product_Simple {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Pieza ' . wp_rand() );
		$p->set_sku( 'ST-' . strtoupper( substr( uniqid( '', true ), -8 ) ) . wp_rand( 10, 99 ) );
		$p->set_regular_price( '10000' );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $stock );
		$p->save();
		$this->products[] = $p->get_id();
		return $p;
	}

	private function order( \WC_Product $product, int $qty ): \WC_Order {
		$order = wc_create_order();
		$order->add_product( $product, $qty );
		$order->set_payment_method( 'wompi' );
		$order->update_meta_data( '_ara_attempts', array( array( 'n' => 1, 'reference' => '1-1', 'transaction_id' => 'tx-1', 'status' => 'PENDING' ) ) );
		$order->calculate_totals();
		$order->save();
		$this->orders[] = $order->get_id();
		return $order;
	}

	private function stock( \WC_Product $p ): int {
		return (int) wc_get_product( $p->get_id() )->get_stock_quantity();
	}

	private function status( \WC_Order $o ): string {
		return wc_get_order( $o->get_id() )->get_status();
	}

	private function as_actor( string $actor, callable $fn ) {
		return StateMachine::run_as( $actor, $fn );
	}

	public function test_custom_statuses_exist_and_paid_ones_count_as_paid(): void {
		$statuses = wc_get_order_statuses();
		foreach ( array_keys( Statuses::CUSTOM ) as $slug ) {
			$this->assertArrayHasKey( 'wc-' . $slug, $statuses );
		}
		$paid = wc_get_is_paid_statuses();
		foreach ( array( 'processing', 'ara-nostock', 'ara-packed', 'ara-shipped', 'completed' ) as $s ) {
			$this->assertContains( $s, $paid );
		}
		$this->assertNotContains( 'ara-reversed', $paid, 'Un pago revertido ya no es dinero cobrado' );
		$this->assertNotContains( 'refunded', $paid );
	}

	public function test_blocked_transition_never_reaches_the_database(): void {
		wp_set_current_user( 0 );
		$order  = $this->order( $this->product( 5 ), 1 );
		$events = array();
		add_action(
			'aralia_blocked_transition',
			function ( $id, $from, $to ) use ( &$events ) {
				$events[] = array( $id, $from, $to );
			},
			10,
			3
		);
		$changed = 0;
		add_action(
			'woocommerce_order_status_changed',
			function () use ( &$changed ) {
				++$changed;
			}
		);

		$order->set_status( 'processing' ); // Nadie con permiso ni el guard: marcar pagado a mano está prohibido.
		$order->save();

		$this->assertSame( 'pending', $this->status( $order ), 'El estado nuevo no se escribe' );
		$this->assertSame( 'pending', $order->get_status(), 'El objeto vuelve al estado anterior' );
		$this->assertSame( array( array( $order->get_id(), 'pending', 'processing' ) ), $events );
		$this->assertSame( 0, $changed, 'Ni ganchos ni correos de una transición que no ocurrió' );
		$this->assertContains( array( 'blocked_transition', 'pedido ' . $order->get_id() . ' pending a processing' ), $this->alerts );
	}

	public function test_payment_actor_can_mark_paid_and_nobody_can_walk_it_back(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		$this->as_actor(
			StateMachine::PAYMENT,
			static function () use ( $order ): void {
				$order->update_status( 'processing', 'Pago verificado.' );
			}
		);
		$this->assertSame( 'processing', $this->status( $order ) );

		// Después de pagado no hay vuelta atrás: un DECLINED posterior no lo cancela, ni siquiera como sistema.
		$fresh = wc_get_order( $order->get_id() );
		$this->as_actor(
			StateMachine::SYSTEM,
			static function () use ( $fresh ): void {
				$fresh->update_status( 'cancelled' );
			}
		);
		$this->assertSame( 'processing', $this->status( $order ) );
		$this->assertSame( 1, count( array_filter( $this->alerts, static fn( $a ) => 'blocked_transition' === $a[0] ) ) );
	}

	public function test_shop_manager_can_pack_but_not_mark_paid(): void {
		$uid = $this->user( 'shop_manager' );
		$order = $this->order( $this->product( 5 ), 1 );
		wp_set_current_user( $uid );
		$order->set_status( 'processing' );
		$order->save();
		$this->assertSame( 'pending', $this->status( $order ), 'Ni Sara marca un pedido como pagado: solo el guard' );

		$this->as_actor( StateMachine::PAYMENT, static fn() => wc_get_order( $order->get_id() )->update_status( 'processing' ) );
		$o = wc_get_order( $order->get_id() );
		$o->set_status( 'ara-packed' );
		$o->save();
		$this->assertSame( 'ara-packed', $this->status( $order ) );
		wp_set_current_user( 0 );
	}

	public function test_creation_reduces_stock_once_on_both_routes_and_schedules_expiry(): void {
		$product = $this->product( 5 );
		$order   = $this->order( $product, 2 );
		$before  = Clock::now();

		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );
		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order->get_id() ) );

		$this->assertSame( 3, $this->stock( $product ), 'Se descuenta una sola vez aunque disparen las dos rutas' );
		$expires = (int) wc_get_order( $order->get_id() )->get_meta( '_ara_expires_at', true );
		$this->assertEqualsWithDelta( $before + StockOnCreate::HOLD_SECONDS, $expires, 5 );
		$this->assertNotFalse( as_next_scheduled_action( Expiry::HOOK, array( $order->get_id() ), Expiry::GROUP ) );
	}

	public function test_expiry_cancels_after_declined_and_returns_stock(): void {
		$product = $this->product( 5 );
		$order   = $this->order( $product, 2 );
		StockOnCreate::handle( $order );
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );

		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $order->get_id() );

		$this->assertSame( 'cancelled', $this->status( $order ) );
		$this->assertSame( 5, $this->stock( $product ), 'El stock vuelve' );
	}

	public function test_expiry_does_nothing_before_the_time(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		StockOnCreate::handle( $order );
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
		Clock::advance( 10 * MINUTE_IN_SECONDS );
		Expiry::run( $order->get_id() );
		$this->assertSame( 'pending', $this->status( $order ) );
	}

	public function test_expiry_extends_when_wompi_says_pending_up_to_the_two_hour_cap(): void {
		$product = $this->product( 5 );
		$order   = $this->order( $product, 1 );
		StockOnCreate::handle( $order );
		add_filter( 'aralia_wompi_lookup', static fn() => 'PENDING' );

		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $order->get_id() );
		$this->assertSame( 'pending', $this->status( $order ) );
		$expires = (int) wc_get_order( $order->get_id() )->get_meta( '_ara_expires_at', true );
		$this->assertEqualsWithDelta( Clock::now() + 15 * MINUTE_IN_SECONDS, $expires, 5 );

		// Pasadas las 2 h desde la creación, aunque Wompi siga diciendo PENDING, se cancela.
		Clock::advance( 2 * HOUR_IN_SECONDS );
		Expiry::run( $order->get_id() );
		$this->assertSame( 'cancelled', $this->status( $order ) );
		$this->assertSame( 5, $this->stock( $product ) );
	}

	public function test_expiry_does_not_cancel_when_wompi_says_approved_or_is_unreachable(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		StockOnCreate::handle( $order );

		add_filter( 'aralia_wompi_lookup', static fn() => 'APPROVED' );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $order->get_id() );
		$this->assertSame( 'pending', $this->status( $order ), 'Un pago aprobado no se cancela por vencimiento: lo resuelve la conciliación' );

		remove_all_filters( 'aralia_wompi_lookup' );
		add_filter(
			'aralia_wompi_lookup',
			static function () {
				throw new \RuntimeException( 'API caída' );
			}
		);
		Clock::advance( 10 * MINUTE_IN_SECONDS );
		Expiry::run( $order->get_id() );
		$this->assertSame( 'pending', $this->status( $order ), 'Sin respuesta de Wompi se reintenta, no se cancela' );
	}

	public function test_expiry_is_idempotent_under_a_lock(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		StockOnCreate::handle( $order );
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
		Clock::advance( 31 * MINUTE_IN_SECONDS );

		add_option( 'aralia_exp_lock_' . $order->get_id(), time(), '', false ); // Otro proceso lo está atendiendo.
		Expiry::run( $order->get_id() );
		$this->assertSame( 'pending', $this->status( $order ) );

		delete_option( 'aralia_exp_lock_' . $order->get_id() );
		Expiry::run( $order->get_id() );
		Expiry::run( $order->get_id() ); // Segunda ejecución: ya está cancelado, no hace nada.
		$this->assertSame( 'cancelled', $this->status( $order ) );
		$this->assertFalse( get_option( 'aralia_exp_lock_' . $order->get_id() ), 'El candado se suelta' );
	}

	public function test_widget_extension_happens_once_and_never_beyond_35_minutes(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		StockOnCreate::handle( $order );
		$order = wc_get_order( $order->get_id() );

		Clock::advance( 28 * MINUTE_IN_SECONDS );
		$this->assertTrue( Expiry::extend_for_widget( $order ) );
		$created = $order->get_date_created()->getTimestamp();
		$this->assertLessThanOrEqual( $created + 35 * MINUTE_IN_SECONDS, (int) wc_get_order( $order->get_id() )->get_meta( '_ara_expires_at', true ) );
		$this->assertFalse( Expiry::extend_for_widget( wc_get_order( $order->get_id() ) ), 'Solo una vez' );
	}

	public function test_native_unpaid_cancellation_skips_wompi_orders(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		$this->assertFalse( apply_filters( 'woocommerce_cancel_unpaid_order', true, $order ) );
		$order->set_payment_method( 'bacs' );
		$this->assertTrue( apply_filters( 'woocommerce_cancel_unpaid_order', true, $order ) );
	}

	public function test_watchdog_alerts_only_when_expiry_is_more_than_5_minutes_late(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		$order->update_meta_data( '_ara_expires_at', Clock::now() - 3 * MINUTE_IN_SECONDS );
		$order->save();
		$this->assertFalse( Expiry::watch(), '3 minutos de atraso todavía es normal' );

		$order->update_meta_data( '_ara_expires_at', Clock::now() - 6 * MINUTE_IN_SECONDS );
		$order->save();
		$this->assertTrue( Expiry::watch() );
		$this->assertSame( 'expiry_late', $this->alerts[0][0] );
	}

	public function test_late_approval_with_stock_returns_to_processing_and_reduces_again(): void {
		$product = $this->product( 5 );
		$order   = $this->order( $product, 2 );
		StockOnCreate::handle( $order );
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $order->get_id() );
		$this->assertSame( 5, $this->stock( $product ) );

		$status = PartialStock::settle_late( wc_get_order( $order->get_id() ) );

		$this->assertSame( 'processing', $status );
		$this->assertSame( 3, $this->stock( $product ) );
		$this->assertSame( array(), $this->alerts );
	}

	public function test_late_approval_without_stock_goes_to_nostock_and_marks_the_missing_line(): void {
		$product = $this->product( 5 );
		$order   = $this->order( $product, 2 );
		StockOnCreate::handle( $order );
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $order->get_id() );
		wc_update_product_stock( $product, 1, 'set' ); // Mientras tanto se vendió casi todo.

		$status = PartialStock::settle_late( wc_get_order( $order->get_id() ) );

		$this->assertSame( 'ara-nostock', $status );
		$this->assertSame( 1, $this->stock( $product ), 'No se deja el stock en negativo' );
		$fresh = wc_get_order( $order->get_id() );
		$items = array_values( $fresh->get_items() );
		$this->assertSame( '1', (string) $items[0]->get_meta( '_ara_missing', true ) );
		$this->assertContains( array( 'nostock', 'pedido ' . $order->get_id() ), $this->alerts );
	}

	public function test_partial_stock_reduces_available_lines_only_and_is_idempotent(): void {
		$ok      = $this->product( 5 );
		$short   = $this->product( 0 );
		$order   = wc_create_order();
		$order->add_product( $ok, 2 );
		$order->add_product( $short, 1 );
		$order->save();
		$this->orders[] = $order->get_id();

		$missing = PartialStock::reduce( wc_get_order( $order->get_id() ) );
		$this->assertCount( 1, $missing );
		$this->assertSame( 3, $this->stock( $ok ) );
		$this->assertSame( 0, $this->stock( $short ) );

		PartialStock::reduce( wc_get_order( $order->get_id() ) ); // Repetir no vuelve a descontar la línea que ya salió.
		$this->assertSame( 3, $this->stock( $ok ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->get_data_store()->get_stock_reduced( $order->get_id() ) );
	}

	public function test_new_order_cannot_be_born_paid(): void {
		wp_set_current_user( 0 );
		$order = new \WC_Order();
		$order->set_status( 'processing' ); // POST wc/v3/orders {"status":"processing"} o new WC_Order().
		$order->save();
		$this->orders[] = $order->get_id();
		$this->assertSame( 'pending', $this->status( $order ), 'Un pedido nuevo no nace pagado' );
		$this->assertContains( 'blocked_transition', array_column( $this->alerts, 0 ) );
	}

	public function test_store_api_draft_flow_is_allowed_for_anonymous_customers(): void {
		wp_set_current_user( 0 );
		$order = new \WC_Order();
		$order->set_status( 'checkout-draft' );
		$order->save();
		$this->orders[] = $order->get_id();
		$this->assertSame( 'checkout-draft', $this->status( $order ) );
		$order->update_status( 'pending' ); // Lo hace la Store API en la petición de la clienta.
		$this->assertSame( 'pending', $this->status( $order ) );
		$this->assertSame( array(), $this->alerts, 'Sin alertas en una compra normal' );
	}

	public function test_payment_complete_without_the_guard_leaves_no_trace(): void {
		wp_set_current_user( 0 );
		$order = $this->order( $this->product( 5 ), 1 );
		$paid  = 0;
		add_action(
			'woocommerce_payment_complete',
			function () use ( &$paid ) {
				++$paid;
			}
		);
		$order->payment_complete( 'tx-evil' );
		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $fresh->get_status() );
		$this->assertNull( $fresh->get_date_paid() );
		$this->assertSame( 0, $paid, 'Ni analítica ni correos de un pago que no se verificó' );

		$this->as_actor( StateMachine::PAYMENT, static fn() => wc_get_order( $order->get_id() )->payment_complete( 'tx-1' ) );
		$this->assertSame( 'processing', $this->status( $order ) );
	}

	public function test_guard_reads_the_stored_status_not_a_stale_copy(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		$stale = wc_get_order( $order->get_id() ); // Expiry cargó el pedido pendiente...
		$this->as_actor( StateMachine::PAYMENT, static fn() => wc_get_order( $order->get_id() )->update_status( 'processing' ) ); // ...y el webhook lo pagó.
		$this->as_actor( StateMachine::SYSTEM, static fn() => $stale->update_status( 'cancelled' ) );
		$this->assertSame( 'processing', $this->status( $order ), 'Un pedido pagado no se cancela por una copia vieja' );
	}

	public function test_customer_cancel_link_is_removed(): void {
		$this->assertSame( array(), apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending', 'failed' ), null ) );
	}

	public function test_trashing_a_pending_order_cancels_it_and_returns_stock(): void {
		$product = $this->product( 5 );
		$order   = $this->order( $product, 2 );
		StockOnCreate::handle( $order );
		$this->assertSame( 3, $this->stock( $product ) );
		wp_set_current_user( $this->user( 'administrator' ) );
		wc_get_order( $order->get_id() )->delete( false );
		$this->assertSame( 5, $this->stock( $product ), 'La papelera devuelve el stock de un pedido sin pagar' );
		$this->assertSame( 'trash', StateMachine::stored_status( $order->get_id() ) );
		$this->assertSame( 'cancelled', get_option( StockOnCreate::TRASH_OPTION . $order->get_id() ), 'Anota el estado real con el que entra en la papelera' );

		// Restaurar de verdad (almacén de WooCommerce): vuelve a cancelled, sin alertas, y la anotación se limpia.
		$this->alerts = array();
		$restore      = wc_get_order( $order->get_id() );
		$restore->get_data_store()->untrash_order( $restore );
		$this->assertSame( 'cancelled', StateMachine::stored_status( $order->get_id() ) );
		$this->assertNotContains( 'blocked_transition', array_column( $this->alerts, 0 ) );
		$this->assertFalse( get_option( StockOnCreate::TRASH_OPTION . $order->get_id() ), 'La anotación se limpia al restaurar' );
	}

	public function test_a_paid_order_trashed_and_restored_returns_paid_with_its_dates_and_no_alert(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		$this->as_actor( StateMachine::PAYMENT, static fn() => wc_get_order( $order->get_id() )->update_status( 'processing' ) );
		$this->assertNotNull( wc_get_order( $order->get_id() )->get_date_paid() );
		wp_set_current_user( $this->user( 'shop_manager' ) );
		wc_get_order( $order->get_id() )->delete( false );
		$this->assertSame( 'processing', get_option( StockOnCreate::TRASH_OPTION . $order->get_id() ) );
		$this->alerts = array();
		$restore      = wc_get_order( $order->get_id() );
		$restore->get_data_store()->untrash_order( $restore );
		$this->assertSame( 'processing', StateMachine::stored_status( $order->get_id() ) );
		$this->assertNotContains( 'blocked_transition', array_column( $this->alerts, 0 ) );
		$this->assertNotNull( wc_get_order( $order->get_id() )->get_date_paid(), 'No se pierde el rastro contable' );
	}

	public function test_a_trashed_order_cannot_be_restored_as_paid_even_with_forged_meta(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		wp_set_current_user( $this->user( 'shop_manager' ) );
		wc_get_order( $order->get_id() )->delete( false ); // A la papelera (se cancela antes).
		// Falsificado por wc/v3 meta_data: la restauración real (wp_untrash_post en el almacén por posts) tomaría este estado.
		update_post_meta( $order->get_id(), '_wp_trash_meta_status', 'wc-processing' );
		$this->alerts = array();
		wp_untrash_post( $order->get_id() );
		$this->assertNotContains( StateMachine::stored_status( $order->get_id() ), array( 'processing', 'ara-packed', 'ara-shipped', 'completed' ), 'Desde la papelera no se llega a pagado sin el guard' );
		$this->assertContains( 'blocked_transition', array_column( $this->alerts, 0 ) );
		$this->assertSame( 'cancelled', StateMachine::stored_status( $order->get_id() ), 'Vuelve a lo anotado (cancelado), no a lo que dice el meta falsificado' );
		// Un pedido cancelado tampoco se marca pagado sin el guard (la rama `trash` del guard con HPOS está en la deuda de T150).
		$again = wc_get_order( $order->get_id() );
		$again->set_status( 'processing' );
		$again->save();
		$this->assertSame( 'cancelled', StateMachine::stored_status( $order->get_id() ) );
	}

	public function test_pending_order_trashed_through_wordpress_comes_back_cancelled_not_pending(): void {
		$product = $this->product( 5 );
		$order   = $this->order( $product, 2 );
		StockOnCreate::handle( $order );
		wp_set_current_user( $this->user( 'shop_manager' ) );
		wp_trash_post( $order->get_id() ); // El listado clásico: WordPress anota `wc-pending` como estado previo.
		$this->assertSame( 5, $this->stock( $product ), 'La papelera devolvió el stock' );
		$this->alerts = array();
		wp_untrash_post( $order->get_id() );
		$this->assertSame( 'cancelled', StateMachine::stored_status( $order->get_id() ), 'No resucita como pendiente sin stock reservado' );
		$this->assertSame( array(), $this->alerts, 'No es un ataque: sin alerta' );
	}

	public function test_trash_restore_rule_without_and_with_a_record(): void {
		$id = $this->order( $this->product( 5 ), 1 )->get_id();
		$this->assertTrue( StateMachine::trash_restore_allowed( $id, 'pending' ), 'Sin anotación: solo no pagados' );
		$this->assertTrue( StateMachine::trash_restore_allowed( $id, 'cancelled' ) );
		$this->assertFalse( StateMachine::trash_restore_allowed( $id, 'processing' ) );
		update_option( StockOnCreate::TRASH_OPTION . $id, 'cancelled', false );
		$this->assertTrue( StateMachine::trash_restore_allowed( $id, 'cancelled' ) );
		$this->assertFalse( StateMachine::trash_restore_allowed( $id, 'pending' ), 'Con anotación solo vale lo anotado' );
		update_option( StockOnCreate::TRASH_OPTION . $id, 'processing', false );
		$this->assertTrue( StateMachine::trash_restore_allowed( $id, 'processing' ) );
		$this->assertFalse( StateMachine::trash_restore_allowed( $id, 'completed' ) );
		delete_option( StockOnCreate::TRASH_OPTION . $id );
	}

	public function test_paid_order_cannot_be_restored_to_a_different_paid_state(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		$this->as_actor( StateMachine::PAYMENT, static fn() => wc_get_order( $order->get_id() )->update_status( 'processing' ) );
		wp_set_current_user( $this->user( 'shop_manager' ) );
		wc_get_order( $order->get_id() )->delete( false );
		$this->assertTrue( StateMachine::trash_restore_allowed( $order->get_id(), 'processing' ) );
		$this->assertFalse( StateMachine::trash_restore_allowed( $order->get_id(), 'completed' ), 'Otro estado pagado falsificado no vale' );
		$this->assertFalse( StateMachine::trash_restore_allowed( $order->get_id(), 'ara-shipped' ) );
		$this->alerts = array();
		update_post_meta( $order->get_id(), '_wp_trash_meta_status', 'wc-completed' );
		wp_untrash_post( $order->get_id() );
		$this->assertSame( 'processing', StateMachine::stored_status( $order->get_id() ), 'Vuelve al estado pagado real, no al falsificado' );
		$this->assertContains( 'blocked_transition', array_column( $this->alerts, 0 ) );
	}

	public function test_rest_writes_cannot_plant_the_trash_meta(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		$order->update_meta_data( '_wp_trash_meta_status', 'wc-processing' );
		$order->update_meta_data( '_wp_trash_meta_time', '1' );
		$order = apply_filters( 'woocommerce_rest_pre_insert_shop_order_object', $order, null, false );
		$this->assertSame( '', (string) $order->get_meta( '_wp_trash_meta_status', true ) );
		$this->assertSame( '', (string) $order->get_meta( '_wp_trash_meta_time', true ) );
	}

	public function test_analytics_always_excludes_reversed_orders(): void {
		$statuses = apply_filters( 'woocommerce_analytics_excluded_order_statuses', array( 'auto-draft', 'trash' ) );
		$this->assertContains( 'ara-reversed', $statuses );
		$this->assertContains( 'trash', $statuses, 'Fusiona, no pisa' );
	}

	public function test_dashboard_order_saved_with_a_blocked_status_stays_visible_as_pending(): void {
		wp_set_current_user( 0 );
		$order = new \WC_Order();
		$order->set_status( 'auto-draft' );
		$order->save();
		$this->orders[] = $order->get_id();
		$this->assertSame( 'auto-draft', StateMachine::stored_status( $order->get_id() ) );
		$order->set_status( 'processing' );
		$order->save();
		$this->assertSame( 'pending', StateMachine::stored_status( $order->get_id() ), 'No se queda como auto-draft (lo borraría la limpieza)' );
	}

	public function test_unreadable_stored_status_falls_back_to_the_object_status_not_to_pending(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		$this->as_actor( StateMachine::PAYMENT, static fn() => wc_get_order( $order->get_id() )->update_status( 'processing' ) );
		$paid = wc_get_order( $order->get_id() );
		$cb = static fn( $q ) => 0 === strpos( $q, 'SELECT post_status FROM' ) || 0 === strpos( $q, 'SELECT status FROM' ) ? 'SELECT NULL' : $q;
		add_filter( 'query', $cb );
		try {
			$paid->set_status( 'cancelled' );
			$paid->save();
		} finally {
			remove_filter( 'query', $cb );
		}
		$this->assertContains( 'blocked_transition', array_column( $this->alerts, 0 ) );
		$this->assertSame( 'processing', StateMachine::stored_status( $order->get_id() ), 'Si la lectura falla, un pedido pagado no se pisa con pending' );
	}

	public function test_retrying_a_pending_order_that_already_deducted_stock_does_not_hold_it_again(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		$this->assertSame( 30, (int) apply_filters( 'woocommerce_order_hold_stock_minutes', 30, $order ) );
		StockOnCreate::handle( $order );
		$this->assertSame( 0, (int) apply_filters( 'woocommerce_order_hold_stock_minutes', 30, wc_get_order( $order->get_id() ) ) );
	}

	public function test_late_approval_on_a_refunded_order_touches_no_stock(): void {
		$product = $this->product( 5 );
		$order   = $this->order( $product, 2 );
		StockOnCreate::handle( $order );
		$this->as_actor( StateMachine::PAYMENT, static fn() => wc_get_order( $order->get_id() )->update_status( 'processing' ) );
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->as_actor( StateMachine::STAFF, static fn() => wc_get_order( $order->get_id() )->update_status( 'refunded' ) );
		$stock = $this->stock( $product );
		$this->assertSame( 'refunded', PartialStock::settle_late( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( $stock, $this->stock( $product ), 'No se descuenta stock de un pedido que no puede pasar a pagado' );
	}

	public function test_expired_hook_fires_once_when_the_order_is_cancelled(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		StockOnCreate::handle( $order );
		$fired = 0;
		add_action(
			'aralia_order_expired',
			function () use ( &$fired ) {
				++$fired;
			}
		);
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $order->get_id() );
		Expiry::run( $order->get_id() ); // Una segunda ejecución no vuelve a emitir.
		$this->assertSame( 1, $fired );
		$this->assertSame( 'cancelled', $this->status( $order ) );
	}

	public function test_expired_hook_does_not_fire_when_the_order_is_kept(): void {
		$fired = 0;
		add_action(
			'aralia_order_expired',
			function () use ( &$fired ) {
				++$fired;
			}
		);
		foreach ( array( 'APPROVED', 'PENDING' ) as $remote ) {
			remove_all_filters( 'aralia_wompi_lookup' );
			add_filter( 'aralia_wompi_lookup', static fn() => $remote );
			$order = $this->order( $this->product( 5 ), 1 );
			StockOnCreate::handle( $order );
			Clock::advance( 31 * MINUTE_IN_SECONDS );
			Expiry::run( $order->get_id() );
			$this->assertSame( 'pending', $this->status( $order ), $remote );
		}
		$this->assertSame( 0, $fired, 'APPROVED y PENDING no cancelan ni avisan de vencimiento' );
	}

	public function test_hard_delete_of_a_pending_order_returns_its_stock(): void {
		$product = $this->product( 5 );
		$order   = $this->order( $product, 2 );
		StockOnCreate::handle( $order );
		$this->assertSame( 3, $this->stock( $product ) );
		wc_get_order( $order->get_id() )->delete( true );
		$this->assertSame( 5, $this->stock( $product ) );
	}

	public function test_widget_extension_adds_five_minutes_and_never_shortens_a_longer_window(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		StockOnCreate::handle( $order );
		Clock::advance( 28 * MINUTE_IN_SECONDS );
		$this->assertTrue( Expiry::extend_for_widget( wc_get_order( $order->get_id() ) ) );
		$created = wc_get_order( $order->get_id() )->get_date_created()->getTimestamp();
		$this->assertEqualsWithDelta( $created + 33 * MINUTE_IN_SECONDS, (int) wc_get_order( $order->get_id() )->get_meta( '_ara_expires_at', true ), 5, 'Ahora + 5 min' );

		// Una ventana ya más larga (Wompi dijo PENDING) no se acorta.
		$second = $this->order( $this->product( 5 ), 1 );
		StockOnCreate::handle( $second );
		$o = wc_get_order( $second->get_id() );
		$o->update_meta_data( '_ara_expires_at', (string) ( Clock::now() + 60 * MINUTE_IN_SECONDS ) );
		$o->save();
		$this->assertFalse( Expiry::extend_for_widget( wc_get_order( $second->get_id() ) ) );
		$this->assertGreaterThan( Clock::now() + 50 * MINUTE_IN_SECONDS, (int) wc_get_order( $second->get_id() )->get_meta( '_ara_expires_at', true ) );
	}

	public function test_resuming_a_pending_order_returns_and_rededucts_stock_once(): void {
		$product = $this->product( 5 );
		$order   = $this->order( $product, 2 );
		StockOnCreate::handle( $order );
		do_action( 'woocommerce_resume_order', $order->get_id() ); // El checkout clásico reutiliza el pedido y le borra las líneas.
		$this->assertSame( 5, $this->stock( $product ) );
		StockOnCreate::handle( wc_get_order( $order->get_id() ) );
		$this->assertSame( 3, $this->stock( $product ), 'Vuelve a descontar una sola vez' );
	}

	public function test_expiry_lock_is_stolen_only_when_stale(): void {
		global $wpdb;
		$order = $this->order( $this->product( 5 ), 1 );
		StockOnCreate::handle( $order );
		add_filter( 'aralia_wompi_lookup', static fn() => 'DECLINED' );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		$key = 'aralia_exp_lock_' . $order->get_id();

		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, ( time() - 10 ) . ':otro' ) );
		Expiry::run( $order->get_id() );
		$this->assertSame( 'pending', $this->status( $order ), 'Candado reciente de otro proceso: no corre' );
		$this->assertNotFalse( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ), 'No borra el candado ajeno' );

		$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 121 ) . ':otro' ), array( 'option_name' => $key ) );
		Expiry::run( $order->get_id() );
		$this->assertSame( 'cancelled', $this->status( $order ), 'Candado vencido: se roba y corre' );
	}

	public function test_expiry_cancels_without_asking_when_there_is_no_transaction(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		$order->delete_meta_data( '_ara_attempts' );
		$order->save();
		StockOnCreate::handle( $order );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $order->get_id() ); // Sin consulta conectada y sin intentos con transacción: no hay nada que esperar.
		$this->assertSame( 'cancelled', $this->status( $order ) );
	}

	public function test_approved_but_unapplied_orders_raise_deferred_unresolved_once(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		StockOnCreate::handle( $order );
		add_filter( 'aralia_wompi_lookup', static fn() => 'APPROVED' );
		Clock::advance( 31 * MINUTE_IN_SECONDS );
		Expiry::run( $order->get_id() );
		$this->assertNotContains( 'deferred_unresolved', array_column( $this->alerts, 0 ) );
		Clock::advance( 16 * MINUTE_IN_SECONDS );
		Expiry::run( $order->get_id() );
		Clock::advance( 6 * MINUTE_IN_SECONDS );
		Expiry::run( $order->get_id() );
		$this->assertSame( 1, count( array_filter( $this->alerts, static fn( $a ) => 'deferred_unresolved' === $a[0] ) ), 'Una sola alerta por pedido' );
		$this->assertSame( 'pending', $this->status( $order ) );
	}

	public function test_widget_extension_never_shortens_and_is_refused_out_of_window(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		StockOnCreate::handle( $order );
		Clock::advance( 36 * MINUTE_IN_SECONDS );
		$this->assertFalse( Expiry::extend_for_widget( wc_get_order( $order->get_id() ) ), 'Fuera de los 35 min no extiende' );
		$this->assertNotSame( 'yes', wc_get_order( $order->get_id() )->get_meta( '_ara_widget_extended', true ) );
	}

	public function test_watchdog_reschedules_a_lost_action_and_flags_orders_stuck_over_two_hours(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		StockOnCreate::handle( $order );
		as_unschedule_all_actions( Expiry::HOOK, array( $order->get_id() ), Expiry::GROUP );
		$this->assertFalse( as_next_scheduled_action( Expiry::HOOK, array( $order->get_id() ), Expiry::GROUP ) );
		Expiry::watch();
		$this->assertNotFalse( as_next_scheduled_action( Expiry::HOOK, array( $order->get_id() ), Expiry::GROUP ), 'La vigilancia repara la acción perdida' );

		$this->alerts = array();
		Clock::advance( 2 * HOUR_IN_SECONDS + 10 * MINUTE_IN_SECONDS );
		$this->assertTrue( Expiry::watch(), 'Más de 2 h pendiente es una alerta aunque el vencimiento se haya aplazado' );
	}

	public function test_partial_stock_handles_unmanaged_backorder_and_empty_orders(): void {
		$free = new \WC_Product_Simple();
		$free->set_name( 'Sin gestión' );
		$free->set_regular_price( '1000' );
		$free->save();
		$this->products[] = $free->get_id();
		$back = $this->product( 1 );
		$back->set_backorders( 'yes' );
		$back->save();

		$order = wc_create_order();
		$order->add_product( $free, 3 );
		$order->add_product( $back, 2 );
		$order->save();
		$this->orders[] = $order->get_id();
		$this->assertSame( array(), PartialStock::reduce( wc_get_order( $order->get_id() ) ), 'Sin gestión y con backorders nada falta' );
		$this->assertSame( -1, $this->stock( $back ), 'Con backorders el stock puede quedar negativo' );

		$empty = wc_create_order();
		$empty->save();
		$this->orders[] = $empty->get_id();
		$this->assertSame( array(), PartialStock::reduce( wc_get_order( $empty->get_id() ) ) );
	}

	public function test_full_refund_from_the_shop_marks_refunded_under_staff(): void {
		$order = $this->order( $this->product( 5 ), 1 );
		$this->as_actor( StateMachine::PAYMENT, static fn() => wc_get_order( $order->get_id() )->update_status( 'processing' ) );
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$o      = wc_get_order( $order->get_id() );
		$refund = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => $o->get_total(), 'reason' => 'prueba' ) );
		$this->assertNotInstanceOf( \WP_Error::class, $refund );
		$this->assertSame( 'refunded', $this->status( $order ) );
	}
}
