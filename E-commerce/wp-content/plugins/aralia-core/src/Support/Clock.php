<?php
/**
 * Reloj de la tienda (T163): toda la lógica que depende de "ahora" lee la hora de aquí.
 *
 * @package Aralia
 */

namespace Aralia\Support;

defined( 'ABSPATH' ) || exit;

/**
 * En producción y staging devuelve siempre la hora real. En local y test admite un desplazamiento (opción
 * `aralia_clock_offset`, en segundos) para que las pruebas simulen el paso del tiempo (T-HOLD-*, T-PSE-ABANDON) sin esperar:
 * los manejadores ven pasar el tiempo de verdad, no solo se disparan antes.
 */
final class Clock {

	public const OPTION = 'aralia_clock_offset';

	/**
	 * Hora actual (segundos Unix) con el desplazamiento de pruebas si procede.
	 *
	 * @param string|null $env Entorno (por defecto, ARALIA_ENV; el parámetro existe para probar la puerta).
	 * @return int
	 */
	public static function now( ?string $env = null ): int {
		return time() + self::offset( $env );
	}

	/**
	 * Desplazamiento vigente: 0 salvo en local/test.
	 *
	 * @param string|null $env Entorno.
	 * @return int
	 */
	public static function offset( ?string $env = null ): int {
		if ( ! Environment::allows_test_tools( $env ) ) {
			return 0;
		}
		$value = get_option( self::OPTION, 0 );
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Adelanta el reloj. Solo en local/test.
	 *
	 * @param int         $seconds Segundos a sumar (>= 0).
	 * @param string|null $env     Entorno.
	 * @return int Nuevo desplazamiento.
	 * @throws \RuntimeException Si el entorno no lo permite.
	 * @throws \InvalidArgumentException Si los segundos no son válidos.
	 */
	public static function advance( int $seconds, ?string $env = null ): int {
		if ( ! Environment::allows_test_tools( $env ) ) {
			throw new \RuntimeException( 'El reloj simulado solo se mueve en local o test.' );
		}
		if ( $seconds < 0 || $seconds > 365 * DAY_IN_SECONDS ) {
			throw new \InvalidArgumentException( 'Segundos fuera de rango.' );
		}
		$new = self::offset( $env ) + $seconds;
		update_option( self::OPTION, $new, false );
		return $new;
	}

	/**
	 * Vuelve a la hora real.
	 *
	 * @return void
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}
}
