<?php
/**
 * Metadatos de producto (T039, data-model §1) y protección del costo interno.
 *
 * @package Aralia
 */

namespace Aralia\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * El costo (`_ara_cost`, clase I) nunca sale de la tienda: no se registra para REST, no está en la Store API, se quita de las respuestas
 * de `wc/v3` (WooCommerce incluye en `meta_data` todo metadato que no sea interno) y no se imprime en el HTML.
 */
final class ProductMeta {

	/**
	 * Claves que no pueden salir por ninguna API.
	 *
	 * @var string[]
	 */
	public const PRIVATE_KEYS = array( '_ara_cost', '_ara_price_history' );

	/**
	 * Engancha el registro y los filtros de REST.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_meta' ), 6 );
		add_filter( 'woocommerce_rest_prepare_product_object', array( self::class, 'strip_private_meta' ), 99 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( self::class, 'strip_private_meta' ), 99 );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( self::class, 'strip_private_meta' ), 99 );
		add_filter( 'woocommerce_product_export_skip_meta_keys', array( self::class, 'skip_export_keys' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'strip_generate_meta' ), 10, 3 );
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( self::class, 'strip_incoming_meta' ), 10, 2 );
		add_filter( 'woocommerce_rest_pre_insert_product_variation_object', array( self::class, 'strip_incoming_meta' ), 10, 2 );
	}

	/**
	 * Registra los metadatos con su saneamiento y sus permisos. Ninguno se expone en REST.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		$auth = static fn(): bool => current_user_can( 'manage_woocommerce' );
		$base = array(
			'object_subtype' => 'product',
			'single'         => true,
			'show_in_rest'   => false,
			'auth_callback'  => $auth,
		);
		$defs = array(
			'_ara_tone_dependent' => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'_ara_cost'           => array(
				'type'              => 'integer',
				'sanitize_callback' => static fn( $v ): int => max( 0, (int) $v ),
			),
			'_ara_swatch_id'      => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'_ara_invima'         => array(
				'type'              => 'string',
				'sanitize_callback' => static fn( $v ): string => mb_substr( sanitize_text_field( (string) $v ), 0, 40 ),
			),
			'_ara_how_to'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'_ara_ingredients'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'_ara_kind'           => array(
				'type'              => 'string',
				'sanitize_callback' => static fn( $v ): string => in_array( $v, array( 'neceser', 'loose', 'fixed' ), true ) ? (string) $v : '',
			),
			'_ara_components'     => array(
				'type'              => 'array',
				'sanitize_callback' => static fn( $v ): array => array_values( array_filter( array_map( 'absint', (array) $v ) ) ),
			),
			'_ara_price_history'  => array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_price_history' ),
			),
		);
		foreach ( $defs as $key => $def ) {
			register_post_meta( 'product', $key, array_merge( $base, $def ) );
			register_post_meta( 'product_variation', $key, array_merge( $base, $def ) );
		}
	}

	/**
	 * Historial de precios: lista de {precio, desde}.
	 *
	 * @param mixed $value Valor.
	 * @return array<int,array{price:int,since:string}>
	 */
	public static function sanitize_price_history( $value ): array {
		$out = array();
		foreach ( (array) $value as $row ) {
			if ( is_array( $row ) && isset( $row['price'], $row['since'] ) && (int) $row['price'] > 0 && false !== strtotime( (string) $row['since'] ) ) {
				$out[] = array(
					'price' => (int) $row['price'],
					'since' => gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $row['since'] ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * Quita de las escrituras por wc/v3 las claves privadas (costo, historial, swatch y marca de bloqueo): solo las escriben el importador y el alta rápida.
	 *
	 * @param mixed            $product Producto.
	 * @param \WP_REST_Request $request Petición.
	 * @return mixed
	 */
	public static function strip_incoming_meta( $product, $request ) {
		$meta = $request instanceof \WP_REST_Request ? $request->get_param( 'meta_data' ) : null;
		if ( ! is_array( $meta ) || ! $product instanceof \WC_Data ) {
			return $product;
		}
		$blocked = array_map( 'strtolower', self::blocked_keys() );
		$kept    = array();
		$touched = false;
		foreach ( $meta as $item ) {
			$key = is_array( $item ) && is_string( $item['key'] ?? '' ) ? strtolower( trim( (string) ( $item['key'] ?? '' ) ) ) : '';
			if ( ! in_array( $key, $blocked, true ) ) {
				$kept[] = $item;
				continue;
			}
			$touched = true;
		}
		// Una fila protegida también puede alterarse por su `id` (renombrándola con otra clave) o por una clave en otra capitalización:
		// se restauran SIEMPRE las filas protegidas con lo que hay guardado, sin importar cómo llegó el cambio.
		if ( $touched || count( $kept ) !== count( $meta ) || self::has_id_items( $meta ) ) {
			self::drop_foreign_protected_rows( $product );
			self::restore_protected( $product );
		}
		$request->set_param( 'meta_data', $kept );
		return $product;
	}

	/**
	 * Claves que no se escriben por la API.
	 *
	 * @return string[]
	 */
	private static function blocked_keys(): array {
		return array_merge( self::PRIVATE_KEYS, array( '_ara_swatch_id', PublishRule::BLOCKED_META ) );
	}

	/**
	 * Algún ítem trae `id` (puede renombrar una fila existente).
	 *
	 * @param array<int,mixed> $meta Ítems.
	 * @return bool
	 */
	private static function has_id_items( array $meta ): bool {
		foreach ( $meta as $item ) {
			if ( is_array( $item ) && ! empty( $item['id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Quita del objeto toda fila que lleve una clave protegida (en cualquier capitalización o con espacios) y no sea la fila guardada de esa
	 * clave: una fila pública renombrada por `id` a una clave protegida vuelve a su clave y valor originales; una fila nueva se descarta.
	 *
	 * @param \WC_Data $product Producto.
	 * @return void
	 */
	private static function drop_foreign_protected_rows( \WC_Data $product ): void {
		$protected = array_map( 'strtolower', self::blocked_keys() );
		foreach ( $product->get_meta_data() as $meta ) {
			// Los getters mágicos leen los cambios ya aplicados (get_data() devuelve el valor original).
			$data = array(
				'key' => $meta->key,
				'id'  => $meta->id,
			);
			$key  = (string) $data['key'];
			if ( ! in_array( strtolower( trim( $key ) ), $protected, true ) ) {
				continue;
			}
			$mid      = (int) ( $data['id'] ?? 0 );
			$original = $mid ? get_metadata_by_mid( 'post', $mid ) : false;
			if ( $original && $original->meta_key === $key ) {
				continue; // Es la fila real de la clave protegida: la restaura restore_protected.
			}
			if ( $original ) {
				$product->update_meta_data( (string) $original->meta_key, maybe_unserialize( $original->meta_value ), $mid ); // Vuelve a ser lo que era.
			} elseif ( $mid ) {
				$product->delete_meta_data_by_mid( $mid );
			} else {
				$product->delete_meta_data( $key ); // Fila nueva con la clave protegida (o una variante): se descarta.
			}
		}
	}

	/**
	 * Devuelve cada fila protegida (por clave e id) al valor guardado; si no existía, la descarta del objeto.
	 *
	 * @param \WC_Data $product Producto.
	 * @return void
	 */
	private static function restore_protected( \WC_Data $product ): void {
		global $wpdb;
		$post_id = $product->get_id();
		foreach ( self::blocked_keys() as $key ) {
			$rows = $post_id ? $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $rows ) {
				$product->delete_meta_data( $key );
				continue;
			}
			foreach ( $rows as $row ) {
				$product->update_meta_data( $key, maybe_unserialize( $row['meta_value'] ), (int) $row['meta_id'] );
			}
		}
	}

	/**
	 * `POST .../variations/generate` copia `meta_data` a todas las variaciones nuevas sin pasar por el filtro de cada variación.
	 *
	 * @param mixed            $result  Resultado previo.
	 * @param \WP_REST_Server  $server  Servidor.
	 * @param \WP_REST_Request $request Petición.
	 * @return mixed
	 */
	public static function strip_generate_meta( $result, $server, $request ) {
		unset( $server );
		if ( $request instanceof \WP_REST_Request && 1 === preg_match( '#^/wc/v\d+/products/\d+/variations/generate$#', $request->get_route() ) ) {
			$meta = $request->get_param( 'meta_data' );
			if ( is_array( $meta ) ) {
				$blocked = array_map( 'strtolower', self::blocked_keys() );
				$request->set_param(
					'meta_data',
					array_values(
						array_filter(
							$meta,
							static fn( $m ): bool => ! is_array( $m ) || ! is_string( $m['key'] ?? '' ) || ! in_array( strtolower( trim( (string) ( $m['key'] ?? '' ) ) ), $blocked, true )
						)
					)
				);
			}
		}
		return $result;
	}

	/**
	 * El exportador CSV nativo de WooCommerce no debe sacar el costo ni el historial.
	 *
	 * @param mixed $keys Claves omitidas.
	 * @return array<int,string>
	 */
	public static function skip_export_keys( $keys ): array {
		return array_values( array_unique( array_merge( is_array( $keys ) ? $keys : array(), self::PRIVATE_KEYS ) ) );
	}

	/**
	 * Quita el costo y el historial de las respuestas de wc/v3.
	 *
	 * @param \WP_REST_Response $response Respuesta.
	 * @return \WP_REST_Response
	 */
	public static function strip_private_meta( $response ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ) {
			$data['meta_data'] = array_values(
				array_filter(
					$data['meta_data'],
					static function ( $meta ): bool {
						$key = is_object( $meta ) ? ( $meta->key ?? ( method_exists( $meta, 'get_data' ) ? ( $meta->get_data()['key'] ?? '' ) : '' ) ) : ( $meta['key'] ?? '' );
						return ! in_array( (string) $key, self::PRIVATE_KEYS, true );
					}
				)
			);
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Historial de precios con el precio nuevo añadido si cambió (un precio solo se tacha si el anterior duró >= 14 días: FR-061).
	 *
	 * @param int      $product_id Producto.
	 * @param int      $new_price  Precio nuevo.
	 * @param int|null $now        Marca de tiempo (pruebas).
	 * @return array<int,array{price:int,since:string}>
	 */
	public static function record_price( int $product_id, int $new_price, ?int $now = null ): array {
		$history = self::sanitize_price_history( get_post_meta( $product_id, '_ara_price_history', true ) );
		$last    = $history ? end( $history ) : null;
		if ( null === $last || (int) $last['price'] !== $new_price ) {
			$history[] = array(
				'price' => $new_price,
				'since' => gmdate( 'Y-m-d H:i:s', $now ?? time() ),
			);
			update_post_meta( $product_id, '_ara_price_history', $history );
		}
		return $history;
	}
}
