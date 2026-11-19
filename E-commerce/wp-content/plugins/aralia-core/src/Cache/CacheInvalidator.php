<?php
/**
 * Invalidación de caché por etiqueta (T034, R-21).
 *
 * @package Aralia
 */

namespace Aralia\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Etiquetas: `ara_home`, `ara_p_<id>` (producto), `ara_c_<id>` (categoría), `ara_s_<id>` (sección). Al cambiar stock, precio, publicación,
 * términos o Full face se purgan el producto, sus categorías y secciones y la home en LiteSpeed (acción `litespeed_purge`) y en la CDN
 * (acción propia `aralia_cdn_purge`, que engancha el proveedor cuando se elija).
 */
class CacheInvalidator {

	public const TRANSIENT_PREFIX = 'aralia_';
	public const VERSION_OPTION   = 'aralia_cache_version';

	/**
	 * Etiquetas afectadas por un producto.
	 *
	 * @param int $product_id Producto (o variación: se usa su padre).
	 * @return string[]
	 */
	public static function tags_for_product( int $product_id ): array {
		$parent = (int) wp_get_post_parent_id( $product_id );
		$id     = $parent > 0 ? $parent : $product_id;
		$tags   = array( 'ara_home', 'ara_p_' . $id );
		foreach ( array(
			'product_cat'    => 'ara_c_',
			'aralia_seccion' => 'ara_s_',
		) as $taxonomy => $prefix ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
			foreach ( is_wp_error( $terms ) ? array() : $terms as $term_id ) {
				$tags[] = $prefix . (int) $term_id;
			}
		}
		return array_values( array_unique( $tags ) );
	}

