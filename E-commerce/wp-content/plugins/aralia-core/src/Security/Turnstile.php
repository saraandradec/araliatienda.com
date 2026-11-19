<?php
/**
 * Turnstile del lado del servidor (T035, R-12).
 *
 * @package Aralia
 */

namespace Aralia\Security;

use Aralia\Support\ClientIp;
use Aralia\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Valida el token de Cloudflare Turnstile antes de crear el pedido. FALLA CERRADO: sin token, con token enorme, con error de red o con una
 * respuesta que no diga `success: true` exactamente, la validación no pasa. La única excepción es local/test sin llave configurada.
 */
class Turnstile {

	public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	public const TOKEN_MAX  = 2048;

	/**
	 * Llaves de PRUEBA de Cloudflare: siempre pasan o siempre fallan. En un entorno estricto serían un bypass total.
	 *
	 * @var string[]
	 */
	public const DUMMY_SECRETS = array( '1x0000000000000000000000000000000AA', '2x0000000000000000000000000000000AA', '3x0000000000000000000000000000000FF' );

	/**
	 * Acción esperada en la respuesta de Cloudflare (se fija en el widget del checkout).
	 */
	public const ACTION = 'checkout';

	/**
	 * Llave secreta.
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * Cliente HTTP (url, args) => array|WP_Error.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param string|null   $secret Llave secreta (por defecto, ARALIA_TURNSTILE_SECRET_KEY).
	 * @param callable|null $http   Cliente HTTP (por defecto, wp_remote_post).
	 */
	public function __construct( ?string $secret = null, ?callable $http = null ) {
		$this->secret = $secret ?? ( defined( 'ARALIA_TURNSTILE_SECRET_KEY' ) ? (string) constant( 'ARALIA_TURNSTILE_SECRET_KEY' ) : '' );
		$this->http   = $http ?? 'wp_remote_post';
	}

