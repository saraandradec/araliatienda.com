<?php
/**
 * Checkout provisional de la puerta de la Fase 1 (T049).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Setup\ProvisionalShipping;
use Aralia\Setup\StoreConfig;
use Aralia\Tests\Support\IsolatedTestCase;

/**
 * Checkout de bloques nativo + envío de tarifa plana provisional, idempotente.
 */
final class ProvisionalCheckoutTest extends IsolatedTestCase {

	private function wipe(): void {
		if ( 'test' !== \Aralia\Support\Environment::current() ) {
			$this->markTestSkipped( 'Estas pruebas borran todas las zonas de envío: solo corren con ARALIA_ENV=test.' );
		}
		// Fuera todas las zonas que cubran Colombia (de otras pruebas o del estado inicial) y las opciones propias.
		foreach ( \WC_Shipping_Zones::get_zones() as $data ) {
			( new \WC_Shipping_Zone( (int) $data['id'] ) )->delete();
		}
		delete_option( ProvisionalShipping::OPTION );
		delete_option( ProvisionalShipping::ZONE_OPTION );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->wipe();
	}

	protected function tearDown(): void {
		$this->wipe();
		parent::tearDown();
	}

	public function test_provisional_shipping_creates_one_colombia_zone_with_one_flat_rate_and_is_idempotent(): void {
		$first = ProvisionalShipping::apply();
		$again = ProvisionalShipping::apply();
		$this->assertSame( $first['zone_id'], $again['zone_id'] );
		$this->assertFalse( $again['created_zone'] );
		$this->assertFalse( $again['created_method'], 'No duplica el método' );

		$zone    = ProvisionalShipping::find_zone();
		$this->assertNotNull( $zone );
		$methods = $zone->get_shipping_methods();
		$this->assertCount( 1, $methods );
		$method = array_values( $methods )[0];
		$this->assertSame( 'flat_rate', $method->id );
		$this->assertSame( ProvisionalShipping::DEFAULT_COST, $method->get_option( 'cost' ) );
		$this->assertSame( 'Envío (provisional)', $method->get_option( 'title' ) );
		$locations = array_map( static fn( $l ) => $l->type . ':' . $l->code, $zone->get_zone_locations() );
		$this->assertSame( array( 'country:CO' ), $locations );
		$this->assertTrue( ProvisionalShipping::is_active() );
	}

	public function test_an_edited_rate_is_not_overwritten_by_running_setup_again(): void {
		ProvisionalShipping::apply();
		$zone   = ProvisionalShipping::find_zone();
		$method = array_values( $zone->get_shipping_methods() )[0];
		update_option( 'woocommerce_flat_rate_' . $method->get_instance_id() . '_settings', array( 'title' => 'Envío (provisional)', 'tax_status' => 'none', 'cost' => '15000' ) );
		ProvisionalShipping::apply();
		$method = array_values( ProvisionalShipping::find_zone()->get_shipping_methods() )[0];
		$this->assertSame( '15000', $method->get_option( 'cost' ) );
	}

	public function test_the_checkout_page_is_the_native_block_checkout(): void {
		StoreConfig::apply();
		$this->assertTrue( StoreConfig::checkout_page_exists() );
		$this->assertTrue( StoreConfig::checkout_is_block(), 'Checkout de bloques nativo, no el shortcode' );
		$this->assertSame( 'yes', get_option( 'woocommerce_enable_guest_checkout' ), 'Compra como invitada' );
	}

	public function test_a_cart_to_the_provisional_zone_gets_the_flat_rate(): void {
		ProvisionalShipping::apply();
		$packages = array(
			array(
				'contents'        => array(),
				'contents_cost'   => 20000,
				'applied_coupons' => array(),
				'user'            => array( 'ID' => 0 ),
				'destination'     => array( 'country' => 'CO', 'state' => 'DC', 'postcode' => '', 'city' => 'Bogotá', 'address' => 'x', 'address_1' => 'x', 'address_2' => '' ),
			),
		);
		$zone = \WC_Shipping_Zones::get_zone_matching_package( $packages[0] );
		$this->assertSame( ProvisionalShipping::ZONE_NAME, $zone->get_zone_name(), 'Un destino en Colombia cae en la zona provisional' );
		$outside = $packages[0];
		$outside['destination']['country'] = 'US';
		$this->assertNotSame( ProvisionalShipping::ZONE_NAME, \WC_Shipping_Zones::get_zone_matching_package( $outside )->get_zone_name() );
	}

