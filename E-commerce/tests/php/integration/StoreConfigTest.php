<?php
/**
 * T023: configuración de WooCommerce (COP, stock, HPOS, invitada, rol cliente).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Setup\StoreConfig;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class StoreConfigTest extends TestCase {

	/**
	 * Opciones que toca `apply()` (se restauran después de cada prueba).
	 *
	 * @var array<string,mixed>
	 */
	private array $saved = array();

	/**
	 * Páginas creadas por la prueba.
	 *
	 * @var int[]
	 */
	private array $pages = array();

	protected function set_up() {
		parent::set_up();
		foreach ( array_keys( StoreConfig::expected() ) as $option ) {
			$this->saved[ $option ] = get_option( $option, null );
		}
		$this->saved['woocommerce_checkout_page_id'] = get_option( 'woocommerce_checkout_page_id', null );
	}

	protected function tear_down() {
		foreach ( $this->pages as $id ) {
			wp_delete_post( $id, true );
		}
		$this->pages = array();
		foreach ( $this->saved as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}
		parent::tear_down();
	}

	private function make_checkout_page( string $content ): int {
		// La librería de pruebas de WordPress limpia las páginas: se crea una de checkout para la prueba.
		$id = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Checkout', 'post_content' => $content ) );
		$this->assertGreaterThan( 0, $id );
		$this->pages[] = $id;
		update_option( 'woocommerce_checkout_page_id', $id );
		return $id;
	}

	public function test_apply_sets_everything_and_is_idempotent(): void {
		$this->make_checkout_page( '[woocommerce_checkout]' );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_price_num_decimals', '2' );
		update_option( 'default_role', 'subscriber' );
		update_option( 'woocommerce_enable_myaccount_registration', 'yes' );

		$changes = StoreConfig::apply();
		$this->assertArrayHasKey( 'woocommerce_currency', $changes );
		$this->assertSame( array(), StoreConfig::diff() );

		$this->assertSame( 'COP', get_option( 'woocommerce_currency' ) );
		$this->assertSame( '0', (string) get_option( 'woocommerce_price_num_decimals' ) );
		$this->assertSame( 'yes', get_option( 'woocommerce_manage_stock' ) );
		$this->assertSame( '30', (string) get_option( 'woocommerce_hold_stock_minutes' ) );
		$this->assertSame( 'yes', get_option( 'woocommerce_custom_orders_table_enabled' ) );
		$this->assertSame( 'yes', get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ) );
		$this->assertSame( 'yes', get_option( 'woocommerce_enable_guest_checkout' ) );
		$this->assertSame( 'no', get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) );
		$this->assertSame( 'no', get_option( 'woocommerce_enable_myaccount_registration' ), 'Sin alta pública desde Mi cuenta' );
		$this->assertSame( 'customer', get_option( 'default_role' ) );
		$this->assertSame( '0', (string) get_option( 'users_can_register' ) );

		$this->assertSame( array(), StoreConfig::apply(), 'Aplicar dos veces no cambia nada' );
	}

	public function test_price_has_no_decimals_after_setup(): void {
		$this->make_checkout_page( '<!-- wp:woocommerce/checkout --><!-- wp:woocommerce/checkout-fields-block --><!-- /wp:woocommerce/checkout-fields-block --><!-- /wp:woocommerce/checkout -->' );
		StoreConfig::apply();
		$this->assertSame( 0, wc_get_price_decimals() );
		$this->assertSame( 'COP', get_woocommerce_currency() );
	}

	public function test_classic_checkout_shortcode_is_converted_to_block_with_inner_blocks(): void {
		$id = $this->make_checkout_page( '[woocommerce_checkout]' );
		$this->assertFalse( StoreConfig::checkout_is_block() );

		StoreConfig::apply();

		$this->assertTrue( StoreConfig::checkout_is_block() );
		$content = (string) get_post( $id )->post_content;
		$this->assertStringContainsString( 'wp:woocommerce/checkout-fields-block', $content, 'Debe traer los bloques internos de WooCommerce, no un contenedor vacío' );
		$this->assertStringNotContainsString( '[woocommerce_checkout]', $content );
	}

	public function test_missing_checkout_page_is_reported_and_created(): void {
		update_option( 'woocommerce_checkout_page_id', 999999 );
		$this->assertFalse( StoreConfig::checkout_page_exists() );
		$this->assertArrayHasKey( 'checkout_page_content', StoreConfig::diff(), 'Sin página no puede darse por configurado' );

		try {
			StoreConfig::apply();
		} finally {
			$this->pages[] = (int) get_option( 'woocommerce_checkout_page_id' ); // Se limpia aunque una aserción falle.
		}

		$this->assertTrue( StoreConfig::checkout_page_exists() );
		$this->assertTrue( StoreConfig::checkout_is_block() );
	}

	public function test_apply_writes_nothing_when_the_default_checkout_content_cannot_be_obtained(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_checkout_page_id', 999999 ); // Sin página: hace falta el contenido por defecto.
		$failing = static function () {
			throw new \RuntimeException( 'WooCommerce cambió su API interna.' );
		};

		try {
			StoreConfig::apply( $failing );
			$this->fail( 'apply() debía lanzar RuntimeException' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'API interna', $e->getMessage() );
		}

		$this->assertSame( 'USD', get_option( 'woocommerce_currency' ), 'Si falla el checkout no se cambia ninguna opción' );
		$this->assertFalse( StoreConfig::checkout_page_exists() );
	}

	public function test_default_checkout_content_comes_with_inner_blocks(): void {
		$method = new \ReflectionMethod( StoreConfig::class, 'default_checkout_content' );
		$this->assertStringContainsString( 'wp:woocommerce/checkout-fields-block', (string) $method->invoke( null ) );
	}

	public function test_checkout_page_in_the_trash_does_not_count(): void {
		$id = $this->make_checkout_page( '<!-- wp:woocommerce/checkout --><!-- /wp:woocommerce/checkout -->' );
		wp_trash_post( $id );
		$this->assertFalse( StoreConfig::checkout_page_exists() );
	}

	/**
	 * @dataProvider provide_sync_envs
	 */
	public function test_hpos_sync_is_only_forced_in_known_non_production_envs( string $env, bool $forced ): void {
		$this->assertSame( $forced, array_key_exists( 'woocommerce_custom_orders_table_data_sync_enabled', StoreConfig::expected( $env ) ), "env=$env" );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function provide_sync_envs(): array {
		return array(
			'local'      => array( 'local', true ),
			'test'       => array( 'test', true ),
			'staging'    => array( 'staging', true ),
			'production' => array( 'production', false ),
			'prod'       => array( 'prod', false ),
			'vacío'      => array( '', false ),
		);
	}

	public function test_hpos_is_switched_on_when_there_are_no_orders(): void {
		$this->make_checkout_page( '<!-- wp:woocommerce/checkout --><!-- wp:woocommerce/checkout-fields-block --><!-- /wp:woocommerce/checkout-fields-block --><!-- /wp:woocommerce/checkout -->' );
		update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
		$this->assertTrue( StoreConfig::hpos_switch_is_safe() );
		$changes = StoreConfig::apply();
		$this->assertArrayHasKey( 'woocommerce_custom_orders_table_enabled', $changes );
		$this->assertSame( 'yes', get_option( 'woocommerce_custom_orders_table_enabled' ) );
	}

	public function test_hpos_is_not_switched_when_orders_already_exist(): void {
		$this->make_checkout_page( '<!-- wp:woocommerce/checkout --><!-- wp:woocommerce/checkout-fields-block --><!-- /wp:woocommerce/checkout-fields-block --><!-- /wp:woocommerce/checkout -->' );
		update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
		$order = wc_create_order();
		try {
			$changes = StoreConfig::apply();
			$this->assertArrayNotHasKey( 'woocommerce_custom_orders_table_enabled', $changes, 'Con pedidos existentes no se cambia HPOS con update_option' );
			$this->assertSame( 'no', get_option( 'woocommerce_custom_orders_table_enabled' ) );
		} finally {
			$order->delete( true );
		}
	}
}
