<?php
/**
 * Prueba de contrato de la superficie del plugin oficial de Wompi y de quién cambia estados de pedido (T066, T-CONTRACT, D-01).
 *
 * Si una actualización del plugin cambia un gancho, el id de la pasarela, la opción de ajustes o dónde se cambia el estado de un
 * pedido, esta prueba falla y obliga a revisar el guard ANTES de actualizar (ops/third-party.lock, T168).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class WompiPluginSurfaceTest extends TestCase {

	private const VERSION = '3.2.1';

	/**
	 * Ganchos que registra el plugin (archivo => lista exacta de "tipo:gancho").
	 *
	 * @var array<string,string[]>
	 */
	private const HOOKS = array(
		'includes/admin/class-wc-wompi-admin-notices.php'        => array( 'action:admin_notices' ),
		'includes/class-wompi-portal-pagos-gateway-custom.php'   => array( 'action:woocommerce_receipt_wompi' ),
		'includes/class-wompi-portal-pagos-gateway.php'          => array( 'action:woocommerce_update_options_payment_gateways_', 'action:woocommerce_update_options_payment_gateways_' ),
		'includes/class-wompi-portal-pagos-main.php'             => array(
			'action:admin_enqueue_scripts',
			'action:woocommerce_update_options_payment_gateways_wompi',
			'action:woocommerce_after_checkout_validation',
			'action:woocommerce_thankyou_order_received_text',
			'action:woocommerce_admin_order_data_after_order_details',
			'filter:woocommerce_thankyou_order_key',
		),
		'includes/class-wompi-portal-pagos-order-statuses.php'   => array( 'action:init', 'filter:wc_order_statuses', 'action:woocommerce_process_shop_order_meta' ),
		'includes/class-wompi-portal-pagos-webhook-handler.php'  => array( 'action:woocommerce_api_wc_wompi' ),
		'wompi-portal-de-pagos.php'                              => array(
			'action:plugins_loaded',
			'action:admin_notices',
			'action:admin_notices',
			'filter:plugin_action_links_',
			'filter:woocommerce_payment_gateways',
			'action:before_woocommerce_init',
			'action:woocommerce_blocks_loaded',
			'action:woocommerce_blocks_payment_method_type_registration',
		),
	);

	/**
	 * Archivos de código propio donde se permite cambiar el estado de UN PEDIDO (payment_complete / update_status / set_status).
	 * Los `set_status` de Catalog/ y Setup/SampleCatalog son de PRODUCTOS (publicado/borrador).
	 *
	 * @var string[]
	 */
	private const ORDER_STATUS_WRITERS = array(
		'wp-content/mu-plugins/aralia-wompi-guard/EventHandler.php',
		'wp-content/plugins/aralia-core/src/Orders/Expiry.php',
		'wp-content/plugins/aralia-core/src/Orders/PartialStock.php',
		'wp-content/plugins/aralia-core/src/Orders/StateMachine.php',
		'wp-content/plugins/aralia-core/src/Orders/StockOnCreate.php',
	);

	private const PRODUCT_STATUS_WRITERS = array(
		'wp-content/plugins/aralia-core/src/Catalog/Importer.php',
		'wp-content/plugins/aralia-core/src/Catalog/PublishRule.php',
		'wp-content/plugins/aralia-core/src/Setup/SampleCatalog.php',
	);

	private function root(): string {
		return dirname( __DIR__, 3 );
	}

	private function plugin(): string {
		return $this->root() . '/wp-content/plugins/wompi-portal-de-pagos';
	}

	/**
	 * Archivos PHP bajo una carpeta (relativos a ella).
	 *
	 * @param string $dir Carpeta.
	 * @return string[]
	 */
	private function php_files( string $dir ): array {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() && false === strpos( $file->getPathname(), '/vendor/' ) && false === strpos( $file->getPathname(), '/node_modules/' ) ) {
				$out[] = ltrim( substr( $file->getPathname(), strlen( $dir ) ), '/' );
			}
		}
		sort( $out );
		return $out;
	}

	public function test_the_plugin_is_installed_at_the_pinned_version(): void {
		$this->assertFileExists( $this->plugin() . '/wompi-portal-de-pagos.php', 'El plugin de Wompi debe estar instalado (composer install)' );
		$this->assertMatchesRegularExpression( '/^\s*\*?\s*Version:\s*' . preg_quote( self::VERSION, '/' ) . '\s*$/m', (string) file_get_contents( $this->plugin() . '/wompi-portal-de-pagos.php' ) );
	}

	public function test_the_hooks_the_plugin_registers_are_exactly_the_ones_the_guard_was_designed_against(): void {
		$found = array();
		foreach ( $this->php_files( $this->plugin() ) as $file ) {
			if ( preg_match_all( "/\b(add_action|add_filter)\s*\(\s*'([a-z_]+)'/", (string) file_get_contents( $this->plugin() . '/' . $file ), $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					$found[ $file ][] = ( 'add_action' === $hit[1] ? 'action:' : 'filter:' ) . $hit[2];
				}
			}
		}
		ksort( $found );
		$expected = self::HOOKS;
		ksort( $expected );
		foreach ( $found as &$list ) {
			sort( $list );
		}
		unset( $list );
		foreach ( $expected as &$list ) {
			sort( $list );
		}
		unset( $list );
		$this->assertSame( $expected, $found, 'Cambió un gancho del plugin de Wompi: revisar el guard y actualizar este contrato' );
	}

	public function test_gateway_id_settings_option_and_webhook_endpoint_are_the_ones_the_guard_uses(): void {
		$gateway = (string) file_get_contents( $this->plugin() . '/includes/class-wompi-portal-pagos-gateway.php' );
		$this->assertMatchesRegularExpression( "/\\\$this->id\s*=\s*'wompi'/", $gateway, 'Id de la pasarela' );
		$main = (string) file_get_contents( $this->plugin() . '/includes/class-wompi-portal-pagos-main.php' );
		$this->assertStringContainsString( "get_option('woocommerce_wompi_settings'", $main, 'Opción de ajustes que el guard intercepta (Keys::OPTION)' );
		$this->assertSame( 'woocommerce_wompi_settings', \Aralia\Guard\Keys::OPTION );
		$this->assertStringContainsString( "add_action('woocommerce_api_wc_wompi'", (string) file_get_contents( $this->plugin() . '/includes/class-wompi-portal-pagos-webhook-handler.php' ) );
		// Las clases que el guard busca por nombre para quitar sus ganchos: si se renombran, remove_class_action no quitaría nada en silencio.
		foreach ( array( 'class-wompi-portal-pagos-webhook-handler.php' => 'Wompi_Portal_Pagos_Webhook_Handler', 'class-wompi-portal-pagos-order-statuses.php' => 'Wompi_Portal_Pagos_Order_Statuses', 'class-wompi-portal-pagos-gateway-custom.php' => 'Wompi_Portal_Pagos_Gateway_Custom', 'class-wompi-portal-pagos-gateway.php' => 'WompiPortalPagos_Gateway' ) as $file => $class ) {
			$this->assertMatchesRegularExpression( '/^\s*class\s+' . $class . '\b/m', (string) file_get_contents( $this->plugin() . '/includes/' . $file ), "Clase $class" );
		}
		$custom = (string) file_get_contents( $this->plugin() . '/includes/class-wompi-portal-pagos-gateway-custom.php' );
		foreach ( array( 'thankyou_order_key', 'thankyou_order_received_text', 'admin_order_data_after_order_details', 'generate_wompi_widget' ) as $method ) {
			$this->assertStringContainsString( 'function ' . $method, $custom, "Método que el guard neutraliza: $method" );
		}
		$statuses = (string) file_get_contents( $this->plugin() . '/includes/class-wompi-portal-pagos-order-statuses.php' );
		$this->assertStringContainsString( "'wc-voided'", $statuses, 'Estado Anulado que el guard oculta' );
		foreach ( array( 'test_public_key', 'test_private_key', 'test_event_secret_key', 'test_integrity_key', 'public_key', 'private_key', 'event_secret_key', 'integrity_key' ) as $field ) {
			$this->assertArrayHasKey( $field, \Aralia\Guard\Keys::FIELDS, "Campo de llave del plugin: $field" );
		}
	}

	public function test_the_plugin_changes_order_status_only_inside_its_webhook_handler(): void {
		$writers = array();
		foreach ( $this->php_files( $this->plugin() ) as $file ) {
			if ( preg_match( '/->(payment_complete|update_status|set_status)\s*\(|process_refund|wc_create_refund/', (string) file_get_contents( $this->plugin() . '/' . $file ) ) ) {
				$writers[] = $file;
			}
		}
		$this->assertSame( array( 'includes/class-wompi-portal-pagos-webhook-handler.php' ), $writers, 'Solo el manejador del webhook (que el guard quita) cambia estados' );
	}

	public function test_only_the_guard_and_the_order_state_code_change_order_status_in_our_own_code(): void {
		$writers = array();
		foreach ( array( 'wp-content/plugins/aralia-core', 'wp-content/mu-plugins', 'wp-content/themes/aralia' ) as $base ) {
			foreach ( $this->php_files( $this->root() . '/' . $base ) as $file ) {
				$path = $base . '/' . $file;
				if ( preg_match( '/->(payment_complete|update_status|set_status)\s*\(|process_refund|wc_create_refund/', (string) file_get_contents( $this->root() . '/' . $path ) ) ) {
					$writers[] = $path;
				}
			}
		}
		sort( $writers );
		$allowed = array_merge( self::ORDER_STATUS_WRITERS, self::PRODUCT_STATUS_WRITERS );
		sort( $allowed );
		$this->assertSame( $allowed, $writers, 'Código nuevo cambia estados: debe pasar por el guard o la máquina de estados (H13) y añadirse aquí tras revisión' );
	}

	public function test_no_own_code_issues_refunds_or_voids_toward_wompi(): void {
		foreach ( array( 'wp-content/plugins/aralia-core/src', 'wp-content/mu-plugins' ) as $base ) {
			foreach ( $this->php_files( $this->root() . '/' . $base ) as $file ) {
				$code = (string) file_get_contents( $this->root() . '/' . $base . '/' . $file );
				$this->assertDoesNotMatchRegularExpression( '#transactions/[^\'"]*(void|refund)#i', $code, "$base/$file no debe llamar a /void ni a reembolsos de Wompi (los hace Sara en su panel)" );
			}
		}
	}
}
