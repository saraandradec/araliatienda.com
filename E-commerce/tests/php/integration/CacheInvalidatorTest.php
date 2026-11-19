<?php
/**
 * T034: invalidación de caché por etiqueta.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Cache\CacheInvalidator;
use Aralia\Tests\Support\IsolatedTestCase;

final class CacheInvalidatorTest extends IsolatedTestCase {

	/**
	 * @var string[]
	 */
	private array $purged = array();

	/**
	 * @var array<int,string[]>
	 */
	private array $cdn = array();

	private ?\WC_Product_Simple $product = null;

	protected function set_up() {
		parent::set_up();
		$this->purged = array();
		$this->cdn    = array();
		add_action( 'litespeed_purge', array( $this, 'on_purge' ) );
		add_action( 'aralia_cdn_purge', array( $this, 'on_cdn' ) );
	}

	protected function tear_down() {
		remove_action( 'litespeed_purge', array( $this, 'on_purge' ) );
		remove_action( 'aralia_cdn_purge', array( $this, 'on_cdn' ) );
		if ( $this->product ) {
			$this->product->delete( true );
		}
		parent::tear_down();
	}

	public function on_purge( $tag ): void {
		$this->purged[] = $tag;
	}

	public function on_cdn( $tags ): void {
		$this->cdn[] = $tags;
	}

	private function make_product(): \WC_Product_Simple {
		$cat = wp_insert_term( 'Labios caché ' . uniqid(), 'product_cat' );
		$p   = new \WC_Product_Simple();
		$p->set_name( 'Caché' );
		$p->set_regular_price( '1000' );
		$p->set_category_ids( array( (int) $cat['term_id'] ) );
		$p->save();
		$this->product = $p;
		return $p;
	}

	public function test_tags_for_a_product_include_home_product_and_categories(): void {
		$p    = $this->make_product();
		$tags = CacheInvalidator::tags_for_product( $p->get_id() );
		$this->assertContains( 'ara_home', $tags );
		$this->assertContains( 'ara_p_' . $p->get_id(), $tags );
		$this->assertContains( 'ara_c_' . $p->get_category_ids()[0], $tags );
	}

	public function test_a_variation_purges_its_parent_product(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable' );
		$parent->save();
		$var = new \WC_Product_Variation();
		$var->set_parent_id( $parent->get_id() );
		$var->save();
		$this->assertContains( 'ara_p_' . $parent->get_id(), CacheInvalidator::tags_for_product( $var->get_id() ) );
		$parent->delete( true );
	}

	public function test_purge_dispatches_to_litespeed_and_the_cdn_and_drops_malformed_tags(): void {
		$done = ( new CacheInvalidator() )->purge_tags( array( 'ara_p_1', 'ara_p_1', 'ara_home', 'otra_cosa', 'ara_', "ara_x'; DROP", 'ARA_UP', '', 'ara_' . str_repeat( 'a', 50 ) ) );
		$this->assertSame( array( 'ara_p_1', 'ara_home' ), $done );
		$this->assertSame( array( 'ara_p_1', 'ara_home' ), $this->purged );
		$this->assertSame( array( array( 'ara_p_1', 'ara_home' ) ), $this->cdn );
	}

	public function test_nothing_valid_means_no_purge_at_all(): void {
		$this->assertSame( array(), ( new CacheInvalidator() )->purge_tags( array( 'x', '' ) ) );
		$this->assertSame( array(), $this->purged );
		$this->assertSame( array(), $this->cdn );
	}

	public function test_a_purge_bumps_the_transient_version_so_old_cache_entries_stop_being_read(): void {
		$inv = new CacheInvalidator();
		$old = CacheInvalidator::transient_key( 'disponibilidad_1' );
		set_transient( $old, 'x', 600 );
		$inv->purge_tags( array( 'ara_home' ) );
		$new = CacheInvalidator::transient_key( 'disponibilidad_1' );
		$this->assertNotSame( $old, $new, 'La versión cambió: la clave nueva no ve los datos viejos' );
		$this->assertFalse( get_transient( $new ) );
		delete_transient( $old );
	}

	public function test_transient_keys_are_versioned_validated_and_separate_from_security_ones(): void {
		$this->assertMatchesRegularExpression( '/^aralia_cache_v\d+_precio_9$/', CacheInvalidator::transient_key( 'precio_9' ) );
		foreach ( array( '', 'con espacio', "x'; DROP", 'MAYUS', str_repeat( 'a', 61 ) ) as $bad ) {
			try {
				CacheInvalidator::transient_key( $bad );
				$this->fail( "Debía rechazar '$bad'" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Nombre de transient inválido.', $e->getMessage() );
			}
		}
		$this->assertStringNotContainsString( 'aralia_sec_', CacheInvalidator::transient_key( 'x' ), 'Los transients de seguridad usan otro prefijo y nunca los toca la purga' );
	}

	public function test_a_purge_never_touches_unrelated_or_security_transients(): void {
		set_transient( 'aralia_sec_bloqueo_1', 'b', 600 );
		set_transient( 'otro_plugin_dato', 'y', 600 );
		( new CacheInvalidator() )->purge_tags( array( 'ara_home' ) );
		$this->assertSame( 'b', get_transient( 'aralia_sec_bloqueo_1' ) );
		$this->assertSame( 'y', get_transient( 'otro_plugin_dato' ) );
		delete_transient( 'aralia_sec_bloqueo_1' );
		delete_transient( 'otro_plugin_dato' );
	}

	public function test_purges_in_one_request_are_queued_and_flushed_once(): void {
		$inv = new CacheInvalidator();
		$inv->queue( array( 'ara_p_1', 'ara_home' ) );
		$inv->queue( array( 'ara_p_1', 'ara_c_5' ) );
		$this->assertSame( array(), $this->purged, 'Encolar no purga todavía' );
		$done = $inv->flush();
		$this->assertSame( array( 'ara_p_1', 'ara_home', 'ara_c_5' ), $done, 'Sin duplicados' );
		$this->assertSame( array( 'ara_p_1', 'ara_home', 'ara_c_5' ), $this->purged );
		$this->assertSame( array(), $inv->flush(), 'Una segunda llamada no vuelve a purgar' );
	}

	public function test_a_stock_change_does_not_purge_the_home(): void {
		$p   = $this->make_product();
		$inv = new CacheInvalidator();
		$inv->on_stock_changed( $p );
		$done = $inv->flush();
		$this->assertContains( 'ara_p_' . $p->get_id(), $done );
		$this->assertNotContains( 'ara_home', $done, 'Cada venta no puede vaciar la caché de la home (pico viral)' );
	}

	public function test_responses_get_the_tags_litespeed_needs_to_purge_them_later(): void {
		$p     = $this->make_product();
		$added = array();
		add_action( 'litespeed_tag_add', static function ( $tag ) use ( &$added ) {
			$added[] = $tag;
		} );
		// Una consulta NUEVA (no se muta la global: la base IsolatedTestCase restaura las referencias originales).
		$query                     = new \WP_Query();
		$query->queried_object_id  = $p->get_id();
		$query->queried_object     = get_post( $p->get_id() );
		$query->is_singular        = true;
		$query->is_single          = true;
		$GLOBALS['wp_the_query']   = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_query']       = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$tags = ( new CacheInvalidator() )->tag_current_response();
		remove_all_actions( 'litespeed_tag_add' );
		$this->assertContains( 'ara_p_' . $p->get_id(), $tags );
		$this->assertSame( $tags, $added, 'Cada etiqueta se entrega a LiteSpeed con litespeed_tag_add' );
	}

	public function test_saving_a_product_and_changing_stock_purge_by_tag(): void {
		$inv = new CacheInvalidator();
		// Se quitan las del plugin (ya enganchadas en init) para probar que register() de ESTA instancia es lo que engancha.
		global $wp_filter;
		foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'transition_post_status', 'set_object_terms', 'shutdown', 'wp' ) as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
					foreach ( $callbacks as $cb ) {
						if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof CacheInvalidator ) {
							remove_action( $hook, $cb['function'], $priority );
						}
					}
				}
			}
		}
		$p = null;
		$inv->register();
		$p = $this->make_product();
		$this->assertSame( array(), $this->purged, 'Con el enganche quitado, nada se purga hasta que ESTA instancia se registre y llegue el fin de la petición' );
		$inv->on_shutdown();
		$this->assertContains( 'ara_p_' . $p->get_id(), $this->purged, 'Guardar el producto purga su etiqueta al terminar la petición' );

		$this->purged = array();
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 5 );
		$p->save();
		$inv->on_shutdown();
		$this->purged = array();
		wc_update_product_stock( $p, 2 );
		$inv->on_shutdown();
		$this->assertContains( 'ara_p_' . $p->get_id(), $this->purged, 'Cambiar el stock purga su etiqueta' );

		foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $hook ) {
			remove_action( $hook, array( $inv, 'on_product_changed' ) );
			remove_action( $hook, array( $inv, 'on_stock_changed' ) );
		}
		remove_action( 'transition_post_status', array( $inv, 'on_transition' ) );
		remove_action( 'set_object_terms', array( $inv, 'on_terms' ) );
		remove_action( 'shutdown', array( $inv, 'on_shutdown' ), 5 );
		remove_action( 'wp', array( $inv, 'on_wp' ) );
	}
}
