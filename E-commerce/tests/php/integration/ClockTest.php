<?php
/**
 * T163: reloj simulado de Action Scheduler.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Support\Clock;
use Aralia\Tests\Support\ActionSchedulerClock;
use Aralia\Tests\Support\IsolatedTestCase;

final class ClockTest extends IsolatedTestCase {

	private int $ran = 0;

	protected function set_up() {
		parent::set_up();
		as_unschedule_all_actions( 'aralia_order_expiry' );
		as_unschedule_all_actions( 'aralia_other_hook' );
		$this->ran = 0;
		Clock::reset();
		add_action( 'aralia_order_expiry', array( $this, 'tick' ) );
		add_action( 'aralia_other_hook', array( $this, 'tick' ) );
	}

	protected function tear_down() {
		remove_action( 'aralia_order_expiry', array( $this, 'tick' ) );
		remove_action( 'aralia_other_hook', array( $this, 'tick' ) );
		as_unschedule_all_actions( 'aralia_order_expiry' );
		as_unschedule_all_actions( 'aralia_other_hook' );
		Clock::reset();
		parent::tear_down();
	}

	public function tick(): void {
		++$this->ran;
	}

	public function test_nothing_runs_before_the_scheduled_time(): void {
		as_schedule_single_action( time() + 30 * MINUTE_IN_SECONDS, 'aralia_order_expiry', array( 1 ) );
		$this->assertSame( 0, ActionSchedulerClock::advance_and_run( 'aralia_order_expiry', 10 ) );
		$this->assertSame( 0, $this->ran );
	}

	public function test_advancing_past_the_hold_runs_only_that_hook(): void {
		as_schedule_single_action( time() + 30 * MINUTE_IN_SECONDS, 'aralia_order_expiry', array( 1 ) );
		as_schedule_single_action( time() + 30 * MINUTE_IN_SECONDS, 'aralia_other_hook', array( 2 ) );

		$this->assertSame( 1, ActionSchedulerClock::advance_and_run( 'aralia_order_expiry', 31 ) );
		$this->assertSame( 1, $this->ran, 'Solo corrió el hook pedido' );
		$this->assertNotFalse( as_next_scheduled_action( 'aralia_other_hook', array( 2 ) ), 'El otro hook sigue pendiente' );
	}

	/**
	 * @dataProvider provide_invalid
	 */
	public function test_rejects_invalid_input( string $hook, int $minutes ): void {
		$this->expectException( \InvalidArgumentException::class );
		ActionSchedulerClock::advance_and_run( $hook, $minutes );
	}

	/**
	 * @return array<string,array{string,int}>
	 */
	public static function provide_invalid(): array {
		return array(
			'sql'      => array( "x'; DROP TABLE y;--", 1 ),
			'empty'    => array( '', 1 ),
			'negative' => array( 'aralia_order_expiry', -1 ),
			'huge'     => array( 'aralia_order_expiry', 99999999 ),
		);
	}

	public function test_store_clock_moves_with_the_simulated_time_so_handlers_see_it_pass(): void {
		$before = Clock::now();
		$this->assertEqualsWithDelta( time(), $before, 2 );

		ActionSchedulerClock::advance_and_run( 'aralia_order_expiry', 31 );

		$this->assertEqualsWithDelta( time() + 31 * 60, Clock::now(), 2, 'Los manejadores deben ver pasar el tiempo, no solo dispararse antes' );
		Clock::reset();
		$this->assertEqualsWithDelta( time(), Clock::now(), 2 );
	}

	/**
	 * @dataProvider provide_blocked_envs
	 */
	public function test_clock_is_inert_outside_local_and_test( string $env ): void {
		// Una opción plantada en la base de datos de producción/staging no debe mover la hora.
		update_option( Clock::OPTION, 99999 );
		$this->assertSame( 0, Clock::offset( $env ), "offset debe ser 0 en '$env'" );
		$this->assertEqualsWithDelta( time(), Clock::now( $env ), 2 );
		try {
			Clock::advance( 60, $env );
			$this->fail( "advance debía lanzar en '$env'" );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'local o test', $e->getMessage() );
		}
		$this->assertSame( '99999', (string) get_option( Clock::OPTION ), 'advance no escribió nada' );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_blocked_envs(): array {
		return array(
			'production' => array( 'production' ),
			'staging'    => array( 'staging' ),
			'vacío'      => array( '' ),
			'prod'       => array( 'prod' ),
		);
	}

	/**
	 * @dataProvider provide_bad_seconds
	 */
	public function test_clock_rejects_invalid_seconds( int $seconds ): void {
		$this->expectException( \InvalidArgumentException::class );
		Clock::advance( $seconds, 'test' );
	}

	/**
	 * @return array<string,array{int}>
	 */
	public static function provide_bad_seconds(): array {
		return array(
			'negativo'  => array( -1 ),
			'excesivo'  => array( 365 * DAY_IN_SECONDS + 1 ),
		);
	}
}