	/**
	 * Purga las etiquetas dadas. Ignora cualquier etiqueta con un formato distinto.
	 *
	 * @param string[] $tags Etiquetas.
	 * @return string[] Etiquetas purgadas.
	 */
	public function purge_tags( array $tags ): array {
		$valid = array_values( array_unique( array_filter( $tags, static fn( $t ): bool => is_string( $t ) && (bool) preg_match( '/^ara_[a-z0-9_]{1,40}$/', $t ) ) ) );
		if ( ! $valid ) {
			return array();
		}
		foreach ( $valid as $tag ) {
			do_action( 'litespeed_purge', $tag ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción de LiteSpeed Cache.
		}
		do_action( 'aralia_cdn_purge', $valid );
		$this->bump_transient_version();
		return $valid;
	}

	/**
	 * Versión actual de los transients de caché: sube en cada purga. Funciona con caché de objetos persistente (Redis, LiteSpeed Object
	 * Cache), donde los transients no están en wp_options y no se pueden borrar con un LIKE.
	 *
	 * @return int
	 */
	public static function transient_version(): int {
		return max( 1, (int) get_option( self::VERSION_OPTION, 1 ) );
	}

	/**
	 * Nombre de transient versionado: `aralia_cache_v<N>_<nombre>`. Los transients de seguridad usan otro prefijo (`aralia_sec_`).
	 *
	 * @param string $name Nombre (solo [a-z0-9_]).
	 * @return string
	 * @throws \InvalidArgumentException Si el nombre no es válido.
	 */
	public static function transient_key( string $name ): string {
		if ( ! preg_match( '/^[a-z0-9_]{1,60}$/', $name ) ) {
			throw new \InvalidArgumentException( 'Nombre de transient inválido.' );
		}
		return self::TRANSIENT_PREFIX . 'cache_v' . self::transient_version() . '_' . $name;
	}

	/**
	 * Invalida todos los transients de caché subiendo la versión (los anteriores caducan solos).
	 *
	 * @return int Nueva versión.
	 */
	public function bump_transient_version(): int {
		$new = self::transient_version() + 1;
		update_option( self::VERSION_OPTION, $new, true );
		return $new;
	}

	/**
	 * Engancha los cambios de catálogo.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_update_product', array( $this, 'on_product_changed' ) );
		add_action( 'woocommerce_new_product', array( $this, 'on_product_changed' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_changed' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_changed' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'set_object_terms', array( $this, 'on_terms' ), 10, 1 );
		// Las purgas de una petición se juntan y se hacen una sola vez al terminar (cada línea de un pedido cambia stock).
		add_action( 'shutdown', array( $this, 'on_shutdown' ), 5 );
		// Las etiquetas se ponen en las respuestas que se cachean; sin ellas litespeed_purge no purga nada.
		add_action( 'wp', array( $this, 'on_wp' ) );
	}

	/**
	 * Fin de la petición: purga lo encolado.
	 *
	 * @return void
	 */
	public function on_shutdown(): void {
		$this->flush();
	}

	/**
	 * Petición de página: etiqueta la respuesta.
	 *
	 * @return void
	 */
	public function on_wp(): void {
		$this->tag_current_response();
	}

	/**
	 * Etiquetas pendientes de purgar en esta petición.
	 *
	 * @var string[]
	 */
	private array $queued = array();

	/**
	 * Encola etiquetas para purgarlas una sola vez al terminar la petición.
	 *
	 * @param string[] $tags Etiquetas.
	 * @return void
	 */
	public function queue( array $tags ): void {
		$this->queued = array_values( array_unique( array_merge( $this->queued, $tags ) ) );
	}

	/**
	 * Purga lo encolado (una vez por petición).
	 *
	 * @return string[] Etiquetas purgadas.
	 */
	public function flush(): array {
		$tags         = $this->queued;
		$this->queued = array();
		return $tags ? $this->purge_tags( $tags ) : array();
	}

	/**
	 * Etiquetas que se añaden a la respuesta actual (LiteSpeed las guarda con la página cacheada).
	 *
	 * @return string[] Etiquetas añadidas.
	 */
	public function tag_current_response(): array {
		$tags = array();
		if ( is_front_page() ) {
			$tags[] = 'ara_home';
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			$tags = array_merge( $tags, self::tags_for_product( (int) get_queried_object_id() ) );
		} elseif ( is_tax() || is_category() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$tags[] = ( 'aralia_seccion' === $term->taxonomy ? 'ara_s_' : 'ara_c_' ) . (int) $term->term_id;
			}
		}
		foreach ( $tags as $tag ) {
			do_action( 'litespeed_tag_add', $tag ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción de LiteSpeed Cache.
		}
		return $tags;
	}

	/**
	 * Producto guardado.
	 *
	 * @param int $product_id Producto.
	 * @return void
	 */
	public function on_product_changed( $product_id ): void {
		$this->queue( self::tags_for_product( (int) $product_id ) );
	}

	/**
	 * Stock cambiado (recibe el producto o la variación).
	 *
	 * @param \WC_Product|int $product Producto.
	 * @return void
	 */
	public function on_stock_changed( $product ): void {
		$id = is_object( $product ) ? (int) $product->get_id() : (int) $product;
		// Cambiar el stock no toca la home salvo que cambie lo visible: se purga el producto y sus categorías, no ara_home.
		$this->queue( array_values( array_diff( self::tags_for_product( $id ), array( 'ara_home' ) ) ) );
	}

	/**
	 * Publicación o despublicación de un producto.
	 *
	 * @param string   $new_status Estado nuevo.
	 * @param string   $old_status Estado anterior.
	 * @param \WP_Post $post Entrada.
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && 'product' === $post->post_type ) {
			$this->queue( self::tags_for_product( (int) $post->ID ) );
		}
	}

	/**
	 * Términos de un producto cambiados.
	 *
	 * @param int $object_id Objeto.
	 * @return void
	 */
	public function on_terms( $object_id ): void {
		if ( 'product' === get_post_type( (int) $object_id ) ) {
			$this->queue( self::tags_for_product( (int) $object_id ) );
		}
	}
}
