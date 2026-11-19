<?php
/**
 * Arranque del guard de Wompi.
 *
 * @package Aralia
 */

namespace Aralia\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Engancha las piezas. Idempotente.
 */
final class Guard {

	/**
	 * Evita arrancar dos veces.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Engancha llaves, TLS, neutralización y el manejador de eventos.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		Keys::register();
		Tls::register();
		Neutralize::register();
		EventHandler::register();
		Lookup::register();
		OrderPay::register();
	}
}
