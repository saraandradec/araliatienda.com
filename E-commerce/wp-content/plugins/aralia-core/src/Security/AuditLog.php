<?php
/**
 * Registro de actividad (T027, R-3 del threat model).
 *
 * @package Aralia
 */

namespace Aralia\Security;

use Aralia\Database\Migrator;
use Aralia\Privacy\Crypto;
use Aralia\Support\ClientIp;

defined( 'ABSPATH' ) || exit;

/**
 * Escribe en `aralia_audit_log`. Reglas:
 * - la acción debe estar en la lista cerrada (nada de texto libre del usuario);
 * - nunca guarda datos personales ni secretos: las claves sensibles de before/after se redactan y el tamaño se limita;
 * - la IP se guarda como huella truncada (Crypto::ip_hash), no en claro;
 * - un fallo al escribir NO interrumpe la petición (se registra con error_log sin datos personales).
 */
class AuditLog {

	/**
	 * Acciones permitidas.
	 *
	 * @var string[]
	 */
	public const ACTIONS = array(
		'login',
		'login_failed',
		'price_change',
		'order_status_change',
		'refund_logged',
		'reversal_logged',
		'role_change',
		'user_created',
		'plugin_change',
		'settings_change',
		'blocked_transition',
		'alert_raised',
		'user_deleted',
		'user_changed',
	);

	/**
	 * Opciones cuyo cambio se registra.
	 *
	 * @var string[]
	 */
	public const WATCHED_OPTIONS = array(
		'siteurl',
		'home',
		'admin_email',
		'default_role',
		'users_can_register',
		'woocommerce_currency',
		'woocommerce_price_num_decimals',
		'woocommerce_enable_guest_checkout',
		'woocommerce_custom_orders_table_enabled',
		'woocommerce_custom_orders_table_data_sync_enabled',
		'woocommerce_wompi_settings',
		'aralia_csp_mode',
		'aralia_alert_email',
		'aralia_alert_phone',
		'aralia_csp_report_uri',
	);

	private const MAX_JSON_BYTES = 8192;

	/**
	 * Capacidades que equivalen a (o dan camino directo a) ser administrador: con `install_plugins`, `install_themes` o `edit_plugins` se ejecuta código
	 * (DISALLOW_FILE_MODS no está definido); `manage_woocommerce` da acceso a los ajustes de pago.
	 *
	 * @var string[]
	 */
	public const PRIVILEGED_CAPS = array( 'manage_options', 'promote_users', 'edit_users', 'create_users', 'delete_users', 'install_plugins', 'activate_plugins', 'edit_plugins', 'update_plugins', 'delete_plugins', 'install_themes', 'update_themes', 'edit_themes', 'unfiltered_html', 'update_core', 'manage_woocommerce' );

	/**
	 * Cripto para la huella de IP (opcional: sin llaves se guarda vacía).
	 *
	 * @var Crypto|null
	 */
	private ?Crypto $crypto;

	/**
	 * Limitador (para no inundar la tabla con intentos de acceso fallidos).
	 *
	 * @var RateLimiter|null
	 */
	private ?RateLimiter $limiter;

	/**
	 * Receptor de alertas: callable(evento, referencia). Por defecto ninguno (los eventos se registran sin alertar).
	 *
	 * @var callable|null
	 */
	private $alert_sink;

	/**
	 * Usuarios ya alertados en esta petición.
	 *
	 * @var array<int,bool>
	 */
	private array $alerted_users = array();

	/**
	 * Alertas de roles ya levantadas en esta petición.
	 *
	 * @var array<string,bool>
	 */
	private array $alerted_roles = array();

	/**
	 * Constructor.
	 *
	 * @param Crypto|null       $crypto     Cripto (por defecto, intenta construirla de las constantes).
	 * @param RateLimiter|null  $limiter    Limitador de los intentos fallidos.
	 * @param callable|null     $alert_sink Receptor de alertas.
	 */
	public function __construct( ?Crypto $crypto = null, ?RateLimiter $limiter = null, ?callable $alert_sink = null ) {
		$this->limiter    = $limiter;
		$this->alert_sink = $alert_sink;
		if ( null === $crypto ) {
			try {
				$crypto = Crypto::from_constants();
			} catch ( \RuntimeException $e ) {
				unset( $e );
				$crypto = null;
			}
		}
		$this->crypto = $crypto;
	}