	/**
	 * Verifica un token.
	 *
	 * @param string $token Token del cliente.
	 * @param string $ip    IP de quien compra (opcional).
	 * @param string|null $env Entorno (por defecto, ARALIA_ENV; el parámetro existe para probar las ramas estrictas).
	 * @return array{success:bool,codes:string[]}
	 */
	public function verify( string $token, string $ip = '', ?string $env = null ): array {
		$relaxed = Environment::allows_test_tools( $env );
		if ( in_array( $this->secret, self::DUMMY_SECRETS, true ) && ! $relaxed ) {
			// La llave de prueba de Cloudflare no verifica nada: fuera de local/test se trata como un fallo.
			return array(
				'success' => false,
				'codes'   => array( 'dummy-secret-in-strict-env' ),
			);
		}
		if ( '' === $this->secret ) {
			// Sin llave: solo local/test lo dejan pasar (para desarrollar sin cuenta de Cloudflare); en otro entorno se bloquea.
			return $relaxed
				? array(
					'success' => true,
					'codes'   => array( 'skipped-no-secret' ),
				)
				: array(
					'success' => false,
					'codes'   => array( 'missing-secret' ),
				);
		}
		if ( '' === $token ) {
			return array(
				'success' => false,
				'codes'   => array( 'missing-input-response' ),
			);
		}
		if ( strlen( $token ) > self::TOKEN_MAX ) {
			return array(
				'success' => false,
				'codes'   => array( 'invalid-input-response' ),
			);
		}
		$body = array(
			'secret'   => $this->secret,
			'response' => $token,
		);
		if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$body['remoteip'] = $ip;
		}
		$response = ( $this->http )(
			self::VERIFY_URL,
			array(
				'timeout' => 5,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'codes'   => array( 'internal-error' ),
			);
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) ) {
			return array(
				'success' => false,
				'codes'   => array( 'internal-error' ),
			);
		}
		$codes   = isset( $data['error-codes'] ) && is_array( $data['error-codes'] ) ? array_map( 'strval', $data['error-codes'] ) : array();
		$success = true === ( $data['success'] ?? false );
		// Un token emitido para OTRO formulario (newsletter, alerta de stock) o para otro sitio no sirve para el checkout.
		if ( $success && ! $relaxed ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			// Cloudflare devuelve action y hostname en un éxito real: si faltan, no se da por bueno.
			if ( ! isset( $data['action'] ) || self::ACTION !== $data['action'] ) {
				$success = false;
				$codes[] = isset( $data['action'] ) ? 'action-mismatch' : 'action-missing';
			}
			if ( ! isset( $data['hostname'] ) || 0 !== strcasecmp( (string) $data['hostname'], $host ) ) {
				$success = false;
				$codes[] = isset( $data['hostname'] ) ? 'hostname-mismatch' : 'hostname-missing';
			}
		}
		return array(
			'success' => $success,
			'codes'   => $codes,
		);
	}

	/**
	 * Engancha el campo `extensions.aralia.turnstile_token` y la validación al checkout de la Store API.
	 *
	 * @return void
	 */
	public function register(): void {
		// WooCommerce Blocks dispara woocommerce_blocks_loaded en plugins_loaded: si ya ocurrió, se registra ahora.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_extension();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_extension' ) );
		}
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'on_checkout' ), 10, 2 );
		// Checkout clásico (?wc-ajax=checkout): sigue disponible aunque la página use bloques, así que también se valida.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'on_classic_checkout' ), 10, 2 );
	}

	/**
	 * Declara el dato de extensión en el esquema del checkout.
	 *
	 * @return void
	 */
	public function register_extension(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
				'namespace'       => 'aralia',
				'data_callback'   => static fn(): array => array(),
				'schema_callback' => static fn(): array => array(
					'turnstile_token' => array(
						'description' => 'Token de Cloudflare Turnstile.',
						'type'        => 'string',
						'context'     => array(),
						'arg_options' => array( 'validate_callback' => static fn( $value ): bool => is_string( $value ) && strlen( $value ) <= self::TOKEN_MAX ),
					),
				),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Checkout clásico: el token llega en el campo `aralia_turnstile_token`.
	 *
	 * @param array<string,mixed> $data   Datos del checkout.
	 * @param \WP_Error           $errors Errores de validación.
	 * @return void
	 */
	public function on_classic_checkout( $data, $errors ): void {
		unset( $data );
		$token = isset( $_POST['aralia_turnstile_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['aralia_turnstile_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifica su nonce antes de esta validación.
		if ( ! $this->verify( $token, ClientIp::get() )['success'] ) {
			$errors->add( 'aralia_turnstile_failed', 'No pudimos verificar que eres una persona. Recarga la página e inténtalo de nuevo.' );
		}
	}

	/**
	 * Valida el token al crear el pedido.
	 *
	 * @param \WC_Order        $order   Pedido.
	 * @param \WP_REST_Request $request Petición.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Si el token no es válido.
	 */
	public function on_checkout( $order, $request ): void {
		unset( $order );
		// WooCommerce dispara esta acción también en PUT/PATCH (actualización del borrador): el token es de un solo uso y se consume en la
		// creación del pedido (POST). Exigirlo en cada PATCH daría 403 o lo gastaría antes del POST final. OJO: el reintento tras un POST
		// fallido es OTRO POST con el token ya gastado: el widget debe renovarse tras cada respuesta de error (criterio de T089).
		if ( 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			return;
		}
		$extensions = isset( $request['extensions'] ) && is_array( $request['extensions'] ) ? $request['extensions'] : array();
		$token      = isset( $extensions['aralia']['turnstile_token'] ) ? (string) $extensions['aralia']['turnstile_token'] : '';
		$ip         = ClientIp::get();
		$result     = $this->verify( $token, $ip );
		if ( $result['success'] ) {
			return;
		}
		$message = 'No pudimos verificar que eres una persona. Recarga la página e inténtalo de nuevo.';
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'aralia_turnstile_failed', $message, 403 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
}
