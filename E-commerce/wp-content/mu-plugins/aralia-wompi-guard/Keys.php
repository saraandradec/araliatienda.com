<?php
/**
 * Llaves de Wompi fuera de la base de datos (T050, H6, R-16).
 *
 * @package Aralia
 */

namespace Aralia\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Las llaves viven en constantes (aralia-secrets.php, fuera del webroot): se inyectan al leer los ajustes del plugin oficial y se
 * vacían antes de que el plugin los guarde. Nada de lo que se escriba en wp-admin llega a la base de datos.
 */
final class Keys {

	public const OPTION = 'woocommerce_wompi_settings';

	/**
	 * Campo de ajustes del plugin => constante (sin el prefijo `ARALIA_WOMPI_`).
	 *
	 * @var array<string,string>
	 */
	public const FIELDS = array(
		'test_public_key'       => 'PUB_TEST',
		'test_private_key'      => 'PRV_TEST',
		'test_event_secret_key' => 'EVENTS_TEST',
		'test_integrity_key'    => 'INTEGRITY_TEST',
		'public_key'            => 'PUB_PROD',
		'private_key'           => 'PRV_PROD',
		'event_secret_key'      => 'EVENTS_PROD',
		'integrity_key'         => 'INTEGRITY_PROD',
	);

	/**
	 * Tipo de llave => sufijo de la constante.
	 *
	 * @var array<string,string>
	 */
	private const KINDS = array(
		'public'    => 'PUB',
		'private'   => 'PRV',
		'events'    => 'EVENTS',
		'integrity' => 'INTEGRITY',
	);

	/**
	 * Engancha los filtros.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'option_' . self::OPTION, array( self::class, 'inject' ), 20 );
		// Sin fila guardada, WordPress aplica `default_option_*` en lugar de `option_*`: instalación nueva con llaves ya definidas.
		add_filter( 'default_option_' . self::OPTION, array( self::class, 'inject' ), 20 );
		add_filter( 'pre_update_option_' . self::OPTION, array( self::class, 'blank' ), 20 );
		add_filter( 'woocommerce_available_payment_gateways', array( self::class, 'require_keys' ), 100 );
		// Las llaves NO se pintan en el formulario de ajustes ni salen por la API REST de pasarelas (T-KEYS, SC-015).
		add_filter( 'woocommerce_settings_api_form_fields_wompi', array( self::class, 'hide_key_fields' ), PHP_INT_MAX );
		// La API REST de pasarelas NO usa get_form_fields(): recorre las propiedades del plugin y devuelve el valor guardado (las constantes
		// ya inyectadas). Se quitan de la respuesta de `/wc/v3/payment_gateways/wompi`.
		add_filter( 'woocommerce_rest_prepare_payment_gateway', array( self::class, 'strip_rest_keys' ), PHP_INT_MAX, 2 );
		// Una instalación que ya las tenía guardadas se limpia una vez (y `wp aralia baseline check` lo comprueba).
		add_action( 'admin_init', array( self::class, 'on_admin_init' ) );
	}

	/**
	 * Callback de `admin_init`: limpia llaves guardadas.
	 *
	 * @return void
	 */
	public static function on_admin_init(): void {
		self::purge_stored();
	}

	/**
	 * Quita los campos de llaves del formulario de ajustes (se reemplazan por un aviso).
	 *
	 * @param mixed $fields Campos del formulario.
	 * @return array<string,mixed>
	 */
	public static function hide_key_fields( $fields ): array {
		$fields = is_array( $fields ) ? $fields : array();
		foreach ( array_keys( self::FIELDS ) as $field ) {
			unset( $fields[ $field ] );
		}
		$fields['aralia_keys_notice'] = array(
			'title'       => 'Llaves',
			'type'        => 'title',
			'description' => 'Las llaves de Wompi se configuran en el archivo de secretos del servidor (constantes ARALIA_WOMPI_*), no aquí.',
		);
		return $fields;
	}

