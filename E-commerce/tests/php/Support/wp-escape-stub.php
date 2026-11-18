<?php
/**
 * Sustituto mínimo de esc_html() para pruebas unitarias sin WordPress.
 *
 * @package Aralia
 */

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Texto.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
