<?php
/**
 * Firma de eventos de Wompi para pruebas (contracts/wompi-integration.md §3, paso 2).
 *
 * @package Aralia
 */

namespace Aralia\Wompi;

defined( 'ABSPATH' ) || exit;

/**
 * Calcula el checksum igual que lo verifica el guard: concatenación de los valores de `signature.properties`
 * (en orden) + `timestamp` + secreto de eventos, SHA-256 en hexadecimal.
 */
final class EventSigner {

	/**
	 * Propiedades firmadas por defecto.
	 *
	 * @var string[]
	 */
	public const DEFAULT_PROPERTIES = array( 'transaction.id', 'transaction.status', 'transaction.amount_in_cents' );

	/**
	 * Añade `signature` y `timestamp` a un evento.
	 *
	 * @param array<string,mixed> $event     Evento (con `data`).
	 * @param string              $secret    Secreto de eventos DE PRUEBA.
	 * @param int|null            $timestamp Marca de tiempo (por defecto, ahora).
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException Si falta el secreto, una propiedad o el evento no tiene `data`.
	 */
	public static function sign( array $event, string $secret, ?int $timestamp = null ): array {
		if ( '' === $secret ) {
			throw new \InvalidArgumentException( 'Falta el secreto de eventos.' );
		}
		if ( ! isset( $event['data'] ) || ! is_array( $event['data'] ) ) {
			throw new \InvalidArgumentException( 'El evento no trae data.' );
		}
		$props = $event['signature']['properties'] ?? self::DEFAULT_PROPERTIES;
		if ( ! is_array( $props ) || array() === $props ) {
			throw new \InvalidArgumentException( 'signature.properties inválido.' );
		}
		$timestamp          = $timestamp ?? ( $event['timestamp'] ?? time() );
		$event['timestamp'] = (int) $timestamp;

		$concat = '';
		foreach ( $props as $path ) {
			if ( ! is_string( $path ) || '' === $path ) {
				throw new \InvalidArgumentException( 'Propiedad de firma inválida.' );
			}
			$value = $event['data'];
			foreach ( explode( '.', $path ) as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					throw new \InvalidArgumentException( 'Falta la propiedad firmada: ' . esc_html( $path ) );
				}
				$value = $value[ $segment ];
			}
			if ( ! is_scalar( $value ) ) {
				throw new \InvalidArgumentException( 'La propiedad firmada no es un valor simple: ' . esc_html( $path ) );
			}
			$concat .= (string) $value;
		}

		$event['signature'] = array(
			'properties' => array_values( $props ),
			'checksum'   => hash( 'sha256', $concat . $event['timestamp'] . $secret ),
		);
		return $event;
	}
}
