<?php
/**
 * Construcción de filas del alta rápida (T042).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Unit;

use Aralia\Admin\QuickProduct;
use Aralia\Catalog\Validator;
use PHPUnit\Framework\TestCase;

/**
 * El alta rápida produce las mismas filas que el contrato CSV.
 */
final class QuickProductTest extends TestCase {

	private function input( array $over = array() ): array {
		return array_merge(
			array(
				'sku' => 'LAB-900', 'nombre' => 'Labial Rojo', 'categoria' => 'Labios', 'subcategoria' => 'Labial', 'marca' => 'Marca A',
				'color' => 'rojos', 'look' => 'noche', 'combina_con' => 'AAA-111|BBB-222', 'costo' => '7000', 'precio' => '22000', 'stock' => '4',
				'depende_tono' => 'no',
			),
			$over
		);
	}

	private function validate( array $rows ): array {
		$out = fopen( 'php://temp', 'r+' );
		fputcsv( $out, Validator::COLUMNS, ',', '"', '' );
		foreach ( $rows as $r ) {
			fputcsv( $out, array_values( $r ), ',', '"', '' );
		}
		rewind( $out );
		return Validator::validate( (string) stream_get_contents( $out ), array( 'AAA-111' => 1, 'BBB-222' => 1 ) );
	}

	public function test_simple_product_is_one_valid_row(): void {
		$rows = QuickProduct::build_rows( $this->input(), array( 'foto' => 'f.png', 'swatch' => 's.png' ) );
		$this->assertCount( 1, $rows );
		$r = $this->validate( $rows );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( array(), $r['incomplete'] );
	}

	public function test_tone_product_builds_a_bare_parent_and_one_variation_per_line_with_the_tone_swatch(): void {
		$rows = QuickProduct::build_rows(
			$this->input( array( 'depende_tono' => 'si', 'categoria' => 'Cejas', 'tonos' => "Miel|LAB-900-MIE|22000|3|7000\nCanela|LAB-900-CAN|22000|2|7000\n\nlínea rota" ) ),
			array( 'foto' => 'f.png', 'foto_uso' => 'u.png', 'swatch_Miel' => 'miel.png', 'swatch_Canela' => 'canela.png' )
		);
		$this->assertCount( 3, $rows );
		foreach ( array( 'tono', 'subtono', 'precio', 'stock', 'costo', 'swatch' ) as $col ) {
			$this->assertSame( '', $rows[0][ $col ], "El padre no lleva $col" );
		}
		$this->assertSame( 'f.png', $rows[0]['foto'] );
		$this->assertSame( 'u.png', $rows[0]['foto_uso'] );
		$this->assertSame( 'LAB-900', $rows[1]['parent_sku'] );
		$this->assertSame( 'cálido', $rows[1]['subtono'] );
		$this->assertSame( 'miel.png', $rows[1]['swatch'] );
		$this->assertSame( 'canela.png', $rows[2]['swatch'], 'Cada tono con su propio swatch' );
		$this->assertSame( '', $rows[1]['foto'], 'La foto no se copia a las variaciones' );
		$r = $this->validate( $rows );
		$this->assertSame( array(), $r['errors'], (string) wp_json_encode_safe( $r['errors'] ) );
		$this->assertSame( array(), $r['incomplete'], 'Completo: nada se marca incompleto' );
	}

	public function test_missing_photo_and_swatch_make_it_incomplete_and_bad_input_is_an_error(): void {
		$r = $this->validate( QuickProduct::build_rows( $this->input() ) );
		$this->assertEqualsCanonicalizing( array( 'foto', 'swatch' ), $r['incomplete'][2] );
		$r = $this->validate( QuickProduct::build_rows( $this->input( array( 'precio' => '=1+1', 'categoria' => 'Zapatos' ) ), array( 'foto' => 'f.png', 'swatch' => 's.png' ) ) );
		$this->assertArrayHasKey( 2, $r['errors'] );
	}

	public function test_unknown_tone_line_is_reported_by_the_validator(): void {
		$rows = QuickProduct::build_rows( $this->input( array( 'depende_tono' => 'si', 'categoria' => 'Cejas', 'tonos' => 'Verde|LAB-900-VER|22000|3|7000' ) ) );
		$this->assertArrayHasKey( 3, $this->validate( $rows )['errors'] );
	}
}

/**
 * JSON sin depender de WordPress en las pruebas unitarias.
 *
 * @param mixed $value Valor.
 * @return string
 */
function wp_json_encode_safe( $value ): string {
	return (string) json_encode( $value );
}
