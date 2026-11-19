<?php
/**
 * IP del cliente: una sola fuente, no manipulable (A-1/H3 del gate del bloque C).
 *
 * @package Aralia
 */

namespace Aralia\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Se usa REMOTE_ADDR. Solo si esa dirección es un proxy de confianza (constante `ARALIA_TRUSTED_PROXIES`, IPs o CIDR separados por comas:
 * el balanceador de Hostinger o la CDN) se lee `X-Forwarded-For`, de derecha a izquierda, tomando la primera dirección que no sea
 * un proxy de confianza. Así rotar `X-Forwarded-For` o `X-Real-IP` desde internet no cambia la IP que ven los límites, Turnstile y la auditoría.
 * Las IPv4 mapeadas en IPv6 (::ffff:a.b.c.d) se devuelven como IPv4.
 */
final class ClientIp {

	/**
	 * IP del cliente, o cadena vacía si no hay una válida.
	 *
	 * @param array<string,mixed>|null $server  Equivalente a $_SERVER (por defecto, el real).
	 * @param string[]|null            $trusted Proxies de confianza (por defecto, ARALIA_TRUSTED_PROXIES).
	 * @return string
	 */
	public static function get( ?array $server = null, ?array $trusted = null ): string {
		$server  = $server ?? $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se valida con inet_pton abajo.
		$trusted = $trusted ?? self::trusted_from_constant();
		$remote  = self::normalize( isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '' );
		if ( '' === $remote ) {
			return '';
		}
		if ( ! $trusted || ! self::in_list( $remote, $trusted ) || empty( $server['HTTP_X_FORWARDED_FOR'] ) ) {
			return $remote;
		}
		$chain = array_reverse( array_map( 'trim', explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] ) ) );
		foreach ( $chain as $candidate ) {
			$ip = self::normalize( self::strip_port( $candidate ) );
			if ( '' === $ip ) {
				return $remote; // Cadena mal formada: no se confía en nada de ella.
			}
			if ( ! self::in_list( $ip, $trusted ) ) {
				return $ip;
			}
		}
		return $remote;
	}

	/**
	 * Quita el puerto de `1.2.3.4:5678` y `[::1]:80` (algunos balanceadores lo añaden a X-Forwarded-For).
	 *
	 * @param string $candidate Elemento de la cadena.
	 * @return string
	 */
	public static function strip_port( string $candidate ): string {
		$candidate = trim( $candidate );
		if ( preg_match( '/^\[([0-9a-fA-F:.]+)\](?::\d{1,5})?$/', $candidate, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/', $candidate, $m ) ) {
			return $m[1];
		}
		return $candidate;
	}

	/**
	 * Normaliza una IP: valida y desmapea IPv4-en-IPv6.
	 *
	 * @param string $ip IP.
	 * @return string Vacía si no es válida.
	 */
	public static function normalize( string $ip ): string {
		$packed = @inet_pton( trim( $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- avisa con entradas inválidas; se valida abajo.
		if ( false === $packed ) {
			return '';
		}
		if ( 16 === strlen( $packed ) && "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( $packed, 0, 12 ) ) {
			$packed = substr( $packed, 12 );
		}
		return (string) inet_ntop( $packed );
	}

	/**
	 * La IP está en la lista de IPs/CIDR.
	 *
	 * @param string   $ip   IP normalizada.
	 * @param string[] $entries IPs o CIDR.
	 * @return bool
	 */
	public static function in_list( string $ip, array $entries ): bool {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return false;
		}
		foreach ( $entries as $entry ) {
			$entry = trim( (string) $entry );
			$bits  = null;
			if ( false !== strpos( $entry, '/' ) ) {
				[ $entry, $mask ] = explode( '/', $entry, 2 );
				if ( ! ctype_digit( $mask ) ) {
					continue;
				}
				$bits = (int) $mask;
			}
			$normalized_entry = self::normalize( $entry );
			$net              = '' === $normalized_entry ? false : inet_pton( $normalized_entry );
			if ( false === $net || strlen( $net ) !== strlen( $packed ) ) {
				continue;
			}
			$bits = $bits ?? strlen( $net ) * 8;
			if ( $bits < 0 || $bits > strlen( $net ) * 8 ) {
				continue;
			}
			$full = intdiv( $bits, 8 );
			$rest = $bits % 8;
			if ( substr( $packed, 0, $full ) !== substr( $net, 0, $full ) ) {
				continue;
			}
			if ( 0 === $rest || ( ( ord( $packed[ $full ] ) ^ ord( $net[ $full ] ) ) >> ( 8 - $rest ) ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Proxies de confianza de la constante.
	 *
	 * @return string[]
	 */
	private static function trusted_from_constant(): array {
		if ( ! defined( 'ARALIA_TRUSTED_PROXIES' ) ) {
			return array();
		}
		return self::filter_entries( explode( ',', (string) constant( 'ARALIA_TRUSTED_PROXIES' ) ) );
	}

	/**
	 * Filtra las entradas de la lista de confianza: descarta (y registra) las de máscara amplia (< /16 en IPv4, < /32 en IPv6) o ilegible.
	 * Una máscara amplia confiaría en rangos enormes de internet y permitiría falsificar X-Forwarded-For.
	 *
	 * @param string[] $entries Entradas (IP o CIDR).
	 * @return string[] Las aceptadas.
	 */
	public static function filter_entries( array $entries ): array {
		$ok = array();
		foreach ( array_filter( array_map( 'trim', $entries ) ) as $entry ) {
			$ip_part = $entry;
			$mask    = null;
			if ( false !== strpos( $entry, '/' ) ) {
				$ip_part = substr( $entry, 0, (int) strrpos( $entry, '/' ) );
				$mask    = substr( $entry, (int) strrpos( $entry, '/' ) + 1 );
			}
			$packed = @inet_pton( $ip_part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$valid  = false !== $packed;
			if ( $valid && null !== $mask ) {
				// Sintaxis IPv6 (lleva ":") => bits de 0 a 128 y mínimo /32; IPv4 => bits de 0 a 32 y mínimo /16. Un IPv4 mapeado con máscara se descarta (ambiguo).
				$is_v6_syntax = false !== strpos( $ip_part, ':' );
				$max          = $is_v6_syntax ? 128 : 32;
				$min          = $is_v6_syntax ? 32 : 16;
				$mapped       = $is_v6_syntax && 16 === strlen( (string) $packed ) && "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( (string) $packed, 0, 12 );
				$valid        = ctype_digit( $mask ) && (int) $mask >= $min && (int) $mask <= $max && ! $mapped;
			}
			if ( ! $valid ) {
				error_log( '[aralia] ARALIA_TRUSTED_PROXIES: entrada descartada (ilegible, máscara fuera de rango o demasiado amplia)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				continue;
			}
			$ok[] = $entry;
		}
		return $ok;
	}
}
