<?php
/**
 * T032: verificación de la línea base.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Security\Baseline;
use Aralia\Security\Csp;
use Aralia\Setup\StoreConfig;
use Aralia\Tests\Support\SecurityTestCase;

final class BaselineTest extends SecurityTestCase {

	/**
	 * @var int[]
	 */
	private array $pages = array();

	/**
	 * @var int[]
	 */
	private array $users = array();

	/**
	 * @var array<string,mixed>
	 */
	private array $saved = array();

	protected function set_up() {
		parent::set_up();
		foreach ( array_keys( StoreConfig::expected() ) as $option ) {
			$this->saved[ $option ] = get_option( $option, null );
		}
		foreach ( array( 'woocommerce_checkout_page_id', 'default_role', Csp::OPTION_MODE ) as $option ) {
			$this->saved[ $option ] = get_option( $option, null );
		}
		// Si ya existe un "admin" (p. ej. el de wp-env) se guarda y se quita para la prueba; se restaura al terminar.
		$existing = get_user_by( 'login', 'admin' );
		$this->saved['__admin_login'] = $existing ? $existing->user_login : null;
		if ( $existing ) {
			global $wpdb;
			$wpdb->update( $wpdb->users, array( 'user_login' => 'admin-renombrado-por-la-prueba' ), array( 'ID' => $existing->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_user_cache( $existing->ID );
			$this->saved['__admin_id'] = $existing->ID;
		}
	}

	protected function tear_down() {
		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}
		foreach ( $this->pages as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->saved as $option => $value ) {
			if ( 0 === strpos( (string) $option, '__' ) ) {
				continue;
			}
			null === $value ? delete_option( $option ) : update_option( $option, $value );
		}
		if ( ! empty( $this->saved['__admin_id'] ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->users, array( 'user_login' => (string) $this->saved['__admin_login'] ), array( 'ID' => (int) $this->saved['__admin_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_user_cache( (int) $this->saved['__admin_id'] );
		}
		parent::tear_down();
	}

	/**
	 * Respuesta real simulada con todas las cabeceras buenas.
	 *
	 * @return array<string,string>
	 */
	private static function good_headers(): array {
		return array(
			'X-Content-Type-Options'    => 'nosniff',
			'Referrer-Policy'           => 'strict-origin-when-cross-origin',
			'Permissions-Policy'        => 'camera=()',
			'Strict-Transport-Security' => 'max-age=31536000',
			'Content-Security-Policy'   => "frame-ancestors 'self'",
		);
	}

	/**
	 * @param array<string,string>|null $headers Cabeceras reales simuladas.
	 * @param array<string,mixed> $overrides Valores simulados (ver Baseline::run).
	 * @return array<string,array{id:string,ok:bool,message:string}>
	 */
	private function results( string $env = 'test', ?array $headers = null, ?string $host = null, array $overrides = array() ): array {
		$out = array();
		foreach ( ( new Baseline() )->run( $env, static fn() => $headers ?? self::good_headers(), $host, $overrides ) as $r ) {
			$out[ $r['id'] ] = $r;
		}
		return $out;
	}

	private function configure_store(): void {
		$id            = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Checkout', 'post_content' => '[woocommerce_checkout]' ) );
		$this->pages[] = $id;
		update_option( 'woocommerce_checkout_page_id', $id );
		StoreConfig::apply();
	}

	public function test_the_check_ids_are_stable(): void {
		$ids = array_keys( $this->results() );
		foreach ( array( 'env_defined', 'env_matches_host', 'no_admin_user', 'file_edit_disabled', 'file_mods_allowed', 'xmlrpc_disabled', 'app_passwords_disabled', 'users_endpoint_hidden', 'security_headers', 'headers_registered', 'headers_real', 'hsts_https', 'csp_mode', 'uploads_no_php', 'debug_off', 'secrets_loaded', 'privacy_keys', 'turnstile_secret', 'brevo_key', 'alert_destinations', 'store_config', 'db_current', 'wordfence_active' ) as $id ) {
			$this->assertContains( $id, $ids );
		}
	}

	public function test_a_configured_test_site_passes_the_checks_that_apply_to_it(): void {
		$this->configure_store();
		( new \Aralia\Database\Migrator() )->run();
		$r = $this->results( 'test' );
		foreach ( array( 'env_defined', 'env_matches_host', 'no_admin_user', 'file_mods_allowed', 'xmlrpc_disabled', 'app_passwords_disabled', 'users_endpoint_hidden', 'security_headers', 'headers_registered', 'csp_mode', 'privacy_keys', 'store_config', 'db_current' ) as $id ) {
			$this->assertTrue( $r[ $id ]['ok'], "$id: " . $r[ $id ]['message'] );
		}
		foreach ( array( 'file_edit_disabled', 'headers_real', 'hsts_https', 'uploads_no_php', 'debug_off', 'turnstile_secret', 'brevo_key', 'alert_destinations', 'wordfence_active' ) as $skipped ) {
			$this->assertStringContainsString( 'omitido', $r[ $skipped ]['message'], "$skipped se omite en test" );
		}
	}

	public function test_an_admin_user_is_detected(): void {
		$this->assertTrue( $this->results( 'test', null, null, array( 'admin_user_exists' => false ) )['no_admin_user']['ok'] );
		$this->assertFalse( $this->results( 'test', null, null, array( 'admin_user_exists' => true ) )['no_admin_user']['ok'] );
		// Y el camino real, con un usuario "admin" creado por la prueba (el de wp-env, si existe, se renombra en set_up).
		$id = wp_insert_user( array( 'user_login' => 'admin', 'user_pass' => wp_generate_password(), 'user_email' => 'admin-baseline@example.com' ) );
		$this->assertIsInt( $id, 'La prueba debe haber podido crear el usuario' );
		$this->users[] = $id;
		$this->assertFalse( $this->results()['no_admin_user']['ok'] );
	}

	public function test_store_config_drift_is_detected_and_named(): void {
		$this->configure_store();
		update_option( 'default_role', 'administrator' );
		$r = $this->results()['store_config'];
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'default_role', $r['message'] );
	}

	public function test_a_pending_migration_or_recorded_error_fails_db_current(): void {
		delete_option( \Aralia\Database\Migrator::OPTION_VERSION );
		$this->assertFalse( $this->results()['db_current']['ok'] );
		( new \Aralia\Database\Migrator() )->run();
		update_option( \Aralia\Database\Migrator::OPTION_ERROR, 'algo falló' );
		$this->assertFalse( $this->results()['db_current']['ok'] );
		delete_option( \Aralia\Database\Migrator::OPTION_ERROR );
	}

	/**
	 * Valores que hacen pasar todo en producción; cada prueba rompe UNO y comprueba que ese check (y solo ese) falla.
	 *
	 * @return array<string,mixed>
	 */
	private static function prod_ok(): array {
		$uploads = sys_get_temp_dir() . '/aralia-uploads-ok';
		if ( ! is_dir( $uploads ) ) {
			mkdir( $uploads );
		}
		file_put_contents( $uploads . '/.htaccess', aralia_hardening_uploads_htaccess_rules() );
		return array(
			'admin_user_exists'       => false,
			'file_edit'               => true,
			'uploads_dir'             => $uploads,
			'turnstile_secret'        => 'secreto-real-no-de-prueba',
			'brevo_key'               => 'llave',
			'wordfence_active'        => true,
			'debug'                   => false,
			'trusted_proxies_defined' => true,
			'alert_email'             => 'sara@example.com',
			'alert_phone'             => '3001234567',
		);
	}

	public function test_in_production_each_setting_is_checked_and_failing_one_fails_only_that_check(): void {
		$base = $this->results( 'production', null, 'tienda.example.com', self::prod_ok() );
		foreach ( array( 'no_admin_user', 'file_edit_disabled', 'uploads_no_php', 'debug_off', 'turnstile_secret', 'brevo_key', 'alert_destinations', 'trusted_proxies', 'wordfence_active', 'headers_real' ) as $id ) {
			$this->assertTrue( $base[ $id ]['ok'], "Punto de partida de producción: $id debe pasar (" . $base[ $id ]['message'] . ')' );
		}
		$breaks = array(
			'no_admin_user'      => array( 'admin_user_exists' => true ),
			'file_edit_disabled' => array( 'file_edit' => false ),
			'uploads_no_php'     => array( 'uploads_dir' => '/no/existe' ),
			'debug_off'          => array( 'debug' => true ),
			'turnstile_secret'   => array( 'turnstile_secret' => '' ),
			'brevo_key'          => array( 'brevo_key' => '' ),
			'alert_destinations' => array( 'alert_email' => '' ),
			'trusted_proxies'    => array( 'trusted_proxies_defined' => false ),
			'trusted_proxies '   => array( 'trusted_proxies_dropped' => true ),
			'wordfence_active'   => array( 'wordfence_active' => false ),
		);
		foreach ( $breaks as $id => $change ) {
			$id = trim( $id );
			$r  = $this->results( 'production', null, 'tienda.example.com', array_merge( self::prod_ok(), $change ) );
			$this->assertFalse( $r[ $id ]['ok'], "$id debe fallar con " . json_encode( $change ) );
			$this->assertStringNotContainsString( 'omitido', $r[ $id ]['message'], "$id no se omite en producción" );
		}
	}

	public function test_the_turnstile_test_secrets_do_not_pass_in_production(): void {
		foreach ( \Aralia\Security\Turnstile::DUMMY_SECRETS as $dummy ) {
			$this->assertFalse( $this->results( 'production', null, 'tienda.example.com', array_merge( self::prod_ok(), array( 'turnstile_secret' => $dummy ) ) )['turnstile_secret']['ok'], $dummy );
		}
	}

	/**
	 * @dataProvider provide_bad_alert_phones
	 */
	public function test_an_alert_phone_that_brevo_would_silently_drop_fails_the_baseline( string $phone ): void {
		$r = $this->results( 'production', null, 'tienda.example.com', array_merge( self::prod_ok(), array( 'alert_phone' => $phone ) ) );
		$this->assertFalse( $r['alert_destinations']['ok'], "'$phone' no es un móvil colombiano: las alertas críticas no llegarían por SMS" );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_bad_alert_phones(): array {
		return array(
			'vacío'           => array( '' ),
			'estadounidense'  => array( '+1 415 555 0100' ),
			'fijo'            => array( '2001234567' ),
			'texto'           => array( 'mi celular' ),
		);
	}

	public function test_a_malformed_alert_email_fails(): void {
		$r = $this->results( 'production', null, 'tienda.example.com', array_merge( self::prod_ok(), array( 'alert_email' => 'no-es-correo' ) ) );
		$this->assertFalse( $r['alert_destinations']['ok'] );
	}

	public function test_unknown_or_misspelled_environments_are_strict_and_not_valid(): void {
		foreach ( array( '', 'prod', 'Production', 'live' ) as $env ) {
			$this->assertTrue( Baseline::is_strict( $env ), "'$env' es estricto" );
			$this->assertFalse( $this->results( $env, null, 'tienda.example.com' )['env_defined']['ok'], "'$env' no es un entorno válido" );
		}
		$this->assertFalse( Baseline::is_strict( 'local' ) );
		$this->assertFalse( Baseline::is_strict( 'test' ) );
		$this->assertTrue( $this->results( 'staging', null, 'staging.example.com' )['env_defined']['ok'] );
	}

	public function test_a_dev_environment_value_on_a_real_host_fails(): void {
		$this->assertFalse( $this->results( 'test', null, 'aralia.example.com' )['env_matches_host']['ok'], 'ARALIA_ENV=test en un dominio real relaja el endurecimiento y habilita el salto de Turnstile' );
		$this->assertFalse( $this->results( 'local', null, 'www.tienda.com' )['env_matches_host']['ok'] );
		foreach ( array( 'localhost', '127.0.0.1', 'tienda.local', 'sitio.test', 'wordpress', 'tests-wordpress' ) as $host ) {
			$this->assertTrue( $this->results( 'test', null, $host )['env_matches_host']['ok'], $host );
		}
		$this->assertTrue( $this->results( 'production', null, 'aralia.example.com' )['env_matches_host']['ok'], 'Producción en un host real es lo normal' );
	}

	public function test_the_real_response_headers_are_what_is_checked_in_strict_environments(): void {
		$this->assertTrue( $this->results( 'production', self::good_headers(), 'tienda.example.com' )['headers_real']['ok'] );

		foreach ( array( 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'Strict-Transport-Security' ) as $missing ) {
			$headers = self::good_headers();
			unset( $headers[ $missing ] );
			$r = $this->results( 'production', $headers, 'tienda.example.com' )['headers_real'];
			$this->assertFalse( $r['ok'], "Sin $missing en la respuesta real" );
			$this->assertStringContainsString( strtolower( $missing ), $r['message'] );
		}
		$no_frame = self::good_headers();
		$no_frame['Content-Security-Policy'] = "default-src 'self'";
		$this->assertFalse( $this->results( 'production', $no_frame, 'tienda.example.com' )['headers_real']['ok'], 'Sin frame-ancestors ni X-Frame-Options' );
		$with_xfo                            = $no_frame;
		$with_xfo['X-Frame-Options']         = 'SAMEORIGIN';
		$this->assertTrue( $this->results( 'production', $with_xfo, 'tienda.example.com' )['headers_real']['ok'] );
		$this->assertFalse( $this->results( 'production', array(), 'tienda.example.com' )['headers_real']['ok'], 'Si no se pudo leer la respuesta, falla' );
		$this->assertFalse( $this->results( 'production', array( 'x-content-type-options' => '' ), 'tienda.example.com' )['headers_real']['ok'], 'Cabecera vacía no cuenta' );
	}

	public function test_csp_off_fails_in_strict_environments(): void {
		update_option( Csp::OPTION_MODE, 'off' );
		$this->assertFalse( $this->results( 'production', null, 'tienda.example.com' )['csp_mode']['ok'] );
		update_option( Csp::OPTION_MODE, 'report-only' );
		$this->assertTrue( $this->results( 'production', null, 'tienda.example.com' )['csp_mode']['ok'], 'report-only es válido mientras se hace el inventario' );
	}

	public function test_enforce_is_required_only_from_the_go_live_so_deploys_are_not_blocked_during_the_inventory(): void {
		update_option( Csp::OPTION_MODE, 'report-only' );
		$before = $this->results( 'production', null, 'tienda.example.com', array( 'go_live' => false ) )['csp_enforce'];
		$this->assertTrue( $before['ok'], 'Antes del go-live, report-only no rompe la línea base (deploy.sh la exige en verde)' );
		$this->assertStringContainsString( 'omitido', $before['message'] );

		$after = $this->results( 'production', null, 'tienda.example.com', array( 'go_live' => true ) )['csp_enforce'];
		$this->assertFalse( $after['ok'], 'Desde el go-live, report-only en producción falla' );

		update_option( Csp::OPTION_MODE, 'enforce' );
		$this->assertTrue( $this->results( 'production', null, 'tienda.example.com', array( 'go_live' => true ) )['csp_enforce']['ok'] );
		$this->assertTrue( $this->results( 'staging', null, 'staging.example.com', array( 'go_live' => true ) )['csp_enforce']['ok'], 'staging nunca lo exige' );
	}

	public function test_application_passwords_must_be_disabled(): void {
		$this->assertTrue( $this->results()['app_passwords_disabled']['ok'] );
		add_filter( 'wp_is_application_passwords_available', '__return_true', 99 );
		$this->assertFalse( $this->results()['app_passwords_disabled']['ok'], 'Si algo las reactiva, la línea base falla' );
		remove_filter( 'wp_is_application_passwords_available', '__return_true', 99 );
	}

	public function test_headers_must_be_registered(): void {
		$this->assertTrue( $this->results()['headers_registered']['ok'] );
		remove_filter( 'wp_headers', array( \Aralia\Security\Headers::class, 'filter_headers' ), 5 );
		$this->assertFalse( $this->results()['headers_registered']['ok'] );
		\Aralia\Security\Headers::register();
	}

	public function test_the_csp_hash_cap_is_documented_in_the_baseline_via_the_csp_mode_check(): void {
		$this->assertArrayHasKey( 'csp_enforce', $this->results() );
		$this->assertStringContainsString( 'omitido', $this->results( 'test' )['csp_enforce']['message'] );
	}
}
