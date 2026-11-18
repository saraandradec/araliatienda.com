<?php
/**
 * Plugin Name: Aralía — endurecimiento
 * Description: DISALLOW_FILE_EDIT en producción, sin XML-RPC, sin enumeración de usuarios, depuración apagada y uploads/ sin PHP (T024).
 * Version: 1.0.0
 *
 * Es un mu-plugin (no se puede desactivar desde wp-admin) y NO depende de aralia-core. DISALLOW_FILE_MODS no se define:
 * las actualizaciones de WordPress y plugins se hacen desde wp-admin (decisión del 2026-11-13).
 *
 * @package Aralia
 */

defined( 'ABSPATH' ) || exit;

/**
 * Entorno actual; vacío si no está definido (se trata como producción: lo más estricto).
 *
 * @return string
 */
function aralia_hardening_env() {
	return defined( 'ARALIA_ENV' ) ? (string) ARALIA_ENV : '';
}

/**
 * Solo local y test relajan las reglas; cualquier otro valor (incluido vacío o mal escrito) endurece.
 *
 * @param string|null $env Entorno.
 * @return bool
 */
function aralia_hardening_is_strict( $env = null ) {
	$env = null === $env ? aralia_hardening_env() : $env;
	return ! in_array( $env, array( 'local', 'test' ), true );
}

// 1. Sin edición de archivos desde el panel (en todo menos local/test). No se define DISALLOW_FILE_MODS.
if ( aralia_hardening_is_strict() && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante de WordPress.
}

// 2. Sin XML-RPC: ni el endpoint ni sus métodos ni las cabeceras que lo anuncian.
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', '__return_empty_array' );
add_filter( 'pings_open', '__return_false', 100 );
remove_action( 'wp_head', 'rsd_link' );
add_filter(
	'wp_headers',
	static function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);
if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
	status_header( 403 );
	exit;
}

/**
 * Quita los endpoints que listan usuarios para quien no puede listarlos.
 *
 * @param array<string,mixed> $endpoints Endpoints REST.
 * @param bool                $can_list  La persona puede listar usuarios.
 * @return array<string,mixed>
 */
function aralia_hardening_filter_rest_endpoints( $endpoints, $can_list ) {
	if ( $can_list ) {
		return $endpoints;
	}
	foreach ( array_keys( $endpoints ) as $route ) {
		if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
			unset( $endpoints[ $route ] );
		}
	}
	return $endpoints;
}
add_filter(
	'rest_endpoints',
	static function ( $endpoints ) {
		return aralia_hardening_filter_rest_endpoints( (array) $endpoints, current_user_can( 'list_users' ) );
	}
);

// 3. Sin archivos de autor (?author=N y /author/x/): 404, para que no revelen nombres de usuario.

/**
 * La petición es de un archivo de autor (o `?author=` en el front).
 *
 * @param bool                $is_author  is_author() de WordPress.
 * @param array<string,mixed> $query      Parámetros GET.
 * @param bool                $is_admin   Es el panel.
 * @return bool
 */
function aralia_hardening_is_author_request( $is_author, $query, $is_admin ) {
	return ! $is_admin && ( $is_author || isset( $query['author'] ) );
}
add_action(
	'template_redirect',
	static function () {
		if ( aralia_hardening_is_author_request( is_author(), $_GET, is_admin() ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo detecta el parámetro.
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;
		}
	},
	1
);
add_filter(
	'wp_sitemaps_add_provider',
	static function ( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	},
	10,
	2
);

// Sin contraseñas de aplicación: autentican por REST con Basic Auth y se saltan el 2FA de Wordfence.
add_filter( 'wp_is_application_passwords_available', '__return_false' );

// oEmbed no revela el autor (su URL /author/<nicename>/ suele derivar del usuario de acceso).
add_filter(
	'oembed_response_data',
	static function ( $data ) {
		if ( is_array( $data ) ) {
			unset( $data['author_name'], $data['author_url'] );
		}
		return $data;
	}
);

// Mensaje de inicio de sesión genérico (no distingue usuario inexistente de contraseña incorrecta).
add_filter(
	'login_errors',
	static function () {
		return 'Los datos de acceso no son correctos.';
	}
);

// 4. Depuración apagada fuera de local/test: no se muestran errores en pantalla (WP_DEBUG se verifica con `wp aralia baseline check`).
if ( aralia_hardening_is_strict() ) {
	@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet, WordPress.PHP.NoSilencedErrors.Discouraged -- endurecimiento deliberado.
}

/**
 * Reglas que impiden ejecutar PHP en uploads/ (Apache y LiteSpeed).
 *
 * @return string
 */
function aralia_hardening_uploads_htaccess_rules() {
	return "# Aralía: sin ejecución de código en uploads/ (T024)\n"
		. "<FilesMatch \"(?i)\\.(php[0-9]?|phtml|phar|pht|phps|shtml|cgi|pl|py|sh)$\">\n"
		. "\tRequire all denied\n"
		. "</FilesMatch>\n"
		. "Options -ExecCGI\n"
		. "RemoveHandler .php .phtml .phar\n"
		. "RemoveType .php .phtml .phar\n";
}

/**
 * Crea (o corrige) el .htaccess de uploads/. Idempotente.
 *
 * @param string $uploads_dir Carpeta uploads/.
 * @return bool Verdadero si el archivo quedó con las reglas.
 */
function aralia_hardening_ensure_uploads_htaccess( $uploads_dir ) {
	if ( '' === $uploads_dir || ! is_dir( $uploads_dir ) || ! is_writable( $uploads_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false;
	}
	$file     = rtrim( $uploads_dir, '/' ) . '/.htaccess';
	$expected = aralia_hardening_uploads_htaccess_rules();
	if ( is_file( $file ) && file_get_contents( $file ) === $expected ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return true;
	}
	return false !== file_put_contents( $file, $expected ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
add_action(
	'admin_init',
	static function () {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['basedir'] ) ) {
			aralia_hardening_ensure_uploads_htaccess( (string) $uploads['basedir'] );
		}
	}
);

// Nunca se aceptan archivos ejecutables por la mediateca (además de las reglas del servidor).
add_filter(
	'upload_mimes',
	static function ( $mimes ) {
		foreach ( array_keys( (array) $mimes ) as $ext_list ) {
			if ( preg_match( '/php|phtml|phar|pht|exe|sh|cgi/i', (string) $ext_list ) ) {
				unset( $mimes[ $ext_list ] );
			}
		}
		return $mimes;
	}
);
