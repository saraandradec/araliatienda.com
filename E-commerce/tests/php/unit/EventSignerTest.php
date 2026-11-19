<?php
/**
 * T162: la firma de eventos coincide con lo que verifica el guard (contracts/wompi-integration.md §3).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Unit;

use Aralia\Wompi\EventSigner;
use PHPUnit\Framework\TestCase;

final class EventSignerTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
		if ( ! function_exists( 'esc_html' ) ) {
			require_once __DIR__ . '/../Support/wp-escape-stub.php';
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function event(): array {
		return array(
			'event' => 'transaction.updated',
			'data'  => array(
				'transaction' => array(
					'id'              => '12345-1',
					'status'          => 'APPROVED',
					'amount_in_cents' => 3500000,
				),
			),
		);
	}

	public function test_checksum_is_sha256_of_values_timestamp_and_secret(): void {
		$signed = EventSigner::sign( $this->event(), 'test_events_secret', 1700000000 );
		$this->assertSame( hash( 'sha256', '12345-1' . 'APPROVED' . '3500000' . '1700000000' . 'test_events_secret' ), $signed['signature']['checksum'] );
		$this->assertSame( EventSigner::DEFAULT_PROPERTIES, $signed['signature']['properties'] );
		$this->assertSame( 1700000000, $signed['timestamp'] );
	}

	public function test_changing_amount_changes_the_checksum(): void {
		$a = EventSigner::sign( $this->event(), 's', 1 );
		$ev = $this->event();
		$ev['data']['transaction']['amount_in_cents'] = 100;
		$b = EventSigner::sign( $ev, 's', 1 );
		$this->assertNotSame( $a['signature']['checksum'], $b['signature']['checksum'] );
	}

	public function test_respects_custom_property_order(): void {
		$ev = $this->event();
		$ev['signature']['properties'] = array( 'transaction.status', 'transaction.id' );
		$signed = EventSigner::sign( $ev, 's', 5 );
		$this->assertSame( hash( 'sha256', 'APPROVED12345-1' . '5' . 's' ), $signed['signature']['checksum'] );
	}

	/**
	 * @dataProvider provide_bad
	 * @param array<string,mixed> $event  Evento.
	 * @param string              $secret Secreto.
	 */
	public function test_rejects_bad_input( array $event, string $secret ): void {
		$this->expectException( \InvalidArgumentException::class );
		EventSigner::sign( $event, $secret, 1 );
	}

	/**
	 * @return array<string,array{array<string,mixed>,string}>
	 */
	public static function provide_bad(): array {
		return array(
			'sin secreto'      => array( array( 'data' => array( 'transaction' => array( 'id' => 'a', 'status' => 'b', 'amount_in_cents' => 1 ) ) ), '' ),
			'sin data'         => array( array( 'event' => 'x' ), 's' ),
			'falta propiedad'  => array( array( 'data' => array( 'transaction' => array( 'id' => 'a' ) ) ), 's' ),
			'valor no simple'  => array( array( 'data' => array( 'transaction' => array( 'id' => array( 'x' ), 'status' => 'b', 'amount_in_cents' => 1 ) ) ), 's' ),
			'props vacías'     => array( array( 'data' => array( 'transaction' => array( 'id' => 'a' ) ), 'signature' => array( 'properties' => array() ) ), 's' ),
		);
	}
}
