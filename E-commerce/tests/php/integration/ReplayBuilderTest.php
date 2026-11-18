<?php
/**
 * T162: petición de `wp aralia wompi replay` (validación, firma antes/después, destino permitido).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Wompi\EventSigner;
use Aralia\Wompi\ReplayBuilder;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ReplayBuilderTest extends TestCase {

	private const SECRET = 'test_events_secret';
	private const HOME   = 'http://localhost:8889/';

	/**
	 * @return array<string,mixed>
	 */
	private function event(): array {
		$file = dirname( __DIR__, 2 ) . '/fixtures/wompi/approved.json';
		return json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * @param array<string,string> $options Opciones.
	 * @return array{url:string,headers:array<string,string>,body:string}
	 */
	private function build( array $options = array(), string $env = 'test' ): array {
		return ReplayBuilder::build( $this->event(), self::SECRET, $env, self::HOME, $options );
	}

	public function test_default_request_is_signed_and_goes_to_the_site_itself(): void {
		$req  = $this->build();
		$body = json_decode( $req['body'], true );
		$this->assertSame( 'http://localhost:8889/?wc-api=wc_wompi', $req['url'] );
		$this->assertSame( $body['signature']['checksum'], $req['headers']['X-Event-Checksum'] );
		$expected = EventSigner::sign( $this->event(), self::SECRET, (int) $body['timestamp'] );
		$this->assertSame( $expected['signature']['checksum'], $body['signature']['checksum'] );
	}

	public function test_amount_override_is_signed_so_the_checksum_is_valid_for_the_altered_amount(): void {
		$req  = $this->build( array( 'amount' => '100' ) );
		$body = json_decode( $req['body'], true );
		$this->assertSame( 100, $body['data']['transaction']['amount_in_cents'] );
		$again = EventSigner::sign( $body, self::SECRET, (int) $body['timestamp'] );
		$this->assertSame( $again['signature']['checksum'], $body['signature']['checksum'], 'T-EVT-AMOUNT: monto distinto con checksum VÁLIDO' );
	}

	public function test_tamper_checksum_breaks_the_signature(): void {
		$body  = json_decode( $this->build( array( 'tamper' => 'checksum' ) )['body'], true );
		$again = EventSigner::sign( $body, self::SECRET, (int) $body['timestamp'] );
		$this->assertNotSame( $again['signature']['checksum'], $body['signature']['checksum'], 'T-EVT-FORGED: firma inválida' );
	}

	public function test_overrides_reference_status_and_transaction(): void {
		$body = json_decode( $this->build( array( 'reference' => '77-2', 'status' => 'DECLINED', 'transaction-id' => 'TX-9' ) )['body'], true );
		$this->assertSame( '77-2', $body['data']['transaction']['reference'] );
		$this->assertSame( 'DECLINED', $body['data']['transaction']['status'] );
		$this->assertSame( 'TX-9', $body['data']['transaction']['id'] );
	}

	public function test_valid_currency_and_pending_status_are_accepted(): void {
		$body = json_decode( $this->build( array( 'currency' => 'USD', 'status' => 'PENDING' ) )['body'], true );
		$this->assertSame( 'USD', $body['data']['transaction']['currency'] );
		$this->assertSame( 'PENDING', $body['data']['transaction']['status'] );
	}

	public function test_fixture_reference_has_the_format_the_guard_expects(): void {
		$this->assertMatchesRegularExpression( '/^\d+-\d{1,3}$/', $this->event()['data']['transaction']['reference'] );
	}

	/**
	 * @dataProvider provide_rejected
	 * @param array<string,string> $options Opciones.
	 */
	public function test_rejects( array $options, string $env ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->build( $options, $env );
	}

	/**
	 * @return array<string,array{array<string,string>,string}>
	 */
	public static function provide_rejected(): array {
		return array(
			'producción'              => array( array(), 'production' ),
			'sin entorno'             => array( array(), '' ),
			'entorno mal escrito'     => array( array(), 'Production' ),
			'tamper desconocido'      => array( array( 'tamper' => 'amount' ), 'test' ),
			'opción desconocida'      => array( array( 'url' => 'http://evil' ), 'test' ),
			'monto no numérico'       => array( array( 'amount' => '1e9' ), 'test' ),
			'estado inventado'        => array( array( 'status' => 'HACKED' ), 'test' ),
			'moneda inválida'         => array( array( 'currency' => 'cop' ), 'test' ),
			'base-url en staging'     => array( array( 'base-url' => 'http://localhost/' ), 'staging' ),
			'base-url metadatos nube' => array( array( 'base-url' => 'http://169.254.169.254/' ), 'test' ),
			'base-url externa'        => array( array( 'base-url' => 'https://example.com/' ), 'test' ),
			'base-url con usuario'    => array( array( 'base-url' => 'http://user:pw@localhost/' ), 'test' ),
			'base-url sin http'       => array( array( 'base-url' => 'ftp://localhost/' ), 'test' ),
		);
	}

	public function test_base_url_to_the_wp_env_container_is_allowed_in_test(): void {
		$this->assertSame( 'http://tests-wordpress/?wc-api=wc_wompi', $this->build( array( 'base-url' => 'http://tests-wordpress' ) )['url'] );
	}

	public function test_staging_may_replay_against_its_own_site(): void {
		$this->assertStringStartsWith( self::HOME, $this->build( array(), 'staging' )['url'] );
	}
}
