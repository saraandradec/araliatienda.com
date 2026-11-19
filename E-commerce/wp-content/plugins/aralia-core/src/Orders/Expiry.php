<?php
/**
 * Vencimiento propio de pedidos sin pago (T058, R-06).
 *
 * @package Aralia
 */

namespace Aralia\Orders;

use Aralia\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Cada pedido `pending` vence a los 30 min (`_ara_expires_at`). Antes de cancelar se pregunta a Wompi:
 *  - APPROVED: no se cancela; se deja a la conciliación (T063) y se reintenta pronto.
 *  - PENDING: +15 min cada vez, con tope de 2 h desde la creación.
 *  - sin respuesta (API caída): se reintenta en 5 min hasta el tope de 2 h.
 *  - cualquier otro estado, o tope alcanzado: se cancela (WooCommerce devuelve el stock).
 * La ventana se extiende UNA vez a +5 min al abrir el widget, hasta los 35 min desde la creación.
 * El manejador es idempotente: un candado por pedido evita que el ejecutor normal de Action Scheduler y el reloj simulado lo corran a la vez.
 */
final class Expiry {

	public const HOOK        = 'aralia_order_expiry';
	public const WATCHDOG    = 'aralia_expiry_watchdog';
	public const GROUP       = 'aralia';
	public const PENDING_BY  = 15 * MINUTE_IN_SECONDS;
	public const RETRY       = 5 * MINUTE_IN_SECONDS;
	public const MAX_AGE     = 2 * HOUR_IN_SECONDS;
	public const WIDGET_MAX  = 35 * MINUTE_IN_SECONDS;
	public const LATE_AFTER  = 5 * MINUTE_IN_SECONDS;
	private const LOCK_TTL   = 120;
	private const WATCH_SCAN = 200;

	/**
	 * Consulta a Wompi: recibe el pedido y devuelve el estado de la transacción (`APPROVED`, `PENDING`, `DECLINED`...) o null si no se pudo saber.
	 * El guard (T059) la sustituye con su cliente; sin él, nunca se cancela por desconocimiento antes del tope.
	 *
	 * @var callable|null
	 */
	private static $lookup = null;

	/**
	 * Engancha el manejador, la vigilancia y la cancelación nativa.
	 *
	 * @param callable|null $lookup Consulta a Wompi (por defecto, el filtro `aralia_wompi_lookup`).
	 * @return void
	 */
	public static function register( ?callable $lookup = null ): void {
		self::$lookup = $lookup;
		add_action( self::HOOK, array( self::class, 'run' ), 10, 1 );
		add_action( self::WATCHDOG, array( self::class, 'on_watch' ) );
		if ( did_action( 'action_scheduler_init' ) ) {
			self::schedule_watchdog();
		} else {
			add_action( 'action_scheduler_init', array( self::class, 'schedule_watchdog' ) );
		}
	}

