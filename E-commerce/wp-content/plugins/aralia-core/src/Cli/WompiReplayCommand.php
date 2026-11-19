<?php
/**
 * Comando `wp aralia wompi replay` (T162).
 *
 * @package Aralia
 */

namespace Aralia\Cli;

use Aralia\Support\Environment;
use Aralia\Wompi\ReplayBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Reproduce un evento de Wompi firmado con el secreto de PRUEBA contra este mismo sitio (staging, local o CI).
 */
class WompiReplayCommand {

	/**
	 * Envía el evento de un fixture al manejador de eventos del sitio.
	 *
	 * ## OPTIONS
	 *
	 * <fixture>
	 * : Ruta del JSON con el evento (event, data.transaction..., opcionalmente signature.properties y timestamp).
	 *
	 * [--reference=<referencia>]
	 * : Referencia del pedido (formato `<id>-<intento>`), para firmar el evento de un pedido creado durante la prueba.
	 *
	 * [--transaction-id=<id>]
	 * : Id de transacción.
	 *
	 * [--amount=<centavos>]
	 * : Monto en centavos. Se cambia ANTES de firmar: checksum válido y monto distinto (T-EVT-AMOUNT).
	 *
	 * [--currency=<moneda>]
	 * : Moneda (T-EVT-CURRENCY).
	 *
	 * [--status=<estado>]
	 * : APPROVED, DECLINED, VOIDED, ERROR o PENDING.
	 *
	 * [--tamper=<campo>]
	 * : `checksum` firma bien y luego invierte el checksum (T-EVT-FORGED).
	 *
	 * [--base-url=<url>]
	 * : Dirección base si desde donde corre el comando no se llega a home_url() (en wp-env: http://tests-wordpress).
	 *   Solo en local y test y solo hacia localhost, 127.0.0.1, WordPress o tests-wordpress.
	 *
	 * [--format=<formato>]
	 * : `table` (por defecto) o `json`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aralia wompi replay tests/fixtures/wompi/approved.json --reference=1234-1 --amount=3500000
	 *     wp aralia wompi replay tests/fixtures/wompi/approved.json --tamper=checksum
	 *
	 * @param array<int,string>   $args       Argumentos posicionales.
	 * @param array<string,mixed> $assoc_args Argumentos con nombre.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ): void {
		$secret = defined( 'ARALIA_WOMPI_EVENTS_TEST' ) ? (string) constant( 'ARALIA_WOMPI_EVENTS_TEST' ) : '';
		if ( '' === $secret ) {
			\WP_CLI::error( 'Falta ARALIA_WOMPI_EVENTS_TEST (el secreto de eventos de pruebas).' );
		}
		$file = (string) $args[0];
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			\WP_CLI::error( 'No se puede leer el fixture: ' . $file );
		}
		$event = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fixture local.
		if ( ! is_array( $event ) ) {
			\WP_CLI::error( 'El fixture no es JSON válido.' );
		}

		$options = array();
		foreach ( array( 'reference', 'transaction-id', 'amount', 'currency', 'status', 'tamper', 'base-url' ) as $name ) {
			$value = \WP_CLI\Utils\get_flag_value( $assoc_args, $name, '' );
			if ( '' !== $value && null !== $value ) {
				$options[ $name ] = (string) $value;
			}
		}

		try {
			$request = ReplayBuilder::build( $event, $secret, Environment::current(), home_url( '/' ), $options );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		$response = wp_remote_post(
			$request['url'],
			array(
				'timeout'     => 15,
				'redirection' => 0, // Un host permitido no puede redirigir fuera de la lista.
				'headers'     => $request['headers'],
				'body'        => $request['body'],
			)
		);
		if ( is_wp_error( $response ) ) {
			\WP_CLI::error( 'No se pudo enviar: ' . $response->get_error_message() );
		}
		$row = array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
		\WP_CLI\Utils\format_items( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), array( $row ), array( 'status', 'body' ) );
	}
}
