<?php
/**
 * Espacio de nombres `wp aralia`.
 *
 * @package Aralia
 */

namespace Aralia\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Comandos de Aralía Beauty Store.
 */
class RootCommand {

	/**
	 * Muestra la versión de Aralía Core y de la base de datos.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aralia version
	 *
	 * @param array<int,string>   $args       Argumentos posicionales.
	 * @param array<string,mixed> $assoc_args Argumentos con nombre.
	 * @return void
	 */
	public function version( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		\WP_CLI::log( 'aralia-core ' . ARALIA_CORE_VERSION . ' · db ' . ( new \Aralia\Database\Migrator() )->stored_version() );
	}
}
