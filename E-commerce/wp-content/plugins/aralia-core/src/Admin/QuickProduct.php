<?php
/**
 * Alta rápida de producto en una sola pantalla (T042, FR-065, FR-067).
 *
 * @package Aralia
 */

namespace Aralia\Admin;

use Aralia\Catalog\Importer;
use Aralia\Catalog\Taxonomies;
use Aralia\Catalog\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Un formulario con todos los atributos de 8.1. Construye una fila del contrato CSV y pasa por el MISMO validador e importador: una sola
 * regla para el CSV y para el alta manual. Si hay tonos, se piden como líneas `Tono|SKU|precio|stock|costo`.
 */
final class QuickProduct {

	/**
	 * Campos de texto del formulario (columna del CSV => etiqueta).
	 *
	 * @var array<string,string>
	 */
	private const FIELDS = array(
		'sku'                => 'SKU',
		'nombre'             => 'Nombre',
		'subcategoria'       => 'Subcategoría',
		'marca'              => 'Marca',
		'color'              => 'Color (separa con |)',
		'look'               => 'Look (separa con |)',
		'secciones'          => 'Secciones (slugs, separa con |)',
		'combina_con'        => 'Combina con (2 SKU separados por |)',
		'costo'              => 'Costo COP (interno)',
		'precio'             => 'Precio COP',
		'stock'              => 'Stock',
		'registro_sanitario' => 'Registro sanitario',
		'descripcion_corta'  => 'Descripción corta',
		'como_se_usa'        => 'Cómo se usa',
		'ingredientes'       => 'Ingredientes',
	);

	/**
	 * Engancha menú y envío.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_aralia_quick_product', array( self::class, 'handle' ) );
	}

	/**
	 * Submenú.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=product', 'Alta rápida', 'Alta rápida', 'manage_woocommerce', 'aralia-quick-product', array( self::class, 'render' ) );
	}

	/**
	 * Formulario.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No tienes permiso.', 'aralia' ), 403 );
		}
		echo '<div class="wrap"><h1>Alta rápida de producto</h1>';
		$error = get_transient( 'aralia_quick_error_' . get_current_user_id() );
		if ( is_array( $error ) ) {
			delete_transient( 'aralia_quick_error_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( implode( ' ', $error ) ) . '</p></div>';
		}
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aralia_quick_product">';
		wp_nonce_field( 'aralia_quick_product' );
		echo '<table class="form-table">';
		echo '<tr><th>Categoría</th><td><select name="categoria">';
		foreach ( Taxonomies::CATEGORIES as $cat ) {
			echo '<option>' . esc_html( $cat ) . '</option>';
		}
		echo '</select></td></tr>';
		foreach ( self::FIELDS as $name => $label ) {
			echo '<tr><th><label for="q_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="q_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"></td></tr>';
		}
		echo '<tr><th>Paso de Full face</th><td><select name="paso_full_face"><option value="">—</option>';
		foreach ( array_keys( Taxonomies::STEPS ) as $step ) {
			echo '<option>' . esc_html( $step ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>¿Depende del tono?</th><td><select name="depende_tono"><option value="no">No</option><option value="si">Sí</option></select></td></tr>';
		echo '<tr><th>Tonos (uno por línea: <code>Tono|SKU|precio|stock|costo</code>; el swatch de cada tono se fija en Productos → Atributos → Tono)</th><td><textarea name="tonos" rows="5" class="large-text"></textarea></td></tr>';
		echo '<tr><th>Foto</th><td><input type="file" name="foto" accept="image/*"></td></tr><tr><th>Foto en uso</th><td><input type="file" name="foto_uso" accept="image/*"></td></tr><tr><th>Swatch real (si no depende del tono)</th><td><input type="file" name="swatch" accept="image/*"></td></tr>';
		echo '</table>';
		submit_button( 'Crear producto' );
		echo '</form></div>';
	}

	/**
	 * Convierte lo enviado en filas del contrato CSV (función pura, probada).
	 *
	 * @param array<string,string> $in    Campos enviados.
	 * @param array<string,string> $files Nombres de archivo de `foto`, `foto_uso` y `swatch` (del producto, o del padre).
	 * @return array<int,array<string,string>> Filas (la primera es el padre).
	 */
	public static function build_rows( array $in, array $files = array() ): array {
		$base = array_fill_keys( Validator::COLUMNS, '' );
		foreach ( array_keys( self::FIELDS ) as $name ) {
			$base[ $name ] = trim( (string) ( $in[ $name ] ?? '' ) );
		}
		$base['categoria']      = trim( (string) ( $in['categoria'] ?? '' ) );
		$base['paso_full_face'] = trim( (string) ( $in['paso_full_face'] ?? '' ) );
		$base['depende_tono']   = 'si' === ( $in['depende_tono'] ?? 'no' ) ? 'si' : 'no';
		$base['foto']           = $files['foto'] ?? '';
		$base['foto_uso']       = $files['foto_uso'] ?? '';
		if ( 'no' === $base['depende_tono'] ) {
			$base['swatch'] = $files['swatch'] ?? '';
			return array( $base );
		}
		// Producto por tono: el padre no lleva tono, precio, stock, costo ni swatch (los llevan las variaciones; el swatch por tono sale de la familia de tono).
		$parent = array_merge(
			$base,
			array(
				'precio' => '',
				'stock'  => '',
				'costo'  => '',
				'swatch' => '',
			)
		);
		$kids   = array();
		$lines  = preg_split( '/\R/', (string) ( $in['tonos'] ?? '' ) );
		foreach ( false === $lines ? array() : $lines as $line ) {
			$cells = array_map( 'trim', explode( '|', $line ) );
			if ( '' === $line || count( $cells ) < 5 ) {
				continue;
			}
			$kids[] = array_merge(
				$parent,
				array(
					'sku'         => $cells[1],
					'parent_sku'  => $base['sku'],
					'nombre'      => $base['nombre'] . ' ' . $cells[0],
					'tono'        => $cells[0],
					'subtono'     => Taxonomies::tones()[ $cells[0] ] ?? '',
					'precio'      => $cells[2],
					'stock'       => $cells[3],
					'costo'       => $cells[4],
					'combina_con' => '',
					'foto'        => '',
					'foto_uso'    => '',
					'swatch'      => $files[ 'swatch_' . $cells[0] ] ?? '',
				)
			);
		}
		return array_merge( array( $parent ), $kids );
	}