	/**
	 * Quita los campos de llaves de la respuesta REST de la pasarela.
	 *
	 * @param mixed $response Respuesta (WP_REST_Response).
	 * @param mixed $gateway  Pasarela.
	 * @return mixed
	 */
	public static function strip_rest_keys( $response, $gateway = null ) {
		if ( ! $response instanceof \WP_REST_Response || ! is_object( $gateway ) || 'wompi' !== ( $gateway->id ?? '' ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			foreach ( array_keys( self::FIELDS ) as $field ) {
				unset( $data['settings'][ $field ] );
			}
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Llaves guardadas en la base de datos, leídas SIN pasar por los filtros (para la limpieza y para `baseline`).
	 *
	 * @return string[] Campos de llave con valor.
	 */
	public static function stored_key_fields(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lectura cruda a propósito: get_option inyectaría las constantes.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		$val = is_string( $raw ) ? maybe_unserialize( $raw ) : array();
		$out = array();
		foreach ( array_keys( self::FIELDS ) as $field ) {
			if ( is_array( $val ) && isset( $val[ $field ] ) && '' !== (string) $val[ $field ] ) {
				$out[] = $field;
			}
		}
		return $out;
	}

	/**
	 * Vacía en la base de datos las llaves que ya estuvieran guardadas.
	 *
	 * @return int Campos vaciados.
	 */
	public static function purge_stored(): int {
		global $wpdb;
		$fields = self::stored_key_fields();
		if ( array() === $fields ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lectura cruda a propósito.
		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		$val = maybe_unserialize( $raw );
		if ( ! is_array( $val ) ) {
			return 0;
		}
		foreach ( $fields as $field ) {
			$val[ $field ] = '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Escritura cruda: update_option reinyectaría las constantes al comparar.
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $val ) ), array( 'option_name' => self::OPTION ) );
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return count( $fields );
	}

	/**
	 * Lee los ajustes con las llaves de las constantes (las de la base de datos se ignoran siempre).
	 *
	 * @param mixed $value Ajustes guardados.
	 * @return array<string,mixed>
	 */
	public static function inject( $value ): array {
		$settings = is_array( $value ) ? $value : array();
		foreach ( self::FIELDS as $field => $suffix ) {
			$settings[ $field ] = self::constant( $suffix );
		}
		// El registro del plugin vuelca cuerpos y respuestas completos de la API: se mantiene apagado.
		$settings['logging'] = 'no';
		// El plugin decide su modo (`is_available`, llaves) con `testmode`: debe coincidir con el del guard cuando la constante manda.
		if ( defined( 'ARALIA_WOMPI_MODE' ) && in_array( constant( 'ARALIA_WOMPI_MODE' ), array( 'test', 'prod' ), true ) ) {
			$settings['testmode'] = 'test' === constant( 'ARALIA_WOMPI_MODE' ) ? 'yes' : 'no';
		}
		return $settings;
	}

	/**
	 * Vacía los campos de llaves antes de guardar.
	 *
	 * @param mixed $value Ajustes que se van a guardar.
	 * @return array<string,mixed>
	 */
	public static function blank( $value ): array {
		$settings = is_array( $value ) ? $value : array();
		foreach ( array_keys( self::FIELDS ) as $field ) {
			$settings[ $field ] = '';
		}
		return $settings;
	}

	/**
	 * Sin las llaves del modo vigente, el medio de pago no se ofrece (falla cerrado).
	 *
	 * @param mixed $gateways Pasarelas disponibles.
	 * @return array<string,mixed>
	 */
	public static function require_keys( $gateways ): array {
		$gateways = is_array( $gateways ) ? $gateways : array();
		if ( isset( $gateways['wompi'] ) && ! self::checkout_ready() ) {
			unset( $gateways['wompi'] );
		}
		return $gateways;
	}

	/**
	 * Modo vigente: 'test' o 'prod'. La constante `ARALIA_WOMPI_MODE` manda; si no existe, solo `local` y `test` obedecen el ajuste
	 * `testmode` de la base de datos (cualquier otro entorno, incluido vacío, es producción: una opción editable no cambia el modo).
	 *
	 * @param string|null $env Entorno (por defecto ARALIA_ENV; el parámetro existe para probarlo).
	 * @return string
	 */
	public static function mode( ?string $env = null ): string {
		$settings = get_option( self::OPTION, array() );
		return self::decide_mode(
			defined( 'ARALIA_WOMPI_MODE' ) ? constant( 'ARALIA_WOMPI_MODE' ) : null,
			$env ?? ( defined( 'ARALIA_ENV' ) ? (string) ARALIA_ENV : '' ),
			is_array( $settings ) ? (string) ( $settings['testmode'] ?? '' ) : ''
		);
	}

	/**
	 * Decisión pura del modo (se prueba sin constantes globales).
	 *
	 * @param mixed  $constant Valor de ARALIA_WOMPI_MODE (null si no está definida).
	 * @param string $env      Entorno.
	 * @param string $testmode Ajuste `testmode` guardado ('yes'/'no').
	 * @return string 'test' | 'prod'
	 */
	public static function decide_mode( $constant, string $env, string $testmode ): string {
		if ( in_array( $constant, array( 'test', 'prod' ), true ) ) {
			return (string) $constant;
		}
		if ( ! in_array( $env, array( 'local', 'test' ), true ) ) {
			return 'prod';
		}
		return 'yes' === $testmode ? 'test' : 'prod';
	}

	/**
	 * ¿Se puede ofrecer el medio de pago? Hace falta el juego de llaves del modo vigente Y el order-pay propio del guard (T061): con el
	 * widget del plugin oficial la referencia no lleva intento (`<pedido>-<n>`) y el pago se cobraría sin que el pedido pudiera
	 * marcarse como pagado.
	 *
	 * @return bool
	 */
	public static function checkout_ready(): bool {
		return self::available() && class_exists( '\\Aralia\\Guard\\OrderPay' );
	}

	/**
	 * Llave del modo vigente: 'public' | 'private' | 'events' | 'integrity'. Vacía si falta.
	 *
	 * @param string      $kind Tipo.
	 * @param string|null $mode Modo (por defecto, el vigente).
	 * @return string
	 */
	public static function get( string $kind, ?string $mode = null ): string {
		if ( ! isset( self::KINDS[ $kind ] ) ) {
			return '';
		}
		$mode = $mode ?? self::mode();
		return self::constant( self::KINDS[ $kind ] . '_' . ( 'test' === $mode ? 'TEST' : 'PROD' ) );
	}

	/**
	 * ¿Están las llaves necesarias para cobrar (pública, de eventos y de integridad)?
	 *
	 * @param string|null $mode Modo (por defecto, el vigente).
	 * @return bool
	 */
	public static function available( ?string $mode = null ): bool {
		return '' !== self::get( 'public', $mode ) && '' !== self::get( 'events', $mode ) && '' !== self::get( 'integrity', $mode );
	}

	/**
	 * Valor de la constante `ARALIA_WOMPI_<sufijo>` (vacío si no existe o no es texto).
	 *
	 * @param string $suffix Sufijo.
	 * @return string
	 */
	private static function constant( string $suffix ): string {
		$name = 'ARALIA_WOMPI_' . $suffix;
		return defined( $name ) && is_string( constant( $name ) ) ? (string) constant( $name ) : '';
	}
}
