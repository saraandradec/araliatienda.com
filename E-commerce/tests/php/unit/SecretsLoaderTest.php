<?php
/**
 * Pruebas del cargador de secretos (research R-16). Incluye casos de abuso.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SecretsLoaderTest extends TestCase {

	/** @var string */
	private $root;

	/** @var string */
	private $webroot;

	/** @var string */
	private $private_dir;

	protected function setUp(): void {
		$this->root        = sys_get_temp_dir() . '/aralia-secrets-' . bin2hex( random_bytes( 6 ) );
		$this->webroot     = $this->root . '/public_html';
		$this->private_dir = $this->root . '/private';
		mkdir( $this->webroot, 0700, true );
		mkdir( $this->private_dir, 0700, true );

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', $this->webroot . '/' );
		}
		// Carga solo las funciones: sin ARALIA_SECRETS_PATH el archivo no incluye nada.
		require_once dirname( __DIR__, 3 ) . '/wp-content/mu-plugins/aralia-secrets-loader.php';
	}

	protected function tearDown(): void {
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $this->root );
	}

	private function secrets_file( string $dir, int $mode = 0600 ): string {
		$path = $dir . '/aralia-secrets.php';
		file_put_contents( $path, "<?php\ndefined( 'ABSPATH' ) || exit;\ndefine( 'ARALIA_TEST_SECRET_LOADED', true );\n" );
		chmod( $path, $mode );
		return $path;
	}

	public function test_without_constant_loads_nothing(): void {
		// Al incluirse sin ARALIA_SECRETS_PATH, el mu-plugin registra el motivo y no carga nada.
		$this->assertSame( 'missing-constant', aralia_secrets_status() );
		$this->assertSame( 'missing-constant', aralia_load_secrets( '', ABSPATH, 'production' ) );
		$this->assertFalse( defined( 'ARALIA_TEST_SECRET_LOADED' ) );
	}

	public function test_loads_file_outside_webroot_with_600(): void {
		$path = $this->secrets_file( $this->private_dir );
		$this->assertSame( 'loaded', aralia_load_secrets( $path, ABSPATH, 'production' ) );
		$this->assertTrue( defined( 'ARALIA_TEST_SECRET_LOADED' ) );
	}

	public function test_refuses_file_inside_webroot_on_server(): void {
		$path = $this->secrets_file( $this->webroot );
		$this->assertSame( 'inside-webroot', aralia_load_secrets( $path, ABSPATH, 'production' ) );
		$this->assertSame( 'inside-webroot', aralia_load_secrets( $path, ABSPATH, 'staging' ) );
		$this->assertFalse( defined( 'ARALIA_TEST_SECRET_LOADED' ) );
	}

	public function test_refuses_webroot_bypass_with_dot_dot_segments(): void {
		mkdir( $this->webroot . '/wp-content', 0700 );
		$this->secrets_file( $this->webroot );
		$tricky = $this->private_dir . '/../public_html/wp-content/../aralia-secrets.php';
		$this->assertSame( 'inside-webroot', aralia_load_secrets( $tricky, ABSPATH, 'production' ) );
	}

	public function test_refuses_world_readable_file_on_server(): void {
		$path = $this->secrets_file( $this->private_dir, 0644 );
		$this->assertSame( 'insecure-permissions', aralia_load_secrets( $path, ABSPATH, 'production' ) );
		$this->assertFalse( defined( 'ARALIA_TEST_SECRET_LOADED' ) );
	}

	/**
	 * El hosting compartido puede tener grupos con varios usuarios: 640 y 660 tampoco valen (solo 600 o 400).
	 *
	 * @dataProvider group_readable_modes
	 */
	public function test_refuses_group_accessible_file_on_server( int $mode ): void {
		$path = $this->secrets_file( $this->private_dir, $mode );
		$this->assertSame( 'insecure-permissions', aralia_load_secrets( $path, ABSPATH, 'production' ) );
		$this->assertFalse( defined( 'ARALIA_TEST_SECRET_LOADED' ) );
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function group_readable_modes(): array {
		return array(
			'640' => array( 0640 ),
			'660' => array( 0660 ),
			'644' => array( 0644 ),
		);
	}

	public function test_accepts_400_on_server(): void {
		$path = $this->secrets_file( $this->private_dir, 0400 );
		$this->assertSame( 'loaded', aralia_load_secrets( $path, ABSPATH, 'production' ) );
	}

	public function test_refuses_directory_named_like_php(): void {
		mkdir( $this->private_dir . '/aralia-secrets.php', 0700 );
		$this->assertSame( 'unreadable', aralia_load_secrets( $this->private_dir . '/aralia-secrets.php', ABSPATH, 'production' ) );
	}

	/**
	 * Un valor desconocido de ARALIA_ENV (typo, mayúsculas, vacío) NO debe relajar los controles.
	 *
	 * @dataProvider unknown_envs
	 */
	public function test_unknown_env_is_treated_as_server( string $env ): void {
		$inside = $this->secrets_file( $this->webroot, 0600 );
		$this->assertSame( 'inside-webroot', aralia_load_secrets( $inside, ABSPATH, $env ) );
		$open = $this->secrets_file( $this->private_dir, 0644 );
		$this->assertSame( 'insecure-permissions', aralia_load_secrets( $open, ABSPATH, $env ) );
		$this->assertFalse( defined( 'ARALIA_TEST_SECRET_LOADED' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unknown_envs(): array {
		return array(
			'prod'       => array( 'prod' ),
			'Production' => array( 'Production' ),
			'live'       => array( 'live' ),
			'empty'      => array( '' ),
		);
	}

	public function test_symlink_to_non_php_target_is_refused(): void {
		file_put_contents( $this->private_dir . '/data.txt', '<?php define( "ARALIA_TEST_SECRET_LOADED", true );' );
		chmod( $this->private_dir . '/data.txt', 0600 );
		symlink( $this->private_dir . '/data.txt', $this->private_dir . '/link.php' );
		$this->assertSame( 'not-a-php-file', aralia_load_secrets( $this->private_dir . '/link.php', ABSPATH, 'production' ) );
		$this->assertFalse( defined( 'ARALIA_TEST_SECRET_LOADED' ) );
	}

	public function test_local_env_allows_mounted_file(): void {
		$path = $this->secrets_file( $this->webroot, 0644 );
		$this->assertSame( 'loaded', aralia_load_secrets( $path, ABSPATH, 'local' ) );
	}

	/**
	 * @dataProvider hostile_paths
	 */
	public function test_rejects_hostile_paths( string $path, string $expected ): void {
		$this->assertSame( $expected, aralia_load_secrets( $path, ABSPATH, 'production' ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function hostile_paths(): array {
		return array(
			'stream wrapper php://' => array( 'php://filter/resource=/etc/passwd.php', 'not-a-php-file' ),
			'phar'                  => array( 'phar:///tmp/x.phar/aralia-secrets.php', 'not-a-php-file' ),
			'remote http'           => array( 'http://example.com/aralia-secrets.php', 'not-a-php-file' ),
			'not php'               => array( '/etc/passwd', 'not-a-php-file' ),
			'missing file'          => array( '/nonexistent/aralia-secrets.php', 'unreadable' ),
		);
	}
}
