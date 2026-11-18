<?php
/**
 * Comando `wp aralia setup`.
 *
 * @package Aralia
 */

namespace Aralia\Cli;

use Aralia\Database\Migrator;
use Aralia\Setup\SampleCatalog;
use Aralia\Setup\StoreConfig;
use Aralia\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Prepara la tienda: tablas, configuración de WooCommerce y, opcionalmente, un catálogo de ejemplo.
 */
class SetupCommand {

	/**
	 * Aplica la configuración base de la tienda (idempotente).
	 *
	 * ## OPTIONS
	 *
	 * [--sample-catalog]
	 * : Crea categorías, atributos y productos de ejemplo (solo local y test; se niega en cualquier otro entorno).
	 *
	 * [--dry-run]
	 * : Muestra qué cambiaría sin aplicarlo.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aralia setup
	 *     wp aralia setup --sample-catalog
	 *
	 * @param array<int,string>   $args       Argumentos posicionales.
	 * @param array<string,mixed> $assoc_args Argumentos con nombre.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ): void {
		unset( $args );
		$dry    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$sample = \WP_CLI\Utils\get_flag_value( $assoc_args, 'sample-catalog', false );

		if ( ! class_exists( 'WooCommerce' ) ) {
			\WP_CLI::error( 'WooCommerce no está activo.' );
		}
		if ( $sample && ! Environment::allows_test_tools() ) {
			\WP_CLI::error( '--sample-catalog solo corre en local o test (ARALIA_ENV=' . Environment::current() . ').' );
		}

		if ( $dry ) {
			foreach ( StoreConfig::diff() as $option => $change ) {
				\WP_CLI::log( sprintf( '%s: "%s" → "%s"', $option, $change['from'], $change['to'] ) );
			}
			if ( ! StoreConfig::hpos_switch_is_safe() ) {
				\WP_CLI::warning( 'HPOS no se cambiaría: ya hay pedidos (migra con `wp wc hpos enable`).' );
			}
			\WP_CLI::success( 'Simulación: no se cambió nada.' );
			return;
		}

		$migration = ( new Migrator() )->run();
		if ( '' !== $migration['error'] ) {
			\WP_CLI::error( 'Migraciones: ' . $migration['error'] );
		}
		\WP_CLI::log( 'Base de datos en versión ' . $migration['version'] . '.' );

		try {
			$changes = StoreConfig::apply();
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::log( count( $changes ) . ' ajuste(s) aplicado(s).' );
		if ( ! StoreConfig::hpos_switch_is_safe() ) {
			\WP_CLI::warning( 'HPOS sigue apagado: ya hay pedidos y no se cambia con update_option. Migra con `wp wc hpos enable` (con sincronización).' );
		}
		if ( StoreConfig::diff() ) {
			\WP_CLI::error( 'La configuración no quedó como se esperaba.' );
		}

		if ( $sample ) {
			$created = ( new SampleCatalog() )->install();
			\WP_CLI::log( sprintf( 'Catálogo de ejemplo: %d categorías, %d atributos, %d productos nuevos.', $created['categories'], $created['attributes'], $created['products'] ) );
		}
		\WP_CLI::success( 'Tienda configurada.' );
	}
}
