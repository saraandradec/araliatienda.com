<?php
/**
 * Importador y ZIP del inventario (T044, T045, T046).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Catalog\Importer;
use Aralia\Catalog\MediaZip;
use Aralia\Catalog\PublishRule;
use Aralia\Catalog\Validator;
use Aralia\Admin\ImportScreen;
use Aralia\Tests\Support\IsolatedTestCase;

/**
 * El importador crea simples y variables, es idempotente y deja borradores; el ZIP rechaza zip slip y archivos no imagen.
 */
final class ImportTest extends IsolatedTestCase {

	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	private function fixture(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/inventory/valid.csv' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private function attachment( string $name ): int {
		$id = (int) wp_insert_attachment( array( 'post_title' => $name, 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ) );
		update_attached_file( $id, '2026/11/' . $name . '.png' );
		return $id;
	}

	private function cleanup_skus(): void {
		foreach ( array( 'LAB-001', 'LAB-002', 'CEJ-001' ) as $sku ) {
			$id = wc_get_product_id_by_sku( $sku );
			if ( $id ) {
				wp_delete_post( $id, true );
			}
		}
	}

	protected function tearDown(): void {
		$this->cleanup_skus();
		parent::tearDown();
	}

	public function test_fixture_csv_validates_and_flags_incomplete_rows(): void {
		$r = Validator::validate( $this->fixture(), array() );
		$this->assertSame( array(), $r['fatal'] );
		$this->assertSame( array(), $r['errors'], wp_json_encode( $r['errors'] ) );
		$this->assertArrayHasKey( 3, $r['incomplete'], 'LAB-002 no tiene foto, swatch ni stock' );
		$this->assertArrayNotHasKey( 4, $r['incomplete'], 'El padre de un producto por tono no se marca incompleto por su propio stock o foto' );
	}

	public function test_import_creates_simple_variable_and_draft_products_and_is_idempotent(): void {
		$r   = Validator::validate( $this->fixture(), array() );
		$att = array( 'labial.png' => $this->attachment( 'labial' ), 'swatch.png' => $this->attachment( 'swatch' ), 'cejas.png' => $this->attachment( 'cejas' ) );
		$sum = Importer::import( $r['rows'], $att, array() );
		$this->assertSame( array(), $sum['errors'] );
		$this->assertSame( 3, $sum['created'] );

		$lab = wc_get_product( wc_get_product_id_by_sku( 'LAB-001' ) );
		$this->assertSame( 'publish', $lab->get_status(), 'Completo: se publica' );
		$this->assertSame( 20000, (int) $lab->get_regular_price() );
		$this->assertSame( 10, $lab->get_stock_quantity() );
		$this->assertSame( '8000', (string) $lab->get_meta( '_ara_cost' ) );
		$this->assertCount( 2, $lab->get_cross_sell_ids(), 'Combina con: exactamente 2' );

		$gloss = wc_get_product( wc_get_product_id_by_sku( 'LAB-002' ) );
		$this->assertSame( 'draft', $gloss->get_status(), 'Sin foto ni stock: borrador' );
		$this->assertArrayHasKey( $gloss->get_id(), \Aralia\Admin\IncompleteCatalog::items() );

		$cej = wc_get_product( wc_get_product_id_by_sku( 'CEJ-001' ) );
		$this->assertTrue( $cej->is_type( 'variable' ) );
		$this->assertSame( 'publish', $cej->get_status(), 'Producto por tono completo (variación con swatch y stock): publicado aunque el padre no traiga stock' );
		$this->assertFalse( $cej->get_manage_stock(), 'El padre variable no gestiona stock' );
		$this->assertTrue( $cej->is_in_stock(), 'Con stock en la variación, el producto no queda agotado' );
		$this->assertCount( 1, $cej->get_children() );
		$var = wc_get_product( $cej->get_children()[0] );
		$this->assertSame( 'miel', $var->get_attributes()['pa_tono'] );
		$this->assertSame( 6, $var->get_stock_quantity() );

		$this->assertSame( array( 18000 ), array_column( get_post_meta( $var->get_id(), '_ara_price_history', true ), 'price' ), 'Historial de la variación' );
		$this->assertSame( array( 20000 ), array_column( get_post_meta( $lab->get_id(), '_ara_price_history', true ), 'price' ), 'Historial del producto simple (FR-061)' );
		$again = Importer::import( $r['rows'], $att, array() );
		$this->assertSame( 0, $again['created'] );
		$this->assertSame( 3, $again['updated'] );
		$this->assertCount( 1, wc_get_product( wc_get_product_id_by_sku( 'CEJ-001' ) )->get_children(), 'No duplica variaciones' );
	}

