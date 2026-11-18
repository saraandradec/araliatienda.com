<?php
/**
 * Base que aísla el estado global de WordPress entre pruebas (ganchos, superglobales, consulta actual).
 *
 * Las pruebas de integración no usan WP_UnitTestCase (necesitan DDL real), así que no hay rollback automático: sin esto, quitar un gancho
 * o reasignar $wp_query en una prueba afectaría a todas las siguientes (y a la instancia global que engancha Plugin::register_security).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Support;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

abstract class IsolatedTestCase extends TestCase {

	/**
	 * Copia de $wp_filter.
	 *
	 * @var array<string,\WP_Hook>
	 */
	private array $hooks_snapshot = array();

	/**
	 * Copia de superglobales y globales de consulta.
	 *
	 * @var array<string,mixed>
	 */
	private array $globals_snapshot = array();

	/**
	 * Usuario actual al empezar.
	 *
	 * @var int
	 */
	private int $user_id_snapshot = 0;

	protected function set_up() {
		parent::set_up();
		global $wp_filter;
		$this->hooks_snapshot = array();
		foreach ( (array) $wp_filter as $name => $hook ) {
			$this->hooks_snapshot[ $name ] = clone $hook;
		}
		$this->user_id_snapshot = get_current_user_id();
		$this->globals_snapshot = array(
			'_SERVER'        => $_SERVER,
			'_POST'          => $_POST,
			'_GET'           => $_GET,
			'wp_query'       => $GLOBALS['wp_query'] ?? null,
			'wp_the_query'   => $GLOBALS['wp_the_query'] ?? null,
			'current_screen' => $GLOBALS['current_screen'] ?? null,
			'wp_query_vars'  => isset( $GLOBALS['wp'] ) ? $GLOBALS['wp']->query_vars : array(),
		);
	}

	protected function tear_down() {
		global $wp_filter;
		$wp_filter = $this->hooks_snapshot; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_SERVER   = $this->globals_snapshot['_SERVER'];
		$_POST     = $this->globals_snapshot['_POST'];
		$_GET      = $this->globals_snapshot['_GET'];
		foreach ( array( 'wp_query', 'wp_the_query', 'current_screen' ) as $name ) {
			$GLOBALS[ $name ] = $this->globals_snapshot[ $name ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		if ( isset( $GLOBALS['wp'] ) ) {
			$GLOBALS['wp']->query_vars = $this->globals_snapshot['wp_query_vars'];
		}
		wp_set_current_user( $this->user_id_snapshot );
		\Aralia\Security\Csp::reset();
		// Alertas reales que el AuditLog global haya encolado durante la prueba.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'aralia_deliver_alert' );
		}
		parent::tear_down();
	}
}
