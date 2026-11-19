<?php
/**
 * Manejador de eventos de Wompi (T060, contracts/wompi-integration.md §3).
 *
 * @package Aralia
 */

namespace Aralia\Guard;

use Aralia\Orders\Expiry;
use Aralia\Orders\PartialStock;
use Aralia\Orders\StateMachine;
use Aralia\Orders\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Responde en `?wc-api=wc_wompi` (prioridad 1) y termina la petición: el plugin oficial nunca llega a ver el evento.
 * Orden: estructura → checksum → referencia → idempotencia → verificación doble contra la API → máquina de estados.
 * Solo este código (con el actor `payment`) marca pedidos como pagados.
 */
final class EventHandler {

	public const MAX_BODY = 65536;

	/**
	 * Intentos de 200 ms para tomar el candado del pedido (5 s): el webhook espera más que una pista, y Wompi reintenta si no se logra.
	 */
	public const LOCK_TRIES = 25;

	/**
	 * Filas de rechazo (checksum o estructura inválidos) que se registran por hora: por IP y en total.
	 */
	public const REJECT_LOG_PER_IP = 20;
	public const REJECT_LOG_GLOBAL = 100;

	/**
	 * Propiedades que `signature.properties` debe incluir siempre (id, estado y monto de la transacción).
	 */
	private const REQUIRED_PROPS = array( 'transaction.id', 'transaction.status', 'transaction.amount_in_cents' );

	private const REFERENCE = '/^(\d+)-(\d{1,3})$/';

	/**
	 * Engancha el manejador.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'woocommerce_api_wc_wompi', array( self::class, 'serve' ), 1 );
	}

	/**
	 * Atiende la petición HTTP y termina.
	 *
	 * @return void
	 */
	public static function serve(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			self::send( 405, array( 'received' => false ) );
		}
		$raw    = (string) file_get_contents( 'php://input', false, null, 0, self::MAX_BODY + 1 );
		$header = isset( $_SERVER['HTTP_X_EVENT_CHECKSUM'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_EVENT_CHECKSUM'] ) ) : '';
		$result = ( new self() )->handle( $raw, $header );
		self::send( $result['status'], $result['body'] );
	}

	/**
	 * Responde y termina.
	 *
	 * @param int                 $status HTTP.
	 * @param array<string,mixed> $body   Cuerpo.
	 * @return void
	 */
	private static function send( int $status, array $body ): void {
		nocache_headers();
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $body );
		exit;
	}

