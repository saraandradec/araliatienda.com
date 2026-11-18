<?php
/**
 * Prepara la petición de `wp aralia wompi replay` (T162): valida, firma y devuelve URL, cabeceras y cuerpo.
 *
 * @package Aralia
 */

namespace Aralia\Wompi;

use Aralia\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Lógica pura y probable del comando: el comando solo envía lo que esto devuelve.
 */
final class ReplayBuilder {

	/**
	 * Hosts que --base-url admite (contenedores de wp-env y localhost): nada de metadatos de nube ni direcciones arbitrarias.
	 *
	 * @var string[]
	 */
	public const ALLOWED_HOSTS = array( 'localhost', '127.0.0.1', 'wordpress', 'tests-wordpress' );

	/**
	 * Campos que se pueden fijar ANTES de firmar (así el checksum es válido): permiten T-EVT-AMOUNT, -REF, -CURRENCY.
	 *
	 * @var array<string,string>
	 */
	private const OVERRIDES = array(
		'transaction-id' => 'id',
		'reference'      => 'reference',
		'amount'         => 'amount_in_cents',
		'currency'       => 'currency',
		'status'         => 'status',
	);

	/**
	 * Construye la petición.
	 *
	 * @param array<string,mixed>  $event     Evento del fixture.
	 * @param string               $secret    Secreto de eventos DE PRUEBA.
	 * @param string               $env       Entorno (ARALIA_ENV).
	 * @param string               $home      home_url() del sitio.
	 * @param array<string,string> $options   transaction-id, reference, amount, currency, status, tamper, base-url.
	 * @return array{url:string,headers:array<string,string>,body:string}
	 * @throws \InvalidArgumentException Si algo no es válido o el entorno no lo permite.
	 */
	public static function build( array $event, string $secret, string $env, string $home, array $options = array() ): array {
		if ( ! Environment::allows_replay( $env ) ) {
			throw new \InvalidArgumentException( 'Este comando solo corre en local, test o staging.' );
		}
		foreach ( array_keys( $options ) as $key ) {
			if ( ! in_array( $key, array_merge( array_keys( self::OVERRIDES ), array( 'tamper', 'base-url' ) ), true ) ) {
				throw new \InvalidArgumentException( 'Opción desconocida: ' . esc_html( (string) $key ) );
			}
		}

		// Cambios antes de firmar: el checksum resultante es VÁLIDO para el evento alterado.
		foreach ( self::OVERRIDES as $option => $field ) {
			if ( isset( $options[ $option ] ) && '' !== $options[ $option ] ) {
				if ( 'amount' === $option && ! ctype_digit( $options[ $option ] ) ) {
					throw new \InvalidArgumentException( '--amount debe ser un entero en centavos.' );
				}
				$event['data']['transaction'][ $field ] = 'amount' === $option ? (int) $options[ $option ] : $options[ $option ];
			}
		}
		if ( isset( $options['status'] ) && ! in_array( $options['status'], array( 'APPROVED', 'DECLINED', 'VOIDED', 'ERROR', 'PENDING' ), true ) ) {
			throw new \InvalidArgumentException( '--status debe ser APPROVED, DECLINED, VOIDED, ERROR o PENDING.' );
		}
		if ( isset( $options['currency'] ) && ! preg_match( '/^[A-Z]{3}$/', $options['currency'] ) ) {
			throw new \InvalidArgumentException( '--currency debe ser un código de 3 letras mayúsculas.' );
		}
		$signed = EventSigner::sign( $event, $secret );

		$tamper = $options['tamper'] ?? '';
		if ( 'checksum' === $tamper ) {
			// Firma inválida: el guard debe responder 401 sin cambiar nada (T-EVT-FORGED).
			$signed['signature']['checksum'] = strrev( $signed['signature']['checksum'] );
		} elseif ( '' !== $tamper ) {
			throw new \InvalidArgumentException( '--tamper solo admite checksum (para otro monto use --amount, que firma el monto alterado).' );
		}

		$base = $home;
		if ( isset( $options['base-url'] ) && '' !== $options['base-url'] ) {
			$parts = wp_parse_url( $options['base-url'] );
			$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
			if (
				! Environment::allows_test_tools( $env )
				|| ! is_array( $parts )
				|| ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
				|| isset( $parts['user'] ) || isset( $parts['pass'] )
				|| ! in_array( $host, self::ALLOWED_HOSTS, true )
			) {
				throw new \InvalidArgumentException( '--base-url solo se admite en local o test, con http(s), sin credenciales y hacia ' . esc_html( implode( ', ', self::ALLOWED_HOSTS ) ) . '.' );
			}
			$base = trailingslashit( $options['base-url'] );
		}

		return array(
			'url'     => add_query_arg( 'wc-api', 'wc_wompi', $base ),
			'headers' => array(
				'Content-Type'     => 'application/json',
				'X-Event-Checksum' => (string) $signed['signature']['checksum'],
			),
			'body'    => (string) wp_json_encode( $signed ),
		);
	}
}
