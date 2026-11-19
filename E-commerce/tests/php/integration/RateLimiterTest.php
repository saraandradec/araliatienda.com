<?php
/**
 * T026: limitador con incremento atómico.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Database\Migrator;
use Aralia\Security\RateLimiter;
use Aralia\Tests\Support\SecurityTestCase;

final class RateLimiterTest extends SecurityTestCase {

	private function key( string $v = 'x' ): string {
		return $this->crypto()->key_hash( 'test', $v );
	}

	public function test_hits_count_up_within_the_window(): void {
		$rl = new RateLimiter();
		$k  = $this->key();
		$this->assertSame( 1, $rl->hit( $k, 'pay', 3600 ) );
		$this->assertSame( 2, $rl->hit( $k, 'pay', 3600 ) );
		$this->assertSame( 3, $rl->hit( $k, 'pay', 3600 ) );
		$this->assertSame( 3, $rl->count( $k, 'pay', 3600 ) );
	}

	public function test_buckets_and_keys_are_independent(): void {
		$rl = new RateLimiter();
		$rl->hit( $this->key( 'a' ), 'pay', 3600 );
		$rl->hit( $this->key( 'a' ), 'pay', 3600 );
		$this->assertSame( 1, $rl->hit( $this->key( 'a' ), 'login', 3600 ) );
		$this->assertSame( 1, $rl->hit( $this->key( 'b' ), 'pay', 3600 ) );
		$this->assertSame( 3, $rl->hit( $this->key( 'a' ), 'pay', 3600 ) );
	}

	public function test_an_expired_window_restarts_the_counter(): void {
		global $wpdb;
		$rl = new RateLimiter();
		$k  = $this->key();
		$rl->hit( $k, 'pay', 60 );
		$rl->hit( $k, 'pay', 60 );
		$wpdb->query( $wpdb->prepare( 'UPDATE `' . Migrator::tables()['rate_limits'] . '` SET window_start = UTC_TIMESTAMP() - INTERVAL 61 SECOND WHERE key_hash = %s', $k ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, $rl->count( $k, 'pay', 60 ), 'Vencida: no cuenta' );
		$this->assertSame( 1, $rl->hit( $k, 'pay', 60 ), 'El siguiente golpe reinicia la ventana' );
		$this->assertSame( 2, $rl->hit( $k, 'pay', 60 ) );
	}

	/**
	 * Alterna dos conexiones reales: demuestra que LAST_INSERT_ID es por conexión y que el contador es único y consecutivo entre ellas.
	 * La atomicidad ante peticiones simultáneas la da la sentencia única (ON DUPLICATE KEY UPDATE con bloqueo de fila), no esta prueba.
	 */
	public function test_two_connections_get_distinct_consecutive_values(): void {
		$rl    = new RateLimiter();
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $GLOBALS['wpdb']->prefix );
		$rl2 = new RateLimiter( $other );
		$k   = $this->key();
		$seen = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$seen[] = $rl->hit( $k, 'pay', 3600 );
			$seen[] = $rl2->hit( $k, 'pay', 3600 );
		}
		$this->assertSame( range( 1, 20 ), $seen, 'Cada golpe recibe un valor distinto y consecutivo, sin importar la conexión' );
		$this->assertSame( 20, $rl->count( $k, 'pay', 3600 ) );
	}

	public function test_reset_and_purge(): void {
		global $wpdb;
		$rl = new RateLimiter();
		$k  = $this->key();
		$rl->hit( $k, 'pay', 3600 );
		$rl->reset( $k, 'pay' );
		$this->assertSame( 0, $rl->count( $k, 'pay', 3600 ) );

		$rl->hit( $k, 'old', 3600 );
		$wpdb->query( 'UPDATE `' . Migrator::tables()['rate_limits'] . '` SET window_start = UTC_TIMESTAMP() - INTERVAL 3 DAY' ); // phpcs:ignore WordPress.DB
		$this->assertSame( 1, $rl->purge_expired( DAY_IN_SECONDS ) );
	}

	/**
	 * @dataProvider provide_bad_args
	 */
	public function test_rejects_raw_or_malformed_arguments( string $key, string $bucket, int $window ): void {
		$this->expectException( \InvalidArgumentException::class );
		( new RateLimiter() )->hit( $key, $bucket, $window );
	}

	/**
	 * @return array<string,array{string,string,int}>
	 */
	public static function provide_bad_args(): array {
		$k = str_repeat( 'a', 64 );
		return array(
			'ip en claro'     => array( '203.0.113.5', 'pay', 60 ),
			'correo en claro' => array( 'ana@example.com', 'pay', 60 ),
			'huella corta'    => array( 'abc', 'pay', 60 ),
			'cubeta rara'     => array( $k, "pay'; DROP TABLE x;--", 60 ),
			'cubeta vacía'    => array( $k, '', 60 ),
			'ventana cero'    => array( $k, 'pay', 0 ),
			'ventana enorme'  => array( $k, 'pay', 99999999 ),
		);
	}

	public function test_a_failing_query_throws_instead_of_returning_a_stale_counter(): void {
		global $wpdb;
		$rl = new RateLimiter();
		$k  = $this->key();
		$rl->hit( $k, 'pay', 3600 );
		$rl->hit( $k, 'pay', 3600 ); // LAST_INSERT_ID de esta conexión queda en 2.
		$wpdb->query( 'DROP TABLE `' . Migrator::tables()['rate_limits'] . '`' ); // phpcs:ignore WordPress.DB
		$prev = $wpdb->suppress_errors( true );
		try {
			$rl->hit( $this->key( 'otra' ), 'pay', 3600 );
			$this->fail( 'Debía lanzar RateLimitUnavailable en vez de devolver el 2 viejo' );
		} catch ( \Aralia\Security\RateLimitUnavailable $e ) {
			$this->assertStringContainsString( 'límite', $e->getMessage() );
		} finally {
			$wpdb->suppress_errors( $prev );
		}
	}

	public function test_reading_a_missing_table_also_fails_closed(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE `' . Migrator::tables()['rate_limits'] . '`' ); // phpcs:ignore WordPress.DB
		$prev = $wpdb->suppress_errors( true );
		try {
			( new RateLimiter() )->count( $this->key(), 'pay', 60 );
			$this->fail( 'Debía lanzar' );
		} catch ( \Aralia\Security\RateLimitUnavailable $e ) {
			$this->assertTrue( true );
		} finally {
			$wpdb->suppress_errors( $prev );
		}
	}

	public function test_the_primary_key_the_atomic_design_depends_on_is_verified_by_the_migrator(): void {
		global $wpdb;
		$rows = $wpdb->get_results( 'SHOW INDEX FROM `' . Migrator::tables()['rate_limits'] . "` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( 'key_hash', 'bucket' ), array_column( $rows, 'Column_name' ) );
		// Si alguien la quita, volver a aplicar la migración la repara (dbDelta la recrea) y el limitador vuelve a ser atómico.
		$wpdb->query( 'ALTER TABLE `' . Migrator::tables()['rate_limits'] . '` DROP PRIMARY KEY' ); // phpcs:ignore WordPress.DB
		delete_option( Migrator::OPTION_VERSION );
		$result = ( new Migrator() )->run();
		$this->assertSame( '', $result['error'] );
		$rows = $wpdb->get_results( 'SHOW INDEX FROM `' . Migrator::tables()['rate_limits'] . "` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( 'key_hash', 'bucket' ), array_column( $rows, 'Column_name' ) );
	}

	public function test_a_migration_that_leaves_rate_limits_without_its_primary_key_is_not_recorded(): void {
		$dir = sys_get_temp_dir() . '/aralia-mig-' . uniqid();
		mkdir( $dir );
		file_put_contents(
			$dir . '/0001_sin_pk.php',
			"<?php\nreturn array( 'version' => 1, 'tables' => array( 'rate_limits' ), 'unique' => array( 'rate_limits' => array( 'PRIMARY' => array( 'key_hash', 'bucket' ) ) ),\n 'up' => static function ( \\wpdb \$wpdb ): void { \$wpdb->query( 'CREATE TABLE ' . \$wpdb->prefix . 'aralia_rate_limits ( key_hash char(64) NOT NULL, bucket varchar(40) NOT NULL, `count` int NOT NULL )' ); } );"
		);
		$this->wipe_aralia_tables();
		$result = ( new Migrator( $dir ) )->run();
		unlink( $dir . '/0001_sin_pk.php' );
		rmdir( $dir );
		$this->assertStringContainsString( 'rate_limits.PRIMARY', $result['error'], 'Sin PK el ON DUPLICATE KEY degenera en INSERT simple: no se da la migración por buena' );
		$this->assertSame( 0, ( new Migrator() )->current_version() );
	}

	public function test_default_purge_keeps_windows_up_to_the_maximum_length(): void {
		global $wpdb;
		$rl = new RateLimiter();
		$k  = $this->key();
		$rl->hit( $k, 'long', 7 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'UPDATE `' . Migrator::tables()['rate_limits'] . '` SET window_start = UTC_TIMESTAMP() - INTERVAL 5 DAY WHERE key_hash = %s', $k ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, $rl->purge_expired(), 'Una ventana de 7 días con 5 días no se borra' );
	}

	public function test_the_assignment_order_the_reset_logic_depends_on_is_documented_by_a_regression(): void {
		global $wpdb;
		$rl = new RateLimiter();
		$k  = $this->key( 'orden' );
		$rl->hit( $k, 'pay', 60 );
		$rl->hit( $k, 'pay', 60 );
		$wpdb->query( $wpdb->prepare( 'UPDATE `' . Migrator::tables()['rate_limits'] . '` SET window_start = UTC_TIMESTAMP() - INTERVAL 2 MINUTE WHERE key_hash = %s', $k ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 1, $rl->hit( $k, 'pay', 60 ), 'Tras vencer, el contador reinicia en 1' );
		$this->assertSame( 2, $rl->hit( $k, 'pay', 60 ), 'y la ventana también se reinició: el siguiente suma 2, no vuelve a 1' );
		$start = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT window_start FROM `' . Migrator::tables()['rate_limits'] . '` WHERE key_hash = %s', $k ) ); // phpcs:ignore WordPress.DB
		$this->assertGreaterThan( time() - 30, strtotime( $start . ' UTC' ), 'window_start es de hace segundos' );
	}

	public function test_a_non_positive_batch_size_is_normalized_and_the_purge_terminates(): void {
		global $wpdb;
		$t = Migrator::tables()['rate_limits'];
		for ( $i = 0; $i < 3; $i++ ) {
			$wpdb->insert( $t, array( 'key_hash' => hash( 'sha256', "lote$i" ), 'bucket' => 'b', 'count' => 1, 'window_start' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		}
		$this->assertSame( 3, ( new RateLimiter() )->purge_expired( 7 * DAY_IN_SECONDS, 0, 10 ), 'Un lote de 0 se trata como 1 y termina cuando no queda nada' );
	}
}
