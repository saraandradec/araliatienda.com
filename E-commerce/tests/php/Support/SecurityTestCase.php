<?php
/**
 * Base para pruebas de seguridad y privacidad: tablas limpias y cripto con llaves de prueba.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Support;

use Aralia\Database\Migrator;
use Aralia\Privacy\Crypto;

abstract class SecurityTestCase extends DatabaseTestCase {

	protected function set_up() {
		parent::set_up();
		( new Migrator() )->run();
	}

	protected function crypto(): Crypto {
		return new Crypto( base64_encode( str_repeat( 'a', 32 ) ), base64_encode( str_repeat( 'b', 32 ) ) );
	}

	/**
	 * Cliente HTTP falso que registra las llamadas.
	 *
	 * @param array<string,mixed>|\WP_Error $response Respuesta.
	 * @param array<int,array<string,mixed>> $calls   Registro (por referencia).
	 * @return callable
	 */
	protected function fake_http( $response, array &$calls ): callable {
		return static function ( string $url, array $args ) use ( $response, &$calls ) {
			$calls[] = array( 'url' => $url, 'args' => $args );
			return $response;
		};
	}

	/**
	 * Respuesta HTTP simulada.
	 *
	 * @param int    $code Código.
	 * @param string $body Cuerpo.
	 * @return array<string,mixed>
	 */
	protected function http_response( int $code, string $body ): array {
		return array(
			'response' => array( 'code' => $code, 'message' => '' ),
			'body'     => $body,
			'headers'  => array(),
		);
	}
}
