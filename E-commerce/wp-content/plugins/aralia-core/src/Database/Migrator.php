<?php
/**
 * Migraciones versionadas de las tablas aralia_* (data-model §4).
 *
 * @package Aralia
 */

namespace Aralia\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Aplica en orden los archivos de migrations/ que superen la versión registrada.
 * Solo hacia adelante (no hay rollback: las migraciones son aditivas e idempotentes con dbDelta).
 */
class Migrator {

	public const OPTION_VERSION = 'aralia_db_version';
	public const OPTION_ERROR   = 'aralia_db_error';
	public const LOCK_NAME      = 'aralia_migrate';

	/**
	 * Carpeta de migraciones.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Espera máxima del candado, en segundos.
	 *
	 * @var int
	 */
	private int $lock_timeout;

	/**
	 * Constructor.
	 *
	 * @param string|null $dir          Carpeta de migraciones (pruebas).
	 * @param int         $lock_timeout Segundos de espera del candado.
	 */
	public function __construct( ?string $dir = null, int $lock_timeout = 10 ) {
		$this->dir          = rtrim( $dir ?? ARALIA_CORE_DIR . 'migrations', '/' );
		$this->lock_timeout = max( 0, $lock_timeout );
	}

	/**
	 * Versión aplicada.
	 *
	 * @return int
	 */
	public function current_version(): int {
		return (int) get_option( self::OPTION_VERSION, 0 );
	}

	/**
	 * Última versión disponible en disco.
	 *
	 * @return int
	 */
	public function latest_version(): int {
		// Solo mira los nombres de archivo (0001_x.php): barato y sin ejecutar nada en cada petición.
		$max   = 0;
		$files = glob( $this->dir . '/[0-9][0-9][0-9][0-9]_*.php' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$max = max( $max, (int) substr( basename( $file ), 0, 4 ) );
		}
		return $max;
	}

	/**
	 * Hay migraciones sin aplicar.
	 *
	 * @return bool
	 */
	public function is_pending(): bool {
		return $this->current_version() < $this->latest_version();
	}

	/**
	 * Nombres completos de las tablas propias.
	 *
	 * @return array<string,string> clave corta => nombre con prefijo.
	 */
	public static function tables(): array {
		global $wpdb;
		$out = array();
		foreach ( array( 'payment_events', 'consents', 'stock_alerts', 'early_access', 'audit_log', 'rate_limits' ) as $short ) {
			$out[ $short ] = $wpdb->prefix . 'aralia_' . $short;
		}
		return $out;
	}

