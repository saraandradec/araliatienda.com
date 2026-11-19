<?php
/**
 * Bootstrap de PHPUnit.
 *
 * - Sin WP_TESTS_DIR (job `php` del CI, suite unit): solo el autoloader de Composer.
 * - Con WP_TESTS_DIR (wp-env, tests-cli): carga la librería de pruebas de WordPress con WooCommerce y Aralía Core.
 *
 * @package Aralia
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$aralia_wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! is_string( $aralia_wp_tests_dir ) || '' === $aralia_wp_tests_dir || ! is_dir( $aralia_wp_tests_dir ) ) {
	return;
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
}
if ( ! defined( 'ARALIA_ENV' ) ) {
	define( 'ARALIA_ENV', 'test' );
}
// Llaves de privacidad SOLO de pruebas, generadas al vuelo (no son literales): 32 bytes cada una y distintas.
if ( ! defined( 'ARALIA_PII_KEY' ) ) {
	define( 'ARALIA_PII_KEY', base64_encode( str_repeat( 'p', 32 ) ) );
}
if ( ! defined( 'ARALIA_HMAC_KEY' ) ) {
	define( 'ARALIA_HMAC_KEY', base64_encode( str_repeat( 'h', 32 ) ) );
}

// Llaves de Wompi SOLO de pruebas (no son literales ni credenciales reales).
foreach ( array( 'PUB' => 'pub_test_', 'PRV' => 'prv_test_', 'EVENTS' => 'test_events_', 'INTEGRITY' => 'test_integrity_' ) as $aralia_kind => $aralia_prefix ) {
	if ( ! defined( 'ARALIA_WOMPI_' . $aralia_kind . '_TEST' ) ) {
		define( 'ARALIA_WOMPI_' . $aralia_kind . '_TEST', $aralia_prefix . str_repeat( 'x', 24 ) );
	}
}

if ( ! defined( 'ARALIA_WOMPI_MODE' ) ) {
	define( 'ARALIA_WOMPI_MODE', 'test' );
}

require_once $aralia_wp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$plugins = dirname( __DIR__, 2 ) . '/wp-content/plugins';
		require_once $plugins . '/woocommerce/woocommerce.php';
		require_once $plugins . '/aralia-core/aralia-core.php';
		// En wp-env el mu-plugin ya se carga desde wp-content/mu-plugins (mapeo): no se declara dos veces.
		if ( ! function_exists( 'aralia_hardening_env' ) ) {
			require_once dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/aralia-hardening.php';
		}
		if ( ! class_exists( '\\Aralia\\Guard\\Guard', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/wp-content/mu-plugins/aralia-wompi-guard.php';
		}
	}
);

// WooCommerce necesita su instalación (tablas y páginas) antes de las pruebas.
tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			\WC_Install::install();
			// La instalación recarga roles y capacidades.
			$GLOBALS['wp_roles'] = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}
);

require $aralia_wp_tests_dir . '/includes/bootstrap.php';
