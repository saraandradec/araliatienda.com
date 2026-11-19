<?php
/**
 * Envío provisional de la puerta de la Fase 1 (T049).
 *
 * @package Aralia
 */

namespace Aralia\Setup;

use Aralia\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Una zona «Colombia (provisional)» con UN método de tarifa plana, marcada como PROVISIONAL (opción `aralia_provisional_shipping`).
 * Lo reemplaza el cálculo real de envío de US1 (T089 a T091): hasta entonces es lo mínimo para completar un pedido de prueba en Sandbox.
 *
 * SALVAGUARDAS: solo se crea en local o test, o con la bandera explícita `--allow-provisional-shipping`; en producción, `baseline check`
 * falla mientras siga activo y el panel muestra un aviso rojo. La zona se identifica por su id (opción), no por su nombre, así que
 * renombrarla no genera duplicados. Idempotente: no pisa una tarifa editada. Si ya hay OTRA zona que cubre Colombia, no crea la suya
 * (quedaría tapada por la primera) y lo informa.
 */
final class ProvisionalShipping {

	public const ZONE_NAME   = 'Colombia (provisional)';
	public const OPTION      = 'aralia_provisional_shipping';
	public const ZONE_OPTION = 'aralia_provisional_shipping_zone';

	/**
	 * Tarifa por defecto en COP (un valor de prueba, no la tarifa comercial).
	 */
	public const DEFAULT_COST = '10000';

	/**
	 * Engancha el aviso del panel.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * ¿Se puede crear en este entorno?
	 *
	 * @param bool        $allow_flag Bandera explícita.
	 * @param string|null $env        Entorno (pruebas).
	 * @return bool
	 */
	public static function may_apply( bool $allow_flag, ?string $env = null ): bool {
		return $allow_flag || Environment::allows_test_tools( $env );
	}

	/**
	 * Aplica solo si el entorno lo permite.
	 *
	 * @param bool        $allow_flag Bandera explícita.
	 * @param string|null $env        Entorno (pruebas).
	 * @return array{zone_id:int,created_zone:bool,created_method:bool,conflict:string}|null Null si el entorno no lo permite.
	 */
	public static function maybe_apply( bool $allow_flag, ?string $env = null ): ?array {
		return self::may_apply( $allow_flag, $env ) ? self::apply() : null;
	}

	/**
	 * Aplica la configuración.
	 *
	 * @return array{zone_id:int,created_zone:bool,created_method:bool,conflict:string}
	 */
	public static function apply(): array {
		$out  = array(
			'zone_id'        => 0,
			'created_zone'   => false,
			'created_method' => false,
			'conflict'       => '',
		);
		$zone = self::find_zone();
		if ( ! $zone ) {
			$other = self::other_zone_covering_colombia();
			if ( $other ) {
				$out['conflict'] = $other;
				return $out; // Una zona anterior taparía la nuestra: no se crea.
			}
			$zone = new \WC_Shipping_Zone();
			$zone->set_zone_name( self::ZONE_NAME );
			$zone->add_location( 'CO', 'country' );
			$zone->save();
			update_option( self::ZONE_OPTION, (int) $zone->get_id(), false );
			$out['created_zone'] = true;
		}
		$out['zone_id'] = (int) $zone->get_id();
		$has_flat       = false; // Cuenta cualquier flat_rate de la zona, aunque esté desactivado: no se añade otro.
		foreach ( $zone->get_shipping_methods() as $method ) {
			if ( 'flat_rate' === $method->id ) {
				$has_flat = true;
			}
		}
		if ( ! $has_flat ) {
			$instance = $zone->add_shipping_method( 'flat_rate' );
			update_option(
				'woocommerce_flat_rate_' . $instance . '_settings',
				array(
					'title'      => 'Envío (provisional)',
					'tax_status' => 'none',
					'cost'       => self::DEFAULT_COST,
				)
			);
			$out['created_method'] = true;
		}
		update_option( self::OPTION, '1', false );
		return $out;
	}

	/**
	 * Nombre de otra zona (no la provisional) que ya cubre Colombia, si la hay.
	 *
	 * @return string
	 */
	private static function other_zone_covering_colombia(): string {
		foreach ( \WC_Shipping_Zones::get_zones() as $data ) {
			foreach ( (array) ( $data['zone_locations'] ?? array() ) as $location ) {
				if ( 'country' === $location->type && 'CO' === $location->code ) {
					return (string) $data['zone_name'];
				}
			}
		}
		return '';
	}

	/**
	 * La zona provisional (por id guardado; si no, por nombre), si existe.
	 *
	 * @return \WC_Shipping_Zone|null
	 */
	public static function find_zone(): ?\WC_Shipping_Zone {
		$id = (int) get_option( self::ZONE_OPTION );
		foreach ( \WC_Shipping_Zones::get_zones() as $data ) {
			if ( ( $id && (int) $data['id'] === $id ) || ( ! $id && self::ZONE_NAME === ( $data['zone_name'] ?? '' ) ) ) {
				return new \WC_Shipping_Zone( (int) $data['id'] );
			}
		}
		return null;
	}

	/**
	 * ¿Sigue el envío provisional en uso? (lo apaga la tarea de envío real).
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return '1' === get_option( self::OPTION );
	}

	/**
	 * Aviso rojo en el panel mientras el envío provisional siga activo fuera de local y test.
	 *
	 * @param string|null $env Entorno (pruebas).
	 * @return void
	 */
	public static function notice( ?string $env = null ): void {
		if ( self::is_active() && ! Environment::allows_test_tools( $env ) && current_user_can( 'manage_woocommerce' ) ) {
			echo '<div class="notice notice-error"><p><strong>Aralía:</strong> el envío PROVISIONAL (tarifa plana de prueba) sigue activo en este entorno. Cada pedido se cobraría con un valor de prueba. Reemplázalo por el envío real antes de vender.</p></div>';
		}
	}
}
