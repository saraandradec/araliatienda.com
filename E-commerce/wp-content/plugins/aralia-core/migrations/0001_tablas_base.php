<?php
/**
 * Migración 0001: las 6 tablas propias (data-model §4).
 *
 * dbDelta es idempotente: ejecutar de nuevo no daña datos. Los índices únicos sostienen la idempotencia de pagos (FR-054),
 * los avisos de stock y los límites atómicos (R-12).
 *
 * @package Aralia
 */

defined( 'ABSPATH' ) || exit;

return array(
	'version' => 1,
	'tables'  => array( 'payment_events', 'consents', 'stock_alerts', 'early_access', 'audit_log', 'rate_limits' ),
	// Índices que sostienen reglas de negocio (idempotencia, unicidad): el migrador comprueba que existan y sean únicos.
	'unique'  => array(
		'payment_events' => array( 'uq_idem' => array( 'idem_key' ) ),
		'stock_alerts'   => array( 'uq_alert' => array( 'product_id', 'variation_id', 'subject_hash' ) ),
		'early_access'   => array( 'uq_subject' => array( 'subject_hash' ) ),
	),
	'up'      => static function ( \wpdb $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'aralia_';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL con prefijo y charset internos.
		$statements = array(
			// Registro de TODOS los eventos de pago (también falsos, diferidos, de conciliación). La idempotencia NO es (transaction_id,
			// status_received) sino idem_key: solo se llena cuando el checksum es válido y la API verificó el estado ("tx|estado verificado").
			// Así un evento falso con un transaction_id real no ocupa la clave del evento legítimo. received_at siempre en UTC.
			"CREATE TABLE {$p}payment_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  received_at datetime NOT NULL,
  source enum('webhook','reconcile','widget_callback','return','expiry') NOT NULL,
  transaction_id varchar(64) DEFAULT NULL,
  idem_key varchar(100) DEFAULT NULL,
  reference varchar(64) NOT NULL DEFAULT '',
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status_received enum('APPROVED','DECLINED','VOIDED','ERROR','PENDING') NOT NULL,
  amount_in_cents bigint(20) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  payment_method_type varchar(32) NOT NULL DEFAULT '',
  checksum_valid tinyint(1) NOT NULL DEFAULT 0,
  api_verified enum('yes','no','deferred','n/a') NOT NULL DEFAULT 'n/a',
  amount_match tinyint(1) DEFAULT NULL,
  currency_match tinyint(1) DEFAULT NULL,
  reference_match tinyint(1) DEFAULT NULL,
  raw_sanitized longtext DEFAULT NULL,
  action_taken varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY uq_idem (idem_key),
  KEY tx_status (transaction_id,status_received),
  KEY reference (reference),
  KEY order_id (order_id),
  KEY received_at (received_at)
) $charset;",

			// Prueba de autorización (FR-075). subject_hash = HMAC; contact_enc = cifrado con ARALIA_PII_KEY.
			"CREATE TABLE {$p}consents (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subject_hash char(64) NOT NULL,
  contact_enc text DEFAULT NULL,
  purpose enum('order','stock_alert','early_access','newsletter','sms') NOT NULL,
  text_version varchar(32) NOT NULL,
  channel varchar(32) NOT NULL DEFAULT '',
  ip_hash char(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  revoked_at datetime DEFAULT NULL,
  claim_in_progress tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY subject_purpose (subject_hash,purpose),
  KEY created_at (created_at),
  KEY revoked_at (revoked_at)
) $charset;",

			// "Avísame cuando vuelva" (FR-008). Un aviso por persona y pieza.
			"CREATE TABLE {$p}stock_alerts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subject_hash char(64) NOT NULL,
  channel enum('email','sms') NOT NULL,
  contact_enc text DEFAULT NULL,
  consent_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  notified_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_alert (product_id,variation_id,subject_hash),
  KEY subject_hash (subject_hash),
  KEY consent_id (consent_id),
  KEY notified_at (notified_at),
  KEY created_at (created_at)
) $charset;",

			// Lista anticipada (FR-013). Una fila por persona (subject_hash único); doble confirmación.
			"CREATE TABLE {$p}early_access (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subject_hash char(64) NOT NULL,
  phone_hash char(64) DEFAULT NULL,
  email_enc text DEFAULT NULL,
  phone_enc text DEFAULT NULL,
  consent_id bigint(20) unsigned NOT NULL,
  email_confirmed_at datetime DEFAULT NULL,
  phone_confirmed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  brevo_synced_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_subject (subject_hash),
  KEY phone_hash (phone_hash),
  KEY consent_id (consent_id),
  KEY confirm_purge (email_confirmed_at,created_at),
  KEY brevo_synced_at (brevo_synced_at)
) $charset;",

			// Registro de actividad (R-3 del TM). Retención >= 1 año.
			"CREATE TABLE {$p}audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ip_hash char(64) NOT NULL DEFAULT '',
  action varchar(40) NOT NULL,
  object_type varchar(40) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  before_value longtext DEFAULT NULL,
  after_value longtext DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY at_action (at,action),
  KEY object_ref (object_type,object_id),
  KEY user_id (user_id)
) $charset;",

			// Límites con incremento atómico (R-12): INSERT ... ON DUPLICATE KEY UPDATE sobre la clave primaria compuesta.
			"CREATE TABLE {$p}rate_limits (
  key_hash char(64) NOT NULL,
  bucket varchar(40) NOT NULL,
  count int(10) unsigned NOT NULL DEFAULT 0,
  window_start datetime NOT NULL,
  PRIMARY KEY  (key_hash,bucket),
  KEY window_start (window_start)
) $charset;",
		);
		// phpcs:enable

		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}
	},
);
