<?php
/**
 * Registro de eventos de pago (tabla aralia_payment_events, data-model §4, FR-054).
 *
 * @package Aralia
 */

namespace Aralia\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Inserta, actualiza y ocupa `idem_key`. El cuerpo se guarda SANEADO con lista blanca: nada de correo, datos del cliente, bin,
 * titular, vencimiento ni dirección.
 */
final class PaymentEvents {

	/**
	 * Segundos (15 min, muy por encima del candado de 120 s y de lo que tarda un procesamiento vivo, incluso con correos lentos) tras los que
	 * una `idem_key` sin terminar se considera abandonada. Se mide desde `received_at` (el INSERT, segundos antes de reclamarla).
	 */
	public const STALE_CLAIM = 900;

	/**
	 * Resultados de una fila ya TERMINADA y sin `idem_key` que un reenvío puede reutilizar. Quedan fuera: las filas en curso (`action_taken`
	 * vacío) y las que levantaron alerta (`mismatch`, `apply_failed`, `order_missing`, `blocked_payment`): son evidencia que no se pisa.
	 */
	public const REUSABLE_ACTIONS = array( 'deferred', 'stale_event', 'api_lag', 'lock_busy', 'idem_busy', 'unknown_reference' );

	public const STATUSES = array( 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR', 'PENDING' );

	private const TX_FIELDS = array( 'id', 'reference', 'amount_in_cents', 'currency', 'status', 'status_message', 'payment_method_type', 'created_at', 'finalized_at' );

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aralia_payment_events';
	}

	/**
	 * Cuerpo saneado por lista blanca (contracts/wompi §3 paso 1).
	 *
	 * @param array<string,mixed> $event Evento recibido.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $event ): array {
		$out = array();
		foreach ( array( 'event', 'timestamp' ) as $key ) {
			if ( isset( $event[ $key ] ) && is_scalar( $event[ $key ] ) ) {
				$out[ $key ] = $event[ $key ];
			}
		}
		$tx = $event['data']['transaction'] ?? null;
		if ( is_array( $tx ) ) {
			foreach ( self::TX_FIELDS as $field ) {
				if ( isset( $tx[ $field ] ) && is_scalar( $tx[ $field ] ) ) {
					$out['data']['transaction'][ $field ] = is_string( $tx[ $field ] ) ? substr( $tx[ $field ], 0, 255 ) : $tx[ $field ];
				}
			}
			$extra = $tx['payment_method']['extra'] ?? null;
			if ( is_array( $extra ) ) {
				foreach ( array( 'brand', 'last_four' ) as $field ) {
					if ( isset( $extra[ $field ] ) && is_scalar( $extra[ $field ] ) ) {
						$out['data']['transaction']['payment_method']['extra'][ $field ] = substr( (string) $extra[ $field ], 0, 32 );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Inserta una fila.
	 *
	 * @param array<string,mixed> $row Columnas.
	 * @return int Id de la fila (0 si no se pudo).
	 */
	public static function insert( array $row ): int {
		global $wpdb;
		$row = array_merge(
			array(
				'received_at'     => gmdate( 'Y-m-d H:i:s' ),
				'source'          => 'webhook',
				'status_received' => 'ERROR',
			),
			$row
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Tabla propia.
		$ok = $wpdb->insert( self::table(), $row );
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Actualiza columnas de una fila.
	 *
	 * @param int                 $id   Fila.
	 * @param array<string,mixed> $data Columnas.
	 * @return void
	 */
	public static function update( int $id, array $data ): void {
		global $wpdb;
		if ( $id > 0 && array() !== $data ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia.
			$wpdb->update( self::table(), $data, array( 'id' => $id ) );
		}
	}

	/**
	 * ¿Existe ya una fila TERMINADA con esa `idem_key`? (una fila sin `action_taken` es un procesamiento en curso o uno que murió: no cuenta).
	 *
	 * @param string $key Clave "<transaction_id>|<estado>".
	 * @return bool
	 */
	public static function has_idem( string $key ): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia con prefijo interno.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE idem_key = %s AND action_taken <> '' LIMIT 1", $key ) );
	}

	/**
	 * Ocupa `idem_key` de una fila. El índice único decide entre dos entregas simultáneas.
	 *
	 * @param int    $id  Fila.
	 * @param string $key Clave.
	 * @return string `claimed` (esta fila la ocupó), `duplicate` (otra ya la tenía) o `error` (fallo de base de datos: NO es un duplicado).
	 */
	public static function claim_idem( int $id, string $key ): string {
		global $wpdb;
		if ( $id <= 0 ) {
			return 'error';
		}
		$table  = self::table();
		$before = $wpdb->suppress_errors( true );
		// Una clave ocupada por un procesamiento que nunca terminó (proceso muerto sin shutdown: SIGKILL, OOM) se recupera pasados 15 minutos.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia con prefijo interno.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET idem_key = NULL WHERE idem_key = %s AND action_taken = '' AND received_at < %s", $key, gmdate( 'Y-m-d H:i:s', time() - self::STALE_CLAIM ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia con prefijo interno.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET idem_key = %s WHERE id = %d AND idem_key IS NULL", $key, $id ) );
		$claimed = 1 === (int) $wpdb->rows_affected && '' === (string) $wpdb->last_error;
		$wpdb->suppress_errors( $before );
		if ( $claimed ) {
			return 'claimed';
		}
		return self::has_idem( $key ) ? 'duplicate' : 'error';
	}

	/**
	 * Libera la `idem_key` de una fila (si el cambio de estado falló, el reintento de Wompi debe poder procesarse).
	 *
	 * @param int $id Fila.
	 * @return void
	 */
	public static function release_idem( int $id ): void {
		global $wpdb;
		$table = self::table();
		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia con prefijo interno.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET idem_key = NULL WHERE id = %d", $id ) );
		}
	}

