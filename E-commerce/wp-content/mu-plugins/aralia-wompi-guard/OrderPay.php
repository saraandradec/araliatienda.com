<?php
/**
 * Order-pay propio: abre el widget de Wompi con una referencia por intento (T061, R-02, contracts/wompi §2).
 *
 * @package Aralia
 */

namespace Aralia\Guard;

use Aralia\Orders\Expiry;
use Aralia\Orders\OrderLock;
use Aralia\Orders\OrderStore;
use Aralia\Orders\StateMachine;
use Aralia\Orders\Statuses;
use Aralia\Rest\OrderAccess;
use Aralia\Support\Clock;
use Aralia\Support\ClientIp;

defined( 'ABSPATH' ) || exit;

/**
 * La página (GET) no tiene efectos: solo pinta un contenedor con el pedido y su clave. Los intentos se crean con un POST del mismo
 * origen (`POST /aralia/v1/order-pay/open`) que hace `order-pay.js` justo antes de abrir el widget: un escáner de enlaces del correo
 * o una vista previa no gasta aperturas ni la extensión de la reserva. En ese POST, todo bajo el candado del pedido y sobre el pedido
 * recargado de la base de datos: se revisa que siga `pending` y con reserva vigente, que no haya un pago anterior en curso, se crea
 * `<pedido>-<n>` (n = mayor existente + 1), se extiende la reserva y se firma en el servidor.
 *
 * Decisión de diseño (T035/T061, nota del bloque I): la página no tiene formulario de pago propio y la CSP de order-pay solo admite a
 * Wompi, así que no lleva Turnstile; la pantalla nativa «pagar el pedido» (`?pay_for_order=true`) se redirige. El abuso lo acotan la
 * clave del pedido, 30 aperturas por hora, 5 rechazos por hora y el tope de 10 rechazos por hora por origen (threat-model D-2).
 */
final class OrderPay {

	public const MAX_OPENS     = 30;
	public const MAX_DECLINES  = 5;
	public const WIDGET_SRC    = 'https://checkout.wompi.co/widget.js';
	public const ROUTE         = '/order-pay/open';
	public const IP_PER_MIN    = 30;
	public const ORDER_PER_MIN = 10;
	public const REUSE_WITHIN  = 60;
	public const RECENT        = 600;
	/** Máximo de ids que `payment_in_progress` consulta (acota el tiempo bajo el candado); con más, no se abre otro widget. */
	public const MAX_CHECKS = 5;

