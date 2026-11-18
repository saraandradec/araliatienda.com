<?php
/**
 * Las páginas de pago nunca se cachean, aunque la CSP esté apagada.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Cache\PaymentPages;
use Aralia\Security\Csp;
use Aralia\Tests\Support\IsolatedTestCase;

final class PaymentPagesTest extends IsolatedTestCase {

	protected function tear_down() {
		delete_option( Csp::OPTION_MODE );
		remove_all_filters( 'woocommerce_is_checkout' );
		parent::tear_down();
	}

	/**
	 * Simula el tipo de página con los mecanismos reales de WooCommerce.
	 */
	private function as_page( string $type ): void {
		global $wp;
		$wp->query_vars = array();
		if ( 'order-pay' === $type || 'order-received' === $type ) {
			$wp->query_vars[ $type ] = '123';
		}
		if ( 'checkout' === $type ) {
			add_filter( 'woocommerce_is_checkout', '__return_true' );
		}
	}

	/**
	 * @dataProvider provide_payment_pages
	 */
	public function test_payment_pages_are_marked_no_cache_whatever_the_csp_mode( string $page, string $mode ): void {
		update_option( Csp::OPTION_MODE, $mode );
		$this->as_page( $page );
		$signals = array();
		add_action( 'litespeed_control_set_nocache', static function ( $why ) use ( &$signals ) {
			$signals[] = $why;
		} );
		$this->assertTrue( PaymentPages::apply(), "$page con CSP en '$mode' debe marcarse como no cacheable" );
		$this->assertNotEmpty( $signals, 'LiteSpeed recibe la orden de no cachear' );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function provide_payment_pages(): array {
		return array(
			'checkout con CSP apagada'   => array( 'checkout', 'off' ),
			'order-pay con CSP apagada'  => array( 'order-pay', 'off' ),
			'gracias con CSP apagada'    => array( 'order-received', 'off' ),
			'checkout con report-only'   => array( 'checkout', 'report-only' ),
			'order-pay con enforce'      => array( 'order-pay', 'enforce' ),
		);
	}

	public function test_public_pages_are_not_marked(): void {
		$this->as_page( 'public' );
		$signals = array();
		add_action( 'litespeed_control_set_nocache', static function ( $why ) use ( &$signals ) {
			$signals[] = $why;
		} );
		$this->assertFalse( PaymentPages::apply() );
		$this->assertSame( array(), $signals, 'Una página pública no se marca' );
	}

	public function test_the_rule_is_registered_independently_of_the_csp(): void {
		$this->assertNotFalse( has_action( 'template_redirect', array( PaymentPages::class, 'on_template_redirect' ) ), 'Lo engancha Plugin::register_security' );
	}
}
