<?php
/**
 * T036: límites anti-abuso.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Database\Migrator;
use Aralia\Security\AbuseLimits;
use Aralia\Security\RateLimiter;
use Aralia\Tests\Support\SecurityTestCase;

final class AbuseLimitsTest extends SecurityTestCase {

	private const IP    = '203.0.113.5';
	private const PHONE = '3001234567';
	private const MAIL  = 'ana@example.com';

	/**
	 * @param array<string,int> $pending Pendientes por tipo.
	 */
	private function limits( array $pending = array() ): AbuseLimits {
		return new AbuseLimits( new RateLimiter(), $this->crypto(), static fn( string $kind ): int => $pending[ $kind ] ?? 0 );
	}

	public function test_a_clean_attempt_is_allowed(): void {
		$res = $this->limits()->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1, 3 ) );
		$this->assertSame( 'allow', $res['decision'] );
		$this->assertSame( array(), $res['reasons'] );
	}

	public function test_more_than_three_units_in_a_line_is_blocked(): void {
		$res = $this->limits()->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1, 4 ) );
		$this->assertSame( 'block', $res['decision'] );
		$this->assertContains( 'units_per_line', $res['reasons'] );
	}

	public function test_two_pending_orders_per_contact_block_the_third(): void {
		$this->assertSame( 'allow', $this->limits( array( 'phone' => 1, 'email' => 1 ) )->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) )['decision'] );
		$phone = $this->limits( array( 'phone' => 2 ) )->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) );
		$this->assertSame( 'block', $phone['decision'] );
		$this->assertContains( 'pending_phone', $phone['reasons'] );
		$email = $this->limits( array( 'email' => 2 ) )->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) );
		$this->assertContains( 'pending_email', $email['reasons'] );
	}

	public function test_pending_by_ip_is_a_soft_signal_that_asks_for_the_visible_challenge(): void {
		$this->assertSame( 'allow', $this->limits( array( 'ip' => 8 ) )->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) )['decision'] );
		$res = $this->limits( array( 'ip' => 9 ) )->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) );
		$this->assertSame( 'challenge', $res['decision'] );
		$this->assertContains( 'pending_ip', $res['reasons'] );
	}

	public function test_declines_by_origin_escalate_to_challenge_then_block(): void {
		$limits = $this->limits();
		for ( $i = 1; $i <= 5; $i++ ) {
			$limits->record_decline( self::IP );
		}
		$this->assertSame( 'allow', $limits->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) )['decision'], 'Hasta 5 por hora es normal' );
		$limits->record_decline( self::IP );
		$this->assertSame( 'challenge', $limits->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) )['decision'], 'Más de 5: desafío visible' );
		for ( $i = 7; $i <= 11; $i++ ) {
			$limits->record_decline( self::IP );
		}
		$res = $limits->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) );
		$this->assertSame( 'block', $res['decision'], 'Más de 10: bloqueo' );
		$this->assertSame( 'allow', $limits->evaluate_checkout( '198.51.100.1', self::PHONE, self::MAIL, array( 1 ) )['decision'], 'Otra IP no se ve afectada' );
	}

	public function test_coupon_attempts_are_capped_per_session_and_ip_and_always_need_turnstile(): void {
		$limits = $this->limits();
		for ( $i = 1; $i <= 10; $i++ ) {
			$r = $limits->coupon_attempt( 'sesion-1', self::IP );
			$this->assertTrue( $r['allowed'], "intento $i" );
			$this->assertTrue( $r['needs_turnstile'] );
		}
		$this->assertFalse( $limits->coupon_attempt( 'sesion-1', self::IP )['allowed'], 'El 11.º se rechaza' );
		$this->assertTrue( $limits->coupon_attempt( 'sesion-2', self::IP )['allowed'], 'Otra sesión tiene su propio cupo' );
		$this->assertTrue( $limits->coupon_attempt( 'sesion-1', '198.51.100.9' )['allowed'], 'Otra IP también' );
	}

	public function test_invalid_contact_or_ip_blocks_instead_of_skipping_the_checks(): void {
		$bad_phone = $this->limits()->evaluate_checkout( self::IP, '12345', self::MAIL, array( 1 ) );
		$this->assertSame( 'block', $bad_phone['decision'] );
		$bad_ip = $this->limits()->evaluate_checkout( 'no-ip', self::PHONE, self::MAIL, array( 1 ) );
		$this->assertSame( 'block', $bad_ip['decision'] );
	}

	public function test_when_pending_orders_cannot_be_counted_it_asks_for_the_visible_challenge_instead_of_blocking_everyone(): void {
		$res = ( new AbuseLimits( new RateLimiter(), $this->crypto(), static fn(): int => PHP_INT_MAX ) )->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) );
		$this->assertSame( 'challenge', $res['decision'], 'Si no se puede contar no se bloquea a toda la tienda (DoS): se exige el desafío' );
		$this->assertContains( 'pending_unknown', $res['reasons'] );
		$limits = new AbuseLimits( new RateLimiter(), $this->crypto() );
		$this->assertSame( PHP_INT_MAX, $limits->count_pending_orders( 'desconocido', 'x' ), 'Un tipo desconocido no se da por bueno' );
		$this->assertSame( 0, $limits->count_pending_orders( 'phone', str_repeat( 'a', 64 ) ), 'Sin pedidos con esa huella hay 0' );
	}

	public function test_rate_limit_failure_degrades_to_challenge_and_coupons_fail_closed(): void {
		global $wpdb;
		$limits = $this->limits();
		$wpdb->query( 'DROP TABLE `' . Migrator::tables()['rate_limits'] . '`' ); // phpcs:ignore WordPress.DB
		$prev = $wpdb->suppress_errors( true );
		$res  = $limits->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) );
		$coup = $limits->coupon_attempt( 's', self::IP );
		$wpdb->suppress_errors( $prev );
		$this->assertSame( 'challenge', $res['decision'] );
		$this->assertContains( 'limits_unavailable', $res['reasons'] );
		$this->assertFalse( $coup['allowed'], 'Si el limitador no responde, el código de descuento no se admite' );
	}

	public function test_the_decline_block_lasts_one_hour_from_the_crossing_with_its_own_window(): void {
		global $wpdb;
		$limits = $this->limits();
		for ( $i = 0; $i < 11; $i++ ) {
			$limits->record_decline( self::IP );
		}
		$this->assertSame( 'block', $limits->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) )['decision'] );
		// Aunque la ventana de conteo expire, el bloqueo sigue (tiene su propia ventana de 1 h).
		$wpdb->query( "UPDATE `" . Migrator::tables()['rate_limits'] . "` SET window_start = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE bucket = 'decline'" ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'block', $limits->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) )['decision'], 'El bloqueo no se levanta al vencer la ventana de conteo' );
		$wpdb->query( "UPDATE `" . Migrator::tables()['rate_limits'] . "` SET window_start = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE bucket = 'decline_block'" ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'allow', $limits->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) )['decision'], 'Pasada 1 h desde el bloqueo, se levanta' );
	}

	public function test_rotating_the_session_cookie_does_not_reset_the_coupon_limit(): void {
		$limits = $this->limits();
		$allowed = 0;
		for ( $i = 0; $i < 40; $i++ ) {
			$allowed += $limits->coupon_attempt( 'sesion-' . $i, self::IP )['allowed'] ? 1 : 0;
		}
		$this->assertSame( AbuseLimits::COUPON_ATTEMPTS_PER_IP, $allowed, 'Cambiar de sesión en cada intento no da intentos ilimitados: hay un tope por IP' );
	}

	public function test_email_aliases_count_as_the_same_mailbox_for_the_pending_limit(): void {
		$limits = new AbuseLimits( new RateLimiter(), $this->crypto(), static function ( string $kind, string $hash ) {
			return 'email' === $kind && hash_equals( $hash, ( new \Aralia\Privacy\Crypto( base64_encode( str_repeat( 'a', 32 ) ), base64_encode( str_repeat( 'b', 32 ) ) ) )->email_limit_hash( 'ana@example.com' ) ) ? 2 : 0;
		} );
		$this->assertSame( 'block', $limits->evaluate_checkout( self::IP, self::PHONE, 'ana+oferta@example.com', array( 1 ) )['decision'], 'Un alias +etiqueta es el mismo buzón' );
	}

	public function test_default_counter_counts_pending_orders_by_the_hashes_stored_in_the_order(): void {
		$limits = new AbuseLimits( new RateLimiter(), $this->crypto() );
		$meta   = $limits->order_meta( self::IP, self::PHONE, self::MAIL );
		$orders = array();
		$other  = wc_create_order( array( 'status' => 'pending' ) );
		$other->update_meta_data( '_ara_phone_hash', str_repeat( 'e', 64 ) );
		$other->save();
		$orders[] = $other;
		for ( $i = 0; $i < 3; $i++ ) {
			$order = wc_create_order( array( 'status' => 'pending' ) );
			foreach ( $meta as $key => $value ) {
				$order->update_meta_data( $key, $value );
			}
			$order->save();
			$orders[] = $order;
		}
		$done = wc_create_order( array( 'status' => 'pending' ) );
		$done->update_meta_data( '_ara_phone_hash', $meta['_ara_phone_hash'] );
		$done->save();
		\Aralia\Tests\Support\OrderStates::walk( $done, 'processing' ); // Un pedido ya no puede nacer pagado.

		try {
			$this->assertSame( 3, $limits->count_pending_orders( 'phone', $meta['_ara_phone_hash'] ), 'Solo cuentan los pendientes de ESA huella (no los de otra persona ni los ya pagados)' );
			$this->assertSame( 3, $limits->count_pending_orders( 'email', $meta['_ara_email_hash'] ) );
			$this->assertSame( 3, $limits->count_pending_orders( 'ip', $meta['_ara_ip_key'] ) );
			$this->assertSame( 0, $limits->count_pending_orders( 'phone', str_repeat( 'f', 64 ) ) );
			$this->assertSame( 1, $limits->count_pending_orders( 'phone', str_repeat( 'e', 64 ) ), 'La otra persona tiene 1' );
			$this->assertSame( 'block', $limits->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) )['decision'] );
		} finally {
			foreach ( array_merge( $orders, array( $done ) ) as $o ) {
				$o->delete( true );
			}
		}
	}

	public function test_order_meta_never_contains_raw_contact_data(): void {
		$meta = $this->limits()->order_meta( self::IP, self::PHONE, self::MAIL );
		foreach ( $meta as $value ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $value );
		}
	}

	public function test_the_decline_block_starts_above_ten_not_at_ten(): void {
		$limits = $this->limits();
		for ( $i = 1; $i <= 10; $i++ ) {
			$limits->record_decline( self::IP );
		}
		$this->assertSame( 'challenge', $limits->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) )['decision'], 'Con 10 rechazos solo hay desafío (> 10 bloquea)' );
		$limits->record_decline( self::IP );
		$this->assertSame( 'block', $limits->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) )['decision'] );
	}

	public function test_when_the_order_store_ignores_the_meta_filter_it_falls_back_to_a_bounded_scan_and_counts_correctly(): void {
		$meta  = $this->limits()->order_meta( self::IP, self::PHONE, self::MAIL );
		$mine  = wc_create_order( array( 'status' => 'pending' ) );
		$mine->update_meta_data( '_ara_phone_hash', $meta['_ara_phone_hash'] );
		$mine->save();
		$other = wc_create_order( array( 'status' => 'pending' ) );
		$other->update_meta_data( '_ara_phone_hash', str_repeat( 'e', 64 ) );
		$other->save();
		$calls = array();
		// Un almacenamiento que IGNORA meta_key/meta_value: devuelve todos los pendientes en la consulta filtrada.
		$query = static function ( array $args ) use ( &$calls ) {
			$calls[] = isset( $args['meta_key'] ) ? 'filtrada' : 'escaneo';
			return wc_get_orders( array_diff_key( $args, array( 'meta_key' => 1, 'meta_value' => 1 ) ) );
		};
		try {
			$limits = new AbuseLimits( new RateLimiter(), $this->crypto(), null, $query );
			$this->assertSame( 1, $limits->count_pending_orders( 'phone', $meta['_ara_phone_hash'] ), 'Cuenta solo los del contacto aunque el filtro se ignore' );
			$this->assertSame( array( 'filtrada', 'escaneo' ), $calls, 'Detecta que el filtro no se aplicó y revisa los pendientes' );
		} finally {
			$mine->delete( true );
			$other->delete( true );
		}
	}

	public function test_the_scan_fallback_gives_up_safely_when_there_are_too_many_pending_orders(): void {
		$fake_orders = array_fill( 0, AbuseLimits::PENDING_SCAN_LIMIT, new \WC_Order() );
		$query       = static function ( array $args ) use ( $fake_orders ) {
			return isset( $args['meta_key'] ) ? array( 999999991 ) : $fake_orders;
		};
		$limits = new AbuseLimits( new RateLimiter(), $this->crypto(), null, $query );
		$this->assertSame( PHP_INT_MAX, $limits->count_pending_orders( 'phone', str_repeat( 'a', 64 ) ), 'Demasiados para revisar: no se da por bueno' );
		$res = $limits->evaluate_checkout( self::IP, self::PHONE, self::MAIL, array( 1 ) );
		$this->assertSame( 'challenge', $res['decision'], 'y se degrada a desafío, sin bloquear a toda la tienda' );
	}
}
