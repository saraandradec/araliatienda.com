<?php
/**
 * Pantallas de catálogo: familias de tono y permisos (T042).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Admin\ToneFamilies;
use Aralia\Catalog\Taxonomies;
use Aralia\Tests\Support\IsolatedTestCase;

/**
 * El guardado exige nonce y permiso, y valida el subtono.
 */
final class AdminCatalogScreensTest extends IsolatedTestCase {

	private function user( string $role ): int {
		return (int) wp_insert_user( array( 'user_login' => $role . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => $role . wp_rand() . '@example.com', 'role' => $role ) );
	}

	public function test_tone_fields_save_with_nonce_and_permission_and_validate_subtone(): void {
		Taxonomies::seed();
		$term = (int) get_term_by( 'name', 'Marfil', 'pa_tono' )->term_id;
		wp_set_current_user( $this->user( 'administrator' ) );
		$img = (int) wp_insert_attachment( array( 'post_title' => 'sw', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ) );
		update_attached_file( $img, '2026/11/sw.png' );
		$_POST = array( ToneFamilies::NONCE => wp_create_nonce( ToneFamilies::NONCE ), 'aralia_subtono' => 'cálido', 'aralia_swatch_id' => (string) $img, 'aralia_order' => '3' );
		ToneFamilies::save( $term );
		$this->assertSame( 'cálido', get_term_meta( $term, 'subtono', true ) );
		$this->assertSame( (string) $img, (string) get_term_meta( $term, 'swatch_id', true ) );
		$_POST['aralia_swatch_id'] = '999999';
		ToneFamilies::save( $term );
		$this->assertSame( '0', (string) get_term_meta( $term, 'swatch_id', true ), 'Un ID que no es una imagen se descarta' );
		$_POST['aralia_swatch_id'] = (string) $img;
		ToneFamilies::save( $term );
		$this->assertSame( '3', (string) get_term_meta( $term, 'order', true ) );
		$_POST['aralia_subtono'] = 'verde';
		ToneFamilies::save( $term );
		$this->assertSame( '', (string) get_term_meta( $term, 'subtono', true ), 'Un subtono inválido se guarda vacío' );
		$_POST = array();
	}

	public function test_tone_fields_are_not_saved_without_nonce_or_without_permission(): void {
		Taxonomies::seed();
		$term = (int) get_term_by( 'name', 'Miel', 'pa_tono' )->term_id;
		$_POST = array( 'aralia_subtono' => 'frío', 'aralia_swatch_id' => '9', 'aralia_order' => '9' );
		wp_set_current_user( $this->user( 'administrator' ) );
		ToneFamilies::save( $term );
		$this->assertSame( 'cálido', get_term_meta( $term, 'subtono', true ), 'Sin nonce no cambia' );
		$_POST[ ToneFamilies::NONCE ] = wp_create_nonce( ToneFamilies::NONCE );
		wp_set_current_user( $this->user( 'customer' ) );
		$_POST[ ToneFamilies::NONCE ] = wp_create_nonce( ToneFamilies::NONCE );
		ToneFamilies::save( $term );
		$this->assertSame( 'cálido', get_term_meta( $term, 'subtono', true ), 'Una clienta no puede cambiarlo' );
		$_POST = array();
	}

