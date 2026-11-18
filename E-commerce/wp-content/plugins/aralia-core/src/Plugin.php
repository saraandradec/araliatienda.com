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
		add_action( 'init', array( $this, 'register_security' ), 1 );
		add_action( 'cli_init', array( $this, 'register_cli' ) );
	}

	/**
	 * Engancha la seguridad base (cabeceras, auditoría, CSP, Turnstile, caché y alertas).
	 *
	 * @return void
	 */
	public function register_security(): void {
		\Aralia\Security\Headers::register();
		\Aralia\Security\Csp::register();
		\Aralia\Cache\PaymentPages::register();
		( new \Aralia\Cache\CacheInvalidator() )->register();
		( new \Aralia\Support\Maintenance() )->register();
		( new \Aralia\Security\Turnstile() )->register();

		// Las alertas se registran SIEMPRE (aunque falten las llaves de privacidad): una acción encolada sin manejador se marcaría como hecha.
		$limiter = new \Aralia\Security\RateLimiter();
		try {
			$crypto = \Aralia\Privacy\Crypto::from_constants();
		} catch ( \RuntimeException $e ) {
			// Sin llaves de privacidad no hay huellas ni SMS con tope: `wp aralia baseline check` lo marca (privacy_keys).
			unset( $e );
			$crypto = null;
		}
		// Las alertas de identidad (admin_role_change) se entregan ya, sin cola: quien tenga una sesión robada podría borrar la acción pendiente.
		$alerts = new \Aralia\Security\Alerts( $limiter, $crypto, new \Aralia\Notifications\BrevoClient( null, null, $limiter, $crypto ) );
		$alerts->register();
		( new \Aralia\Security\AuditLog( $crypto, $limiter, static fn( string $event, string $ref ) => $alerts->raise( $event, $ref, 'admin_role_change' === $event ) ) )->register_hooks();
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
		\WP_CLI::add_command( 'aralia baseline', \Aralia\Cli\BaselineCommand::class );
		// Herramientas de prueba: no se registran en producción ni sin ARALIA_ENV (defensa adicional a la comprobación del comando).
		if ( \Aralia\Support\Environment::allows_replay() ) {
			\WP_CLI::add_command( 'aralia wompi replay', \Aralia\Cli\WompiReplayCommand::class );
		}
	}
}
