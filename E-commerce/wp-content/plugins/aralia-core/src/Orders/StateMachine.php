<?php
/**
 * Máquina de estados del pedido (T054, data-model §3, R-05).
 *
 * @package Aralia
 */

namespace Aralia\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Toda transición que no esté en la tabla se bloquea: el estado vuelve al anterior, queda en la auditoría y sale una alerta.
 * Además de "desde → hacia", cada transición dice QUIÉN puede hacerla:
 *  - `payment`: el guard, tras verificar el evento de Wompi (checksum + API + monto + moneda + referencia). Nadie más marca un pedido como pagado.
 *  - `staff`: una persona con permiso (`manage_woocommerce`) en el panel.
 *  - `system`: procesos propios (creación, vencimiento, cierre automático).
 */
final class StateMachine {

	public const PAYMENT = 'payment';
	public const STAFF   = 'staff';
	public const SYSTEM  = 'system';

	/**
	 * Estados "de borrador" desde los que nace un pedido.
	 *
	 * @var string[]
	 */
	private const DRAFT = array( 'checkout-draft', 'auto-draft', 'new' );

	/**
	 * Estados pagados activos: desde ellos se puede reembolsar o revertir.
	 *
	 * @var string[]
	 */
	private const PAID_ACTIVE = array( 'processing', 'ara-packed', 'ara-shipped', 'completed' );

	/**
	 * Comodín de actor: la transición no da ningún derecho de pago, así que la puede hacer cualquiera.
	 */
	private const ANY = '*';

	/**
	 * Actor vigente (lo fija run_as()).
	 *
	 * @var string[]
	 */
	private static array $actors = array();

	/**
	 * Tabla: "desde>hacia" => actores permitidos.
	 *
	 * @return array<string,string[]>
	 */
	public static function table(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$t = array();
		// Nacer como pendiente la hace cualquiera: la Store API lo hace en la petición de una clienta anónima. Pasar a `pending` no da pago.
		foreach ( self::DRAFT as $draft ) {
			$t[ $draft . '>pending' ] = array( self::ANY );
		}
		// La Store API crea el borrador (`checkout-draft`) de un pedido que aún no existe.
		$t['new>checkout-draft']     = array( self::ANY );
		$t['new>auto-draft']         = array( self::ANY );
		$t['pending>processing']     = array( self::PAYMENT );
		$t['pending>cancelled']      = array( self::SYSTEM, self::STAFF );
		$t['cancelled>processing']   = array( self::PAYMENT );
		$t['cancelled>ara-nostock']  = array( self::PAYMENT );
		$t['ara-nostock>processing'] = array( self::STAFF );
		$t['ara-nostock>refunded']   = array( self::STAFF );
		$t['processing>ara-packed']  = array( self::STAFF );
		$t['ara-packed>ara-shipped'] = array( self::STAFF );
		$t['ara-shipped>completed']  = array( self::STAFF, self::SYSTEM );
		foreach ( self::PAID_ACTIVE as $from ) {
			// La anulación hecha por Sara en Wompi llega como evento VOIDED válido: solo desde processing y ara-packed.
			$t[ $from . '>refunded' ] = in_array( $from, array( 'processing', 'ara-packed' ), true ) ? array( self::STAFF, self::PAYMENT ) : array( self::STAFF );
		}
		foreach ( array( 'processing', 'ara-nostock', 'ara-packed', 'ara-shipped', 'completed' ) as $from ) {
			$t[ $from . '>ara-reversed' ] = array( self::STAFF );
		}
		$cache = $t;
		return $t;
	}

	/**
	 * ¿Está permitida la transición para alguno de los actores dados? (Pura: se prueba sin WordPress.)
	 *
	 * @param string   $from   Estado actual (sin `wc-`).
	 * @param string   $to     Estado pedido (sin `wc-`).
	 * @param string[] $actors Actores vigentes.
	 * @return bool
	 */
	public static function is_allowed( string $from, string $to, array $actors ): bool {
		if ( $from === $to ) {
			return true;
		}
		$allowed = self::table()[ $from . '>' . $to ] ?? null;
		return null !== $allowed && ( in_array( self::ANY, $allowed, true ) || array() !== array_intersect( $allowed, $actors ) );
	}

	/**
	 * Ejecuta algo con un actor (el guard usa PAYMENT; vencimiento y creación, SYSTEM).
	 *
	 * @param string   $actor    Actor.
	 * @param callable $callback Código a ejecutar.
	 * @return mixed
	 */
	public static function run_as( string $actor, callable $callback ) {
		self::$actors[] = $actor;
		try {
			return $callback();
		} finally {
			array_pop( self::$actors );
		}
	}

