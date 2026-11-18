<?php
/**
 * Validador del CSV de inventario (T043, T046).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Unit;

use Aralia\Catalog\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Columnas, listas controladas, fórmulas, referencias y cambios de precio.
 */
final class ValidatorTest extends TestCase {

	private function csv( array $overrides = array(), array $extra_rows = array() ): string {
		$base = array_fill_keys( Validator::COLUMNS, '' );
		$base = array_merge(
			$base,
			array(
				'sku' => 'LAB-001', 'nombre' => 'Labial Rosa', 'categoria' => 'Labios', 'subcategoria' => 'Labial', 'marca' => 'Marca A',
				'depende_tono' => 'no', 'color' => 'rosas', 'look' => 'natural', 'combina_con' => 'AAA-111|BBB-222',
				'costo' => '8000', 'precio' => '20000', 'stock' => '10', 'foto' => 'a.png', 'swatch' => 's.png',
			),
			$overrides
		);
		$out = fopen( 'php://temp', 'r+' );
		fputcsv( $out, Validator::COLUMNS, ',', '"', '' );
		fputcsv( $out, array_values( $base ), ',', '"', '' );
		foreach ( $extra_rows as $r ) {
			fputcsv( $out, array_values( array_merge( $base, $r ) ), ',', '"', '' );
		}
		rewind( $out );
		return (string) stream_get_contents( $out );
	}

	private const PRICES = array( 'AAA-111' => 1000, 'BBB-222' => 1000 );

	public function test_a_valid_row_has_no_errors(): void {
		$r = Validator::validate( $this->csv(), self::PRICES );
		$this->assertSame( array(), $r['fatal'] );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( array(), $r['incomplete'] );
	}

	public function test_wrong_headers_and_non_utf8_and_empty_are_fatal(): void {
		$this->assertNotEmpty( Validator::validate( "sku,nombre\nA,B", self::PRICES )['fatal'] );
		$this->assertNotEmpty( Validator::validate( '', self::PRICES )['fatal'] );
		$this->assertNotEmpty( Validator::validate( "\xff\xfe", self::PRICES )['fatal'] );
		$this->assertNotEmpty( Validator::validate( str_repeat( 'a', Validator::MAX_CSV_BYTES + 1 ), self::PRICES )['fatal'] );
	}

