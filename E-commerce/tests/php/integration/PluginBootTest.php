<?php
/**
 * T020: arranque, autoloader y servicios de Aralía Core.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Plugin;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class PluginBootTest extends TestCase {

	public function test_plugin_is_loaded_with_constants(): void {
		$this->assertTrue( defined( 'ARALIA_CORE_VERSION' ) );
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_maybe_migrate_applies_pending_migrations_and_is_a_noop_otherwise(): void {
		$this->assertNotFalse( has_action( 'plugins_loaded', array( Plugin::instance(), 'maybe_migrate' ) ) );
		$migrator = new \Aralia\Database\Migrator();
		delete_option( \Aralia\Database\Migrator::OPTION_VERSION );
		$this->assertTrue( $migrator->is_pending() );

		Plugin::instance()->maybe_migrate();
		$this->assertFalse( $migrator->is_pending() );
		$this->assertSame( 1, $migrator->current_version() );

		// Sin pendientes no debe ni intentar migrar: un migrador falso cuenta las llamadas a run().
		$fake = new class() extends \Aralia\Database\Migrator {
			/** @var int */
			public int $runs = 0;

			public function is_pending(): bool {
				return false;
			}

			public function run(): array {
				++$this->runs;
				return parent::run();
			}
		};
		Plugin::instance()->register( 'migrator', static fn() => $fake );
		try {
			Plugin::instance()->maybe_migrate();
			$this->assertSame( 0, $fake->runs, 'Sin pendientes, maybe_migrate no llama a run()' );
		} finally {
			Plugin::instance()->register( 'migrator', static fn() => new \Aralia\Database\Migrator() );
		}
	}

	public function test_services_are_lazy_singletons(): void {
		$plugin = Plugin::instance();
		$calls  = 0;
		$plugin->register(
			'probe',
			static function () use ( &$calls ) {
				++$calls;
				return new \stdClass();
			}
		);
		$this->assertSame( 0, $calls );
		$this->assertSame( $plugin->get( 'probe' ), $plugin->get( 'probe' ) );
		$this->assertSame( 1, $calls );
	}

	public function test_unknown_service_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		Plugin::instance()->get( 'no-existe' );
	}

	/**
	 * @dataProvider provide_hostile_class_names
	 */
	public function test_autoloader_never_includes_files_outside_src( string $name ): void {
		// Archivo señuelo que, si el autoloader lo incluyera, definiría esta constante.
		$decoy = ARALIA_CORE_DIR . 'Decoy.php';
		file_put_contents( $decoy, "<?php\ndefine( 'ARALIA_DECOY_LOADED', true );\n" );
		try {
			$this->assertFalse( class_exists( $name, true ), "No debe resolver $name" );
			$this->assertFalse( defined( 'ARALIA_DECOY_LOADED' ), "El autoloader incluyó un archivo fuera de src/ con $name" );
		} finally {
			unlink( $decoy );
		}
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_hostile_class_names(): array {
		return array(
			// Estos tres resolverían a aralia-core/Decoy.php (el señuelo) si el autoloader no validara el nombre.
			'traversal'       => array( 'Aralia\\Cli\\..\\..\\Decoy' ),
			'traversal-short' => array( 'Aralia\\..\\Decoy' ),
			'slashes'         => array( 'Aralia\\Cli/../../Decoy' ),
			// Estos se rechazan por otras reglas (prefijo, NUL, puntos, cola vacía); no usan el señuelo.
			'nul'             => array( "Aralia\\..\\Decoy\0" ),
			'other-prefix'    => array( 'Evil\\Decoy' ),
			'dots'            => array( 'Aralia\\..Decoy' ),
			'empty-tail'      => array( 'Aralia\\' ),
		);
	}

	public function test_command_classes_load_through_the_autoloader_and_register_on_cli_init(): void {
		$this->assertTrue( class_exists( \Aralia\Cli\SetupCommand::class ) );
		$this->assertTrue( class_exists( \Aralia\Cli\WompiReplayCommand::class ) );
		$this->assertNotFalse( has_action( 'cli_init', array( Plugin::instance(), 'register_cli' ) ), 'Los comandos se registran en cli_init' );
	}
}
