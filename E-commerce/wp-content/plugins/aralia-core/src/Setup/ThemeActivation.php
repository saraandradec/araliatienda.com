<?php
/**
 * Activación del tema `aralia` (T047).
 *
 * @package Aralia
 */

namespace Aralia\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * `.wp-env.json` solo instala el tema: sin activarlo, la tienda seguiría con el tema por defecto (y sin tokens, menú ni estilos).
 */
final class ThemeActivation {

	public const SLUG = 'aralia';

	/**
	 * Activa el tema si existe y no es el activo (idempotente).
	 *
	 * @param string $slug Tema (por defecto el propio).
	 * @return string activated | already | missing
	 */
	public static function apply( string $slug = self::SLUG ): string {
		if ( get_stylesheet() === $slug ) {
			return 'already';
		}
		if ( ! wp_get_theme( $slug )->exists() ) {
			return 'missing';
		}
		switch_theme( $slug );
		return 'activated';
	}
}
