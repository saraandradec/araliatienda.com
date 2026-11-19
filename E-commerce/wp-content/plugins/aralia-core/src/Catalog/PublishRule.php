<?php
/**
 * Regla de publicación de productos (T041, FR-066, R-09).
 *
 * @package Aralia
 */

namespace Aralia\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Solo en la TRANSICIÓN a `publish` se exige foto, swatch donde aplica, stock gestionado y stock > 0 (en el producto o en alguna variación).
 * Un producto ya publicado que se agota NO se despublica. Si falta algo, vuelve a borrador y el panel lo lista en "Catálogo incompleto"
 * (que calcula lo que falta al momento, no depende de un metadato).
 */
final class PublishRule {

	public const BLOCKED_META = '_ara_publish_blocked';

	/**
	 * Productos que acaban de pasar a `publish` y esperan a que el guardado termine para evaluarse (id => true).
	 *
	 * @var array<int,bool>
	 */
	private static array $pending = array();

	/**
	 * Profundidad de guardados CRUD de WooCommerce en curso: dentro de uno, los metadatos aún no están escritos.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Sin avisos al usuario (importaciones por lotes: el resumen ya lo cuenta).
	 *
	 * @var bool
	 */
	private static bool $quiet = false;

	/**
	 * Engancha la regla. WooCommerce dispara `transition_post_status` ANTES de escribir stock y foto (CRUD, editor y wc/v3), por eso solo se
	 * anota el producto y se evalúa al terminar el guardado: `woocommerce_after_product_object_save` (CRUD, editor, REST) o `wp_after_insert_post`.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'transition_post_status', array( self::class, 'on_transition' ), 10, 3 );
		add_action( 'woocommerce_before_product_object_save', array( self::class, 'before_save' ), 1 );
		add_action( 'woocommerce_after_product_object_save', array( self::class, 'after_save' ), 99 );
		add_action( 'wp_after_insert_post', array( self::class, 'after_insert' ), 99, 2 );
	}

	/**
	 * Estado interno a cero (pruebas).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$pending = array();
		self::$depth   = 0;
	}

	/**
	 * Activa o desactiva el modo silencioso.
	 *
	 * @param bool $quiet Silencioso.
	 * @return void
	 */
	public static function quiet( bool $quiet ): void {
		self::$quiet = $quiet;
	}

	/**
	 * Qué falta, a partir de hechos simples (función pura, probada en unitarias).
	 *
	 * @param array{has_image:bool,tone_dependent:bool,has_color:bool,has_swatch:bool,manages_stock:bool,stock:int,variations:array<int,array{has_swatch:bool,manages_stock:bool,stock:int}>} $facts Hechos.
	 * @return string[] Códigos de lo que falta (vacío = completo).
	 */
	public static function evaluate( array $facts ): array {
		$missing = array();
		if ( empty( $facts['has_image'] ) ) {
			$missing[] = 'foto';
		}
		$variations = $facts['variations'] ?? array();
		if ( ! empty( $facts['tone_dependent'] ) ) {
			if ( ! $variations ) {
				$missing[] = 'variaciones';
			}
			foreach ( $variations as $v ) {
				if ( empty( $v['has_swatch'] ) ) {
					$missing[] = 'swatch_variacion';
					break;
				}
			}
		} elseif ( ! empty( $facts['has_color'] ) && empty( $facts['has_swatch'] ) ) {
			$missing[] = 'swatch';
		}
		$stock_rows = $variations ? $variations : array(
			array(
				'manages_stock' => $facts['manages_stock'] ?? false,
				'stock'         => $facts['stock'] ?? 0,
			),
		);
		$managed    = true;
		$available  = false;
		foreach ( $stock_rows as $row ) {
			$managed   = $managed && ! empty( $row['manages_stock'] );
			$available = $available || (int) ( $row['stock'] ?? 0 ) > 0;
		}
		if ( ! $managed ) {
			$missing[] = 'stock_no_gestionado';
		}
		if ( ! $available ) {
			$missing[] = 'sin_stock';
		}
		return array_values( array_unique( $missing ) );
	}

