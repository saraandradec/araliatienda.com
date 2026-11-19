<?php
/**
 * Límites con incremento atómico (T026, R-12, D-04).
 *
 * @package Aralia
 */

namespace Aralia\Security;

use Aralia\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Contadores por (clave, cubeta) en `aralia_rate_limits`. Ventana FIJA que empieza en el primer golpe: pasada la ventana,
 * el siguiente golpe la reinicia. El incremento es una sola sentencia (`INSERT ... ON DUPLICATE KEY UPDATE` con
 * `LAST_INSERT_ID(expr)`), así dos peticiones simultáneas nunca leen el mismo valor.
 * La clave es SIEMPRE una huella (64 hex, ver Crypto): nunca una IP, celular o correo en claro.
 */
class RateLimiter {

	/**
	 * Base de datos.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $db Base de datos (por defecto, $wpdb).
	 */
	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	private function table(): string {
		return Migrator::tables()['rate_limits'];
	}

	/**
	 * Valida los argumentos.
	 *
	 * @param string $key_hash Huella.
	 * @param string $bucket   Cubeta.
	 * @return void
	 * @throws \InvalidArgumentException Si no son válidos.
	 */
	private function check_args( string $key_hash, string $bucket ): void {
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $key_hash ) ) {
			throw new \InvalidArgumentException( 'La clave del límite debe ser una huella de 64 caracteres hexadecimales.' );
		}
		if ( ! preg_match( '/^[a-z0-9_:.-]{1,40}$/', $bucket ) ) {
			throw new \InvalidArgumentException( 'Cubeta de límite inválida.' );
		}
	}

	/**
	 * Registra un golpe y devuelve el contador dentro de la ventana.
	 *
	 * @param string $key_hash       Huella de la clave.
	 * @param string $bucket         Cubeta (p. ej. `decline`, `coupon`).
	 * @param int    $window_seconds Duración de la ventana.
	 * @return int Golpes en la ventana actual, contando este.
	 * @throws \InvalidArgumentException Si los argumentos no son válidos.
	 * @throws RateLimitUnavailable Si la base de datos no pudo registrar el golpe (quien llama debe FALLAR CERRADO).
	 */
	public function hit( string $key_hash, string $bucket, int $window_seconds ): int {
		$this->check_args( $key_hash, $bucket );
		if ( $window_seconds < 1 || $window_seconds > 7 * DAY_IN_SECONDS ) {
			throw new \InvalidArgumentException( 'Ventana fuera de rango.' );
		}
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- tabla interna; valores preparados.
		$res = $this->db->query(
			$this->db->prepare(
				"INSERT INTO `{$table}` (key_hash, bucket, `count`, window_start) VALUES (%s, %s, LAST_INSERT_ID(1), UTC_TIMESTAMP())
				ON DUPLICATE KEY UPDATE
					`count` = LAST_INSERT_ID(IF(window_start <= UTC_TIMESTAMP() - INTERVAL %d SECOND, 1, `count` + 1)),
					window_start = IF(window_start <= UTC_TIMESTAMP() - INTERVAL %d SECOND, UTC_TIMESTAMP(), window_start)",
				$key_hash,
				$bucket,
				$window_seconds,
				$window_seconds
			)
		);
		if ( false === $res ) {
			// Deadlock, tabla ausente, conexión perdida: LAST_INSERT_ID() devolvería un valor viejo de esta conexión (un contador falso).
			throw new RateLimitUnavailable( 'No se pudo registrar el golpe del límite.' );
		}
		$value = (int) $this->db->get_var( 'SELECT LAST_INSERT_ID()' );
		// phpcs:enable
		if ( $value < 1 ) {
			throw new RateLimitUnavailable( 'El contador del límite no es válido.' );
		}
		return $value;
	}

	/**
	 * Golpes en la ventana actual sin registrar uno nuevo.
	 *
	 * @param string $key_hash       Huella.
	 * @param string $bucket         Cubeta.
	 * @param int    $window_seconds Ventana.
	 * @return int
	 */
	public function count( string $key_hash, string $bucket, int $window_seconds ): int {
		$this->check_args( $key_hash, $bucket );
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$value = $this->db->get_var(
			$this->db->prepare(
				"SELECT `count` FROM `{$table}` WHERE key_hash = %s AND bucket = %s AND window_start > UTC_TIMESTAMP() - INTERVAL %d SECOND",
				$key_hash,
				$bucket,
				$window_seconds
			)
		);
		// phpcs:enable
		if ( '' !== $this->db->last_error ) {
			throw new RateLimitUnavailable( 'No se pudo leer el contador del límite.' );
		}
		return (int) $value;
	}

	/**
	 * Borra el contador (p. ej. tras un desbloqueo manual).
	 *
	 * @param string $key_hash Huella.
	 * @param string $bucket   Cubeta.
	 * @return void
	 */
	public function reset( string $key_hash, string $bucket ): void {
		$this->check_args( $key_hash, $bucket );
		$this->db->delete(
			$this->table(),
			array(
				'key_hash' => $key_hash,
				'bucket'   => $bucket,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Purga ventanas vencidas en lotes (cada hora, por Action Scheduler). Por defecto 7 días: es la ventana máxima que acepta hit(),
	 * así nunca se borra una ventana vigente.
	 *
	 * @param int $older_than_seconds Antigüedad mínima de la ventana.
	 * @param int $batch              Filas por lote.
	 * @param int $max_batches        Lotes máximos por llamada.
	 * @return int Filas borradas.
	 */
	public function purge_expired( int $older_than_seconds = 7 * DAY_IN_SECONDS, int $batch = 1000, int $max_batches = 20 ): int {
		$table = $this->table();
		$batch = max( 1, $batch );
		$total = 0;
		for ( $i = 0; $i < $max_batches; $i++ ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$res = (int) $this->db->query( $this->db->prepare( "DELETE FROM `{$table}` WHERE window_start < UTC_TIMESTAMP() - INTERVAL %d SECOND LIMIT %d", max( 1, $older_than_seconds ), max( 1, $batch ) ) );
			// phpcs:enable
			$total += $res;
			if ( $res < $batch ) {
				break;
			}
		}
		return $total;
	}
}
