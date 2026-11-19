<?php
/**
 * Taxonomías y atributos del catálogo (T038, data-model §1-2).
 *
 * @package Aralia
 */

namespace Aralia\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * - `product_cat`: las 7 categorías de primer nivel.
 * - `aralia_seccion` (8) y `aralia_paso` (7, con `requires_tone`): taxonomías propias de producto.
 * - Atributos globales `pa_tono` (swatch, subtono, orden en el término), `pa_color`, `pa_look` y `pa_tipo_bolsa`.
 * La marca es la taxonomía nativa `product_brand` de WooCommerce.
 */
final class Taxonomies {

	public const CATEGORIES = array( 'Labios', 'Cejas', 'Pestañas', 'Ojos', 'Piel', 'Brochas', 'Accesorios' );

	/**
	 * Secciones (slug => nombre).
	 *
	 * @var array<string,string>
	 */
	public const SECTIONS = array(
		'full-face'        => 'Full face',
		'principiantes'    => 'Principiantes',
		'makeup-no-makeup' => 'Makeup no makeup',
		'cejas-perfectas'  => 'Cejas perfectas',
		'labios'           => 'Labios',
		'retoque'          => 'Retoque',
		'surprised-bag'    => 'Surprised bag',
		'neceser'          => 'Neceser',
	);

	/**
	 * Pasos de Full face (slug => [nombre, requiere tono]).
	 *
	 * @var array<string,array{0:string,1:bool}>
	 */
	public const STEPS = array(
		'piel-corrector' => array( 'Piel: corrector', true ),
		'piel-rubor'     => array( 'Piel: rubor', true ),
		'piel-polvo'     => array( 'Piel: polvo', false ),
		'cejas'          => array( 'Cejas', true ),
		'ojos'           => array( 'Ojos', false ),
		'pestanas'       => array( 'Pestañas', false ),
		'labios'         => array( 'Labios', false ),
	);

	/**
	 * Familias de tono iniciales (nombre => subtono).
	 *
	 * @var array<string,string>
	 */
	public const TONES = array(
		'Porcelana' => 'frío',
		'Marfil'    => 'neutro',
		'Miel'      => 'cálido',
		'Canela'    => 'cálido',
		'Caramelo'  => 'cálido',
		'Espresso'  => 'neutro',
	);

	public const SUBTONES = array( 'frío', 'neutro', 'cálido' );
	public const COLORS   = array( 'nudes', 'rosas', 'rojos', 'berry', 'cobre y dorados', 'light', 'medium' );
	public const LOOKS    = array( 'natural', 'clásico', 'noche', 'atrevido' );

	/**
	 * Atributos globales (slug => etiqueta).
	 *
	 * @var array<string,string>
	 */
	public const ATTRIBUTES = array(
		'tono'       => 'Tono',
		'color'      => 'Color',
		'look'       => 'Look',
		'tipo_bolsa' => 'Tipo de bolsa',
	);

	/**
	 * Engancha el registro.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_taxonomies' ), 5 );
		add_action( 'init', array( self::class, 'register_term_meta' ), 6 );
	}

	/**
	 * Registra `aralia_seccion` y `aralia_paso` y los atributos que ya existen en la base de datos.
	 *
	 * @return void
	 */
	public static function register_taxonomies(): void {
		$common = array(
			'public'            => false,
			'show_ui'           => true,
			'show_in_nav_menus' => false,
			'show_admin_column' => true,
			'hierarchical'      => false,
			'show_in_rest'      => false,
			'rewrite'           => false,
			'capabilities'      => array(
				'manage_terms' => 'manage_woocommerce',
				'edit_terms'   => 'manage_woocommerce',
				'delete_terms' => 'manage_woocommerce',
				'assign_terms' => 'edit_products',
			),
		);
		register_taxonomy( 'aralia_seccion', array( 'product' ), array_merge( $common, array( 'label' => 'Secciones' ) ) );
		register_taxonomy( 'aralia_paso', array( 'product' ), array_merge( $common, array( 'label' => 'Pasos de Full face' ) ) );
		self::register_attribute_taxonomies();
	}