	public function test_catalog_screens_are_restricted_to_manage_woocommerce(): void {
		wp_set_current_user( $this->user( 'customer' ) );
		foreach ( array( '\Aralia\Admin\IncompleteCatalog', '\Aralia\Admin\ImportScreen', '\Aralia\Admin\QuickProduct' ) as $class ) {
			add_filter( 'wp_die_handler', static fn() => static function (): void {
				throw new \RuntimeException( 'denied' );
			} );
			try {
				$class::render();
				$this->fail( "$class debe negar el acceso" );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'denied', $e->getMessage() );
			}
			remove_all_filters( 'wp_die_handler' );
		}
	}

	public function test_quick_product_rejects_an_existing_sku_and_accepts_a_new_valid_one(): void {
		Taxonomies::seed();
		$existing = new \WC_Product_Simple();
		$existing->set_name( 'Ya existe' );
		$existing->set_sku( 'QP-EXIST' );
		$id = $existing->save();
		$in = array( 'sku' => 'QP-EXIST', 'nombre' => 'Labial nuevo', 'categoria' => 'Labios', 'subcategoria' => 'Labial', 'marca' => 'Marca A', 'color' => 'rojos', 'look' => 'noche', 'combina_con' => 'QP-EXIST|QP-EXIST2', 'costo' => '7000', 'precio' => '22000', 'stock' => '4', 'depende_tono' => 'no' );
		$out = \Aralia\Admin\QuickProduct::prepare( $in, array( 'foto' => 'f.png', 'swatch' => 's.png' ) );
		$this->assertStringContainsString( 'ya existe', implode( ' ', $out['errors'] ) );
		$in['sku']         = 'QP-NEW';
		$in['combina_con'] = 'QP-EXIST|QP-EXIST';
		$out = \Aralia\Admin\QuickProduct::prepare( $in, array( 'foto' => 'f.png', 'swatch' => 's.png' ) );
		$this->assertStringContainsString( '2 SKU distintos', implode( ' ', $out['errors'] ) );
		wp_delete_post( $id, true );
	}

	public function test_wc_v3_cannot_write_the_private_catalog_keys(): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		\Aralia\Catalog\ProductMeta::register();
		$req = new \WP_REST_Request( 'POST', '/wc/v3/products' );
		$req->set_body_params(
			array(
				'name'      => 'Meta por REST',
				'type'      => 'simple',
				'status'    => 'draft',
				'meta_data' => array(
					array( 'key' => '_ara_cost', 'value' => '5' ),
					array( 'key' => '_ara_price_history', 'value' => 'x' ),
					array( 'key' => 'visible_meta', 'value' => 'ok' ),
				),
			)
		);
		$res = rest_do_request( $req );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$id = (int) $res->get_data()['id'];
		$this->assertSame( '', (string) get_post_meta( $id, '_ara_cost', true ), 'El costo no se escribe por wc/v3' );
		$this->assertSame( '', (string) get_post_meta( $id, '_ara_price_history', true ) );
		$this->assertSame( 'ok', get_post_meta( $id, 'visible_meta', true ) );
		wp_delete_post( $id, true );
	}

	private function product_with_cost(): int {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Con costo' );
		$p->set_status( 'draft' );
		$p->update_meta_data( '_ara_cost', '123' );
		$p->update_meta_data( '_ara_swatch_id', '77' );
		return $p->save();
	}

	public function test_wc_v3_update_restores_protected_keys_even_renamed_by_id_or_in_another_case(): void {
		global $wpdb;
		wp_set_current_user( $this->user( 'administrator' ) );
		\Aralia\Catalog\ProductMeta::register();
		$id  = $this->product_with_cost();
		$mid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_ara_cost'", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$put = function ( array $meta ) use ( $id ) {
			$req = new \WP_REST_Request( 'PUT', '/wc/v3/products/' . $id );
			$req->set_body_params( array( 'meta_data' => $meta ) );
			return rest_do_request( $req );
		};

		$this->assertSame( 200, $put( array( array( 'key' => '_ara_cost', 'value' => '999' ), array( 'key' => '_ara_swatch_id', 'value' => '1' ), array( 'key' => '_ara_publish_blocked', 'value' => '1' ) ) )->get_status() );
		$this->assertSame( '123', get_post_meta( $id, '_ara_cost', true ), 'Una actualización restaura el costo guardado' );
		$this->assertSame( '77', get_post_meta( $id, '_ara_swatch_id', true ) );
		$this->assertSame( '', (string) get_post_meta( $id, '_ara_publish_blocked', true ) );

		$put( array( array( 'id' => $mid, 'key' => 'otra_clave', 'value' => 'x' ) ) );
		$this->assertSame( '123', get_post_meta( $id, '_ara_cost', true ), 'Renombrar la fila por su id no la saca de la clave protegida' );
		$this->assertSame( '', (string) get_post_meta( $id, 'otra_clave', true ) );

		$put( array( array( 'key' => '_ARA_COST ', 'value' => '5' ) ) );
		$this->assertSame( '123', get_post_meta( $id, '_ara_cost', true ) );
		$json = (string) wp_json_encode( rest_get_server()->response_to_data( rest_do_request( new \WP_REST_Request( 'GET', '/wc/v3/products/' . $id ) ), false ) );
		$this->assertStringNotContainsString( '_ara_cost', $json );
		wp_delete_post( $id, true );
	}

	public function test_the_variations_generate_route_cannot_copy_protected_meta_and_the_experimental_v4_api_is_off(): void {
		\Aralia\Catalog\ProductMeta::register();
		$this->assertNotFalse( has_filter( 'rest_pre_dispatch', array( \Aralia\Catalog\ProductMeta::class, 'strip_generate_meta' ) ) );
		$req = new \WP_REST_Request( 'POST', '/wc/v3/products/5/variations/generate' );
		$req->set_param( 'meta_data', array( array( 'key' => '_ara_cost', 'value' => '1' ), array( 'key' => '_ARA_SWATCH_ID', 'value' => '2' ), array( 'key' => 'ok', 'value' => '3' ) ) );
		\Aralia\Catalog\ProductMeta::strip_generate_meta( null, rest_get_server(), $req );
		$this->assertSame( array( array( 'key' => 'ok', 'value' => '3' ) ), $req->get_param( 'meta_data' ) );
		$other = new \WP_REST_Request( 'POST', '/wc/v3/products/5/variations/batch' );
		$other->set_param( 'meta_data', array( array( 'key' => '_ara_cost', 'value' => '1' ) ) );
		\Aralia\Catalog\ProductMeta::strip_generate_meta( null, rest_get_server(), $other );
		$this->assertCount( 1, $other->get_param( 'meta_data' ), 'Otras rutas no se tocan aquí (las cubre el filtro por objeto)' );

		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			$this->assertFalse( \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'rest-api-v4' ), 'La API experimental wc/v4 no filtra el costo en sus respuestas: debe seguir apagada' );
		}
	}

	public function test_a_manual_move_to_draft_clears_the_blocked_mark_but_the_rule_revert_keeps_it(): void {
		\Aralia\Catalog\PublishRule::register();
		$p = new \WC_Product_Simple();
		$p->set_name( 'Marca' );
		$p->set_status( 'publish' );
		$id = $p->save();
		$this->assertSame( 'draft', get_post_status( $id ), 'Sin foto ni stock: la regla lo revierte' );
		$this->assertSame( '1', get_post_meta( $id, '_ara_publish_blocked', true ) );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'pending' ) );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assertSame( '', (string) get_post_meta( $id, '_ara_publish_blocked', true ), 'Una decisión manual de dejarlo en borrador borra la marca' );
		wp_delete_post( $id, true );
	}

	public function test_wc_v3_cannot_turn_a_public_row_into_a_protected_key_nor_leave_case_variants(): void {
		global $wpdb;
		wp_set_current_user( $this->user( 'administrator' ) );
		\Aralia\Catalog\ProductMeta::register();
		$id = $this->product_with_cost();
		update_post_meta( $id, '_ara_how_to', 'texto original' );
		$mid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_ara_how_to'", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$req = new \WP_REST_Request( 'PUT', '/wc/v3/products/' . $id );
		$req->set_body_params( array( 'meta_data' => array( array( 'id' => $mid, 'key' => '_ara_cost', 'value' => '999' ) ) ) );
		$this->assertSame( 200, rest_do_request( $req )->get_status() );
		$this->assertSame( array( '123' ), get_post_meta( $id, '_ara_cost', false ), 'No aparece un segundo _ara_cost' );
		$this->assertSame( 'texto original', get_post_meta( $id, '_ara_how_to', true ), 'La fila pública conserva su clave y su valor' );
		wp_delete_post( $id, true );

		$p = new \WC_Product_Simple();
		$p->set_name( 'Sin costo' );
		$p->set_status( 'draft' );
		$id2 = $p->save();
		$req = new \WP_REST_Request( 'PUT', '/wc/v3/products/' . $id2 );
		$req->set_body_params( array( 'meta_data' => array( array( 'key' => '_ARA_COST ', 'value' => '5' ), array( 'key' => array( 'raro' ), 'value' => 'x' ) ) ) );
		rest_do_request( $req );
		$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_ara_cost'", $id2 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 0, $rows, 'Ni siquiera como variante de mayúsculas o espacios (la colación de MySQL las iguala)' );
		wp_delete_post( $id2, true );
	}
}
