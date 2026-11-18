<?php
/**
 * Importador del inventario (T044, FR-065).
 *
 * @package Aralia
 */

namespace Aralia\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Importa filas YA validadas por `Validator` con la API CRUD de WooCommerce. Es idempotente por SKU: volver a importar actualiza.
 * Las filas incompletas (sin foto, swatch o stock) quedan en borrador; `PublishRule` vuelve a comprobarlo al publicar.
 */
final class Importer {

	/**
	 * Precios actuales por SKU (para el aviso de cambios > 20 %).
	 *
	 * @return array<string,int>
	 */
	public static function current_prices(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT s.meta_value AS sku, p.meta_value AS price FROM {$wpdb->postmeta} s LEFT JOIN {$wpdb->postmeta} p ON p.post_id = s.post_id AND p.meta_key = '_regular_price' WHERE s.meta_key = '_sku' AND s.meta_value <> ''", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['sku'] ] = (int) $row['price'];
		}
		return $out;
	}

	/**
	 * Importa.
	 *
	 * @param array<int,array<string,string>> $rows        Filas validadas (línea => fila).
	 * @param array<string,int>               $attachments Nombre de archivo (sin distinguir mayúsculas) => id de adjunto.
	 * @param string[]                        $confirmed   SKU cuyo cambio de precio > 20 % fue confirmado.
	 * @return array{created:int,updated:int,drafts:int,skipped_prices:string[],errors:string[],warnings:string[]}
	 */
	public static function import( array $rows, array $attachments = array(), array $confirmed = array() ): array {
		try {
			return self::run_import( $rows, $attachments, $confirmed );
		} finally {
			PublishRule::quiet( false ); // Pase lo que pase, los avisos vuelven a su estado normal.
			PublishRule::reset(); // Y un guardado interrumpido fuera del bucle no deja el contador de la regla desajustado.
		}
	}

	/**
	 * Cuerpo de la importación.
	 *
	 * @param array<int,array<string,string>> $rows        Filas validadas.
	 * @param array<string,int>               $attachments Adjuntos.
	 * @param string[]                        $confirmed   SKU confirmados.
	 * @return array{created:int,updated:int,drafts:int,skipped_prices:string[],errors:string[],warnings:string[]}
	 */
	private static function run_import( array $rows, array $attachments, array $confirmed ): array {
		$summary = array(
			'created'        => 0,
			'updated'        => 0,
			'drafts'         => 0,
			'skipped_prices' => array(),
			'errors'         => array(),
			'warnings'       => array(),
		);
		Taxonomies::seed();
		$attachments = array_change_key_case( $attachments, CASE_LOWER );
		$current     = self::current_prices();
		$parents     = array();
		$kids        = array();
		foreach ( $rows as $line => $row ) {
			if ( '' === $row['parent_sku'] ) {
				$parents[ $line ] = $row;
			} else {
				$kids[ $row['parent_sku'] ][ $line ] = $row;
			}
		}
		foreach ( $kids as $parent_sku => $orphans ) {
			if ( ! in_array( $parent_sku, array_column( $parents, 'sku' ), true ) ) {
				$summary['errors'][] = 'Variaciones sin su padre en el archivo (' . $parent_sku . '): ' . count( $orphans ) . ' filas omitidas.';
			}
		}
		$done = array();
		PublishRule::quiet( true );
		foreach ( $parents as $line => $row ) {
			$is_new  = false;
			$existed = (bool) wc_get_product_id_by_sku( $row['sku'] );
			try {
				$id = self::upsert_parent( $row, $kids[ $row['sku'] ] ?? array(), $attachments, $confirmed, $current, $summary, $is_new );
			} catch ( \Throwable $e ) {
				$summary['errors'][] = "Fila $line ({$row['sku']}): " . $e->getMessage();
				PublishRule::reset(); // Un guardado interrumpido no debe dejar la regla de publicación apagada para el resto del lote.
				PublishRule::quiet( true );
				// Si el producto ya existe (se creó antes de fallar), se marca para que la próxima importación vuelva a intentar publicarlo.
				$partial = (int) wc_get_product_id_by_sku( $row['sku'] );
				if ( $partial && ! $existed && 'publish' !== get_post_status( $partial ) ) {
					update_post_meta( $partial, PublishRule::BLOCKED_META, '1' );
				}
				continue;
			}
			$done[ $row['sku'] ] = $id;
			// Se intenta publicar SOLO lo nuevo o lo que la regla bloqueó antes: un borrador, privado o pendiente dejado a propósito no vuelve a la tienda.
			// La decisión final es siempre de la regla de publicación sobre lo ya guardado (no de las banderas de la fila).
			$status = get_post_status( $id );
			if ( 'publish' !== $status && ( $is_new || ( 'draft' === $status && '1' === get_post_meta( $id, PublishRule::BLOCKED_META, true ) ) ) ) {
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => 'publish',
					)
				);
			}
			if ( 'publish' !== get_post_status( $id ) ) {
				++$summary['drafts'];
			}
		}
		PublishRule::quiet( false );
		// Segunda pasada: «combina con» por SKU, solo productos (no variaciones) y solo de lo que se importó bien.
		foreach ( $parents as $row ) {
			if ( ! isset( $done[ $row['sku'] ] ) ) {
				continue;
			}
			$ids = array();
			foreach ( array_filter( array_map( 'trim', explode( '|', $row['combina_con'] ) ) ) as $sku ) {
				$ref = (int) wc_get_product_id_by_sku( $sku );
				if ( $ref && $ref !== $done[ $row['sku'] ] && 'product' === get_post_type( $ref ) ) {
					$ids[] = $ref;
				}
			}
			$ids = array_slice( array_values( array_unique( $ids ) ), 0, 2 );
			if ( 2 !== count( $ids ) ) {
				$summary['warnings'][] = $row['sku'] . ': «Combina con» quedó con ' . count( $ids ) . ' de 2 productos.';
			}
			$product = wc_get_product( $done[ $row['sku'] ] );
			if ( $product ) {
				$product->set_cross_sell_ids( $ids );
				$product->save();
			}
		}
		return $summary;
	}

	/**
	 * Adjunto de un nombre de archivo (sin distinguir mayúsculas).
	 *
	 * @param array<string,int> $attachments Adjuntos (claves en minúsculas).
	 * @param string            $name        Nombre.
	 * @return int
	 */
	private static function attachment( array $attachments, string $name ): int {
		return '' === $name ? 0 : (int) ( $attachments[ strtolower( $name ) ] ?? 0 );
	}

	/**
	 * Crea o actualiza un producto y sus variaciones.
	 *
	 * @param array<string,string>            $row         Fila del padre.
	 * @param array<int,array<string,string>> $variations  Filas de variaciones.
	 * @param array<string,int>               $attachments Adjuntos.
	 * @param string[]                        $confirmed   Cambios de precio confirmados.
	 * @param array<string,int>               $current     Precios actuales.
	 * @param array<string,mixed>             $summary     Resumen (por referencia).
	 * @param bool                            $is_new      Se pone a true si el producto se creó ahora (por referencia).
	 * @return int ID del producto.
	 */
	private static function upsert_parent( array $row, array $variations, array $attachments, array $confirmed, array $current, array &$summary, bool &$is_new = false ): int {
		$tone_dep = 'si' === $row['depende_tono'];
		$id       = (int) wc_get_product_id_by_sku( $row['sku'] );
		if ( $id && 'product' !== get_post_type( $id ) ) {
			throw new \RuntimeException( 'ese SKU pertenece a una variación de otro producto' );
		}
		$product = $id ? wc_get_product( $id ) : null;
		if ( $product && $tone_dep !== $product->is_type( 'variable' ) ) {
			throw new \RuntimeException( 'el producto existente cambia entre simple y variable; bórralo o corrige depende_tono' );
		}
		$is_new = ! $product;
		if ( ! $product ) {
			$product = $tone_dep ? new \WC_Product_Variable() : new \WC_Product_Simple();
			$product->set_sku( $row['sku'] );
			$product->set_status( 'draft' );
		}
		$product->set_name( sanitize_text_field( $row['nombre'] ) );
		$product->set_short_description( wp_kses_post( $row['descripcion_corta'] ) );
		// El padre variable NO gestiona stock: WooCommerce lo calcula con el de sus variaciones (si lo gestionara con cantidad nula, quedaría agotado).
		$product->set_manage_stock( ! $tone_dep );
		if ( $tone_dep ) {
			$product->set_stock_quantity( null );
		} else {
			self::set_price( $product, $row, $confirmed, $current, $summary );
			$product->set_stock_quantity( (int) $row['stock'] );
		}
		$cat_id = self::term_id( $row['categoria'], 'product_cat' );
		$sub_id = self::term_id( $row['subcategoria'], 'product_cat', $cat_id );
		$product->set_category_ids( array_filter( array( $cat_id, $sub_id ) ) );
		if ( self::attachment( $attachments, $row['foto'] ) ) {
			$product->set_image_id( self::attachment( $attachments, $row['foto'] ) );
		}
		if ( self::attachment( $attachments, $row['foto_uso'] ) ) {
			$product->set_gallery_image_ids( array( self::attachment( $attachments, $row['foto_uso'] ) ) );
		}
		$product->update_meta_data( '_ara_tone_dependent', $tone_dep ? '1' : '0' );
		$product->update_meta_data( '_ara_cost', (string) (int) $row['costo'] );
		$product->update_meta_data( '_ara_invima', $row['registro_sanitario'] );
		$product->update_meta_data( '_ara_how_to', $row['como_se_usa'] );
		$product->update_meta_data( '_ara_ingredients', $row['ingredientes'] );
		if ( self::attachment( $attachments, $row['swatch'] ) ) {
			$product->update_meta_data( '_ara_swatch_id', (string) self::attachment( $attachments, $row['swatch'] ) );
		}
		$id = $product->save();
		// Se cuenta aquí: si algo falla después, el producto ya existe y la importación (idempotente por SKU) puede repetirse.
		$is_new ? ++$summary['created'] : ++$summary['updated'];
		self::record_applied_price( $id, $product );

		self::set_terms( $id, 'pa_color', $row['color'] );
		self::set_terms( $id, 'pa_look', $row['look'] );
		self::set_terms( $id, 'aralia_seccion', $row['secciones'], true );
		self::set_terms( $id, 'aralia_paso', $row['paso_full_face'], true );
		if ( taxonomy_exists( 'product_brand' ) ) {
			wp_set_object_terms( $id, array( self::term_id( $row['marca'], 'product_brand' ) ), 'product_brand' );
		} else {
			$summary['warnings'][] = 'La taxonomía de marca de WooCommerce no existe: no se asignó la marca de ' . $row['sku'];
		}
		if ( $tone_dep ) {
			self::sync_variations( $id, $variations, $attachments, $confirmed, $current, $summary );
		}
		return $id;
	}

	/**
	 * Historial de precios con el precio REALMENTE aplicado (FR-061), no el de la fila.
	 *
	 * @param int         $id      Producto o variación.
	 * @param \WC_Product $product Objeto guardado.
	 * @return void
	 */
	private static function record_applied_price( int $id, \WC_Product $product ): void {
		$price = (int) $product->get_regular_price();
		if ( $price > 0 ) {
			ProductMeta::record_price( $id, $price );
		}
	}

	/**
	 * Fija el precio salvo que el cambio supere el 20 % sin confirmar (entonces se conserva el anterior y se informa).
	 *
	 * @param \WC_Product          $product   Producto o variación.
	 * @param array<string,string> $row       Fila.
	 * @param string[]             $confirmed Confirmados.
	 * @param array<string,int>    $current   Precios actuales.
	 * @param array<string,mixed>  $summary   Resumen.
	 * @return void
	 */
	private static function set_price( \WC_Product $product, array $row, array $confirmed, array $current, array &$summary ): void {
		$new  = (int) $row['precio'];
		$from = $current[ $row['sku'] ] ?? 0;
		if ( $from > 0 && abs( $new - $from ) / $from > 0.2 && ! in_array( $row['sku'], $confirmed, true ) ) {
			$summary['skipped_prices'][] = $row['sku'];
			return;
		}
		$product->set_regular_price( (string) $new );
	}

	/**
	 * Variaciones por tono.
	 *
	 * @param int                             $parent_id   Padre.
	 * @param array<int,array<string,string>> $rows        Filas de variaciones.
	 * @param array<string,int>               $attachments Adjuntos.
	 * @param string[]                        $confirmed   Confirmados.
	 * @param array<string,int>               $current     Precios actuales.
	 * @param array<string,mixed>             $summary     Resumen.
	 * @return void
	 */
	private static function sync_variations( int $parent_id, array $rows, array $attachments, array $confirmed, array $current, array &$summary ): void {
		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof \WC_Product_Variable || ! $rows ) {
			return; // Un padre sin filas de variación en este CSV no toca sus tonos.
		}
		$slugs = array();
		foreach ( $rows as $row ) {
			$tone_id = self::tone_term( $row['tono'], $row['subtono'] );
			if ( ! $tone_id ) {
				$summary['errors'][] = $row['sku'] . ': no se pudo crear la familia de tono ' . $row['tono'] . '.';
				continue;
			}
			$term = get_term( $tone_id, 'pa_tono' );
			$slug = $term instanceof \WP_Term ? $term->slug : sanitize_title( $row['tono'] );
			$vid  = (int) wc_get_product_id_by_sku( $row['sku'] );
			$var  = $vid ? wc_get_product( $vid ) : new \WC_Product_Variation();
			if ( $vid && ( ! $var instanceof \WC_Product_Variation || $var->get_parent_id() !== $parent_id ) ) {
				$summary['errors'][] = $row['sku'] . ': ese SKU ya pertenece a otro producto; se omitió.';
				continue;
			}
			if ( ! $var instanceof \WC_Product_Variation ) {
				continue;
			}
			$slugs[] = $tone_id;
			$var->set_parent_id( $parent_id );
			$var->set_sku( $row['sku'] );
			$var->set_attributes( array( 'pa_tono' => $slug ) );
			self::set_price( $var, $row, $confirmed, $current, $summary );
			$var->set_manage_stock( true );
			$var->set_stock_quantity( (int) $row['stock'] );
			// La foto de la variación es opcional (hereda la del padre); el swatch real es el de la variación o el de su familia de tono.
			if ( self::attachment( $attachments, $row['foto'] ) ) {
				$var->set_image_id( self::attachment( $attachments, $row['foto'] ) );
			}
			if ( self::attachment( $attachments, $row['swatch'] ) ) {
				$var->update_meta_data( '_ara_swatch_id', (string) self::attachment( $attachments, $row['swatch'] ) );
			}
			$var->update_meta_data( '_ara_cost', (string) (int) $row['costo'] );
			$var->save();
			self::record_applied_price( $var->get_id(), $var );
		}
		// Se UNEN los tonos de las variaciones que ya existían: una reimportación parcial no puede dejar tonos sin poder elegirse en la tienda.
		foreach ( $parent->get_children() as $child_id ) {
			$existing = get_term_by( 'slug', (string) get_post_meta( (int) $child_id, 'attribute_pa_tono', true ), 'pa_tono' );
			if ( $existing instanceof \WP_Term ) {
				$slugs[] = $existing->term_id;
			}
		}
		$attr = new \WC_Product_Attribute();
		$attr->set_id( (int) wc_attribute_taxonomy_id_by_name( 'tono' ) );
		$attr->set_name( 'pa_tono' );
		$attr->set_options( array_values( array_unique( $slugs ) ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$parent->set_attributes( array( $attr ) );
		wp_set_object_terms( $parent_id, array_values( array_unique( $slugs ) ), 'pa_tono' );
		$parent->save();
		\WC_Product_Variable::sync( $parent_id );
	}

	/**
	 * ID de un término, creándolo si no existe.
	 *
	 * @param string $name     Nombre.
	 * @param string $taxonomy Taxonomía.
	 * @param int    $parent_id Padre.
	 * @return int
	 */
	private static function term_id( string $name, string $taxonomy, int $parent_id = 0 ): int {
		$found = term_exists( $name, $taxonomy, $parent_id );
		if ( $found ) {
			return (int) ( is_array( $found ) ? $found['term_id'] : $found );
		}
		$new = wp_insert_term( $name, $taxonomy, $parent_id ? array( 'parent' => $parent_id ) : array() );
		return is_wp_error( $new ) ? 0 : (int) $new['term_id'];
	}

	/**
	 * Familia de tono (con su subtono la primera vez).
	 *
	 * @param string $tone    Nombre.
	 * @param string $subtone Subtono.
	 * @return int
	 */
	private static function tone_term( string $tone, string $subtone ): int {
		$found = term_exists( $tone, 'pa_tono' );
		if ( $found ) {
			return (int) ( is_array( $found ) ? $found['term_id'] : $found );
		}
		$id = self::term_id( $tone, 'pa_tono' );
		if ( $id ) {
			update_term_meta( $id, 'subtono', $subtone );
		}
		return $id;
	}

	/**
	 * Asigna términos de una lista `a|b`.
	 *
	 * @param int    $id       Producto.
	 * @param string $taxonomy Taxonomía.
	 * @param string $values   Lista.
	 * @param bool   $by_slug  Los valores son slugs.
	 * @return void
	 */
	private static function set_terms( int $id, string $taxonomy, string $values, bool $by_slug = false ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$ids = array();
		foreach ( array_filter( array_map( 'trim', explode( '|', $values ) ) ) as $value ) {
			$term  = $by_slug ? get_term_by( 'slug', $value, $taxonomy ) : get_term_by( 'name', $value, $taxonomy );
			$ids[] = $term instanceof \WP_Term ? $term->term_id : self::term_id( $value, $taxonomy );
		}
		wp_set_object_terms( $id, array_filter( $ids ), $taxonomy );
	}
}