	/**
	 * Engancha la página, la ruta y las redirecciones de la pantalla nativa.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'woocommerce_receipt_wompi', array( self::class, 'render' ), 10, 1 );
		add_action( 'template_redirect', array( self::class, 'redirect_native_pay_screen' ), 1 );
		add_action( 'woocommerce_before_pay_action', array( self::class, 'block_native_pay_action' ), 1, 1 );
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Ruta `POST /aralia/v1/order-pay/open`.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route(
			'aralia/v1',
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_open' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'order_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'order_key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * La pantalla nativa de WooCommerce para pagar un pedido existente (`?pay_for_order=true`, con su formulario y POST) no se usa con
	 * Wompi: se redirige a esta página. Y un pedido de Wompi que ya no está `pending` (reserva vencida, pagado) no pasa por el aviso
	 * genérico de WooCommerce: va a «Gracias», que muestra su estado real con `order-status`.
	 *
	 * @return void
	 */
	public static function redirect_native_pay_screen(): void {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			header( 'Referrer-Policy: no-referrer' ); // La clave del pedido va en la URL: no sale en el Referer.
		}
		$target = self::native_redirect_target();
		if ( null !== $target ) {
			wp_safe_redirect( $target, 302 );
			exit;
		}
	}

	/**
	 * Adónde redirigir la petición actual (null = nada). Pura respecto al pedido y la URL: se prueba sin `exit`.
	 *
	 * @return string|null
	 */
	public static function native_redirect_target(): ?string {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return null;
		}
		$order = self::order_from_request();
		if ( ! $order instanceof \WC_Order || ! self::is_wompi_order( $order ) ) {
			return null;
		}
		if ( 'pending' !== StateMachine::stored_status( $order->get_id() ) ) {
			return $order->get_checkout_order_received_url();
		}
		if ( isset( $_GET['pay_for_order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo decide una redirección; no hay efectos.
			return self::receipt_url( $order );
		}
		return null;
	}

	/**
	 * Un POST al formulario nativo de pago de un pedido de Wompi no procede.
	 *
	 * @param mixed $order Pedido.
	 * @return void
	 */
	public static function block_native_pay_action( $order ): void {
		if ( $order instanceof \WC_Order && self::is_wompi_order( $order ) ) {
			wp_safe_redirect( self::receipt_url( $order ), 303 );
			exit;
		}
	}

	/**
	 * ¿Es un pedido de Wompi?
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	private static function is_wompi_order( \WC_Order $order ): bool {
		$method = $order->get_payment_method();
		return 'wompi' === $method;
	}

	/**
	 * URL de esta página (la de «recibo» de order-pay, con la clave del pedido, sin `pay_for_order`).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return string
	 */
	public static function receipt_url( \WC_Order $order ): string {
		return remove_query_arg( 'pay_for_order', $order->get_checkout_payment_url( true ) );
	}

	/**
	 * Pedido de la petición actual (por `order-pay` y `key`, con la clave verificada).
	 *
	 * @return \WC_Order|null
	 */
	private static function order_from_request(): ?\WC_Order {
		global $wp;
		$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- La clave del pedido es la autorización.
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		return $order instanceof \WC_Order && '' !== $key && hash_equals( (string) $order->get_order_key(), $key ) ? $order : null;
	}

	/**
	 * Pinta la página (gancho `woocommerce_receipt_wompi`): un contenedor sin efectos. El intento lo crea el POST de `order-pay.js`.
	 *
	 * @param mixed $order_id Pedido.
	 * @return void
	 */
	public static function render( $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( 'aralia-sentinel' === $order->get_created_via() ) {
			echo '<p class="aralia-wompi-pay__msg">Este pedido no admite pago en este momento.</p>';
			return;
		}
		$attrs = array(
			'data-order'    => (string) $order->get_id(),
			'data-key'      => (string) $order->get_order_key(),
			'data-open'     => rest_url( 'aralia/v1' . self::ROUTE ),
			'data-endpoint' => rest_url( 'aralia/v1/payment-attempt' ),
			'data-redirect' => $order->get_checkout_order_received_url(),
		);
		$out   = '<div id="aralia-wompi-pay" class="aralia-wompi-pay"';
		foreach ( $attrs as $name => $value ) {
			$out .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		$out .= '><p class="aralia-wompi-pay__time" data-role="time" aria-live="polite"></p>';
		$out .= '<p class="aralia-wompi-pay__msg" data-role="msg" role="status">Preparando el pago seguro…</p>';
		$out .= '<button type="button" class="aralia-wompi-pay__retry" data-role="retry" hidden>Intentar de nuevo</button></div>';
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cada valor ya salió por esc_attr().
		wp_enqueue_script( 'wompi-widget', self::WIDGET_SRC, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- widget.js no admite versión ni SRI (R-11: se monitorea su contenido).
		wp_enqueue_script( 'aralia-wompi-order-pay', WPMU_PLUGIN_URL . '/aralia-wompi-guard/assets/order-pay.js', array( 'wompi-widget' ), (string) max( 2, (int) filemtime( __DIR__ . '/assets/order-pay.js' ) ), true );
	}

	/**
	 * Atiende el POST de apertura.
	 *
	 * @param \WP_REST_Request $request Petición.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_open( \WP_REST_Request $request ) {
		$limited = OrderAccess::limit( 'order-pay-ip', OrderAccess::ip(), self::IP_PER_MIN );
		if ( null !== $limited ) {
			return $limited;
		}
		$order = OrderAccess::order_with_key( absint( $request->get_param( 'order_id' ) ), (string) $request->get_param( 'order_key' ) );
		if ( $order instanceof \WP_Error ) {
			return $order;
		}
		$limited = OrderAccess::limit( 'order-pay', $order->get_id() . '|' . OrderAccess::ip(), self::ORDER_PER_MIN );
		if ( null !== $limited ) {
			return $limited;
		}
		$response = new \WP_REST_Response( self::open( $order ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Decide si se puede pagar y, si sí, crea el intento. Todo bajo el candado del pedido y con el pedido recargado de la base de datos.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<string,mixed> `state` = open | expired | paid | blocked | processing | unavailable | other (+ datos del widget si `open`).
	 */
	public static function open( \WC_Order $order ): array {
		if ( 'aralia-sentinel' === $order->get_created_via() ) {
			return array( 'state' => 'other' ); // El pedido centinela no abre el widget ni cuenta como intento.
		}
		if ( 'COP' !== $order->get_currency() || ! Keys::checkout_ready() ) {
			return array( 'state' => 'unavailable' );
		}
		// Las consultas a la API (hasta 5 de 8 s) van ANTES del candado, sobre el pedido recargado: el candado se comparte con el webhook y
		// el vencimiento y no debe retenerse mientras Wompi responde. Dentro solo se consultan los ids nuevos o cambiados.
		$fresh = OrderStore::fresh( $order->get_id() );
		$known = array();
		$prune = array();
		if ( $fresh instanceof \WC_Order && 'pending' === StateMachine::stored_status( $order->get_id() ) ) {
			$pre = self::payment_in_progress( $fresh, self::attempts( $fresh ), array() );
			if ( $pre['blocked'] ) {
				return array( 'state' => 'processing' );
			}
			$known = $pre['clear'];
			$prune = $pre['prune'];
		}
		$ran = OrderLock::run(
			$order->get_id(),
			static function () use ( $order, $known, $prune ): array {
				return self::open_locked( $order->get_id(), $known, $prune );
			}
		);
		return $ran['ok'] && is_array( $ran['value'] ) ? $ran['value'] : array( 'state' => 'unavailable' );
	}

	/**
	 * Cuerpo de open() dentro del candado.
	 *
	 * @param int                $order_id Pedido.
	 * @param array<int,string> $known    Ids ya consultados (antes del candado) que no bloquean.
	 * @param array<int,string> $prune    Pistas que la API descartó antes del candado (se borran del pedido).
	 * @return array<string,mixed>
	 */
	private static function open_locked( int $order_id, array $known, array $prune ): array {
		$order = OrderStore::fresh( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return array( 'state' => 'unavailable' );
		}
		$stored = StateMachine::stored_status( $order_id );
		if ( 'cancelled' === $stored ) {
			return array( 'state' => 'expired' );
		}
		if ( in_array( $stored, Statuses::PAID, true ) ) {
			return array( 'state' => 'paid' );
		}
		if ( 'pending' !== $stored ) {
			return array( 'state' => 'other' );
		}
		$attempts = self::attempts( $order );
		if ( array() !== $prune ) {
			// Las pistas que la API ya descartó (NOT_FOUND y viejas, o de otro pedido) dejan de contar para los topes: sin esto, quien tiene
			// la clave podría llenar el pedido de ids falsos y dejar «en proceso» el pago de la clienta.
			$pruned = \Aralia\Orders\Attempts::drop_hints( $attempts, $prune );
			if ( $pruned !== $attempts ) {
				$attempts = $pruned;
				$order->update_meta_data( '_ara_attempts', $attempts );
				$order->save();
			}
		}
		if ( self::opens_last_hour( $attempts ) >= self::MAX_OPENS || self::recent_declines( $attempts ) >= self::MAX_DECLINES || self::origin_blocked( $order ) ) {
			return array( 'state' => 'blocked' );
		}
		// Reserva vencida (Action Scheduler atrasado): no se abre un widget sobre un pedido que está por cancelarse. Se revisan antes los topes
		// (sin efectos); la única extensión de la reserva se usa aquí, dentro del mismo candado.
		if ( (int) $order->get_meta( '_ara_expires_at', true ) <= Clock::now() && ! Expiry::extend_for_widget( $order ) ) {
			return array( 'state' => 'expired' );
		}
		if ( self::payment_in_progress( $order, $attempts, $known )['blocked'] ) {
			return array( 'state' => 'processing' );
		}
		$attempt = self::attempt_for_open( $attempts, $order_id );
		if ( ! $attempt['reused'] ) {
			$attempts[] = $attempt['row'];
			$order->update_meta_data( '_ara_attempts', $attempts );
		}
		Expiry::extend_for_widget( $order ); // Una sola vez, hasta los 35 min (N-04), dentro del mismo candado.
		$order->save();
		$cents = (int) round( (float) $order->get_total() * 100 );
		$ref   = (string) $attempt['row']['reference'];
		return array(
			'state'      => 'open',
			'reference'  => $ref,
			'cents'      => $cents,
			'currency'   => 'COP',
			'public'     => Keys::get( 'public' ),
			'signature'  => self::signature( $ref, $cents, 'COP', Keys::get( 'integrity' ) ),
			'redirect'   => $order->get_checkout_order_received_url(),
			'expires_in' => max( 0, (int) $order->get_meta( '_ara_expires_at', true ) - Clock::now() ),
		);
	}

	/**
	 * Intento a abrir: el último sin transacción y de hace menos de 60 s se reutiliza (doble clic, dos pestañas, reintento del JS);
	 * si no, uno nuevo `<pedido>-<n>` con n = mayor existente + 1.
	 *
	 * @param array<int,array<string,mixed>> $attempts Intentos.
	 * @param int                            $order_id Pedido.
	 * @return array{reused:bool,row:array<string,mixed>}
	 */
	private static function attempt_for_open( array $attempts, int $order_id ): array {
		$last = array() === $attempts ? null : $attempts[ count( $attempts ) - 1 ];
		if ( is_array( $last ) && 'OPENED' === ( $last['status'] ?? '' ) && empty( $last['transaction_id'] ) && array() === \Aralia\Orders\Attempts::hints( $last ) && self::age( $last ) < self::REUSE_WITHIN ) {
			return array(
				'reused' => true,
				'row'    => $last,
			);
		}
		$n = 0;
		foreach ( $attempts as $attempt ) {
			$n = max( $n, (int) ( $attempt['n'] ?? 0 ) );
		}
		++$n;
		return array(
			'reused' => false,
			'row'    => array(
				'n'         => $n,
				'reference' => $order_id . '-' . $n,
				'status'    => 'OPENED',
				'at'        => gmdate( 'c' ),
			),
		);
	}

	/**
	 * ¿Hay un pago anterior PENDIENTE o APROBADO (o que no se pudo verificar)? Se consulta a la API ANTES de abrir otro widget (doble
	 * cobro). Solo cuenta la transacción que es de ESTE intento (referencia, monto y COP): un id ajeno o sembrado no bloquea ni retiene.
	 * Solo un id VERIFICADO que la API aún no conoce bloquea (mientras el intento es reciente, < 10 min); una pista que la API no conoce (o es de otro pedido) no; una que reconoce como de este intento y PENDING/APPROVED sí.
	 *
	 * @param \WC_Order                      $order    Pedido.
	 * @param array<int,array<string,mixed>> $attempts Intentos.
	 * @param array<int,string>              $skip     Claves (`tx:<id>` o `hint:<id>`) que no hace falta volver a consultar (ya se vio que no bloquean).
	 * @return array{blocked:bool,clear:array<int,string>,prune:array<int,string>}
	 */
	private static function payment_in_progress( \WC_Order $order, array $attempts, array $skip ): array {
		$client = new WompiClient();
		$clear  = array();
		$prune  = array();
		$cands  = \Aralia\Orders\Attempts::candidates( $attempts );
		$todo   = array();
		// Los intentos ya cerrados (rechazado, error, anulado) no cuentan.
		foreach ( $cands as $cand ) {
			if ( ! in_array( $cand['attempt']['status'] ?? '', array( 'DECLINED', 'ERROR', 'VOIDED' ), true ) ) {
				$todo[] = $cand;
			}
		}
		foreach ( $todo as $idx => $cand ) {
			$attempt = $cand['attempt'];
			$id      = $cand['id'];
			// La clave distingue id verificado de pista: si una pista pasa a verificada entre la comprobación previa y el candado, se vuelve
			// a consultar. Los ids ya vistos también cuentan para el tope de 5 (acota el tiempo bajo el candado).
			$ck = ( $cand['verified'] ? 'tx:' : 'hint:' ) . $id;
			if ( $idx >= self::MAX_CHECKS ) {
				// Quedan ids sin revisar: si entre ellos hay uno verificado o una pista reciente no se puede descartar un pago en curso y no se
				// abre otro widget (falla cerrado); las pistas viejas sin revisar no bloquean (se podan en las próximas aperturas).
				foreach ( array_slice( $todo, $idx ) as $rest ) {
					if ( $rest['verified'] || \Aralia\Orders\Attempts::hint_age( $rest['attempt'] ) < self::RECENT ) {
						return self::progress( true, $clear, $prune );
					}
				}
				break;
			}
			if ( in_array( $ck, $skip, true ) ) {
				continue;
			}
			$api = $client->transaction( $id );
			if ( null === $api ) {
				return self::progress( true, $clear, $prune ); // No se pudo verificar el pago anterior: no se abre otro (falla cerrado).
			}
			if ( 'NOT_FOUND' === $api['status'] ) {
				// Solo un id VERIFICADO (reportado por el evento) que la API aún no refleja bloquea; una pista sin verificar no. Un NOT_FOUND NO
				// se da por «limpio»: se vuelve a consultar dentro del candado. Una pista NOT_FOUND vieja ya no sirve: se poda.
				if ( $cand['verified'] && self::age( $attempt ) < self::RECENT ) {
					return self::progress( true, $clear, $prune );
				}
				if ( ! $cand['verified'] && \Aralia\Orders\Attempts::hint_age( $attempt ) >= self::RECENT ) {
					$prune[] = $id;
				}
				continue;
			}
			if ( ! Lookup::belongs( $api, $attempt, $order ) ) {
				$clear[] = $ck;
				if ( ! $cand['verified'] ) {
					$prune[] = $id; // Existe pero es de otro pedido, monto o moneda: la pista no sirve.
				}
				continue;
			}
			if ( in_array( $api['status'], array( 'PENDING', 'APPROVED' ), true ) ) {
				return self::progress( true, $clear, $prune );
			}
			$clear[] = $ck;
		}
		return self::progress( false, $clear, $prune );
	}

	/**
	 * Resultado de payment_in_progress.
	 *
	 * @param bool              $blocked ¿Bloquea?
	 * @param array<int,string> $clear   Claves ya vistas que no bloquean.
	 * @param array<int,string> $prune   Pistas que la API descartó (se borran del pedido).
	 * @return array{blocked:bool,clear:array<int,string>,prune:array<int,string>}
	 */
	private static function progress( bool $blocked, array $clear, array $prune ): array {
		return array(
			'blocked' => $blocked,
			'clear'   => $clear,
			'prune'   => $prune,
		);
	}

	/**
	 * ¿El origen (IP del pedido o de la petición) superó el tope de rechazos de la hora? (threat-model D-2).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	private static function origin_blocked( \WC_Order $order ): bool {
		if ( ! class_exists( '\Aralia\Security\AbuseLimits' ) ) {
			return false;
		}
		try {
			$limits = new \Aralia\Security\AbuseLimits( new \Aralia\Security\RateLimiter(), \Aralia\Privacy\Crypto::from_constants() );
		} catch ( \Throwable $e ) {
			unset( $e );
			return false; // Sin llaves de privacidad no hay huellas (`baseline` ya lo marca): no se bloquea por eso.
		}
		foreach ( array_unique( array_filter( array( (string) $order->get_customer_ip_address(), ClientIp::get() ) ) ) as $ip ) {
			if ( $limits->declines_blocked( $ip ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Firma de integridad: SHA-256 de `<referencia><monto en centavos><moneda><secreto de integridad>`.
	 *
	 * @param string $reference Referencia.
	 * @param int    $cents     Centavos.
	 * @param string $currency  Moneda.
	 * @param string $integrity Secreto de integridad.
	 * @return string
	 */
	public static function signature( string $reference, int $cents, string $currency, string $integrity ): string {
		return hash( 'sha256', $reference . $cents . $currency . $integrity );
	}

	/**
	 * Intentos del pedido.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<int,array<string,mixed>>
	 */
	private static function attempts( \WC_Order $order ): array {
		$attempts = $order->get_meta( '_ara_attempts', true );
		return is_array( $attempts ) ? array_values( array_filter( $attempts, 'is_array' ) ) : array();
	}

	/**
	 * Segundos desde que se abrió el intento (muy grande si no hay marca).
	 *
	 * @param array<string,mixed> $attempt Intento.
	 * @return int
	 */
	private static function age( array $attempt ): int {
		$at = isset( $attempt['at'] ) && is_string( $attempt['at'] ) ? strtotime( $attempt['at'] ) : false;
		return false === $at ? PHP_INT_MAX : max( 0, time() - $at );
	}

	/**
	 * Aperturas de la última hora.
	 *
	 * @param array<int,array<string,mixed>> $attempts Intentos.
	 * @return int
	 */
	private static function opens_last_hour( array $attempts ): int {
		return count( array_filter( $attempts, static fn( $a ) => self::age( $a ) < HOUR_IN_SECONDS ) );
	}

	/**
	 * Rechazos de la última hora (por `declined_at`; sin marca, por la hora del intento).
	 *
	 * @param array<int,array<string,mixed>> $attempts Intentos.
	 * @return int
	 */
	private static function recent_declines( array $attempts ): int {
		$count = 0;
		foreach ( $attempts as $attempt ) {
			if ( ! in_array( $attempt['status'] ?? '', array( 'DECLINED', 'ERROR', 'VOIDED' ), true ) ) {
				continue;
			}
			$at = isset( $attempt['declined_at'] ) && is_string( $attempt['declined_at'] ) ? strtotime( $attempt['declined_at'] ) : false;
			if ( ( false !== $at ? time() - $at : self::age( $attempt ) ) < HOUR_IN_SECONDS ) {
				++$count;
			}
		}
		return $count;
	}
}
