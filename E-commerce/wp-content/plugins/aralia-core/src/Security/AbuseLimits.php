<?php
/**
 * Límites anti-abuso (T036, R-12).
 *
 * @package Aralia
 */

namespace Aralia\Security;

use Aralia\Privacy\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Decide si un intento de crear un pedido se permite, exige el desafío visible de Turnstile o se bloquea.
 * Los contadores de ventana usan RateLimiter con huellas (nunca IP, celular o correo en claro). Los pedidos pendientes se cuentan por
 * metadatos con huella en el pedido (`_ara_phone_hash`, `_ara_email_hash`, `_ara_ip_key`) que se guardan al crearlo.
 */
class AbuseLimits {

	public const MAX_PENDING_PER_CONTACT = 2;   // Por celular y por correo (bloquea al intentar el tercero).
	public const MAX_PENDING_PER_IP      = 8;   // Señal blanda (CGNAT): por encima exige desafío visible.
	public const MAX_UNITS_PER_LINE      = 3;
	public const DECLINES_CHALLENGE      = 5;   // Más de 5 rechazos/h desde una IP: desafío visible.
	public const DECLINES_BLOCK          = 10;  // Más de 10/h: creación bloqueada.
	public const COUPON_ATTEMPTS         = 10;  // Por sesión + IP y hora.
	public const COUPON_ATTEMPTS_PER_IP  = 30;  // Por IP sola (rotar la cookie de sesión no lo reinicia).
	public const WINDOW                  = HOUR_IN_SECONDS;
	public const PENDING_SCAN_LIMIT      = 500; // Pedidos pendientes que se revisan para contar; más que eso, falla cerrado.

	/**
	 * Limitador.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Cripto.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	/**
	 * Cuentas de pendientes ya hechas en esta petición.
	 *
	 * @var array<string,int>
	 */
	private array $pending_memo = array();

	/**
	 * Contador de pedidos pendientes (kind, hash) => int.
	 *
	 * @var callable
	 */
	private $pending_counter;

	/**
	 * Consulta de pedidos (por defecto wc_get_orders): se inyecta en pruebas para simular un almacenamiento que ignora el filtro.
	 *
	 * @var callable
	 */
	private $order_query;

	/**
	 * Constructor.
	 *
	 * @param RateLimiter   $limiter         Limitador.
	 * @param Crypto        $crypto          Cripto.
	 * @param callable|null $pending_counter Contador de pendientes (por defecto, consulta de WooCommerce por metadatos con huella).
	 * @param callable|null $order_query     Consulta de pedidos (por defecto, wc_get_orders).
	 */
	public function __construct( RateLimiter $limiter, Crypto $crypto, ?callable $pending_counter = null, ?callable $order_query = null ) {
		$this->order_query     = $order_query ?? 'wc_get_orders';
		$this->limiter         = $limiter;
		$this->crypto          = $crypto;
		$this->pending_counter = $pending_counter ?? array( $this, 'count_pending_orders' );
	}

