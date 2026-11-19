<?php
/**
 * Símbolos que aporta el entorno en tiempo de ejecución (aralia-core.php y WP-CLI), declarados solo para PHPStan.
 * Propios y mínimos a propósito: no se añade una dependencia de desarrollo solo por los stubs de WP-CLI.
 *
 * @package Aralia
 */

namespace {
	define( 'ARALIA_CORE_VERSION', '0.0.0' );
	define( 'ARALIA_CORE_FILE', __FILE__ );
	define( 'ARALIA_CORE_DIR', __DIR__ . '/' );
	define( 'COOKIEHASH', 'abc' );
	define( 'WPMU_PLUGIN_URL', 'https://example.test/wp-content/mu-plugins' );

	class WP_CLI {
		/**
		 * @param string $message Mensaje.
		 * @return never
		 */
		public static function error( $message ) {
			exit( 1 );
		}

		/**
		 * @param string $message Mensaje.
		 * @return void
		 */
		public static function log( $message ) {}

		/**
		 * @param string $message Mensaje.
		 * @return void
		 */
		public static function success( $message ) {}

		/**
		 * @param string $message Mensaje.
		 * @return void
		 */
		public static function warning( $message ) {}

		/**
		 * @param string       $name Nombre.
		 * @param string|mixed $callable Clase o callable.
		 * @return bool
		 */
		public static function add_command( $name, $callable ) {
			return true;
		}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param array<string,mixed> $assoc_args Argumentos.
	 * @param string              $flag       Nombre.
	 * @param mixed               $default    Valor por defecto.
	 * @return mixed
	 */
	function get_flag_value( $assoc_args, $flag, $default = null ) {
		return $assoc_args[ $flag ] ?? $default;
	}

	/**
	 * @param string                           $format Formato.
	 * @param array<int,array<string,mixed>>   $items  Filas.
	 * @param string[]                         $fields Campos.
	 * @return void
	 */
	function format_items( $format, $items, $fields ) {}
}
