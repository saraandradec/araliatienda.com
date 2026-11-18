<?php
/**
 * T022: migraciones e índices únicos de las 6 tablas aralia_*.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Database\Migrator;
use Aralia\Tests\Support\DatabaseTestCase;

/**
 * @group migrations
 */
final class MigrationsTest extends DatabaseTestCase {

	/**
	 * Carpetas temporales creadas por la prueba (se borran siempre en tear_down).
	 *
	 * @var string[]
	 */
	private array $tmp_dirs = array();

	protected function tear_down() {
		foreach ( $this->tmp_dirs as $dir ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $dir );
		}
		$this->tmp_dirs = array();
		parent::tear_down();
	}

	private function tmp_dir(): string {
		$dir = sys_get_temp_dir() . '/aralia-mig-' . uniqid();
		mkdir( $dir );
		$this->tmp_dirs[] = $dir;
		return $dir;
	}

	private function tbl( string $short ): string {
		return Migrator::tables()[ $short ];
	}

	public function test_run_creates_the_six_tables_and_records_version(): void {
		global $wpdb;
		$result = ( new Migrator() )->run();

		$this->assertSame( '', $result['error'] );
		$this->assertSame( array( 1 ), $result['applied'] );
		$this->assertSame( 1, ( new Migrator() )->current_version() );
		foreach ( Migrator::tables() as $name ) {
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ), "Falta $name" );
		}
	}

	public function test_payment_events_idempotency_uses_idem_key_not_the_received_status(): void {
		( new Migrator() )->run();
		$t    = $this->tbl( 'payment_events' );
		$base = array(
			'received_at'     => '2026-01-01 00:00:00',
			'source'          => 'webhook',
			'transaction_id'  => '12345-1',
			'reference'       => '10-1',
			'status_received' => 'APPROVED',
		);
		// Un evento FALSO (checksum inválido) con un transaction_id real no lleva idem_key: no ocupa la clave del legítimo.
		$this->assertTrue( $this->try_insert( $t, array_merge( $base, array( 'checksum_valid' => 0 ) ) ) );
		$this->assertTrue( $this->try_insert( $t, array_merge( $base, array( 'checksum_valid' => 0 ) ) ), 'Los eventos sin idem_key se registran todos' );
		// El legítimo, verificado por la API, sí la lleva.
		$ok = array_merge( $base, array( 'checksum_valid' => 1, 'api_verified' => 'yes', 'idem_key' => '12345-1|APPROVED' ) );
		$this->assertTrue( $this->try_insert( $t, $ok ), 'El evento legítimo no debe chocar con el falso previo' );
		// La repetición del legítimo (mismo tx y mismo estado verificado) sí se rechaza: idempotencia.
		$this->assertFalse( $this->try_insert( $t, $ok ) );
		// Otro estado verificado de la misma transacción es otro evento.
		$this->assertTrue( $this->try_insert( $t, array_merge( $ok, array( 'idem_key' => '12345-1|VOIDED', 'status_received' => 'VOIDED' ) ) ) );
	}

	public function test_stock_alerts_unique_per_person_and_piece(): void {
		( new Migrator() )->run();
		$row = array(
			'product_id'   => 10,
			'variation_id' => 0,
			'subject_hash' => str_repeat( 'a', 64 ),
			'channel'      => 'email',
			'contact_enc'  => 'x',
			'consent_id'   => 1,
			'created_at'   => '2026-01-01 00:00:00',
		);
		$this->assertTrue( $this->try_insert( $this->tbl( 'stock_alerts' ), $row ) );
		$this->assertFalse( $this->try_insert( $this->tbl( 'stock_alerts' ), $row ) );
		$this->assertTrue( $this->try_insert( $this->tbl( 'stock_alerts' ), array_merge( $row, array( 'variation_id' => 11 ) ) ) );
		$this->assertTrue( $this->try_insert( $this->tbl( 'stock_alerts' ), array_merge( $row, array( 'subject_hash' => str_repeat( 'b', 64 ) ) ) ) );
	}

	public function test_early_access_one_row_per_person(): void {
		( new Migrator() )->run();
		$row = array(
			'subject_hash' => str_repeat( 'c', 64 ),
			'email_enc'    => 'x',
			'consent_id'   => 1,
			'created_at'   => '2026-01-01 00:00:00',
		);
		$this->assertTrue( $this->try_insert( $this->tbl( 'early_access' ), $row ) );
		$this->assertFalse( $this->try_insert( $this->tbl( 'early_access' ), $row ) );
	}

	public function test_rate_limits_composite_primary_key_and_atomic_increment(): void {
		global $wpdb;
		( new Migrator() )->run();
		$t = $this->tbl( 'rate_limits' );
		$k = str_repeat( 'd', 64 );
		$this->assertTrue( $this->try_insert( $t, array( 'key_hash' => $k, 'bucket' => 'pay', 'count' => 1, 'window_start' => '2026-01-01 00:00:00' ) ) );
		$this->assertFalse( $this->try_insert( $t, array( 'key_hash' => $k, 'bucket' => 'pay', 'count' => 1, 'window_start' => '2026-01-01 00:00:00' ) ) );
		$this->assertTrue( $this->try_insert( $t, array( 'key_hash' => $k, 'bucket' => 'login', 'count' => 1, 'window_start' => '2026-01-01 00:00:00' ) ) );
		// El patrón de R-12 funciona sobre esa clave.
		for ( $i = 0; $i < 3; $i++ ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO `$t` (key_hash,bucket,count,window_start) VALUES (%s,%s,1,%s) ON DUPLICATE KEY UPDATE count = count + 1", $k, 'pay', '2026-01-01 00:00:00' ) ); // phpcs:ignore WordPress.DB
		}
		$this->assertSame( '4', $wpdb->get_var( $wpdb->prepare( "SELECT count FROM `$t` WHERE key_hash=%s AND bucket=%s", $k, 'pay' ) ) ); // phpcs:ignore WordPress.DB
	}

	public function test_enums_reject_invalid_values_in_strict_mode(): void {
		global $wpdb;
		( new Migrator() )->run();
		$previous = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
		try {
			// WordPress quita el modo estricto de la sesión; aquí se pide explícitamente para probar el tipo enum.
			$wpdb->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" ); // phpcs:ignore WordPress.DB
			$this->assertFalse( $this->try_insert( $this->tbl( 'payment_events' ), array( 'received_at' => '2026-01-01 00:00:00', 'source' => 'webhook', 'status_received' => 'HACKED' ) ) );
			$this->assertFalse( $this->try_insert( $this->tbl( 'consents' ), array( 'subject_hash' => str_repeat( 'a', 64 ), 'purpose' => 'hacked', 'text_version' => '1', 'created_at' => '2026-01-01 00:00:00' ) ) );
			$this->assertFalse( $this->try_insert( $this->tbl( 'stock_alerts' ), array( 'product_id' => 1, 'subject_hash' => str_repeat( 'a', 64 ), 'channel' => 'fax', 'consent_id' => 1, 'created_at' => '2026-01-01 00:00:00' ) ) );
		} finally {
			// Se restaura el modo que tenía la sesión (el que dejó WordPress), no el del servidor.
			$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $previous ) ); // phpcs:ignore WordPress.DB
		}
	}

	public function test_enum_with_wordpress_sql_mode_stores_empty_string_so_php_must_validate(): void {
		global $wpdb;
		( new Migrator() )->run();
		// Documenta el riesgo (M1 del gate de base de datos): con el modo de WordPress un valor inválido NO se rechaza,
		// se guarda como ''. La validación con lista blanca debe estar en la capa PHP (guard, T060).
		if ( false !== strpos( (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' ), 'STRICT' ) ) {
			$this->markTestSkipped( 'La sesión ya es estricta.' );
		}
		$this->assertTrue( $this->try_insert( $this->tbl( 'payment_events' ), array( 'received_at' => '2026-01-01 00:00:00', 'source' => 'webhook', 'status_received' => 'HACKED' ) ) );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->tbl( 'payment_events' ) . '` WHERE status_received = \'\'' ) ); // phpcs:ignore WordPress.DB
	}

	public function test_rerun_is_idempotent_and_keeps_data(): void {
		( new Migrator() )->run();
		$this->assertTrue( $this->try_insert( $this->tbl( 'audit_log' ), array( 'at' => '2026-01-01 00:00:00', 'action' => 'login' ) ) );

		delete_option( Migrator::OPTION_VERSION ); // Fuerza reaplicar.
		$result = ( new Migrator() )->run();
		$this->assertSame( '', $result['error'] );

		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->tbl( 'audit_log' ) . '`' ) ); // phpcs:ignore WordPress.DB
		$again = ( new Migrator() )->run();
		$this->assertSame( array(), $again['applied'], 'Sin cambios no aplica nada' );
	}

	public function test_is_pending_reflects_version(): void {
		$m = new Migrator();
		$this->assertTrue( $m->is_pending() );
		$m->run();
		$this->assertFalse( $m->is_pending() );
	}

	public function test_invalid_migration_fails_closed_and_keeps_version(): void {
		$dir = $this->tmp_dir();
		file_put_contents( $dir . '/0001_mala.php', "<?php\nreturn array( 'version' => 7, 'up' => static function () {} );" );
		$result = ( new Migrator( $dir ) )->run();
		$this->assertNotSame( '', $result['error'] );
		$this->assertSame( 0, ( new Migrator( $dir ) )->current_version() );
		$this->assertNotSame( false, get_option( Migrator::OPTION_ERROR ) );
	}

	public function test_migration_that_does_not_create_declared_tables_is_not_recorded(): void {
		$dir = $this->tmp_dir();
		file_put_contents( $dir . '/0001_vacia.php', "<?php\nreturn array( 'version' => 1, 'tables' => array( 'rate_limits' ), 'up' => static function () {} );" );
		$result = ( new Migrator( $dir ) )->run();
		$this->assertStringContainsString( 'rate_limits', $result['error'] );
		$this->assertSame( 0, ( new Migrator( $dir ) )->current_version() );
	}

	public function test_lock_held_by_another_connection_blocks_without_changing_state(): void {
		global $wpdb;
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses
		$name  = $wpdb->prefix . Migrator::LOCK_NAME;
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 1)" );

		$result = ( new Migrator( null, 1 ) )->run();

		$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $name ) . "')" );
		$other->close();
		$this->assertStringContainsString( 'candado', $result['error'] );
		$this->assertSame( 0, ( new Migrator() )->current_version() );
		$this->assertNotFalse( get_option( Migrator::OPTION_ERROR ), 'El fallo por candado queda registrado' );
	}

	public function test_lock_not_obtained_but_already_migrated_is_not_an_error(): void {
		global $wpdb;
		( new Migrator() )->run(); // Otro proceso ya migró.
		delete_option( Migrator::OPTION_ERROR );
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses
		$name  = $wpdb->prefix . Migrator::LOCK_NAME;
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 1)" );

		$result = ( new Migrator( null, 0 ) )->run();

		$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $name ) . "')" );
		$other->close();
		$this->assertSame( '', $result['error'], 'Si la migración ya está hecha, no obtener el candado no es un fallo' );
		$this->assertSame( 1, $result['version'] );
		$this->assertFalse( get_option( Migrator::OPTION_ERROR ), 'No debe quedar un error obsoleto' );
	}

	public function test_a_stale_options_cache_does_not_make_run_apply_the_migration_again(): void {
		global $wpdb;
		( new Migrator() )->run();
		// Simula una caché de objetos persistente vieja: la caché dice 0 pero la base de datos ya tiene la versión 1.
		wp_cache_set( 'alloptions', array_merge( wp_load_alloptions(), array( Migrator::OPTION_VERSION => '0' ) ), 'options' );
		wp_cache_set( Migrator::OPTION_VERSION, '0', 'options' );
		$this->assertSame( 0, ( new Migrator() )->current_version(), 'La caché vieja es lo que ve get_option' );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Migrator::OPTION_VERSION ) ) ); // phpcs:ignore WordPress.DB

		$result = ( new Migrator() )->run();

		$this->assertSame( array(), $result['applied'], 'run() debe leer la versión de la base de datos, no de la caché' );
	}

	public function test_a_homonymous_unique_index_with_other_columns_is_not_accepted(): void {
		$dir = $this->tmp_dir();
		file_put_contents(
			$dir . '/0001_otras_columnas.php',
			"<?php\nreturn array( 'version' => 1, 'tables' => array( 'early_access' ), 'unique' => array( 'early_access' => array( 'uq_subject' => array( 'subject_hash' ) ) ),\n 'up' => static function ( \\wpdb \$wpdb ): void { \$wpdb->query( 'CREATE TABLE ' . \$wpdb->prefix . 'aralia_early_access ( id int NOT NULL, other int NOT NULL, UNIQUE KEY uq_subject (other) )' ); } );"
		);
		$result = ( new Migrator( $dir ) )->run();
		$this->assertStringContainsString( 'early_access.uq_subject', $result['error'] );
	}

	public function test_is_pending_does_not_load_migration_files(): void {
		$dir = $this->tmp_dir();
		file_put_contents( $dir . '/0001_invalida.php', '<?php throw new \\Exception( "no debe ejecutarse" );' );
		$this->assertTrue( ( new Migrator( $dir ) )->is_pending(), 'is_pending solo mira los nombres de archivo' );
		$this->assertSame( 1, ( new Migrator( $dir ) )->latest_version() );
	}

	public function test_unique_indexes_exist_and_other_indexes_are_not_unique(): void {
		global $wpdb;
		( new Migrator() )->run();
		$unique = array(
			'payment_events' => 'uq_idem',
			'stock_alerts'   => 'uq_alert',
			'early_access'   => 'uq_subject',
		);
		foreach ( $unique as $short => $index ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM `' . $this->tbl( $short ) . '` WHERE Key_name = %s', $index ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$this->assertIsArray( $row, "Falta $short.$index" );
			$this->assertSame( 0, (int) $row['Non_unique'], "$short.$index debe ser único" );
		}
		$plain = array(
			'payment_events' => array( 'tx_status', 'reference', 'order_id' ),
			'stock_alerts'   => array( 'subject_hash', 'consent_id' ),
			'early_access'   => array( 'consent_id', 'confirm_purge' ),
		);
		foreach ( $plain as $short => $indexes ) {
			foreach ( $indexes as $index ) {
				$row = $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM `' . $this->tbl( $short ) . '` WHERE Key_name = %s', $index ), ARRAY_A ); // phpcs:ignore WordPress.DB
				$this->assertIsArray( $row, "Falta $short.$index" );
				$this->assertSame( 1, (int) $row['Non_unique'], "$short.$index no debe ser único" );
			}
		}
	}

	public function test_migration_missing_a_declared_unique_index_is_not_recorded(): void {
		$dir = $this->tmp_dir();
		file_put_contents(
			$dir . '/0001_sin_indice.php',
			"<?php\nreturn array( 'version' => 1, 'tables' => array( 'stock_alerts' ), 'unique' => array( 'stock_alerts' => array( 'uq_alert' => array( 'product_id', 'variation_id', 'subject_hash' ) ) ),\n 'up' => static function ( \\wpdb \$wpdb ): void { \$wpdb->query( 'CREATE TABLE ' . \$wpdb->prefix . 'aralia_stock_alerts ( id int NOT NULL )' ); } );"
		);
		$result = ( new Migrator( $dir ) )->run();
		$this->assertStringContainsString( 'stock_alerts.uq_alert', $result['error'], 'Falla por el índice declarado que falta, no por otra cosa' );
		$this->assertSame( 0, ( new Migrator( $dir ) )->current_version() );
	}

	public function test_a_non_unique_index_with_the_same_columns_is_not_accepted(): void {
		$dir = $this->tmp_dir();
		file_put_contents(
			$dir . '/0001_no_unico.php',
			"<?php\nreturn array( 'version' => 1, 'tables' => array( 'early_access' ), 'unique' => array( 'early_access' => array( 'uq_subject' => array( 'subject_hash' ) ) ),\n 'up' => static function ( \\wpdb \$wpdb ): void { \$wpdb->query( 'CREATE TABLE ' . \$wpdb->prefix . 'aralia_early_access ( id int NOT NULL, subject_hash char(64) NOT NULL, KEY uq_subject (subject_hash) )' ); } );"
		);
		$result = ( new Migrator( $dir ) )->run();
		$this->assertStringContainsString( 'early_access.uq_subject', $result['error'], 'Un índice con el nombre y las columnas correctas pero NO único no sirve' );
	}

	public function test_run_reports_the_database_version_even_with_a_stale_cache(): void {
		( new Migrator() )->run();
		wp_cache_set( 'alloptions', array_merge( wp_load_alloptions(), array( Migrator::OPTION_VERSION => '0' ) ), 'options' );
		wp_cache_set( Migrator::OPTION_VERSION, '0', 'options' );

		$result = ( new Migrator() )->run();

		$this->assertSame( 1, $result['version'] );
		$this->assertSame( 1, ( new Migrator() )->current_version(), 'La caché vieja se refrescó' );
	}

	public function test_contact_is_nullable_so_it_can_be_anonymized_keeping_the_proof_of_consent(): void {
		( new Migrator() )->run();
		$this->assertTrue(
			$this->try_insert(
				$this->tbl( 'consents' ),
				array(
					'subject_hash' => str_repeat( 'e', 64 ),
					'contact_enc'  => null,
					'purpose'      => 'order',
					'text_version' => 'v1',
					'created_at'   => '2026-01-01 00:00:00',
				)
			)
		);
	}

	public function test_second_dbdelta_run_does_not_change_the_schema(): void {
		global $wpdb;
		( new Migrator() )->run();
		$before = array();
		foreach ( Migrator::tables() as $name ) {
			$before[ $name ] = (string) $wpdb->get_row( "SHOW CREATE TABLE `$name`", ARRAY_N )[1]; // phpcs:ignore WordPress.DB
		}
		delete_option( Migrator::OPTION_VERSION );
		( new Migrator() )->run();
		foreach ( Migrator::tables() as $name ) {
			$this->assertSame( $before[ $name ], (string) $wpdb->get_row( "SHOW CREATE TABLE `$name`", ARRAY_N )[1], "$name cambió al reaplicar" ); // phpcs:ignore WordPress.DB
		}
	}
}