	public function test_attachment_names_are_matched_without_case_and_orphan_variations_are_reported(): void {
		$r   = Validator::validate( $this->fixture(), array() );
		Importer::import( $r['rows'], array( 'LABIAL.PNG' => $this->attachment( 'l' ) ), array() );
		$this->assertNotSame( 0, wc_get_product( wc_get_product_id_by_sku( 'LAB-001' ) )->get_image_id(), 'Labial.png casa con LABIAL.PNG' );
		$orphan = $r['rows'];
		unset( $orphan[4] ); // Se quita el padre: la variación queda huérfana.
		$sum = Importer::import( $orphan, array(), array() );
		$this->assertStringContainsString( 'sin su padre', implode( ' ', $sum['errors'] ) );
	}

	public function test_combina_con_ignores_variation_skus_and_warns(): void {
		$r    = Validator::validate( $this->fixture(), array() );
		$rows = $r['rows'];
		$rows[2]['combina_con'] = 'CEJ-001-MIE|LAB-002';
		Importer::import( $rows, array(), array() );
		$lab = wc_get_product( wc_get_product_id_by_sku( 'LAB-001' ) );
		$this->assertSame( array( wc_get_product_id_by_sku( 'LAB-002' ) ), $lab->get_cross_sell_ids(), 'Una variación no puede ser «combina con»' );
	}

	public function test_unconfirmed_price_change_over_twenty_percent_keeps_the_old_price(): void {
		$r = Validator::validate( $this->fixture(), array() );
		Importer::import( $r['rows'], array(), array() );
		$rows                      = $r['rows'];
		$rows[2]['precio']         = '40000';
		$sum                       = Importer::import( $rows, array(), array() );
		$this->assertContains( 'LAB-001', $sum['skipped_prices'] );
		$this->assertSame( 20000, (int) wc_get_product( wc_get_product_id_by_sku( 'LAB-001' ) )->get_regular_price() );
		$this->assertSame( array( 20000 ), array_column( get_post_meta( wc_get_product_id_by_sku( 'LAB-001' ), '_ara_price_history', true ), 'price' ), 'El historial no registra un precio que no se aplicó' );
		Importer::import( $rows, array(), array( 'LAB-001' ) );
		$this->assertSame( 40000, (int) wc_get_product( wc_get_product_id_by_sku( 'LAB-001' ) )->get_regular_price(), 'Confirmado: se aplica' );
	}

