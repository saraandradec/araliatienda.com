<?php
/**
 * T028: cliente de Brevo (correo y SMS).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Notifications\BrevoClient;
use Aralia\Security\RateLimiter;
use Aralia\Tests\Support\SecurityTestCase;

final class BrevoClientTest extends SecurityTestCase {

	public function test_email_goes_to_the_api_with_the_key_in_the_header_only(): void {
		$calls  = array();
		$client = new BrevoClient( 'llave-de-prueba', $this->fake_http( $this->http_response( 201, '{}' ), $calls ) );
		$this->assertTrue( $client->send_email( 'sara@example.com', 'Hola <b>mundo</b>', '<p>x</p>', 'x' ) );
		$this->assertSame( BrevoClient::EMAIL_URL, $calls[0]['url'] );
		$this->assertSame( 'llave-de-prueba', $calls[0]['args']['headers']['api-key'] );
		$body = json_decode( $calls[0]['args']['body'], true );
		$this->assertSame( array( array( 'email' => 'sara@example.com' ) ), $body['to'] );
		$this->assertSame( 'Hola mundo', $body['subject'], 'El asunto no lleva HTML' );
		$this->assertStringNotContainsString( 'llave-de-prueba', $calls[0]['args']['body'] );
	}

	public function test_nothing_is_sent_without_key_or_with_bad_recipient(): void {
		$calls = array();
		$http  = $this->fake_http( $this->http_response( 201, '{}' ), $calls );
		$this->assertFalse( ( new BrevoClient( '', $http ) )->send_email( 'sara@example.com', 'a', 'b' ) );
		$this->assertFalse( ( new BrevoClient( 'k', $http ) )->send_email( 'no-es-correo', 'a', 'b' ) );
		$this->assertFalse( ( new BrevoClient( 'k', $http ) )->send_email( 'sara@example.com', '   ', 'b' ) );
		$this->assertFalse( ( new BrevoClient( '', $http ) )->send_sms( '3001234567', 'hola' ) );
		$this->assertSame( array(), $calls );
	}

	public function test_failures_return_false_without_leaking_the_key_or_the_recipient_to_the_log(): void {
		$calls = array();
		$log   = tempnam( sys_get_temp_dir(), 'aralia-log' );
		$prev  = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet
		foreach ( array( $this->http_response( 401, '{"message":"bad key"}' ), $this->http_response( 500, '' ), new \WP_Error( 'x', 'timeout' ) ) as $response ) {
			$client = new BrevoClient( 'llave-secreta', $this->fake_http( $response, $calls ) );
			$this->assertFalse( $client->send_email( 'sara@example.com', 'a', 'b' ) );
		}
		ini_set( 'error_log', (string) $prev ); // phpcs:ignore WordPress.PHP.IniSet
		$logged = (string) file_get_contents( $log );
		unlink( $log );
		$this->assertStringContainsString( 'Brevo', $logged, 'Los fallos sí se registran' );
		$this->assertStringNotContainsString( 'llave-secreta', $logged );
		$this->assertStringNotContainsString( 'sara@example.com', $logged );
	}

	private function sms_client( array &$calls, int $code = 201 ): BrevoClient {
		return new BrevoClient( 'k', $this->fake_http( $this->http_response( $code, '{}' ), $calls ), new RateLimiter(), $this->crypto() );
	}

	public function test_customer_sms_without_a_limiter_is_never_sent(): void {
		$calls = array();
		$this->assertFalse( ( new BrevoClient( 'k', $this->fake_http( $this->http_response( 201, '{}' ), $calls ) ) )->send_sms( '3001234567', 'hola' ), 'El tope por número no puede ser opcional' );
		$this->assertSame( array(), $calls );
	}

	public function test_sms_fails_closed_when_the_limiter_cannot_count(): void {
		global $wpdb;
		$calls  = array();
		$client = $this->sms_client( $calls );
		$wpdb->query( 'DROP TABLE `' . \Aralia\Database\Migrator::tables()['rate_limits'] . '`' ); // phpcs:ignore WordPress.DB
		$prev = $wpdb->suppress_errors( true );
		$sent = $client->send_sms( '3001234567', 'hola' );
		$wpdb->suppress_errors( $prev );
		$this->assertFalse( $sent );
		$this->assertSame( array(), $calls );
	}

	public function test_alert_sms_is_not_capped_per_number_but_has_its_own_global_cap(): void {
		$calls  = array();
		$client = $this->sms_client( $calls );
		for ( $i = 1; $i <= BrevoClient::ALERT_SMS_PER_DAY; $i++ ) {
			$this->assertTrue( $client->send_alert_sms( '3001234567', "alerta $i" ), "alerta $i" );
		}
		$this->assertFalse( $client->send_alert_sms( '3001234567', 'una más' ), 'Tope global diario de alertas' );
		$this->assertTrue( $client->send_sms( '3001234567', 'cliente' ), 'El tope de alertas no consume el cupo de las clientas' );
	}

	public function test_alert_sms_still_goes_out_if_the_limiter_fails(): void {
		global $wpdb;
		$calls  = array();
		$client = $this->sms_client( $calls );
		$wpdb->query( 'DROP TABLE `' . \Aralia\Database\Migrator::tables()['rate_limits'] . '`' ); // phpcs:ignore WordPress.DB
		$prev = $wpdb->suppress_errors( true );
		$sent = $client->send_alert_sms( '3001234567', 'crítica' );
		$wpdb->suppress_errors( $prev );
		$this->assertTrue( $sent, 'Una alerta crítica no se pierde por un fallo del contador' );
	}

	public function test_email_sender_comes_from_the_verified_domain_constant_when_defined(): void {
		$calls  = array();
		$client = new BrevoClient( 'k', $this->fake_http( $this->http_response( 201, '{}' ), $calls ) );
		add_filter( 'aralia_mail_from', static fn() => 'tienda@dominio-verificado.example' );
		$client->send_email( 'sara@example.com', 'a', 'b' );
		remove_all_filters( 'aralia_mail_from' );
		$this->assertSame( 'tienda@dominio-verificado.example', json_decode( $calls[0]['args']['body'], true )['sender']['email'] );
	}

	public function test_sms_only_to_colombian_mobiles_with_the_international_format(): void {
		$calls  = array();
		$client = $this->sms_client( $calls );
		$this->assertTrue( $client->send_sms( '300 123 4567', 'Tu pedido salió' ) );
		$body = json_decode( $calls[0]['args']['body'], true );
		$this->assertSame( '573001234567', $body['recipient'] );
		$this->assertSame( 'transactional', $body['type'] );

		foreach ( array( '+1 415 555 0100', '2001234567', 'abc', '' ) as $bad ) {
			$this->assertFalse( $client->send_sms( $bad, 'hola' ), "SMS a $bad" );
		}
		$this->assertCount( 1, $calls );
	}

	public function test_sms_content_limits(): void {
		$calls  = array();
		$client = $this->sms_client( $calls );
		$this->assertFalse( $client->send_sms( '3001234567', '' ) );
		$this->assertFalse( $client->send_sms( '3001234567', str_repeat( 'a', BrevoClient::SMS_MAX_CHARS + 1 ) ) );
		$this->assertTrue( $client->send_sms( '3001234567', '<b>ok</b>' ) );
		$this->assertSame( 'ok', json_decode( $calls[0]['args']['body'], true )['content'] );
	}

	public function test_sms_are_capped_at_three_per_number_per_day(): void {
		$calls  = array();
		$client = $this->sms_client( $calls );
		$this->assertTrue( $client->send_sms( '3001234567', 'uno' ) );
		$this->assertTrue( $client->send_sms( '+57 300 123 4567', 'dos' ) );
		$this->assertTrue( $client->send_sms( '3001234567', 'tres' ) );
		$this->assertFalse( $client->send_sms( '3001234567', 'cuatro' ), 'El cuarto del día se rechaza (SMS pumping)' );
		$this->assertTrue( $client->send_sms( '3109876543', 'otro número' ) );
		$this->assertCount( 4, $calls );
	}
}