	/**
	 * Hechos de un producto de WooCommerce.
	 *
	 * @param \WC_Product $product Producto.
	 * @return array<string,mixed>
	 */
	public static function facts_for( \WC_Product $product ): array {
		$id         = $product->get_id();
		$variations = array();
		foreach ( $product->is_type( 'variable' ) ? $product->get_children() : array() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child ) {
				continue;
			}
			$slug         = (string) get_post_meta( $child_id, 'attribute_pa_tono', true ); // El dato guardado: get_attributes() filtra por los atributos que declare el padre.
			$tone         = '' === $slug ? null : get_term_by( 'slug', $slug, 'pa_tono' );
			$tone_swatch  = $tone instanceof \WP_Term ? (int) get_term_meta( $tone->term_id, 'swatch_id', true ) : 0;
			$variations[] = array(
				// El swatch REAL: el de la variación o el de su familia de tono. La foto heredada del padre NO cuenta (FR-066).
				'has_swatch'    => (bool) ( (int) get_post_meta( $child_id, '_ara_swatch_id', true ) || $tone_swatch ),
				'manages_stock' => (bool) $child->get_manage_stock(),
				'stock'         => (int) $child->get_stock_quantity(),
			);
		}
		return array(
			'has_image'      => (bool) $product->get_image_id(),
			'tone_dependent' => (bool) get_post_meta( $id, '_ara_tone_dependent', true ),
			'has_color'      => (bool) wp_get_post_terms( $id, 'pa_color', array( 'fields' => 'ids' ) ),
			'has_swatch'     => (bool) get_post_meta( $id, '_ara_swatch_id', true ),
			'manages_stock'  => (bool) $product->get_manage_stock(),
			'stock'          => (int) $product->get_stock_quantity(),
			'variations'     => $variations,
		);
	}

	/**
	 * Anota la transición a `publish` desde otro estado (la regla se aplica solo ahí).
	 *
	 * @param string   $new_status Estado nuevo.
	 * @param string   $old_status Estado anterior.
	 * @param \WP_Post $post       Entrada.
	 * @return void
	 */
	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( $post instanceof \WP_Post && 'product' === $post->post_type && 'publish' === $new_status && 'publish' !== $old_status ) {
			self::$pending[ $post->ID ] = true;
		}
		// La regla revierte con SQL directo (sin transición): cualquier paso a borrador que llegue aquí es MANUAL y borra la marca de «bloqueado por la regla».
		if ( $post instanceof \WP_Post && 'product' === $post->post_type && 'draft' === $new_status && 'draft' !== $old_status && 'new' !== $old_status && 'auto-draft' !== $old_status ) {
			delete_post_meta( $post->ID, self::BLOCKED_META );
		}
	}

	/**
	 * Antes de un guardado CRUD: marca que los metadatos aún no están. Cubre además el caso en que WooCommerce cambia el estado con SQL
	 * directo dentro de `save_post` y no dispara la transición.
	 *
	 * @param \WC_Product $product Producto.
	 * @return void
	 */
	public static function before_save( $product ): void {
		++self::$depth;
		if ( $product instanceof \WC_Product && $product->get_id() && 'publish' === $product->get_status() && 'publish' !== get_post_status( $product->get_id() ) ) {
			self::$pending[ $product->get_id() ] = true;
		}
	}

	/**
	 * Tras un guardado CRUD: ya están el stock, la foto y los metadatos.
	 *
	 * @param \WC_Product $product Producto.
	 * @return void
	 */
	public static function after_save( $product ): void {
		self::$depth = max( 0, self::$depth - 1 );
		if ( $product instanceof \WC_Product && 0 === self::$depth ) {
			self::evaluate_pending( $product->get_id(), $product );
		}
	}

	/**
	 * Tras `wp_insert_post`: cubre los caminos que no pasan por el CRUD (p. ej. `wp_update_post`).
	 *
	 * @param int      $post_id Entrada.
	 * @param \WP_Post $post    Entrada.
	 * @return void
	 */
	public static function after_insert( $post_id, $post ): void {
		if ( 0 === self::$depth && $post instanceof \WP_Post && 'product' === $post->post_type ) {
			self::evaluate_pending( (int) $post_id, null );
		}
	}

	/**
	 * Evalúa un producto pendiente y, si falta algo, lo devuelve a borrador (también en el objeto en memoria, para que REST no mienta).
	 *
	 * @param int              $id      Producto.
	 * @param \WC_Product|null $product Objeto en memoria, si lo hay.
	 * @return void
	 */
	private static function evaluate_pending( int $id, ?\WC_Product $product ): void {
		if ( empty( self::$pending[ $id ] ) ) {
			return;
		}
		unset( self::$pending[ $id ] );
		if ( 'publish' !== get_post_status( $id ) && ! ( $product && 'publish' === $product->get_status() ) ) {
			return;
		}
		clean_post_cache( $id );
		$fresh   = wc_get_product( $id );
		$missing = $fresh ? self::evaluate( self::facts_for( $fresh ) ) : array();
		if ( ! $missing ) {
			delete_post_meta( $id, self::BLOCKED_META );
			return;
		}
		global $wpdb;
		// SQL directo: evita reentrar en los ganchos de guardado y no reaplica filtros de contenido.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );
		if ( $product ) {
			$product->set_status( 'draft' );
		}
		// Marca: «lo intentaron publicar y la regla lo bloqueó». Solo estos borradores se vuelven a intentar al reimportar.
		update_post_meta( $id, self::BLOCKED_META, '1' );
		if ( ! self::$quiet && get_current_user_id() ) {
			set_transient(
				'aralia_publish_blocked_' . get_current_user_id(),
				array(
					'id'      => $id,
					'missing' => $missing,
				),
				60
			);
		}
	}

	/**
	 * Etiquetas legibles.
	 *
	 * @var array<string,string>
	 */
	public const LABELS = array(
		'foto'                => 'falta la foto',
		'variaciones'         => 'depende del tono pero no tiene variaciones',
		'swatch_variacion'    => 'alguna variación no tiene swatch',
		'swatch'              => 'falta el swatch',
		'stock_no_gestionado' => 'el stock no está gestionado',
		'sin_stock'           => 'no hay stock',
	);
}
