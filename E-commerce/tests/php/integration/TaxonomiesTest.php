<?php
/**
 * Taxonomías y atributos del catálogo (T038).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Catalog\Taxonomies;
use Aralia\Tests\Support\IsolatedTestCase;

/**
 * El sembrado crea lo esperado y es idempotente.
 */
final class TaxonomiesTest extends IsolatedTestCase {

	public function test_seed_creates_categories_sections_steps_and_attributes_and_is_idempotent(): void {
		Taxonomies::seed();
		$again = Taxonomies::seed();
		$this->assertSame( array_fill_keys( array( 'categories', 'sections', 'steps', 'attributes', 'terms' ), 0 ), $again, 'La segunda ejecución no crea nada' );
		foreach ( Taxonomies::CATEGORIES as $name ) {
			$this->assertNotFalse( term_exists( $name, 'product_cat' ), $name );
		}
		$this->assertCount( 8, get_terms( array( 'taxonomy' => 'aralia_seccion', 'hide_empty' => false ) ) );
		$steps = get_terms( array( 'taxonomy' => 'aralia_paso', 'hide_empty' => false ) );
		$this->assertCount( 7, $steps );
		$needs = array();
		foreach ( $steps as $step ) {
			if ( (int) get_term_meta( $step->term_id, 'requires_tone', true ) ) {
				$needs[] = $step->slug;
			}
		}
		sort( $needs );
		$this->assertSame( array( 'cejas', 'piel-corrector', 'piel-rubor' ), $needs );
		foreach ( array( 'pa_tono', 'pa_color', 'pa_look', 'pa_tipo_bolsa' ) as $taxonomy ) {
			$this->assertTrue( taxonomy_exists( $taxonomy ), $taxonomy );
		}
	}

	public function test_tone_terms_carry_subtone_and_order_and_reject_invalid_subtone(): void {
		Taxonomies::seed();
		$term = get_term_by( 'name', 'Porcelana', 'pa_tono' );
		$this->assertSame( 'frío', get_term_meta( $term->term_id, 'subtono', true ) );
		$this->assertSame( '0', (string) get_term_meta( $term->term_id, 'order', true ) );
		update_term_meta( $term->term_id, 'subtono', 'verde' );
		$this->assertSame( '', (string) get_term_meta( $term->term_id, 'subtono', true ), 'Un subtono fuera de la lista se guarda vacío' );
	}

	public function test_price_history_is_recorded_only_when_the_price_changes(): void {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Hist' );
		$id = $p->save();
		\Aralia\Catalog\ProductMeta::record_price( $id, 10000, 1000 );
		\Aralia\Catalog\ProductMeta::record_price( $id, 10000, 2000 );
		$h = \Aralia\Catalog\ProductMeta::record_price( $id, 12000, 3000 );
		$this->assertCount( 2, $h );
		$this->assertSame( 12000, $h[1]['price'] );
		wp_delete_post( $id, true );
	}
}