	/**
	 * Aplica las migraciones pendientes bajo un candado de MySQL (dos procesos no migran a la vez).
	 *
	 * @return array{applied:int[],version:int,error:string} Resultado.
	 */
	public function run(): array {
		global $wpdb;
		$result = array(
			'applied' => array(),
			'version' => $this->current_version(),
			'error'   => '',
		);

		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $wpdb->prefix . self::LOCK_NAME, $this->lock_timeout ) );
		if ( 1 !== $locked ) {
			// Si otro proceso ya terminó la migración, no es un error (y no se deja uno obsoleto).
			wp_cache_delete( 'alloptions', 'options' );
			$stored = $this->stored_version();
			if ( $stored < $this->latest_version() ) {
				$result['error'] = 'No se obtuvo el candado de migraciones.';
				$this->record_error( $result['error'] );
				// Carrera estrecha: el otro proceso pudo terminar entre la lectura y el registro; no se deja un error obsoleto.
				if ( $this->stored_version() >= $this->latest_version() ) {
					delete_option( self::OPTION_ERROR );
					$result['error'] = '';
				}
			}
			$result['version'] = $stored;
			return $result;
		}

		try {
			// Se relee bajo el candado y DIRECTO de la base de datos (no de la caché de opciones, que con una caché de objetos
			// persistente puede estar vieja): otro proceso pudo haber migrado mientras esperábamos.
			$current = $this->stored_version();
			foreach ( $this->load() as $version => $migration ) {
				if ( $version <= $current ) {
					continue;
				}
				( $migration['up'] )( $wpdb );
				$missing = $this->missing_tables( $migration['tables'] ?? array() );
				if ( $missing ) {
					throw new \RuntimeException( 'La migración ' . (int) $version . ' no creó: ' . implode( ', ', $missing ) );
				}
				$no_unique = $this->missing_unique_indexes( $migration['unique'] ?? array() );
				if ( $no_unique ) {
					// dbDelta ignora los errores de SQL: sin el índice único no hay idempotencia, y la versión no se registra.
					throw new \RuntimeException( 'La migración ' . (int) $version . ' no dejó índices únicos: ' . implode( ', ', $no_unique ) );
				}
				update_option( self::OPTION_VERSION, $version, true );
				$current             = $version;
				$result['applied'][] = $version;
			}
			delete_option( self::OPTION_ERROR );
		} catch ( \Throwable $e ) {
			// Mensaje sin datos personales; la tabla ausente hace que lo que dependa de ella falle cerrado.
			$result['error'] = $e->getMessage();
			$this->record_error( $result['error'] );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $wpdb->prefix . self::LOCK_NAME ) );
		}

		// La versión que se informa sale de la base de datos; si la caché de opciones está vieja, se refresca.
		$result['version'] = $this->stored_version();
		if ( $this->current_version() !== $result['version'] ) {
			wp_cache_delete( self::OPTION_VERSION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		return $result;
	}

	/**
	 * Versión guardada, leída directo de wp_options (sin caché). Es la que decide; current_version() puede venir de una caché vieja.
	 *
	 * @return int
	 */
	public function stored_version(): int {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION_VERSION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null === $value ? 0 : (int) $value;
	}

	/**
	 * Índices únicos declarados que no existen o no son únicos.
	 *
	 * @param array<string,array<string,string[]>> $declared Tabla corta => nombre de índice => columnas.
	 * @return string[]
	 */
	private function missing_unique_indexes( array $declared ): array {
		global $wpdb;
		$all     = self::tables();
		$missing = array();
		foreach ( $declared as $short => $indexes ) {
			$table = $all[ $short ] ?? '';
			foreach ( $indexes as $index => $columns ) {
				$rows = '' === $table ? array() : (array) $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index ), ARRAY_A ); // phpcs:ignore WordPress.DB
				usort( $rows, static fn( $a, $b ): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
				$found = array_map( static fn( $row ): string => (string) $row['Column_name'], $rows );
				// Existe, es único y cubre exactamente las columnas declaradas (un índice homónimo con otras columnas no sirve).
				if ( ! $rows || 0 !== (int) $rows[0]['Non_unique'] || $found !== $columns ) {
					$missing[] = $short . '.' . $index;
				}
			}
		}
		return $missing;
	}

	/**
	 * Tablas de la lista que no existen.
	 *
	 * @param string[] $shorts Nombres cortos.
	 * @return string[]
	 */
	private function missing_tables( array $shorts ): array {
		global $wpdb;
		$all     = self::tables();
		$missing = array();
		foreach ( $shorts as $short ) {
			$name  = $all[ $short ] ?? '';
			$found = '' === $name ? null : $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
			// Sin distinguir mayúsculas: con lower_case_table_names=1/2 (macOS, Windows) el servidor devuelve el nombre en minúsculas.
			if ( ! is_string( $found ) || 0 !== strcasecmp( $name, $found ) ) {
				$missing[] = (string) $short;
			}
		}
		return $missing;
	}

	/**
	 * Guarda y registra el error.
	 *
	 * @param string $message Mensaje.
	 * @return void
	 */
	private function record_error( string $message ): void {
		update_option( self::OPTION_ERROR, $message, false );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- registro operativo sin datos personales.
		error_log( '[aralia] migración: ' . $message );
	}

	/**
	 * Carga y valida los archivos de migración, ordenados por versión.
	 *
	 * @return array<int,array{up:callable,tables?:string[]}>
	 */
	private function load(): array {
		$files = glob( $this->dir . '/[0-9][0-9][0-9][0-9]_*.php' );
		if ( ! is_array( $files ) ) {
			return array();
		}
		sort( $files );
		$out = array();
		foreach ( $files as $file ) {
			$version = (int) substr( basename( $file ), 0, 4 );
			$data    = require $file;
			if ( ! is_array( $data ) || ! isset( $data['up'] ) || ! is_callable( $data['up'] ) || ( $data['version'] ?? null ) !== $version ) {
				throw new \UnexpectedValueException( 'Migración inválida: ' . esc_html( basename( $file ) ) );
			}
			if ( isset( $out[ $version ] ) ) {
				throw new \UnexpectedValueException( 'Versión de migración repetida: ' . (int) $version );
			}
			$out[ $version ] = $data;
		}
		return $out;
	}
}
