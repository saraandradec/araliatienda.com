<?php
/**
 * Cabeceras de seguridad (T025, R-11).
 *
 * @package Aralia
 */

namespace Aralia\Security;

defined( 'ABSPATH' ) || exit;

/**
 * HSTS (solo con HTTPS), nosniff, Referrer-Policy, Permissions-Policy y `frame-ancestors 'self'`.
 * La CSP completa por tipo de página (T033) se une a la cabecera `Content-Security-Policy` ya presente.
 */
class Headers {

	/**
	 * Cabeceras que se envían.
	 *
	 * @param bool $is_ssl La petición es HTTPS.
	 * @return array<string,string>
	 */
	public static function build( bool $is_ssl ): array {
		$headers = array(
			'X-Content-Type-Options'  => 'nosniff',
			'Referrer-Policy'         => 'strict-origin-when-cross-origin',
			// payment solo para el origen propio y el widget de Wompi; sin cámara, micrófono ni geolocalización.
			'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=(), usb=(), payment=(self "https://checkout.wompi.co")',
			'X-Frame-Options'         => 'SAMEORIGIN',
			'Content-Security-Policy' => "frame-ancestors 'self'",
		);
		if ( $is_ssl ) {
			// 1 año con subdominios (R-11). Sin "preload": exige un compromiso que se decide al abrir.
			$headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
		}
		return $headers;
	}

	/**
	 * Engancha las cabeceras a la respuesta de WordPress.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_headers', array( self::class, 'filter_headers' ), 5 );
	}

	/**
	 * Une las cabeceras propias con las existentes (no pisa una CSP más estricta: la une).
	 *
	 * @param array<string,string> $headers Cabeceras de WordPress.
	 * @return array<string,string>
	 */
	public static function filter_headers( $headers ): array {
		$headers = is_array( $headers ) ? $headers : array();
		foreach ( self::build( is_ssl() ) as $name => $value ) {
			if ( 'Content-Security-Policy' === $name && ! empty( $headers[ $name ] ) ) {
				$headers[ $name ] = $headers[ $name ] . ', ' . $value;
				continue;
			}
			$headers[ $name ] = $value;
		}
		return $headers;
	}
}