	/**
	 * Borra las filas de eventos NO autenticados (checksum inválido) más viejas que `$days` días; las autenticadas se conservan 5 años.
	 *
	 * @param int $days Días.
	 * @return int Filas borradas.
	 */
	public static function purge_unauthenticated( int $days = 30 ): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia con prefijo interno.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE checksum_valid = 0 AND received_at < %s LIMIT 5000", gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Fila abierta (sin `idem_key`) de la misma transacción y estado en la última hora: se reutiliza en vez de insertar otra, así repetir
	 * un evento firmado cuyo resultado nunca ocupa `idem_key` (diferido, API atrasada, discrepancia...) no hace crecer la tabla.
	 *
	 * @param string $transaction_id Transacción.
	 * @param string $status         Estado recibido.
	 * @return int Id de la fila (0 si no hay).
	 */
	public static function reusable_row( string $transaction_id, string $status ): int {
		global $wpdb;
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( self::REUSABLE_ACTIONS ), '%s' ) );
		$args         = array_merge( array( $transaction_id, $status ), self::REUSABLE_ACTIONS, array( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Tabla propia; los marcadores salen de una constante.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE transaction_id = %s AND status_received = %s AND idem_key IS NULL AND checksum_valid = 1 AND action_taken IN ({$placeholders}) AND received_at > %s ORDER BY id DESC LIMIT 1", $args ) );
	}

	/**
	 * Reinicia una fila abierta con los datos de un reenvío. ATÓMICO y condicional: solo si sigue sin `idem_key` Y está terminada con un
	 * resultado reutilizable (otro procesamiento pudo reclamarla o seguir en curso entre el SELECT y este UPDATE; no se pisa). Limpia las banderas de verificación del intento anterior.
	 *
	 * @param int                 $id  Fila.
	 * @param array<string,mixed> $row Datos nuevos.
	 * @return bool True si se reutilizó.
	 */
	public static function reuse( int $id, array $row ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		$data   = array_merge(
			$row,
			array(
				'received_at'     => gmdate( 'Y-m-d H:i:s' ),
				'action_taken'    => '',
				'api_verified'    => 'n/a',
				'amount_match'    => null,
				'currency_match'  => null,
				'reference_match' => null,
				'order_id'        => 0,
			)
		);
		$table  = self::table();
		$sets   = array();
		$values = array();
		foreach ( $data as $column => $value ) {
			if ( null === $value ) {
				$sets[] = '`' . preg_replace( '/[^a-z_]/', '', (string) $column ) . '` = NULL';
			} else {
				$sets[]   = '`' . preg_replace( '/[^a-z_]/', '', (string) $column ) . '` = ' . ( is_int( $value ) ? '%d' : '%s' );
				$values[] = $value;
			}
		}
		$values[]     = $id;
		$placeholders = implode( ',', array_fill( 0, count( self::REUSABLE_ACTIONS ), '%s' ) );
		$values       = array_merge( $values, self::REUSABLE_ACTIONS );
		$set_sql      = implode( ', ', $sets );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Tabla propia; las columnas salen de una lista fija y los marcadores de una constante.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$set_sql} WHERE id = %d AND idem_key IS NULL AND action_taken IN ({$placeholders})", $values ) );
		return 1 === (int) $wpdb->rows_affected;
	}
}
