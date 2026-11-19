<?php
/**
 * Conciliación (T063, contracts/wompi-integration.md §3): termina el trabajo que el evento firmado no pudo hacer.
 *
 * @package Aralia
 */

namespace Aralia\Guard;

use Aralia\Orders\Attempts;
use Aralia\Orders\OrderLock;
use Aralia\Orders\OrderStore;
use Aralia\Orders\StateMachine;
use Aralia\Orders\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Cada 5 min (y de inmediato por pedido, tras una pista del widget) busca los pagos de los pedidos `pending` y `cancelled` y los aplica por la
 * máquina de estados: es idempotente porque `pending` pasa a `processing` una sola vez, así que NO usa `idem_key` (repara también el caso de un
 * proceso que cayó tras ocuparla y antes de cambiar el pedido, o tras anotar la transacción y antes de aplicar el pago).
 *
 * Dos niveles de confianza:
 * - Intento con `transaction_id` (lo fijó un evento firmado o la propia conciliación: es de NUESTRO comercio por la firma): se consulta por id.
 * - Pistas y intentos `OPENED` sin id (nadie los ha firmado): solo cuenta lo que devuelve la búsqueda por referencia con la llave PRIVADA
 *   (`GET /transactions?reference=`), acotada al comercio de la llave; la consulta por id es pública y global y NO basta. Sin llave privada
 *   (o con 401, API caída...) esos intentos no conceden nada: se alerta y el evento firmado sigue siendo la vía.
 *
 * Costo acotado: cada pedido se revisa cada 5 min la primera hora, cada 30 min hasta las 24 h y cada hora después (solo para alertar); el
 * barrido corta al primer fallo de la API (circuit breaker), respeta un presupuesto de tiempo y un fallo en un pedido no detiene a los demás.
 */
final class Reconciler {

	public const SWEEP_HOOK    = 'aralia_wompi_reconcile_sweep';
	public const ORDER_HOOK    = 'aralia_reconcile_order';
	public const GROUP         = 'aralia';
	public const EVERY         = 300;
	public const APPLY_WINDOW  = DAY_IN_SECONDS;
	public const SCAN_WINDOW   = 7 * DAY_IN_SECONDS;
	public const PER_SWEEP     = 50;
	public const CANDIDATES    = 1000;
	public const TIME_BUDGET   = 25;
	public const DEFERRED_MIN  = 900;
	public const IMMEDIATE_GAP = 60;
	public const MAX_REFS      = 10;
	public const FAIL_ALERT_AT = 3;

	/**
	 * La búsqueda por referencia no está disponible en este barrido (sin llave privada, 401...): los demás pedidos siguen por el nivel de id.
	 *
	 * @var bool
	 */
	private static bool $ref_blocked = false;

	/**
	 * ¿Corre un barrido? (la marca anterior solo vale dentro de uno).
	 *
	 * @var bool
	 */
	private static bool $sweeping = false;

	/**
	 * Engancha los manejadores y programa el barrido.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::SWEEP_HOOK, array( self::class, 'sweep' ) );
		add_action( self::ORDER_HOOK, array( self::class, 'reconcile_order' ), 10, 1 );
		add_action( 'init', array( self::class, 'schedule' ), 20 );
	}

	/**
	 * Programa el barrido recurrente (una sola vez; la comprobación se cachea 10 min para no consultar Action Scheduler en cada petición).
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) || false !== get_transient( 'aralia_recon_scheduled' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::SWEEP_HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, self::EVERY, self::SWEEP_HOOK, array(), self::GROUP );
		}
		set_transient( 'aralia_recon_scheduled', 1, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Barrido: pedidos `pending` y `cancelled` recientes de Wompi que toca revisar (los menos revisados primero), y alerta de eventos
	 * diferidos sin resolver. Un fallo en un pedido no detiene a los demás; la alerta de diferidos corre siempre.
	 *
	 * @return void
	 */
	public static function sweep(): void {
		if ( ! class_exists( '\\Aralia\\Orders\\StateMachine' ) ) {
			return;
		}
		try {
			$deadline          = time() + self::TIME_BUDGET;
			self::$ref_blocked = false;
			self::$sweeping    = true;
			foreach ( self::due_orders() as $id ) {
				if ( time() >= $deadline ) {
					break; // El resto, en el próximo barrido (los menos revisados primero: no hay inanición).
				}
				if ( in_array( self::safe_reconcile( $id, $deadline ), array( 'api_down', 'budget' ), true ) ) {
					break; // Circuit breaker solo para fallos GLOBALES (red) o tiempo agotado: un pedido con un error propio no frena a los demás.
				}
			}
		} finally {
			self::$sweeping    = false;
			self::$ref_blocked = false;
			try {
				self::alert_deferred();
			} catch ( \Throwable $e ) {
				self::log( $e );
			}
		}
	}