	/**
	 * Procesa el envío: valida PRIMERO y sube las imágenes solo si todo es válido.
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_admin_referer( 'aralia_quick_product' );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'No tienes permiso.', 'aralia' ), 403 );
		}
		$in = array();
		foreach ( array_merge( array_keys( self::FIELDS ), array( 'categoria', 'paso_full_face', 'depende_tono' ) ) as $name ) {
			$in[ $name ] = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
		}
		$in['tonos'] = isset( $_POST['tonos'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tonos'] ) ) : '';
		// Nombres provisionales de las imágenes enviadas; el validador ve que existen y la subida real ocurre después de validar.
		$present = array();
		$files   = array();
		foreach ( array( 'foto', 'foto_uso', 'swatch' ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Solo se mira el código de error; media_handle_upload valida el contenido.
			if ( isset( $_FILES[ $key ] ) && UPLOAD_ERR_OK === (int) $_FILES[ $key ]['error'] ) {
				$present[ $key ] = true;
				$files[ $key ]   = $key . '-nuevo.png';
			}
		}
		$prepared = self::prepare( $in, $files );
		$rows     = $prepared['rows'];
		$errors   = $prepared['errors'];
		if ( $errors ) {
			set_transient( 'aralia_quick_error_' . get_current_user_id(), $errors, 60 );
			wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=aralia-quick-product' ) );
			exit;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachments = array();
		foreach ( array_keys( $present ) as $key ) {
			$problem = self::image_problem( (string) ( $_FILES[ $key ]['tmp_name'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( '' !== $problem ) {
				set_transient( 'aralia_quick_error_' . get_current_user_id(), array( $key . ': ' . $problem ), 60 );
				wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=aralia-quick-product' ) );
				exit;
			}
			$id = media_handle_upload(
				$key,
				0,
				array(),
				array(
					'mimes'     => array(
						'jpg|jpeg' => 'image/jpeg',
						'png'      => 'image/png',
						'webp'     => 'image/webp',
					),
					'test_form' => false,
				)
			);
			if ( is_wp_error( $id ) ) {
				set_transient( 'aralia_quick_error_' . get_current_user_id(), array( $key . ': ' . $id->get_error_message() ), 60 );
				wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=aralia-quick-product' ) );
				exit;
			}
			$attachments[ $files[ $key ] ] = (int) $id;
		}
		$summary = Importer::import( $rows, $attachments, array() );
		set_transient( 'aralia_import_summary_' . get_current_user_id(), $summary, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=aralia-incomplete&imported=1' ) );
		exit;
	}

	/**
	 * Construye y valida las filas; rechaza SKU que ya existen (el alta rápida no sobrescribe productos).
	 *
	 * @param array<string,string> $in    Campos.
	 * @param array<string,string> $files Nombres de imagen.
	 * @return array{rows:array<int,array<string,string>>,errors:string[]}
	 */
	public static function prepare( array $in, array $files = array() ): array {
		$rows   = self::build_rows( $in, $files );
		$errors = self::validate_rows( $rows );
		foreach ( $rows as $row ) {
			if ( '' !== $row['sku'] && wc_get_product_id_by_sku( $row['sku'] ) ) {
				$errors[] = 'El SKU ' . $row['sku'] . ' ya existe: el alta rápida no sobrescribe productos (usa Importar inventario para actualizar).';
			}
		}
		return array(
			'rows'   => $rows,
			'errors' => $errors,
		);
	}

	/**
	 * Valida las filas con el mismo validador que el CSV.
	 *
	 * @param array<int,array<string,string>> $rows Filas.
	 * @return string[] Errores legibles.
	 */
	public static function validate_rows( array $rows ): array {
		$csv = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $csv, Validator::COLUMNS, ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( $rows as $row ) {
			fputcsv( $csv, array_values( $row ), ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rewind( $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$result = Validator::validate( (string) stream_get_contents( $csv ), Importer::current_prices(), Taxonomies::tones() );
		fclose( $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$errors = $result['fatal'];
		foreach ( $result['errors'] as $line => $msgs ) {
			$errors[] = 'Fila ' . $line . ': ' . implode( ' ', $msgs );
		}
		return $errors;
	}

	/**
	 * Tipo real y dimensiones de una imagen subida.
	 *
	 * @param string $path Archivo temporal.
	 * @return string Problema o vacío.
	 */
	private static function image_problem( string $path ): string {
		$mime = \Aralia\Catalog\MediaZip::real_mime( $path );
		if ( ! in_array( $mime, \Aralia\Catalog\MediaZip::MIMES, true ) ) {
			return 'no es una imagen JPG, PNG o WebP.';
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || $info[0] > \Aralia\Catalog\MediaZip::MAX_SIDE || $info[1] > \Aralia\Catalog\MediaZip::MAX_SIDE || $info[0] * $info[1] > \Aralia\Catalog\MediaZip::MAX_PIXELS || filesize( $path ) > \Aralia\Catalog\MediaZip::MAX_FILE ) {
			return 'dimensiones o tamaño no permitidos.';
		}
		return '';
	}
}
