<?php
/**
 * Arranque de Aralía Core y registro de servicios.
 *
 * @package Aralia
 */

namespace Aralia;

use Aralia\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Punto de entrada: registra servicios, migraciones y comandos WP-CLI.
 */
final class Plugin {

	/**
	 * Instancia única.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Fábricas de servicios (id => callable).
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Servicios ya construidos.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Evita arrancar dos veces.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Instancia única.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Solo para pruebas: descarta la instancia.
	 *
	 * @return void
	 */
	public static function reset_for_tests(): void {
		self::$instance = null;
	}

	/**
	 * Activación: crea o actualiza las tablas.
	 *
	 * @return void
	 */
	public static function activate(): void {
		( new Migrator() )->run();
	}

	/**
	 * Engancha todo. Idempotente.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// En una petición web no se espera el candado (si otro proceso migra, se sigue sin esperar); en WP-CLI se esperan hasta 10 s.
		$this->register( 'migrator', static fn(): Migrator => new Migrator( null, ( defined( 'WP_CLI' ) && WP_CLI ) ? 10 : 0 ) );

		// El despliegue copia archivos (no activa el plugin): las migraciones también se aplican al cargar.
		add_action( 'plugins_loaded', array( $this, 'maybe_migrate' ), 5 );
		add_action( 'cli_init', array( $this, 'register_cli' ) );
	}

	/**
	 * Registra un servicio perezoso.
	 *
	 * @param string   $id      Identificador.
	 * @param callable $factory Fábrica sin argumentos.
	 * @return void
	 */
	public function register( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->services[ $id ] );
	}

	/**
	 * Obtiene un servicio (se construye una sola vez).
	 *
	 * @param string $id Identificador.
	 * @return object
	 * @throws \InvalidArgumentException Si el servicio no existe.
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			if ( ! isset( $this->factories[ $id ] ) ) {
				throw new \InvalidArgumentException( 'Servicio no registrado: ' . esc_html( $id ) );
			}
			$this->services[ $id ] = ( $this->factories[ $id ] )();
		}
		return $this->services[ $id ];
	}

	/**
	 * Aplica las migraciones pendientes (barato: compara una opción).
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		/**
		 * Servicio de migraciones.
		 *
		 * @var Migrator $migrator
		 */
		$migrator = $this->get( 'migrator' );
		if ( $migrator->is_pending() ) {
			// run() atrapa los errores de migración: una migración defectuosa no debe tumbar todas las peticiones.
			$migrator->run();
		}
	}

	/**
	 * Registra los comandos `wp aralia ...`.
	 *
	 * @return void
	 */
	public function register_cli(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'aralia', \Aralia\Cli\RootCommand::class );
		\WP_CLI::add_command( 'aralia setup', \Aralia\Cli\SetupCommand::class );
		// Herramientas de prueba: no se registran en producción ni sin ARALIA_ENV (defensa adicional a la comprobación del comando).
		if ( \Aralia\Support\Environment::allows_replay() ) {
			\WP_CLI::add_command( 'aralia wompi replay', \Aralia\Cli\WompiReplayCommand::class );
		}
	}
}
