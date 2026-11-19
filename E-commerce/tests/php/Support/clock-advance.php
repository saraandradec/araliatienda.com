<?php
/**
 * Script para `wp eval-file` (lo usa tests/e2e/support/clock.ts):
 *   wp eval-file wp-content/aralia-repo/tests/php/Support/clock-advance.php <hook> <minutos>
 *   wp eval-file wp-content/aralia-repo/tests/php/Support/clock-advance.php reset   (vuelve a la hora real)
 *
 * @package Aralia
 */

if ( ! \Aralia\Support\Environment::allows_test_tools() ) {
	WP_CLI::error( 'El reloj simulado solo corre en local o test.' );
}
require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php';

$aralia_args = isset( $args ) && is_array( $args ) ? $args : array();
if ( array( 'reset' ) === $aralia_args ) {
	\Aralia\Support\Clock::reset();
	WP_CLI::success( 'Reloj de la tienda en la hora real.' );
	return;
}
if ( 2 !== count( $aralia_args ) || ! ctype_digit( (string) $aralia_args[1] ) ) {
	WP_CLI::error( 'Uso: clock-advance.php <hook> <minutos> | reset' );
}
$aralia_ran = \Aralia\Tests\Support\ActionSchedulerClock::advance_and_run( (string) $aralia_args[0], (int) $aralia_args[1] );
WP_CLI::success( $aralia_ran . ' acción(es) ejecutada(s).' );
