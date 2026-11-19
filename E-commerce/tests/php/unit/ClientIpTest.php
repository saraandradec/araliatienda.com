<?php
/**
 * IP del cliente: una sola fuente que no se deja manipular.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Unit;

use Aralia\Support\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
	}

	/**
	 * @param array<string,string> $extra Cabeceras o variables adicionales.
	 * @return array<string,string>
	 */
	private function server( string $remote, array $extra = array() ): array {
		return array_merge( array( 'REMOTE_ADDR' => $remote ), $extra );
	}

	public function test_without_trusted_proxies_only_remote_addr_counts_whatever_the_headers_say(): void {
		$server = $this->server( '203.0.113.9', array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_X_REAL_IP' => '198.51.100.8' ) );
		$this->assertSame( '203.0.113.9', ClientIp::get( $server, array() ) );
	}

	public function test_a_forged_forwarded_header_from_an_untrusted_remote_is_ignored(): void {
		$server = $this->server( '203.0.113.9', array( 'HTTP_X_FORWARDED_FOR' => '10.0.0.1' ) );
		$this->assertSame( '203.0.113.9', ClientIp::get( $server, array( '192.0.2.10' ) ) );
	}

	public function test_behind_a_trusted_proxy_the_forwarded_chain_is_read_from_the_right(): void {
		$server = $this->server( '192.0.2.10', array( 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.50, 192.0.2.11' ) );
		// El atacante puso 1.2.3.4 a la izquierda; el cliente real, visto por nuestros proxies, es 203.0.113.50.
		$this->assertSame( '203.0.113.50', ClientIp::get( $server, array( '192.0.2.0/24' ) ) );
	}

	public function test_a_malformed_hop_next_to_our_proxy_falls_back_to_remote_addr(): void {
		$server = $this->server( '192.0.2.10', array( 'HTTP_X_FORWARDED_FOR' => '203.0.113.50, no-es-ip' ) );
		$this->assertSame( '192.0.2.10', ClientIp::get( $server, array( '192.0.2.10' ) ), 'Lo que escribió el último salto no es una IP: no se confía en nada de la cadena' );
	}

	public function test_garbage_far_to_the_left_is_attacker_controlled_and_never_reached(): void {
		$server = $this->server( '192.0.2.10', array( 'HTTP_X_FORWARDED_FOR' => 'no-es-ip, 203.0.113.50' ) );
		$this->assertSame( '203.0.113.50', ClientIp::get( $server, array( '192.0.2.10' ) ), 'Se toma el primer salto no confiable desde la derecha; lo de la izquierda lo controla el cliente' );
	}

	public function test_all_hops_trusted_returns_remote_addr(): void {
		$server = $this->server( '192.0.2.10', array( 'HTTP_X_FORWARDED_FOR' => '192.0.2.11, 192.0.2.12' ) );
		$this->assertSame( '192.0.2.10', ClientIp::get( $server, array( '192.0.2.0/24' ) ) );
	}

	public function test_ipv4_mapped_ipv6_is_returned_as_ipv4(): void {
		$this->assertSame( '203.0.113.9', ClientIp::get( $this->server( '::ffff:203.0.113.9' ), array() ) );
		$this->assertSame( '203.0.113.9', ClientIp::normalize( '::FFFF:203.0.113.9' ) );
	}

	/**
	 * @dataProvider provide_invalid
	 */
	public function test_invalid_remote_addr_gives_an_empty_string( string $remote ): void {
		$this->assertSame( '', ClientIp::get( $this->server( $remote ), array() ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_invalid(): array {
		return array(
			'vacía'     => array( '' ),
			'texto'     => array( 'localhost' ),
			'con ruido' => array( '203.0.113.9; DROP' ),
			'octeto'    => array( '999.1.1.1' ),
		);
	}

	public function test_missing_remote_addr_gives_an_empty_string(): void {
		$this->assertSame( '', ClientIp::get( array(), array() ) );
	}

	/**
	 * @dataProvider provide_cidr
	 */
	public function test_cidr_matching( string $ip, string $entry, bool $expected ): void {
		$this->assertSame( $expected, ClientIp::in_list( $ip, array( $entry ) ), "$ip en $entry" );
	}

	/**
	 * @return array<string,array{string,string,bool}>
	 */
	public static function provide_cidr(): array {
		return array(
			'exacta'              => array( '192.0.2.10', '192.0.2.10', true ),
			'otra exacta'         => array( '192.0.2.11', '192.0.2.10', false ),
			'/24 dentro'          => array( '192.0.2.200', '192.0.2.0/24', true ),
			'/24 fuera'           => array( '192.0.3.1', '192.0.2.0/24', false ),
			'/25 borde dentro'    => array( '192.0.2.127', '192.0.2.0/25', true ),
			'/25 borde fuera'     => array( '192.0.2.128', '192.0.2.0/25', false ),
			'/0 todo'             => array( '8.8.8.8', '0.0.0.0/0', true ),
			'/32'                 => array( '192.0.2.10', '192.0.2.10/32', true ),
			'v6 /48 dentro'       => array( '2001:db8:1:5::9', '2001:db8:1::/48', true ),
			'v6 /48 fuera'        => array( '2001:db8:2::9', '2001:db8:1::/48', false ),
			'familias distintas'  => array( '192.0.2.10', '2001:db8::/32', false ),
			'máscara inválida'    => array( '192.0.2.10', '192.0.2.0/abc', false ),
			'máscara enorme'      => array( '192.0.2.10', '192.0.2.0/99', false ),
			'entrada inválida'    => array( '192.0.2.10', 'basura', false ),
		);
	}

	public function test_ports_in_the_forwarded_chain_are_stripped(): void {
		$server = $this->server( '192.0.2.10', array( 'HTTP_X_FORWARDED_FOR' => '203.0.113.50:51234' ) );
		$this->assertSame( '203.0.113.50', ClientIp::get( $server, array( '192.0.2.10' ) ) );
		$server = $this->server( '192.0.2.10', array( 'HTTP_X_FORWARDED_FOR' => '[2001:db8::7]:443' ) );
		$this->assertSame( '2001:db8::7', ClientIp::get( $server, array( '192.0.2.10' ) ) );
	}

	public function test_trusted_entries_are_normalized_so_mapped_ipv4_entries_still_match(): void {
		$this->assertTrue( ClientIp::in_list( '192.0.2.10', array( '::ffff:192.0.2.10' ) ) );
		// in_list normaliza el CIDR mapeado y lo trata como IPv4 (/24 sobre 192.0.2.0): coincide.
		$this->assertTrue( ClientIp::in_list( '192.0.2.77', array( '::ffff:192.0.2.0/24' ) ), 'Un CIDR mapeado se interpreta como IPv4' );
		// Pero el filtro de la constante lo trata como entrada IPv6 (lleva ":") y exige /32 o más: un /24 mapeado se descarta.
		$log  = tempnam( sys_get_temp_dir(), 'aralia-ip' );
		$prev = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet
		$this->assertSame( array(), ClientIp::filter_entries( array( '::ffff:192.0.2.0/24' ) ) );
		ini_set( 'error_log', (string) $prev ); // phpcs:ignore WordPress.PHP.IniSet
		unlink( $log );
	}

	public function test_strip_port_leaves_plain_addresses_untouched(): void {
		foreach ( array( '203.0.113.50', '2001:db8::1', '::1', 'basura' ) as $plain ) {
			$this->assertSame( $plain, ClientIp::strip_port( $plain ) );
		}
	}

	/**
	 * @dataProvider provide_masks
	 * @param string[] $entries  Entradas.
	 * @param string[] $expected Aceptadas.
	 * @param int      $logged_drops Entradas descartadas que deben dejar constancia (las vacías se ignoran sin log).
	 */
	public function test_filter_entries_drops_masks_that_would_trust_huge_ranges( array $entries, array $expected, int $logged_drops ): void {
		$log  = tempnam( sys_get_temp_dir(), 'aralia-ip' );
		$prev = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet
		$out  = ClientIp::filter_entries( $entries );
		ini_set( 'error_log', (string) $prev ); // phpcs:ignore WordPress.PHP.IniSet
		$logged = (string) file_get_contents( $log );
		unlink( $log );
		$this->assertSame( $expected, $out );
		$this->assertSame( $logged_drops, substr_count( $logged, 'descartada' ), 'Cada entrada descartada deja constancia en el log' );
	}

	/**
	 * @return array<string,array{string[],string[],int}>
	 */
	public static function provide_masks(): array {
		return array(
			'/8 IPv4 se ignora'        => array( array( '10.0.0.0/8' ), array(), 1 ),
			'/15 IPv4 se ignora'       => array( array( '10.0.0.0/15' ), array(), 1 ),
			'/16 IPv4 se acepta'       => array( array( '10.0.0.0/16' ), array( '10.0.0.0/16' ), 0 ),
			'/24 IPv4 se acepta'       => array( array( '192.0.2.0/24' ), array( '192.0.2.0/24' ), 0 ),
			'/0 se ignora'             => array( array( '0.0.0.0/0' ), array(), 1 ),
			'/31 IPv6 se ignora'       => array( array( '2001:db8::/31' ), array(), 1 ),
			'/32 IPv6 se acepta'       => array( array( '2001:db8::/32' ), array( '2001:db8::/32' ), 0 ),
			'sin máscara se acepta'    => array( array( '192.0.2.10' ), array( '192.0.2.10' ), 0 ),
			'máscara no numérica'      => array( array( '192.0.2.0/abc' ), array(), 1 ),
			'máscara vacía'            => array( array( '192.0.2.0/' ), array(), 1 ),
			'ip ilegible sin máscara'  => array( array( 'abc' ), array(), 1 ),
			'ip ilegible con máscara'  => array( array( 'abc/24' ), array(), 1 ),
			'máscara fuera de rango'   => array( array( '1.2.3.4/99' ), array(), 1 ),
			'máscara IPv6 > 128'       => array( array( '2001:db8::/129' ), array(), 1 ),
			'IPv4 mapeado con máscara' => array( array( '::ffff:192.0.2.0/120' ), array(), 1 ),
			'IPv4 mapeado exacto'      => array( array( '::ffff:192.0.2.10' ), array( '::ffff:192.0.2.10' ), 0 ),
			'mezcla y espacios'        => array( array( ' 192.0.2.10 ', '10.0.0.0/8', '' ), array( '192.0.2.10' ), 1 ),
		);
	}
}