	/**
	 * Programa la vigilancia cada 5 min (idempotente).
	 *
	 * @return void
	 */
	public static function schedule_watchdog(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) && ! as_has_scheduled_action( self::WATCHDOG, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, self::RETRY, self::WATCHDOG, array(), self::GROUP );
		}
	}

	/**
	 * Programa (o reprograma) el vencimiento de un pedido. `$at` está en hora de la tienda (Clock); la acción se encola en hora real.
	 *
	 * @param int $order_id Pedido.
	 * @param int $at       Momento de vencimiento (Clock).
	 * @return void
	 */
	public static function schedule( int $order_id, int $at ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array( $order_id ), self::GROUP );
		}
		as_schedule_single_action( time() + max( 0, $at - Clock::now() ), self::HOOK, array( $order_id ), self::GROUP );
	}

	/**
	 * Manejador de la acción.
	 *
	 * @param mixed $order_id Pedido.
	 * @return void
	 */
	public static function run( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! self::lock( $order_id ) ) {
			return;
		}
		try {
			self::process( $order_id );
		} finally {
			self::unlock( $order_id );
		}
	}

	/**
	 * Decide qué hacer con un pedido que venció.
	 *
	 * @param int $order_id Pedido.
	 * @return void
	 */
	private static function process( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || 'pending' !== $order->get_status() ) {
			return;
		}
		$now     = Clock::now();
		$expires = (int) $order->get_meta( '_ara_expires_at', true );
		if ( $expires > $now ) {
			self::schedule( $order_id, $expires ); // Se extendió mientras tanto.
			return;
		}
		$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : $now;
		// El pedido se creó en hora real; con el reloj simulado la edad se mide contra la misma base.
		$age    = $now - $created;
		$at_cap = $age >= self::MAX_AGE;
		$status = self::lookup( $order );

		// Sin ningún intento con transacción no hay nada que preguntar: "sin transacción" se cancela al vencer (contrato wompi §3).
		if ( null === $status && ! self::has_transaction( $order ) ) {
			$status = 'NONE';
		}
		if ( 'APPROVED' === $status ) {
			// Pagado y aún sin aplicar: lo resuelve la conciliación (T063). Pasados 15 min desde el vencimiento se alerta (una vez por pedido).
			if ( $now - $created > StockOnCreate::HOLD_SECONDS + 15 * MINUTE_IN_SECONDS && 'yes' !== $order->get_meta( '_ara_deferred_alerted', true ) ) {
				$order->update_meta_data( '_ara_deferred_alerted', 'yes' );
				do_action( 'aralia_alert', 'deferred_unresolved', 'pedido ' . $order_id );
			}
			self::postpone( $order, $now + self::RETRY );
			return;
		}
		if ( ! $at_cap && ( 'PENDING' === $status || null === $status ) ) {
			$next = 'PENDING' === $status ? self::PENDING_BY : self::RETRY;
			self::postpone( $order, min( $now + $next, $created + self::MAX_AGE ) );
			return;
		}
		StateMachine::run_as(
			StateMachine::SYSTEM,
			static function () use ( $order, $at_cap ): void {
				$order->update_status( 'cancelled', $at_cap ? 'Reserva vencida (tope de 2 h).' : 'Reserva vencida sin pago.' );
			}
		);
		if ( 'cancelled' === StateMachine::stored_status( $order_id ) ) {
			do_action( 'aralia_order_expired', $order_id );
		}
	}

	/**
	 * ¿Algún intento de pago tiene ya una transacción de Wompi?
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	private static function has_transaction( \WC_Order $order ): bool {
		$attempts = $order->get_meta( '_ara_attempts', true );
		foreach ( is_array( $attempts ) ? $attempts : array() as $attempt ) {
			if ( is_array( $attempt ) && ! empty( $attempt['transaction_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mueve el vencimiento y lo reprograma.
	 *
	 * @param \WC_Order $order Pedido.
	 * @param int       $to    Nuevo vencimiento (Clock).
	 * @return void
	 */
	private static function postpone( \WC_Order $order, int $to ): void {
		$order->update_meta_data( '_ara_expires_at', (string) $to );
		$order->save();
		self::schedule( $order->get_id(), $to );
	}

	/**
	 * Extensión única al abrir el widget: la ventana pasa a +5 min (hasta los 35 min desde la creación).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool True si se extendió.
	 */
	public static function extend_for_widget( \WC_Order $order ): bool {
		if ( 'pending' !== $order->get_status() || 'yes' === $order->get_meta( '_ara_widget_extended', true ) ) {
			return false;
		}
		$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : Clock::now();
		if ( Clock::now() >= $created + self::WIDGET_MAX ) {
			return false; // Fuera de la ventana de 35 min.
		}
		$current = (int) $order->get_meta( '_ara_expires_at', true );
		$to      = min( Clock::now() + self::RETRY, $created + self::WIDGET_MAX );
		if ( $to <= $current ) {
			return false; // Nunca acorta: fuera de la ventana de 35 min, o ya hay más margen.
		}
		$order->update_meta_data( '_ara_widget_extended', 'yes' );
		self::postpone( $order, $to );
		return true;
	}

	/**
	 * Callback de la acción recurrente.
	 *
	 * @return void
	 */
	public static function on_watch(): void {
		self::watch();
	}

	/**
	 * Vigilancia: si hay pedidos pendientes vencidos hace más de 5 minutos, el vencimiento va atrasado.
	 *
	 * @return bool True si se levantó la alerta.
	 */
	public static function watch(): bool {
		// Se filtra en PHP: el filtro por meta de wc_get_orders no es fiable entre almacenamientos (posts y HPOS). Los pendientes son pocos.
		$now     = Clock::now();
		$late_id = 0;
		$pending = wc_get_orders(
			array(
				'status'  => 'pending',
				'limit'   => self::WATCH_SCAN,
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);
		foreach ( is_array( $pending ) ? $pending : array() as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$expires = (int) $order->get_meta( '_ara_expires_at', true );
			if ( $expires <= 0 ) {
				continue;
			}
			// Autorreparación: un pedido pendiente sin acción programada (se perdió, o el proceso cayó tras crearlo) vuelve a la cola.
			if ( function_exists( 'as_next_scheduled_action' ) && false === as_next_scheduled_action( self::HOOK, array( $order->get_id() ), self::GROUP ) ) {
				self::schedule( $order->get_id(), $expires );
			}
			$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : $now;
			$overdue = $expires < $now - self::LATE_AFTER;
			$stuck   = $now - $created > self::MAX_AGE + self::LATE_AFTER; // Más de 2 h pendiente: algo no la está resolviendo.
			if ( 0 === $late_id && ( $overdue || $stuck ) ) {
				$late_id = $order->get_id();
			}
		}
		if ( $late_id <= 0 ) {
			return false;
		}
		do_action( 'aralia_alert', 'expiry_late', 'pedido ' . $late_id );
		return true;
	}

	/**
	 * Estado de la transacción en Wompi (null si no se pudo saber).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return string|null
	 */
	private static function lookup( \WC_Order $order ): ?string {
		try {
			$status = self::$lookup ? ( self::$lookup )( $order ) : apply_filters( 'aralia_wompi_lookup', null, $order );
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}
		return is_string( $status ) && '' !== $status ? strtoupper( $status ) : null;
	}

	/**
	 * Fichas de los candados que tiene este proceso (pedido => ficha).
	 *
	 * @var array<int,string>
	 */
	private static array $tokens = array();

	/**
	 * Candado por pedido, atómico: `INSERT IGNORE` (solo uno gana) y, para robar uno vencido, un `UPDATE` condicionado al valor leído.
	 * Es exclusión de mejor esfuerzo sobre la tabla de opciones; la segunda barrera es que `process()` vuelve a leer el estado y la
	 * máquina de estados (que lee la base sin caché) rechaza cancelar un pedido ya pagado.
	 *
	 * @param int $order_id Pedido.
	 * @return bool
	 */
	public static function lock( int $order_id ): bool {
		global $wpdb;
		$key   = 'aralia_exp_lock_' . $order_id;
		$token = time() . ':' . wp_generate_password( 12, false );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $token ) );
		$got = 1 === (int) $wpdb->rows_affected;
		if ( ! $got ) {
			$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
			if ( '' !== $held && time() - (int) $held > self::LOCK_TTL ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $key, $held ) );
				$got = 1 === (int) $wpdb->rows_affected;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		if ( $got ) {
			self::$tokens[ $order_id ] = $token;
		}
		self::forget_cache( $key );
		return $got;
	}

	/**
	 * Suelta el candado, solo si sigue siendo el propio (no borra el de otro proceso que lo robó).
	 *
	 * @param int $order_id Pedido.
	 * @return void
	 */
	public static function unlock( int $order_id ): void {
		global $wpdb;
		$key = 'aralia_exp_lock_' . $order_id;
		if ( isset( self::$tokens[ $order_id ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, self::$tokens[ $order_id ] ) );
			unset( self::$tokens[ $order_id ] );
		}
		self::forget_cache( $key );
	}

	/**
	 * Quita la opción de la caché de objetos (las escrituras directas no la actualizan).
	 *
	 * @param string $key Opción.
	 * @return void
	 */
	private static function forget_cache( string $key ): void {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