	public function test_it_is_not_created_in_production_or_staging_without_the_explicit_flag(): void {
		foreach ( array( 'production', 'staging', '', 'prod' ) as $env ) {
			$this->assertNull( ProvisionalShipping::maybe_apply( false, $env ), "No se crea en «{$env}»" );
		}
		$this->assertNull( ProvisionalShipping::find_zone(), 'No quedó ninguna zona' );
		$this->assertFalse( ProvisionalShipping::is_active() );
		$this->assertNotNull( ProvisionalShipping::maybe_apply( false, 'test' ), 'En test sí' );
		$this->wipe();
		$this->assertNotNull( ProvisionalShipping::maybe_apply( false, 'local' ), 'En local sí' );
		$this->wipe();
		$this->assertNotNull( ProvisionalShipping::maybe_apply( true, 'production' ), 'Con la bandera explícita, bajo responsabilidad de quien la usa' );
	}

	public function test_renaming_the_zone_does_not_create_a_duplicate(): void {
		$first = ProvisionalShipping::apply();
		$zone  = ProvisionalShipping::find_zone();
		$zone->set_zone_name( 'Envíos nacionales' );
		$zone->save();
		$again = ProvisionalShipping::apply();
		$this->assertSame( $first['zone_id'], $again['zone_id'] );
		$this->assertFalse( $again['created_zone'], 'Se identifica por id, no por nombre' );
		$this->assertCount( 1, \WC_Shipping_Zones::get_zones() );
	}

	public function test_an_existing_zone_covering_colombia_is_reported_and_no_shadowed_zone_is_created(): void {
		$other = new \WC_Shipping_Zone();
		$other->set_zone_name( 'Mi zona real' );
		$other->add_location( 'CO', 'country' );
		$other->save();
		$out = ProvisionalShipping::apply();
		$this->assertSame( 'Mi zona real', $out['conflict'] );
		$this->assertFalse( $out['created_zone'] );
		$this->assertNull( ProvisionalShipping::find_zone() );
		$this->assertFalse( ProvisionalShipping::is_active(), 'Si no se creó, no queda marcado como activo' );
	}

