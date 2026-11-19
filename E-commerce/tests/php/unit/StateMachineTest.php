<?php
/**
 * Máquina de estados del pedido: una prueba por transición permitida y por transición bloqueada (T055).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Unit;

use Aralia\Orders\StateMachine;
use PHPUnit\Framework\TestCase;

/**
 * Tabla de data-model §3. Es pura: no usa WordPress.
 */
final class StateMachineTest extends TestCase {

	private const STATES = array( 'checkout-draft', 'pending', 'processing', 'cancelled', 'ara-nostock', 'ara-packed', 'ara-shipped', 'completed', 'refunded', 'ara-reversed', 'auto-draft', 'new', 'failed', 'on-hold', 'trash' );

	/**
	 * Transiciones permitidas: desde, hacia, actor que sí puede.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function allowed(): array {
		return array(
			'borrador a pendiente (cualquiera)'     => array( 'checkout-draft', 'pending', '*' ),
			'auto-draft a pendiente (cualquiera)'   => array( 'auto-draft', 'pending', '*' ),
			'nuevo a pendiente (cualquiera)'        => array( 'new', 'pending', '*' ),
			'nuevo a borrador de checkout'          => array( 'new', 'checkout-draft', '*' ),
			'nuevo a auto-draft'                    => array( 'new', 'auto-draft', '*' ),
			'pendiente a pagado (guard)'            => array( 'pending', 'processing', 'payment' ),
			'pendiente a cancelado (vencimiento)'   => array( 'pending', 'cancelled', 'system' ),
			'cancelado a pagado tardío (guard)'     => array( 'cancelled', 'processing', 'payment' ),
			'cancelado a sin stock (guard)'         => array( 'cancelled', 'ara-nostock', 'payment' ),
			'sin stock a pagado (Sara)'             => array( 'ara-nostock', 'processing', 'staff' ),
			'sin stock a reembolsado (Sara)'        => array( 'ara-nostock', 'refunded', 'staff' ),
			'pagado a empacado (Sara)'              => array( 'processing', 'ara-packed', 'staff' ),
			'empacado a enviado (Sara)'             => array( 'ara-packed', 'ara-shipped', 'staff' ),
			'enviado a completado (Sara)'           => array( 'ara-shipped', 'completed', 'staff' ),
			'enviado a completado (automático)'     => array( 'ara-shipped', 'completed', 'system' ),
			'pagado a reembolsado (Sara)'           => array( 'processing', 'refunded', 'staff' ),
			'empacado a reembolsado (Sara)'         => array( 'ara-packed', 'refunded', 'staff' ),
			'enviado a reembolsado (Sara)'          => array( 'ara-shipped', 'refunded', 'staff' ),
			'completado a reembolsado (Sara)'       => array( 'completed', 'refunded', 'staff' ),
			'pagado a reembolsado (VOIDED válido)'  => array( 'processing', 'refunded', 'payment' ),
			'empacado a reembolsado (VOIDED)'       => array( 'ara-packed', 'refunded', 'payment' ),
			'pagado a revertido (Sara)'             => array( 'processing', 'ara-reversed', 'staff' ),
			'sin stock a revertido (Sara)'          => array( 'ara-nostock', 'ara-reversed', 'staff' ),
			'empacado a revertido (Sara)'           => array( 'ara-packed', 'ara-reversed', 'staff' ),
			'enviado a revertido (Sara)'            => array( 'ara-shipped', 'ara-reversed', 'staff' ),
			'completado a revertido (Sara)'         => array( 'completed', 'ara-reversed', 'staff' ),
		);
	}

	/**
	 * @dataProvider allowed
	 */
	public function test_allowed_transition_passes_for_its_actor( string $from, string $to, string $actor ): void {
		$this->assertTrue( StateMachine::is_allowed( $from, $to, '*' === $actor ? array() : array( $actor ) ) );
	}

