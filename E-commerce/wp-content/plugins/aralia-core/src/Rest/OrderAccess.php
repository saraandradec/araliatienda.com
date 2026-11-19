<?php
/**
 * Acceso a un pedido desde los endpoints públicos de pago (contracts/rest-api.md §8, §8b).
 *
 * @package Aralia
 */

namespace Aralia\Rest;

use Aralia\Security\RateLimiter;
use Aralia\Support\ClientIp;

defined( 'ABSPATH' ) || exit;

/**
 * Valida la clave del pedido (no adivinable), la sesión con la que se creó y los límites de frecuencia. Ningún mensaje distingue
 * "el pedido no existe" de "la clave no es válida" (no se puede enumerar pedidos).
 */
final class OrderAccess {

	public const MESSAGE_DENIED  = 'No pudimos verificar tu pedido.';
	public const MESSAGE_LIMITED = 'Demasiados intentos. Espera un momento e inténtalo de nuevo.';

	/**
	 * Error uniforme `{ code, message }`.
	 *
	 * @param string $code    Código.
	 * @param string $message Texto de marca.
	 * @param int    $status  HTTP.
	 * @return \WP_Error
	 */
	public static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Pedido + clave: 403 si el pedido no existe o la clave no coincide (comparación de tiempo constante).
	 *
	 * @param int    $order_id Pedido.
	 * @param string $key      Clave del pedido.
	 * @return \WC_Order|\WP_Error
	 */
	public static function order_with_key( int $order_id, string $key ) {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) || 'wompi' !== $order->get_payment_method() ) {
			return self::error( 'aralia_order_denied', self::MESSAGE_DENIED, 403 );
		}
		return $order;
	}

	/**
	 * ¿La sesión actual es la que creó el pedido? (huella guardada al crearlo; sin ella, nadie la cumple: falla cerrado).
	 *
	 * Se lee la cookie de sesión de WooCommerce SIN cargar su manejador: en REST (sin `X-WP-Nonce` el usuario es 0), cargarlo vería
	 * una cookie de usuaria registrada sin usuario y DESTRUIRÍA su sesión y su carrito. Aquí solo se lee y se verifica la firma.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	public static function same_session( \WC_Order $order ): bool {
		$stored  = (string) $order->get_meta( '_ara_session_hash', true );
		$current = self::session_customer_id();
		return '' !== $stored && null !== $current && hash_equals( $stored, self::session_hash( $current ) );
	}

	/**
	 * Id de cliente de la cookie de sesión de WooCommerce, con su firma verificada (mismo formato que `WC_Session_Handler`:
	 * `id|vencimiento|vence-pronto|hash` (o con `||`, el formato antiguo), hash = HMAC-MD5 de `id|vencimiento` con `wp_hash` como clave). Null si falta o es falsa.
	 *
	 * @return string|null
	 */
	public static function session_customer_id(): ?string {
		$name = (string) apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Gancho de WooCommerce: mismo nombre de cookie que su manejador.
		if ( empty( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
			return null;
		}
		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
		// Mismo criterio que `WC_Session_Handler::get_session_cookie()`: el formato actual separa con `|`; `||` es el de versiones antiguas.
		$parts = explode( false !== strpos( $raw, '||' ) ? '||' : '|', $raw );
		if ( 4 !== count( $parts ) ) {
			return null;
		}
		[ $customer_id, $expiration, , $cookie_hash ] = $parts;
		if ( '' === $customer_id || '' === $cookie_hash || ! ctype_digit( $expiration ) || (int) $expiration < time() ) {
			return null;
		}
		$to_hash = $customer_id . '|' . $expiration;
		if ( ! hash_equals( hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) ), $cookie_hash ) ) {
			return null;
		}
		return $customer_id;
	}

	/**
	 * Huella de un id de sesión de WooCommerce.
	 *
	 * @param string $customer_id Id de cliente de la sesión.
	 * @return string
	 */
	public static function session_hash( string $customer_id ): string {
		return hash_hmac( 'sha256', 'wc-session|' . $customer_id, wp_salt( 'auth' ) );
	}

	/**
	 * Límite de frecuencia por ventana de un minuto. Falla cerrado si el limitador no responde.
	 *
	 * @param string $scope   Endpoint.
	 * @param string $subject Quién (IP o pedido).
	 * @param int    $max     Máximo por minuto.
	 * @return \WP_REST_Response|null Respuesta 429 (con `Retry-After`) o null si pasa.
	 */
	public static function limit( string $scope, string $subject, int $max ) {
		try {
			$hits = ( new RateLimiter() )->hit( hash_hmac( 'sha256', $scope . '|' . $subject, wp_salt( 'auth' ) ), 'rest_' . substr( $scope, 0, 24 ), MINUTE_IN_SECONDS );
		} catch ( \Throwable $e ) {
			unset( $e );
			$hits = $max + 1; // Si el limitador no responde, se niega (falla cerrado).
		}
		if ( $hits <= $max ) {
			return null;
		}
		$response = new \WP_REST_Response(
			array(
				'code'    => 'aralia_rate_limited',
				'message' => self::MESSAGE_LIMITED,
			),
			429
		);
		$response->header( 'Retry-After', '60' );
		return $response;
	}

	/**
	 * IP del cliente para los límites.
	 *
	 * @return string
	 */
	public static function ip(): string {
		$ip = ClientIp::get();
		if ( false !== strpos( $ip, ':' ) ) {
			// IPv6: se limita por /64 (una persona suele tener todo un /64; rotar dentro de él no debe evadir el límite).
			$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ya validada por ClientIp.
			if ( false !== $packed && 16 === strlen( $packed ) ) {
				return (string) inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
			}
		}
		return $ip;
	}

	/**
	 * Engancha la retirada de las cabeceras CORS en las rutas `aralia/v1` (WordPress refleja `Origin` con credenciales): estos
	 * endpoints son del mismo origen y no deben poder leerse desde otro sitio.
	 *
	 * @return void
	 */
	public static function register_cors_guard(): void {
		add_filter( 'rest_pre_serve_request', array( self::class, 'strip_cors' ), 99, 3 );
	}

	/**
	 * Quita las cabeceras CORS si la ruta es de `aralia/v1`.
	 *
	 * @param mixed $served  Si ya se sirvió.
	 * @param mixed $result  Resultado.
	 * @param mixed $request Petición.
	 * @return mixed
	 */
	public static function strip_cors( $served, $result = null, $request = null ) {
		unset( $result );
		if ( $request instanceof \WP_REST_Request && 0 === strpos( $request->get_route(), '/aralia/v1/' ) && ! headers_sent() ) {
			header_remove( 'Access-Control-Allow-Origin' );
			header_remove( 'Access-Control-Allow-Credentials' );
			header_remove( 'Access-Control-Allow-Methods' );
			header_remove( 'Access-Control-Allow-Headers' );
		}
		return $served;
	}
}