	/**
	 * Conciliación inmediata de un pedido (la encola `payment-attempt`), con un enfriamiento de 60 s por pedido.
	 *
	 * @param mixed $order_id Pedido.
	 * @return void
	 */
	public static function reconcile_order( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! class_exists( '\\Aralia\\Orders\\StateMachine' ) ) {
			return;
		}
		$key = 'aralia_recon_gap_' . $order_id;
		if ( false !== get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, self::IMMEDIATE_GAP );
		self::safe_reconcile( $order_id, time() + self::TIME_BUDGET );
	}

	/**
	 * Concilia un pedido sin dejar escapar excepciones (hooks de terceros, correos...): se registra, se alerta (una vez por hora por pedido) y
	 * el barrido sigue con el siguiente.
	 *
	 * @param int $order_id Pedido.
	 * @param int $deadline Hora límite (timestamp).
	 * @return string Resultado de reconcile(), o `error`.
	 */
	private static function safe_reconcile( int $order_id, int $deadline ): string {
		try {
			return self::reconcile( $order_id, $deadline );
		} catch ( \Throwable $e ) {
			self::log( $e );
			try {
				self::mark_checked( $order_id ); // Mejor esfuerzo: un pedido que lanza siempre no debe ocupar los primeros puestos del barrido.
			} catch ( \Throwable $e2 ) {
				self::log( $e2 );
			}
			$key   = 'aralia_recon_err_' . $order_id;
			$total = (int) get_transient( 'aralia_recon_err_n' );
			if ( false === get_transient( $key ) && $total < 5 ) {
				set_transient( $key, 1, HOUR_IN_SECONDS );
				set_transient( 'aralia_recon_err_n', $total + 1, HOUR_IN_SECONDS ); // Tope global: como mucho 5 alertas por hora.
				do_action( 'aralia_alert', 'payment_mismatch', 'conciliación falló en el pedido ' . $order_id );
			}
			return 'error';
		}
	}

	/**
	 * Registra un error sin datos del pago (solo clase y lugar).
	 *
	 * @param \Throwable $e Error.
	 * @return void
	 */
	private static function log( \Throwable $e ): void {
		error_log( '[aralia] conciliación: ' . get_class( $e ) . ' en ' . basename( $e->getFile() ) . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Pedidos que toca revisar ahora, los menos revisados primero.
	 *
	 * @return int[]
	 */
	private static function due_orders(): array {
		$ids = wc_get_orders(
			array(
				'status'         => array( 'pending', 'cancelled' ),
				'payment_method' => 'wompi',
				'date_created'   => '>' . ( time() - self::SCAN_WINDOW ),
				'limit'          => self::CANDIDATES,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'return'         => 'ids',
			)
		);
		$due = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$id    = $id instanceof \WC_Order ? $id->get_id() : (int) $id; // Con `return => ids` llegan enteros; el stub de WooCommerce los tipa como pedidos.
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
			$age     = time() - $created;
			$gap     = $age < HOUR_IN_SECONDS ? self::EVERY : ( $age < DAY_IN_SECONDS ? 6 * self::EVERY : 12 * self::EVERY );
			if ( 'COP' !== $order->get_currency() ) {
				continue; // Nunca se concilia (reconcile lo saltaría sin marcarlo y ocuparía cupo).
			}
			if ( $age >= self::APPLY_WINDOW && 'yes' === $order->get_meta( '_ara_old_payment_alerted', true ) ) {
				continue; // Ya alertado: no ocupa cupo del barrido.
			}
			$last = (int) $order->get_meta( '_ara_recon_at', true );
			if ( time() - $last >= $gap - 30 ) {
				$due[ $id ] = $last;
			}
		}
		asort( $due );
		return array_slice( array_keys( $due ), 0, self::PER_SWEEP );
	}

	/**
	 * Concilia un pedido.
	 *
	 * @param int $order_id Pedido.
	 * @param int $deadline Hora límite (timestamp); 0 = sin límite.
	 * @return string Resultado: skip | budget | api_down (red, 429/5xx o credenciales de consulta) | api_unavailable (error propio del pedido o sin llave privada) | locked | nothing | <acciones>.
	 */
	public static function reconcile( int $order_id, int $deadline = 0 ): string {
		$order = OrderStore::fresh( $order_id );
		if ( ! $order instanceof \WC_Order || 'wompi' !== $order->get_payment_method() || 'COP' !== $order->get_currency() ) {
			return 'skip';
		}
		if ( ! in_array( StateMachine::stored_status( $order_id ), array( 'pending', 'cancelled' ), true ) ) {
			return 'skip';
		}
		$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
		$within  = time() - $created <= self::APPLY_WINDOW;
		if ( ! $within && 'yes' === $order->get_meta( '_ara_old_payment_alerted', true ) ) {
			return 'skip'; // Ya se alertó de su pago de más de 24 h: no se sigue consultando.
		}
		$client    = new WompiClient();
		$found     = array();
		$by_ref    = array();
		$skipped   = false;
		$responded = false;
		$abort     = '';
		foreach ( self::attempts_to_check( $order ) as $attempt ) {
			$reference = (string) $attempt['reference'];
			$tx_id     = isset( $attempt['transaction_id'] ) && is_string( $attempt['transaction_id'] ) ? $attempt['transaction_id'] : '';
			if ( '' === $tx_id ) {
				$by_ref[] = $reference;
				continue;
			}
			// Id fijado por un evento firmado o por esta conciliación: es de nuestro comercio; se consulta por id (sin llave privada).
			if ( ! self::budget_left( $deadline ) ) {
				$abort = 'budget'; // Tiempo agotado: no es un fallo de Wompi (sin alerta); el pedido no se marca como revisado.
				break;
			}
			$tx = $client->transaction( $tx_id );
			if ( null === $tx ) {
				self::note_failure( $client->last_error, $order_id );
				if ( in_array( $client->last_error, array( 'network', 'server', 'auth', 'no_key' ), true ) ) {
					$abort = 'api_down'; // Fallo global (red, 429/5xx, credenciales de consulta): el resto fallaría igual.
					break;
				}
				$skipped = true; // Error propio de este id (HTTP, formato): el pedido va al final de la cola; los demás siguen.
				continue;
			}
			$responded = true;
			if ( in_array( $tx['status'], array( 'APPROVED', 'PENDING', 'DECLINED', 'ERROR', 'VOIDED' ), true ) && Lookup::belongs( $tx, array( 'reference' => $reference ), $order ) ) {
				$found[] = array(
					'reference' => $reference,
					'tx'        => $tx,
				);
			}
		}
		foreach ( '' === $abort ? $by_ref : array() as $reference ) {
			if ( self::$sweeping && self::$ref_blocked ) {
				$skipped = true; // Sin llave privada válida en este barrido: pistas y OPENED sin firma no conceden nada.
				break;
			}
			if ( ! self::budget_left( $deadline ) ) {
				$abort = 'budget';
				break;
			}
			$list = $client->by_reference( $reference );
			if ( null === $list ) {
				self::note_failure( $client->last_error, $order_id );
				if ( in_array( $client->last_error, array( 'network', 'server' ), true ) ) {
					$abort = 'api_down';
					break;
				}
				self::$ref_blocked = self::$sweeping; // Configuración (llave, formato, HTTP): los demás pedidos siguen por el nivel de id.
				$skipped           = true;
				break;
			}
			$responded = true;
			foreach ( $list as $tx ) {
				if ( in_array( $tx['status'], array( 'APPROVED', 'PENDING' ), true ) && Lookup::belongs( $tx, array( 'reference' => $reference ), $order ) ) {
					$found[] = array(
						'reference' => $reference,
						'tx'        => $tx,
					);
				}
			}
		}
		if ( $responded ) {
			delete_transient( 'aralia_wompi_recon_fail' ); // Solo una respuesta válida de la API prueba que la red funciona.
		}
		// Lo ya encontrado se aplica aunque una consulta posterior haya fallado (un pago aprobado no espera a que la API se recupere).
		$done   = self::apply_found( $order_id, $found, $within );
		$locked = in_array( 'locked', $done, true );
		$done   = array_values( array_diff( $done, array( 'locked' ) ) );
		if ( ! $locked && 'budget' !== $abort ) {
			self::mark_checked( $order_id ); // Con el candado ocupado o sin tiempo NO se marca; tras un fallo de la API sí (rota al final de la cola).
		}
		if ( '' !== $abort ) {
			return $abort;
		}
		if ( array() !== $done ) {
			return implode( ',', $done );
		}
		if ( $locked ) {
			return 'locked';
		}
		return $skipped ? 'api_unavailable' : 'nothing';
	}

	/**
	 * Aplica lo encontrado: primero los aprobados (el segundo de otro intento lo detecta `apply` como doble cobro); un PENDING no se aplica
	 * si ya hay un aprobado de la misma referencia.
	 *
	 * @param int                              $order_id Pedido.
	 * @param array<int,array<string,mixed>>   $found    Hallazgos (`reference`, `tx`).
	 * @param bool                             $within   ¿El pedido tiene 24 h o menos?
	 * @return string[]
	 */
	private static function apply_found( int $order_id, array $found, bool $within ): array {
		usort( $found, static fn( array $a, array $b ): int => ( 'APPROVED' === $b['tx']['status'] ) <=> ( 'APPROVED' === $a['tx']['status'] ) );
		$approved_refs = array();
		foreach ( $found as $item ) {
			if ( 'APPROVED' === $item['tx']['status'] ) {
				$approved_refs[ $item['reference'] ] = true;
			}
		}
		$done = array();
		foreach ( $found as $item ) {
			$tx = $item['tx'];
			if ( 'PENDING' === $tx['status'] && isset( $approved_refs[ $item['reference'] ] ) ) {
				continue;
			}
			if ( 'APPROVED' === $tx['status'] && ! $within ) {
				// Aprobado de más de 24 h: no se aplica solo; alerta una vez por pedido para que Sara lo resuelva.
				self::alert_old_payment( $order_id );
				$done[] = 'old_alerted';
				continue;
			}
			$result = self::apply( $order_id, $item['reference'], $tx['id'], $tx['status'] );
			if ( '' !== $result ) {
				$done[] = $result;
			}
		}
		return $done;
	}

	/**
	 * ¿Queda tiempo del presupuesto?
	 *
	 * @param int $deadline Hora límite (0 = sin límite).
	 * @return bool
	 */
	private static function budget_left( int $deadline ): bool {
		return 0 === $deadline || time() < $deadline;
	}

	/**
	 * Anota la hora de la última revisión (el barrido atiende primero a los menos revisados).
	 *
	 * @param int $order_id Pedido.
	 * @return void
	 */
	private static function mark_checked( int $order_id ): void {
		OrderLock::run(
			$order_id,
			static function () use ( $order_id ): void {
				$fresh = OrderStore::fresh( $order_id );
				if ( $fresh instanceof \WC_Order ) {
					$fresh->update_meta_data( '_ara_recon_at', (string) time() );
					$fresh->save();
				}
			}
		);
	}

	/**
	 * Intentos que pueden tener un pago sin reflejar: todos salvo los que ya terminaron mal (DECLINED, ERROR, VOIDED). Incluye los que tienen
	 * el id anotado y el estado APPROVED pero el pedido sin aplicar (proceso caído entre ambos pasos). Primero los que tienen id o pistas, luego
	 * los más recientes, hasta MAX_REFS.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<int,array<string,mixed>>
	 */
	private static function attempts_to_check( \WC_Order $order ): array {
		$attempts = $order->get_meta( '_ara_attempts', true );
		$strong   = array();
		$plain    = array();
		foreach ( array_reverse( is_array( $attempts ) ? $attempts : array() ) as $attempt ) {
			if ( ! is_array( $attempt ) || empty( $attempt['reference'] ) || ! is_string( $attempt['reference'] ) ) {
				continue;
			}
			if ( in_array( $attempt['status'] ?? '', array( 'DECLINED', 'ERROR', 'VOIDED' ), true ) ) {
				continue;
			}
			if ( ! empty( $attempt['transaction_id'] ) || array() !== Attempts::hints( $attempt ) ) {
				$strong[] = $attempt;
			} else {
				$plain[] = $attempt;
			}
		}
		return array_slice( array_merge( $strong, $plain ), 0, self::MAX_REFS );
	}

	/**
	 * ¿El intento ya tiene esta transacción con este estado Y el pedido lo refleja?
	 *
	 * @param \WC_Order $order     Pedido recargado.
	 * @param string    $reference Referencia.
	 * @param string    $tx_id     Transacción.
	 * @param string    $status    Estado.
	 * @return bool
	 */
	private static function already_noted( \WC_Order $order, string $reference, string $tx_id, string $status ): bool {
		$attempts = $order->get_meta( '_ara_attempts', true );
		foreach ( is_array( $attempts ) ? $attempts : array() as $attempt ) {
			if ( is_array( $attempt ) && ( $attempt['reference'] ?? '' ) === $reference ) {
				$same = ( $attempt['transaction_id'] ?? '' ) === $tx_id && ( $attempt['status'] ?? '' ) === $status;
				if ( ! $same ) {
					return false;
				}
				// Un APPROVED anotado solo está resuelto si el pedido ya está pagado con esa transacción (si no, el proceso cayó a medias).
				return 'APPROVED' !== $status || ( in_array( StateMachine::stored_status( $order->get_id() ), Statuses::PAID, true ) && $order->get_transaction_id() === $tx_id );
			}
		}
		return false;
	}

	/**
	 * Aplica un estado confirmado, bajo el candado del pedido y con el pedido recargado. Un PENDING solo se anota mientras el pedido siga
	 * `pending` o `cancelled` (nunca rebaja el intento de un pedido que el evento ya pagó).
	 *
	 * @param int    $order_id  Pedido.
	 * @param string $reference Referencia.
	 * @param string $tx_id     Transacción.
	 * @param string $status    Estado.
	 * @return string Acción tomada; '' si no hay nada que hacer; `locked` si no se pudo tomar el candado (se reintenta en el próximo barrido).
	 */
	private static function apply( int $order_id, string $reference, string $tx_id, string $status ): string {
		if ( ! OrderLock::acquire( $order_id, 10 ) ) {
			return 'locked';
		}
		try {
			$fresh = OrderStore::fresh( $order_id );
			if ( ! $fresh instanceof \WC_Order ) {
				return '';
			}
			$stored = StateMachine::stored_status( $order_id );
			if ( 'APPROVED' === $status ? ! in_array( $stored, array( 'pending', 'cancelled', 'processing', 'ara-packed', 'ara-nostock' ), true ) : ! in_array( $stored, array( 'pending', 'cancelled' ), true ) ) {
				return '';
			}
			if ( self::already_noted( $fresh, $reference, $tx_id, $status ) ) {
				return '';
			}
			$result = ( new EventHandler() )->apply_verified( $fresh, $reference, $tx_id, $status );
			// Nota y alerta solo cuando el resultado CAMBIA (un pedido bloqueado no llena de notas y alertas cada 5 min).
			$sig = $tx_id . '|' . $status . '|' . $result;
			if ( $fresh->get_meta( '_ara_recon_last', true ) !== $sig ) {
				$fresh->update_meta_data( '_ara_recon_last', $sig );
				$fresh->add_order_note( 'Conciliación con Wompi: ' . $status . ' → ' . $result . '.' );
				$fresh->save();
				if ( in_array( $result, array( 'apply_failed', 'blocked_payment', 'late_blocked' ), true ) ) {
					do_action( 'aralia_alert', 'payment_mismatch', 'pedido ' . $order_id . ' ' . $result );
				}
			}
			return $result;
		} finally {
			OrderLock::release( $order_id );
		}
	}

	/**
	 * La consulta a Wompi falló: configuración (llave privada, formato, HTTP) o red. Una alerta por hora para lo de configuración; la red solo
	 * alerta tras varios fallos seguidos.
	 *
	 * @param string $error    Motivo de WompiClient.
	 * @param int    $order_id Pedido.
	 * @return void
	 */
	private static function note_failure( string $error, int $order_id ): void {
		if ( in_array( $error, array( 'network', 'server' ), true ) ) {
			$fails = (int) get_transient( 'aralia_wompi_recon_fail' ) + 1;
			set_transient( 'aralia_wompi_recon_fail', $fails, HOUR_IN_SECONDS );
			if ( $fails < self::FAIL_ALERT_AT ) {
				return;
			}
		}
		if ( false === get_transient( 'aralia_wompi_recon_alert' ) ) {
			set_transient( 'aralia_wompi_recon_alert', 1, HOUR_IN_SECONDS );
			do_action( 'aralia_alert', 'payment_mismatch', 'conciliación sin respuesta válida de Wompi: ' . ( '' === $error ? 'tiempo' : $error ) . ' (pedido ' . $order_id . ')' );
		}
	}

	/**
	 * Pago aprobado de más de 24 h sin reflejar: alerta una vez por pedido (bajo el candado).
	 *
	 * @param int $order_id Pedido.
	 * @return void
	 */
	private static function alert_old_payment( int $order_id ): void {
		$alert = false;
		OrderLock::run(
			$order_id,
			static function () use ( $order_id, &$alert ): void {
				$order = OrderStore::fresh( $order_id );
				if ( $order instanceof \WC_Order && 'yes' !== $order->get_meta( '_ara_old_payment_alerted', true ) ) {
					$order->update_meta_data( '_ara_old_payment_alerted', 'yes' );
					$order->save();
					$alert = true;
				}
			}
		);
		if ( $alert ) {
			do_action( 'aralia_alert', 'late_payment', 'pedido ' . $order_id . ' (aprobado de más de 24 h, sin aplicar)' );
		}
	}

	/**
	 * Eventos diferidos de pagos (aprobados o pendientes: la API no respondió) que siguen sin resolver pasados 15 min: alerta crítica, una vez por
	 * pedido (comparte `_ara_deferred_alerted` con Expiry).
	 *
	 * @return void
	 */
	private static function alert_deferred(): void {
		global $wpdb;
		$table = PaymentEvents::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT order_id, transaction_id, status_received FROM {$table} WHERE action_taken = 'deferred' AND status_received IN ('APPROVED','PENDING') AND order_id > 0 AND received_at < %s AND received_at > %s ORDER BY order_id DESC LIMIT 100", gmdate( 'Y-m-d H:i:s', time() - self::DEFERRED_MIN ), gmdate( 'Y-m-d H:i:s', time() - self::SCAN_WINDOW ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- tabla propia; valores preparados.
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id    = (int) $row['order_id'];
			$tx_id = (string) $row['transaction_id'];
			$pend  = 'PENDING' === $row['status_received'];
			$alert = false;
			OrderLock::run(
				$id,
				static function () use ( $id, $tx_id, $pend, &$alert ): void {
					$order = OrderStore::fresh( $id );
					if ( ! $order instanceof \WC_Order || ! in_array( StateMachine::stored_status( $id ), array( 'pending', 'cancelled' ), true ) || 'yes' === $order->get_meta( '_ara_deferred_alerted', true ) ) {
						return;
					}
					if ( $pend && self::has_noted( $order, $tx_id ) ) {
						return; // Un PENDING que la conciliación ya anotó sigue en proceso de verdad (PSE...): no es un evento sin resolver.
					}
					$order->update_meta_data( '_ara_deferred_alerted', 'yes' );
					$order->save();
					$alert = true;
				}
			);
			if ( $alert ) {
				do_action( 'aralia_alert', 'deferred_unresolved', 'pedido ' . $id );
			}
		}
	}

	/**
	 * ¿Algún intento del pedido tiene ya anotada esta transacción?
	 *
	 * @param \WC_Order $order Pedido.
	 * @param string    $tx_id Transacción.
	 * @return bool
	 */
	private static function has_noted( \WC_Order $order, string $tx_id ): bool {
		$attempts = $order->get_meta( '_ara_attempts', true );
		foreach ( is_array( $attempts ) ? $attempts : array() as $attempt ) {
			if ( '' !== $tx_id && is_array( $attempt ) && ( $attempt['transaction_id'] ?? '' ) === $tx_id ) {
				return true;
			}
		}
		return false;
	}
}
