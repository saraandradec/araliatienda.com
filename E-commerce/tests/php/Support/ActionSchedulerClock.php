<?php
/**
 * Reloj simulado de Action Scheduler para pruebas (T163): T-PSE-ABANDON, T-HOLD-PENDING.
 *
 * No toca el reloj del sistema. Hace dos cosas: (1) adelanta el reloj de la tienda (Aralia\Support\Clock, solo local/test), para que
 * los manejadores vean pasar el tiempo de verdad, y (2) adelanta la fecha programada de las acciones pendientes de UN hook y ejecuta
 * solo las que ya vencieron de ese hook (equivale a `wp action-scheduler run --hooks=<hook>`, pero sin pasar por claim ni lotes).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Support;

use Aralia\Support\Clock;

final class ActionSchedulerClock {

	/**
	 * Adelanta `$minutes` las acciones pendientes de `$hook` y las ejecuta si vencieron.
	 *
	 * @param string $hook    Hook (solo [a-z0-9_]).
	 * @param int    $minutes Minutos a adelantar (>= 0).
	 * @return int Acciones ejecutadas.
	 * @throws \InvalidArgumentException Si el hook o los minutos no son válidos.
	 */
	public static function advance_and_run( string $hook, int $minutes ): int {
		if ( ! preg_match( '/^[a-z0-9_]{1,80}$/', $hook ) ) {
			throw new \InvalidArgumentException( 'Hook inválido.' );
		}
		if ( $minutes < 0 || $minutes > 60 * 24 * 365 ) {
			throw new \InvalidArgumentException( 'Minutos fuera de rango.' );
		}
		if ( ! class_exists( 'ActionScheduler' ) ) {
			throw new \RuntimeException( 'Action Scheduler no está cargado.' );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		$secs  = $minutes * 60;
		Clock::advance( $secs );
		// phpcs:disable WordPress.DB
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET scheduled_date_gmt = DATE_SUB(scheduled_date_gmt, INTERVAL %d SECOND), scheduled_date_local = DATE_SUB(scheduled_date_local, INTERVAL %d SECOND) WHERE hook = %s AND status = 'pending'",
				$secs,
				$secs,
				$hook
			)
		);
		// phpcs:enable

		$ids = \ActionScheduler::store()->query_actions(
			array(
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'date'     => as_get_datetime_object(),
				'per_page' => 100,
			)
		);
		foreach ( $ids as $id ) {
			\ActionScheduler::runner()->process_action( (int) $id, 'Aralia clock' );
		}
		return count( $ids );
	}
}