	/**
	 * Registra `pa_*` si WooCommerce aún no lo hizo (la primera vez que se crea el atributo en la misma petición).
	 *
	 * @return void
	 */
	public static function register_attribute_taxonomies(): void {
		foreach ( array_keys( self::ATTRIBUTES ) as $slug ) {
			$taxonomy = 'pa_' . $slug;
			if ( taxonomy_exists( $taxonomy ) || ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) || ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
				continue;
			}
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

	/**
	 * Metadatos de términos (swatch, subtono, orden, requiere tono).
	 *
	 * @return void
	 */
	public static function register_term_meta(): void {
		$int  = array(
			'type'              => 'integer',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => 'absint',
		);
		$bool = array(
			'type'              => 'boolean',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		);
		register_term_meta( 'aralia_paso', 'requires_tone', $bool );
		register_term_meta( 'aralia_seccion', 'header_image_id', $int );
		register_term_meta(
			'aralia_seccion',
			'proposal_line',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_term_meta( 'aralia_seccion', 'visible', $bool );
		register_term_meta( 'pa_tono', 'swatch_id', $int );
		register_term_meta( 'pa_tono', 'order', $int );
		register_term_meta(
			'pa_tono',
			'subtono',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn( $v ): string => in_array( $v, self::SUBTONES, true ) ? (string) $v : '',
			)
		);
	}

	/**
	 * Familias de tono vigentes (nombre => subtono), leídas de los términos de `pa_tono` (FR-068: se editan en el panel).
	 * Si el atributo aún no existe, las iniciales.
	 *
	 * @return array<string,string>
	 */
	public static function tones(): array {
		$terms = function_exists( 'taxonomy_exists' ) && taxonomy_exists( 'pa_tono' ) ? get_terms(
			array(
				'taxonomy'   => 'pa_tono',
				'hide_empty' => false,
			)
		) : array();
		if ( ! is_array( $terms ) || ! $terms ) {
			return self::TONES;
		}
		$out = array();
		foreach ( $terms as $term ) {
			$out[ $term->name ] = (string) get_term_meta( $term->term_id, 'subtono', true );
		}
		return $out;
	}

	/**
	 * Crea lo que falte (idempotente): categorías, secciones, pasos, atributos y sus términos iniciales.
	 *
	 * @return array<string,int> Términos y atributos creados, por tipo.
	 */
	public static function seed(): array {
		$created = array(
			'categories' => 0,
			'sections'   => 0,
			'steps'      => 0,
			'attributes' => 0,
			'terms'      => 0,
		);
		self::register_taxonomies();
		self::register_term_meta();

		foreach ( self::CATEGORIES as $name ) {
			if ( ! term_exists( $name, 'product_cat' ) && ! is_wp_error( wp_insert_term( $name, 'product_cat' ) ) ) {
				++$created['categories'];
			}
		}
		foreach ( self::SECTIONS as $slug => $name ) {
			if ( ! term_exists( $slug, 'aralia_seccion' ) && ! is_wp_error( wp_insert_term( $name, 'aralia_seccion', array( 'slug' => $slug ) ) ) ) {
				++$created['sections'];
			}
		}
		foreach ( self::STEPS as $slug => $def ) {
			$term = term_exists( $slug, 'aralia_paso' );
			if ( ! $term ) {
				$term = wp_insert_term( $def[0], 'aralia_paso', array( 'slug' => $slug ) );
				if ( is_wp_error( $term ) ) {
					continue;
				}
				++$created['steps'];
			}
			update_term_meta( (int) ( is_array( $term ) ? $term['term_id'] : $term ), 'requires_tone', $def[1] ? 1 : 0 );
		}
		$created['attributes'] = self::ensure_attributes();
		$created['terms']      = self::seed_attribute_terms();
		return $created;
	}

	/**
	 * Crea los atributos globales que falten.
	 *
	 * @return int Creados.
	 */
	public static function ensure_attributes(): int {
		$count = 0;
		foreach ( self::ATTRIBUTES as $slug => $label ) {
			if ( function_exists( 'wc_attribute_taxonomy_id_by_name' ) && ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
				$id = wc_create_attribute(
					array(
						'name' => $label,
						'slug' => $slug,
					)
				);
				if ( ! is_wp_error( $id ) ) {
					++$count;
				}
			}
		}
		delete_transient( 'wc_attribute_taxonomies' );
		self::register_attribute_taxonomies();
		return $count;
	}

	/**
	 * Términos iniciales de tono, color y look (el subtono y el orden van en el término).
	 *
	 * @return int Creados.
	 */
	private static function seed_attribute_terms(): int {
		$count = 0;
		$order = 0;
		foreach ( self::TONES as $name => $subtone ) {
			$term = term_exists( $name, 'pa_tono' );
			if ( ! $term ) {
				$term = wp_insert_term( $name, 'pa_tono' );
				if ( is_wp_error( $term ) ) {
					continue;
				}
				++$count;
				update_term_meta( (int) $term['term_id'], 'subtono', $subtone );
				update_term_meta( (int) $term['term_id'], 'order', $order );
			}
			++$order;
		}
		foreach ( array(
			'pa_color' => self::COLORS,
			'pa_look'  => self::LOOKS,
		) as $taxonomy => $names ) {
			foreach ( $names as $name ) {
				if ( taxonomy_exists( $taxonomy ) && ! term_exists( $name, $taxonomy ) && ! is_wp_error( wp_insert_term( $name, $taxonomy ) ) ) {
					++$count;
				}
			}
		}
		return $count;
	}
}