	public function test_a_zone_with_a_disabled_or_extra_flat_rate_is_not_given_a_second_one(): void {
		ProvisionalShipping::apply();
		$zone   = ProvisionalShipping::find_zone();
		$method = array_values( $zone->get_shipping_methods() )[0];
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'woocommerce_shipping_zone_methods', array( 'is_enabled' => 0 ), array( 'instance_id' => $method->get_instance_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$again = ProvisionalShipping::apply();
		$this->assertFalse( $again['created_method'], 'Un flat_rate desactivado cuenta: no se añade otro' );
		$this->assertCount( 1, ProvisionalShipping::find_zone()->get_shipping_methods( false ) );
	}

	public function test_the_flat_rate_is_really_charged_for_a_colombian_package(): void {
		ProvisionalShipping::apply();
		$product = new \WC_Product_Simple();
		$product->set_name( 'Para envío' );
		$product->set_regular_price( '20000' );
		$id = $product->save();
		$package = array(
			'contents'        => array( 1 => array( 'data' => wc_get_product( $id ), 'quantity' => 1, 'line_total' => 20000, 'line_tax' => 0, 'line_subtotal' => 20000, 'line_subtotal_tax' => 0, 'product_id' => $id, 'variation_id' => 0, 'variation' => array(), 'key' => 'k' ) ),
			'contents_cost'   => 20000,
			'applied_coupons' => array(),
			'user'            => array( 'ID' => 0 ),
			'destination'     => array( 'country' => 'CO', 'state' => 'DC', 'postcode' => '', 'city' => 'Bogotá', 'address' => 'x', 'address_1' => 'x', 'address_2' => '' ),
		);
		$rates = array();
		foreach ( \WC_Shipping_Zones::get_zone_matching_package( $package )->get_shipping_methods( true ) as $method ) {
			$rates = array_merge( $rates, $method->get_rates_for_package( $package ) );
		}
		$this->assertCount( 1, $rates );
		$this->assertSame( 10000.0, (float) array_values( $rates )[0]->get_cost(), 'La tarifa plana de prueba se calcula' );
		wp_delete_post( $id, true );
	}

	public function test_the_admin_notice_shows_in_production_only_while_the_provisional_shipping_is_active(): void {
		wp_set_current_user( (int) wp_insert_user( array( 'user_login' => 'adm' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'adm' . wp_rand() . '@example.com', 'role' => 'administrator' ) ) );
		ob_start();
		ProvisionalShipping::notice();
		$this->assertSame( '', ob_get_clean(), 'Inactivo: sin aviso' );
		update_option( ProvisionalShipping::OPTION, '1' );
		foreach ( array( 'test', 'local' ) as $env ) {
			ob_start();
			ProvisionalShipping::notice( $env );
			$this->assertSame( '', ob_get_clean(), "En $env (donde se permite) no molesta" );
		}
		foreach ( array( 'production', 'staging' ) as $env ) {
			ob_start();
			ProvisionalShipping::notice( $env );
			$html = ob_get_clean();
			$this->assertStringContainsString( 'notice-error', $html, "En $env, con el envío provisional activo, hay aviso rojo" );
			$this->assertStringContainsString( 'PROVISIONAL', $html );
		}
		wp_set_current_user( (int) wp_insert_user( array( 'user_login' => 'cli' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'cli' . wp_rand() . '@example.com', 'role' => 'customer' ) ) );
		ob_start();
		ProvisionalShipping::notice( 'production' );
		$this->assertSame( '', ob_get_clean(), 'Una clienta no ve el aviso' );
	}

	public function test_baseline_fails_in_production_while_the_provisional_shipping_is_active(): void {
		$baseline = new \Aralia\Security\Baseline();
		$run      = static fn( string $env, bool $on ): array => $baseline->run( $env, static fn() => array(), 'example.com', array( 'provisional_shipping' => $on ) );
		$find     = static function ( array $report ): ?array {
			foreach ( $report as $check ) {
				if ( 'provisional_shipping' === ( $check['id'] ?? $check['name'] ?? '' ) ) {
					return $check;
				}
			}
			return null;
		};
		$prod_on  = $run( 'production', true );
		$check    = $find( isset( $prod_on['checks'] ) ? $prod_on['checks'] : $prod_on );
		$this->assertNotNull( $check, 'El chequeo existe: ' . wp_json_encode( array_keys( $prod_on ) ) );
		$this->assertFalse( (bool) $check['ok'], 'Producción con envío provisional: falla' );
		$check = $find( isset( $run( 'production', false )['checks'] ) ? $run( 'production', false )['checks'] : $run( 'production', false ) );
		$this->assertTrue( (bool) $check['ok'] );
		$local = $run( 'local', true );
		$check = $find( isset( $local['checks'] ) ? $local['checks'] : $local );
		$this->assertTrue( (bool) $check['ok'], 'En local no bloquea' );
	}

	public function test_theme_activation_is_idempotent_and_reports_a_missing_theme(): void {
		register_theme_directory( dirname( __DIR__, 2 ) . '/../wp-content/themes' );
		$previous = get_stylesheet();
		try {
			$this->assertSame( 'missing', \Aralia\Setup\ThemeActivation::apply( 'tema-que-no-existe' ), 'Un tema inexistente se informa y no se activa' );
			$this->assertSame( $previous, get_stylesheet() );
			// Estado inicial conocido: otro tema distinto de aralia (si aralia ya estaba activo, se pasa a uno cualquiera instalado).
			if ( 'aralia' === get_stylesheet() ) {
				$other = array_values( array_diff( array_keys( wp_get_themes() ), array( 'aralia' ) ) );
				if ( ! $other ) {
					$this->markTestSkipped( 'No hay otro tema instalado con el que partir de un estado conocido.' );
				}
				switch_theme( $other[0] );
			}
			$this->assertSame( 'activated', \Aralia\Setup\ThemeActivation::apply() );
			$this->assertSame( 'aralia', get_stylesheet() );
			$this->assertSame( 'already', \Aralia\Setup\ThemeActivation::apply() );
		} finally {
			switch_theme( $previous );
		}
	}
}