	/**
	 * Registra un evento.
	 *
	 * @param string   $action      Una de ACTIONS.
	 * @param string   $object_type Tipo de objeto (user, order, product, plugin, option...).
	 * @param int      $object_id   Id del objeto.
	 * @param mixed    $before      Estado anterior (se redacta).
	 * @param mixed    $after       Estado posterior (se redacta).
	 * @param int|null $user_id     Quién lo hizo (por defecto, el usuario actual).
	 * @return int Id de la fila, o 0 si no se pudo escribir.
	 * @throws \InvalidArgumentException Si la acción no está permitida.
	 */
	public function record( string $action, string $object_type = '', int $object_id = 0, $before = null, $after = null, ?int $user_id = null ): int {
		global $wpdb;
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			throw new \InvalidArgumentException( 'Acción de auditoría no permitida.' );
		}
		$ip_hash = '';
		$ip      = ClientIp::get();
		if ( null !== $this->crypto && '' !== $ip ) {
			try {
				$ip_hash = $this->crypto->ip_hash( $ip );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e );
			}
		}
		$row  = array(
			'at'           => gmdate( 'Y-m-d H:i:s' ),
			'user_id'      => max( 0, $user_id ?? (int) get_current_user_id() ),
			'ip_hash'      => $ip_hash,
			'action'       => $action,
			'object_type'  => substr( preg_replace( '/[^a-z0-9_:-]/', '', strtolower( $object_type ) ), 0, 40 ),
			'object_id'    => max( 0, $object_id ),
			'before_value' => $this->encode( $before ),
			'after_value'  => $this->encode( $after ),
		);
		$prev = $wpdb->suppress_errors( true );
		$ok   = $wpdb->insert( Migrator::tables()['audit_log'], $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $prev );
		if ( false === $ok ) {
			error_log( '[aralia] no se pudo escribir el registro de actividad (' . $action . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Redacta claves sensibles de forma recursiva.
	 *
	 * @param mixed $value Valor.
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && preg_match( '/pass|pwd|token|secret|key|email|phone|tel|mobile|celular|whatsapp|card|cvv|nonce|signature|checksum|address|name|nit|cedula|document|auth|cookie|session|bearer|private|iban|integrity|events|hash/i', $key ) ) {
				$out[ $key ] = '[redactado]';
			} else {
				$out[ $key ] = self::redact( $item );
			}
		}
		return $out;
	}

	/**
	 * JSON redactado y acotado.
	 *
	 * @param mixed $value Valor.
	 * @return string|null
	 */
	private function encode( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$json = (string) wp_json_encode( self::redact( $value ) );
		if ( strlen( $json ) > self::MAX_JSON_BYTES ) {
			$json = (string) wp_json_encode( array( 'truncado' => true ) );
		}
		return $json;
	}

	/**
	 * Purga por antigüedad, en lotes. El registro de actividad se conserva al menos 1 año (TM R-3): se fuerza un mínimo de 365 días.
	 * La huella de IP tiene una retención más corta (anonymize_ip_older_than).
	 *
	 * @param int $days Días a conservar (mínimo 365).
	 * @return int Filas borradas.
	 */
	public function purge_older_than( int $days ): int {
		global $wpdb;
		$table = Migrator::tables()['audit_log'];
		$total = 0;
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE `at` < UTC_TIMESTAMP() - INTERVAL %d DAY LIMIT 1000", max( 365, $days ) ) );
			// phpcs:enable
			$total += $n;
			if ( $n < 1000 ) {
				break;
			}
		}
		return $total;
	}

	/**
	 * Borra la huella de IP de las filas más viejas que `$days` (los registros de seguridad conservan la IP 90 días; la fila, 1 año).
	 *
	 * @param int $days Días.
	 * @return int Filas modificadas.
	 */
	public function anonymize_ip_older_than( int $days ): int {
		global $wpdb;
		$table = Migrator::tables()['audit_log'];
		$total = 0;
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET ip_hash = '' WHERE ip_hash <> '' AND `at` < UTC_TIMESTAMP() - INTERVAL %d DAY LIMIT 1000", max( 1, $days ) ) );
			// phpcs:enable
			$total += $n;
			if ( $n < 1000 ) {
				break;
			}
		}
		return $total;
	}

	/**
	 * Engancha los eventos de WordPress y WooCommerce.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ) );
		add_action( 'set_user_role', array( $this, 'on_set_user_role' ), 10, 3 );
		add_action( 'add_user_role', array( $this, 'on_role_added_removed' ), 10, 2 );
		add_action( 'remove_user_role', array( $this, 'on_role_removed' ), 10, 2 );
		add_action( 'added_user_meta', array( $this, 'on_user_meta' ), 10, 4 );
		add_action( 'updated_user_meta', array( $this, 'on_user_meta' ), 10, 4 );
		add_action( 'after_password_reset', array( $this, 'on_password_reset' ), 10, 1 );
		add_action( 'wp_set_password', array( $this, 'on_set_password' ), 10, 2 );
		add_action( 'user_register', array( $this, 'on_user_register' ) );
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ) );
		add_action( 'updated_option', array( $this, 'on_option_updated' ), 10, 3 );
		add_action( 'added_option', array( $this, 'on_option_added' ), 10, 2 );
		add_action( 'deleted_option', array( $this, 'on_option_deleted' ), 10, 1 );
		add_action( 'delete_user', array( $this, 'on_user_deleted' ) );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
		add_action( 'deleted_plugin', array( $this, 'on_plugin_deleted' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'on_theme_switched' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
		add_action( 'woocommerce_product_object_updated_props', array( $this, 'on_product_props' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refund' ), 10, 2 );
		add_action( 'aralia_blocked_transition', array( $this, 'on_blocked_transition' ), 10, 3 );
		add_action( 'aralia_reversal_logged', array( $this, 'on_reversal' ), 10, 2 );
	}

	/**
	 * Inicio de sesión.
	 *
	 * @param string   $login Usuario.
	 * @param \WP_User $user  Objeto.
	 * @return void
	 */
	public function on_login( $login, $user ): void {
		unset( $login );
		$this->record( 'login', 'user', (int) $user->ID, null, array( 'roles' => array_values( (array) $user->roles ) ), (int) $user->ID );
	}

	/**
	 * Inicio de sesión fallido: solo si el usuario existe (nunca el nombre escrito: puede ser una contraseña o un correo).
	 *
	 * @param string $username Escrito.
	 * @return void
	 */
	public function on_login_failed( $username ): void {
		// Una fila por intento permitiría inflar la tabla con fuerza bruta: como máximo 5 filas cada 5 minutos por IP; el resto se resume en una fila.
		$ip = ClientIp::get();
		if ( null !== $this->limiter && null !== $this->crypto && '' !== $ip ) {
			try {
				$hits = $this->limiter->hit( $this->crypto->ip_key( $ip ), 'audit_lf', 300 );
			} catch ( \RuntimeException | \InvalidArgumentException $e ) {
				unset( $e );
				$hits = 1;
			}
			if ( $hits > 6 ) {
				return;
			}
			if ( 6 === $hits ) {
				$this->record( 'login_failed', 'user', 0, null, array( 'resumen' => 'intentos fallidos suprimidos durante 5 minutos' ), 0 );
				return;
			}
		}
		$this->record( 'login_failed', 'user', 0, null, array( 'usuario_existe' => false !== username_exists( (string) $username ) ), 0 );
	}

	/**
	 * Cambio de rol.
	 *
	 * @param int      $user_id   Usuario.
	 * @param string   $role      Rol nuevo.
	 * @param string[] $old_roles Roles anteriores.
	 * @return void
	 */
	public function on_set_user_role( $user_id, $role, $old_roles ): void {
		$this->record( 'role_change', 'user', (int) $user_id, array( 'roles' => array_values( (array) $old_roles ) ), array( 'roles' => array( (string) $role ) ) );
		$this->alert_if_admin( (int) $user_id, (array) $old_roles );
	}

	/**
	 * Levanta la alerta `admin_role_change` si el usuario es (o pasa a ser) administrador.
	 *
	 * @param int      $user_id   Usuario.
	 * @param string[] $old_roles Roles anteriores (opcional).
	 * @return void
	 */
	private function alert_if_admin( int $user_id, array $old_roles = array() ): void {
		if ( null === $this->alert_sink || isset( $this->alerted_users[ $user_id ] ) ) {
			return; // Una sola alerta por usuario y petición (set_role dispara varias acciones y cada alerta gasta correo y SMS).
		}
		clean_user_cache( $user_id ); // Las capacidades pueden haberse escrito como metadato un instante antes.
		$user   = get_userdata( $user_id );
		$is_now = self::user_is_privileged( $user );
		$was    = in_array( 'administrator', $old_roles, true );
		// Alerta si ES administrador ahora o LO ERA (degradar a un administrador es el bloqueo típico de la dueña).
		if ( $is_now || $was ) {
			$this->raise_admin_alert( $user_id );
		}
	}

	/**
	 * Levanta la alerta de identidad una sola vez por usuario y petición.
	 *
	 * @param int $user_id Usuario.
	 * @return void
	 */
	private function raise_admin_alert( int $user_id ): void {
		if ( null === $this->alert_sink || isset( $this->alerted_users[ $user_id ] ) ) {
			return;
		}
		$this->alerted_users[ $user_id ] = true;
		( $this->alert_sink )( 'admin_role_change', 'usuario #' . $user_id );
	}

	/**
	 * Rol agregado o retirado.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $role    Rol.
	 * @return void
	 */
	public function on_role_added_removed( $user_id, $role ): void {
		$this->record( 'role_change', 'user', (int) $user_id, null, array( 'rol_tocado' => (string) $role ) );
		$this->alert_if_admin( (int) $user_id );
	}

	/**
	 * Capacidades del usuario escritas DIRECTAMENTE como metadato (`add_cap`, `update_user_meta` de un plugin vulnerable): no pasan por
	 * set_role y no disparan set_user_role ni profile_update. Es la vía típica de escalada de privilegios.
	 *
	 * @param int    $meta_id    Id del metadato.
	 * @param int    $user_id    Usuario.
	 * @param string $meta_key   Clave.
	 * @param mixed  $meta_value Valor.
	 * @return void
	 */
	public function on_user_meta( $meta_id, $user_id, $meta_key, $meta_value ): void {
		global $wpdb;
		unset( $meta_id );
		// La colación de meta_key no distingue mayúsculas ni espacios finales: `WP_CAPABILITIES` o `wp_capabilities ` escriben la MISMA fila.
		if ( 0 !== strcasecmp( rtrim( (string) $meta_key ), $wpdb->get_blog_prefix() . 'capabilities' ) ) {
			return;
		}
		$raw = is_array( $meta_value ) ? $meta_value : array();
		// Se registran las claves SIN filtrar por valor: WordPress toma los roles de las CLAVES del metadato aunque el valor sea false.
		$this->record( 'role_change', 'user', (int) $user_id, null, array( 'claves' => array_map( 'boolval', $raw ) ) );
		if ( self::meta_grants_privileges( $raw ) ) {
			$this->raise_admin_alert( (int) $user_id );
			return;
		}
		// Respaldo: el efecto real sobre el usuario ya guardado (las cachés de metadatos se limpian antes de disparar este gancho).
		clean_user_cache( (int) $user_id );
		$user = new \WP_User( (int) $user_id );
		if ( self::user_is_privileged( $user ) ) {
			$this->raise_admin_alert( (int) $user_id );
		}
	}

	/**
	 * El valor de las capacidades concede privilegios de administrador. Imita a WP_User::get_role_caps: cada CLAVE que sea un rol conocido
	 * cuenta como rol (sin mirar su valor), y las capacidades directas cuentan si su valor es verdadero.
	 *
	 * @param array<string,mixed> $caps Valor del metadato.
	 * @return bool
	 */
	public static function meta_grants_privileges( array $caps ): bool {
		foreach ( $caps as $key => $value ) {
			$key = (string) $key;
			if ( wp_roles()->is_role( $key ) ) {
				if ( 'administrator' === $key || self::role_is_privileged( $key ) ) {
					return true;
				}
			} elseif ( $value && in_array( $key, self::PRIVILEGED_CAPS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Un rol tiene alguna capacidad privilegiada.
	 *
	 * @param string $role Rol.
	 * @return bool
	 */
	public static function role_is_privileged( string $role ): bool {
		$object = wp_roles()->get_role( $role );
		if ( ! $object ) {
			return false;
		}
		foreach ( self::PRIVILEGED_CAPS as $cap ) {
			if ( $object->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * El usuario es administrador o tiene alguna capacidad privilegiada.
	 *
	 * @param \WP_User|false|null $user Usuario.
	 * @return bool
	 */
	public static function user_is_privileged( $user ): bool {
		if ( ! $user instanceof \WP_User ) {
			return false;
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return true;
		}
		foreach ( self::PRIVILEGED_CAPS as $cap ) {
			if ( $user->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rol retirado: se pasa como «rol anterior» porque al dispararse la acción el usuario ya no lo tiene.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $role    Rol retirado.
	 * @return void
	 */
	public function on_role_removed( $user_id, $role ): void {
		$this->record( 'role_change', 'user', (int) $user_id, array( 'roles' => array( (string) $role ) ), null );
		$this->alert_if_admin( (int) $user_id, array( (string) $role ) );
	}

	/**
	 * Contraseña restablecida con «olvidé mi contraseña» (no dispara profile_update).
	 *
	 * @param \WP_User $user Usuario.
	 * @return void
	 */
	public function on_password_reset( $user ): void {
		$this->record( 'user_changed', 'user', (int) $user->ID, null, array( 'cambio' => array( 'contrasena' ) ) );
		$this->alert_if_admin( (int) $user->ID );
	}

	/**
	 * Contraseña fijada con wp_set_password (WP-CLI, plugins).
	 *
	 * @param string $password Contraseña (nunca se guarda).
	 * @param int    $user_id  Usuario.
	 * @return void
	 */
	public function on_set_password( $password, $user_id ): void {
		unset( $password );
		$this->record( 'user_changed', 'user', (int) $user_id, null, array( 'cambio' => array( 'contrasena' ) ) );
		$this->alert_if_admin( (int) $user_id );
	}

	/**
	 * Usuario creado.
	 *
	 * @param int $user_id Usuario.
	 * @return void
	 */
	public function on_user_register( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		$this->record( 'user_created', 'user', (int) $user_id, null, array( 'roles' => $user ? array_values( (array) $user->roles ) : array() ) );
		$this->alert_if_admin( (int) $user_id );
	}

	/**
	 * Opción vigilada creada por primera vez.
	 *
	 * @param string $option Nombre.
	 * @param mixed  $value  Valor.
	 * @return void
	 */
	public function on_option_added( $option, $value ): void {
		$this->check_roles_option( (string) $option, array(), $value );
		if ( in_array( $option, self::WATCHED_OPTIONS, true ) ) {
			$this->record( 'settings_change', 'option', 0, null, array( (string) $option => $value ) );
		}
	}

	/**
	 * Cambios en la definición de los roles (`{prefijo}user_roles`): `get_role('customer')->add_cap('manage_options')` daría ese privilegio a
	 * TODAS las clientas sin pasar por ningún gancho de usuario. Se alerta si un rol gana una capacidad privilegiada o cambia el de administrador.
	 *
	 * @param string $option Opción.
	 * @param mixed  $old    Valor anterior.
	 * @param mixed  $new_roles Valor nuevo.
	 * @return void
	 */
	private function check_roles_option( string $option, $old, $new_roles ): void {
		global $wpdb;
		// option_name tampoco distingue mayúsculas: `WP_USER_ROLES` sobrescribe la misma fila.
		if ( 0 !== strcasecmp( trim( $option ), $wpdb->get_blog_prefix() . 'user_roles' ) || ! is_array( $new_roles ) ) {
			return;
		}
		$old    = is_array( $old ) ? $old : array();
		$gained = array();
		if ( isset( $old['administrator'] ) && ! isset( $new_roles['administrator'] ) ) {
			$gained[] = 'administrator-eliminado'; // Quitar el rol administrador de la definición bloquea a la dueña.
		}
		foreach ( $new_roles as $role => $def ) {
			$new_caps = isset( $def['capabilities'] ) && is_array( $def['capabilities'] ) ? array_keys( array_filter( $def['capabilities'] ) ) : array();
			$old_caps = isset( $old[ $role ]['capabilities'] ) && is_array( $old[ $role ]['capabilities'] ) ? array_keys( array_filter( $old[ $role ]['capabilities'] ) ) : array();
			if ( array_intersect( array_diff( $new_caps, $old_caps ), self::PRIVILEGED_CAPS ) ) {
				$gained[] = (string) $role;
			} elseif ( 'administrator' === $role && $new_caps !== $old_caps ) {
				$gained[] = (string) $role;
			}
		}
		if ( ! $gained ) {
			return;
		}
		$this->record( 'settings_change', 'option', 0, null, array( 'roles_con_privilegios_nuevos' => $gained ) );
		$this->alert_roles( 'rol ' . implode( ',', array_slice( $gained, 0, 3 ) ) );
	}

	/**
	 * Alerta de cambio en la definición de los roles, una vez por referencia y petición.
	 *
	 * @param string $ref Referencia sin datos personales.
	 * @return void
	 */
	private function alert_roles( string $ref ): void {
		if ( null === $this->alert_sink || isset( $this->alerted_roles[ $ref ] ) ) {
			return;
		}
		$this->alerted_roles[ $ref ] = true;
		( $this->alert_sink )( 'admin_role_change', $ref );
	}

	/**
	 * Borrado de la opción de roles (deja a todos sin rol, incluida la dueña).
	 *
	 * @param string $option Opción.
	 * @return void
	 */
	public function on_option_deleted( $option ): void {
		global $wpdb;
		if ( 0 === strcasecmp( trim( (string) $option ), $wpdb->get_blog_prefix() . 'user_roles' ) ) {
			$this->record( 'settings_change', 'option', 0, null, array( 'opcion_de_roles' => 'borrada' ) );
			$this->alert_roles( 'opcion de roles borrada' );
		}
	}

	/**
	 * Usuario borrado.
	 *
	 * @param int $user_id Usuario.
	 * @return void
	 */
	public function on_user_deleted( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		$this->record( 'user_deleted', 'user', (int) $user_id, array( 'roles' => $user ? array_values( (array) $user->roles ) : array() ), null );
		$this->alert_if_admin( (int) $user_id );
	}

	/**
	 * Cambio de correo o de contraseña de un usuario (sin guardar ninguno de los dos valores).
	 *
	 * @param int      $user_id       Usuario.
	 * @param \WP_User $old_user_data Datos anteriores.
	 * @return void
	 */
	public function on_profile_update( $user_id, $old_user_data ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! $old_user_data ) {
			return;
		}
		$changed = array();
		if ( $user->user_email !== $old_user_data->user_email ) {
			$changed[] = 'correo';
		}
		if ( $user->user_pass !== $old_user_data->user_pass ) {
			$changed[] = 'contrasena';
		}
		if ( $changed ) {
			$this->record( 'user_changed', 'user', (int) $user_id, null, array( 'cambio' => $changed ) );
			// Cambio de correo o contraseña de un administrador: es como se toma una cuenta.
			$this->alert_if_admin( (int) $user_id );
		}
	}

	/**
	 * Plugin borrado.
	 *
	 * @param string $plugin  Ruta.
	 * @param bool   $deleted Se borró.
	 * @return void
	 */
	public function on_plugin_deleted( $plugin, $deleted ): void {
		$this->record(
			'plugin_change',
			'plugin',
			0,
			null,
			array(
				'plugin' => (string) $plugin,
				'estado' => $deleted ? 'borrado' : 'borrado_fallido',
			)
		);
	}

	/**
	 * Tema cambiado.
	 *
	 * @param string    $new_name  Nombre del tema nuevo.
	 * @param \WP_Theme $new_theme Tema nuevo.
	 * @param \WP_Theme $old_theme Tema anterior.
	 * @return void
	 */
	public function on_theme_switched( $new_name, $new_theme, $old_theme ): void {
		$this->record( 'settings_change', 'theme', 0, array( 'tema' => $old_theme instanceof \WP_Theme ? $old_theme->get_stylesheet() : '' ), array( 'tema' => $new_theme instanceof \WP_Theme ? $new_theme->get_stylesheet() : (string) $new_name ) );
	}

	/**
	 * Instalación o actualización de plugins y temas.
	 *
	 * @param \WP_Upgrader $upgrader Actualizador.
	 * @param array<string,mixed> $hook_extra Datos de la operación.
	 * @return void
	 */
	public function on_upgrade( $upgrader, $hook_extra ): void {
		unset( $upgrader );
		$type = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '';
		if ( in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			$this->record( 'plugin_change', $type, 0, null, array( 'accion' => isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '' ) );
		}
	}

	/**
	 * Plugin activado.
	 *
	 * @param string $plugin Ruta del plugin.
	 * @return void
	 */
	public function on_plugin_activated( $plugin ): void {
		$this->record(
			'plugin_change',
			'plugin',
			0,
			null,
			array(
				'plugin' => (string) $plugin,
				'estado' => 'activado',
			)
		);
	}

	/**
	 * Plugin desactivado.
	 *
	 * @param string $plugin Ruta del plugin.
	 * @return void
	 */
	public function on_plugin_deactivated( $plugin ): void {
		$this->record(
			'plugin_change',
			'plugin',
			0,
			null,
			array(
				'plugin' => (string) $plugin,
				'estado' => 'desactivado',
			)
		);
	}

	/**
	 * Opción vigilada actualizada.
	 *
	 * @param string $option Nombre.
	 * @param mixed  $old_value Valor anterior.
	 * @param mixed  $new_value Valor nuevo.
	 * @return void
	 */
	public function on_option_updated( $option, $old_value, $new_value ): void {
		$this->check_roles_option( (string) $option, $old_value, $new_value );
		if ( in_array( $option, self::WATCHED_OPTIONS, true ) ) {
			$this->record( 'settings_change', 'option', 0, array( (string) $option => $old_value ), array( (string) $option => $new_value ) );
		}
	}

	/**
	 * Cambio de precio de un producto.
	 *
	 * @param \WC_Product $product Producto.
	 * @param string[]    $props   Propiedades cambiadas.
	 * @return void
	 */
	public function on_product_props( $product, $props ): void {
		if ( array_intersect( (array) $props, array( 'regular_price', 'sale_price', 'price' ) ) ) {
			$this->record(
				'price_change',
				'product',
				(int) $product->get_id(),
				null,
				array(
					'precio_regular' => $product->get_regular_price(),
					'precio_oferta'  => $product->get_sale_price(),
				)
			);
		}
	}

	/**
	 * Cambio de estado de un pedido.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $from     Estado anterior.
	 * @param string $to       Estado nuevo.
	 * @return void
	 */
	public function on_order_status( $order_id, $from, $to ): void {
		$this->record( 'order_status_change', 'order', (int) $order_id, array( 'estado' => (string) $from ), array( 'estado' => (string) $to ) );
	}

	/**
	 * Reembolso registrado.
	 *
	 * @param int $order_id  Pedido.
	 * @param int $refund_id Reembolso.
	 * @return void
	 */
	public function on_refund( $order_id, $refund_id ): void {
		$this->record( 'refund_logged', 'order', (int) $order_id, null, array( 'reembolso' => (int) $refund_id ) );
	}

	/**
	 * Anulación o reversa registrada (las produce el flujo de reembolsos y reversas, T-REV).
	 *
	 * @param int    $order_id Pedido.
	 * @param string $note     Referencia sin datos personales.
	 * @return void
	 */
	public function on_reversal( $order_id, $note = '' ): void {
		$this->record( 'reversal_logged', 'order', (int) $order_id, null, array( 'nota' => substr( (string) $note, 0, 120 ) ) );
	}

	/**
	 * Transición de estado bloqueada por la máquina de estados (T054).
	 *
	 * @param int    $order_id Pedido.
	 * @param string $from     Estado actual.
	 * @param string $to       Estado pedido.
	 * @return void
	 */
	public function on_blocked_transition( $order_id, $from, $to ): void {
		$this->record( 'blocked_transition', 'order', (int) $order_id, array( 'estado' => (string) $from ), array( 'estado_pedido' => (string) $to ) );
	}
}
