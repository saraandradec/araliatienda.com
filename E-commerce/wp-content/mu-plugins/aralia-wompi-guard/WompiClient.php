<?php
/**
 * Cliente mínimo de la API de Wompi: consulta de una transacción (T059, contracts/wompi §3 paso 5).
 *
 * @package Aralia
 */

namespace Aralia\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /v1/transactions/{id}` con tiempo máximo de 8 s y TLS verificado (el filtro de Tls.php también lo fuerza).
 * Cualquier fallo (red, 5xx, JSON inválido) devuelve null = "no se pudo saber": el llamador difiere y la conciliación termina el trabajo.
 *
 * La llave de la consulta se decide en T-API-AUTH (Sandbox, pendiente): la constante `ARALIA_WOMPI_QUERY_AUTH` vale `none` (por
 * defecto: el endpoint es público), `public` o `private`.
 */
final class WompiClient {

	public const TIMEOUT = 8;

	private const BASE = array(
		'test' => 'https://sandbox.wompi.co/v1',
		'prod' => 'https://production.wompi.co/v1',
	);

	/**
	 * Modo ('test' | 'prod').
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * Motivo del último fallo: '' | 'network' | 'http' | 'auth' | 'no_key' | 'format'. `auth` y `no_key` son de configuración (T-API-AUTH).
	 *
	 * @var string
	 */
	public string $last_error = '';

	/**
	 * Constructor.
	 *
	 * @param string|null $mode Modo (por defecto, el vigente).
	 */
	public function __construct( ?string $mode = null ) {
		$this->mode = 'test' === ( $mode ?? Keys::mode() ) ? 'test' : 'prod';
	}

	/**
	 * Consulta una transacción.
	 *
	 * @param string $transaction_id Id de la transacción.
	 * @return array{id:string,status:string,amount_in_cents:int,currency:string,reference:string,payment_method_type:string}|null
	 *         Los datos; `status = 'NOT_FOUND'` si Wompi no la conoce; null si no se pudo saber.
	 */
	public function transaction( string $transaction_id ): ?array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $transaction_id ) || 1 !== preg_match( '/[A-Za-z0-9]/', $transaction_id ) ) {
			return self::not_found( $transaction_id );
		}
		$this->last_error = '';
		$headers          = array( 'Accept' => 'application/json' );
		$auth             = defined( 'ARALIA_WOMPI_QUERY_AUTH' ) ? (string) ARALIA_WOMPI_QUERY_AUTH : 'none';
		if ( in_array( $auth, array( 'public', 'private' ), true ) ) {
			if ( '' === Keys::get( $auth, $this->mode ) ) {
				$this->last_error = 'no_key'; // Configurado con llave pero falta la constante: no se consulta sin autenticar en silencio.
				return null;
			}
			$headers['Authorization'] = 'Bearer ' . Keys::get( $auth, $this->mode );
		}
		$response = wp_remote_get(
			self::BASE[ $this->mode ] . '/transactions/' . rawurlencode( $transaction_id ),
			array(
				'timeout'     => self::TIMEOUT,
				'sslverify'   => true,
				'redirection' => 0,
				'headers'     => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->last_error = 'network';
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return self::not_found( $transaction_id );
		}
		if ( 200 !== $code ) {
			$this->last_error = in_array( $code, array( 401, 403 ), true ) ? 'auth' : 'http';
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : null;
		if ( null === $data || ! isset( $data['status'], $data['reference'], $data['amount_in_cents'], $data['currency'] ) || ! is_scalar( $data['status'] ) || ! is_scalar( $data['reference'] ) || ! is_scalar( $data['amount_in_cents'] ) || ! is_scalar( $data['currency'] ) ) {
			$this->last_error = 'format';
			return null;
		}
		return array(
			'id'                  => isset( $data['id'] ) && is_scalar( $data['id'] ) ? (string) $data['id'] : $transaction_id,
			'status'              => strtoupper( (string) $data['status'] ),
			'amount_in_cents'     => (int) $data['amount_in_cents'],
			'currency'            => strtoupper( (string) $data['currency'] ),
			'reference'           => (string) $data['reference'],
			'payment_method_type' => isset( $data['payment_method_type'] ) && is_scalar( $data['payment_method_type'] ) ? substr( (string) $data['payment_method_type'], 0, 32 ) : '',
		);
	}

	/**
	 * Respuesta "Wompi no la conoce".
	 *
	 * @param string $transaction_id Id consultado.
	 * @return array{id:string,status:string,amount_in_cents:int,currency:string,reference:string,payment_method_type:string}
	 */
	private static function not_found( string $transaction_id ): array {
		return array(
			'id'                  => $transaction_id,
			'status'              => 'NOT_FOUND',
			'amount_in_cents'     => 0,
			'currency'            => '',
			'reference'           => '',
			'payment_method_type' => '',
		);
	}
}
