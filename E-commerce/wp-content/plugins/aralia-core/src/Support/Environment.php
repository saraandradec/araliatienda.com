<?php
/**
 * Entorno de ejecución (ARALIA_ENV), siempre con lista blanca.
 *
 * @package Aralia
 */

namespace Aralia\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Las herramientas de prueba solo funcionan en entornos declarados explícitamente. Si ARALIA_ENV no está definida,
 * está vacía o tiene un valor desconocido ('prod', 'Production'...), se tratan como NO permitidas.
 */
final class Environment {

	/**
	 * Entorno actual o '' si no está definido.
	 *
	 * @return string
	 */
	public static function current(): string {
		return defined( 'ARALIA_ENV' ) ? (string) constant( 'ARALIA_ENV' ) : '';
	}

	/**
	 * Entornos donde corren los comandos de prueba (replay de eventos, catálogo de ejemplo, reloj simulado).
	 * Staging solo para el replay (lo usan los E2E con Sandbox): ver allows_replay().
	 *
	 * @param string|null $env Entorno (por defecto, el actual).
	 * @return bool
	 */
	public static function allows_test_tools( ?string $env = null ): bool {
		$env = $env ?? self::current();
		return in_array( $env, array( 'local', 'test' ), true );
	}

	/**
	 * El replay de eventos firmados también se permite en staging (contra eventos de Sandbox).
	 *
	 * @param string|null $env Entorno.
	 * @return bool
	 */
	public static function allows_replay( ?string $env = null ): bool {
		$env = $env ?? self::current();
		return in_array( $env, array( 'local', 'test', 'staging' ), true );
	}
}
