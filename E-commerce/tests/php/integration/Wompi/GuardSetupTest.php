<?php
/**
 * Llaves, TLS, neutralización del plugin oficial y consulta de Wompi (T050, T051, T052, T059).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration\Wompi;

use Aralia\Guard\Keys;
use Aralia\Guard\Lookup;
use Aralia\Guard\Neutralize;
use Aralia\Guard\Tls;
use Aralia\Guard\WompiClient;
use Aralia\Tests\Support\IsolatedTestCase;
use Aralia\Tests\Support\WompiFixtures;

final class GuardSetupTest extends IsolatedTestCase {

	protected function set_up() {
		parent::set_up();
		update_option( Keys::OPTION, array( 'testmode' => 'yes' ) );
	}

	protected function tear_down() {
		delete_option( Keys::OPTION );
		parent::tear_down();
	}

	public function test_keys_come_from_constants_and_never_from_the_database(): void {
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => serialize( array( 'testmode' => 'yes', 'test_private_key' => 'prv_test_EN_LA_BD', 'private_key' => 'prv_prod_EN_LA_BD' ) ) ), array( 'option_name' => Keys::OPTION ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( Keys::OPTION, 'options' );
		$s = get_option( Keys::OPTION );
		$this->assertSame( ARALIA_WOMPI_PUB_TEST, $s['test_public_key'] );
		$this->assertSame( ARALIA_WOMPI_EVENTS_TEST, $s['test_event_secret_key'] );
		$this->assertSame( ARALIA_WOMPI_INTEGRITY_TEST, $s['test_integrity_key'] );
		$this->assertSame( ARALIA_WOMPI_PRV_TEST, $s['test_private_key'], 'La de la BD se ignora' );
		$this->assertSame( '', $s['private_key'], 'Sin constante de producción, vacío (no la de la BD)' );
	}

	public function test_saving_settings_blanks_every_key_field_in_the_database(): void {
		update_option(
			Keys::OPTION,
			array(
				'testmode'         => 'yes',
				'title'            => 'Wompi',
				'public_key'       => 'pub_prod_pegada_en_el_panel',
				'private_key'      => 'prv_prod_pegada_en_el_panel',
				'event_secret_key' => 'prod_events_pegada',
				'integrity_key'    => 'prod_integrity_pegada',
				'test_private_key' => 'prv_test_pegada',
			)
		);
		global $wpdb;
		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Keys::OPTION ) ); // phpcs:ignore WordPress.DB
		foreach ( array( 'pegada', 'prv_prod', 'prod_events', 'prod_integrity' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $raw, "No debe llegar a la BD: $leak" );
		}
		$this->assertStringContainsString( 'Wompi', $raw, 'Los demás ajustes sí se guardan' );
	}

	public function test_without_keys_for_the_current_mode_the_payment_method_is_not_offered(): void {
		$gateways = array( 'wompi' => new \stdClass(), 'bacs' => new \stdClass() );
		$this->assertTrue( Keys::available(), 'Modo prueba: hay llaves de prueba' );
		$this->assertFalse( Keys::checkout_ready(), 'Hasta T061 (order-pay propio) el medio de pago no se ofrece: el widget del plugin cobraría sin poder pagar el pedido' );
		$this->assertArrayNotHasKey( 'wompi', Keys::require_keys( $gateways ) );
		$this->assertFalse( Keys::available( 'prod' ), 'Sin constantes de producción, el modo producción no tiene llaves' );
		$this->assertArrayHasKey( 'bacs', Keys::require_keys( $gateways ) );
	}

	/**
	 * @dataProvider hosts
	 */
	public function test_tls_is_forced_only_for_wompi_hosts( string $url, bool $forced ): void {
		$args = apply_filters( 'http_request_args', array( 'sslverify' => false ), $url );
		$this->assertSame( $forced ? true : false, $args['sslverify'], $url );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function hosts(): array {
		return array(
			'sandbox'         => array( 'https://sandbox.wompi.co/v1/transactions/1', true ),
			'production'      => array( 'https://production.wompi.co/v1/transactions/1', true ),
			'checkout'        => array( 'https://checkout.wompi.co/widget.js', true ),
			'raíz'            => array( 'https://wompi.co/', true ),
			'mayúsculas'      => array( 'https://SANDBOX.WOMPI.CO./x', true ),
			'dominio parecido' => array( 'https://evil-wompi.co/x', false ),
			'sufijo engañoso' => array( 'https://wompi.co.evil.com/x', false ),
			'otro'            => array( 'https://example.com/', false ),
		);
	}

	public function test_the_client_uses_the_sandbox_with_8_seconds_and_verified_tls(): void {
		WompiFixtures::mock_api( array( 'TX-1' => array( 'APPROVED', 100, 'COP', '1-1' ) ) );
		$tx = ( new WompiClient( 'test' ) )->transaction( 'TX-1' );
		$this->assertSame( 'APPROVED', $tx['status'] );
		$this->assertTrue( WompiFixtures::$requests[0]['args']['sslverify'] );
		$this->assertSame( 8, (int) WompiFixtures::$requests[0]['args']['timeout'] );
		$this->assertStringStartsWith( 'https://sandbox.wompi.co/v1/transactions/TX-1', WompiFixtures::$requests[0]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', WompiFixtures::$requests[0]['args']['headers'], 'T-API-AUTH pendiente: sin llave por defecto' );
		( new WompiClient( 'prod' ) )->transaction( 'TX-1' );
		$this->assertStringStartsWith( 'https://production.wompi.co/v1/', WompiFixtures::$requests[1]['url'] );
	}

	public function test_the_client_distinguishes_unknown_from_unreachable(): void {
		WompiFixtures::mock_api( array( 'DOWN' => 'down', 'ERR' => 500, 'GONE' => 404 ) );
		$c = new WompiClient( 'test' );
		$this->assertNull( $c->transaction( 'DOWN' ), 'Red caída: no se sabe' );
		$this->assertNull( $c->transaction( 'ERR' ), '5xx: no se sabe' );
		$this->assertSame( 'NOT_FOUND', $c->transaction( 'GONE' )['status'] );
		$this->assertSame( 'NOT_FOUND', $c->transaction( '../../etc/passwd' )['status'], 'Un id con forma rara no sale a la red' );
		$this->assertCount( 3, WompiFixtures::$requests );
	}

	public function test_plugin_hooks_the_guard_replaces_are_removed_and_voided_is_hidden(): void {
		require_once __DIR__ . '/../../Support/Doubles/Wompi_Portal_Pagos_Webhook_Handler.php';
		$h = new \Wompi_Portal_Pagos_Webhook_Handler();
		add_action( 'woocommerce_api_wc_wompi', array( $h, 'check_for_webhook' ) );
		$this->assertGreaterThan( 0, Neutralize::remove_class_action( 'woocommerce_api_wc_wompi', 'Wompi_Portal_Pagos_Webhook_Handler' ) );
		$this->assertFalse( has_action( 'woocommerce_api_wc_wompi', array( $h, 'check_for_webhook' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_api_wc_wompi', array( \Aralia\Guard\EventHandler::class, 'serve' ) ), 'El manejador del guard sigue' );

		$this->assertArrayNotHasKey( 'wc-voided', apply_filters( 'wc_order_statuses', array( 'wc-pending' => 'x', 'wc-voided' => 'Voided' ) ) );
		$this->assertFalse( apply_filters( 'woocommerce_admin_order_should_render_refunds', true, 1, null ), 'Sin reembolsos desde la tienda' );
		$this->assertSame( array( 'send_order_details' => 'x' ), apply_filters( 'woocommerce_order_actions', array( 'wompi_void' => 'Anular', 'send_order_details' => 'x', 'void_it' => 'y' ) ) );
	}

	public function test_the_event_handler_runs_before_the_plugin_and_has_priority_one(): void {
		$this->assertSame( 1, has_action( 'woocommerce_api_wc_wompi', array( \Aralia\Guard\EventHandler::class, 'serve' ) ) );
	}

	public function test_the_expiry_lookup_aggregates_all_attempts(): void {
		$o = wc_create_order();
		$o->set_total( '35000' );
		$o->update_meta_data(
			'_ara_attempts',
			array(
				array( 'reference' => '1-1', 'transaction_id' => 'TX-A' ),
				array( 'reference' => '1-2', 'transaction_id' => 'TX-B' ),
				array( 'reference' => '1-3' ),
			)
		);
		$o->save();
		$res = static fn() => Lookup::for_order( null, wc_get_order( $o->get_id() ) );

		WompiFixtures::mock_api( array( 'TX-A' => array( 'DECLINED', 3500000, 'COP', '1-1' ), 'TX-B' => array( 'APPROVED', 3500000, 'COP', '1-2' ) ) );
		$this->assertSame( 'APPROVED', $res(), 'APPROVED de cualquiera gana' );
		remove_all_filters( 'pre_http_request' );
		WompiFixtures::mock_api( array( 'TX-A' => array( 'PENDING', 3500000, 'COP', '1-1' ), 'TX-B' => array( 'DECLINED', 3500000, 'COP', '1-2' ) ) );
		$this->assertSame( 'PENDING', $res() );
		remove_all_filters( 'pre_http_request' );
		WompiFixtures::mock_api( array( 'TX-A' => 'down', 'TX-B' => array( 'DECLINED', 3500000, 'COP', '1-2' ) ) );
		$this->assertNull( $res(), 'Una consulta fallida sin APPROVED: no se sabe' );
		remove_all_filters( 'pre_http_request' );
		WompiFixtures::mock_api( array( 'TX-A' => array( 'DECLINED', 3500000, 'COP', '1-1' ), 'TX-B' => array( 'APPROVED', 100, 'COP', '1-2' ) ) ); // Aprobada, pero de OTRO monto.
		$this->assertSame( 'DECLINED', $res(), 'Una transacción que no es de este pedido no retiene el stock' );
		remove_all_filters( 'pre_http_request' );
		WompiFixtures::mock_api( array( 'TX-A' => array( 'DECLINED', 3500000, 'COP', '1-1' ), 'TX-B' => array( 'APPROVED', 3500000, 'COP', '9-9' ) ) ); // Aprobada, pero de OTRA referencia.
		$this->assertSame( 'DECLINED', $res() );
		remove_all_filters( 'pre_http_request' );
		WompiFixtures::mock_api( array( 'TX-A' => array( 'DECLINED', 3500000, 'COP', '1-1' ), 'TX-B' => array( 'ERROR', 3500000, 'COP', '1-2' ) ) );
		$this->assertSame( 'DECLINED', $res() );
		$this->assertSame( 'DECLINED', $res(), 'Premisa' );
		$o->delete( true );
	}

	public function test_the_key_fields_are_not_rendered_nor_returned_by_rest_and_logging_stays_off(): void {
		$fields = array_fill_keys( array_merge( array_keys( Keys::FIELDS ), array( 'title', 'enabled' ) ), array( 'type' => 'password' ) );
		$out    = apply_filters( 'woocommerce_settings_api_form_fields_wompi', $fields );
		foreach ( array_keys( Keys::FIELDS ) as $field ) {
			$this->assertArrayNotHasKey( $field, $out, "No se pinta ni sale por REST: $field" );
		}
		$this->assertArrayHasKey( 'title', $out );
		$this->assertArrayHasKey( 'aralia_keys_notice', $out );
		$this->assertSame( 'no', get_option( Keys::OPTION )['logging'], 'El registro del plugin vuelca las respuestas de la API: apagado' );
	}

	public function test_keys_already_stored_are_detected_and_purged(): void {
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => serialize( array( 'testmode' => 'yes', 'title' => 'W', 'private_key' => 'prv_prod_guardada', 'test_event_secret_key' => 'x' ) ) ), array( 'option_name' => Keys::OPTION ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( Keys::OPTION, 'options' );
		$this->assertEqualsCanonicalizing( array( 'private_key', 'test_event_secret_key' ), Keys::stored_key_fields() );
		$rows = ( new \Aralia\Security\Baseline() )->run( 'test' );
		$this->assertFalse( array_values( array_filter( $rows, static fn( $r ) => 'wompi_keys_in_db' === $r['id'] ) )[0]['ok'], 'baseline lo marca' );
		$this->assertSame( 2, Keys::purge_stored() );
		$this->assertSame( array(), Keys::stored_key_fields() );
		$this->assertSame( 'W', get_option( Keys::OPTION )['title'], 'Los demás ajustes se conservan' );
	}

	public function test_mode_comes_from_the_environment_not_from_an_editable_option(): void {
		update_option( Keys::OPTION, array( 'testmode' => 'yes' ) );
		// La constante del archivo de secretos manda sobre cualquier entorno y sobre la opción de la base de datos.
		$this->assertTrue( defined( 'ARALIA_WOMPI_MODE' ) );
		foreach ( array( 'test', 'local', 'production', '', 'staging' ) as $env ) {
			$this->assertSame( constant( 'ARALIA_WOMPI_MODE' ), Keys::mode( $env ), "Entorno '$env'" );
		}
	}

	public function test_the_static_callbacks_and_the_receipt_widget_of_the_plugin_are_taken_over(): void {
		require_once __DIR__ . '/../../Support/Doubles/Wompi_Portal_Pagos_Gateway_Custom.php';
		add_filter( 'woocommerce_thankyou_order_key', array( 'Wompi_Portal_Pagos_Gateway_Custom', 'thankyou_order_key' ) );
		add_action( 'woocommerce_thankyou_order_received_text', array( 'Wompi_Portal_Pagos_Gateway_Custom', 'thankyou_order_received_text' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( 'Wompi_Portal_Pagos_Gateway_Custom', 'admin_order_data_after_order_details' ) );
		Neutralize::remove_plugin_hooks();
		$this->assertFalse( has_filter( 'woocommerce_thankyou_order_key', array( 'Wompi_Portal_Pagos_Gateway_Custom', 'thankyou_order_key' ) ), 'Sin la clave real del pedido en "Gracias" (I-2)' );
		$this->assertFalse( has_action( 'woocommerce_thankyou_order_received_text', array( 'Wompi_Portal_Pagos_Gateway_Custom', 'thankyou_order_received_text' ) ) );
		$this->assertFalse( has_action( 'woocommerce_admin_order_data_after_order_details', array( 'Wompi_Portal_Pagos_Gateway_Custom', 'admin_order_data_after_order_details' ) ) );

		// El widget se quita DENTRO de su propio gancho (la pasarela puede registrarlo después de wp_loaded) y avisa mientras no haya order-pay propio.
		add_action( 'woocommerce_receipt_wompi', array( 'Wompi_Portal_Pagos_Gateway_Custom', 'generate_wompi_widget' ) );
		ob_start();
		do_action( 'woocommerce_receipt_wompi', 1 );
		$html = (string) ob_get_clean();
		$this->assertSame( 0, \Wompi_Portal_Pagos_Gateway_Custom::$called, 'El widget del plugin no se renderiza' );
		$this->assertStringContainsString( 'no está disponible', $html );
	}

	public function test_the_real_rest_endpoint_of_the_gateway_never_returns_the_keys(): void {
		require_once __DIR__ . '/../../Support/Doubles/Aralia_Test_Wompi_Gateway.php';
		$add = static fn( $g ) => array_merge( (array) $g, array( 'Aralia_Test_Wompi_Gateway' ) );
		add_filter( 'woocommerce_payment_gateways', $add );
		WC()->payment_gateways()->init();
		wp_set_current_user( (int) wp_insert_user( array( 'user_login' => 'sm' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'sm' . wp_rand() . '@example.com', 'role' => 'shop_manager' ) ) );
		do_action( 'rest_api_init' );
		$get = static fn( string $path ) => rest_do_request( new \WP_REST_Request( 'GET', $path ) );
		try {
			// Premisa de control: SIN el filtro del guard, el controlador REST real SÍ entrega las llaves (la prueba discrimina).
			remove_filter( 'woocommerce_rest_prepare_payment_gateway', array( Keys::class, 'strip_rest_keys' ), PHP_INT_MAX );
			$open = (string) wp_json_encode( $get( '/wc/v3/payment_gateways/wompi' )->get_data() );
			add_filter( 'woocommerce_rest_prepare_payment_gateway', array( Keys::class, 'strip_rest_keys' ), PHP_INT_MAX, 2 );
			$this->assertStringContainsString( ARALIA_WOMPI_EVENTS_TEST, $open, 'Premisa: sin el filtro la llave de eventos saldría por REST' );

			$res  = $get( '/wc/v3/payment_gateways/wompi' );
			$json = (string) wp_json_encode( $res->get_data() );
			$this->assertSame( 200, $res->get_status(), $json );
			foreach ( array( ARALIA_WOMPI_PRV_TEST, ARALIA_WOMPI_EVENTS_TEST, ARALIA_WOMPI_INTEGRITY_TEST, ARALIA_WOMPI_PUB_TEST ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $json, 'Ninguna llave sale por REST' );
			}
			foreach ( array_keys( Keys::FIELDS ) as $field ) {
				$this->assertArrayNotHasKey( $field, (array) $res->get_data()['settings'], "Campo $field" );
			}
			$this->assertArrayHasKey( 'title', (array) $res->get_data()['settings'], 'Los demás ajustes sí salen' );
			$this->assertStringNotContainsString( ARALIA_WOMPI_EVENTS_TEST, (string) wp_json_encode( $get( '/wc/v3/payment_gateways' )->get_data() ), 'Tampoco en el listado' );
		} finally {
			if ( ! has_filter( 'woocommerce_rest_prepare_payment_gateway', array( Keys::class, 'strip_rest_keys' ) ) ) {
				add_filter( 'woocommerce_rest_prepare_payment_gateway', array( Keys::class, 'strip_rest_keys' ), PHP_INT_MAX, 2 );
			}
			remove_filter( 'woocommerce_payment_gateways', $add );
			WC()->payment_gateways()->init();
			wp_set_current_user( 0 );
		}
	}

	public function test_the_plugin_mode_follows_the_guard_mode_when_the_constant_decides(): void {
		update_option( Keys::OPTION, array( 'testmode' => 'no' ) );
		$this->assertTrue( defined( 'ARALIA_WOMPI_MODE' ), 'El arranque de las pruebas fija el modo' );
		$expected = 'test' === constant( 'ARALIA_WOMPI_MODE' ) ? 'yes' : 'no';
		$this->assertSame( $expected, get_option( Keys::OPTION )['testmode'], 'El plugin y el guard usan el mismo juego de llaves' );
	}

	public function test_a_just_created_transaction_that_the_api_does_not_know_yet_is_not_a_decline(): void {
		$o = wc_create_order();
		$o->set_total( '35000' );
		$o->update_meta_data( '_ara_attempts', array( array( 'reference' => '1-1', 'transaction_id' => 'TX-NEW', 'at' => gmdate( 'c' ) ) ) );
		$o->save();
		WompiFixtures::mock_api( array( 'TX-NEW' => 404 ) );
		$this->assertNull( Lookup::for_order( null, wc_get_order( $o->get_id() ) ), 'Reciente: no se sabe (el vencimiento reintenta)' );
		$o->update_meta_data( '_ara_attempts', array( array( 'reference' => '1-1', 'transaction_id' => 'TX-NEW', 'at' => gmdate( 'c', time() - HOUR_IN_SECONDS ) ) ) );
		$o->save();
		$this->assertSame( 'DECLINED', Lookup::for_order( null, wc_get_order( $o->get_id() ) ), 'Vieja y desconocida: no es de este pedido' );
		$o->delete( true );
	}

	public function test_a_fresh_install_without_the_settings_row_still_gets_the_keys_injected(): void {
		delete_option( Keys::OPTION );
		$s = get_option( Keys::OPTION, array() );
		$this->assertSame( ARALIA_WOMPI_EVENTS_TEST, $s['test_event_secret_key'] );
		$this->assertSame( 'no', $s['logging'] );
	}

	/**
	 * @dataProvider mode_cases
	 */
	public function test_the_mode_decision_table( $constant, string $env, string $testmode, string $expected ): void {
		$this->assertSame( $expected, Keys::decide_mode( $constant, $env, $testmode ) );
	}

	/**
	 * @return array<string,array{0:mixed,1:string,2:string,3:string}>
	 */
	public static function mode_cases(): array {
		return array(
			'constante test manda en producción' => array( 'test', 'production', 'no', 'test' ),
			'constante prod manda en local'      => array( 'prod', 'local', 'yes', 'prod' ),
			'constante inválida se ignora'       => array( 'sandbox', 'production', 'yes', 'prod' ),
			'producción ignora testmode'         => array( null, 'production', 'yes', 'prod' ),
			'staging ignora testmode'            => array( null, 'staging', 'yes', 'prod' ),
			'entorno vacío ignora testmode'      => array( null, '', 'yes', 'prod' ),
			'entorno mal escrito'                => array( null, 'prod', 'yes', 'prod' ),
			'local obedece testmode=yes'         => array( null, 'local', 'yes', 'test' ),
			'test obedece testmode=yes'          => array( null, 'test', 'yes', 'test' ),
			'local con testmode=no'              => array( null, 'local', 'no', 'prod' ),
			'local sin testmode'                 => array( null, 'local', '', 'prod' ),
		);
	}
}
