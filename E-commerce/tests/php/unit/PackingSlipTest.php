<?php
/**
 * Hoja de empaque: construcción y render (T048).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Unit;

use Aralia\Orders\PackingSlip;
use PHPUnit\Framework\TestCase;

/**
 * Funciones puras `build` y `render`.
 */
final class PackingSlipTest extends TestCase {

	/**
	 * @param array<string,mixed> $over Cambios.
	 * @return array<string,mixed>
	 */
	private function data( array $over = array() ): array {
		return array_merge(
			array(
				'number'   => '1042',
				'date'     => '2026-11-18 10:30',
				'shipping' => array( 'name' => 'Ana Pérez', 'address' => 'Cra 7 # 10-20', 'city' => 'Bogotá', 'state' => 'DC', 'phone' => '3001234567', 'notes' => '' ),
				'lines'    => array(
					array( 'name' => 'Labial Rosa', 'sku' => 'LAB-001', 'tone' => '', 'qty' => 2, 'group_id' => '', 'group_label' => '', 'missing' => false ),
					array( 'name' => 'Corrector Miel', 'sku' => 'COR-001-MIE', 'tone' => 'Miel', 'qty' => 1, 'group_id' => 'g-1', 'group_label' => 'Neceser Aralía', 'missing' => false ),
					array( 'name' => 'Rubor Canela', 'sku' => 'RUB-001-CAN', 'tone' => 'Canela', 'qty' => 1, 'group_id' => 'g-1', 'group_label' => 'Neceser Aralía', 'missing' => false ),
				),
			),
			$over
		);
	}

	public function test_lines_are_grouped_by_group_id_with_loose_pieces_last(): void {
		$slip = PackingSlip::build( $this->data() );
		$this->assertCount( 2, $slip['groups'] );
		$this->assertSame( 'Neceser Aralía', $slip['groups'][0]['label'] );
		$this->assertCount( 2, $slip['groups'][0]['lines'] );
		$this->assertSame( 'Piezas sueltas', $slip['groups'][1]['label'] );
		$this->assertSame( 4, $slip['total_units'] );
		$this->assertFalse( $slip['has_missing'] );
	}

	public function test_a_group_without_label_gets_a_default_and_two_groups_stay_separate(): void {
		$data = $this->data( array( 'lines' => array(
			array( 'name' => 'A', 'qty' => 1, 'group_id' => 'x' ),
			array( 'name' => 'B', 'qty' => 1, 'group_id' => 'y', 'group_label' => 'Set Labios' ),
			array( 'name' => 'C', 'qty' => 1, 'group_id' => 'x' ),
		) ) );
		$slip = PackingSlip::build( $data );
		$this->assertSame( array( 'Set', 'Set Labios' ), array_column( $slip['groups'], 'label' ) );
		$this->assertCount( 2, $slip['groups'][0]['lines'], 'Se conserva el orden de aparición de los grupos' );
	}

	public function test_negative_or_missing_quantities_do_not_corrupt_the_totals(): void {
		$slip = PackingSlip::build( $this->data( array( 'lines' => array( array( 'name' => 'A', 'qty' => -5 ), array( 'name' => 'B' ), array( 'name' => 'C', 'qty' => 3 ) ) ) ) );
		$this->assertSame( 3, $slip['total_units'] );
	}

	public function test_render_shows_pieces_tones_quantities_and_shipping_data(): void {
		$html = PackingSlip::render( $this->data() );
		foreach ( array( 'Pedido #1042', 'Ana Pérez', 'Cra 7 # 10-20', 'Bogotá', '3001234567', 'Corrector Miel', 'Miel', 'LAB-001', 'Piezas sueltas', 'Neceser Aralía', '4 unidad' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, $needle );
		}
		$this->assertStringContainsString( '@media print', $html, 'Imprimible' );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'http', $html, 'Sin recursos externos' );
	}

	public function test_render_escapes_every_field_against_html_injection(): void {
		$evil = '<script>alert(1)</script>"\'<img src=x onerror=alert(2)>';
		$html = PackingSlip::render( $this->data( array(
			'number'   => $evil,
			'shipping' => array( 'name' => $evil, 'address' => $evil, 'city' => $evil, 'state' => $evil, 'phone' => $evil, 'notes' => $evil ),
			'lines'    => array( array( 'name' => $evil, 'sku' => $evil, 'tone' => $evil, 'qty' => 1, 'group_id' => 'g', 'group_label' => $evil ) ),
		) ) );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_missing_stock_lines_are_flagged_loudly(): void {
		$html = PackingSlip::render( $this->data( array( 'lines' => array( array( 'name' => 'Sin stock', 'qty' => 1, 'missing' => true ) ) ) ) );
		$this->assertStringContainsString( 'SIN STOCK', $html );
		$this->assertStringContainsString( 'No empacar', $html );
	}

	public function test_buyer_note_only_appears_when_there_is_one(): void {
		$this->assertStringNotContainsString( 'Nota de la compradora', PackingSlip::render( $this->data() ) );
		$with = $this->data();
		$with['shipping']['notes'] = 'Dejar con el portero';
		$this->assertStringContainsString( 'Nota de la compradora: Dejar con el portero', PackingSlip::render( $with ) );
	}
}
