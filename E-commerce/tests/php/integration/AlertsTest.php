<?php
/**
 * T029: matriz de alertas (R-22).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Database\Migrator;
use Aralia\Notifications\BrevoClient;
use Aralia\Security\Alerts;
use Aralia\Security\RateLimiter;
use Aralia\Tests\Support\SecurityTestCase;

final class AlertsTest extends SecurityTestCase {

	/**
	 * @var array<int,array<string,mixed>>
	 */
	private array $calls = array();

	private function alerts( int $code = 201, bool $with_crypto = true ): Alerts {
		$this->calls = array();
		$brevo       = new BrevoClient( 'k', $this->fake_http( $this->http_response( $code, '{}' ), $this->calls ), new RateLimiter(), $this->crypto() );
		return new Alerts( new RateLimiter(), $with_crypto ? $this->crypto() : null, $brevo );
	}

	protected function set_up() {
		parent::set_up();
		update_option( 'aralia_alert_email', 'sara@example.com' );
		update_option( 'aralia_alert_phone', '3001234567' );
	}

	protected function tear_down() {
		delete_option( 'aralia_alert_email' );
		delete_option( 'aralia_alert_phone' );
		parent::tear_down();
	}

	/**
	 * @return string[] URLs llamadas.
	 */
	private function urls(): array {
		return array_column( $this->calls, 'url' );
	}

	public function test_the_matrix_matches_r_22(): void {
		$m = Alerts::matrix();
		foreach ( array( 'nostock', 'blocked_transition', 'checksum_invalid', 'payment_declines', 'deferred_unresolved', 'expiry_late', 'script_critical', 'admin_role_change', 'vulnerability' ) as $critical ) {
			$this->assertSame( Alerts::CRITICAL, $m[ $critical ][0], $critical );
		}
		foreach ( array( 'script_inline', 'new_device_login', 'backup_failed', 'low_stock' ) as $notice ) {
			$this->assertSame( Alerts::NOTICE, $m[ $notice ][0], $notice );
		}
		$this->assertSame( 5, $m['checksum_invalid'][1] );
		$this->assertSame( 20, $m['payment_declines'][1] );
		$this->assertCount( 13, $m );
	}

	public function test_a_critical_alert_sends_email_and_sms_and_is_audited(): void {
		global $wpdb;
		$this->assertTrue( $this->alerts()->raise( 'nostock', 'pedido 123', true ) );
		$this->assertSame( array( BrevoClient::EMAIL_URL, BrevoClient::SMS_URL ), $this->urls() );
		$email = json_decode( $this->calls[0]['args']['body'], true );
		$this->assertStringStartsWith( '[CRÍTICO]', $email['subject'] );
		$this->assertStringContainsString( 'pedido 123', $email['textContent'] );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM `" . Migrator::tables()['audit_log'] . "` WHERE action = 'alert_raised'" ) ); // phpcs:ignore WordPress.DB
	}

	public function test_a_notice_sends_only_email(): void {
		$this->alerts()->raise( 'low_stock', '', true );
		$this->assertSame( array( BrevoClient::EMAIL_URL ), $this->urls() );
		$this->assertStringStartsWith( '[Aviso]', json_decode( $this->calls[0]['args']['body'], true )['subject'] );
	}

	public function test_thresholds_fire_once_when_crossed_within_the_window(): void {
		$alerts = $this->alerts();
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertFalse( $alerts->raise( 'checksum_invalid', '', true ), "evento $i: por debajo del umbral" );
		}
		$this->assertSame( array(), $this->calls );
		$this->assertTrue( $alerts->raise( 'checksum_invalid', '', true ), 'El 6.º cruza el umbral (> 5/h)' );
		$this->assertFalse( $alerts->raise( 'checksum_invalid', '', true ), 'No vuelve a saltar en la misma ventana' );
		$this->assertCount( 2, $this->calls, 'Un correo y un SMS, una sola vez' );
	}

	public function test_unknown_events_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->alerts()->raise( 'inventado' );
	}

	public function test_the_reference_is_sanitized_and_carries_no_markup(): void {
		$this->alerts()->raise( 'nostock', "pedido 7 <script>alert(1)</script>\n@x", true );
		$text = json_decode( $this->calls[0]['args']['body'], true )['textContent'];
		$this->assertStringNotContainsString( '<script>', $text );
		$this->assertStringNotContainsString( "\n@x", $text );
	}

	public function test_without_a_phone_a_critical_alert_still_goes_by_email(): void {
		delete_option( 'aralia_alert_phone' );
		$this->assertTrue( $this->alerts()->raise( 'nostock', '', true ) );
		$this->assertSame( array( BrevoClient::EMAIL_URL ), $this->urls() );
	}

	public function test_when_brevo_fails_the_email_falls_back_to_wp_mail(): void {
		$sent = array();
		add_filter( 'pre_wp_mail', static function ( $null, $atts ) use ( &$sent ) {
			$sent[] = $atts;
			return true;
		}, 10, 2 );
		$this->assertTrue( $this->alerts( 500 )->deliver( 'nostock', Alerts::CRITICAL, 'pedido 9' ) );
		remove_all_filters( 'pre_wp_mail' );
		$this->assertCount( 1, $sent, 'Si Brevo cae, la alerta sale por wp_mail' );
		$this->assertSame( 'sara@example.com', $sent[0]['to'] );
	}

	public function test_delivery_reports_failure_when_no_channel_works(): void {
		add_filter( 'pre_wp_mail', '__return_false' );
		$this->assertFalse( $this->alerts( 500 )->deliver( 'nostock', Alerts::CRITICAL, '' ) );
		remove_all_filters( 'pre_wp_mail' );
	}

	public function test_severity_comes_from_the_matrix_not_from_the_queue(): void {
		$this->alerts()->deliver( 'low_stock', Alerts::CRITICAL, '' );
		$this->assertSame( array( BrevoClient::EMAIL_URL ), $this->urls(), 'Un aviso encolado como "crítico" no dispara SMS' );
		$this->assertStringStartsWith( '[Aviso]', json_decode( $this->calls[0]['args']['body'], true )['subject'] );
	}

	public function test_alert_sms_are_not_consumed_by_the_per_number_cap_of_customers(): void {
		$alerts = $this->alerts();
		for ( $i = 0; $i < 6; $i++ ) {
			$alerts->raise( 'nostock', "p$i", true );
		}
		$sms = array_filter( $this->urls(), static fn( $u ) => BrevoClient::SMS_URL === $u );
		$this->assertCount( 6, $sms, 'Más de 3 alertas críticas el mismo día siguen llegando por SMS' );
	}

	public function test_it_works_without_privacy_keys(): void {
		$this->assertTrue( $this->alerts( 201, false )->raise( 'nostock', 'sin llaves', true ) );
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->alerts( 201, false )->raise( 'checksum_invalid', '', true );
		}
		$this->assertTrue( $this->alerts( 201, false )->raise( 'checksum_invalid', '', true ), 'El umbral también funciona sin llaves de privacidad' );
	}

	public function test_if_the_counter_fails_the_threshold_alert_still_fires(): void {
		global $wpdb;
		$alerts = $this->alerts();
		$wpdb->query( 'DROP TABLE `' . Migrator::tables()['rate_limits'] . '`' ); // phpcs:ignore WordPress.DB
		$prev  = $wpdb->suppress_errors( true );
		$fired = $alerts->raise( 'checksum_invalid', '', true );
		$wpdb->suppress_errors( $prev );
		$this->assertTrue( $fired, 'Perder una alerta es peor que repetirla' );
	}

	public function test_destinations_only_come_from_constants_in_strict_envs(): void {
		update_option( 'aralia_alert_email', 'opcion@example.com' );
		update_option( 'aralia_alert_phone', '3001112233' );
		[ $email, $phone ] = Alerts::destinations( 'test' );
		$this->assertSame( 'opcion@example.com', $email );
		$this->assertSame( '3001112233', $phone );
		foreach ( array( 'production', 'staging', '' ) as $env ) {
			$this->assertSame( array( '', '' ), Alerts::destinations( $env ), "En '$env' una opción editable desde wp-admin no puede desviar las alertas" );
		}
	}

	public function test_it_is_queued_in_action_scheduler_when_not_sync(): void {
		$alerts = $this->alerts();
		$alerts->register();
		$this->assertNotFalse( has_action( 'aralia_deliver_alert', array( $alerts, 'on_deliver_action' ) ) );
		$this->assertTrue( $alerts->raise( 'backup_failed', 'ref' ) );
		$this->assertSame( array(), $this->calls, 'Encolada: no se envía en la misma petición' );
		$this->assertNotFalse( as_next_scheduled_action( 'aralia_deliver_alert', array( 'backup_failed', Alerts::NOTICE, 'ref' ), 'aralia' ) );
		as_unschedule_all_actions( 'aralia_deliver_alert' );
		remove_action( 'aralia_deliver_alert', array( $alerts, 'on_deliver_action' ) );
	}

	public function test_the_queued_action_actually_delivers_when_it_runs(): void {
		// Se quita el manejador real del plugin (sin llave de Brevo) para observar solo esta instancia.
		remove_all_actions( 'aralia_deliver_alert' );
		$alerts = $this->alerts();
		$alerts->register();
		do_action( 'aralia_deliver_alert', 'nostock', Alerts::CRITICAL, 'pedido 5' );
		$this->assertSame( array( BrevoClient::EMAIL_URL, BrevoClient::SMS_URL ), $this->urls() );
	}

	public function test_a_failed_delivery_makes_the_scheduled_action_fail_visibly_instead_of_completing(): void {
		add_filter( 'pre_wp_mail', '__return_false' );
		$alerts = $this->alerts( 500 );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Alerta no entregada' );
		try {
			$alerts->on_deliver_action( 'nostock', Alerts::CRITICAL, '' );
		} finally {
			remove_all_filters( 'pre_wp_mail' );
		}
	}

	public function test_if_the_queue_is_unavailable_the_alert_is_delivered_immediately(): void {
		$this->calls = array();
		$brevo       = new BrevoClient( 'k', $this->fake_http( $this->http_response( 201, '{}' ), $this->calls ), new RateLimiter(), $this->crypto() );
		$queued      = array();
		$alerts      = new Alerts( new RateLimiter(), $this->crypto(), $brevo, static function ( ...$args ) use ( &$queued ) {
			$queued[] = $args;
			return 0; // Action Scheduler no disponible: devuelve 0.
		} );
		$this->assertTrue( $alerts->raise( 'nostock', 'sin cola' ) );
		$this->assertCount( 1, $queued, 'Se intentó encolar' );
		$this->assertSame( array( BrevoClient::EMAIL_URL, BrevoClient::SMS_URL ), $this->urls(), 'y como no se pudo, se entregó ya' );
	}

	public function test_when_the_queue_works_nothing_is_sent_in_the_same_request(): void {
		$this->calls = array();
		$brevo       = new BrevoClient( 'k', $this->fake_http( $this->http_response( 201, '{}' ), $this->calls ), new RateLimiter(), $this->crypto() );
		$alerts      = new Alerts( new RateLimiter(), $this->crypto(), $brevo, static fn() => 123 );
		$this->assertTrue( $alerts->raise( 'nostock', 'con cola' ) );
		$this->assertSame( array(), $this->calls );
	}
}