	/**
	 * Procesa un evento. Pública y sin `exit` para probarla.
	 *
	 * Un POST anónimo NO escribe en `payment_events` hasta que el checksum es válido: lo inválido deja, como mucho, una fila sin cuerpo con
	 * tope por hora (`reject()`; sus contadores son una fila por clave en `aralia_rate_limits`, y el global se consulta primero).
	 *
	 * @param string $raw             Cuerpo crudo.
	 * @param string $header_checksum Cabecera `X-Event-Checksum` (vacía si no vino).
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public function handle( string $raw, string $header_checksum = '' ): array {
		try {
			return $this->process( $raw, $header_checksum );
		} catch ( \Throwable $e ) {
			// Sin datos del evento ni del mensaje (podría llevar PII): solo la clase y el lugar.
			error_log( '[aralia] guard de Wompi: ' . get_class( $e ) . ' en ' . basename( $e->getFile() ) . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			if ( false === get_transient( 'aralia_wompi_error_alert' ) ) {
				set_transient( 'aralia_wompi_error_alert', 1, HOUR_IN_SECONDS );
				do_action( 'aralia_alert', 'payment_mismatch', 'error interno del guard de Wompi' );
			}
			return $this->reply( 500, 'error' ); // Wompi reintenta; nunca un fatal de WordPress con la fila a medias.
		}
	}

	/**
	 * Cuerpo de handle() (los pasos del contrato §3).
	 *
	 * @param string $raw             Cuerpo crudo.
	 * @param string $header_checksum Cabecera.
	 * @return array{status:int,body:array<string,mixed>}
	 */
	private function process( string $raw, string $header_checksum ): array {
		// Sin aralia-core (máquina de estados, candado, alertas) no se toca ningún pedido: Wompi reintenta cuando vuelva.
		if ( ! class_exists( '\\Aralia\\Orders\\StateMachine' ) || ! class_exists( '\\Aralia\\Orders\\Expiry' ) || ! class_exists( '\\Aralia\\Orders\\PartialStock' ) ) {
			return $this->reply( 503, 'core_missing' );
		}
		if ( strlen( $raw ) > self::MAX_BODY ) {
			$this->reject( 'too_large' );
			return $this->reply( 400, 'too_large' );
		}
		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) || ! $this->valid_structure( $event ) ) {
			$this->reject( 'invalid_structure' );
			return $this->reply( 400, 'invalid_structure' );
		}
		/** @var array<string,mixed> $tx */
		$tx = $event['data']['transaction'];

		// 2. Checksum (antes de escribir nada).
		$secret = Keys::get( 'events' );
		if ( '' === $secret ) {
			$this->reject( 'no_events_key' );
			return $this->reply( 503, 'not_configured' ); // Wompi reintenta cuando haya llave.
		}
		if ( ! $this->checksum_ok( $event, $secret, $header_checksum ) ) {
			$this->reject( 'checksum_invalid' );
			do_action( 'aralia_alert', 'checksum_invalid', 'evento' );
			return $this->reply( 401, 'checksum_invalid' );
		}
		if ( 'transaction.updated' !== $event['event'] ) {
			return $this->reply( 200, 'ignored_event' );
		}

		// 4. Idempotencia (atajo; la decisión final es la escritura de idem_key tras el paso 5). Un duplicado NO deja fila nueva: repetir
		// un evento firmado capturado no puede llenar la tabla.
		if ( PaymentEvents::has_idem( $tx['id'] . '|' . $tx['status'] ) ) {
			return $this->reply( 200, 'duplicate_ignored' );
		}

		// 1. Registro (ya autenticado). Si no se puede registrar, NO se sigue: responder 503 hace que Wompi reintente.
		$row = array(
			'transaction_id'      => (string) $tx['id'],
			'reference'           => substr( (string) $tx['reference'], 0, 64 ),
			'status_received'     => (string) $tx['status'],
			'amount_in_cents'     => (int) $tx['amount_in_cents'],
			'currency'            => strtoupper( substr( (string) $tx['currency'], 0, 3 ) ),
			'payment_method_type' => isset( $tx['payment_method_type'] ) && is_scalar( $tx['payment_method_type'] ) ? substr( (string) $tx['payment_method_type'], 0, 32 ) : '',
			'checksum_valid'      => 1,
			'raw_sanitized'       => wp_json_encode( PaymentEvents::sanitize( $event ) ),
		);
		// Un reenvío del mismo evento cuyo resultado no ocupó idem_key reutiliza su fila (no crece la tabla).
		$id = PaymentEvents::reusable_row( (string) $tx['id'], (string) $tx['status'] );
		// Reinicio condicional: si otro procesamiento la reclamó entretanto, se inserta una fila nueva (no se pisa su resultado).
		if ( $id <= 0 || ! PaymentEvents::reuse( $id, $row ) ) {
			$id = PaymentEvents::insert( $row );
		}
		if ( $id <= 0 ) {
			return $this->reply( 503, 'log_failed' );
		}

		// 3. Referencia y pedido.
		$reference = (string) $tx['reference'];
		$order     = $this->resolve_order( $reference );
		if ( null === $order ) {
			PaymentEvents::update(
				$id,
				array(
					'action_taken'    => 'unknown_reference',
					'reference_match' => 0,
				)
			);
			// Wompi manda eventos de TODAS las transacciones del comercio (otros canales): solo alerta lo que tiene el formato del guard.
			if ( 1 === preg_match( self::REFERENCE, $reference ) ) {
				do_action( 'aralia_alert', 'payment_mismatch', 'referencia desconocida' );
			}
			return $this->reply( 200, 'unknown_reference' ); // 200: no se reintenta.
		}
		PaymentEvents::update(
			$id,
			array(
				'order_id'        => $order->get_id(),
				'reference_match' => 1,
			)
		);

		$status = (string) $tx['status'];

		// 5. Verificación doble contra la API.
		$client = new WompiClient();
		$api    = $client->transaction( (string) $tx['id'] );
		if ( null === $api ) {
			if ( in_array( $client->last_error, array( 'auth', 'no_key' ), true ) ) {
				// No es "API caída": la consulta está mal configurada (T-API-AUTH) y ningún evento se resolverá hasta corregirlo.
				if ( false === get_transient( 'aralia_wompi_auth_alert' ) ) {
					set_transient( 'aralia_wompi_auth_alert', 1, HOUR_IN_SECONDS ); // Una vez por hora: cada evento fallaría igual.
					do_action( 'aralia_alert', 'payment_mismatch', 'consulta a Wompi sin autorización' );
				}
			}
			if ( ! $this->note_under_lock( $order->get_id(), $reference, (string) $tx['id'] ) ) {
				PaymentEvents::update( $id, array( 'action_taken' => 'lock_busy' ) );
				return $this->reply( 503, 'busy' );
			}
			PaymentEvents::update(
				$id,
				array(
					'api_verified' => 'deferred',
					'action_taken' => 'deferred',
				)
			);
			return $this->reply( 200, 'deferred' );
		}
		$expected = (int) round( (float) $order->get_total() * 100 );
		$amount   = $api['amount_in_cents'] === $expected && (int) $tx['amount_in_cents'] === $expected;
		$currency = 'COP' === $api['currency'] && 'COP' === strtoupper( (string) $tx['currency'] ) && 'COP' === $order->get_currency();
		$ref_ok   = $api['reference'] === $reference;
		$same     = $api['status'] === $status;
		PaymentEvents::update(
			$id,
			array(
				'api_verified'    => 'NOT_FOUND' === $api['status'] ? 'no' : 'yes',
				'amount_match'    => $amount ? 1 : 0,
				'currency_match'  => $currency ? 1 : 0,
				'reference_match' => $ref_ok ? 1 : 0,
			)
		);
		if ( 'NOT_FOUND' !== $api['status'] && $amount && $currency && $ref_ok && ! $same ) {
			// El estado del evento y el de la API difieren (no es un fraude: monto, moneda y referencia coinciden): no se alerta. La
			// transacción se anota igual (bajo el candado) para que el vencimiento y la conciliación la encuentren.
			if ( ! $this->note_under_lock( $order->get_id(), $reference, (string) $tx['id'] ) ) {
				PaymentEvents::update( $id, array( 'action_taken' => 'lock_busy' ) );
				return $this->reply( 503, 'busy' );
			}
			PaymentEvents::update( $id, array( 'action_taken' => 'stale_event' ) );
			// Si el evento va POR DELANTE de la API (la API aún dice PENDING), 503: Wompi reintenta cuando la API ya lo refleje.
			return 'PENDING' === $api['status'] ? $this->reply( 503, 'stale_retry' ) : $this->reply( 200, 'stale_event' );
		}
		if ( 'NOT_FOUND' === $api['status'] && $this->attempt_is_recent( $order, $reference ) ) {
			// La API puede tardar en reflejar una transacción recién creada: no es un fraude. Se anota y Wompi reintenta, sin alerta.
			if ( ! $this->note_under_lock( $order->get_id(), $reference, (string) $tx['id'] ) ) {
				PaymentEvents::update( $id, array( 'action_taken' => 'lock_busy' ) );
				return $this->reply( 503, 'busy' );
			}
			PaymentEvents::update( $id, array( 'action_taken' => 'api_lag' ) );
			return $this->reply( 503, 'api_lag' );
		}
		if ( 'NOT_FOUND' === $api['status'] || ! $amount || ! $currency || ! $ref_ok ) {
			PaymentEvents::update( $id, array( 'action_taken' => 'mismatch' ) );
			do_action( 'aralia_alert', 'payment_mismatch', 'pedido ' . $order->get_id() );
			return $this->reply( 200, 'mismatch' );
		}

		// 6. Estado: candado del pedido, luego idem_key, luego el cambio (así dos procesos no lo cambian dos veces).
		if ( ! \Aralia\Orders\OrderLock::acquire( $order->get_id(), self::LOCK_TRIES ) ) {
			PaymentEvents::update( $id, array( 'action_taken' => 'lock_busy' ) );
			return $this->reply( 503, 'busy' ); // Sin idem_key: el reintento de Wompi sí se procesa.
		}
		$result = 'apply_failed';
		$code   = 500;
		try {
			$claim = PaymentEvents::claim_idem( $id, $tx['id'] . '|' . $api['status'] );
			if ( 'duplicate' === $claim ) {
				PaymentEvents::update( $id, array( 'action_taken' => 'duplicate_ignored' ) );
				return $this->reply( 200, 'duplicate_ignored' );
			}
			if ( 'claimed' !== $claim ) {
				PaymentEvents::update( $id, array( 'action_taken' => 'idem_busy' ) );
				return $this->reply( 503, 'idem_busy' ); // Otro procesamiento sigue en curso (o un fallo de base de datos): NO es un duplicado; Wompi reintenta.
			}
			// Un fatal (tiempo, memoria, exit en un gancho) no deja la clave ocupada: se libera al cierre si no se terminó.
			$state           = new \stdClass();
			$state->finished = false;
			register_shutdown_function( array( self::class, 'release_if_unfinished' ), $state, $id, $order->get_id() );
			try {
				$fresh  = \Aralia\Orders\OrderStore::fresh( $order->get_id() );
				$result = $fresh instanceof \WC_Order ? $this->apply( $fresh, $reference, (string) $tx['id'], $api['status'] ) : 'order_missing';
			} catch ( \Throwable $e ) {
				unset( $e );
				$result = 'apply_failed';
			}
			if ( in_array( $result, array( 'apply_failed', 'order_missing', 'blocked_payment' ), true ) ) {
				// Nada cambió: se libera la clave para que el reintento de Wompi (o la conciliación) pueda aplicarlo, y se avisa.
				PaymentEvents::release_idem( $id );
				$state->finished = true;
				PaymentEvents::update( $id, array( 'action_taken' => $result ) );
				do_action( 'aralia_alert', 'payment_mismatch', 'pedido ' . $order->get_id() . ' ' . $result );
				return $this->reply( 500, $result );
			}
			$state->finished = true; // Antes del registro final: si ese UPDATE fallara, la clave de un cambio ya aplicado NO se libera.
			PaymentEvents::update( $id, array( 'action_taken' => substr( $result, 0, 64 ) ) );
			$code = 200;
		} finally {
			\Aralia\Orders\OrderLock::release( $order->get_id() );
		}
		return $this->reply( $code, $result );
	}

	/**
	 * Cierre del proceso: si el procesamiento no terminó (fatal, tiempo, memoria), libera la `idem_key` para que el reintento de Wompi
	 * se procese. Un SIGKILL no ejecuta esto: `PaymentEvents::claim_idem` recupera la clave abandonada pasados 15 minutos.
	 *
	 * @param object $state Estado con la propiedad `finished`.
	 * @param int    $id       Fila del evento.
	 * @param int    $order_id Pedido (se libera también su candado: un fatal no ejecuta el `finally`).
	 * @return void
	 */
	public static function release_if_unfinished( object $state, int $id, int $order_id = 0 ): void {
		if ( empty( $state->finished ) ) {
			PaymentEvents::release_idem( $id );
			if ( $order_id > 0 ) {
				\Aralia\Orders\OrderLock::release( $order_id );
			}
		}
	}

	/**
	 * ¿El intento de esa referencia (o, sin marca, el pedido) tiene menos de 10 minutos?
	 *
	 * @param \WC_Order $order     Pedido.
	 * @param string    $reference Referencia.
	 * @return bool
	 */
	private function attempt_is_recent( \WC_Order $order, string $reference ): bool {
		$attempts = $order->get_meta( '_ara_attempts', true );
		foreach ( is_array( $attempts ) ? $attempts : array() as $attempt ) {
			if ( is_array( $attempt ) && ( $attempt['reference'] ?? '' ) === $reference && isset( $attempt['at'] ) && is_string( $attempt['at'] ) && false !== strtotime( $attempt['at'] ) ) {
				return time() - (int) strtotime( $attempt['at'] ) < 10 * MINUTE_IN_SECONDS;
			}
		}
		$created = $order->get_date_created();
		return null !== $created && time() - $created->getTimestamp() < 10 * MINUTE_IN_SECONDS;
	}

	/**
	 * Anota la transacción en el intento del pedido bajo el candado del pedido.
	 *
	 * @param int    $order_id  Pedido.
	 * @param string $reference Referencia del intento.
	 * @param string $tx_id     Transacción.
	 * @return bool False si el candado está ocupado (el llamador responde 503).
	 */
	private function note_under_lock( int $order_id, string $reference, string $tx_id ): bool {
		if ( ! \Aralia\Orders\OrderLock::acquire( $order_id ) ) {
			return false;
		}
		try {
			$locked = \Aralia\Orders\OrderStore::fresh( $order_id );
			if ( $locked instanceof \WC_Order ) {
				$this->note_transaction( $locked, $reference, $tx_id, null );
			}
		} finally {
			\Aralia\Orders\OrderLock::release( $order_id );
		}
		return true;
	}

	/**
	 * Cuenta un rechazo de un POST no autenticado con límite por hora (por IP y global): solo las primeras filas dejan rastro, sin cuerpo.
	 *
	 * @param string $action Motivo.
	 * @return void
	 */
	private function reject( string $action ): void {
		if ( ! class_exists( '\\Aralia\\Security\\RateLimiter' ) || ! class_exists( '\\Aralia\\Support\\ClientIp' ) ) {
			return;
		}
		try {
			$limiter = new \Aralia\Security\RateLimiter();
			$ip      = hash_hmac( 'sha256', 'wompi_rej|' . \Aralia\Support\ClientIp::get(), wp_salt( 'auth' ) );
			$global  = hash_hmac( 'sha256', 'wompi_rej|all', wp_salt( 'auth' ) );
			$all     = $limiter->hit( $global, 'wompi_rej_all', HOUR_IN_SECONDS );
			// Superado el tope global no se crea ni una clave por IP más (rotar IPs no llena aralia_rate_limits).
			if ( $all > self::REJECT_LOG_GLOBAL ) {
				return;
			}
			$by_ip = $limiter->hit( $ip, 'wompi_rej_ip', HOUR_IN_SECONDS );
		} catch ( \Throwable $e ) {
			unset( $e );
			return; // Sin limitador no se escribe: es preferible perder el rastro de un POST no autenticado que dejar crecer la tabla.
		}
		if ( $by_ip <= self::REJECT_LOG_PER_IP ) {
			PaymentEvents::insert( array( 'action_taken' => $action ) );
		}
	}

	/**
	 * Aplica un estado ya verificado (lo usa la conciliación, T063): misma lógica y máquina de estados que el evento, sin `idem_key`.
	 *
	 * @param \WC_Order $order     Pedido (recargado dentro del candado).
	 * @param string    $reference Referencia del intento.
	 * @param string    $tx_id     Transacción.
	 * @param string    $status    Estado verificado.
	 * @return string Acción tomada.
	 */
	public function apply_verified( \WC_Order $order, string $reference, string $tx_id, string $status ): string {
		return $this->apply( $order, $reference, $tx_id, $status );
	}

	/**
	 * Aplica un estado verificado por la API al pedido (máquina de estados de data-model §3).
	 *
	 * @param \WC_Order $order     Pedido (recargado dentro del candado).
	 * @param string    $reference Referencia del intento.
	 * @param string    $tx_id     Transacción.
	 * @param string    $status    Estado verificado.
	 * @return string Acción tomada.
	 */
	private function apply( \WC_Order $order, string $reference, string $tx_id, string $status ): string {
		$stored = StateMachine::stored_status( $order->get_id() ); // Sin caché: manda la base de datos.
		$paid   = in_array( $stored, Statuses::PAID, true );

		if ( 'APPROVED' === $status ) {
			if ( $paid ) {
				if ( $this->already_applied( $order, $tx_id ) ) {
					return 'duplicate_ignored';
				}
				$this->note_transaction( $order, $reference, $tx_id, $status );
				do_action( 'aralia_alert', 'duplicate_charge', 'pedido ' . $order->get_id() );
				do_action( 'aralia_double_charge', $order->get_id(), $tx_id );
				return 'double_charge';
			}
			if ( 'pending' === $stored ) {
				$this->note_transaction( $order, $reference, $tx_id, $status );
				StateMachine::run_as(
					StateMachine::PAYMENT,
					static function () use ( $order, $tx_id ): void {
						$order->payment_complete( $tx_id );
					}
				);
				return in_array( StateMachine::stored_status( $order->get_id() ), Statuses::PAID, true ) ? 'to_processing' : 'blocked_payment';
			}
			if ( 'cancelled' === $stored ) {
				$this->note_transaction( $order, $reference, $tx_id, $status );
				$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
				if ( time() - $created > DAY_IN_SECONDS ) {
					do_action( 'aralia_alert', 'late_payment', 'pedido ' . $order->get_id() );
				}
				StateMachine::run_as(
					StateMachine::PAYMENT,
					static function () use ( $order, $tx_id ): void {
						$order->set_transaction_id( $tx_id );
						$order->save();
					}
				);
				$final = PartialStock::settle_late( $order );
				return 'late_' . ( 'processing' === $final ? 'processing' : ( 'ara-nostock' === $final ? 'nostock' : 'blocked' ) );
			}
			// refunded, ara-reversed, trash...: un pago aprobado no puede revivirlo.
			$this->note_transaction( $order, $reference, $tx_id, $status );
			do_action( 'aralia_blocked_transition', $order->get_id(), $stored, 'processing' );
			do_action( 'aralia_alert', 'blocked_transition', 'pedido ' . $order->get_id() . ' ' . $stored . ' a processing' );
			return 'blocked_approved';
		}

		if ( 'PENDING' === $status ) {
			$this->note_transaction( $order, $reference, $tx_id, $status );
			return 'pending_recorded';
		}

		if ( 'VOIDED' === $status && $paid ) {
			$this->note_transaction( $order, $reference, $tx_id, $status );
			if ( in_array( $stored, array( 'processing', 'ara-packed' ), true ) ) {
				StateMachine::run_as(
					StateMachine::PAYMENT,
					static function () use ( $order ): void {
						$order->update_status( 'refunded', 'Anulación recibida de Wompi (evento VOIDED verificado).' );
					}
				);
				$this->log_refund( $order, $tx_id );
				do_action( 'aralia_alert', 'payment_voided', 'pedido ' . $order->get_id() );
				return 'voided_refunded';
			}
			do_action( 'aralia_blocked_transition', $order->get_id(), $stored, 'refunded' );
			do_action( 'aralia_alert', 'payment_voided', 'pedido ' . $order->get_id() . ' ' . $stored );
			return 'blocked_void';
		}

		// DECLINED, ERROR o VOIDED de un intento sin pago. Si la conciliación ya lo anotó (misma transacción y mismo estado), no se cuenta
		// otra vez: ni segundo rechazo en el límite por origen, ni segunda alerta ni segundo correo.
		if ( $this->attempt_has( $order, $reference, $tx_id, $status ) ) {
			return 'duplicate_ignored';
		}
		$this->note_transaction( $order, $reference, $tx_id, $status );
		if ( $paid ) {
			if ( ! $this->already_applied( $order, $tx_id ) ) {
				return 'declined_other_attempt'; // Un rechazo de OTRO intento no toca un pedido ya pagado: ni retroceso ni alerta.
			}
			do_action( 'aralia_blocked_transition', $order->get_id(), $stored, 'pending' );
			do_action( 'aralia_alert', 'blocked_transition', 'pedido ' . $order->get_id() . ' ' . $stored . ' a pending' );
			return 'blocked_regression';
		}
		if ( 'pending' === $stored ) {
			if ( ! $this->flag_is( $order, $reference, 'decline_recorded', $tx_id . '|' . $status ) ) {
				$this->record_decline( $order );
				$this->set_flag( $order, $reference, 'decline_recorded', $tx_id . '|' . $status ); // Antes de la alerta y los ganchos: un fallo de ellos no suma otro rechazo al origen.
			}
			do_action( 'aralia_alert', 'payment_declines', 'pedido ' . $order->get_id() );
			do_action( 'aralia_payment_declined', $order->get_id(), $reference );
			$this->set_flag( $order, $reference, 'decline_counted', $tx_id . '|' . $status ); // Al final: si un gancho cae antes, el reintento repite la alerta y el gancho (no el conteo).
			return 'declined_recorded';
		}
		return 'declined_ignored';
	}

	/**
	 * Marca del intento (`decline_recorded`: ya se sumó al límite por origen; `decline_counted`: ya se emitieron la alerta y el gancho), con el valor
	 * `<transacción>|<estado>`: así solo vale para ESA transacción y ese estado, y un segundo procesamiento (evento tras conciliación, o al revés)
	 * no cuenta dos veces. Si el proceso cae antes de marcar, el reintento sí lo hace.
	 *
	 * @param \WC_Order $order     Pedido.
	 * @param string    $reference Referencia.
	 * @param string    $key       Nombre de la marca.
	 * @param string    $value     Valor.
	 * @return void
	 */
	private function set_flag( \WC_Order $order, string $reference, string $key, string $value ): void {
		$attempts = $order->get_meta( '_ara_attempts', true );
		$attempts = is_array( $attempts ) ? $attempts : array();
		foreach ( $attempts as $i => $attempt ) {
			if ( is_array( $attempt ) && ( $attempt['reference'] ?? '' ) === $reference ) {
				$attempts[ $i ][ $key ] = $value;
			}
		}
		$order->update_meta_data( '_ara_attempts', $attempts );
		$order->save();
	}

	/**
	 * ¿La marca del intento tiene este valor?
	 *
	 * @param \WC_Order $order     Pedido.
	 * @param string    $reference Referencia.
	 * @param string    $key       Nombre de la marca.
	 * @param string    $value     Valor.
	 * @return bool
	 */
	private function flag_is( \WC_Order $order, string $reference, string $key, string $value ): bool {
		$attempts = $order->get_meta( '_ara_attempts', true );
		foreach ( is_array( $attempts ) ? $attempts : array() as $attempt ) {
			if ( is_array( $attempt ) && ( $attempt['reference'] ?? '' ) === $reference ) {
				return ( $attempt[ $key ] ?? '' ) === $value;
			}
		}
		return false;
	}

	/**
	 * ¿El intento de la referencia ya tiene esta transacción anotada con este estado y su rechazo ya contado?
	 *
	 * @param \WC_Order $order     Pedido.
	 * @param string    $reference Referencia.
	 * @param string    $tx_id     Transacción.
	 * @param string    $status    Estado.
	 * @return bool
	 */
	private function attempt_has( \WC_Order $order, string $reference, string $tx_id, string $status ): bool {
		$attempts = $order->get_meta( '_ara_attempts', true );
		foreach ( is_array( $attempts ) ? $attempts : array() as $attempt ) {
			if ( is_array( $attempt ) && ( $attempt['reference'] ?? '' ) === $reference ) {
				return ( $attempt['transaction_id'] ?? '' ) === $tx_id && ( $attempt['status'] ?? '' ) === $status && ( $attempt['decline_counted'] ?? '' ) === $tx_id . '|' . $status;
			}
		}
		return false;
	}

	/**
	 * ¿Esta transacción ya fue la que pagó el pedido?
	 *
	 * @param \WC_Order $order Pedido.
	 * @param string    $tx_id Transacción.
	 * @return bool
	 */
	private function already_applied( \WC_Order $order, string $tx_id ): bool {
		return $order->get_transaction_id() === $tx_id;
	}

	/**
	 * Anota la transacción y el estado en el intento de la referencia (`_ara_attempts`).
	 *
	 * @param \WC_Order   $order     Pedido.
	 * @param string      $reference Referencia.
	 * @param string      $tx_id     Transacción.
	 * @param string|null $status    Estado verificado (null si no se pudo verificar).
	 * @return void
	 */
	private function note_transaction( \WC_Order $order, string $reference, string $tx_id, ?string $status ): void {
		$attempts = $order->get_meta( '_ara_attempts', true );
		$attempts = is_array( $attempts ) ? $attempts : array();
		foreach ( $attempts as $i => $attempt ) {
			if ( is_array( $attempt ) && ( $attempt['reference'] ?? '' ) === $reference ) {
				if ( ! empty( $attempts[ $i ]['transaction_id'] ) && $attempts[ $i ]['transaction_id'] !== $tx_id ) {
					// Otra transacción con la misma referencia: el estado y las marcas de rechazo de la anterior no valen para ella.
					unset( $attempts[ $i ]['status'], $attempts[ $i ]['declined_at'], $attempts[ $i ]['decline_counted'], $attempts[ $i ]['decline_recorded'] );
				}
				$attempts[ $i ]['transaction_id'] = $tx_id;
				$attempts[ $i ]                   = \Aralia\Orders\Attempts::clear_hints( $attempts[ $i ] ); // El id verificado por el evento reemplaza cualquier pista.
				if ( null !== $status ) {
					$attempts[ $i ]['status'] = $status;
					// Hora del rechazo (la ventana de 5 rechazos por hora de OrderPay se mide desde aquí).
					if ( in_array( $status, array( 'DECLINED', 'ERROR', 'VOIDED' ), true ) && empty( $attempts[ $i ]['declined_at'] ) ) {
						$attempts[ $i ]['declined_at'] = gmdate( 'c' );
					}
				}
				// La hora del intento no se reescribe: la ventana de 10 min de `api_lag` y de Lookup se mide desde su creación.
				if ( empty( $attempts[ $i ]['at'] ) ) {
					$attempts[ $i ]['at'] = gmdate( 'c' );
				}
			}
		}
		$order->update_meta_data( '_ara_attempts', $attempts );
		$order->save();
	}

	/**
	 * Registra la anulación en `_ara_refund_log` (sin datos personales).
	 *
	 * @param \WC_Order $order Pedido.
	 * @param string    $tx_id Transacción.
	 * @return void
	 */
	private function log_refund( \WC_Order $order, string $tx_id ): void {
		$log   = $order->get_meta( '_ara_refund_log', true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'tipo'      => 'reversal',
			'monto'     => (float) $order->get_total(),
			'fecha'     => gmdate( 'c' ),
			'motivo'    => 'Evento VOIDED de Wompi',
			'ref_wompi' => $tx_id,
			'usuario'   => 'guard',
		);
		$order->update_meta_data( '_ara_refund_log', $log );
		$order->save();
	}

	/**
	 * Cuenta un rechazo contra la IP guardada en el pedido (T036).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return void
	 */
	private function record_decline( \WC_Order $order ): void {
		$ip = (string) $order->get_customer_ip_address();
		if ( '' === $ip || ! class_exists( '\Aralia\Security\AbuseLimits' ) ) {
			return;
		}
		try {
			$limits = new \Aralia\Security\AbuseLimits( new \Aralia\Security\RateLimiter(), \Aralia\Privacy\Crypto::from_constants() );
			$limits->record_decline( $ip );
		} catch ( \Throwable $e ) {
			unset( $e ); // Sin llaves de privacidad o limitador caído: el rechazo igual queda registrado.
		}
	}

	/**
	 * Resuelve el pedido de una referencia `<id>-<n>`: debe existir, ser del gateway wompi y tener ese intento.
	 *
	 * @param string $reference Referencia.
	 * @return \WC_Order|null
	 */
	private function resolve_order( string $reference ): ?\WC_Order {
		if ( 1 !== preg_match( self::REFERENCE, $reference, $m ) ) {
			return null;
		}
		$order = wc_get_order( (int) $m[1] );
		if ( ! $order instanceof \WC_Order || 'wompi' !== $order->get_payment_method() ) {
			return null;
		}
		$attempts = $order->get_meta( '_ara_attempts', true );
		foreach ( is_array( $attempts ) ? $attempts : array() as $attempt ) {
			if ( is_array( $attempt ) && ( $attempt['reference'] ?? '' ) === $reference ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Estructura mínima del evento.
	 *
	 * @param array<string,mixed> $event Evento.
	 * @return bool
	 */
	private function valid_structure( array $event ): bool {
		$tx  = $event['data']['transaction'] ?? null;
		$sig = $event['signature'] ?? null;
		if ( ! is_string( $event['event'] ?? null ) || ! is_array( $tx ) || ! is_array( $sig ) ) {
			return false;
		}
		$props = $sig['properties'] ?? null;
		if ( ! is_array( $props ) || array() === $props || ! is_string( $sig['checksum'] ?? null ) || ! ( is_int( $event['timestamp'] ?? null ) || is_string( $event['timestamp'] ?? null ) ) || 1 !== preg_match( '/^\d{1,13}$/', (string) $event['timestamp'] ) || array() !== array_diff( self::REQUIRED_PROPS, $props ) ) {
			return false;
		}
		foreach ( $props as $p ) {
			if ( ! is_string( $p ) || 1 !== preg_match( '/^[A-Za-z0-9_.]{1,64}$/', $p ) ) {
				return false;
			}
		}
		return is_string( $tx['id'] ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $tx['id'] )
			&& is_string( $tx['reference'] ?? null ) && strlen( $tx['reference'] ) <= 64
			&& ( is_int( $tx['amount_in_cents'] ?? null ) || ( is_string( $tx['amount_in_cents'] ?? null ) && 1 === preg_match( '/^\d{1,15}$/', $tx['amount_in_cents'] ) ) )
			&& is_string( $tx['currency'] ?? null ) && 3 === strlen( $tx['currency'] )
			&& in_array( $tx['status'] ?? null, PaymentEvents::STATUSES, true );
	}

	/**
	 * Checksum del evento: valores de `signature.properties` (en orden, relativos a `data`) + timestamp + secreto, SHA-256 hex en
	 * minúsculas, comparado con `hash_equals` contra el campo y, si viene, la cabecera.
	 *
	 * @param array<string,mixed> $event  Evento.
	 * @param string              $secret Secreto de eventos.
	 * @param string              $header Cabecera X-Event-Checksum.
	 * @return bool
	 */
	private function checksum_ok( array $event, string $secret, string $header ): bool {
		$concat = '';
		foreach ( $event['signature']['properties'] as $path ) {
			$value = $event['data'];
			foreach ( explode( '.', $path ) as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					return false;
				}
				$value = $value[ $segment ];
			}
			if ( ! is_scalar( $value ) ) {
				return false;
			}
			$concat .= is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
		}
		$calc = hash( 'sha256', $concat . (string) $event['timestamp'] . $secret );
		if ( ! hash_equals( $calc, strtolower( (string) $event['signature']['checksum'] ) ) ) {
			return false;
		}
		return '' === $header || hash_equals( $calc, strtolower( $header ) );
	}

	/**
	 * Respuesta uniforme.
	 *
	 * @param int    $status HTTP.
	 * @param string $result Resultado.
	 * @return array{status:int,body:array<string,mixed>}
	 */
	private function reply( int $status, string $result ): array {
		return array(
			'status' => $status,
			'body'   => array(
				'received' => 200 === $status,
				'result'   => $result,
			),
		);
	}
}
