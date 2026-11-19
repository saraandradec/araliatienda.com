<?php
/**
 * T024: mu-plugin de endurecimiento.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Tests\Support\IsolatedTestCase;

final class HardeningTest extends IsolatedTestCase {

	private string $dir = '';

	protected function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/aralia-up-' . uniqid();
		mkdir( $this->dir );
	}

	protected function tear_down() {
		foreach ( (array) scandir( $this->dir ) as $f ) {
			if ( is_file( $this->dir . '/' . $f ) ) {
				unlink( $this->dir . '/' . $f );
			}
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	public function test_xmlrpc_is_disabled_everywhere(): void {
		$this->assertFalse( apply_filters( 'xmlrpc_enabled', true ) );
		$this->assertSame( array(), apply_filters( 'xmlrpc_methods', array( 'wp.getUsers' => 'x' ) ) );
		$this->assertFalse( apply_filters( 'pings_open', true, 0 ) );
		$this->assertArrayNotHasKey( 'X-Pingback', apply_filters( 'wp_headers', array( 'X-Pingback' => 'x' ) ) );
	}

	public function test_user_endpoints_are_hidden_from_visitors_but_not_from_user_listers(): void {
		$endpoints = array( '/wp/v2/users' => array(), '/wp/v2/users/(?P<id>[\d]+)' => array(), '/wp/v2/users/me' => array(), '/wp/v2/posts' => array() );
		$visitor   = aralia_hardening_filter_rest_endpoints( $endpoints, false );
		$this->assertSame( array( '/wp/v2/posts' ), array_keys( $visitor ) );
		$this->assertSame( array_keys( $endpoints ), array_keys( aralia_hardening_filter_rest_endpoints( $endpoints, true ) ) );
	}

	public function test_a_real_rest_request_for_users_is_not_available_to_anonymous_visitors(): void {
		wp_set_current_user( 0 );
		$server = rest_get_server();
		$routes = array_keys( $server->get_routes() );
		$this->assertCount( 0, array_filter( $routes, static fn( $r ) => 0 === strpos( $r, '/wp/v2/users' ) ) );
		$response = $server->dispatch( new \WP_REST_Request( 'GET', '/wp/v2/users' ) );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_login_errors_do_not_reveal_whether_the_user_exists(): void {
		$this->assertSame( 'Los datos de acceso no son correctos.', apply_filters( 'login_errors', 'Error: la contraseña del usuario admin es incorrecta.' ) );
	}

	public function test_uploads_htaccess_blocks_php_execution_and_is_idempotent(): void {
		$rules = aralia_hardening_uploads_htaccess_rules();
		$this->assertStringContainsString( 'php', $rules );
		$this->assertStringContainsString( 'Require all denied', $rules );
		$this->assertStringContainsString( 'phar', $rules );

		$this->assertTrue( aralia_hardening_ensure_uploads_htaccess( $this->dir ) );
		$this->assertSame( $rules, file_get_contents( $this->dir . '/.htaccess' ) );
		$mtime = filemtime( $this->dir . '/.htaccess' );
		$this->assertTrue( aralia_hardening_ensure_uploads_htaccess( $this->dir ) );
		$this->assertSame( $mtime, filemtime( $this->dir . '/.htaccess' ), 'Si ya está bien no se reescribe' );

		file_put_contents( $this->dir . '/.htaccess', "# alterado\n" );
		$this->assertTrue( aralia_hardening_ensure_uploads_htaccess( $this->dir ), 'Se corrige un archivo alterado' );
		$this->assertSame( $rules, file_get_contents( $this->dir . '/.htaccess' ) );
	}

	public function test_uploads_htaccess_fails_safely_for_bad_directories(): void {
		$this->assertFalse( aralia_hardening_ensure_uploads_htaccess( '' ) );
		$this->assertFalse( aralia_hardening_ensure_uploads_htaccess( $this->dir . '/no-existe' ) );
	}

	public function test_executable_extensions_never_make_it_into_the_allowed_mime_types(): void {
		$mimes    = array( 'jpg|jpeg' => 'image/jpeg', 'php|phtml' => 'application/x-php', 'sh' => 'text/x-sh', 'exe' => 'application/x-msdownload', 'csv' => 'text/csv' );
		$filtered = apply_filters( 'upload_mimes', $mimes );
		$this->assertSame( array( 'jpg|jpeg', 'csv' ), array_keys( $filtered ) );
	}

	/**
	 * @dataProvider provide_strict
	 */
	public function test_only_local_and_test_relax_the_rules( string $env, bool $strict ): void {
		$this->assertSame( $strict, aralia_hardening_is_strict( $env ), "env=$env" );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function provide_strict(): array {
		return array(
			'local'      => array( 'local', false ),
			'test'       => array( 'test', false ),
			'staging'    => array( 'staging', true ),
			'production' => array( 'production', true ),
			'vacío'      => array( '', true ),
			'prod'       => array( 'prod', true ),
			'Local'      => array( 'Local', true ),
		);
	}

	public function test_file_mods_are_never_disabled_by_this_plugin(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 3 ) . '/wp-content/mu-plugins/aralia-hardening.php' );
		$this->assertSame( 0, preg_match( '/define\s*\(\s*[\'"]DISALLOW_FILE_MODS[\'"]/', $code ), 'No debe definir DISALLOW_FILE_MODS con ninguna forma de comillas' );
		$this->assertSame( 1, preg_match( '/define\s*\(\s*[\'"]DISALLOW_FILE_EDIT[\'"]\s*,\s*true/', $code ), 'Sí define DISALLOW_FILE_EDIT' );
		$this->assertStringContainsString( 'aralia_hardening_is_strict()', $code, 'y solo fuera de local/test' );
	}

	public function test_application_passwords_are_disabled(): void {
		$this->assertFalse( wp_is_application_passwords_available() );
		$this->assertFalse( apply_filters( 'wp_is_application_passwords_available', true ) );
	}

	public function test_oembed_does_not_reveal_the_author(): void {
		$post_id = (int) wp_insert_post( array( 'post_title' => 'Para oEmbed', 'post_status' => 'publish', 'post_content' => 'x' ) );
		$data    = apply_filters( 'oembed_response_data', array( 'title' => 'x', 'author_name' => 'saraadmin', 'author_url' => 'https://t.example/author/saraadmin/' ), get_post( $post_id ), 600, 400 );
		wp_delete_post( $post_id, true );
		$this->assertArrayNotHasKey( 'author_name', $data );
		$this->assertArrayNotHasKey( 'author_url', $data );
		$this->assertSame( 'x', $data['title'] );
	}

	/**
	 * @dataProvider provide_author_requests
	 * @param array<string,string> $query GET.
	 */
	public function test_author_requests_are_detected( bool $is_author, array $query, bool $is_admin, bool $expected ): void {
		$this->assertSame( $expected, aralia_hardening_is_author_request( $is_author, $query, $is_admin ) );
	}

	/**
	 * @return array<string,array{bool,array<string,string>,bool,bool}>
	 */
	public static function provide_author_requests(): array {
		return array(
			'archivo de autor'     => array( true, array(), false, true ),
			'?author=1'            => array( false, array( 'author' => '1' ), false, true ),
			'?author= vacío'       => array( false, array( 'author' => '' ), false, true ),
			'página normal'        => array( false, array(), false, false ),
			'otro parámetro'       => array( false, array( 'autor' => '1' ), false, false ),
			'panel con ?author='   => array( false, array( 'author' => '1' ), true, false ),
		);
	}

	public function test_the_author_handler_is_registered_with_priority_1_before_redirect_canonical(): void {
		global $wp_filter;
		$this->assertNotEmpty( $wp_filter['template_redirect']->callbacks[1] ?? array(), 'Prioridad 1: antes de redirect_canonical (que revelaría /author/<login>/)' );
		// El comportamiento HTTP real (404 y sin redirección) se verifica en staging (T-ENUM, pruebas previas a la apertura).
	}

	public function test_the_user_sitemap_provider_is_removed(): void {
		$this->assertFalse( apply_filters( 'wp_sitemaps_add_provider', new \stdClass(), 'users' ) );
		$provider = new \stdClass();
		$this->assertSame( $provider, apply_filters( 'wp_sitemaps_add_provider', $provider, 'posts' ) );
	}
}
