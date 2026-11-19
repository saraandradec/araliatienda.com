<?php
/**
 * Pantalla "Importar inventario" (T045, FR-065).
 *
 * @package Aralia
 */

namespace Aralia\Admin;

use Aralia\Catalog\Importer;
use Aralia\Catalog\MediaZip;
use Aralia\Catalog\Taxonomies;
use Aralia\Catalog\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Subir CSV (+ ZIP) → validar → vista previa → confirmar → importar. Nada se importa si hay errores bloqueantes.
 */
final class ImportScreen {

	/**
	 * Engancha el menú y los envíos.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_aralia_import_preview', array( self::class, 'preview' ) );
		add_action( 'admin_post_aralia_import_confirm', array( self::class, 'confirm' ) );
	}

	/**
	 * Submenú de Productos.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=product', 'Importar inventario', 'Importar inventario', 'manage_woocommerce', 'aralia-import', array( self::class, 'render' ) );
	}

	/**
	 * Carpeta temporal fuera de uploads.
	 *
	 * @param string $token Token del trabajo.
	 * @return string
	 */
	public static function work_dir( string $token ): string {
		return trailingslashit( get_temp_dir() ) . 'aralia-import-' . $token;
	}

	/**
	 * Pantalla: formulario y, si hay un trabajo en curso, la vista previa.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No tienes permiso.', 'aralia' ), 403 );
		}
		echo '<div class="wrap"><h1>Importar inventario</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo se lee el token, y el trabajo pertenece al usuario actual.
		$token = isset( $_GET['job'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_GET['job'] ) ) ) : '';
		$job   = $token ? get_transient( 'aralia_import_' . $token ) : false;
		if ( is_array( $job ) && get_current_user_id() === (int) $job['user'] ) {
			self::render_preview( (string) $token, $job );
		} else {
			?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aralia_import_preview">
				<?php wp_nonce_field( 'aralia_import_preview' ); ?>
				<p><label>CSV de inventario <input type="file" name="csv" accept=".csv,text/csv" required></label></p>
				<p><label>ZIP de fotos (opcional; solo archivos, sin carpetas; máx. 200 archivos y 100 MB) <input type="file" name="zip" accept=".zip"></label></p>
				<?php submit_button( 'Validar y ver vista previa' ); ?>
			</form>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Vista previa.
	 *
	 * @param string              $token Token.
	 * @param array<string,mixed> $job   Trabajo.
	 * @return void
	 */
	private static function render_preview( string $token, array $job ): void {
		$res = $job['result'];
		if ( $res['fatal'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html( implode( ' ', $res['fatal'] ) ) . '</p></div>';
			return;
		}
		if ( $job['zip_errors'] ) {
			echo '<div class="notice notice-error"><p>ZIP rechazado: ' . esc_html( implode( ' ', $job['zip_errors'] ) ) . '</p></div>';
		}
		$existing = Importer::current_prices();
		$new      = 0;
		$brands   = array();
		foreach ( $res['rows'] as $row ) {
			isset( $existing[ $row['sku'] ] ) || ++$new;
			if ( taxonomy_exists( 'product_brand' ) && ! term_exists( $row['marca'], 'product_brand' ) ) {
				$brands[ $row['marca'] ] = true;
			}
		}
		printf( '<p>%d filas leídas · %d altas · %d cambios · %d con errores · %d con avisos de foto, swatch o stock (la regla de publicación decide al importar qué queda en borrador) · %d cambios de precio mayores al 20 %%.</p>', count( $res['rows'] ), (int) $new, count( $res['rows'] ) - (int) $new, count( $res['errors'] ), count( $res['incomplete'] ), count( $res['price_changes'] ) );
		if ( $brands ) {
			echo '<p>Marcas nuevas que se crearán: ' . esc_html( implode( ', ', array_keys( $brands ) ) ) . '.</p>';
		}
		foreach ( $res['warnings'] as $line => $msgs ) {
			echo '<p>Aviso fila ' . (int) $line . ': ' . esc_html( implode( ' ', $msgs ) ) . '</p>';
		}
		foreach ( $res['incomplete'] as $line => $what ) {
			echo '<p>Fila ' . (int) $line . ': falta ' . esc_html( implode( ', ', $what ) ) . '.</p>';
		}
		$zip_names = array_map( 'strtolower', (array) $job['files'] );
		$missing   = array();
		foreach ( $res['rows'] as $row ) {
			foreach ( array( 'foto', 'foto_uso', 'swatch' ) as $col ) {
				if ( '' !== $row[ $col ] && ! in_array( strtolower( $row[ $col ] ), $zip_names, true ) ) {
					$missing[ $row[ $col ] ] = true;
				}
			}
		}
		if ( $missing ) {
			echo '<p>Fotos que el CSV nombra y no vienen en el ZIP (un producto nuevo quedará sin esa imagen; uno existente conserva la que tiene): ' . esc_html( implode( ', ', array_keys( $missing ) ) ) . '.</p>';
		}
		echo '<p><em>Las celdas que empiezan por = + - o @ se rechazan (protección contra fórmulas): no empieces una lista con «-».</em></p>';
		foreach ( $res['errors'] as $line => $msgs ) {
			echo '<p class="notice notice-error" style="padding:6px 12px">Fila ' . (int) $line . ': ' . esc_html( implode( ' ', $msgs ) ) . '</p>';
		}
		$blocked = $res['errors'] || $job['zip_errors'];
		if ( $blocked ) {
			echo '<p>Corrige el archivo y vuelve a subirlo. No se importó nada.</p>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aralia_import_confirm">
			<input type="hidden" name="job" value="<?php echo esc_attr( $token ); ?>">
			<?php wp_nonce_field( 'aralia_import_confirm' ); ?>
			<?php if ( $res['price_changes'] ) : ?>
				<h2>Cambios de precio a confirmar</h2>
				<?php foreach ( $res['price_changes'] as $c ) : ?>
					<p><label><input type="checkbox" name="confirm_price[]" value="<?php echo esc_attr( $c['sku'] ); ?>"> <?php echo esc_html( $c['sku'] . ': ' . $c['from'] . ' → ' . $c['to'] ); ?></label></p>
				<?php endforeach; ?>
				<p>Los que no marques conservan el precio actual.</p>
			<?php endif; ?>
			<?php submit_button( 'Confirmar e importar' ); ?>
		</form>
		<?php
	}

	/**
	 * Paso 1: recibe los archivos y valida.
	 *
	 * @return void
	 */
	public static function preview(): void {
		check_admin_referer( 'aralia_import_preview' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No tienes permiso.', 'aralia' ), 403 );
		}
		$token = bin2hex( random_bytes( 16 ) );
		$dir   = self::work_dir( $token );
		$job   = array(
			'user'       => get_current_user_id(),
			'result'     => array(
				'fatal'         => array( 'No se recibió el CSV.' ),
				'rows'          => array(),
				'errors'        => array(),
				'incomplete'    => array(),
				'price_changes' => array(),
				'warnings'      => array(),
			),
			'zip_errors' => array(),
			'files'      => array(),
		);
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Se valida la subida con is_uploaded_file y el contenido con Validator.
		$csv = $_FILES['csv'] ?? null;
		if ( is_array( $csv ) && UPLOAD_ERR_OK === (int) $csv['error'] && is_uploaded_file( (string) $csv['tmp_name'] ) ) {
			$text          = (string) file_get_contents( (string) $csv['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$job['result'] = Validator::validate( $text, Importer::current_prices(), Taxonomies::tones() );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$zip = $_FILES['zip'] ?? null;
		if ( is_array( $zip ) && UPLOAD_ERR_OK === (int) $zip['error'] && is_uploaded_file( (string) $zip['tmp_name'] ) ) {
			$extracted         = MediaZip::extract( (string) $zip['tmp_name'], $dir );
			$job['zip_errors'] = $extracted['errors'];
			$job['files']      = array_keys( $extracted['files'] ); // Solo nombres: la ruta se reconstruye al confirmar.
		}
		if ( $job['zip_errors'] || $job['result']['fatal'] || $job['result']['errors'] ) {
			self::cleanup( $dir ); // Trabajo rechazado: no se deja nada en disco.
		}
		set_transient( 'aralia_import_' . $token, $job, HOUR_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'aralia-import',
					'job'  => $token,
				),
				admin_url( 'edit.php?post_type=product' )
			)
		);
		exit;
	}

	/**
	 * Paso 2: importa lo confirmado.
	 *
	 * @return void
	 */
	public static function confirm(): void {
		check_admin_referer( 'aralia_import_confirm' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No tienes permiso.', 'aralia' ), 403 );
		}
		$token     = isset( $_POST['job'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_POST['job'] ) ) ) : '';
		$confirmed = isset( $_POST['confirm_price'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['confirm_price'] ) ) : array();
		$summary   = self::process_job( (string) $token, $confirmed, get_current_user_id() );
		if ( $summary instanceof \WP_Error ) {
			wp_die( esc_html( $summary->get_error_message() ), (int) $summary->get_error_data() );
		}
		set_transient( 'aralia_import_summary_' . get_current_user_id(), $summary, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'aralia-incomplete',
					'imported' => 1,
				),
				admin_url( 'edit.php?post_type=product' )
			)
		);
		exit;
	}

