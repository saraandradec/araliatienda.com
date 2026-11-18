<?php
/**
 * Registro de consentimientos (T031, FR-075, FR-083).
 *
 * @package Aralia
 */

namespace Aralia\Privacy;

use Aralia\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Prueba de autorización por propósito: quién (huella con HMAC, contacto cifrado), para qué, con qué versión del texto,
 * por qué canal, cuándo y si se revocó. La revocación NO borra la fila (es la prueba); lo que se puede anonimizar es el contacto.
 */
class Consents {

	public const PURPOSES = array( 'order', 'stock_alert', 'early_access', 'newsletter', 'sms' );

	/**
	 * Cripto.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	/**
	 * Constructor.
	 *
	 * @param Crypto|null $crypto Cripto (por defecto, de las constantes; falla cerrado si faltan).
	 */
	public function __construct( ?Crypto $crypto = null ) {
		$this->crypto = $crypto ?? Crypto::from_constants();
	}

	/**
	 * Huella según el tipo de contacto.
	 *
	 * @param string $kind    email | phone.
	 * @param string $contact Contacto.
	 * @return string
	 * @throws \InvalidArgumentException Si el tipo o el contacto no son válidos.
	 */
	private function subject( string $kind, string $contact ): string {
		if ( 'email' === $kind ) {
			return $this->crypto->email_hash( $contact );
		}
		if ( 'phone' === $kind ) {
			return $this->crypto->phone_hash( $contact );
		}
		throw new \InvalidArgumentException( 'Tipo de contacto inválido.' );
	}

	/**
	 * Registra un consentimiento.
	 *
	 * @param string $kind         email | phone.
	 * @param string $contact      Contacto (se normaliza y se cifra).
	 * @param string $purpose      Uno de PURPOSES.
	 * @param string $text_version Versión del texto mostrado.
	 * @param string $channel      Canal (checkout, footer, ficha...).
	 * @param string $ip           IP de quien consintió (se guarda truncada y con HMAC).
	 * @return int Id del consentimiento.
	 * @throws \InvalidArgumentException Si algún dato no es válido.
	 * @throws \RuntimeException Si no se pudo escribir.
	 */
	public function grant( string $kind, string $contact, string $purpose, string $text_version, string $channel, string $ip ): int {
		global $wpdb;
		if ( ! in_array( $purpose, self::PURPOSES, true ) ) {
			throw new \InvalidArgumentException( 'Propósito inválido.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9._-]{1,32}$/', $text_version ) || ! preg_match( '/^[a-z0-9_-]{0,32}$/', $channel ) ) {
			throw new \InvalidArgumentException( 'Versión del texto o canal inválidos.' );
		}
		$normalized = 'email' === $kind ? Crypto::normalize_email( $contact ) : Crypto::normalize_phone( $contact );
		$ok         = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Migrator::tables()['consents'],
			array(
				'subject_hash' => $this->subject( $kind, $contact ),
				'contact_enc'  => $this->crypto->encrypt( $normalized ),
				'purpose'      => $purpose,
				'text_version' => $text_version,
				'channel'      => $channel,
				'ip_hash'      => $this->crypto->ip_hash( $ip ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'No se pudo registrar el consentimiento.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Revoca los consentimientos vigentes de un propósito. Conserva la fila (prueba).
	 *
	 * @param string $kind    email | phone.
	 * @param string $contact Contacto.
	 * @param string $purpose Propósito.
	 * @return int Filas revocadas.
	 */
	public function revoke( string $kind, string $contact, string $purpose ): int {
		global $wpdb;
		$table = Migrator::tables()['consents'];
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET revoked_at = UTC_TIMESTAMP() WHERE subject_hash = %s AND purpose = %s AND revoked_at IS NULL",
				$this->subject( $kind, $contact ),
				$purpose
			)
		);
		// phpcs:enable
	}

	/**
	 * Hay un consentimiento vigente (no revocado) para ese propósito.
	 *
	 * @param string $kind    email | phone.
	 * @param string $contact Contacto.
	 * @param string $purpose Propósito.
	 * @return bool
	 */
	public function is_active( string $kind, string $contact, string $purpose ): bool {
		global $wpdb;
		$table = Migrator::tables()['consents'];
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE subject_hash = %s AND purpose = %s AND revoked_at IS NULL",
				$this->subject( $kind, $contact ),
				$purpose
			)
		);
		// phpcs:enable
		return $count > 0;
	}

	/**
	 * Marca o desmarca un reclamo en trámite (FR-083): mientras dure, no se purga ni se anonimiza.
	 *
	 * @param string $kind    email | phone.
	 * @param string $contact Contacto.
	 * @param bool   $active  Estado.
	 * @return int Filas afectadas.
	 */
	public function set_claim_in_progress( string $kind, string $contact, bool $active ): int {
		global $wpdb;
		return (int) $wpdb->update( Migrator::tables()['consents'], array( 'claim_in_progress' => $active ? 1 : 0 ), array( 'subject_hash' => $this->subject( $kind, $contact ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Anonimiza el contacto de los consentimientos revocados de una persona, conservando la prueba (huella, propósito, fechas).
	 * No toca los que tienen un reclamo en trámite.
	 *
	 * @param string $kind    email | phone.
	 * @param string $contact Contacto.
	 * @return int Filas anonimizadas.
	 */
	public function anonymize_revoked( string $kind, string $contact ): int {
		global $wpdb;
		$table = Migrator::tables()['consents'];
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET contact_enc = NULL WHERE subject_hash = %s AND revoked_at IS NOT NULL AND claim_in_progress = 0",
				$this->subject( $kind, $contact )
			)
		);
		// phpcs:enable
	}
}
