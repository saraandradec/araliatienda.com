<?php
/**
 * Tema de bloques aralia (T047).
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Tests\Support\IsolatedTestCase;

/**
 * Tokens, contraste, fuentes auto-alojadas, plantillas, y GSAP solo donde corresponde.
 */
final class ThemeTest extends IsolatedTestCase {

	private function dir(): string {
		return dirname( __DIR__, 2 ) . '/../wp-content/themes/aralia';
	}

	/**
	 * Archivos de una o varias carpetas del tema con una extensión (sin GLOB_BRACE, que no existe en todos los contenedores).
	 *
	 * @param string[] $subdirs Carpetas relativas al tema.
	 * @param string   $ext     Extensión sin punto.
	 * @return string[]
	 */
	private function files( array $subdirs, string $ext ): array {
		$out = array();
		foreach ( $subdirs as $sub ) {
			foreach ( (array) scandir( $this->dir() . '/' . $sub ) as $name ) {
				if ( substr( $name, -strlen( $ext ) - 1 ) === '.' . $ext ) {
					$out[] = $this->dir() . '/' . $sub . '/' . $name;
				}
			}
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function json( string $file ): array {
		$data = json_decode( (string) file_get_contents( $this->dir() . '/' . $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertIsArray( $data, $file . ' es JSON válido' );
		return $data;
	}

	/**
	 * Luminancia relativa WCAG de un color #RRGGBB.
	 */
	private static function luminance( string $hex ): float {
		$rgb = array_map(
			static function ( string $c ): float {
				$v = hexdec( $c ) / 255;
				return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
			},
			array( substr( $hex, 1, 2 ), substr( $hex, 3, 2 ), substr( $hex, 5, 2 ) )
		);
		return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
	}

	private static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * @return array<string,string>
	 */
	private function palette(): array {
		$out = array();
		foreach ( $this->json( 'theme.json' )['settings']['color']['palette'] as $c ) {
			$out[ $c['slug'] ] = $c['color'];
		}
		return $out;
	}

	public function test_theme_json_declares_the_brand_tokens_and_a_dark_variation(): void {
		$p = $this->palette();
		$this->assertSame( '#5D2044', $p['plum'] );
		$this->assertSame( '#3A0F28', $p['plum-deep'] );
		$this->assertSame( '#F3F7EF', $p['cream'] );
		$this->assertSame( '#E2B4C4', $p['rose'] );
		$this->assertSame( '#C8A46A', $p['champagne'] );
		$this->assertArrayHasKey( 'champagne-ink', $p, 'Champagne oscurecido para texto sobre crema' );
		$this->assertSame( 3, $this->json( 'theme.json' )['version'] );
		$dark = $this->json( 'styles/dark.json' );
		$this->assertSame( 'var(--wp--preset--color--plum-deep)', $dark['styles']['color']['background'] );
		$this->assertSame( 'var(--wp--preset--color--cream)', $dark['styles']['color']['text'] );
	}

	/**
	 * @dataProvider provide_text_pairs
	 */
	public function test_text_color_pairs_meet_wcag_aa( string $fg, string $bg, float $min ): void {
		$p = $this->palette();
		$this->assertGreaterThanOrEqual( $min, self::contrast( $p[ $fg ], $p[ $bg ] ), "$fg sobre $bg" );
	}

	/**
	 * @return array<string,array{string,string,float}>
	 */
	public static function provide_text_pairs(): array {
		return array(
			'texto sobre crema (claro)'         => array( 'plum-deep', 'cream', 7.0 ),
			'enlace sobre crema'                => array( 'plum', 'cream', 7.0 ),
			'champagne oscurecido sobre crema'  => array( 'champagne-ink', 'cream', 4.5 ),
			'crema sobre ciruela profundo'      => array( 'cream', 'plum-deep', 7.0 ),
			'rosa sobre ciruela profundo'       => array( 'rose', 'plum-deep', 4.5 ),
			'crema sobre ciruela (botón)'       => array( 'cream', 'plum', 7.0 ),
			'rosa sobre ciruela'                => array( 'rose', 'plum', 4.5 ),
			'ciruela profundo sobre rosa (botón oscuro)' => array( 'plum-deep', 'rose', 7.0 ),
		);
	}

	public function test_plain_champagne_is_decorative_only_and_never_a_text_color(): void {
		$p = $this->palette();
		$this->assertLessThan( 4.5, self::contrast( $p['champagne'], $p['cream'] ), 'Champagne puro NO alcanza AA sobre crema: por eso existe champagne-ink' );
		$theme = (string) file_get_contents( $this->dir() . '/theme.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$dark  = (string) file_get_contents( $this->dir() . '/styles/dark.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertStringNotContainsString( '"text": "var(--wp--preset--color--champagne)"', $theme . $dark );
		foreach ( $this->files( array( 'templates', 'parts' ), 'html' ) as $file ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->assertStringNotContainsString( '"textColor":"champagne"', (string) file_get_contents( $file ), basename( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	public function test_fonts_are_self_hosted_and_every_declared_file_exists(): void {
		$theme = $this->json( 'theme.json' );
		$count = 0;
		foreach ( $theme['settings']['typography']['fontFamilies'] as $family ) {
			foreach ( $family['fontFace'] as $face ) {
				foreach ( $face['src'] as $src ) {
					$this->assertStringStartsWith( 'file:./assets/fonts/', $src, 'Auto-alojada: ni Google Fonts ni CDN' );
					$file = $this->dir() . '/' . substr( $src, strlen( 'file:./' ) );
					$this->assertFileExists( $file );
					$this->assertSame( 'wOF2', substr( (string) file_get_contents( $file ), 0, 4 ), 'woff2 real' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					++$count;
				}
			}
		}
		$this->assertSame( 8, $count, '5 de Cormorant (300, 300 cursiva, 400 cursiva, 500, 500 cursiva) y 3 de Jost' );
		$cormorant = array_values( array_filter( $theme['settings']['typography']['fontFamilies'], static fn( $f ) => 'cormorant' === $f['slug'] ) )[0];
		$weights   = array_map( static fn( $f ) => $f['fontWeight'] . ( 'italic' === $f['fontStyle'] ? 'i' : '' ), $cormorant['fontFace'] );
		foreach ( array( '300', '400i', '500' ) as $needed ) {
			$this->assertContains( $needed, $weights, '§11: Cormorant 300, 400 cursiva y 500' );
		}
		$families = array_column( $theme['settings']['typography']['fontFamilies'], 'name' );
		$this->assertSame( array( 'Cormorant Garamond', 'Jost' ), $families );
	}

	public function test_asset_hashes_in_the_inventory_match_the_files(): void {
		$doc = (string) file_get_contents( $this->dir() . '/assets/ASSETS.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( array_merge( $this->files( array( 'assets/fonts' ), 'woff2' ), array( $this->dir() . '/assets/js/gsap.min.js', $this->dir() . '/assets/js/ScrollTrigger.min.js' ) ) as $file ) {
			$this->assertStringContainsString( hash_file( 'sha256', $file ), $doc, basename( $file ) . ' coincide con ASSETS.md' );
		}
	}

	public function test_templates_parts_and_the_mobile_drawer_exist_with_categories_and_sections(): void {
		foreach ( array( 'index', 'page', 'single', 'archive', 'search', '404', 'front-page' ) as $t ) {
			$this->assertFileExists( $this->dir() . "/templates/$t.html" );
		}
		$header = (string) file_get_contents( $this->dir() . '/parts/header.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertFileExists( $this->dir() . '/parts/footer.html' );
		$this->assertStringContainsString( '"overlayMenu":"mobile"', $header, 'Cajón móvil' );
		foreach ( array( 'Categorías', 'Secciones', 'Regalos', 'Cómo llega' ) as $label ) {
			$this->assertStringContainsString( '"label":"' . $label . '"', $header, "FR-001: $label" );
		}
		foreach ( array( 'Labios', 'Cejas', 'Pestañas', 'Ojos', 'Piel', 'Brochas', 'Accesorios' ) as $cat ) {
			$this->assertStringContainsString( '"label":"' . $cat . '"', $header );
		}
		foreach ( \Aralia\Catalog\Taxonomies::SECTIONS as $slug => $name ) {
			$this->assertStringContainsString( "/secciones/$slug/", $header, $name );
		}
		foreach ( array( 'wp:search', 'woocommerce/customer-account', 'woocommerce/mini-cart' ) as $block ) {
			$this->assertStringContainsString( $block, $header, "FR-001: $block" );
		}
	}

	public function test_block_templates_are_parsed_by_wordpress_without_errors(): void {
		foreach ( $this->files( array( 'templates', 'parts' ), 'html' ) as $file ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$blocks = parse_blocks( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->assertNotEmpty( $blocks, basename( $file ) );
			$names = array_filter( array_column( $blocks, 'blockName' ) );
			$this->assertNotEmpty( $names, basename( $file ) . ' tiene bloques' );
		}
	}

	public function test_the_theme_has_no_inline_scripts_and_no_external_resources(): void {
		$files = array_merge(
			$this->files( array( 'templates', 'parts' ), 'html' ), // phpcs:ignore WordPress.WP.AlternativeFunctions
			array( $this->dir() . '/functions.php', $this->dir() . '/style.css', $this->dir() . '/theme.json', $this->dir() . '/assets/js/motion.js' )
		);
		foreach ( $files as $file ) {
			$text = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->assertStringNotContainsString( '<script', $text, basename( $file ) . ': sin scripts inline' );
			$this->assertStringNotContainsString( 'wp_add_inline_script', $text, basename( $file ) );
			$this->assertDoesNotMatchRegularExpression( '#https?://(fonts\.googleapis|fonts\.gstatic|cdn\.|unpkg|cdnjs)#i', $text, basename( $file ) . ': sin recursos externos' );
		}
	}

	public function test_motion_script_respects_reduced_motion_and_runs_only_after_that_check(): void {
		$js  = (string) file_get_contents( $this->dir() . '/assets/js/motion.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$pos = strpos( $js, 'prefers-reduced-motion: reduce' );
		$this->assertNotFalse( $pos );
		$this->assertLessThan( strpos( $js, 'gsap.from' ), $pos, 'La comprobación va antes de animar' );
		$this->assertStringContainsString( "'(prefers-reduced-motion: reduce)'", $js );
		$css = (string) file_get_contents( $this->dir() . '/style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $css );
	}

	/**
	 * @dataProvider provide_contexts
	 */
	public function test_gsap_is_allowed_only_on_public_pages( array $context, bool $allowed ): void {
		require_once $this->dir() . '/functions.php';
		$this->assertSame( $allowed, aralia_theme_motion_allowed( $context ) );
	}

	/**
	 * @return array<string,array{array<string,bool>,bool}>
	 */
	public static function provide_contexts(): array {
		return array(
			'página pública'          => array( array(), true ),
			'checkout'                => array( array( 'checkout' => true ), false ),
			'order-pay'               => array( array( 'order_pay' => true ), false ),
			'Gracias (order-received)' => array( array( 'order_received' => true ), false ),
			'mi cuenta'               => array( array( 'account' => true ), false ),
			'panel'                   => array( array( 'admin' => true ), false ),
			'checkout y pública a la vez' => array( array( 'checkout' => true, 'admin' => false ), false ),
		);
	}

	private function reset_assets(): void {
		global $wp_scripts, $wp_styles;
		$wp_scripts = new \WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$wp_styles  = new \WP_Styles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}

	public function test_gsap_is_enqueued_only_when_a_block_uses_ara_reveal_and_never_where_motion_is_blocked(): void {
		global $wp_scripts, $wp_styles;
		$saved = array( $wp_scripts, $wp_styles );
		require_once $this->dir() . '/functions.php';
		try {
			$this->visit( array() );
			$this->reset_assets();
			aralia_theme_maybe_enqueue_motion( '<p class="ara-eyebrow">sin movimiento</p>' );
			$this->assertFalse( wp_script_is( 'aralia-gsap', 'enqueued' ), 'Una página sin .ara-reveal no carga GSAP (R-04: solo donde se usa)' );
			$this->assertSame( '<p>x</p>', aralia_theme_maybe_enqueue_motion( '<p>x</p>' ), 'El filtro no altera el contenido' );

			aralia_theme_maybe_enqueue_motion( '<p class="ara-reveal">con movimiento</p>' );
			foreach ( array( 'aralia-gsap', 'aralia-scrolltrigger', 'aralia-motion' ) as $handle ) {
				$this->assertTrue( wp_script_is( $handle, 'enqueued' ), $handle );
			}
			$this->assertStringContainsString( '/assets/js/gsap.min.js', (string) wp_scripts()->registered['aralia-gsap']->src, 'GSAP auto-alojado' );
			$this->assertSame( 'defer', wp_scripts()->registered['aralia-motion']->extra['strategy'] ?? '', 'Diferido' );

			// Donde WooCommerce dice checkout o Gracias, aunque el bloque pida movimiento.
			foreach ( array( 'woocommerce_is_checkout', 'woocommerce_is_order_received_page' ) as $filter ) {
				$this->reset_assets();
				add_filter( $filter, '__return_true' );
				aralia_theme_maybe_enqueue_motion( '<p class="ara-reveal">x</p>' );
				remove_filter( $filter, '__return_true' );
				$this->assertFalse( wp_script_is( 'aralia-gsap', 'enqueued' ), "Nunca cuando $filter" );
			}
			// order-pay y cuenta, aislados de la rama de checkout (que ya bloquea por sí sola).
			$this->assertFalse( aralia_theme_motion_allowed( array( 'order_pay' => true, 'checkout' => false ) ), 'La rama order_pay bloquea por sí misma' );
			$this->assertFalse( aralia_theme_motion_allowed( array( 'account' => true, 'checkout' => false ) ), 'La rama account bloquea por sí misma' );
			$this->assertFalse( aralia_theme_motion_allowed( array( 'order_received' => true, 'checkout' => false ) ), 'La rama order_received bloquea por sí misma' );
		} finally {
			list( $wp_scripts, $wp_styles ) = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}

	public function test_the_theme_stylesheet_is_enqueued_everywhere_including_checkout(): void {
		global $wp_scripts, $wp_styles;
		$saved = array( $wp_scripts, $wp_styles );
		register_theme_directory( dirname( $this->dir() ) );
		require_once $this->dir() . '/functions.php';
		try {
			foreach ( array( '', 'woocommerce_is_checkout' ) as $filter ) {
				$this->reset_assets();
				if ( $filter ) {
					add_filter( $filter, '__return_true' );
				}
				aralia_theme_enqueue_style();
				if ( $filter ) {
					remove_filter( $filter, '__return_true' );
				}
				$this->assertTrue( wp_style_is( 'aralia-style', 'enqueued' ), 'style.css se carga (un tema de bloques no lo hace solo)' . ( $filter ? ' también en checkout' : '' ) );
			}
		} finally {
			list( $wp_scripts, $wp_styles ) = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}

	public function test_header_and_footer_do_not_nest_landmarks_and_stick_on_the_template_part_wrapper(): void {
		foreach ( array( 'header', 'footer' ) as $part ) {
			$html = (string) file_get_contents( $this->dir() . "/parts/$part.html" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->assertStringNotContainsString( "<$part", $html, "La parte $part no repite el landmark que ya pone la plantilla (tagName $part)" );
			$this->assertStringNotContainsString( '"tagName":"' . $part . '"', $html );
		}
		$theme = $this->json( 'theme.json' );
		$this->assertStringContainsString( '.wp-block-template-part:has(> .ara-header){position:sticky', $theme['styles']['css'], 'El sticky va en el contenedor' );
	}

	public function test_dark_variation_paints_a_legible_header_buttons_in_champagne_and_a_visible_focus_ring(): void {
		$p     = $this->palette();
		$light = $this->json( 'theme.json' )['styles']['css'];
		$dark  = $this->json( 'styles/dark.json' )['styles']['css'];
		$this->assertStringContainsString( '.ara-header{', $light );
		$this->assertStringContainsString( 'background-color:var(--wp--preset--color--cream)', $light );
		$this->assertStringContainsString( 'color:var(--wp--preset--color--plum-deep)', $light, 'Encabezado claro: texto oscuro sobre crema' );
		$this->assertStringContainsString( 'background-color:var(--wp--preset--color--plum-deep)', $dark );
		$this->assertStringContainsString( '.ara-header a{color:var(--wp--preset--color--cream)}', $dark, 'Encabezado oscuro: texto crema sobre ciruela' );
		$this->assertGreaterThanOrEqual( 7.0, self::contrast( $p['cream'], $p['plum-deep'] ) );
		$button = $this->json( 'styles/dark.json' )['styles']['elements']['button'];
		$this->assertSame( 'var(--wp--preset--color--champagne)', $button['color']['background'], '§11: botón principal champagne en oscuro' );
		$this->assertSame( 'var(--wp--preset--color--plum-deep)', $button['color']['text'] );
		$this->assertGreaterThanOrEqual( 4.5, self::contrast( $p['champagne'], $p['plum-deep'] ) );
		$css = (string) file_get_contents( $this->dir() . '/style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertStringContainsString( ':root{--ara-focus:var(--wp--preset--color--rose)}', $dark, 'La variación oscura cambia la variable del foco (no depende del orden de las hojas)' );
		$this->assertStringContainsString( 'outline: 2px solid var(--ara-focus, var(--wp--preset--color--champagne-ink))', $css, 'style.css pinta el foco con la variable y champagne-ink como valor por defecto en claro' );
		$code = (string) preg_replace( '#/\\*.*?\\*/#s', '', $css ); // Sin comentarios: solo el CSS que cuenta.
		$this->assertDoesNotMatchRegularExpression( '/(^|})\\s*:root[^{]*\\{[^}]*--ara-focus\\s*:/', $code, 'style.css NO declara --ara-focus en :root: empataría con dark.json y, al imprimirse después, ganaría (ALTO-1)' );
		$this->assertDoesNotMatchRegularExpression( '/:root[^{]*\\{[^}]*--ara-focus\\s*:/', $this->json( 'theme.json' )['styles']['css'], 'El tema base tampoco' );
		preg_match_all( '/outline\\s*:[^;}]+/', $code, $outlines );
		$this->assertNotEmpty( $outlines[0] );
		foreach ( $outlines[0] as $declaration ) {
			$this->assertStringContainsString( 'var(--ara-focus', $declaration, 'Todo outline pasa por la variable (un color fijo ganaría la cascada sobre la variación oscura): ' . $declaration );
		}
		$this->assertGreaterThanOrEqual( 3.0, self::contrast( $p['rose'], $p['plum-deep'] ), 'WCAG 1.4.11: el foco sobre oscuro llega a 3:1' );
		$this->assertLessThan( 3.0, self::contrast( $p['champagne-ink'], $p['plum-deep'] ), 'Premisa: champagne-ink NO sirve de foco sobre oscuro' );
		$this->assertSame( '999px', $this->json( 'theme.json' )['styles']['elements']['button']['border']['radius'], '§11: botones redondeados' );
		$this->assertStringContainsString( '.has-plum-deep-background-color, .has-plum-background-color { --ara-focus: var(--wp--preset--color--rose); }', $css );
	}

	public function test_block_templates_use_registered_blocks_and_point_to_existing_parts(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		$walk     = function ( array $blocks, string $file ) use ( &$walk, $registry ): void {
			foreach ( $blocks as $block ) {
				$name = (string) ( $block['blockName'] ?? '' );
				if ( '' !== $name ) {
					$this->assertTrue( $registry->is_registered( $name ), "$file: el bloque $name existe" );
					if ( 'core/template-part' === $name ) {
						$slug = (string) ( $block['attrs']['slug'] ?? '' );
						$this->assertFileExists( $this->dir() . "/parts/$slug.html", "$file: la parte $slug existe" );
					}
				}
				$walk( (array) ( $block['innerBlocks'] ?? array() ), $file );
			}
		};
		foreach ( $this->files( array( 'templates', 'parts' ), 'html' ) as $file ) {
			$walk( parse_blocks( (string) file_get_contents( $file ) ), basename( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Simula la visita a una página: deja la consulta principal global como la dejaría WordPress.
	 *
	 * @param array<string,mixed> $args Argumentos de WP_Query.
	 * @return void
	 */
	private function visit( array $args ): void {
		global $wp_query, $wp_the_query;
		$wp_query     = new \WP_Query( $args ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$wp_the_query = $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}

	public function test_every_block_comment_in_the_templates_has_valid_json_attributes(): void {
		$checked = 0;
		foreach ( $this->files( array( 'templates', 'parts' ), 'html' ) as $file ) {
			$html = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			preg_match_all( '/<!--\s+wp:[a-z0-9\/-]+\s+(\{.*?\})\s+\/?-->/s', $html, $m );
			foreach ( $m[1] as $json ) {
				$this->assertIsArray( json_decode( $json, true ), basename( $file ) . ': JSON de bloque inválido (' . substr( $json, 0, 80 ) . '…): el editor lo marcaría como bloque inválido' );
				++$checked;
			}
		}
		$this->assertGreaterThan( 20, $checked, 'Se revisaron los atributos de los bloques' );
	}

	public function test_the_theme_hooks_are_actually_wired(): void {
		$fn = (string) file_get_contents( $this->dir() . '/functions.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertMatchesRegularExpression( "/add_action\(\s*'wp_enqueue_scripts',\s*'aralia_theme_enqueue_style'\s*\)/", $fn, 'Sin esta línea style.css no se carga (el fallo original de H1) y las pruebas que llaman a la función a mano no lo verían' );
		$this->assertMatchesRegularExpression( "/add_filter\(\s*'render_block',\s*'aralia_theme_maybe_enqueue_motion'\s*\)/", $fn, 'Sin esta línea GSAP nunca se encola' );
		$this->assertMatchesRegularExpression( "/add_action\(\s*'after_setup_theme',\s*'aralia_theme_setup'\s*\)/", $fn );
		$this->assertDoesNotMatchRegularExpression( "/add_action\(\s*'wp_enqueue_scripts',\s*'aralia_theme_enqueue_motion'/", $fn, 'El movimiento NO se encola en todas las páginas, solo vía render_block' );
	}
}
