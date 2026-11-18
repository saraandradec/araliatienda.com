<?php
/**
 * T027: registro de actividad.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Database\Migrator;
use Aralia\Security\AuditLog;
use Aralia\Tests\Support\SecurityTestCase;

final class AuditLogTest extends SecurityTestCase {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( string $action = '' ): array {
		global $wpdb;
		$sql = 'SELECT * FROM `' . Migrator::tables()['audit_log'] . '`' . ( '' !== $action ? $wpdb->prepare( ' WHERE action = %s', $action ) : '' ) . ' ORDER BY id'; // phpcs:ignore WordPress.DB
		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public function test_records_a_row_with_json_state_and_hashed_ip(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$log = new AuditLog( $this->crypto() );
		$id  = $log->record( 'price_change', 'product', 42, array( 'precio' => '100' ), array( 'precio' => '90' ), 7 );
		$this->assertGreaterThan( 0, $id );
		$row = $this->rows( 'price_change' )[0];
		$this->assertSame( '7', $row['user_id'] );
		$this->assertSame( 'product', $row['object_type'] );
		$this->assertSame( '42', $row['object_id'] );
		$this->assertSame( array( 'precio' => '100' ), json_decode( $row['before_value'], true ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $row['ip_hash'] );
		$this->assertStringNotContainsString( '203.0.113', json_encode( $row ) );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_unknown_actions_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new AuditLog( $this->crypto() ) )->record( 'hacked<script>' );
	}

	public function test_sensitive_keys_are_redacted_recursively_and_size_is_capped(): void {
		$log = new AuditLog( $this->crypto() );
		$log->record(
			'settings_change',
			'option',
			0,
			array( 'woocommerce_wompi_settings' => array( 'private_key' => 'prv_prod_X', 'events_secret' => 's', 'enabled' => 'yes', 'nested' => array( 'customer_email' => 'a@b.co', 'ok' => 1 ) ) ),
			array( 'telefono' => '300', 'pass' => 'x' )
		);
		$row = $this->rows( 'settings_change' )[0];
		$this->assertStringNotContainsString( 'prv_prod_X', $row['before_value'] );
		$this->assertStringNotContainsString( 'a@b.co', $row['before_value'] );
		$before = json_decode( $row['before_value'], true );
		$this->assertSame( '[redactado]', $before['woocommerce_wompi_settings']['private_key'] );
		$this->assertSame( 'yes', $before['woocommerce_wompi_settings']['enabled'] );
		$this->assertSame( '[redactado]', json_decode( $row['after_value'], true )['pass'] );

		$log->record( 'settings_change', 'option', 0, null, array( 'blob' => str_repeat( 'x', 20000 ) ) );
		$big = $this->rows( 'settings_change' )[1];
		$this->assertLessThan( 200, strlen( $big['after_value'] ), 'Los estados enormes se sustituyen por un marcador' );
	}

	public function test_object_type_is_sanitized(): void {
		( new AuditLog( $this->crypto() ) )->record( 'login', "User'; DROP--", 1 );
		$this->assertSame( 'userdrop--', $this->rows( 'login' )[0]['object_type'] );
	}

	public function test_a_database_failure_does_not_break_the_request(): void {
		global $wpdb;
		$log = new AuditLog( $this->crypto() );
		$wpdb->query( 'DROP TABLE `' . Migrator::tables()['audit_log'] . '`' ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, $log->record( 'login', 'user', 1 ) );
	}

	public function test_hooks_record_logins_roles_users_and_failures(): void {
		// Los eventos los registra el AuditLog que el plugin engancha en init (Plugin::register_security).
		$uid = wp_insert_user( array( 'user_login' => 'auditada', 'user_pass' => wp_generate_password(), 'user_email' => 'auditada@example.com' ) );
		$this->assertIsInt( $uid );
		wp_update_user( array( 'ID' => $uid, 'role' => 'shop_manager' ) );
		do_action( 'wp_login_failed', 'auditada' );
		do_action( 'wp_login_failed', 'no-existe' );
		do_action( 'wp_login', 'auditada', get_user_by( 'id', $uid ) );

		$this->assertNotEmpty( $this->rows( 'user_created' ) );
		$this->assertNotEmpty( $this->rows( 'role_change' ) );
		$this->assertCount( 1, $this->rows( 'login' ) );
		$failed = $this->rows( 'login_failed' );
		$this->assertCount( 2, $failed );
		$this->assertTrue( json_decode( $failed[0]['after_value'], true )['usuario_existe'] );
		$this->assertFalse( json_decode( $failed[1]['after_value'], true )['usuario_existe'] );
		$this->assertStringNotContainsString( 'no-existe', json_encode( $failed ), 'El nombre escrito no se guarda' );
		wp_delete_user( $uid );
	}

	public function test_watched_options_prices_and_order_states_are_logged(): void {
		$default_role = get_option( 'default_role' );
		update_option( 'default_role', 'editor' );
		update_option( 'blogdescription', 'no vigilada' );
		update_option( 'default_role', $default_role );
		$settings = $this->rows( 'settings_change' );
		$this->assertGreaterThanOrEqual( 2, count( $settings ) );
		$this->assertCount( 0, array_filter( $settings, static fn( $r ) => false !== strpos( (string) $r['after_value'], 'no vigilada' ) ), 'Solo se vigilan las opciones de la lista' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Auditado' );
		$product->set_regular_price( '1000' );
		$product->save();
		$product->set_regular_price( '900' );
		$product->save();
		$this->assertNotEmpty( $this->rows( 'price_change' ) );
		$product->delete( true );

		$order = wc_create_order();
		$order->set_status( 'on-hold' );
		$order->save();
		$order->update_status( 'processing' );
		$this->assertNotEmpty( $this->rows( 'order_status_change' ) );

		do_action( 'aralia_blocked_transition', $order->get_id(), 'processing', 'pending' );
		$blocked = $this->rows( 'blocked_transition' );
		$this->assertCount( 1, $blocked );
		$this->assertSame( array( 'estado_pedido' => 'pending' ), json_decode( $blocked[0]['after_value'], true ) );
		$order->delete( true );
	}

	public function test_failed_logins_are_throttled_per_ip_so_brute_force_cannot_flood_the_table(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
		for ( $i = 0; $i < 30; $i++ ) {
			do_action( 'wp_login_failed', 'atacante' . $i );
		}
		unset( $_SERVER['REMOTE_ADDR'] );
		$rows = $this->rows( 'login_failed' );
		$this->assertLessThanOrEqual( 6, count( $rows ), '30 intentos no escriben 30 filas' );
		$this->assertStringContainsString( 'suprimidos', json_encode( $rows ), 'Queda una fila-resumen' );
	}

	public function test_administrator_creation_and_role_changes_raise_the_alert(): void {
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), static function ( string $e, string $r ) use ( &$alerts ) {
			$alerts[] = array( $e, $r );
		} );
		$uid = wp_insert_user( array( 'user_login' => 'nuevoadmin', 'user_pass' => wp_generate_password(), 'user_email' => 'nuevoadmin@example.com', 'role' => 'administrator' ) );
		$log->on_user_register( $uid );
		$this->assertSame( array( array( 'admin_role_change', 'usuario #' . $uid ) ), $alerts );

		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log->on_set_user_role( $uid, 'customer', array( 'administrator' ) );
		$this->assertCount( 1, $alerts, 'wp_insert_user dejó el rol de administrador: el cambio se alerta mientras siga siéndolo' );

		wp_update_user( array( 'ID' => $uid, 'role' => 'customer' ) );
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log->on_set_user_role( $uid, 'customer', array( 'customer' ) );
		$this->assertSame( array(), $alerts, 'Un cliente no dispara la alerta' );
		wp_delete_user( $uid );
	}

	public function test_user_changes_deletions_and_installs_are_logged_without_storing_values(): void {
		$uid = wp_insert_user( array( 'user_login' => 'cambia', 'user_pass' => 'clave-inicial-123', 'user_email' => 'cambia@example.com' ) );
		$old = get_userdata( $uid );
		wp_update_user( array( 'ID' => $uid, 'user_email' => 'nuevo@example.com', 'user_pass' => 'clave-nueva-456' ) );
		$changed = $this->rows( 'user_changed' );
		$this->assertNotEmpty( $changed );
		$this->assertStringNotContainsString( 'nuevo@example.com', json_encode( $changed ) );
		$this->assertStringNotContainsString( 'clave-nueva', json_encode( $changed ) );
		$this->assertStringContainsString( 'correo', json_encode( $changed ) );
		$this->assertStringContainsString( 'contrasena', json_encode( $changed ) );
		unset( $old );

		wp_delete_user( $uid );
		$this->assertNotEmpty( $this->rows( 'user_deleted' ) );

		// Se llama al manejador directamente: otros plugins (WooCommerce) tipan el primer argumento de esta acción como WP_Upgrader.
		( new AuditLog( $this->crypto() ) )->on_upgrade( null, array( 'type' => 'plugin', 'action' => 'install' ) );
		do_action( 'deleted_plugin', 'cualquiera/cualquiera.php', true );
		$this->assertGreaterThanOrEqual( 2, count( $this->rows( 'plugin_change' ) ) );
	}

	public function test_first_creation_of_a_watched_option_is_logged_too(): void {
		delete_option( 'aralia_csp_mode' );
		add_option( 'aralia_csp_mode', 'enforce' );
		$rows = $this->rows( 'settings_change' );
		$this->assertStringContainsString( 'aralia_csp_mode', json_encode( $rows ) );
		delete_option( 'aralia_csp_mode' );
	}

	public function test_alert_destinations_are_watched_options(): void {
		$this->assertContains( 'aralia_alert_email', AuditLog::WATCHED_OPTIONS );
		$this->assertContains( 'aralia_alert_phone', AuditLog::WATCHED_OPTIONS );
	}

	public function test_redaction_covers_ids_and_secrets_that_do_not_contain_the_word_key(): void {
		$redacted = AuditLog::redact( array( 'nit' => '900', 'cedula' => '1', 'whatsapp' => '3', 'integrity' => 'x', 'events' => 'y', 'cookie' => 'c', 'bearer' => 'b', 'ok' => 'visible' ) );
		foreach ( array( 'nit', 'cedula', 'whatsapp', 'integrity', 'events', 'cookie', 'bearer' ) as $k ) {
			$this->assertSame( '[redactado]', $redacted[ $k ], $k );
		}
		$this->assertSame( 'visible', $redacted['ok'] );
	}

	public function test_ip_comes_from_the_trusted_source_not_from_spoofable_headers(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.30';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
		$_SERVER['HTTP_X_REAL_IP']       = '198.51.100.98';
		( new AuditLog( $this->crypto() ) )->record( 'login', 'user', 77, null, null, 1 );
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'] );
		$row = $this->rows( 'login' )[0];
		$this->assertSame( $this->crypto()->ip_hash( '203.0.113.30' ), $row['ip_hash'] );
	}

	/**
	 * @return array<int,array{string,string}> Alertas levantadas.
	 */
	private function alerts_sink( array &$alerts ): callable {
		return static function ( string $e, string $r ) use ( &$alerts ) {
			$alerts[] = array( $e, $r );
		};
	}

	public function test_demoting_deleting_or_changing_an_administrator_raises_the_identity_alert(): void {
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$uid    = wp_insert_user( array( 'user_login' => 'exadmin', 'user_pass' => wp_generate_password(), 'user_email' => 'exadmin@example.com', 'role' => 'customer' ) );

		$log->on_set_user_role( $uid, 'customer', array( 'administrator' ) );
		$this->assertCount( 1, $alerts, 'Degradar a un administrador (rol anterior) se alerta aunque ya no lo sea' );

		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log->on_set_user_role( $uid, 'customer', array( 'subscriber' ) );
		$this->assertSame( array(), $alerts, 'Un cambio entre roles sin privilegios no alerta' );

		wp_update_user( array( 'ID' => $uid, 'role' => 'administrator' ) );
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$old    = get_userdata( $uid );
		$old->user_email = 'viejo@example.com';
		$log->on_profile_update( $uid, $old );
		$this->assertCount( 1, $alerts, 'Cambiar el correo de un administrador es como se toma una cuenta' );

		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log->on_user_deleted( $uid );
		$this->assertCount( 1, $alerts, 'Borrar a un administrador se alerta' );
		wp_delete_user( $uid );
	}

	public function test_refunds_role_additions_plugins_theme_and_reversals_are_logged(): void {
		$log = new AuditLog( $this->crypto() );
		$log->on_refund( 55, 9 );
		$log->on_role_added_removed( 1, 'editor' );
		$log->on_plugin_activated( 'plugin-x/plugin-x.php' );
		$log->on_plugin_deactivated( 'plugin-x/plugin-x.php' );
		$old = wp_get_theme( 'twentytwentyfive' );
		$log->on_theme_switched( 'Otro', wp_get_theme(), $old );
		$log->on_reversal( 55, 'anulación manual' );

		$this->assertCount( 1, $this->rows( 'refund_logged' ) );
		$this->assertSame( '55', $this->rows( 'refund_logged' )[0]['object_id'] );
		$this->assertSame( array( 'reembolso' => 9 ), json_decode( $this->rows( 'refund_logged' )[0]['after_value'], true ) );
		$this->assertCount( 1, $this->rows( 'role_change' ) );
		$plugins = $this->rows( 'plugin_change' );
		$this->assertCount( 2, $plugins );
		$this->assertStringContainsString( 'activado', $plugins[0]['after_value'] );
		$this->assertStringContainsString( 'desactivado', $plugins[1]['after_value'] );
		$this->assertCount( 1, $this->rows( 'reversal_logged' ) );
		$this->assertCount( 1, $this->rows( 'settings_change' ), 'El cambio de tema' );
	}

	public function test_order_status_changes_keep_the_from_and_to_states(): void {
		$log = new AuditLog( $this->crypto() );
		$log->on_order_status( 12, 'pending', 'processing' );
		$row = $this->rows( 'order_status_change' )[0];
		$this->assertSame( array( 'estado' => 'pending' ), json_decode( $row['before_value'], true ) );
		$this->assertSame( array( 'estado' => 'processing' ), json_decode( $row['after_value'], true ) );
		$this->assertSame( '12', $row['object_id'] );
	}

	public function test_the_real_wordpress_and_woocommerce_actions_reach_the_handlers_with_the_right_arguments(): void {
		do_action( 'woocommerce_order_refunded', 55, 9 );
		do_action( 'add_user_role', 1, 'editor' );
		do_action( 'activated_plugin', 'plugin-x/plugin-x.php' );
		do_action( 'deactivated_plugin', 'plugin-x/plugin-x.php' );
		do_action( 'switch_theme', 'Nombre', wp_get_theme(), wp_get_theme( 'twentytwentyfive' ) );
		do_action( 'aralia_reversal_logged', 56, str_repeat( 'x', 300 ) );

		$this->assertSame( '55', $this->rows( 'refund_logged' )[0]['object_id'] );
		$this->assertCount( 1, $this->rows( 'role_change' ) );
		$plugins = $this->rows( 'plugin_change' );
		$this->assertSame( 'plugin-x/plugin-x.php', json_decode( $plugins[0]['after_value'], true )['plugin'] );
		$this->assertStringContainsString( 'desactivado', $plugins[1]['after_value'] );
		$theme = $this->rows( 'settings_change' )[0];
		$this->assertStringContainsString( 'twentytwentyfive', $theme['before_value'], 'Tema anterior' );
		$this->assertStringContainsString( wp_get_theme()->get_stylesheet(), $theme['after_value'], 'Tema nuevo' );
		$reversal = json_decode( $this->rows( 'reversal_logged' )[0]['after_value'], true );
		$this->assertSame( 120, strlen( $reversal['nota'] ), 'La nota se recorta a 120 caracteres' );
	}

	public function test_a_password_reset_or_set_password_is_logged_and_alerted_for_administrators_without_storing_it(): void {
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$uid    = wp_insert_user( array( 'user_login' => 'cuentaadmin', 'user_pass' => wp_generate_password(), 'user_email' => 'cuentaadmin@example.com', 'role' => 'administrator' ) );
		$before = count( $this->rows( 'user_changed' ) );
		$log->on_password_reset( get_userdata( $uid ) );
		$this->assertCount( 1, $alerts );
		$log->on_set_password( 'clave-super-secreta-999', $uid );
		$this->assertCount( 1, $alerts, 'Una sola alerta por usuario y petición' );
		$rows = $this->rows( 'user_changed' );
		$this->assertCount( $before + 2, $rows );
		$this->assertStringNotContainsString( 'clave-super-secreta', json_encode( $rows ) );
		wp_delete_user( $uid );
	}

	public function test_the_password_actions_are_hooked(): void {
		$uid = wp_insert_user( array( 'user_login' => 'cuentacliente', 'user_pass' => wp_generate_password(), 'user_email' => 'cuentacliente@example.com' ) );
		$before = count( $this->rows( 'user_changed' ) );
		do_action( 'after_password_reset', get_userdata( $uid ) );
		do_action( 'wp_set_password', 'x', $uid );
		$this->assertCount( $before + 2, $this->rows( 'user_changed' ), 'Las acciones reales llegan a los manejadores' );
		wp_delete_user( $uid );
	}

	public function test_removing_the_administrator_role_directly_still_raises_the_alert(): void {
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$uid    = wp_insert_user( array( 'user_login' => 'sinrol', 'user_pass' => wp_generate_password(), 'user_email' => 'sinrol@example.com', 'role' => 'customer' ) );
		// remove_user_role se dispara cuando el usuario YA no es administrador: el rol retirado hace de «rol anterior».
		$log->on_role_removed( $uid, 'administrator' );
		$this->assertCount( 1, $alerts );
		$alerts = array();
		$log2   = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log2->on_role_removed( $uid, 'subscriber' );
		$this->assertSame( array(), $alerts );
		wp_delete_user( $uid );
	}

	public function test_set_role_style_bursts_raise_a_single_alert_per_user(): void {
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$uid    = wp_insert_user( array( 'user_login' => 'rafaga', 'user_pass' => wp_generate_password(), 'user_email' => 'rafaga@example.com', 'role' => 'administrator' ) );
		$log->on_user_register( $uid );
		$log->on_role_added_removed( $uid, 'administrator' );
		$log->on_set_user_role( $uid, 'administrator', array( 'customer' ) );
		$this->assertCount( 1, $alerts, 'set_role dispara varias acciones: una sola alerta (cada una gasta correo y SMS)' );
		wp_delete_user( $uid );
	}

	public function test_capabilities_written_directly_as_user_meta_are_logged_and_alerted(): void {
		$alerts = array();
		remove_all_actions( 'added_user_meta' );
		remove_all_actions( 'updated_user_meta' );
		$log = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log->register_hooks();
		$uid = wp_insert_user( array( 'user_login' => 'escalada', 'user_pass' => wp_generate_password(), 'user_email' => 'escalada@example.com', 'role' => 'customer' ) );
		$alerts = array();

		// Vía 1: add_cap (no dispara set_user_role ni profile_update).
		$user = new \WP_User( $uid );
		$user->add_cap( 'manage_options' );
		$this->assertCount( 1, $alerts, 'add_cap(manage_options) debe alertar' );
		$this->assertStringContainsString( 'manage_options', json_encode( $this->rows( 'role_change' ) ) );

		// Vía 2: update_user_meta directo (lo que hace una vulnerabilidad de actualización arbitraria de metadatos).
		global $wpdb;
		$other = wp_insert_user( array( 'user_login' => 'escalada2', 'user_pass' => wp_generate_password(), 'user_email' => 'escalada2@example.com', 'role' => 'customer' ) );
		$alerts = array();
		$log2   = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log2->on_user_meta( 1, $other, $wpdb->get_blog_prefix() . 'capabilities', array( 'administrator' => true ) );
		$this->assertCount( 1, $alerts, 'Escribir {administrator: true} en las capacidades debe alertar' );

		// Un metadato cualquiera no hace nada.
		$alerts = array();
		$log3   = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log3->on_user_meta( 1, $other, 'nickname', array( 'administrator' => true ) );
		$this->assertSame( array(), $alerts );
		// Capacidades sin privilegios: se registra pero no alerta.
		$log3->on_user_meta( 1, $other, $wpdb->get_blog_prefix() . 'capabilities', array( 'customer' => true ) );
		$this->assertSame( array(), $alerts );
		wp_delete_user( $uid );
		wp_delete_user( $other );
	}

	public function test_the_real_remove_user_role_action_reaches_the_handler(): void {
		$uid = wp_insert_user( array( 'user_login' => 'quitarol', 'user_pass' => wp_generate_password(), 'user_email' => 'quitarol@example.com', 'role' => 'editor' ) );
		$before = count( $this->rows( 'role_change' ) );
		do_action( 'remove_user_role', $uid, 'editor' );
		$this->assertCount( $before + 1, $this->rows( 'role_change' ), 'La acción real llega a on_role_removed con sus 2 argumentos' );
		wp_delete_user( $uid );
	}

	public function test_a_false_value_still_grants_the_role_because_wordpress_reads_the_keys(): void {
		global $wpdb;
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log->register_hooks();
		$uid = wp_insert_user( array( 'user_login' => 'valorfalso', 'user_pass' => wp_generate_password(), 'user_email' => 'valorfalso@example.com', 'role' => 'customer' ) );
		$alerts = array();

		update_user_meta( $uid, $wpdb->get_blog_prefix() . 'capabilities', array( 'administrator' => false ) );

		clean_user_cache( $uid );
		$this->assertTrue( user_can( $uid, 'manage_options' ), 'Premisa: con la clave administrator y valor false WordPress IGUAL da el rol de administrador' );
		$this->assertCount( 1, $alerts, 'y por eso debe alertar' );
		$row = json_encode( $this->rows( 'role_change' ) );
		$this->assertStringContainsString( 'administrator', $row, 'La fila registra las claves sin filtrar por valor' );
		wp_delete_user( $uid );
	}

	public function test_a_custom_role_with_privileged_capabilities_and_direct_privileged_caps_alert(): void {
		global $wpdb;
		add_role( 'rol_ajeno_poderoso', 'Ajeno', array( 'read' => true, 'manage_options' => true ) );
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$key    = $wpdb->get_blog_prefix() . 'capabilities';
		$log->on_user_meta( 1, 501, $key, array( 'rol_ajeno_poderoso' => true ) );
		$this->assertCount( 1, $alerts, 'Un rol propio de otro plugin con manage_options equivale a administrador' );
		remove_role( 'rol_ajeno_poderoso' );

		foreach ( array( 'install_plugins', 'edit_plugins', 'activate_plugins', 'promote_users', 'edit_users', 'unfiltered_html' ) as $cap ) {
			$alerts = array();
			$l      = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
			$l->on_user_meta( 1, 600, $key, array( 'customer' => true, $cap => true ) );
			$this->assertCount( 1, $alerts, "La capacidad $cap equivale a administrador" );
			$alerts = array();
			$l2     = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
			$l2->on_user_meta( 1, 601, $key, array( 'customer' => true, $cap => false ) );
			$this->assertSame( array(), $alerts, "Una capacidad directa $cap con valor false no concede nada" );
		}
	}

	public function test_giving_a_privileged_capability_to_the_customer_role_alerts_for_everyone_at_once(): void {
		global $wpdb;
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$key    = $wpdb->get_blog_prefix() . 'user_roles';
		$old    = array(
			'customer'      => array( 'name' => 'Cliente', 'capabilities' => array( 'read' => true ) ),
			'administrator' => array( 'name' => 'Admin', 'capabilities' => array( 'manage_options' => true ) ),
		);
		$new    = $old;
		$new['customer']['capabilities']['manage_options'] = true;
		$log->on_option_updated( $key, $old, $new );
		$this->assertCount( 1, $alerts, 'Todas las clientas pasarían a ser administradoras' );
		$this->assertStringContainsString( 'rol customer', $alerts[0][1] );
		$this->assertStringContainsString( 'roles_con_privilegios_nuevos', json_encode( $this->rows( 'settings_change' ) ) );

		$alerts = array();
		$log2   = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log2->on_option_updated( $key, $old, $old );
		$this->assertSame( array(), $alerts, 'Sin cambios no alerta' );

		$harmless = $old;
		$harmless['customer']['capabilities']['read_private_posts'] = true;
		$log2->on_option_updated( $key, $old, $harmless );
		$this->assertSame( array(), $alerts, 'Una capacidad sin privilegio no alerta' );

		$admin_changed = $old;
		$admin_changed['administrator']['capabilities']['edit_posts'] = true;
		$log2->on_option_updated( $key, $old, $admin_changed );
		$this->assertCount( 1, $alerts, 'Cambiar la definición del rol administrador alerta' );

		$alerts = array();
		$log3   = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log3->on_option_added( $key, $new );
		$this->assertCount( 1, $alerts, 'Crear la opción con un rol privilegiado también alerta' );

		$log3->on_option_updated( 'otra_opcion', array(), array( 'customer' => array( 'capabilities' => array( 'manage_options' => true ) ) ) );
		$this->assertCount( 1, $alerts, 'Otra opción no se confunde con la de roles' );
	}

	public function test_the_roles_option_is_hooked_through_the_real_update_option_path(): void {
		global $wpdb;
		$alerts = array();
		remove_all_actions( 'updated_option' );
		$log = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log->register_hooks();
		$key   = $wpdb->get_blog_prefix() . 'user_roles';
		$roles = get_option( $key );
		$new   = $roles;
		$new['customer']['capabilities']['install_plugins'] = true;
		update_option( $key, $new );
		update_option( $key, $roles ); // Se restaura.
		$this->assertNotEmpty( $alerts, 'update_option sobre {prefijo}user_roles llega al manejador' );
	}

	/**
	 * @dataProvider provide_key_variants
	 */
	public function test_case_and_trailing_space_variants_of_the_capabilities_key_are_detected_through_the_real_path( string $variant ): void {
		global $wpdb;
		$alerts = array();
		remove_all_actions( 'updated_user_meta' );
		remove_all_actions( 'added_user_meta' );
		$log = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log->register_hooks();
		$uid = wp_insert_user( array( 'user_login' => 'variante' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'variante' . wp_rand() . '@example.com', 'role' => 'customer' ) );
		$alerts = array();
		$prefix = $wpdb->get_blog_prefix();
		$key    = str_replace( '{p}', $prefix, $variant );
		update_user_meta( $uid, $key, array( 'administrator' => true ) );
		$this->assertNotEmpty( $alerts, "La clave '$key' escribe la misma fila que wp_capabilities y debe alertar" );
		wp_delete_user( $uid );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_key_variants(): array {
		return array(
			'mayúsculas'            => array( 'WP_CAPABILITIES' ),
			'mixto'                 => array( 'Wp_Capabilities' ),
			'espacio final'         => array( '{p}capabilities ' ),
			'exacta'                => array( '{p}capabilities' ),
		);
	}

	public function test_case_variants_of_the_roles_option_name_are_detected(): void {
		global $wpdb;
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$old    = array( 'customer' => array( 'name' => 'C', 'capabilities' => array( 'read' => true ) ) );
		$new    = $old;
		$new['customer']['capabilities']['install_themes'] = true;
		$log->on_option_updated( strtoupper( $wpdb->get_blog_prefix() . 'user_roles' ), $old, $new );
		$this->assertCount( 1, $alerts, 'WP_USER_ROLES es la misma fila y install_themes es privilegiada' );
	}

	/**
	 * @dataProvider provide_new_privileged_caps
	 */
	public function test_themes_woocommerce_and_plugin_deletion_capabilities_are_privileged( string $cap ): void {
		global $wpdb;
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log->on_user_meta( 1, 700, $wpdb->get_blog_prefix() . 'capabilities', array( 'customer' => true, $cap => true ) );
		$this->assertCount( 1, $alerts, $cap );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_new_privileged_caps(): array {
		return array_map( static fn( $c ) => array( $c ), array_combine( array( 'install_themes', 'update_themes', 'edit_themes', 'delete_plugins', 'manage_woocommerce' ), array( 'install_themes', 'update_themes', 'edit_themes', 'delete_plugins', 'manage_woocommerce' ) ) );
	}

	public function test_deleting_the_roles_option_or_removing_the_administrator_role_alerts(): void {
		global $wpdb;
		$key    = $wpdb->get_blog_prefix() . 'user_roles';
		$alerts = array();
		$log    = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$log->on_option_deleted( strtoupper( $key ) );
		$this->assertCount( 1, $alerts, 'Borrar la opción deja a todos sin rol' );
		$log->on_option_deleted( 'otra_opcion' );
		$this->assertCount( 1, $alerts );

		$alerts = array();
		$log2   = new AuditLog( $this->crypto(), new \Aralia\Security\RateLimiter(), $this->alerts_sink( $alerts ) );
		$old    = array( 'administrator' => array( 'name' => 'A', 'capabilities' => array( 'manage_options' => true ) ), 'customer' => array( 'name' => 'C', 'capabilities' => array( 'read' => true ) ) );
		$new    = $old;
		unset( $new['administrator'] );
		$log2->on_option_updated( $key, $old, $new );
		$this->assertCount( 1, $alerts, 'Quitar el rol administrador de la definición bloquea a la dueña' );
	}
}
