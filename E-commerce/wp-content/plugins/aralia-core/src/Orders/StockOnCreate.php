<?php
/**
 * Stock y vencimiento al crear el pedido (T056, R-06, L-01).
 *
 * @package Aralia
 */

namespace Aralia\Orders;

use Aralia\Security\AbuseLimits;
use Aralia\Support\ClientIp;
use Aralia\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * El pedido descuenta stock desde que existe (no cuando se paga): `wc_reduce_stock_levels` y, a continuación, se libera la reserva
 * temporal del checkout (`wc_release_stock_for_order`) para no contar la pieza dos veces. Se engancha en las DOS rutas de creación
 * (checkout clásico y Store API de bloques): si una faltara, esa ruta no descontaría nada.
 */
final class StockOnCreate {

	public const HOLD_SECONDS = 30 * MINUTE_IN_SECONDS;

	/**
	 * Prefijo de la opción que guarda el estado con el que un pedido entró en la papelera.
	 */
	public const TRASH_OPTION = 'aralia_pre_trash_';

	/**
	 * Servicio de huellas de abuso (puede faltar si no hay llaves de privacidad).
	 *
	 * @var callable|null
	 */
	private static $abuse_factory = null;

	/**
	 * Engancha las dos rutas de creación.
	 *
	 * @param callable|null $abuse_factory Devuelve AbuseLimits o null.
	 * @return void
	 */
	public static function register( ?callable $abuse_factory = null ): void {
		self::$abuse_factory = $abuse_factory;
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_classic' ), 5, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_blocks' ), 5, 1 );
		// El checkout clásico reutiliza un pedido pendiente con el mismo carrito y le borra las líneas: antes se devuelve su stock.
		add_action( 'woocommerce_resume_order', array( self::class, 'on_resume' ), 1, 1 );
		// Mandar a la papelera un pedido pendiente (la papelera no pasa por el guardado) lo cancela antes, para devolver el stock.
		add_action( 'wp_trash_post', array( self::class, 'on_trash' ), 1, 1 );
		add_action( 'woocommerce_before_trash_order', array( self::class, 'on_trash' ), 1, 2 );
		add_action( 'untrashed_post', array( self::class, 'on_untrashed' ), 10, 1 );
		// Con HPOS, `woocommerce_untrash_order` se dispara ANTES de restaurar el estado (y de la guarda): la anotación se limpia cuando el estado sale de `trash`.
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status_left_trash' ), 10, 2 );
		add_action( 'before_delete_post', array( self::class, 'on_delete' ), 1, 1 );
		add_action( 'woocommerce_before_delete_order', array( self::class, 'on_delete' ), 1, 1 );
		// Un pedido que ya descontó su stock no vuelve a reservarlo al reintentar el pago (la reserva contaría la pieza dos veces).
		add_filter( 'woocommerce_order_hold_stock_minutes', array( self::class, 'no_double_hold' ), 10, 2 );
	}

	/**
	 * Checkout clásico.
	 *
	 * @param mixed $order_id Pedido.
	 * @param mixed $posted   Datos enviados.
	 * @param mixed $order    Pedido.
	 * @return void
	 */
	public static function on_classic( $order_id, $posted = null, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			self::handle( $order );
		}
	}

	/**
	 * Store API (checkout de bloques).
	 *
	 * @param mixed $order Pedido.
	 * @return void
	 */
	public static function on_blocks( $order ): void {
		if ( $order instanceof \WC_Order ) {
			self::handle( $order );
		}
	}

	/**
	 * Papelera: un pedido pendiente se cancela primero (como sistema) para que WooCommerce devuelva su stock, y se anota el estado
	 * con el que entra en la papelera. Se usa el mismo objeto que WooCommerce va a guardar, para que su "estado previo" sea el real.
	 *
	 * @param mixed $order_id Pedido (o entrada).
	 * @param mixed $order    Pedido que WooCommerce está enviando a la papelera (si lo da el gancho).
	 * @return void
	 */
	public static function on_trash( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$stored = StateMachine::stored_status( $order->get_id() );
		if ( 'trash' === $stored ) {
			return; // Con HPOS y sincronización el gancho se dispara dos veces: la segunda ya no sabe el estado real.
		}
		if ( 'pending' === $stored ) {
			StateMachine::run_as(
				StateMachine::SYSTEM,
				static function () use ( $order ): void {
					$order->update_status( 'cancelled', 'Enviado a la papelera sin pago: se devuelve el stock.' );
				}
			);
			$stored = StateMachine::stored_status( $order->get_id() );
		}
		update_option( self::TRASH_OPTION . $order->get_id(), $stored, false );
	}

	/**
	 * Restauración terminada: la anotación de la papelera ya no sirve (se vuelve a escribir si el pedido regresa a la papelera).
	 *
	 * @param mixed $order_id Pedido.
	 * @return void
	 */
	public static function on_untrashed( $order_id ): void {
		delete_option( self::TRASH_OPTION . (int) $order_id );
	}

	/**
	 * El pedido salió de la papelera (cambio de estado con origen `trash`): se limpia la anotación.
	 *
	 * @param mixed $order_id Pedido.
	 * @param mixed $from     Estado anterior.
	 * @return void
	 */
	public static function on_status_left_trash( $order_id, $from = '' ): void {
		if ( 'trash' === $from ) {
			self::on_untrashed( $order_id );
		}
	}

	/**
	 * Borrado definitivo: un pedido pendiente devuelve su stock antes de desaparecer, y se limpia la anotación de la papelera.
	 *
	 * @param mixed $order_id Pedido (o entrada).
	 * @return void
	 */
	public static function on_delete( $order_id ): void {
		$order_id = (int) $order_id;
		$order    = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order && 'pending' === StateMachine::stored_status( $order_id ) ) {
			StateMachine::run_as(
				StateMachine::SYSTEM,
				static function () use ( $order ): void {
					$order->update_status( 'cancelled', 'Pedido borrado sin pago: se devuelve el stock.' );
				}
			);
		}
		if ( $order_id > 0 ) {
			delete_option( self::TRASH_OPTION . $order_id );
		}
	}

	/**
	 * Cero minutos de reserva para un pedido que ya descontó su stock.
	 *
	 * @param mixed $minutes Minutos.
	 * @param mixed $order   Pedido.
	 * @return int
	 */
	public static function no_double_hold( $minutes, $order ): int {
		return $order instanceof \WC_Order && 'yes' === $order->get_meta( '_ara_created_handled', true ) ? 0 : (int) $minutes;
	}

	/**
	 * Un pedido pendiente se reutiliza: devuelve el stock de sus líneas actuales y lo deja listo para volver a descontar.
	 *
	 * @param mixed $order_id Pedido.
	 * @return void
	 */
	public static function on_resume( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		wc_increase_stock_levels( $order );
		$order->get_data_store()->set_stock_reduced( $order->get_id(), false );
		$order->delete_meta_data( '_ara_created_handled' );
		$order->save();
	}

	/**
	 * Descuenta el stock, guarda las huellas de abuso y programa el vencimiento. Idempotente (las dos rutas pueden dispararse).
	 *
	 * @param \WC_Order $order Pedido recién creado.
	 * @return void
	 */
	public static function handle( \WC_Order $order ): void {
		// Idempotencia: la bandera final y, por línea, `_reduced_stock` (wc_reduce_stock_levels no descuenta dos veces una línea).
		if ( 'yes' === $order->get_meta( '_ara_created_handled', true ) ) {
			return;
		}
		$order->update_meta_data( '_ara_expires_at', (string) ( Clock::now() + self::HOLD_SECONDS ) );
		// Huella de la sesión que creó el pedido: `order-status` la exige (junto con la clave) para mostrar el estado.
		if ( '' === (string) $order->get_meta( '_ara_session_hash', true ) && function_exists( 'WC' ) && is_object( WC()->session ) && method_exists( WC()->session, 'get_customer_id' ) && '' !== (string) WC()->session->get_customer_id() ) {
			$order->update_meta_data( '_ara_session_hash', \Aralia\Rest\OrderAccess::session_hash( (string) WC()->session->get_customer_id() ) );
		}
		$abuse = self::abuse_meta( $order );
		foreach ( $abuse as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		// Sin huellas, el pedido queda marcado: AbuseLimits lo cuenta como pendiente del contacto en lugar de dejarlo fuera de los topes.
		$order->update_meta_data( '_ara_abuse_unknown', array() === $abuse ? 'yes' : 'no' );
		$order->save();
		wc_reduce_stock_levels( $order );
		// wc_reduce_stock_levels no marca el pedido: sin esta marca WooCommerce no devolvería el stock al cancelar (wc_maybe_increase_stock_levels).
		$order->get_data_store()->set_stock_reduced( $order->get_id(), true );
		wc_release_stock_for_order( $order );
		Expiry::schedule( $order->get_id(), (int) $order->get_meta( '_ara_expires_at', true ) );
		// La bandera va al final: si algo falló antes, un segundo disparo (o la vigilancia) repara el pedido.
		$order->update_meta_data( '_ara_created_handled', 'yes' );
		$order->save();
	}

	/**
	 * Huellas de IP, celular y correo del pedido (T036) para contar pendientes sin guardar datos en claro.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<string,string>
	 */
	private static function abuse_meta( \WC_Order $order ): array {
		$abuse = self::$abuse_factory ? ( self::$abuse_factory )() : null;
		if ( ! $abuse instanceof AbuseLimits ) {
			return array();
		}
		try {
			return $abuse->order_meta( ClientIp::get(), (string) $order->get_billing_phone(), (string) $order->get_billing_email() );
		} catch ( \Throwable $e ) {
			unset( $e );
			return array();
		}
	}
}
