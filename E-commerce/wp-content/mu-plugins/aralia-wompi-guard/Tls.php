<?php
/**
 * TLS verificado hacia Wompi (T051, H2).
 *
 * @package Aralia
 */

namespace Aralia\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * El plugin oficial desactiva la verificación del certificado (`sslverify => false`). Toda petición a `*.wompi.co` la lleva activa.
 */
final class Tls {

	/**
	 * Engancha el filtro (prioridad alta: gana a cualquier otro).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'http_request_args', array( self::class, 'force_verify' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Fuerza `sslverify => true` para el dominio de Wompi y sus subdominios.
	 *
	 * @param mixed $args Argumentos de la petición.
	 * @param mixed $url  URL.
	 * @return array<string,mixed>
	 */
	public static function force_verify( $args, $url = '' ): array {
		$args = is_array( $args ) ? $args : array();
		if ( self::is_wompi_host( (string) $url ) ) {
			$args['sslverify'] = true;
		}
		return $args;
	}

	/**
	 * ¿Es `wompi.co` o un subdominio?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_wompi_host( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}
		$host = strtolower( rtrim( $host, '.' ) );
		return 'wompi.co' === $host || '.wompi.co' === substr( $host, -9 );
	}
}
