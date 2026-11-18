<?php
/**
 * `wp aralia catalog` (T043).
 *
 * @package Aralia
 */

namespace Aralia\Cli;

use Aralia\Catalog\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo desde WP-CLI.
 */
class CatalogCommand {

	/**
	 * Valida un CSV de inventario sin importar nada.
	 *
	 * ## OPTIONS
	 *
	 * <archivo>
	 * : Ruta del CSV.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aralia catalog validate inventario.csv
	 *
	 * @param array<int,string>   $args       Argumentos.
	 * @param array<string,mixed> $assoc_args Opciones.
	 * @return void
	 */
	public function validate( $args, $assoc_args ): void {
		unset( $assoc_args );
		$file = (string) ( $args[0] ?? '' );
		if ( ! is_readable( $file ) || is_dir( $file ) ) {
			\WP_CLI::error( 'No se puede leer el archivo.' );
		}
		if ( filesize( $file ) > Validator::MAX_CSV_BYTES ) {
			\WP_CLI::error( 'El CSV supera 2 MB.' );
		}
		$result = Validator::validate( (string) file_get_contents( $file ), \Aralia\Catalog\Importer::current_prices(), \Aralia\Catalog\Taxonomies::tones() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		foreach ( $result['fatal'] as $msg ) {
			\WP_CLI::error( $msg );
		}
		foreach ( $result['errors'] as $line => $msgs ) {
			foreach ( $msgs as $msg ) {
				\WP_CLI::warning( "Fila $line: " . preg_replace( '/[\x00-\x1F\x7F]/', '?', $msg ) );
			}
		}
		foreach ( $result['warnings'] as $line => $msgs ) {
			foreach ( $msgs as $msg ) {
				\WP_CLI::log( "Aviso fila $line: " . preg_replace( '/[\x00-\x1F\x7F]/', '?', $msg ) );
			}
		}
		foreach ( $result['price_changes'] as $line => $c ) {
			\WP_CLI::log( "Fila $line: {$c['sku']} cambia de {$c['from']} a {$c['to']} (más del 20 %): requiere confirmación." );
		}
		foreach ( $result['incomplete'] as $line => $what ) {
			\WP_CLI::log( "Fila $line: se importará como borrador (falta " . implode( ', ', $what ) . ').' );
		}
		if ( $result['errors'] ) {
			\WP_CLI::error( 'Hay errores bloqueantes: no se importaría nada.' );
		}
		\WP_CLI::success( count( $result['rows'] ) . ' filas válidas.' );
	}
}
