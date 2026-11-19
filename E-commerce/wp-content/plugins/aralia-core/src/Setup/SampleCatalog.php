<?php
/**
 * Catálogo de ejemplo para local y CI (T164). Nunca en producción.
 *
 * @package Aralia
 */

namespace Aralia\Setup;

use Aralia\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Crea las 7 categorías, los atributos globales de tono y color y unos productos de prueba. Idempotente.
 *
 * Pendiente (T038/T039): las taxonomías `aralia_seccion` y `aralia_paso` y los metadatos `_ara_*` se añaden aquí cuando existan.
 */
class SampleCatalog {

	public const MARKER = '_ara_sample';

	/**
	 * Categorías de primer nivel (data-model §1).
	 *
	 * @var string[]
	 */
	private const CATEGORIES = array( 'Labios', 'Cejas', 'Pestañas', 'Ojos', 'Piel', 'Brochas', 'Accesorios' );

	/**
	 * Instala lo que falte.
	 *
	 * @return array{categories:int,attributes:int,products:int}
	 */
	public function install(): array {
		$out = array(
			'categories' => 0,
			'attributes' => 0,
			'products'   => 0,
		);
		// Lista blanca: sin ARALIA_ENV, o con un valor desconocido ('prod', 'Production'), no se instala.
		if ( ! Environment::allows_test_tools() ) {
			throw new \RuntimeException( 'El catálogo de ejemplo solo se instala en local o test.' );
		}

		$cat_ids = array();
		foreach ( self::CATEGORIES as $name ) {
			$term = term_exists( $name, 'product_cat' );
			if ( ! $term ) {
				$term = wp_insert_term( $name, 'product_cat' );
				if ( is_wp_error( $term ) ) {
					continue;
				}
				++$out['categories'];
			}
			$cat_ids[ $name ] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}

		$attributes = array(
			'tono'  => 'Tono',
			'color' => 'Color',
		);
		foreach ( $attributes as $slug => $label ) {
			if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
				$id = wc_create_attribute(
					array(
						'name' => $label,
						'slug' => $slug,
					)
				);
				if ( ! is_wp_error( $id ) ) {
					++$out['attributes'];
				}
			}
		}
		// Las taxonomías de atributos se registran en el siguiente arranque; para crear términos hay que registrarlas ya.
		delete_transient( 'wc_attribute_taxonomies' );
		foreach ( array( 'pa_tono', 'pa_color' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy(
					$taxonomy,
					array( 'product' ),
					array(
						'hierarchical' => false,
						'public'       => false,
						'show_ui'      => false,
						'query_var'    => true,
						'rewrite'      => false,
					)
				);
			}
		}

		$out['products'] += $this->simple( 'Labial de ejemplo', 'SAMPLE-LAB-1', 35000, $cat_ids['Labios'] ?? 0 );
		$out['products'] += $this->simple( 'Gel de cejas de ejemplo', 'SAMPLE-CEJ-1', 28000, $cat_ids['Cejas'] ?? 0 );
		$out['products'] += $this->simple( 'Pestañina de ejemplo', 'SAMPLE-PES-1', 42000, $cat_ids['Pestañas'] ?? 0 );
		$out['products'] += $this->variable_by_tone( 'Base de ejemplo', 'SAMPLE-PIE-1', 55000, $cat_ids['Piel'] ?? 0, array( 'Claro', 'Medio', 'Oscuro' ) );
		return $out;
	}

	/**
	 * Producto simple si no existe su SKU.
	 *
	 * @param string $name  Nombre.
	 * @param string $sku   SKU.
	 * @param int    $price Precio en pesos.
	 * @param int    $cat   Categoría.
	 * @return int 1 si se creó.
	 */
	private function simple( string $name, string $sku, int $price, int $cat ): int {
		if ( wc_get_product_id_by_sku( $sku ) ) {
			return 0;
		}
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 20 );
		$product->set_status( 'publish' );
		if ( $cat ) {
			$product->set_category_ids( array( $cat ) );
		}
		$product->update_meta_data( self::MARKER, '1' );
		$product->save();
		return 1;
	}

	/**
	 * Producto variable por `pa_tono`.
	 *
	 * @param string   $name  Nombre.
	 * @param string   $sku   SKU base.
	 * @param int      $price Precio.
	 * @param int      $cat   Categoría.
	 * @param string[] $tones Tonos.
	 * @return int 1 si se creó.
	 */
	private function variable_by_tone( string $name, string $sku, int $price, int $cat, array $tones ): int {
		if ( wc_get_product_id_by_sku( $sku ) ) {
			return 0;
		}
		$attr_id = wc_attribute_taxonomy_id_by_name( 'tono' );
		if ( ! $attr_id ) {
			return 0;
		}
		foreach ( $tones as $tone ) {
			if ( ! term_exists( $tone, 'pa_tono' ) ) {
				wp_insert_term( $tone, 'pa_tono' );
			}
		}
		$product = new \WC_Product_Variable();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		if ( $cat ) {
			$product->set_category_ids( array( $cat ) );
		}
		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( $attr_id );
		$attribute->set_name( 'pa_tono' );
		$term_ids = array();
		foreach ( $tones as $tone ) {
			$term = get_term_by( 'name', $tone, 'pa_tono' );
			if ( ! $term instanceof \WP_Term ) {
				return 0;
			}
			$term_ids[] = (int) $term->term_id;
		}
		$attribute->set_options( $term_ids );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->update_meta_data( self::MARKER, '1' );
		$parent_id = $product->save();

		foreach ( $tones as $i => $tone ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( 'pa_tono' => sanitize_title( $tone ) ) );
			$variation->set_sku( $sku . '-' . ( $i + 1 ) );
			$variation->set_regular_price( (string) $price );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 10 );
			$variation->save();
		}
		\WC_Product_Variable::sync( $parent_id );
		return 1;
	}
}
