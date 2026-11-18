<?php
/**
 * Tareas programadas de retención y limpieza.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Database\Migrator;
use Aralia\Security\AuditLog;
use Aralia\Security\RateLimiter;
use Aralia\Support\Maintenance;
use Aralia\Tests\Support\SecurityTestCase;

final class MaintenanceTest extends SecurityTestCase {

	protected function set_up() {
		parent::set_up();
		delete_transient( 'aralia_sec_maint_scheduled' );
	}

	protected function tear_down() {
		delete_transient( 'aralia_sec_maint_scheduled' );
		as_unschedule_all_actions( Maintenance::HOURLY );
		as_unschedule_all_actions( Maintenance::DAILY );
		parent::tear_down();
	}

	private function count_rows( string $short ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . Migrator::tables()[ $short ] . '`' ); // phpcs:ignore WordPress.DB
	}

	public function test_schedule_creates_both_recurring_actions_once(): void {
		$m = new Maintenance();
		$m->schedule();
		$m->schedule();
		$this->assertNotFalse( as_next_scheduled_action( Maintenance::HOURLY, array(), 'aralia' ) );
		$this->assertNotFalse( as_next_scheduled_action( Maintenance::DAILY, array(), 'aralia' ) );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => Maintenance::HOURLY, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ), 'Idempotente: una sola acción' );
	}

	public function test_register_hooks_handlers_and_scheduling(): void {
		$m = new Maintenance();
		$m->register();
		$this->assertNotFalse( has_action( Maintenance::HOURLY, array( $m, 'on_hourly' ) ) );
		$this->assertNotFalse( has_action( Maintenance::DAILY, array( $m, 'on_daily' ) ) );
		// Action Scheduler ya se inicializó: se programa de inmediato (si no, se engancha a action_scheduler_init).
		$this->assertTrue( did_action( 'action_scheduler_init' ) > 0 ? false !== as_next_scheduled_action( Maintenance::HOURLY, array(), 'aralia' ) : false !== has_action( 'action_scheduler_init', array( $m, 'schedule' ) ) );
		remove_action( Maintenance::HOURLY, array( $m, 'on_hourly' ) );
		remove_action( Maintenance::DAILY, array( $m, 'on_daily' ) );
	}

	public function test_hourly_purges_old_rate_limit_windows_but_keeps_current_ones(): void {
		global $wpdb;
		$rl = new RateLimiter();
		$rl->hit( $this->crypto()->key_hash( 't', 'viejo' ), 'pay', 3600 );
		$rl->hit( $this->crypto()->key_hash( 't', 'vigente' ), 'pay', 3600 );
		$wpdb->query( $wpdb->prepare( 'UPDATE `' . Migrator::tables()['rate_limits'] . '` SET window_start = UTC_TIMESTAMP() - INTERVAL 9 DAY WHERE key_hash = %s', $this->crypto()->key_hash( 't', 'viejo' ) ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 1, ( new Maintenance() )->hourly() );
		$this->assertSame( 1, $this->count_rows( 'rate_limits' ) );
	}

	public function test_a_window_of_the_maximum_length_is_never_purged_before_it_expires(): void {
		global $wpdb;
		$rl = new RateLimiter();
		$k  = $this->crypto()->key_hash( 't', 'larga' );
		$rl->hit( $k, 'pay', 7 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'UPDATE `' . Migrator::tables()['rate_limits'] . '` SET window_start = UTC_TIMESTAMP() - INTERVAL 3 DAY WHERE key_hash = %s', $k ) ); // phpcs:ignore WordPress.DB
		( new Maintenance() )->hourly();
		$this->assertSame( 1, $rl->count( $k, 'pay', 7 * DAY_IN_SECONDS ), 'La ventana de 7 días con 3 días de antigüedad sigue vigente' );
	}

	public function test_purge_works_in_batches(): void {
		global $wpdb;
		$t = Migrator::tables()['rate_limits'];
		for ( $i = 0; $i < 25; $i++ ) {
			$wpdb->insert( $t, array( 'key_hash' => hash( 'sha256', "k$i" ), 'bucket' => 'b', 'count' => 1, 'window_start' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		}
		$this->assertSame( 25, ( new RateLimiter() )->purge_expired( 7 * DAY_IN_SECONDS, 10, 5 ), 'Varios lotes de 10 hasta vaciar' );
		for ( $i = 0; $i < 25; $i++ ) {
			$wpdb->insert( $t, array( 'key_hash' => hash( 'sha256', "j$i" ), 'bucket' => 'b', 'count' => 1, 'window_start' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		}
		$this->assertSame( 20, ( new RateLimiter() )->purge_expired( 7 * DAY_IN_SECONDS, 10, 2 ), 'El tope de lotes limita el trabajo por llamada' );
	}

	public function test_daily_keeps_activity_one_year_wipes_ip_after_90_days_and_purges_notified_alerts(): void {
		global $wpdb;
		$audit = new AuditLog( $this->crypto() );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$audit->record( 'login', 'user', 1, null, null, 1 );
		$audit->record( 'login', 'user', 2, null, null, 2 );
		$audit->record( 'login', 'user', 3, null, null, 3 );
		unset( $_SERVER['REMOTE_ADDR'] );
		$t = Migrator::tables()['audit_log'];
		$wpdb->query( "UPDATE `$t` SET `at` = UTC_TIMESTAMP() - INTERVAL 100 DAY WHERE object_id = 2" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "UPDATE `$t` SET `at` = UTC_TIMESTAMP() - INTERVAL 400 DAY WHERE object_id = 3" ); // phpcs:ignore WordPress.DB

		$stock = Migrator::tables()['stock_alerts'];
		$base  = array( 'product_id' => 1, 'variation_id' => 0, 'channel' => 'email', 'contact_enc' => 'x', 'consent_id' => 1, 'created_at' => '2026-01-01 00:00:00' );
		$wpdb->insert( $stock, array_merge( $base, array( 'subject_hash' => str_repeat( 'a', 64 ), 'notified_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ) ) ); // phpcs:ignore WordPress.DB
		$wpdb->insert( $stock, array_merge( $base, array( 'subject_hash' => str_repeat( 'b', 64 ), 'notified_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) ) ); // phpcs:ignore WordPress.DB
		$wpdb->insert( $stock, array_merge( $base, array( 'subject_hash' => str_repeat( 'c', 64 ), 'notified_at' => null ) ) ); // phpcs:ignore WordPress.DB

		$out = ( new Maintenance() )->daily();

		$this->assertSame( 1, $out['audit_purged'], 'Solo la de 400 días' );
		$this->assertSame( 2, $this->count_rows( 'audit_log' ) );
		$this->assertSame( 1, $out['audit_ip_wiped'], 'La de 100 días pierde la huella de IP' );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM `$t` WHERE object_id = 2 AND ip_hash = ''" ) ); // phpcs:ignore WordPress.DB
		$this->assertNotSame( '', $wpdb->get_var( "SELECT ip_hash FROM `$t` WHERE object_id = 1" ), 'La reciente conserva la IP' ); // phpcs:ignore WordPress.DB
		$this->assertSame( 1, $out['alerts_purged'], 'Avisos notificados hace más de 90 días' );
		$this->assertSame( 2, $this->count_rows( 'stock_alerts' ), 'Los no notificados y los recientes se quedan' );
	}

	public function test_the_12_hour_mark_is_only_set_when_both_recurring_actions_are_scheduled(): void {
		( new Maintenance() )->schedule();
		$this->assertNotFalse( get_transient( 'aralia_sec_maint_scheduled' ), 'Con ambas acciones programadas se pone la marca' );
		$this->assertNotFalse( as_next_scheduled_action( Maintenance::HOURLY, array(), 'aralia' ) );
		$this->assertNotFalse( as_next_scheduled_action( Maintenance::DAILY, array(), 'aralia' ) );
	}

	public function test_a_second_schedule_with_the_mark_set_does_nothing_and_a_cleared_mark_does_not_duplicate_the_actions(): void {
		$m = new Maintenance();
		$m->schedule();
		$m->schedule(); // La marca está puesta: no consulta ni programa.
		delete_transient( 'aralia_sec_maint_scheduled' );
		$m->schedule(); // Sin marca: as_has_scheduled_action evita duplicar.
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => Maintenance::HOURLY, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => Maintenance::DAILY, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	}
}
