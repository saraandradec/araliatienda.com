<?php
/**
 * Plugin Name: Aralía Core
 * Description: Lógica de la tienda Aralía Beauty Store (catálogo, pedidos, seguridad, privacidad). Código propio.
 * Version: 0.1.0
 * Requires at least: 7.1
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * Text Domain: aralia
 *
 * Autoloader propio PSR-4 (Aralia\ => src/): en producción no hay Composer (solo se despliega código propio).
 *
 * @package Aralia
 */

defined( 'ABSPATH' ) || exit;

define( 'ARALIA_CORE_VERSION', '0.1.0' );
define( 'ARALIA_CORE_FILE', __FILE__ );
define( 'ARALIA_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARALIA_CORE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader PSR-4 de Aralia\. Solo resuelve nombres bien formados dentro de src/.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Aralia\\';
		if ( ! is_string( $class_name ) || 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		// Solo letras, números, guion bajo y separador de espacio de nombres: sin "..", "/" ni caracteres raros.
		if ( '' === $relative || ! preg_match( '/^[A-Za-z0-9_]+(\\\\[A-Za-z0-9_]+)*$/', $relative ) ) {
			return;
		}
		$file = ARALIA_CORE_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	// Falla cerrado y visible: el plugin no se carga con una versión de PHP no soportada.
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>Aralía Core requiere PHP 8.2 o superior.</p></div>';
		}
	);
	return;
}

register_activation_hook( __FILE__, array( \Aralia\Plugin::class, 'activate' ) );

\Aralia\Plugin::instance()->boot();