	/**
	 * @dataProvider provide_formula_cells
	 */
	public function test_formula_injection_is_rejected( string $cell, bool $rejected ): void {
		$r = Validator::validate( $this->csv( array( 'descripcion_corta' => $cell ) ), self::PRICES );
		$this->assertSame( $rejected, isset( $r['errors'][2] ), $cell );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function provide_formula_cells(): array {
		return array(
			'igual'              => array( '=HYPERLINK("http://x","a")', true ),
			'suma'               => array( '+cmd|calc', true ),
			'menos con texto'    => array( '-2+3', true ),
			'arroba'             => array( '@SUM(A1)', true ),
			'tabulador y igual'  => array( "\t=cmd", true ),
			'tabulador solo'     => array( "\tcmd", false ),
			'número negativo'    => array( '-15', false ),
			'número con signo +' => array( '+15', false ),
			'texto normal'       => array( 'Hidrata y cubre', false ),
		);
	}

	public function test_controlled_lists_and_numbers_are_enforced(): void {
		$cases = array(
			array( 'categoria', 'Zapatos' ),
			array( 'color', 'verde' ),
			array( 'look', 'punk' ),
			array( 'paso_full_face', 'manos' ),
			array( 'secciones', 'inexistente' ),
			array( 'costo', '-5' ),
			array( 'precio', '0' ),
			array( 'precio', '12.5' ),
			array( 'stock', 'diez' ),
			array( 'depende_tono', 'tal vez' ),
			array( 'sku', 'ab' ),
			array( 'nombre', 'ab' ),
			array( 'foto', '../etc/passwd.png' ),
			array( 'swatch', 'carpeta/x.png' ),
			array( 'foto', 'script.php' ),
			array( 'registro_sanitario', str_repeat( 'x', 41 ) ),
			array( 'combina_con', 'AAA-111' ),
			array( 'combina_con', 'AAA-111|AAA-111' ),
			array( 'combina_con', 'AAA-111|NOEXISTE' ),
		);
		foreach ( $cases as $case ) {
			$r = Validator::validate( $this->csv( array( $case[0] => $case[1] ) ), self::PRICES );
			$this->assertArrayHasKey( 2, $r['errors'], $case[0] . '=' . $case[1] );
		}
	}

	public function test_a_tone_variation_requires_a_listed_tone_and_subtone(): void {
		$parent = array( 'sku' => 'CEJ-902', 'categoria' => 'Cejas', 'depende_tono' => 'si', 'precio' => '', 'stock' => '', 'costo' => '' );
		$kid    = array( 'sku' => 'CEJ-902-MIE', 'parent_sku' => 'CEJ-902', 'categoria' => 'Cejas', 'depende_tono' => 'si', 'tono' => 'Miel', 'subtono' => 'cálido', 'costo' => '1000', 'precio' => '9000', 'stock' => '2', 'swatch' => 's.png' );
		$this->assertSame( array(), Validator::validate( $this->csv( $parent, array( $kid ) ), self::PRICES )['errors'] );
		$this->assertArrayHasKey( 3, Validator::validate( $this->csv( $parent, array( array_merge( $kid, array( 'tono' => 'Verde' ) ) ) ), self::PRICES )['errors'] );
		$this->assertArrayHasKey( 3, Validator::validate( $this->csv( $parent, array( array_merge( $kid, array( 'subtono' => 'x' ) ) ) ), self::PRICES )['errors'] );
	}

	public function test_duplicate_sku_and_unknown_parent_are_errors(): void {
		$r = Validator::validate( $this->csv( array(), array( array() ) ), self::PRICES );
		$this->assertArrayHasKey( 3, $r['errors'] );
		$r = Validator::validate( $this->csv( array( 'parent_sku' => 'NOEXISTE' ) ), self::PRICES );
		$this->assertArrayHasKey( 2, $r['errors'] );
	}

	public function test_rows_without_photo_swatch_or_stock_are_incomplete_not_errors(): void {
		$r = Validator::validate( $this->csv( array( 'foto' => '', 'swatch' => '', 'stock' => '0' ) ), self::PRICES );
		$this->assertSame( array(), $r['errors'] );
		$this->assertEqualsCanonicalizing( array( 'foto', 'swatch', 'sin_stock' ), $r['incomplete'][2] );
	}

	public function test_price_warnings_and_changes_over_twenty_percent(): void {
		$r = Validator::validate( $this->csv( array( 'precio' => '20100' ) ), self::PRICES );
		$this->assertArrayHasKey( 2, $r['warnings'], 'No es múltiplo de 500' );
		$r = Validator::validate( $this->csv(), array_merge( self::PRICES, array( 'LAB-001' => 10000 ) ) );
		$this->assertSame( array( 'sku' => 'LAB-001', 'from' => 10000, 'to' => 20000 ), $r['price_changes'][2] );
		$r = Validator::validate( $this->csv(), array_merge( self::PRICES, array( 'LAB-001' => 19000 ) ) );
		$this->assertSame( array(), $r['price_changes'] );
	}

	public function test_quoted_cells_with_commas_and_newlines_are_parsed(): void {
		$r = Validator::validate( $this->csv( array( 'descripcion_corta' => "Hidrata, cubre\ny dura" ) ), self::PRICES );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( "Hidrata, cubre\ny dura", $r['rows'][2]['descripcion_corta'] );
	}

	public function test_safe_filename(): void {
		foreach ( array( 'a.png', 'Foto 1.JPG', 'x-y_z.webp' ) as $ok ) {
			$this->assertTrue( Validator::is_safe_filename( $ok ), $ok );
		}
		foreach ( array( '../a.png', 'a/b.png', 'a\\b.png', '.htaccess', 'a.php', 'a.png.php', "a\0.png", '', 'a..png' ) as $bad ) {
			$this->assertFalse( Validator::is_safe_filename( $bad ), $bad );
		}
	}

	public function test_tone_is_forbidden_in_lips_eyes_and_lashes_and_required_by_tone_steps(): void {
		foreach ( array( 'Labios', 'Ojos', 'Pestañas', 'Brochas', 'Accesorios' ) as $cat ) {
			$r = Validator::validate( $this->csv( array( 'categoria' => $cat, 'depende_tono' => 'si' ) ), self::PRICES );
			$this->assertArrayHasKey( 2, $r['errors'], "FR-019: $cat" );
		}
		$r = Validator::validate( $this->csv( array( 'categoria' => 'Piel', 'depende_tono' => 'no', 'paso_full_face' => 'piel-rubor' ) ), self::PRICES );
		$this->assertArrayHasKey( 2, $r['errors'], 'El rubor exige tono' );
		$r = Validator::validate( $this->csv( array( 'categoria' => 'Piel', 'depende_tono' => 'no', 'paso_full_face' => 'piel-polvo' ) ), self::PRICES );
		$this->assertSame( array(), $r['errors'] );
	}

	public function test_variable_parent_row_needs_no_tone_price_stock_or_cost_but_variations_do(): void {
		$parent = array( 'sku' => 'CEJ-900', 'categoria' => 'Cejas', 'depende_tono' => 'si', 'precio' => '', 'stock' => '', 'costo' => '', 'foto' => 'a.png', 'swatch' => '' );
		$kid    = array( 'sku' => 'CEJ-900-MIE', 'parent_sku' => 'CEJ-900', 'categoria' => 'Cejas', 'depende_tono' => 'si', 'tono' => 'Miel', 'subtono' => 'cálido', 'swatch' => 's.png', 'foto' => '', 'costo' => '1000', 'precio' => '9000', 'stock' => '2' );
		$r      = Validator::validate( $this->csv( $parent, array( $kid ) ), self::PRICES );
		$this->assertSame( array(), $r['errors'], (string) json_encode( $r['errors'] ) );
		$this->assertArrayNotHasKey( 2, $r['incomplete'], 'El padre no se marca incompleto por stock o swatch' );
		$bad = array_merge( $kid, array( 'tono' => 'Verde' ) );
		$this->assertArrayHasKey( 3, Validator::validate( $this->csv( $parent, array( $bad ) ), self::PRICES )['errors'] );
		$new = Validator::validate( $this->csv( $parent, array( array_merge( $kid, array( 'tono' => 'Nuevo' ) ) ) ), self::PRICES, array( 'Nuevo' => 'neutro' ) );
		$this->assertSame( array(), $new['errors'], 'Una familia nueva creada en el panel es válida' );
	}

	public function test_variation_rules_parent_must_be_in_the_csv_and_variable(): void {
		$kid = array( 'sku' => 'CEJ-901-MIE', 'parent_sku' => 'LAB-001', 'categoria' => 'Cejas', 'depende_tono' => 'si', 'tono' => 'Miel', 'subtono' => 'cálido', 'swatch' => 's.png', 'costo' => '1000', 'precio' => '9000', 'stock' => '2' );
		$this->assertArrayHasKey( 3, Validator::validate( $this->csv( array(), array( $kid ) ), self::PRICES )['errors'], 'El padre es simple' );
		$orphan = array_merge( $kid, array( 'parent_sku' => 'AAA-111' ) );
		$this->assertArrayHasKey( 3, Validator::validate( $this->csv( array(), array( $orphan ) ), self::PRICES )['errors'], 'El padre solo existe en el catálogo: debe venir en el CSV' );
		$no_tone = array_merge( $kid, array( 'depende_tono' => 'no' ) );
		$this->assertArrayHasKey( 3, Validator::validate( $this->csv( array(), array( $no_tone ) ), self::PRICES )['errors'] );
	}

	public function test_safe_filename_rejects_a_trailing_newline(): void {
		$this->assertFalse( Validator::is_safe_filename( "a.png\n" ) );
	}
}