	/**
	 * Cuenta pedidos pendientes por una huella guardada en el pedido.
	 *
	 * Primero usa el filtro por metadato de wc_get_orders (rápido, con tope de resultados) y VERIFICA cada pedido devuelto: si el
	 * almacenamiento de pedidos ignora el filtro (pasa con el de entradas), cae a revisar los pendientes en PHP, con tope.
	 * Si no se puede contar con certeza devuelve PHP_INT_MAX; quien llama lo degrada a "desafío visible" (no bloquea a toda la tienda).
	 *
	 * @param string $kind phone | email | ip.
	 * @param string $hash Huella.
	 * @return int
	 */
	public function count_pending_orders( string $kind, string $hash ): int {
		$meta = array(
			'phone' => '_ara_phone_hash',
			'email' => '_ara_email_hash',
			'ip'    => '_ara_ip_key',
		);
		if ( ! isset( $meta[ $kind ] ) || ! function_exists( 'wc_get_orders' ) ) {
			return PHP_INT_MAX;
		}
		$memo = $kind . '|' . $hash;
		if ( isset( $this->pending_memo[ $memo ] ) ) {
			return $this->pending_memo[ $memo ];
		}
		$limit    = self::MAX_PENDING_PER_IP + 2;
		$ids      = (array) ( $this->order_query )(
			array(
				'status'     => array( 'pending' ),
				'limit'      => $limit,
				'return'     => 'ids',
				'meta_key'   => $meta[ $kind ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$count    = 0;
		$filtered = true;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order && hash_equals( $hash, (string) $order->get_meta( $meta[ $kind ] ) ) ) {
				++$count;
			} else {
				$filtered = false; // El almacenamiento no aplicó el filtro.
			}
		}
		if ( ! $filtered ) {
			$orders = (array) ( $this->order_query )(
				array(
					'status' => array( 'pending' ),
					'limit'  => self::PENDING_SCAN_LIMIT,
					'return' => 'objects',
				)
			);
			if ( count( $orders ) >= self::PENDING_SCAN_LIMIT ) {
				return $this->pending_memo[ $memo ] = PHP_INT_MAX; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
			}
			$count = 0;
			foreach ( $orders as $order ) {
				if ( hash_equals( $hash, (string) $order->get_meta( $meta[ $kind ] ) ) ) {
					++$count;
				}
			}
		}
		$this->pending_memo[ $memo ] = $count;
		return $count;
	}

	/**
	 * Evalúa un intento de crear un pedido.
	 *
	 * @param string          $ip         IP.
	 * @param string          $phone      Celular.
	 * @param string          $email      Correo.
	 * @param array<int,int>  $quantities Cantidad por línea del carrito.
	 * @return array{decision:string,reasons:string[]} decision: allow | challenge | block.
	 */
	public function evaluate_checkout( string $ip, string $phone, string $email, array $quantities ): array {
		$block     = array();
		$challenge = array();

		foreach ( $quantities as $qty ) {
			if ( (int) $qty > self::MAX_UNITS_PER_LINE ) {
				$block[] = 'units_per_line';
				break;
			}
		}
		try {
			if ( $this->pending_for( 'phone', $this->crypto->phone_hash( $phone ) ) >= self::MAX_PENDING_PER_CONTACT ) {
				$block[] = 'pending_phone';
			}
			// Para límites el correo se normaliza sin alias (+etiqueta, puntos de Gmail).
			if ( $this->pending_for( 'email', $this->crypto->email_limit_hash( $email ) ) >= self::MAX_PENDING_PER_CONTACT ) {
				$block[] = 'pending_email';
			}
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			$block[] = 'invalid_contact';
		}
		try {
			$ip_key = $this->crypto->ip_key( $ip );
			if ( $this->pending_for( 'ip', $ip_key ) > self::MAX_PENDING_PER_IP ) {
				$challenge[] = 'pending_ip';
			}
			$declines = $this->limiter->count( $ip_key, 'decline', self::WINDOW );
			if ( $this->limiter->count( $ip_key, 'decline_block', self::WINDOW ) > 0 || $declines > self::DECLINES_BLOCK ) {
				$block[] = 'declines';
			} elseif ( $declines > self::DECLINES_CHALLENGE ) {
				$challenge[] = 'declines';
			}
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			$block[] = 'invalid_ip';
		} catch ( RateLimitUnavailable $e ) {
			unset( $e );
			// Si los contadores no responden no se bloquea a toda la tienda: se exige el desafío visible.
			$challenge[] = 'limits_unavailable';
		}
		if ( in_array( true, array_map( static fn( $v ): bool => PHP_INT_MAX === $v, $this->pending_memo ), true ) ) {
			$challenge[] = 'pending_unknown';
		}

		if ( $block ) {
			return array(
				'decision' => 'block',
				'reasons'  => array_values( array_unique( array_merge( $block, $challenge ) ) ),
			);
		}
		return array(
			'decision' => $challenge ? 'challenge' : 'allow',
			'reasons'  => array_values( array_unique( $challenge ) ),
		);
	}

	/**
	 * Pendientes de una huella.
	 *
	 * @param string $kind Tipo.
	 * @param string $hash Huella.
	 * @return int
	 */
	private function pending_for( string $kind, string $hash ): int {
		$count = (int) ( $this->pending_counter )( $kind, $hash );
		if ( PHP_INT_MAX === $count ) {
			// No se pudo contar con certeza: se registra para exigir el desafío visible y no se cuenta como "muchos".
			$this->pending_memo[ $kind . '|' . $hash ] = PHP_INT_MAX;
			return 0;
		}
		return $count;
	}

	/**
	 * Registra un pago rechazado asociado a la IP guardada en el pedido (los eventos de Wompi se asocian a esa IP).
	 *
	 * @param string $ip IP del pedido.
	 * @return int Rechazos de la hora.
	 */
	public function record_decline( string $ip ): int {
		$key   = $this->crypto->ip_key( $ip );
		$count = $this->limiter->hit( $key, 'decline', self::WINDOW );
		if ( $count > self::DECLINES_BLOCK ) {
			// El bloqueo dura 1 h desde que se cruza el umbral (su propia ventana), no lo que quede de la ventana de conteo.
			$this->limiter->hit( $key, 'decline_block', self::WINDOW );
		}
		return $count;
	}

	/**
	 * Registra un intento de código de descuento. Falla cerrado si el limitador no responde.
	 *
	 * @param string $session_id Identificador de sesión (cualquier valor: se limita su longitud).
	 * @param string $ip         IP.
	 * @return array{allowed:bool,attempts:int,needs_turnstile:bool}
	 */
	public function coupon_attempt( string $session_id, string $ip ): array {
		try {
			$ip_key = $this->crypto->ip_key( $ip );
			// Además del contador por sesión + IP (que se esquiva cambiando la cookie), hay uno por IP sola con un tope mayor.
			$by_ip    = $this->limiter->hit( $ip_key, 'coupon_ip', self::WINDOW );
			$attempts = $this->limiter->hit( $this->crypto->key_hash( 'coupon', substr( $session_id, 0, 64 ) . '|' . $ip_key ), 'coupon', self::WINDOW );
		} catch ( \InvalidArgumentException | RateLimitUnavailable $e ) {
			unset( $e );
			return array(
				'allowed'         => false,
				'attempts'        => PHP_INT_MAX,
				'needs_turnstile' => true,
			);
		}
		return array(
			'allowed'         => $attempts <= self::COUPON_ATTEMPTS && $by_ip <= self::COUPON_ATTEMPTS_PER_IP,
			'attempts'        => $attempts,
			// Los intentos de código siempre llevan Turnstile (R-12).
			'needs_turnstile' => true,
		);
	}

	/**
	 * Huellas que se guardan en el pedido para contar pendientes después.
	 *
	 * @param string $ip    IP.
	 * @param string $phone Celular.
	 * @param string $email Correo.
	 * @return array<string,string> meta => valor.
	 */
	public function order_meta( string $ip, string $phone, string $email ): array {
		return array(
			'_ara_ip_key'     => $this->crypto->ip_key( $ip ),
			'_ara_phone_hash' => $this->crypto->phone_hash( $phone ),
			'_ara_email_hash' => $this->crypto->email_limit_hash( $email ),
		);
	}
}
