<?php
/**
 * T025: cabeceras de seguridad.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Security\Headers;
use Aralia\Tests\Support\IsolatedTestCase;

final class HeadersTest extends IsolatedTestCase {

	public function test_https_gets_hsts_and_all_the_basics(): void {
		$h = Headers::build( true );
		$this->assertSame( 'max-age=31536000; includeSubDomains', $h['Strict-Transport-Security'] );
		$this->assertSame( 'nosniff', $h['X-Content-Type-Options'] );
		$this->assertSame( 'strict-origin-when-cross-origin', $h['Referrer-Policy'] );
		$this->assertStringContainsString( 'camera=()', $h['Permissions-Policy'] );
		$this->assertStringContainsString( 'payment=(self "https://checkout.wompi.co")', $h['Permissions-Policy'] );
		$this->assertSame( "frame-ancestors 'self'", $h['Content-Security-Policy'] );
		$this->assertSame( 'SAMEORIGIN', $h['X-Frame-Options'] );
	}

	public function test_http_never_sends_hsts(): void {
		$this->assertArrayNotHasKey( 'Strict-Transport-Security', Headers::build( false ) );
	}

	public function test_filter_merges_with_an_existing_csp_instead_of_overwriting_it(): void {
		$merged = Headers::filter_headers( array( 'Content-Security-Policy' => "default-src 'self'", 'X-Other' => '1' ) );
		$this->assertSame( "default-src 'self', frame-ancestors 'self'", $merged['Content-Security-Policy'] );
		$this->assertSame( '1', $merged['X-Other'] );
		$this->assertSame( "frame-ancestors 'self'", Headers::filter_headers( array() )['Content-Security-Policy'] );
	}

	public function test_filter_is_registered_on_wp_headers(): void {
		Headers::register();
		$this->assertNotFalse( has_filter( 'wp_headers', array( Headers::class, 'filter_headers' ) ) );
	}
}
