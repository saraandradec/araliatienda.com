<?php
/**
 * El costo interno no sale por ninguna API (T040, FR-067).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Catalog\ProductMeta;
use Aralia\Catalog\Taxonomies;
use Aralia\Tests\Support\IsolatedTestCase;

/**
 * `_ara_cost` y `_ara_price_history` no aparecen en wc/v3, la Store API ni los metadatos de REST.
 */
final class CostExposureTest extends IsolatedTestCase {

	private const MARKER = 987654;

	protected function setUp(): void {
		parent::setUp();
		ProductMeta::register();
		Taxonomies::register();
		do_action( 'init' );
		do_action( 'rest_api_init' );
	}

	private function product(): int {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Costo oculto' );
		$p->set_regular_price( '20000' );
		$p->set_status( 'publish' );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 5 );
		$img = (int) wp_insert_attachment( array( 'post_title' => 'img', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ) );
		update_attached_file( $img, '2026/11/img.png' );
		$p->set_image_id( $img );
		$p->update_meta_data( '_ara_cost', self::MARKER );
		$p->update_meta_data( '_ara_price_history', array( array( 'price' => 18000, 'since' => '2026-01-01 00:00:00' ) ) );
		$p->update_meta_data( 'visible_meta', 'ok' );
		return $p->save();
	}

	public function test_cost_is_not_in_wc_v3_responses(): void {
		$admin = (int) wp_insert_user( array( 'user_login' => 'adm' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'adm' . wp_rand() . '@example.com', 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$id  = $this->product();
		$res = rest_do_request( new \WP_REST_Request( 'GET', '/wc/v3/products/' . $id ) );
		$this->assertSame( 200, $res->get_status() );
		$json = wp_json_encode( rest_get_server()->response_to_data( $res, false ) );
		$this->assertStringNotContainsString( '_ara_cost', (string) $json );
		$this->assertStringNotContainsString( (string) self::MARKER, (string) $json );
		$this->assertStringNotContainsString( '_ara_price_history', (string) $json );
		$this->assertStringContainsString( 'visible_meta', (string) $json, 'Los demás metadatos siguen saliendo' );
		wp_delete_post( $id, true );
	}

	public function test_cost_is_not_in_the_store_api_nor_the_wp_posts_endpoint(): void {
		wp_set_current_user( 0 );
		$id = $this->product();
		$this->assertSame( 'publish', get_post_status( $id ), 'El producto de prueba está completo y publicado (si no, estas rutas darían 404 y la prueba no probaría nada)' );
		foreach ( array( '/wc/store/v1/products/' . $id, '/wp/v2/product/' . $id ) as $route ) {
			$res = rest_do_request( new \WP_REST_Request( 'GET', $route ) );
			$this->assertSame( 200, $res->get_status(), $route );
			$json = (string) wp_json_encode( rest_get_server()->response_to_data( $res, false ) );
			$this->assertStringNotContainsString( '_ara_cost', $json, $route );
			$this->assertStringNotContainsString( (string) self::MARKER, $json, $route );
		}
		wp_delete_post( $id, true );
	}

	public function test_cost_meta_is_registered_without_rest_and_requires_the_capability(): void {
		$registered = get_registered_meta_keys( 'post', 'product' );
		$this->assertArrayHasKey( '_ara_cost', $registered );
		$this->assertFalse( (bool) $registered['_ara_cost']['show_in_rest'] );
		wp_set_current_user( 0 );
		$id = $this->product();
		$this->assertFalse( current_user_can( 'edit_post_meta', $id, '_ara_cost' ) );
		wp_delete_post( $id, true );
	}

	public function test_cost_is_not_printed_in_the_product_page_html(): void {
		$id = $this->product();
		$this->assertStringNotContainsString( (string) self::MARKER, (string) apply_filters( 'the_content', get_post_field( 'post_content', $id ) ) );
		$product = wc_get_product( $id );
		$this->assertStringNotContainsString( (string) self::MARKER, (string) $product->get_price_html() . (string) $product->get_short_description() );
		wp_delete_post( $id, true );
	}

	public function test_cost_is_not_in_the_product_listing_variations_endpoint_nor_the_native_exporter(): void {
		wp_set_current_user( (int) wp_insert_user( array( 'user_login' => 'adm' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'adm' . wp_rand() . '@example.com', 'role' => 'administrator' ) ) );
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Costo variable' );
		$parent->set_status( 'publish' );
		$parent->update_meta_data( '_ara_cost', self::MARKER );
		$pid = $parent->save();
		$var = new \WC_Product_Variation();
		$var->set_parent_id( $pid );
		$var->set_regular_price( '5000' );
		$var->update_meta_data( '_ara_cost', self::MARKER );
		$vid = $var->save();

		foreach ( array( '/wc/v3/products', '/wc/v3/products/' . $pid . '/variations', '/wc/v3/products/' . $pid . '/variations/' . $vid ) as $route ) {
			$res  = rest_do_request( new \WP_REST_Request( 'GET', $route ) );
			$json = (string) wp_json_encode( rest_get_server()->response_to_data( $res, false ) );
			$this->assertStringNotContainsString( (string) self::MARKER, $json, $route );
		}
		$batch = new \WP_REST_Request( 'GET', '/wc/v3/products' );
		$batch->set_param( '_fields', 'id,meta_data' );
		$this->assertStringNotContainsString( (string) self::MARKER, (string) wp_json_encode( rest_get_server()->response_to_data( rest_do_request( $batch ), false ) ) );

		$skip = apply_filters( 'woocommerce_product_export_skip_meta_keys', array( 'otra' ) );
		$this->assertContains( '_ara_cost', $skip );
		$this->assertContains( 'otra', $skip );
		wp_delete_post( $pid, true );
	}
}
