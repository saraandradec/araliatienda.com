<?php
/**
 * Plugin Name: Aralía — cargador de secretos
 * Description: Carga aralia-secrets.php (fuera de public_html) antes que cualquier plugin. research R-16.
 * Version: 1.0.0
 *
 * wp-config.php solo define la ruta:  define( 'ARALIA_SECRETS_PATH', '/home/<usuario>/aralia-secrets.php' );
 * Este mu-plugin la carga. Si algo falla, no carga nada y lo registra: sin llaves, el guard de Wompi
 * deja el medio de pago apagado (falla cerrada), y `wp aralia baseline check` marca el error.
 *
 * @package Aralia
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resultado de la última carga, para `wp aralia baseline check` y las pruebas.
 * 'loaded' | 'missing-constant' | 'not-a-php-file' | 'unreadable' | 'inside-webroot' | 'insecure-permissions'
 *
 * @return string
 */
function aralia_secrets_status() {
	return isset( $GLOBALS['aralia_secrets_status'] ) ? (string) $GLOBALS['aralia_secrets_status'] : 'not-run';
}

/**
 * Carga el archivo de secretos con las comprobaciones de R-16.
 *
 * @param string $path    Ruta del archivo (ARALIA_SECRETS_PATH).
 * @param string $webroot Raíz pública del sitio (ABSPATH).
 * @param string $env     ARALIA_ENV ('production', 'staging', 'local', 'test').
 * @return string Estado (ver aralia_secrets_status()).
 */
function aralia_load_secrets( $path, $webroot, $env ) {
	if ( '' === $path ) {
		return 'missing-constant';
	}

	// Solo un archivo .php regular; nada de rutas a directorios, flujos (php://, phar://) ni enlaces raros.
	if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $path ) || '.php' !== substr( $path, -4 ) ) {
		return 'not-a-php-file';
	}

	$real = realpath( $path );
	if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
		return 'unreadable';
	}

	// La extensión se comprueba también sobre el destino real (un enlace x.php hacia otro archivo no vale).
	if ( '.php' !== substr( $real, -4 ) ) {
		return 'not-a-php-file';
	}

	// Falla cerrado: solo 'local' y 'test' son laxos; cualquier otro valor (typo, vacío, 'prod') se trata como servidor.
	$is_relaxed = in_array( $env, array( 'local', 'test' ), true );

	if ( ! $is_relaxed ) {
		$real_webroot = realpath( $webroot );
		if ( false !== $real_webroot && 0 === strpos( $real . DIRECTORY_SEPARATOR, rtrim( $real_webroot, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
			return 'inside-webroot';
		}

		$perms = fileperms( $real );
		if ( false !== $perms && ( $perms & 0o077 ) ) {
			return 'insecure-permissions';
		}
	}

	require_once $real;
	return 'loaded';
}

$GLOBALS['aralia_secrets_status'] = aralia_load_secrets(
	defined( 'ARALIA_SECRETS_PATH' ) ? (string) ARALIA_SECRETS_PATH : '',
	ABSPATH,
	defined( 'ARALIA_ENV' ) ? (string) ARALIA_ENV : 'production'
);

if ( 'loaded' !== $GLOBALS['aralia_secrets_status'] ) {
	// Sin la ruta ni el contenido: solo el motivo.
	error_log( '[aralia] secretos no cargados: ' . $GLOBALS['aralia_secrets_status'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
