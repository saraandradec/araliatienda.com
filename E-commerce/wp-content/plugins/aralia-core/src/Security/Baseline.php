<?php
/**
 * Verificación de la línea base de seguridad (T032).
 *
 * @package Aralia
 */

namespace Aralia\Security;

use Aralia\Database\Migrator;
use Aralia\Privacy\Crypto;
use Aralia\Setup\StoreConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Devuelve una lista de comprobaciones `{id, ok, message}`. En local y test las reglas de producción se relajan y se marcan como omitidas.
 * Se ejecuta con `wp aralia baseline check` tras cada despliegue (ops/deploy.sh) y falla si cualquiera no cumple.
 */
class Baseline {

	/**
	 * Entorno estricto: todo lo que no sea local o test.
	 *
	 * @param string $env Entorno.
	 * @return bool
	 */
	public static function is_strict( string $env ): bool {
		return ! in_array( $env, array( 'local', 'test' ), true );
	}

	/**
	 * Ejecuta las comprobaciones.
	 *
	 * @param string        $env           Entorno (ARALIA_ENV).
	 * @param callable|null $fetch_headers Devuelve las cabeceras de la respuesta real de la home (por defecto, una petición HTTP).
	 * @param string|null   $home_host     Host del sitio (por defecto, el de home_url()).
	 * @param array<string,mixed> $overrides Valores simulados para pruebas deterministas: admin_user_exists, file_edit, uploads_dir, turnstile_secret, brevo_key, wordfence_active, debug, trusted_proxies_defined, trusted_proxies_dropped, go_live, alert_email, alert_phone.
	 * @return array<int,array{id:string,ok:bool,message:string}>
	 */
	public function run( string $env, ?callable $fetch_headers = null, ?string $home_host = null, array $overrides = array() ): array {
		$strict = self::is_strict( $env );
		$out    = array();
		$add    = static function ( string $id, bool $ok, string $message ) use ( &$out ): void {
			$out[] = array(
				'id'      => $id,
				'ok'      => $ok,
				'message' => $message,
			);
		};

		$add( 'env_defined', in_array( $env, array( 'local', 'test', 'staging', 'production' ), true ), 'ARALIA_ENV=' . ( '' === $env ? '(sin definir)' : $env ) );
		$add( 'no_admin_user', ! ( $overrides['admin_user_exists'] ?? ( false !== get_user_by( 'login', 'admin' ) ) ), 'No existe el usuario "admin"' );

		$file_edit = (bool) ( $overrides['file_edit'] ?? ( defined( 'DISALLOW_FILE_EDIT' ) && constant( 'DISALLOW_FILE_EDIT' ) ) );
		$add( 'file_edit_disabled', ! $strict || $file_edit, $strict ? 'DISALLOW_FILE_EDIT activo' : 'omitido en ' . $env );
		$add( 'file_mods_allowed', ! defined( 'DISALLOW_FILE_MODS' ) || ! constant( 'DISALLOW_FILE_MODS' ), 'DISALLOW_FILE_MODS no está activo (las actualizaciones se hacen desde wp-admin)' );

		$add(
			'xmlrpc_disabled',
			false === apply_filters( 'xmlrpc_enabled', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de WordPress.
			'XML-RPC desactivado'
		);

		$prev = get_current_user_id();
		wp_set_current_user( 0 );
		$routes = array_keys( rest_get_server()->get_routes() );
		wp_set_current_user( $prev );
		$leaks = array_filter( $routes, static fn( $r ): bool => 0 === strpos( (string) $r, '/wp/v2/users' ) );
		$add( 'users_endpoint_hidden', ! $leaks, '/wp/v2/users no se expone a visitantes' );

		// ARALIA_ENV=local/test relaja el endurecimiento y habilita atajos de prueba: no puede estar en un sitio que no sea de desarrollo.
		$host     = strtolower( $home_host ?? (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$dev_host = in_array( $host, \Aralia\Wompi\ReplayBuilder::ALLOWED_HOSTS, true ) || (bool) preg_match( '/^127\.\d+\.\d+\.\d+$/', $host ) || (bool) preg_match( '/\.(local|test|localhost)$/', $host );
		$add( 'env_matches_host', ! in_array( $env, array( 'local', 'test' ), true ) || $dev_host, "ARALIA_ENV=$env en el host $host" );

		$add( 'app_passwords_disabled', false === apply_filters( 'wp_is_application_passwords_available', true ), 'Contraseñas de aplicación desactivadas' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de WordPress.

		$mode = Csp::mode();
		$add( 'csp_mode', ! $strict || 'off' !== $mode, 'CSP en modo ' . $mode );
		// La CSP debe estar en enforce SOLO desde el go-live (constante ARALIA_GO_LIVE, T160): antes, producción corre en report-only durante el
		// inventario (T150) y esta comprobación en rojo impediría registrar cualquier despliegue (deploy.sh exige la línea base en verde).
		$go_live = (bool) ( $overrides['go_live'] ?? ( defined( 'ARALIA_GO_LIVE' ) && constant( 'ARALIA_GO_LIVE' ) ) );
		$add( 'csp_enforce', 'production' !== $env || ! $go_live || 'enforce' === $mode, ( 'production' === $env && $go_live ) ? 'CSP en enforce (go-live)' : 'omitido hasta el go-live (ARALIA_GO_LIVE, T160)' );

		$provisional = (bool) ( $overrides['provisional_shipping'] ?? ( '1' === get_option( 'aralia_provisional_shipping' ) ) );
		$add( 'provisional_shipping', ! $strict || ! $provisional, $strict ? 'el envío provisional de prueba no debe estar activo en ' . $env : 'omitido en ' . $env );
		// T-KEYS / SC-015: ninguna llave de Wompi guardada en la base de datos (el guard las toma de constantes y las vacía al guardar).
		$keys_in_db = (array) ( $overrides['wompi_keys_in_db'] ?? ( class_exists( '\\Aralia\\Guard\\Keys' ) ? \Aralia\Guard\Keys::stored_key_fields() : array() ) );
		$add( 'wompi_keys_in_db', array() === $keys_in_db, array() === $keys_in_db ? 'sin llaves de Wompi en la base de datos' : 'llaves de Wompi guardadas en la base de datos: ' . implode( ', ', $keys_in_db ) . ' (se vacían al entrar a wp-admin; rotarlas)' );
		// Detrás de un balanceador o CDN, sin proxies de confianza declarados todas las clientas comparten una IP (los límites por IP serían globales).
		$proxies_defined = (bool) ( $overrides['trusted_proxies_defined'] ?? defined( 'ARALIA_TRUSTED_PROXIES' ) );
		// Una entrada descartada (máscara demasiado amplia o ilegible) deja a las clientas con la IP del proxy: falla en vez de pasar en silencio.
		$raw_proxies     = defined( 'ARALIA_TRUSTED_PROXIES' ) ? array_filter( array_map( 'trim', explode( ',', (string) constant( 'ARALIA_TRUSTED_PROXIES' ) ) ) ) : array();
		$proxies_dropped = (bool) ( $overrides['trusted_proxies_dropped'] ?? ( count( $raw_proxies ) !== count( \Aralia\Support\ClientIp::filter_entries( $raw_proxies ) ) ) );
		$add( 'trusted_proxies', ! $strict || ( $proxies_defined && ! $proxies_dropped ), $strict ? 'ARALIA_TRUSTED_PROXIES definida (vacía solo si REMOTE_ADDR ya es la IP real: verificarlo en staging, T012)' : 'omitido en ' . $env );

		$turnstile_secret = (string) ( $overrides['turnstile_secret'] ?? ( defined( 'ARALIA_TURNSTILE_SECRET_KEY' ) ? constant( 'ARALIA_TURNSTILE_SECRET_KEY' ) : '' ) );
		$add( 'turnstile_secret', ! $strict || ( '' !== $turnstile_secret && ! in_array( $turnstile_secret, Turnstile::DUMMY_SECRETS, true ) ), $strict ? 'Turnstile con llave real (no vacía ni de prueba)' : 'omitido en ' . $env );
		$add( 'brevo_key', ! $strict || '' !== (string) ( $overrides['brevo_key'] ?? ( defined( 'ARALIA_BREVO_API_KEY' ) ? constant( 'ARALIA_BREVO_API_KEY' ) : '' ) ), $strict ? 'ARALIA_BREVO_API_KEY configurada' : 'omitido en ' . $env );
		[ $alert_email, $alert_phone ] = array(
			(string) ( $overrides['alert_email'] ?? Alerts::destinations( $env )[0] ),
			(string) ( $overrides['alert_phone'] ?? Alerts::destinations( $env )[1] ),
		);
		// El celular debe ser un móvil colombiano: BrevoClient descarta en silencio cualquier otro y las alertas críticas no llegarían por SMS.
		$phone_ok = true;
		try {
			Crypto::normalize_phone( $alert_phone );
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			$phone_ok = false;
		}
		$add( 'alert_destinations', ! $strict || ( is_email( $alert_email ) && $phone_ok ), $strict ? 'ARALIA_ALERT_EMAIL (correo) y ARALIA_ALERT_PHONE (móvil colombiano +57 3XX) definidas en aralia-secrets.php' : 'omitido en ' . $env );

		$headers = Headers::build( true );
		$needed  = array( 'Strict-Transport-Security', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'Content-Security-Policy' );
		$missing = array_diff( $needed, array_keys( $headers ) );
		$add( 'security_headers', ! $missing && false !== strpos( $headers['Content-Security-Policy'], "frame-ancestors 'self'" ), 'Cabeceras de seguridad y frame-ancestors' . ( $missing ? ' (faltan: ' . implode( ', ', $missing ) . ')' : '' ) );
		$add( 'headers_registered', false !== has_filter( 'wp_headers', array( Headers::class, 'filter_headers' ) ), 'Las cabeceras están enganchadas' );
		$add( 'hsts_https', ! $strict || is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ), $strict ? 'El sitio se sirve por HTTPS' : 'omitido en ' . $env );

		// En entornos estrictos se mira la respuesta REAL (con caché, CDN y servidor), no la función que arma las cabeceras.
		if ( $strict ) {
			$real = array_change_key_case( null !== $fetch_headers ? (array) $fetch_headers() : self::fetch_home_headers(), CASE_LOWER );
			$gaps = array();
			foreach ( array( 'x-content-type-options', 'referrer-policy', 'permissions-policy', 'strict-transport-security' ) as $name ) {
				if ( empty( $real[ $name ] ) ) {
					$gaps[] = $name;
				}
			}
			if ( false === strpos( (string) ( $real['content-security-policy'] ?? '' ), "frame-ancestors 'self'" ) && empty( $real['x-frame-options'] ) ) {
				$gaps[] = 'frame-ancestors';
			}
			$add( 'headers_real', ! $gaps, $gaps ? 'La respuesta real no trae: ' . implode( ', ', $gaps ) : 'La respuesta real de la home trae las cabeceras de seguridad' );
		} else {
			$add( 'headers_real', true, 'omitido en ' . $env );
		}

		$uploads  = wp_get_upload_dir();
		$htfile   = rtrim( (string) ( $overrides['uploads_dir'] ?? ( $uploads['basedir'] ?? '' ) ), '/' ) . '/.htaccess';
		$rules_ok = function_exists( 'aralia_hardening_uploads_htaccess_rules' ) && is_file( $htfile ) && file_get_contents( $htfile ) === aralia_hardening_uploads_htaccess_rules(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$add( 'uploads_no_php', ! $strict || $rules_ok, $strict ? 'uploads/ sin ejecución de PHP (.htaccess)' : 'omitido en ' . $env );

		$debug_off = ! (bool) ( $overrides['debug'] ?? ( defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ) ) );
		$add( 'debug_off', ! $strict || $debug_off, $strict ? 'WP_DEBUG apagado' : 'omitido en ' . $env );

		$secrets = function_exists( 'aralia_secrets_status' ) ? aralia_secrets_status() : 'not-run';
		$add( 'secrets_loaded', 'loaded' === $secrets, 'Secretos: ' . $secrets );

		try {
			Crypto::from_constants();
			$crypto = true;
		} catch ( \RuntimeException $e ) {
			unset( $e );
			$crypto = false;
		}
		$add( 'privacy_keys', $crypto, 'ARALIA_PII_KEY y ARALIA_HMAC_KEY válidas y distintas' );

		$diff = class_exists( 'WooCommerce' ) ? StoreConfig::diff() : array(
			'woocommerce' => array(
				'from' => 'ausente',
				'to'   => 'activo',
			),
		);
		$add( 'store_config', ! $diff, $diff ? 'Configuración distinta de lo esperado: ' . implode( ', ', array_keys( $diff ) ) : 'Configuración de la tienda conforme (COP, HPOS, invitada, rol cliente)' );

		$migrator = new Migrator();
		$add( 'db_current', ! $migrator->is_pending() && false === get_option( Migrator::OPTION_ERROR ), 'Base de datos en la versión ' . $migrator->stored_version() . ' sin errores de migración' );

		if ( ! function_exists( 'is_plugin_active' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$wordfence = (bool) ( $overrides['wordfence_active'] ?? ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'wordfence/wordfence.php' ) ) );
		$add( 'wordfence_active', ! $strict || $wordfence, $strict ? 'Wordfence activo' : 'omitido en ' . $env );

		return $out;
	}

	/**
	 * Cabeceras de la respuesta real de la home.
	 *
	 * @return array<string,string>
	 */
	private static function fetch_home_headers(): array {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$out = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$out[ (string) $name ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return $out;
	}
}