	/**
	 * Cada transición permitida se niega a un actor que no le corresponde (nadie más marca un pedido como pagado).
	 *
	 * @dataProvider allowed
	 */
	public function test_allowed_transition_is_refused_to_other_actors( string $from, string $to, string $actor ): void {
		$table = StateMachine::table()[ $from . '>' . $to ];
		if ( in_array( '*', $table, true ) ) {
			$this->assertTrue( StateMachine::is_allowed( $from, $to, array() ), 'Transición abierta: no da derecho de pago' );
			return;
		}
		foreach ( array( 'payment', 'staff', 'system' ) as $other ) {
			if ( ! in_array( $other, $table, true ) ) {
				$this->assertFalse( StateMachine::is_allowed( $from, $to, array( $other ) ), "$other no debe poder $from>$to" );
			}
		}
		$this->assertFalse( StateMachine::is_allowed( $from, $to, array() ), 'Sin actor no hay transición' );
	}

	/**
	 * Todas las demás combinaciones desde-hacia quedan bloqueadas, con cualquier actor.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function blocked(): array {
		$out     = array();
		$allowed = array();
		foreach ( self::allowed() as $row ) {
			$allowed[ $row[0] . '>' . $row[1] ] = true;
		}
		foreach ( self::STATES as $from ) {
			foreach ( self::STATES as $to ) {
				if ( $from !== $to && ! isset( $allowed[ $from . '>' . $to ] ) ) {
					$out[ "$from a $to" ] = array( $from, $to );
				}
			}
		}
		return $out;
	}

	/**
	 * @dataProvider blocked
	 */
	public function test_every_other_transition_is_blocked_for_everyone( string $from, string $to ): void {
		$this->assertFalse( StateMachine::is_allowed( $from, $to, array( 'payment', 'staff', 'system' ) ) );
		$this->assertArrayNotHasKey( $from . '>' . $to, StateMachine::table() );
	}

	public function test_no_going_back_after_payment(): void {
		foreach ( array( 'processing', 'ara-packed', 'ara-shipped', 'completed' ) as $paid ) {
			foreach ( array( 'cancelled', 'pending' ) as $back ) {
				$this->assertFalse( StateMachine::is_allowed( $paid, $back, array( 'payment', 'staff', 'system' ) ), "$paid>$back debe estar bloqueada (DECLINED posterior)" );
			}
		}
	}

	public function test_payment_states_are_unreachable_without_the_guard(): void {
		foreach ( array( 'checkout-draft', 'auto-draft', 'new', 'pending', 'cancelled', 'failed', 'on-hold', 'trash' ) as $from ) {
			foreach ( array( 'processing', 'ara-packed', 'ara-shipped', 'completed' ) as $paid ) {
				foreach ( array( array( 'staff' ), array( 'system' ), array() ) as $actors ) {
					$this->assertFalse( StateMachine::is_allowed( $from, $paid, $actors ), "$from>$paid sin el guard" );
				}
			}
		}
		$this->assertFalse( StateMachine::is_allowed( 'pending', 'failed', array( 'payment', 'staff', 'system' ) ), 'failed y on-hold no se modelan: se bloquean y alertan' );
		$this->assertFalse( StateMachine::is_allowed( 'pending', 'on-hold', array( 'payment', 'staff', 'system' ) ) );
	}

	public function test_same_status_is_not_a_transition_and_is_always_accepted(): void {
		$this->assertTrue( StateMachine::is_allowed( 'pending', 'pending', array() ) );
	}

	public function test_refunded_and_reversed_are_terminal(): void {
		foreach ( self::STATES as $to ) {
			if ( 'refunded' !== $to ) {
				$this->assertArrayNotHasKey( 'refunded>' . $to, StateMachine::table() );
			}
			if ( 'ara-reversed' !== $to ) {
				$this->assertArrayNotHasKey( 'ara-reversed>' . $to, StateMachine::table() );
			}
		}
	}
}
