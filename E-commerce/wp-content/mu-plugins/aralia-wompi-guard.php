<?php
/**
 * Plugin Name: Aralía — guard de Wompi
 * Description: Toma el control de lo crítico del pago con Wompi: llaves fuera de la BD, TLS verificado, neutralización del plugin oficial, verificación de eventos. contracts/wompi-integration.md.
 * Version: 1.0.0
 *
 * Es un mu-plugin (no se puede desactivar desde wp-admin). WordPress no carga mu-plugins en subcarpetas: este archivo es el cargador y
 * las clases viven en aralia-wompi-guard/ (espacio de nombres Aralia\Guard). Usa aralia-core (máquina de estados, stock, alertas) solo
 * en tiempo de ejecución; sin él, el manejador de eventos falla cerrado.
 *
 * @package Aralia
 */

defined( 'ABSPATH' ) || exit;

define( 'ARALIA_GUARD_DIR', __DIR__ . '/aralia-wompi-guard/' );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Aralia\\Guard\\';
		if ( ! is_string( $class_name ) || 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		if ( '' === $relative || ! preg_match( '/^[A-Za-z0-9_]+$/', $relative ) ) {
			return;
		}
		$file = ARALIA_GUARD_DIR . $relative . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

\Aralia\Guard\Guard::boot();
