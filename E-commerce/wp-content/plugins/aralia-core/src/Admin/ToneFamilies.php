<?php
/**
 * Familias de tono con swatch, subtono y orden (T042, FR-068).
 *
 * @package Aralia
 */

namespace Aralia\Admin;

use Aralia\Catalog\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Campos extra en el formulario de términos del atributo `pa_tono` (Productos → Atributos → Tono).
 */
final class ToneFamilies {

	public const NONCE = 'aralia_tone_fields';

	/**
	 * Engancha los formularios y el guardado.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'pa_tono_add_form_fields', array( self::class, 'add_fields' ) );
		add_action( 'pa_tono_edit_form_fields', array( self::class, 'edit_fields' ) );
		add_action( 'created_pa_tono', array( self::class, 'save' ) );
		add_action( 'edited_pa_tono', array( self::class, 'save' ) );
	}

	/**
	 * Campos del alta.
	 *
	 * @return void
	 */
	public static function add_fields(): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<div class="form-field"><label for="aralia_subtono">Subtono</label>';
		self::subtone_select( '' );
		echo '</div><div class="form-field"><label for="aralia_swatch_id">Swatch real (ID de la imagen en la biblioteca)</label><input type="number" min="0" name="aralia_swatch_id" id="aralia_swatch_id" value="0"></div>';
		echo '<div class="form-field"><label for="aralia_order">Orden</label><input type="number" min="0" name="aralia_order" id="aralia_order" value="0"></div>';
	}

	/**
	 * Campos de la edición.
	 *
	 * @param \WP_Term $term Término.
	 * @return void
	 */
	public static function edit_fields( $term ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<tr class="form-field"><th><label for="aralia_subtono">Subtono</label></th><td>';
		self::subtone_select( (string) get_term_meta( $term->term_id, 'subtono', true ) );
		echo '</td></tr><tr class="form-field"><th><label for="aralia_swatch_id">Swatch real (ID de la imagen)</label></th><td><input type="number" min="0" name="aralia_swatch_id" id="aralia_swatch_id" value="' . esc_attr( (string) (int) get_term_meta( $term->term_id, 'swatch_id', true ) ) . '"></td></tr>';
		echo '<tr class="form-field"><th><label for="aralia_order">Orden</label></th><td><input type="number" min="0" name="aralia_order" id="aralia_order" value="' . esc_attr( (string) (int) get_term_meta( $term->term_id, 'order', true ) ) . '"></td></tr>';
	}

	/**
	 * Selector de subtono.
	 *
	 * @param string $current Valor actual.
	 * @return void
	 */
	private static function subtone_select( string $current ): void {
		echo '<select name="aralia_subtono" id="aralia_subtono"><option value="">—</option>';
		foreach ( Taxonomies::SUBTONES as $subtone ) {
			echo '<option value="' . esc_attr( $subtone ) . '"' . selected( $current, $subtone, false ) . '>' . esc_html( $subtone ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Guarda los campos (nonce y permiso obligatorios).
	 *
	 * @param int $term_id Término.
	 * @return void
	 */
	public static function save( $term_id ): void {
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$subtone = isset( $_POST['aralia_subtono'] ) ? sanitize_text_field( wp_unslash( $_POST['aralia_subtono'] ) ) : '';
		update_term_meta( (int) $term_id, 'subtono', in_array( $subtone, Taxonomies::SUBTONES, true ) ? $subtone : '' );
		$swatch = isset( $_POST['aralia_swatch_id'] ) ? absint( wp_unslash( $_POST['aralia_swatch_id'] ) ) : 0;
		update_term_meta( (int) $term_id, 'swatch_id', $swatch && wp_attachment_is_image( $swatch ) ? $swatch : 0 );
		update_term_meta( (int) $term_id, 'order', isset( $_POST['aralia_order'] ) ? absint( wp_unslash( $_POST['aralia_order'] ) ) : 0 );
	}
}
