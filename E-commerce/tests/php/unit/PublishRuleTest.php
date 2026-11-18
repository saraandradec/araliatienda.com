<?php
/**
 * Pruebas de la regla de publicación (T041).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Unit;

use Aralia\Catalog\PublishRule;
use PHPUnit\Framework\TestCase;

/**
 * Función pura `evaluate`.
 */
final class PublishRuleTest extends TestCase {

	/**
	 * Hechos completos de un producto simple.
	 *
	 * @param array<string,mixed> $over Cambios.
	 * @return array<string,mixed>
	 */
	private function facts( array $over = array() ): array {
		return array_merge(
			array(
				'has_image'      => true,
				'tone_dependent' => false,
				'has_color'      => false,
				'has_swatch'     => false,
				'manages_stock'  => true,
				'stock'          => 5,
				'variations'     => array(),
			),
			$over
		);
	}

	public function test_complete_simple_product_has_nothing_missing(): void {
		$this->assertSame( array(), PublishRule::evaluate( $this->facts() ) );
	}

	public function test_missing_image_unmanaged_stock_and_zero_stock_are_reported(): void {
		$this->assertSame( array( 'foto' ), PublishRule::evaluate( $this->facts( array( 'has_image' => false ) ) ) );
		$this->assertSame( array( 'stock_no_gestionado' ), PublishRule::evaluate( $this->facts( array( 'manages_stock' => false ) ) ) );
		$this->assertSame( array( 'sin_stock' ), PublishRule::evaluate( $this->facts( array( 'stock' => 0 ) ) ) );
	}

	public function test_color_requires_swatch(): void {
		$this->assertSame( array( 'swatch' ), PublishRule::evaluate( $this->facts( array( 'has_color' => true ) ) ) );
		$this->assertSame( array(), PublishRule::evaluate( $this->facts( array( 'has_color' => true, 'has_swatch' => true ) ) ) );
	}

	public function test_tone_dependent_without_variations_is_incomplete(): void {
		$missing = PublishRule::evaluate( $this->facts( array( 'tone_dependent' => true, 'manages_stock' => true, 'stock' => 5 ) ) );
		$this->assertContains( 'variaciones', $missing );
	}

	public function test_stock_is_evaluated_across_variations(): void {
		$ok  = array( 'has_swatch' => true, 'manages_stock' => true, 'stock' => 0 );
		$buy = array( 'has_swatch' => true, 'manages_stock' => true, 'stock' => 3 );
		$this->assertSame( array(), PublishRule::evaluate( $this->facts( array( 'tone_dependent' => true, 'variations' => array( $ok, $buy ) ) ) ), 'Basta una variación con stock' );
		$this->assertSame( array( 'sin_stock' ), PublishRule::evaluate( $this->facts( array( 'tone_dependent' => true, 'variations' => array( $ok ) ) ) ) );
		$no_swatch = array_merge( $buy, array( 'has_swatch' => false ) );
		$this->assertSame( array( 'swatch_variacion' ), PublishRule::evaluate( $this->facts( array( 'tone_dependent' => true, 'variations' => array( $buy, $no_swatch ) ) ) ) );
		$unmanaged = array_merge( $buy, array( 'manages_stock' => false ) );
		$this->assertContains( 'stock_no_gestionado', PublishRule::evaluate( $this->facts( array( 'tone_dependent' => true, 'variations' => array( $buy, $unmanaged ) ) ) ) );
	}
}
