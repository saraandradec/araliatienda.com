<?php
/**
 * T035: Turnstile del lado del servidor (falla cerrado).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Security\Turnstile;
use Aralia\Tests\Support\SecurityTestCase;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

final class TurnstileTest extends SecurityTestCase {

	/**
	 * @var Turnstile[]
	 */
	private array $registered = array();

	protected function tear_down() {
		foreach ( $this->registered as $ts ) {
			remove_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $ts, 'on_checkout' ) );
			remove_action( 'woocommerce_after_checkout_validation', array( $ts, 'on_classic_checkout' ) );
			remove_action( 'woocommerce_blocks_loaded', array( $ts, 'register_extension' ) );
		}
		$this->registered = array();
		parent::tear_down();
	}

	private function request( ?string $token, string $method = 'POST' ): \WP_REST_Request {
		$req = new \WP_REST_Request( $method, '/wc/store/v1/checkout' );
		if ( null !== $token ) {
			$req->set_param( 'extensions', array( 'aralia' => array( 'turnstile_token' => $token ) ) );
		}
		return $req;
	}

	public function test_valid_token_passes_and_sends_secret_response_and_ip(): void {
		$calls = array();
		$ts    = new Turnstile( 'secreto-de-prueba', $this->fake_http( $this->http_response( 200, '{"success":true}' ), $calls ) );
		$res   = $ts->verify( 'token-ok', '203.0.113.5' );
		$this->assertTrue( $res['success'] );
		$this->assertSame( Turnstile::VERIFY_URL, $calls[0]['url'] );
		$this->assertSame( 'secreto-de-prueba', $calls[0]['args']['body']['secret'] );
		$this->assertSame( 'token-ok', $calls[0]['args']['body']['response'] );
		$this->assertSame( '203.0.113.5', $calls[0]['args']['body']['remoteip'] );
		$this->assertSame( 5, $calls[0]['args']['timeout'] );
	}

	/**
	 * @dataProvider provide_failures
	 * @param array<string,mixed>|\WP_Error $response Respuesta.
	 */
	public function test_everything_that_is_not_an_explicit_success_fails_closed( $response ): void {
		$calls = array();
		$res   = ( new Turnstile( 's', $this->fake_http( $response, $calls ) ) )->verify( 'token' );
		$this->assertFalse( $res['success'] );
	}

	/**
	 * @return array<string,array{mixed}>
	 */
	public static function provide_failures(): array {
		$r = static fn( int $c, string $b ): array => array( 'response' => array( 'code' => $c, 'message' => '' ), 'body' => $b, 'headers' => array() );
		return array(
			'success false'         => array( $r( 200, '{"success":false,"error-codes":["timeout-or-duplicate"]}' ) ),
			'success como texto'    => array( $r( 200, '{"success":"true"}' ) ),
			'success como número'   => array( $r( 200, '{"success":1}' ) ),
			'sin success'           => array( $r( 200, '{}' ) ),
			'json inválido'         => array( $r( 200, 'no es json' ) ),
			'error 500'             => array( $r( 500, '{"success":true}' ) ),
			'cuerpo vacío'          => array( $r( 200, '' ) ),
			'error de red'          => array( new \WP_Error( 'http_request_failed', 'timeout' ) ),
		);
	}

	public function test_empty_and_oversized_tokens_do_not_even_call_the_api(): void {
		$calls = array();
		$ts    = new Turnstile( 's', $this->fake_http( $this->http_response( 200, '{"success":true}' ), $calls ) );
		$this->assertFalse( $ts->verify( '' )['success'] );
		$this->assertFalse( $ts->verify( str_repeat( 'x', Turnstile::TOKEN_MAX + 1 ) )['success'] );
		$this->assertSame( array(), $calls );
	}

	public function test_invalid_ip_is_not_forwarded(): void {
		$calls = array();
		( new Turnstile( 's', $this->fake_http( $this->http_response( 200, '{"success":true}' ), $calls ) ) )->verify( 't', 'no-ip; drop' );
		$this->assertArrayNotHasKey( 'remoteip', $calls[0]['args']['body'] );
	}

	/**
	 * @dataProvider provide_strict_envs
	 */
	public function test_without_a_secret_only_local_and_test_pass( string $env, bool $passes ): void {
		$res = ( new Turnstile( '' ) )->verify( 'cualquiera', '', $env );
		$this->assertSame( $passes, $res['success'], "env=$env" );
		$this->assertSame( $passes ? array( 'skipped-no-secret' ) : array( 'missing-secret' ), $res['codes'] );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function provide_strict_envs(): array {
		return array(
			'local'      => array( 'local', true ),
			'test'       => array( 'test', true ),
			'staging'    => array( 'staging', false ),
			'production' => array( 'production', false ),
			'vacío'      => array( '', false ),
			'prod'       => array( 'prod', false ),
		);
	}

	/**
	 * @dataProvider provide_dummy_secrets
	 */
	public function test_cloudflare_test_secrets_are_a_failure_outside_local_and_test( string $secret ): void {
		$calls = array();
		$ts    = new Turnstile( $secret, $this->fake_http( $this->http_response( 200, '{"success":true}' ), $calls ) );
		$res   = $ts->verify( 'token', '', 'production' );
		$this->assertFalse( $res['success'], 'La llave de prueba de Cloudflare siempre pasa: en producción sería un bypass total' );
		$this->assertSame( array( 'dummy-secret-in-strict-env' ), $res['codes'] );
		$this->assertSame( array(), $calls, 'Ni siquiera se llama a Cloudflare' );
		$this->assertTrue( $ts->verify( 'token', '', 'test' )['success'], 'En test sí se admiten' );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_dummy_secrets(): array {
		return array_map( static fn( $s ) => array( $s ), array_combine( Turnstile::DUMMY_SECRETS, Turnstile::DUMMY_SECRETS ) );
	}

	public function test_a_token_for_another_form_or_site_is_rejected_in_strict_envs(): void {
		$calls = array();
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$make  = fn( string $body ) => new Turnstile( 'secreto-real', $this->fake_http( $this->http_response( 200, $body ), $calls ) );

		$ok = $make( '{"success":true,"action":"checkout","hostname":"' . $host . '"}' )->verify( 't', '', 'production' );
		$this->assertTrue( $ok['success'] );

		$other_form = $make( '{"success":true,"action":"newsletter","hostname":"' . $host . '"}' )->verify( 't', '', 'production' );
		$this->assertFalse( $other_form['success'] );
		$this->assertContains( 'action-mismatch', $other_form['codes'] );

		$other_site = $make( '{"success":true,"action":"checkout","hostname":"otro-sitio.example"}' )->verify( 't', '', 'production' );
		$this->assertFalse( $other_site['success'] );
		$this->assertContains( 'hostname-mismatch', $other_site['codes'] );

		$this->assertTrue( $make( '{"success":true,"action":"newsletter","hostname":"otro-sitio.example"}' )->verify( 't', '', 'test' )['success'], 'En test (llaves de prueba) no se exige' );
	}

	public function test_checkout_hook_rejects_a_bad_token_with_a_403_route_exception(): void {
		$calls = array();
		$ts    = new Turnstile( 's', $this->fake_http( $this->http_response( 200, '{"success":false}' ), $calls ) );
		$caught = null;
		try {
			$ts->on_checkout( new \WC_Order(), $this->request( 'malo' ) );
		} catch ( RouteException $e ) {
			$caught = $e;
		}
		$this->assertInstanceOf( RouteException::class, $caught, 'on_checkout debía lanzar RouteException' );
		$this->assertSame( 'aralia_turnstile_failed', $caught->getErrorCode() );
		$this->assertSame( 403, $caught->getCode() );
	}

	public function test_checkout_hook_without_the_extension_is_rejected_with_the_same_exception(): void {
		$calls = array();
		$ts    = new Turnstile( 's', $this->fake_http( $this->http_response( 200, '{"success":true}' ), $calls ) );
		$this->expectException( RouteException::class );
		$ts->on_checkout( new \WC_Order(), $this->request( null ) );
	}

	public function test_checkout_hook_accepts_a_valid_token(): void {
		$calls = array();
		$ts    = new Turnstile( 's', $this->fake_http( $this->http_response( 200, '{"success":true}' ), $calls ) );
		$ts->on_checkout( new \WC_Order(), $this->request( 'bueno' ) );
		$this->assertCount( 1, $calls );
	}

	public function test_the_classic_checkout_is_validated_too(): void {
		$calls = array();
		$ts    = new Turnstile( 's', $this->fake_http( $this->http_response( 200, '{"success":false}' ), $calls ) );
		$errors = new \WP_Error();
		$_POST['aralia_turnstile_token'] = 'malo';
		$ts->on_classic_checkout( array(), $errors );
		$this->assertSame( array( 'aralia_turnstile_failed' ), $errors->get_error_codes() );

		unset( $_POST['aralia_turnstile_token'] );
		$errors = new \WP_Error();
		$ts->on_classic_checkout( array(), $errors );
		$this->assertSame( array( 'aralia_turnstile_failed' ), $errors->get_error_codes(), 'Sin token también falla' );
	}

	public function test_register_hooks_both_checkouts_and_registers_the_extension_right_away_if_blocks_already_loaded(): void {
		$this->assertGreaterThan( 0, did_action( 'woocommerce_blocks_loaded' ), 'WooCommerce Blocks ya se cargó antes de init' );
		$ts = new Turnstile( 's' );
		$this->registered[] = $ts;
		$ts->register();
		$this->assertNotFalse( has_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $ts, 'on_checkout' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_after_checkout_validation', array( $ts, 'on_classic_checkout' ) ) );
		$this->assertFalse( has_action( 'woocommerce_blocks_loaded', array( $ts, 'register_extension' ) ), 'No se espera a un hook que ya pasó' );
	}

	/**
	 * @dataProvider provide_non_post_methods
	 */
	public function test_updates_of_the_draft_order_do_not_require_or_consume_the_token( string $method ): void {
		$calls = array();
		$ts    = new Turnstile( 's', $this->fake_http( $this->http_response( 200, '{"success":false}' ), $calls ) );
		$ts->on_checkout( new \WC_Order(), $this->request( null, $method ) );
		$ts->on_checkout( new \WC_Order(), $this->request( 'token-valido', $method ) );
		$this->assertSame( array(), $calls, "$method no debe consultar a Cloudflare (gastaría el token de un solo uso antes del POST final)" );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_non_post_methods(): array {
		return array( 'PUT' => array( 'PUT' ), 'PATCH' => array( 'PATCH' ), 'GET' => array( 'GET' ) );
	}

	public function test_post_still_requires_the_token_after_a_patch(): void {
		$calls = array();
		$ts    = new Turnstile( 's', $this->fake_http( $this->http_response( 200, '{"success":false}' ), $calls ) );
		$ts->on_checkout( new \WC_Order(), $this->request( null, 'PATCH' ) );
		$this->expectException( RouteException::class );
		$ts->on_checkout( new \WC_Order(), $this->request( null, 'POST' ) );
	}

	/**
	 * @dataProvider provide_missing_claims
	 */
	public function test_in_strict_envs_a_success_without_action_or_hostname_is_not_trusted( string $body, string $code ): void {
		$calls = array();
		$res   = ( new Turnstile( 'secreto-real', $this->fake_http( $this->http_response( 200, $body ), $calls ) ) )->verify( 't', '', 'production' );
		$this->assertFalse( $res['success'] );
		$this->assertContains( $code, $res['codes'] );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function provide_missing_claims(): array {
		return array(
			'sin action'   => array( '{"success":true,"hostname":"x"}', 'action-missing' ),
			'sin hostname' => array( '{"success":true,"action":"checkout"}', 'hostname-missing' ),
		);
	}

	public function test_the_store_api_schema_declares_the_aralia_turnstile_field(): void {
		// Sin llamar a register_extension(): el esquema debe estar ya declarado por el registro real del plugin (Plugin::register_security).
		$extend = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema::class );
		$schema = json_decode( (string) wp_json_encode( $extend->get_endpoint_schema( \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER ) ), true );
		$this->assertArrayHasKey( 'aralia', $schema, 'extensions.aralia debe existir en el esquema del checkout' );
		$this->assertStringContainsString( 'turnstile_token', (string) wp_json_encode( $schema['aralia'] ), 'El campo turnstile_token debe estar en el esquema de extensions.aralia' );
	}

	public function test_the_client_ip_sent_to_cloudflare_comes_from_the_trusted_source_not_from_headers(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.44';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
		$_SERVER['HTTP_X_REAL_IP']       = '198.51.100.2';
		$calls = array();
		$ts    = new Turnstile( 's', $this->fake_http( $this->http_response( 200, '{"success":true}' ), $calls ) );
		$ts->on_checkout( new \WC_Order(), $this->request( 'ok' ) );
		$this->assertSame( '203.0.113.44', $calls[0]['args']['body']['remoteip'] );
	}
}
