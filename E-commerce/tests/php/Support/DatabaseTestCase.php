<?php
/**
 * Base para pruebas que crean tablas reales.
 *
 * WP_UnitTestCase convierte CREATE TABLE en tablas temporales dentro de una transacción; aquí hace falta DDL real
 * (índices únicos, SHOW TABLES, candados), por eso se parte de PHPUnit y se limpia a mano.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Support;

use Aralia\Database\Migrator;

abstract class DatabaseTestCase extends IsolatedTestCase {

	/**
	 * Borra las tablas aralia_* y el estado de migración.
	 *
	 * @return void
	 */
	protected function wipe_aralia_tables(): void {
		global $wpdb;
		foreach ( Migrator::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
		}
		delete_option( Migrator::OPTION_VERSION );
		delete_option( Migrator::OPTION_ERROR );
	}

	protected function set_up() {
		parent::set_up();
		$this->wipe_aralia_tables();
	}

	protected function tear_down() {
		$this->wipe_aralia_tables();
		// Estado base para las demás pruebas (guard, consentimientos, límites...): las tablas existen y la versión está al día.
		( new Migrator() )->run();
		parent::tear_down();
	}

	/**
	 * Inserta sin que WordPress imprima o lance el error de la BD; devuelve si funcionó.
	 *
	 * @param string              $table Nombre completo.
	 * @param array<string,mixed> $row   Fila.
	 * @return bool
	 */
	protected function try_insert( string $table, array $row ): bool {
		global $wpdb;
		$prev = $wpdb->suppress_errors( true );
		$res  = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $prev );
		return false !== $res;
	}
}
