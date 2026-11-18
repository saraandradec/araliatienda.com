<?php
/**
 * T164: catálogo de ejemplo (idempotente, nunca en producción).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Setup\SampleCatalog;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class SampleCatalogTest extends TestCase {

	private function purge(): void {
		$ids = get_posts(
			array(
				'post_type'   => array( 'product', 'product_variation' ),
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => SampleCatalog::MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	protected function set_up() {
		parent::set_up();
		$this->purge();
	}

	protected function tear_down() {
		$this->purge();
		parent::tear_down();
	}

	public function test_install_creates_catalog_once(): void {
		$first = ( new SampleCatalog() )->install();
		$this->assertSame( 4, $first['products'] );
		foreach ( array( 'Labios', 'Cejas', 'Pestañas', 'Ojos', 'Piel', 'Brochas', 'Accesorios' ) as $cat ) {
			$this->assertNotFalse( term_exists( $cat, 'product_cat' ), "Falta la categoría $cat" );
		}
		$this->assertNotEmpty( wc_attribute_taxonomy_id_by_name( 'tono' ) );
		$this->assertNotEmpty( wc_get_product_id_by_sku( 'SAMPLE-PIE-1' ) );

		$second = ( new SampleCatalog() )->install();
		$this->assertSame( 0, $second['products'], 'No duplica productos' );
		$this->assertSame( 0, $second['categories'] );
	}

	public function test_variable_product_has_one_variation_per_tone(): void {
		( new SampleCatalog() )->install();
		$product = wc_get_product( wc_get_product_id_by_sku( 'SAMPLE-PIE-1' ) );
		$this->assertInstanceOf( \WC_Product_Variable::class, $product );
		$this->assertCount( 3, $product->get_children() );
	}
}