	/**
	 * Actores vigentes en esta petición.
	 *
	 * @return string[]
	 */
	public static function current_actors(): array {
		$actors = self::$actors;
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ) ) {
			$actors[] = self::STAFF;
		}
		// WP-CLI solo da permisos de personal a los comandos `wp aralia ...`: un `wp action-scheduler run` por cron no los hereda.
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			$runner = call_user_func( array( '\\WP_CLI', 'get_runner' ) );
			$args   = is_object( $runner ) ? $runner->arguments : array(); // Propiedad privada con __get: isset() daría falso.
			if ( is_array( $args ) && 'aralia' === ( $args[0] ?? '' ) ) {
				$actors[] = self::STAFF;
			}
		}
		return array_values( array_unique( $actors ) );
	}

	/**
	 * Engancha la guarda: se evalúa justo antes de que WooCommerce escriba el pedido.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Dos veces: la primera antes que cualquier otro, la última por si un callback posterior cambia el estado (idempotente).
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'guard' ), 1, 1 );
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'guard' ), PHP_INT_MAX, 1 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( self::class, 'payment_complete_statuses' ) );
		add_filter( 'wp_untrash_post_status', array( self::class, 'untrash_status' ), PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( self::class, 'strip_trash_meta' ), 10, 1 );
	}

	/**
	 * `payment_complete()` solo avanza si el actor vigente es el guard: sin él no cambia fechas, transacción ni dispara
	 * `woocommerce_payment_complete` (analítica, correos), así que un cobro "marcado" por otro código no deja efectos.
	 *
	 * @param mixed $statuses Estados válidos para completar el pago.
	 * @return array<int,string>
	 */
	public static function payment_complete_statuses( $statuses ): array {
		return in_array( self::PAYMENT, self::current_actors(), true ) && is_array( $statuses ) ? $statuses : array();
	}

	/**
	 * Si el guardado cambia el estado a algo no permitido, lo deshace antes de que llegue a la base de datos.
	 * Cubre también los pedidos nuevos (`POST wc/v3/orders` con `status=processing`) y falla cerrado si no puede leer el estado guardado.
	 *
	 * @param mixed $order Pedido.
	 * @return void
	 */
	public static function guard( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$changes = $order->get_changes();
		if ( ! isset( $changes['status'] ) ) {
			return;
		}
		$to = self::strip( (string) $changes['status'] );
		if ( $order->get_id() > 0 ) {
			$from = self::stored_status( $order->get_id() );
			// Restaurar de la papelera nunca marca un pedido como pagado (ver trash_restore_allowed()).
			if ( 'trash' === $from && self::trash_restore_allowed( $order->get_id(), $to ) ) {
				return;
			}
		} else {
			$from = 'new';
		}
		if ( $from === $to || self::is_allowed( $from, $to, self::current_actors() ) ) {
			return;
		}
		self::revert( $order, $from, $to );
		/**
		 * Transición bloqueada: la auditoría la registra (AuditLog) y la alerta la levanta Plugin.
		 *
		 * @param int    $order_id Pedido.
		 * @param string $from     Estado actual.
		 * @param string $to       Estado pedido.
		 */
		do_action( 'aralia_blocked_transition', $order->get_id(), $from, $to );
		do_action( 'aralia_alert', 'blocked_transition', 'pedido ' . $order->get_id() . ' ' . $from . ' a ' . $to );
	}

	/**
	 * ¿Puede un pedido de la papelera volver a `$to`? Solo al estado que la papelera anotó en una opción propia (a diferencia de
	 * `_wp_trash_meta_status`, no se puede falsificar por la API REST). Sin anotación (pedido enviado antes de esta regla), solo a
	 * `pending`/`cancelled`: nunca a un estado pagado.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $to       Estado al que vuelve (sin prefijo).
	 * @return bool
	 */
	public static function trash_restore_allowed( int $order_id, string $to ): bool {
		$recorded = (string) get_option( StockOnCreate::TRASH_OPTION . $order_id, '' );
		if ( '' !== $recorded ) {
			return $to === $recorded;
		}
		return in_array( $to, array( 'pending', 'cancelled' ), true );
	}

	/**
	 * Con almacenamiento por posts, `wp_untrash_post()` escribe el estado previo directamente en la base de datos, sin pasar por el
	 * guardado del pedido (y por tanto sin la guarda). Este filtro aplica la misma regla a ese camino. Un pedido pendiente enviado a la
	 * papelera por WordPress guarda `wc-pending` como "previo" aunque `on_trash` ya lo canceló (y devolvió su stock): vuelve `cancelled`.
	 *
	 * @param mixed $new_status      Estado al que WordPress restauraría (con prefijo).
	 * @param mixed $post_id         Entrada.
	 * @param mixed $previous_status Estado previo.
	 * @return string
	 */
	public static function untrash_status( $new_status, $post_id = 0, $previous_status = '' ): string {
		unset( $previous_status );
		$new_status = (string) $new_status;
		$post_id    = (int) $post_id;
		if ( 'shop_order' !== get_post_type( $post_id ) ) {
			return $new_status;
		}
		$to       = self::strip( $new_status );
		$recorded = (string) get_option( StockOnCreate::TRASH_OPTION . $post_id, '' );
		$safe     = '' !== $recorded ? $recorded : 'cancelled';
		if ( self::trash_restore_allowed( $post_id, $to ) ) {
			return $new_status;
		}
		if ( 'pending' === $to && 'cancelled' === $safe ) {
			return 'wc-cancelled'; // "Previo" obsoleto de WordPress: no es un intento de ataque, no se alerta.
		}
		do_action( 'aralia_blocked_transition', $post_id, 'trash', $to );
		do_action( 'aralia_alert', 'blocked_transition', 'pedido ' . $post_id . ' trash a ' . $to );
		return 'wc-' . $safe;
	}

	/**
	 * Quita de las escrituras REST de pedidos las claves internas de la papelera (`_wp_trash_meta_*`): no son de la clienta ni de
	 * Sara, y falsificarlas era la vía para "restaurar" un pedido como pagado.
	 *
	 * @param mixed $order Pedido que se va a guardar.
	 * @return mixed
	 */
	public static function strip_trash_meta( $order ) {
		// Un pedido que ya está en la papelera no se toca: manda la opción propia, no este meta (que se ignora en los dos almacenes).
		if ( $order instanceof \WC_Order && 'trash' !== self::stored_status( $order->get_id() ) ) {
			foreach ( array( '_wp_trash_meta_status', '_wp_trash_meta_time' ) as $key ) {
				$order->delete_meta_data( $key );
			}
		}
		return $order;
	}

	/**
	 * Estado guardado, leído de la base de datos SIN caché (la caché de la petición puede ir por detrás de un webhook concurrente).
	 * Si no se puede leer, devuelve 'unknown': no está en la tabla, así que la guarda falla cerrada.
	 *
	 * @param int $order_id Pedido.
	 * @return string Estado sin prefijo.
	 */
	public static function stored_status( int $order_id ): string {
		global $wpdb;
		$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos ) {
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Sin caché a propósito.
		} else {
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Sin caché a propósito.
		}
		return is_string( $status ) && '' !== $status ? self::strip( $status ) : 'unknown';
	}

	/**
	 * Deja el pedido en su estado anterior sin que WooCommerce dispare los ganchos de una transición. Un borrador que se guarda con un
	 * estado no permitido queda `pending` (no desaparece del listado); si no se pudo leer el estado guardado, vuelve al del objeto.
	 * Las fechas de pago solo se quitan si el pedido no estaba pagado (un intento bloqueado no borra el rastro contable).
	 *
	 * @param \WC_Order $order Pedido.
	 * @param string    $from  Estado guardado.
	 * @param string    $to    Estado pedido (bloqueado).
	 * @return void
	 */
	private static function revert( \WC_Order $order, string $from, string $to ): void {
		unset( $to );
		$back = $from;
		if ( in_array( $from, self::DRAFT, true ) ) {
			$back = 'pending';
		} elseif ( 'unknown' === $from || '' === $from ) {
			$orig = self::strip( (string) ( $order->get_data()['status'] ?? '' ) );
			$back = '' !== $orig ? $orig : 'pending';
		}
		$order->set_status( $back );
		if ( in_array( $from, array_merge( self::DRAFT, array( 'pending', 'cancelled', 'failed', 'on-hold' ) ), true ) ) {
			$order->set_date_paid( null );
			$order->set_date_completed( null );
		}
		// set_status deja anotada una "transición" from→from: se descarta para que no se emitan correos ni ganchos de estado.
		// La propiedad se declara en WC_Order; si una versión de WooCommerce la renombrara, la prueba `never_reaches_the_database` falla.
		\Closure::bind(
			static function ( \WC_Order $o ): void {
				$o->status_transition = false;
			},
			null,
			\WC_Order::class
		)( $order );
	}

	/**
	 * Quita el prefijo `wc-`.
	 *
	 * @param string $status Estado.
	 * @return string
	 */
	private static function strip( string $status ): string {
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}
}
