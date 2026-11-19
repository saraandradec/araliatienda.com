<?php
/**
 * Eventos de Wompi firmados con el secreto de PRUEBA y una API simulada (T067).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Support;

use Aralia\Wompi\EventSigner;

final class WompiFixtures {

	/**
	 * Peticiones hechas a la API simulada.
	 *
	 * @var array<int,array{url:string,args:array<string,mixed>}>
	 */
	public static array $requests = array();

	/**
	 * Evento `transaction.updated` firmado.
	 *
	 * @param string              $reference Referencia `<pedido>-<n>`.
	 * @param string              $tx_id     Id de la transacción.
	 * @param string              $status    APPROVED | DECLINED | VOIDED | ERROR | PENDING.
	 * @param int                 $cents     Monto en centavos.
	 * @param array<string,mixed> $tx_over   Cambios al bloque `transaction` (después de firmar no: se firma con ellos).
	 * @return array<string,mixed>
	 */
	public static function event( string $reference, string $tx_id, string $status, int $cents, array $tx_over = array() ): array {
		$event = array(
			'event'       => 'transaction.updated',
			'data'        => array(
				'transaction' => array_merge(
					array(
						'id'                  => $tx_id,
						'reference'           => $reference,
						'amount_in_cents'     => $cents,
						'currency'            => 'COP',
						'status'              => $status,
						'payment_method_type' => 'CARD',
						// Campos personales que NUNCA deben llegar al registro:
						'customer_email'      => 'clienta@example.com',
						'customer_data'       => array( 'full_name' => 'Ana Pérez', 'phone_number' => '3001234567' ),
						'payment_method'      => array( 'extra' => array( 'brand' => 'VISA', 'last_four' => '4242', 'bin' => '424242', 'name' => 'ANA PEREZ' ) ),
					),
					$tx_over
				),
			),
			'environment' => 'test',
			'signature'   => array( 'properties' => array( 'transaction.id', 'transaction.status', 'transaction.amount_in_cents' ) ),
		);
		return EventSigner::sign( $event, (string) ARALIA_WOMPI_EVENTS_TEST, 1700000000 );
	}

	/**
	 * JSON del evento.
	 *
	 * @param array<string,mixed> $event Evento.
	 * @return string
	 */
	public static function json( array $event ): string {
		return (string) wp_json_encode( $event );
	}

	/**
	 * Simula la API de Wompi. `$by_tx`: id => array(status, cents, currency, reference) | 'down' | 404 | 500.
	 *
	 * @param array<string,mixed> $by_tx Respuestas por transacción.
	 * @return void
	 */
	public static function mock_api( array $by_tx ): void {
		self::$requests = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $by_tx ) {
				if ( false === strpos( (string) $url, 'wompi.co/v1/transactions/' ) ) {
					return $pre;
				}
				self::$requests[] = array( 'url' => (string) $url, 'args' => (array) $args );
				$id = rawurldecode( substr( (string) $url, strrpos( (string) $url, '/' ) + 1 ) );
				$r  = $by_tx[ $id ] ?? 404;
				if ( 'down' === $r ) {
					return new \WP_Error( 'http_request_failed', 'timeout' );
				}
				if ( is_int( $r ) ) {
					return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => $r, 'message' => '' ), 'cookies' => array(), 'filename' => null );
				}
				$body = array(
					'data' => array(
						'id'                  => $id,
						'status'              => $r[0],
						'amount_in_cents'     => $r[1],
						'currency'            => $r[2] ?? 'COP',
						'reference'           => $r[3],
						'payment_method_type' => 'CARD',
					),
				);
				return array( 'headers' => array(), 'body' => (string) wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
			},
			10,
			3
		);
	}
}