	private function zip( array $entries ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'aralia-zip' );
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		foreach ( $entries as $name => $bytes ) {
			$zip->addFromString( $name, $bytes );
		}
		$zip->close();
		return $path;
	}

	private function dest(): string {
		return trailingslashit( sys_get_temp_dir() ) . 'aralia-import-' . bin2hex( random_bytes( 6 ) );
	}

	public function test_zip_with_valid_images_is_extracted_outside_uploads(): void {
		$dest = $this->dest();
		$out  = MediaZip::extract( $this->zip( array( 'a.png' => base64_decode( self::PNG ) ) ), $dest ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->assertSame( array(), $out['errors'] );
		$this->assertArrayHasKey( 'a.png', $out['files'] );
		$this->assertStringNotContainsString( wp_get_upload_dir()['basedir'], $out['files']['a.png'] );
		\Aralia\Admin\ImportScreen::cleanup( $dest );
		$this->assertDirectoryDoesNotExist( $dest );
	}

	/**
	 * @dataProvider provide_bad_entries
	 */
	public function test_zip_rejects_slip_paths_non_images_and_folders( string $name ): void {
		$dest = $this->dest();
		$out  = MediaZip::extract( $this->zip( array( 'ok.png' => base64_decode( self::PNG ), $name => base64_decode( self::PNG ) ) ), $dest ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->assertNotEmpty( $out['errors'], $name );
		$this->assertSame( array(), $out['files'], 'Se rechaza el ZIP completo' );
		$this->assertFileDoesNotExist( dirname( $dest ) . '/evil.png' );
		\Aralia\Admin\ImportScreen::cleanup( $dest );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_bad_entries(): array {
		return array(
			'zip slip'        => array( '../evil.png' ),
			'zip slip hondo'  => array( 'a/../../evil.png' ),
			'ruta absoluta'   => array( '/tmp/evil.png' ),
			'subcarpeta'      => array( 'fotos/b.png' ),
			'barra invertida' => array( '..\\evil.png' ),
			'extensión php'   => array( 'shell.php' ),
			'doble extensión' => array( 'shell.png.php' ),
			'unidad windows'  => array( 'C:evil.png' ),
		);
	}

	public function test_zip_rejects_a_php_file_renamed_to_png_by_real_mime(): void {
		$dest = $this->dest();
		$out  = MediaZip::extract( $this->zip( array( 'x.png' => '<?php echo 1;' ) ), $dest );
		$this->assertNotEmpty( $out['errors'] );
		$this->assertStringContainsString( 'tipo real', implode( ' ', $out['errors'] ) );
		$this->assertSame( array(), $out['files'] );
		\Aralia\Admin\ImportScreen::cleanup( $dest );
	}

	public function test_zip_rejects_more_than_the_file_limit_and_duplicate_names(): void {
		$entries = array();
		for ( $i = 0; $i <= MediaZip::MAX_FILES; $i++ ) {
			$entries[ "f$i.png" ] = base64_decode( self::PNG ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		$out = MediaZip::extract( $this->zip( $entries ), $this->dest() );
		$this->assertStringContainsString( '200', implode( ' ', $out['errors'] ) );
		$out = MediaZip::extract( $this->zip( array( 'A.png' => base64_decode( self::PNG ), 'a.PNG' => base64_decode( self::PNG ) ) ), $this->dest() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->assertStringContainsString( 'repetido', implode( ' ', $out['errors'] ) );
	}

	public function test_zip_cannot_be_extracted_inside_uploads_and_garbage_is_rejected(): void {
		$inside = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'aralia-import-x';
		$out    = MediaZip::extract( $this->zip( array( 'a.png' => base64_decode( self::PNG ) ) ), $inside ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->assertNotEmpty( $out['errors'] );
		$this->assertDirectoryDoesNotExist( $inside );
		$garbage = (string) tempnam( sys_get_temp_dir(), 'aralia-zip' );
		file_put_contents( $garbage, 'esto no es un zip' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertNotEmpty( MediaZip::extract( $garbage, $this->dest() )['errors'] );
	}

	public function test_zip_rejects_pixel_bombs_huge_files_and_cleans_the_whole_folder_on_failure(): void {
		$wide = imagecreatetruecolor( MediaZip::MAX_SIDE + 1, 1 );
		ob_start();
		imagepng( $wide );
		$bomb = (string) ob_get_clean();
		$dest = $this->dest();
		$out  = MediaZip::extract( $this->zip( array( 'ok.png' => base64_decode( self::PNG ), 'bomba.png' => $bomb ) ), $dest ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->assertStringContainsString( 'dimensiones', implode( ' ', $out['errors'] ) );
		$this->assertDirectoryDoesNotExist( $dest, 'Al fallar se borra la carpeta completa, también el archivo parcial' );

		$dest = $this->dest();
		$big  = "\x89PNG\r\n\x1a\n" . random_bytes( MediaZip::MAX_FILE + 1024 );
		$out  = MediaZip::extract( $this->zip( array( 'grande.png' => $big ) ), $dest );
		$this->assertStringContainsString( '10 MB', implode( ' ', $out['errors'] ) );
		$this->assertDirectoryDoesNotExist( $dest );
	}

	public function test_zip_cannot_be_extracted_under_wp_content_and_stale_folders_are_purged(): void {
		$inside = WP_CONTENT_DIR . '/aralia-import-x';
		$out    = MediaZip::extract( $this->zip( array( 'a.png' => base64_decode( self::PNG ) ) ), $inside ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$this->assertNotEmpty( $out['errors'] );
		$this->assertDirectoryDoesNotExist( $inside );

		$old = $this->dest();
		mkdir( $old, 0700 );
		file_put_contents( $old . '/a.png', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		touch( $old, time() - 3 * HOUR_IN_SECONDS );
		$recent = $this->dest();
		mkdir( $recent, 0700 );
		touch( $recent, time() - 90 * MINUTE_IN_SECONDS ); // Más de la hora del trabajo, pero aún no purgable: una confirmación lenta no pierde sus fotos.
		$new = $this->dest();
		mkdir( $new, 0700 );
		$this->assertGreaterThanOrEqual( 1, MediaZip::purge_stale() );
		$this->assertDirectoryDoesNotExist( $old );
		$this->assertDirectoryExists( $new );
		$this->assertDirectoryExists( $recent );
		MediaZip::remove_dir( $new );
		MediaZip::remove_dir( $recent );

		$target = (string) tempnam( sys_get_temp_dir(), 'aralia-target' );
		unlink( $target );
		mkdir( $target, 0700 );
		file_put_contents( $target . '/keep.txt', 'k' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$link = $this->dest();
		symlink( $target, $link );
		MediaZip::remove_dir( $link );
		$this->assertFileExists( $target . '/keep.txt', 'Un enlace simbólico se quita como enlace: nunca se sigue' );
		$this->assertFalse( is_link( $link ) );
		unlink( $target . '/keep.txt' );
		rmdir( $target );
	}

	/**
	 * Filas del fixture con una segunda variación (Canela).
	 *
	 * @return array<int,array<string,string>>
	 */
	private function rows_with_two_tones(): array {
		$rows      = Validator::validate( $this->fixture(), array() )['rows'];
		$second    = $rows[5];
		$second['sku']     = 'CEJ-001-CAN';
		$second['tono']    = 'Canela';
		$second['subtono'] = 'cálido';
		$rows[6]   = $second;
		return $rows;
	}

	private function tone_slugs( int $parent_id ): array {
		$slugs = wp_get_object_terms( $parent_id, 'pa_tono', array( 'fields' => 'slugs' ) );
		sort( $slugs );
		return $slugs;
	}

	public function test_a_partial_reimport_keeps_every_tone_selectable(): void {
		$rows = $this->rows_with_two_tones();
		Importer::import( $rows, array(), array() );
		$pid = wc_get_product_id_by_sku( 'CEJ-001' );
		$this->assertSame( array( 'canela', 'miel' ), $this->tone_slugs( $pid ) );

		$partial = $rows;
		unset( $partial[6] ); // El CSV solo trae el padre y UNA variación.
		Importer::import( $partial, array(), array() );
		$this->assertSame( array( 'canela', 'miel' ), $this->tone_slugs( $pid ), 'Los tonos que no vienen en el CSV siguen pudiendo elegirse' );
		$options = wc_get_product( $pid )->get_attributes()['pa_tono']->get_options();
		$this->assertCount( 2, $options );

		$only_parent = $rows;
		unset( $only_parent[5], $only_parent[6] );
		Importer::import( $only_parent, array(), array() );
		$this->assertSame( array( 'canela', 'miel' ), $this->tone_slugs( $pid ), 'Un padre sin filas de variación no toca sus tonos' );
		$this->assertCount( 2, wc_get_product( $pid )->get_children() );
	}

	public function test_reimport_does_not_republish_an_intentional_draft_but_retries_a_blocked_one(): void {
		$r   = Validator::validate( $this->fixture(), array() );
		$att = array( 'labial.png' => $this->attachment( 'labial' ), 'swatch.png' => $this->attachment( 'swatch' ), 'cejas.png' => $this->attachment( 'cejas' ) );
		Importer::import( $r['rows'], $att, array() );
		$lab = wc_get_product_id_by_sku( 'LAB-001' );
		wp_update_post( array( 'ID' => $lab, 'post_status' => 'draft' ) ); // La operadora lo retiró a propósito.
		Importer::import( $r['rows'], $att, array() );
		$this->assertSame( 'draft', get_post_status( $lab ), 'Un borrador intencional no vuelve a la tienda' );

		$gloss = wc_get_product_id_by_sku( 'LAB-002' );
		$this->assertSame( 'draft', get_post_status( $gloss ) );
		$this->assertSame( '1', get_post_meta( $gloss, PublishRule::BLOCKED_META, true ), 'La regla lo bloqueó: se reintenta' );
		$rows              = $r['rows'];
		$rows[3]['foto']   = 'labial.png';
		$rows[3]['swatch'] = 'swatch.png';
		$rows[3]['stock']  = '5';
		Importer::import( $rows, $att, array() );
		$this->assertSame( 'publish', get_post_status( $gloss ), 'Al completarse, el que la regla bloqueó se publica' );
		$this->assertSame( '', (string) get_post_meta( $gloss, PublishRule::BLOCKED_META, true ) );
	}

	public function test_an_exception_in_one_row_keeps_the_rule_on_and_the_failed_product_is_retried_by_the_next_import(): void {
		$r    = Validator::validate( $this->fixture(), array() );
		$att  = array( 'labial.png' => $this->attachment( 'labial' ), 'swatch.png' => $this->attachment( 'swatch' ), 'cejas.png' => $this->attachment( 'cejas' ) );
		$boom = static function ( $id ): void {
			if ( 'LAB-001' === get_post_meta( $id, '_sku', true ) ) {
				throw new \RuntimeException( 'boom' );
			}
		};
		add_action( 'woocommerce_new_product', $boom );
		$sum = Importer::import( $r['rows'], $att, array() );
		remove_action( 'woocommerce_new_product', $boom );
		$this->assertStringContainsString( 'boom', implode( ' ', $sum['errors'] ) );
		$this->assertSame( 'draft', get_post_status( wc_get_product_id_by_sku( 'LAB-002' ) ), 'LAB-002 no tiene foto ni stock: la regla sigue activa tras la excepción' );
		$lab = wc_get_product_id_by_sku( 'LAB-001' );
		$this->assertGreaterThan( 0, $lab, 'El producto alcanzó a crearse antes de fallar' );
		$this->assertSame( 'draft', get_post_status( $lab ) );
		$this->assertSame( '1', get_post_meta( $lab, PublishRule::BLOCKED_META, true ), 'Quedó marcado para reintentarse' );

		Importer::import( $r['rows'], $att, array() );
		$this->assertSame( 'publish', get_post_status( $lab ), 'Volver a importar lo publica: lo que dice la deuda declarada' );
	}

	public function test_a_variation_price_change_over_twenty_percent_is_skipped_and_not_recorded(): void {
		$r = Validator::validate( $this->fixture(), array() );
		Importer::import( $r['rows'], array(), array() );
		$rows              = $r['rows'];
		$rows[5]['precio'] = '40000';
		$sum               = Importer::import( $rows, array(), array() );
		$this->assertContains( 'CEJ-001-MIE', $sum['skipped_prices'] );
		$vid = wc_get_product_id_by_sku( 'CEJ-001-MIE' );
		$this->assertSame( 18000, (int) wc_get_product( $vid )->get_regular_price() );
		$this->assertSame( array( 18000 ), array_column( get_post_meta( $vid, '_ara_price_history', true ), 'price' ) );
	}

	public function test_an_exception_outside_the_row_loop_releases_the_lock_and_allows_retrying(): void {
		$owner  = (int) wp_insert_user( array( 'user_login' => 'own' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'own' . wp_rand() . '@example.com', 'role' => 'administrator' ) );
		$result = Validator::validate( $this->fixture(), array() );
		$token  = bin2hex( random_bytes( 8 ) );
		set_transient( 'aralia_import_' . $token, array( 'user' => $owner, 'result' => $result, 'zip_errors' => array(), 'files' => array() ), HOUR_IN_SECONDS );
		// La segunda pasada («combina con») está fuera del try por fila: aquí se hace fallar.
		$boom = static function ( $product ): void {
			if ( $product instanceof \WC_Product && $product->get_cross_sell_ids() ) {
				throw new \RuntimeException( 'fallo en la segunda pasada' );
			}
		};
		add_action( 'woocommerce_before_product_object_save', $boom, 5 );
		$err = ImportScreen::process_job( $token, array(), $owner );
		remove_action( 'woocommerce_before_product_object_save', $boom, 5 );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 500, $err->get_error_data() );
		$this->assertFalse( get_option( 'aralia_import_lock_' . $token ), 'El candado se libera aunque falle' );
		$this->assertNotFalse( get_transient( 'aralia_import_' . $token ), 'El trabajo se conserva para reintentar' );

		$ok = ImportScreen::process_job( $token, array(), $owner );
		$this->assertIsArray( $ok, 'Reintentar la confirmación funciona (idempotente por SKU)' );
	}

	public function test_confirming_a_job_checks_owner_errors_lock_and_runs_once(): void {
		$owner = (int) wp_insert_user( array( 'user_login' => 'own' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'own' . wp_rand() . '@example.com', 'role' => 'administrator' ) );
		$other = (int) wp_insert_user( array( 'user_login' => 'oth' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'oth' . wp_rand() . '@example.com', 'role' => 'administrator' ) );
		$result = Validator::validate( $this->fixture(), array() );
		$make   = function ( string $token, array $over = array() ) use ( $owner, $result ): void {
			set_transient( 'aralia_import_' . $token, array_merge( array( 'user' => $owner, 'result' => $result, 'zip_errors' => array(), 'files' => array() ), $over ), HOUR_IN_SECONDS );
		};

		$token = bin2hex( random_bytes( 8 ) );
		$make( $token );
		$err = ImportScreen::process_job( $token, array(), $other );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 400, $err->get_error_data(), 'El trabajo de otra usuaria no se confirma' );
		$this->assertSame( 0, (int) wc_get_product_id_by_sku( 'LAB-001' ) );

		$bad = array_merge( $result, array( 'errors' => array( 2 => array( 'x' ) ) ) );
		$make( $token, array( 'result' => $bad ) );
		$this->assertSame( 400, ImportScreen::process_job( $token, array(), $owner )->get_error_data(), 'Con errores no se importa' );
		$make( $token, array( 'zip_errors' => array( 'zip malo' ) ) );
		$this->assertSame( 400, ImportScreen::process_job( $token, array(), $owner )->get_error_data() );

		$make( $token );
		add_option( 'aralia_import_lock_' . $token, time(), '', false );
		$this->assertSame( 409, ImportScreen::process_job( $token, array(), $owner )->get_error_data(), 'Un trabajo ya reclamado no se repite' );
		delete_option( 'aralia_import_lock_' . $token );

		$summary = ImportScreen::process_job( $token, array(), $owner );
		$this->assertIsArray( $summary );
		$this->assertSame( 3, $summary['created'] );
		$this->assertFalse( get_transient( 'aralia_import_' . $token ), 'El trabajo se borra al terminar' );
		$this->assertFalse( get_option( 'aralia_import_lock_' . $token ), 'El candado también' );
		$again = ImportScreen::process_job( $token, array(), $owner );
		$this->assertInstanceOf( \WP_Error::class, $again, 'No se puede confirmar dos veces' );
	}
}
