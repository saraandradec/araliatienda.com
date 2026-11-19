<?php
/**
 * Regla de publicación con WooCommerce real, por los caminos reales de guardado (T041).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Admin\IncompleteCatalog;
use Aralia\Catalog\PublishRule;
use Aralia\Catalog\Taxonomies;
use Aralia\Tests\Support\IsolatedTestCase;

/**
 * CRUD, wp_update_post, wc/v3, producto por tono, no despublicar por agotamiento y listado de incompletos.
 */
final class PublishRuleTest extends IsolatedTestCase {

	protected function setUp(): void {
		parent::setUp();
		PublishRule::reset();
		PublishRule::register();
		Taxonomies::seed();
		wp_set_current_user( (int) wp_insert_user( array( 'user_login' => 'pub' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'pub' . wp_rand() . '@example.com', 'role' => 'administrator' ) ) );
	}

	private function attachment(): int {
		$id = (int) wp_insert_attachment( array( 'post_title' => 'img', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ) );
		update_attached_file( $id, '2026/11/img-' . $id . '.png' );
		return $id;
	}

	private function simple( string $status, bool $image, int $stock, bool $manage = true ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Regla ' . wp_rand() );
		$product->set_regular_price( '10000' );
		$product->set_status( $status );
		$product->set_manage_stock( $manage );
		$product->set_stock_quantity( $stock );
		if ( $image ) {
			$product->set_image_id( $this->attachment() );
		}
		return $product;
	}

	public function test_a_complete_product_created_directly_as_publish_through_crud_stays_published(): void {
		$product = $this->simple( 'publish', true, 4 );
		$id      = $product->save();
		$this->assertSame( 'publish', get_post_status( $id ), 'El guardado CRUD escribe stock y foto DESPUÉS del cambio de estado: no debe revertirse' );
		$this->assertSame( 'publish', $product->get_status() );
		wp_delete_post( $id, true );
	}

	public function test_an_incomplete_product_saved_as_publish_reverts_in_db_and_in_memory(): void {
		$product = $this->simple( 'publish', false, 0 );
		$id      = $product->save();
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'draft', $product->get_status(), 'El objeto en memoria no miente (REST devolvería publish)' );
		$missing = IncompleteCatalog::items()[ $id ] ?? array();
		$this->assertContains( 'foto', $missing );
		$this->assertContains( 'sin_stock', $missing );
		wp_delete_post( $id, true );
	}

	public function test_a_draft_completed_and_published_later_through_crud_and_through_wp_update_post(): void {
		$product = $this->simple( 'draft', true, 2 );
		$id      = $product->save();
		$product->set_status( 'publish' );
		$product->save();
		$this->assertSame( 'publish', get_post_status( $id ) );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$this->assertSame( 'publish', get_post_status( $id ), 'wp_update_post sobre un producto completo' );
		wp_delete_post( $id, true );

		$bad = $this->simple( 'draft', false, 0 );
		$bid = $bad->save();
		wp_update_post( array( 'ID' => $bid, 'post_status' => 'publish' ) );
		$this->assertSame( 'draft', get_post_status( $bid ) );
		wp_delete_post( $bid, true );
	}

	public function test_wc_v3_post_with_publish_and_complete_data_is_published_and_incomplete_is_reported_as_draft(): void {
		$img = $this->attachment();
		$req = new \WP_REST_Request( 'POST', '/wc/v3/products' );
		$req->set_body_params( array( 'name' => 'Por REST', 'type' => 'simple', 'status' => 'publish', 'regular_price' => '9000', 'manage_stock' => true, 'stock_quantity' => 3, 'images' => array( array( 'id' => $img ) ) ) );
		$res = rest_do_request( $req );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'publish', get_post_status( (int) $res->get_data()['id'] ) );
		wp_delete_post( (int) $res->get_data()['id'], true );

		$req = new \WP_REST_Request( 'POST', '/wc/v3/products' );
		$req->set_body_params( array( 'name' => 'Por REST incompleto', 'type' => 'simple', 'status' => 'publish', 'regular_price' => '9000' ) );
		$res = rest_do_request( $req );
		$id  = (int) $res->get_data()['id'];
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'draft', $res->get_data()['status'], 'La respuesta REST dice lo mismo que la base de datos' );
		wp_delete_post( $id, true );
	}

	public function test_a_published_product_that_sells_out_is_not_unpublished(): void {
		$product = $this->simple( 'publish', true, 3 );
		$id      = $product->save();
		$product->set_stock_quantity( 0 );
		$product->save();
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Agotado' ) );
		$this->assertSame( 'publish', get_post_status( $id ), 'Agotarse no despublica' );
		$this->assertArrayNotHasKey( $id, IncompleteCatalog::items() );
		wp_delete_post( $id, true );
	}

	private function variable( bool $swatch, int $stock ): int {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable ' . wp_rand() );
		$parent->set_status( 'draft' );
		$parent->set_image_id( $this->attachment() );
		$parent->update_meta_data( '_ara_tone_dependent', '1' );
		$id = $parent->save();
		$var = new \WC_Product_Variation();
		$var->set_parent_id( $id );
		$var->set_attributes( array( 'pa_tono' => 'miel' ) );
		$var->set_regular_price( '5000' );
		$var->set_manage_stock( true );
		$var->set_stock_quantity( $stock );
		if ( $swatch ) {
			$var->update_meta_data( '_ara_swatch_id', (string) $this->attachment() );
		}
		$var->save();
		\WC_Product_Variable::sync( $id );
		return $id;
	}

	public function test_a_variation_without_a_real_swatch_blocks_publication_even_if_the_parent_has_a_photo(): void {
		$id = $this->variable( false, 5 );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$this->assertSame( 'draft', get_post_status( $id ), 'La foto heredada del padre no es el swatch (FR-066)' );
		$this->assertContains( 'swatch_variacion', IncompleteCatalog::items()[ $id ] );
		wp_delete_post( $id, true );
	}

	public function test_a_variation_takes_its_swatch_from_the_tone_family_and_a_complete_tone_product_publishes(): void {
		$tone = get_term_by( 'slug', 'miel', 'pa_tono' );
		update_term_meta( $tone->term_id, 'swatch_id', $this->attachment() );
		$id = $this->variable( false, 5 );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$facts = PublishRule::facts_for( wc_get_product( $id ) );
		$this->assertSame( array(), PublishRule::evaluate( $facts ), (string) wp_json_encode( $facts ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertTrue( wc_get_product( $id )->is_in_stock() );
		wp_delete_post( $id, true );
		delete_term_meta( $tone->term_id, 'swatch_id' );
	}

	public function test_incomplete_drafts_never_published_by_hand_are_listed_too(): void {
		$bad = $this->simple( 'draft', false, 0 );
		$id  = $bad->save();
		$this->assertArrayHasKey( $id, IncompleteCatalog::items(), 'Un borrador creado a mano, nunca publicado, también aparece' );
		wp_delete_post( $id, true );
	}
}
