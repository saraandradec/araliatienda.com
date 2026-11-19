<?php
/**
 * Tareas programadas de mantenimiento y retención (data-model §4, R-23).
 *
 * @package Aralia
 */

namespace Aralia\Support;

use Aralia\Database\Migrator;
use Aralia\Security\AuditLog;
use Aralia\Security\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * - Cada hora: purga de ventanas vencidas de `aralia_rate_limits` (por lotes).
 * - Cada día: registro de actividad (conserva 1 año; la huella de IP se borra a los 90 días) y avisos de stock notificados hace más de 90 días.
 * Pendientes (T139 la EXTIENDE; no crea otro sitio de retención): consentimientos (tratamiento + 5 años), lista anticipada, datos de regalos,
 * `customer_ip_address` y `client_id`/`fbp` de los pedidos a 90 días.
 */
class Maintenance {

	public const HOURLY = 'aralia_maintenance_hourly';
	public const DAILY  = 'aralia_maintenance_daily';

	/**
	 * Engancha los manejadores y programa las acciones recurrentes en Action Scheduler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOURLY, array( $this, 'on_hourly' ) );
		add_action( self::DAILY, array( $this, 'on_daily' ) );
		// Si Action Scheduler ya se inicializó cuando se registra esto, se programa ahora; si no, al iniciarse.
		if ( did_action( 'action_scheduler_init' ) ) {
			$this->schedule();
		} else {
			add_action( 'action_scheduler_init', array( $this, 'schedule' ) );
		}
	}

	/**
	 * Callback de la acción horaria.
	 *
	 * @return void
	 */
	public function on_hourly(): void {
		$this->hourly();
	}

	/**
	 * Callback de la acción diaria.
	 *
	 * @return void
	 */
	public function on_daily(): void {
		$this->daily();
	}

	/**
	 * Programa las acciones si no lo están (idempotente).
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		// Una marca de 12 h evita dos consultas a Action Scheduler en cada petición.
		if ( false !== get_transient( 'aralia_sec_maint_scheduled' ) ) {
			return;
		}
		$jobs   = array(
			self::HOURLY => HOUR_IN_SECONDS,
			self::DAILY  => DAY_IN_SECONDS,
		);
		$all_ok = true;
		foreach ( $jobs as $hook => $interval ) {
			if ( ! as_has_scheduled_action( $hook, array(), 'aralia' ) ) {
				// El 6.º parámetro (único) evita duplicar la acción si dos peticiones programan a la vez.
				$all_ok = ( 0 !== (int) as_schedule_recurring_action( time() + 60, $interval, $hook, array(), 'aralia', true ) ) && $all_ok;
			}
		}
		// La marca solo se pone si las dos acciones quedaron programadas: si Action Scheduler falló, se reintenta en la siguiente petición.
		if ( $all_ok ) {
			set_transient( 'aralia_sec_maint_scheduled', 1, 12 * HOUR_IN_SECONDS );
		}
	}

	/**
	 * Tarea horaria.
	 *
	 * @return int Filas purgadas.
	 */
	public function hourly(): int {
		\Aralia\Catalog\MediaZip::purge_stale(); // Carpetas temporales de importaciones abandonadas.
		return ( new RateLimiter() )->purge_expired();
	}

	/**
	 * Tarea diaria.
	 *
	 * @return array<string,int> Filas afectadas por tarea.
	 */
	public function daily(): array {
		global $wpdb;
		$audit = new AuditLog();
		$out   = array(
			'audit_purged'                          => $audit->purge_older_than( 365 ),
			'audit_ip_wiped'                        => $audit->anonymize_ip_older_than( 90 ),
			'alerts_purged'                         => 0,
			// Eventos de pago NO autenticados (checksum inválido): 30 días; los autenticados se conservan 5 años (FR-054).
			'payment_events_unauthenticated_purged' => class_exists( '\\Aralia\\Guard\\PaymentEvents' ) ? \Aralia\Guard\PaymentEvents::purge_unauthenticated( 30 ) : 0,
		);
		$table = Migrator::tables()['stock_alerts'];
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$n = (int) $wpdb->query( "DELETE FROM `{$table}` WHERE notified_at IS NOT NULL AND notified_at < UTC_TIMESTAMP() - INTERVAL 90 DAY LIMIT 1000" );
			// phpcs:enable
			$out['alerts_purged'] += $n;
			if ( $n < 1000 ) {
				break;
			}
		}
		return $out;
	}
}
