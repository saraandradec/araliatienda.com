<?php
/**
 * Pantalla "Catálogo incompleto" (T041, FR-066).
 *
 * @package Aralia
 */

namespace Aralia\Admin;

use Aralia\Catalog\PublishRule;

defined( 'ABSPATH' ) || exit;

/**
 * Lista los borradores a los que la regla de publicación les encontró faltantes, y avisa cuando un intento de publicar fue revertido.
 */
final class IncompleteCatalog {

	/**
	 * Engancha menú y aviso.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * Submenú de Productos.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=product', 'Catálogo incompleto', 'Catálogo incompleto', 'manage_woocommerce', 'aralia-incomplete', array( self::class, 'render' ) );
	}

	public const PER_PAGE = 50;
	public const SCAN_MAX = 500;

	/**
	 * Borradores revisados en la última pasada (para avisar si se alcanzó el tope).
	 *
	 * @var int
	 */
	private static int $scanned = 0;

	/**
	 * Borradores a los que les falta algo para publicarse (id => faltantes), calculado al momento con la misma regla de publicación.
	 * Se recorren hasta SCAN_MAX borradores y se pagina sobre el resultado YA filtrado.
	 *
	 * @param int $page Página (de PER_PAGE).
	 * @return array<int,string[]>
	 */
	public static function items( int $page = 1 ): array {
		return array_slice( self::all_items(), ( max( 1, $page ) - 1 ) * self::PER_PAGE, self::PER_PAGE, true );
	}

	/**
	 * Todos los incompletos (hasta el tope de recorrido).
	 *
	 * @return array<int,string[]>
	 */
	public static function all_items(): array {
		$ids           = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'draft', 'pending', 'private' ),
				'posts_per_page' => self::SCAN_MAX, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		self::$scanned = count( $ids );
		$out           = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( ! $product ) {
				continue;
			}
			$missing = PublishRule::evaluate( PublishRule::facts_for( $product ) );
			if ( $missing ) {
				$out[ (int) $id ] = $missing;
			}
		}
		return $out;
	}

	/**
	 * Pantalla.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No tienes permiso.', 'aralia' ), 403 );
		}
		echo '<div class="wrap"><h1>Catálogo incompleto</h1>';
		$page  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$all   = self::all_items();
		$total = count( $all );
		$items = array_slice( $all, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE, true );
		if ( ! $items ) {
			echo '<p>No hay productos incompletos.</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Producto</th><th>Qué falta</th></tr></thead><tbody>';
		foreach ( $items as $id => $missing ) {
			$labels = array_map( static fn( $m ) => PublishRule::LABELS[ $m ] ?? $m, $missing );
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td></tr>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( get_the_title( $id ) ),
				esc_html( implode( '; ', $labels ) )
			);
		}
		echo '</tbody></table>';
		$base = admin_url( 'edit.php?post_type=product&page=aralia-incomplete' );
		echo '<p>' . ( $page > 1 ? '<a href="' . esc_url( add_query_arg( 'paged', $page - 1, $base ) ) . '">← Anteriores</a> ' : '' ) . ( $page * self::PER_PAGE < $total ? '<a href="' . esc_url( add_query_arg( 'paged', $page + 1, $base ) ) . '">Siguientes →</a>' : '' ) . ' (' . (int) $total . ' en total' . ( self::$scanned >= self::SCAN_MAX ? '; se revisan los ' . self::SCAN_MAX . ' más recientes' : '' ) . ')</p></div>';
	}

	/**
	 * Aviso tras un intento de publicar revertido.
	 *
	 * @return void
	 */
	public static function notice(): void {
		$summary = get_transient( 'aralia_import_summary_' . get_current_user_id() );
		if ( is_array( $summary ) ) {
			delete_transient( 'aralia_import_summary_' . get_current_user_id() );
			$class = $summary['errors'] ? 'notice-warning' : 'notice-success';
			printf(
				'<div class="notice %s"><p>Importación: %d creados, %d actualizados, %d en borrador, %d cambios de precio sin confirmar (precio conservado).</p>%s%s</div>',
				esc_attr( $class ),
				(int) $summary['created'],
				(int) $summary['updated'],
				(int) $summary['drafts'],
				count( (array) $summary['skipped_prices'] ),
				$summary['errors'] ? '<p>Errores: ' . esc_html( implode( ' | ', array_map( 'strval', $summary['errors'] ) ) ) . '</p>' : '',
				$summary['warnings'] ? '<p>Avisos: ' . esc_html( implode( ' | ', array_map( 'strval', $summary['warnings'] ) ) ) . '</p>' : ''
			);
		}
		$key  = 'aralia_publish_blocked_' . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return;
		}
		delete_transient( $key );
		$labels = array_map( static fn( $m ) => PublishRule::LABELS[ $m ] ?? $m, (array) $data['missing'] );
		printf( '<div class="notice notice-error"><p>No se publicó «%s»: %s.</p></div>', esc_html( get_the_title( (int) $data['id'] ) ), esc_html( implode( '; ', $labels ) ) );
	}
}
