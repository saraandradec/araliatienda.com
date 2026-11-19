<?php
/**
 * T031: registro de consentimientos.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Database\Migrator;
use Aralia\Privacy\Consents;
use Aralia\Tests\Support\SecurityTestCase;

final class ConsentsTest extends SecurityTestCase {

	private function consents(): Consents {
		return new Consents( $this->crypto() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row( int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . Migrator::tables()['consents'] . '` WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public function test_grant_stores_hash_encrypted_contact_and_truncated_ip(): void {
		$id  = $this->consents()->grant( 'email', ' Ana@Example.com ', 'stock_alert', 'v1.0', 'ficha', '203.0.113.7' );
		$row = $this->row( $id );
		$this->assertSame( $this->crypto()->email_hash( 'ana@example.com' ), $row['subject_hash'] );
		$this->assertSame( 'ana@example.com', $this->crypto()->decrypt( $row['contact_enc'] ) );
		$this->assertStringNotContainsString( 'ana@example.com', $row['contact_enc'] );
		$this->assertSame( $this->crypto()->ip_hash( '203.0.113.250' ), $row['ip_hash'], 'Misma /24 => misma huella (la IP se trunca)' );
		$this->assertSame( 'v1.0', $row['text_version'] );
		$this->assertNull( $row['revoked_at'] );
	}

	public function test_phone_consent_uses_the_normalized_number(): void {
		$c = $this->consents();
		$c->grant( 'phone', '300 123 4567', 'sms', 'v1', 'checkout', '203.0.113.7' );
		$this->assertTrue( $c->is_active( 'phone', '+57 3001234567', 'sms' ) );
		$this->assertFalse( $c->is_active( 'phone', '3001234567', 'newsletter' ), 'Otro propósito no cuenta' );
	}

	public function test_revoke_keeps_the_row_as_proof_and_is_scoped_to_the_purpose(): void {
		$c = $this->consents();
		$a = $c->grant( 'email', 'ana@example.com', 'newsletter', 'v1', 'footer', '203.0.113.7' );
		$c->grant( 'email', 'ana@example.com', 'stock_alert', 'v1', 'ficha', '203.0.113.7' );

		$this->assertSame( 1, $c->revoke( 'email', 'ana@example.com', 'newsletter' ) );
		$this->assertFalse( $c->is_active( 'email', 'ana@example.com', 'newsletter' ) );
		$this->assertTrue( $c->is_active( 'email', 'ana@example.com', 'stock_alert' ) );
		$this->assertNotNull( $this->row( $a )['revoked_at'], 'La fila sigue ahí, con la fecha de revocación' );
		$this->assertSame( 0, $c->revoke( 'email', 'ana@example.com', 'newsletter' ), 'Revocar dos veces no cambia nada' );
	}

	public function test_anonymize_removes_the_contact_but_keeps_the_proof_unless_a_claim_is_open(): void {
		$c  = $this->consents();
		$id = $c->grant( 'email', 'ana@example.com', 'newsletter', 'v1', 'footer', '203.0.113.7' );
		$this->assertSame( 0, $c->anonymize_revoked( 'email', 'ana@example.com' ), 'Vigente: no se anonimiza' );

		$c->revoke( 'email', 'ana@example.com', 'newsletter' );
		$c->set_claim_in_progress( 'email', 'ana@example.com', true );
		$this->assertSame( 0, $c->anonymize_revoked( 'email', 'ana@example.com' ), 'Con un reclamo en trámite no se toca' );

		$c->set_claim_in_progress( 'email', 'ana@example.com', false );
		$this->assertSame( 1, $c->anonymize_revoked( 'email', 'ana@example.com' ) );
		$row = $this->row( $id );
		$this->assertNull( $row['contact_enc'] );
		$this->assertSame( $this->crypto()->email_hash( 'ana@example.com' ), $row['subject_hash'], 'La huella (prueba) se conserva' );
		$this->assertSame( 'newsletter', $row['purpose'] );
	}

	/**
	 * @dataProvider provide_invalid
	 * @param array<int,string> $args Argumentos de grant().
	 */
	public function test_invalid_input_is_rejected_and_nothing_is_written( array $args ): void {
		global $wpdb;
		try {
			$this->consents()->grant( ...$args );
			$this->fail( 'Debía rechazar' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM `' . Migrator::tables()['consents'] . '`' ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * @return array<string,array{array<int,string>}>
	 */
	public static function provide_invalid(): array {
		return array(
			'propósito'       => array( array( 'email', 'a@b.co', 'hackeo', 'v1', 'x', '203.0.113.7' ) ),
			'correo'          => array( array( 'email', 'no-es-correo', 'order', 'v1', 'x', '203.0.113.7' ) ),
			'celular'         => array( array( 'phone', '12345', 'sms', 'v1', 'x', '203.0.113.7' ) ),
			'versión'         => array( array( 'email', 'a@b.co', 'order', 'v1; DROP', 'x', '203.0.113.7' ) ),
			'canal'           => array( array( 'email', 'a@b.co', 'order', 'v1', 'CANAL RARO!', '203.0.113.7' ) ),
			'ip'              => array( array( 'email', 'a@b.co', 'order', 'v1', 'x', 'no-ip' ) ),
		);
	}

	public function test_invalid_privacy_keys_make_it_impossible_to_build_the_crypto_that_consents_need(): void {
		// Consents exige un Crypto ya válido: con llaves inválidas el Crypto no se puede construir y no hay forma de registrar un consentimiento sin cifrar.
		$this->expectException( \InvalidArgumentException::class );
		new Consents( new \Aralia\Privacy\Crypto( 'x', 'y' ) );
	}

	public function test_an_unknown_contact_type_is_rejected_by_the_type_check_itself(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Tipo de contacto inválido.' );
		$this->consents()->revoke( 'fax', '3001234567', 'sms' );
	}
}
