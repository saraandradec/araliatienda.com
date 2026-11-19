<?php
/**
 * Comando `wp aralia baseline check` (T032).
 *
 * @package Aralia
 */

namespace Aralia\Cli;

use Aralia\Security\Baseline;
use Aralia\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Verifica la línea base de seguridad.
 */
class BaselineCommand {

	/**
	 * Comprueba la línea base (sin `admin`, sin edición de archivos, sin XML-RPC, cabeceras, HSTS, uploads sin PHP...).
	 *
	 * Sale con código distinto de cero si alguna comprobación falla: ops/deploy.sh lo usa para dar por válido (o no) un despliegue.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<formato>]
	 * : `table` (por defecto) o `json`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aralia baseline check
	 *
	 * @subcommand check
	 *
	 * @param array<int,string>   $args       Argumentos posicionales.
	 * @param array<string,mixed> $assoc_args Argumentos con nombre.
	 * @return void
	 */
	public function check( $args, $assoc_args ): void {
		unset( $args );
		$results = ( new Baseline() )->run( Environment::current() );
		$rows    = array();
		$failed  = 0;
		foreach ( $results as $r ) {
			$rows[]  = array(
				'check'   => $r['id'],
				'estado'  => $r['ok'] ? 'OK' : 'FALLA',
				'detalle' => $r['message'],
			);
			$failed += $r['ok'] ? 0 : 1;
		}
		\WP_CLI\Utils\format_items( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, array( 'check', 'estado', 'detalle' ) );
		if ( $failed > 0 ) {
			\WP_CLI::error( $failed . ' comprobación(es) de la línea base fallaron.' );
		}
		\WP_CLI::success( 'Línea base conforme.' );
	}
}