	/**
	 * Ejecuta un trabajo de importación confirmado. Devuelve el resumen o un WP_Error (con el código HTTP como dato).
	 * El trabajo debe ser del usuario, no tener errores y reclamarse una sola vez (candado).
	 *
	 * @param string   $token     Token del trabajo.
	 * @param string[] $confirmed SKU con cambio de precio confirmado.
	 * @param int      $user      Usuario que confirma.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function process_job( string $token, array $confirmed, int $user ) {
		$job = '' === $token ? false : get_transient( 'aralia_import_' . $token );
		if ( ! is_array( $job ) || $user !== (int) $job['user'] || $job['result']['errors'] || $job['result']['fatal'] || $job['zip_errors'] ) {
			return new \WP_Error( 'aralia_job', 'El trabajo de importación expiró o tiene errores. Vuelve a subir los archivos.', 400 );
		}
		// Un trabajo se confirma una sola vez: se reclama con un candado (dos envíos simultáneos no importan dos veces).
		if ( ! add_option( 'aralia_import_lock_' . $token, time(), '', false ) ) {
			return new \WP_Error( 'aralia_lock', 'Esta importación ya se está procesando o se procesó.', 409 );
		}
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		try {
			$summary = Importer::import( $job['result']['rows'], self::sideload( $token, $job['files'], $job['result']['rows'] ), $confirmed );
			self::cleanup( self::work_dir( $token ) );
			delete_transient( 'aralia_import_' . $token );
			return $summary;
		} catch ( \Throwable $e ) {
			// El trabajo se conserva (la importación es idempotente por SKU) y el candado se libera: se puede reintentar la confirmación.
			return new \WP_Error( 'aralia_import_failed', 'La importación se interrumpió: ' . $e->getMessage() . ' Puedes confirmarla de nuevo.', 500 );
		} finally {
			delete_option( 'aralia_import_lock_' . $token );
		}
	}

	/**
	 * Sube a la biblioteca de medios SOLO las fotos que el CSV referencia. La ruta se reconstruye (no se confía en la guardada), debe estar
	 * dentro de la carpeta del trabajo, no ser un enlace y volver a pasar la comprobación de tipo real.
	 *
	 * @param string                          $token Token del trabajo.
	 * @param string[]                        $names Nombres de archivo extraídos.
	 * @param array<int,array<string,string>> $rows  Filas.
	 * @return array<string,int> Nombre (minúsculas) => id de adjunto.
	 */
	private static function sideload( string $token, array $names, array $rows ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$wanted = array();
		foreach ( $rows as $row ) {
			foreach ( array( 'foto', 'foto_uso', 'swatch' ) as $col ) {
				if ( '' !== $row[ $col ] ) {
					$wanted[ strtolower( $row[ $col ] ) ] = true;
				}
			}
		}
		$dir = (string) realpath( self::work_dir( $token ) );
		$ids = array();
		foreach ( $names as $name ) {
			$name = basename( (string) $name );
			$path = $dir . '/' . $name;
			if ( '' === $dir || ! isset( $wanted[ strtolower( $name ) ] ) || ! Validator::is_safe_filename( $name ) || is_link( $path ) || ! is_file( $path ) || 0 !== strpos( (string) realpath( $path ), $dir . '/' ) || ! in_array( MediaZip::real_mime( $path ), MediaZip::MIMES, true ) ) {
				continue;
			}
			$tmp = wp_tempnam( $name );
			if ( ! $tmp || ! copy( $path, $tmp ) ) {
				continue;
			}
			$id = media_handle_sideload(
				array(
					'name'     => $name,
					'tmp_name' => $tmp,
				),
				0
			);
			if ( ! is_wp_error( $id ) ) {
				$ids[ strtolower( $name ) ] = (int) $id;
			} else {
				wp_delete_file( $tmp );
			}
		}
		return $ids;
	}

	/**
	 * Borra la carpeta temporal.
	 *
	 * @param string $dir Carpeta.
	 * @return void
	 */
	public static function cleanup( string $dir ): void {
		MediaZip::remove_dir( $dir );
	}
}
