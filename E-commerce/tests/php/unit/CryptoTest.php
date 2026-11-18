<?php
/**
 * T030: cifrado de datos personales y huellas con clave.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Unit;

use Aralia\Privacy\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
	}

	private function crypto( string $pii = 'a', string $hmac = 'b' ): Crypto {
		return new Crypto( base64_encode( str_repeat( $pii, 32 ) ), base64_encode( str_repeat( $hmac, 32 ) ) );
	}

	public function test_encrypt_decrypt_roundtrip_with_unicode(): void {
		$c = $this->crypto();
		$this->assertSame( 'Camila Ñandú +57 300', $c->decrypt( $c->encrypt( 'Camila Ñandú +57 300' ) ) );
		$this->assertSame( '', $c->decrypt( $c->encrypt( '' ) ) );
	}

	public function test_same_plaintext_gives_different_ciphertexts(): void {
		$c = $this->crypto();
		$this->assertNotSame( $c->encrypt( 'x' ), $c->encrypt( 'x' ), 'El nonce es aleatorio por mensaje' );
		$this->assertStringStartsWith( 'v1:', $c->encrypt( 'x' ) );
	}

	/**
	 * @dataProvider provide_tampered
	 */
	public function test_tampered_or_foreign_ciphertext_is_rejected( callable $mutate ): void {
		$c = $this->crypto();
		$this->expectException( \RuntimeException::class );
		$c->decrypt( $mutate( $c->encrypt( 'secreto' ) ) );
	}

	/**
	 * @return array<string,array{callable}>
	 */
	public static function provide_tampered(): array {
		return array(
			'bit flip'      => array( static fn( $e ) => substr( $e, 0, -3 ) . ( 'A' === substr( $e, -3, 1 ) ? 'B' : 'A' ) . substr( $e, -2 ) ),
			'truncated'     => array( static fn( $e ) => substr( $e, 0, 20 ) ),
			'no prefix'     => array( static fn( $e ) => substr( $e, 3 ) ),
			'bad base64'    => array( static fn( $e ) => 'v1:%%%' ),
			'empty'         => array( static fn( $e ) => '' ),
			'other version' => array( static fn( $e ) => 'v2:' . substr( $e, 3 ) ),
		);
	}

	public function test_wrong_key_cannot_decrypt(): void {
		$encrypted = $this->crypto( 'a' )->encrypt( 'secreto' );
		$this->expectException( \RuntimeException::class );
		$this->crypto( 'c' )->decrypt( $encrypted );
	}

	public function test_error_message_does_not_reveal_the_cause_or_key(): void {
		try {
			$this->crypto()->decrypt( 'v1:' . base64_encode( str_repeat( 'x', 60 ) ) );
			$this->fail( 'Debía lanzar' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'No se pudo descifrar.', $e->getMessage() );
		}
	}

	/**
	 * @dataProvider provide_bad_keys
	 */
	public function test_bad_keys_fail_closed( string $pii, string $hmac ): void {
		$this->expectException( \InvalidArgumentException::class );
		new Crypto( $pii, $hmac );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function provide_bad_keys(): array {
		$ok = base64_encode( str_repeat( 'k', 32 ) );
		return array(
			'corta'         => array( base64_encode( 'corta' ), $ok ),
			'no base64'     => array( '###', $ok ),
			'vacía'         => array( '', $ok ),
			'hmac corta'    => array( $ok, base64_encode( 'corta' ) ),
			'iguales'       => array( $ok, $ok ),
			'larga'         => array( base64_encode( str_repeat( 'k', 33 ) ), $ok ),
		);
	}

	public function test_key_values_never_appear_in_the_error(): void {
		try {
			new Crypto( 'clave-secreta-no-valida', base64_encode( str_repeat( 'b', 32 ) ) );
			$this->fail( 'Debía lanzar' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringNotContainsString( 'clave-secreta-no-valida', $e->getMessage() );
		}
	}

	public function test_hashes_are_deterministic_keyed_and_domain_separated(): void {
		$c = $this->crypto();
		$this->assertSame( $c->email_hash( 'Ana@Example.com ' ), $c->email_hash( 'ana@example.com' ), 'Se normaliza antes de la huella' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $c->email_hash( 'ana@example.com' ) );
		$this->assertNotSame( $c->email_hash( 'ana@example.com' ), $this->crypto( 'a', 'z' )->email_hash( 'ana@example.com' ), 'Depende de la llave' );
		$this->assertNotSame( $c->key_hash( 'coupon', 'x' ), $c->key_hash( 'otro', 'x' ), 'Separación de dominio' );
		$this->assertNotSame( $c->email_hash( 'a@b.co' ), hash( 'sha256', 'a@b.co' ), 'No es un SHA-256 sin clave' );
	}

	public function test_phone_normalization_accepts_only_colombian_mobiles(): void {
		$this->assertSame( '+573001234567', Crypto::normalize_phone( '300 123 4567' ) );
		$this->assertSame( '+573001234567', Crypto::normalize_phone( '+57 (300) 123-4567' ) );
		$this->assertSame( '+573001234567', Crypto::normalize_phone( '573001234567' ) );
		$c = $this->crypto();
		$this->assertSame( $c->phone_hash( '3001234567' ), $c->phone_hash( '+57 300 123 4567' ) );
		foreach ( array( '2001234567', '3001234', '+1 300 123 4567', 'abc', '', '30012345678' ) as $bad ) {
			try {
				Crypto::normalize_phone( $bad );
				$this->fail( "Debía rechazar $bad" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'inválido', $e->getMessage() );
			}
		}
	}

	/**
	 * @dataProvider provide_bad_emails
	 */
	public function test_bad_emails_are_rejected( string $email ): void {
		$this->expectException( \InvalidArgumentException::class );
		Crypto::normalize_email( $email );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_bad_emails(): array {
		return array(
			'sin arroba'   => array( 'ana.example.com' ),
			'dos arrobas'  => array( 'a@b@c.co' ),
			'sin dominio'  => array( 'ana@' ),
			'sin punto'    => array( 'ana@localhost' ),
			'con espacio'  => array( 'a na@example.com' ),
			'vacío'        => array( '' ),
			'enorme'       => array( str_repeat( 'a', 250 ) . '@b.co' ),
		);
	}

	public function test_stored_ip_hash_is_truncated_but_the_rate_limit_key_is_not(): void {
		$c = $this->crypto();
		$this->assertSame( $c->ip_hash( '203.0.113.5' ), $c->ip_hash( '203.0.113.200' ), 'IPv4 a /24 para lo que se guarda' );
		$this->assertNotSame( $c->ip_hash( '203.0.113.5' ), $c->ip_hash( '203.0.114.5' ) );
		$this->assertSame( $c->ip_hash( '2001:db8:1:1::1' ), $c->ip_hash( '2001:db8:1:ffff::9' ), 'IPv6 a /48' );
		$this->assertNotSame( $c->ip_key( '203.0.113.5' ), $c->ip_key( '203.0.113.200' ), 'La clave de límite distingue IP completas' );
		$this->assertNotSame( $c->ip_hash( '203.0.113.5' ), $c->ip_key( '203.0.113.5' ), 'Dominios distintos' );
	}

	public function test_ipv4_mapped_ipv6_is_treated_as_the_same_ipv4_everywhere(): void {
		$c = $this->crypto();
		$this->assertSame( $c->ip_hash( '203.0.113.5' ), $c->ip_hash( '::ffff:203.0.113.5' ), 'La evidencia de consentimiento no se pierde en sockets dual-stack' );
		$this->assertSame( $c->ip_key( '203.0.113.5' ), $c->ip_key( '::ffff:203.0.113.5' ) );
		$this->assertNotSame( $c->ip_hash( '::ffff:203.0.113.5' ), $c->ip_hash( '::ffff:198.51.100.5' ), 'Ya no colapsan todas en la misma huella' );
	}

	public function test_rate_limit_key_for_ipv6_covers_the_whole_64(): void {
		$c = $this->crypto();
		$this->assertSame( $c->ip_key( '2001:db8:1:2::1' ), $c->ip_key( '2001:db8:1:2:ffff:ffff:ffff:ffff' ), 'Un /64 es una sola identidad para los límites' );
		$this->assertNotSame( $c->ip_key( '2001:db8:1:2::1' ), $c->ip_key( '2001:db8:1:3::1' ) );
	}

	public function test_limit_email_hash_ignores_plus_aliases_and_gmail_dots_but_the_consent_hash_does_not(): void {
		$c = $this->crypto();
		$this->assertSame( $c->email_limit_hash( 'ana@gmail.com' ), $c->email_limit_hash( 'a.n.a+promo@gmail.com' ) );
		$this->assertSame( $c->email_limit_hash( 'ana@gmail.com' ), $c->email_limit_hash( 'ANA@googlemail.com' ) );
		$this->assertSame( $c->email_limit_hash( 'ana@example.com' ), $c->email_limit_hash( 'ana+x@example.com' ) );
		$this->assertNotSame( $c->email_limit_hash( 'a.na@example.com' ), $c->email_limit_hash( 'ana@example.com' ), 'Los puntos solo se ignoran en Gmail' );
		$this->assertNotSame( $c->email_hash( 'ana@gmail.com' ), $c->email_hash( 'ana+x@gmail.com' ), 'La huella del consentimiento es del correo tal cual' );
		$this->assertNotSame( $c->email_hash( 'ana@gmail.com' ), $c->email_limit_hash( 'ana@gmail.com' ), 'Dominios distintos' );
	}

	public function test_invalid_ips_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->crypto()->ip_hash( 'no-es-una-ip' );
	}

	public function test_from_constants_fails_closed_without_keys(): void {
		if ( defined( 'ARALIA_PII_KEY' ) ) {
			$this->markTestSkipped( 'Las constantes están definidas en este proceso.' );
		}
		$this->expectException( \RuntimeException::class );
		Crypto::from_constants();
	}
}
