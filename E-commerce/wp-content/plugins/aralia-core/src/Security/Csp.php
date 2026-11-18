<?php
/**
 * Content-Security-Policy por tipo de página (T033, R-11).
 *
 * @package Aralia
 */

namespace Aralia\Security;

defined( 'ABSPATH' ) || exit;

// base64 codifica el nonce y los hashes de la CSP (no ofusca código).
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions

/**
 * Cuatro políticas distintas:
 * - `checkout`: solo Turnstile como tercero; nonce + `strict-dynamic`.
 * - `order-pay`: solo Wompi; nonce + `strict-dynamic`.
 * - `thankyou`: ningún tercero; nonce.
 * - `public` (cacheable): lista de hosts con RUTA + hashes SHA-256 de los scripts inline que WordPress imprime por su API
 *   (`wp_add_inline_script`, `wp_print_inline_script_tag`). NO se hashea el HTML final: un `<script>` inyectado en el cuerpo
 *   (XSS reflejado o almacenado) no está en esa lista y el navegador lo bloquea.
 * Sin 'unsafe-eval' ni 'unsafe-inline' en script-src. Modo: `off` | `report-only` (por defecto) | `enforce`.
 * El nonce solo se pone a los scripts de una lista blanca de handles: un plugin que encole otro script en el checkout NO queda autorizado.
 */
class Csp {

	public const OPTION_MODE   = 'aralia_csp_mode';
	public const OPTION_REPORT = 'aralia_csp_report_uri';
	public const MAX_HASHES    = 60; // Una página de WooCommerce en es_CO imprime decenas de scripts de traducciones; 60 deja margen.
	public const WARN_HASHES   = 25;

	/**
	 * Nonce de la petición actual (solo páginas de pago).
	 *
	 * @var string
	 */
	private static string $nonce = '';

	/**
	 * Hashes de los scripts inline impresos por la API de WordPress en esta petición.
	 *
	 * @var string[]
	 */
	private static array $inline_hashes = array();

	/**
	 * Si se están recogiendo hashes (página pública).
	 *
	 * @var bool
	 */
	private static bool $collecting = false;

	/**
	 * Función que envía una cabecera (solo se reemplaza en pruebas).
	 *
	 * @var callable|null
	 */
	private static $header_sender = null;

	/**
	 * Función que dice si ya se enviaron cabeceras (solo se reemplaza en pruebas).
	 *
	 * @var callable|null
	 */
	private static $headers_sent_checker = null;

	/**
	 * Sustituye el envío de cabeceras y la comprobación de `headers_sent()`. SOLO PARA PRUEBAS: en CLI las cabeceras no se pueden leer y
	 * `headers_sent()` ya es verdadero por la salida de PHPUnit.
	 *
	 * @param callable|null $sender  fn(string $linea): void.
	 * @param callable|null $checker fn(): bool.
	 * @return void
	 */
	public static function use_io( ?callable $sender, ?callable $checker ): void {
		// Silenciar la CSP desde código de producción sería un bypass: el seam solo existe en local/test.
		if ( ! \Aralia\Support\Environment::allows_test_tools() ) {
			return;
		}
		self::$header_sender        = $sender;
		self::$headers_sent_checker = $checker;
	}

	private const WOMPI     = array( 'https://checkout.wompi.co', 'https://sandbox.wompi.co', 'https://production.wompi.co' );
	private const TURNSTILE = array( 'https://challenges.cloudflare.com' );

	/**
	 * Hosts de terceros en páginas públicas, con RUTA (GA4 y Meta Pixel, con consentimiento). Una ruta evita `gtm.js?id=` de contenedores ajenos.
	 * Ampliable por filtro tras el inventario de R-11.
	 *
	 * @return string[]
	 */
	public static function public_script_sources(): array {
		return (array) apply_filters(
			'aralia_csp_script_hosts',
			array(
				'https://www.googletagmanager.com/gtag/js',
				'https://connect.facebook.net/en_US/fbevents.js',
			)
		);
	}

	/**
	 * Patrones de handles de script que reciben nonce en las páginas de pago.
	 *
	 * @return string[]
	 */
	public static function nonce_handle_patterns(): array {
		return (array) apply_filters(
			'aralia_csp_nonce_handles',
			array( '/^aralia-(?!gsap|scrolltrigger|motion)/', '/^wc-/', '/^woocommerce/', '/^wp-/', '/^@wordpress\//', '/^@woocommerce\//', '/^react/', '/^jquery/', '/^lodash$/', '/^moment$/', '/^wompi/', '/^turnstile/', '/^sourcebuster/', '/^js-cookie$/' )
		);
	}

	/**
	 * Modo vigente.
	 *
	 * @return string off | report-only | enforce.
	 */
	public static function mode(): string {
		$mode = (string) get_option( self::OPTION_MODE, 'report-only' );
		return in_array( $mode, array( 'off', 'report-only', 'enforce' ), true ) ? $mode : 'report-only';
	}

	/**
	 * Tipo de página.
	 *
	 * @return string checkout | order-pay | thankyou | public | skip.
	 */
	public static function page_type(): string {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_robots() ) {
			return 'skip';
		}
		if ( function_exists( 'is_wc_endpoint_url' ) ) {
			if ( is_wc_endpoint_url( 'order-pay' ) ) {
				return 'order-pay';
			}
			if ( is_wc_endpoint_url( 'order-received' ) ) {
				return 'thankyou';
			}
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}
		return 'public';
	}

	/**
	 * ¿Es una página de pago (nunca se cachea, lleva nonce)?
	 *
	 * @param string $type Tipo.
	 * @return bool
	 */
	public static function is_payment_type( string $type ): bool {
		return in_array( $type, array( 'checkout', 'order-pay', 'thankyou' ), true );
	}

	/**
	 * Arma la política.
	 *
	 * @param string   $type   checkout | order-pay | thankyou | public.
	 * @param string   $nonce  Nonce (páginas de pago).
	 * @param string[] $hashes Hashes `'sha256-...'` de los scripts inline (páginas públicas).
	 * @return string
	 * @throws \InvalidArgumentException Si el tipo o el nonce no son válidos.
	 */
	public static function policy( string $type, string $nonce = '', array $hashes = array() ): string {
		$wompi = implode( ' ', self::WOMPI );
		$ts    = implode( ' ', self::TURNSTILE );

		if ( self::is_payment_type( $type ) ) {
			if ( ! preg_match( '/^[A-Za-z0-9_-]{16,}$/', $nonce ) ) {
				throw new \InvalidArgumentException( 'Nonce inválido.' );
			}
			$third      = array(
				'checkout'  => array(
					'scripts' => $ts,
					'frames'  => $ts,
					'connect' => $ts,
					'img'     => '',
					'form'    => '',
				),
				'order-pay' => array(
					'scripts' => $wompi,
					'frames'  => $wompi,
					'connect' => $wompi,
					'img'     => $wompi,
					'form'    => $wompi,
				),
				'thankyou'  => array(
					'scripts' => '',
					'frames'  => '',
					'connect' => '',
					'img'     => '',
					'form'    => '',
				),
			)[ $type ];
			$directives = array(
				"default-src 'self'",
				// `strict-dynamic` hace que los scripts con nonce confíen en todo lo que ellos inserten (un sumidero .html() lo aprovecharía):
				// solo se usa donde hay un tercero que carga más scripts (Turnstile en checkout, el widget de Wompi en order-pay).
				trim( "script-src 'self' 'nonce-{$nonce}'" . ( 'thankyou' === $type ? '' : " 'strict-dynamic'" ) . " {$third['scripts']}" ),
				"style-src 'self' 'unsafe-inline'",
				trim( "frame-src {$third['frames']}" ) === 'frame-src' ? "frame-src 'none'" : trim( "frame-src {$third['frames']}" ),
				trim( "connect-src 'self' {$third['connect']}" ),
				trim( "img-src 'self' data: {$third['img']}" ),
				"font-src 'self' data:",
				trim( "form-action 'self' {$third['form']}" ),
			);
		} elseif ( 'public' === $type ) {
			$sources    = implode( ' ', array_filter( array_map( 'esc_url_raw', self::public_script_sources() ) ) );
			$valid_hash = array_values( array_unique( array_filter( $hashes, static fn( $h ): bool => (bool) preg_match( "/^'sha256-[A-Za-z0-9+\/]{43}='$/", (string) $h ) ) ) );
			// Un tope de hashes evita que una página con muchísimos scripts infle la cabecera (los servidores cortan en ~8-16 KB).
			if ( count( $valid_hash ) > self::MAX_HASHES ) {
				error_log( '[aralia] CSP pública: más de ' . self::MAX_HASHES . ' scripts inline; los sobrantes quedan sin hash (se bloquearían en enforce)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			$valid_hash = array_slice( $valid_hash, 0, self::MAX_HASHES );
			$inline     = $valid_hash ? ' ' . implode( ' ', $valid_hash ) : '';
			$directives = array(
				"default-src 'self'",
				trim( "script-src 'self' {$sources} {$ts}{$inline}" ),
				"style-src 'self' 'unsafe-inline'",
				"connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com https://www.facebook.com {$ts}",
				"img-src 'self' data: https://www.google-analytics.com https://www.facebook.com",
				"font-src 'self' data:",
				// Turnstile se carga en un iframe en los formularios públicos (avisos de stock y lista anticipada).
				"frame-src {$ts}",
				"form-action 'self'",
			);
		} else {
			throw new \InvalidArgumentException( 'Tipo de página inválido.' );
		}
		$directives[] = "object-src 'none'";
		$directives[] = "base-uri 'self'";
		$directives[] = "frame-ancestors 'self'";
		$report       = (string) get_option( self::OPTION_REPORT, '' );
		// Solo https y sin `;` ni espacios: no se pueden añadir directivas por esta vía.
		if ( '' !== $report && preg_match( '#^https://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/%-]*)?$#', $report ) ) {
			$directives[] = 'report-uri ' . $report;
		}
		return implode( '; ', $directives );
	}

	/**
	 * Hash CSP de un fragmento exacto de script.
	 *
	 * @param string $content Contenido tal cual se imprime entre las etiquetas.
	 * @return string `'sha256-BASE64'`.
	 */
	public static function hash_of( string $content ): string {
		return "'sha256-" . base64_encode( hash( 'sha256', $content, true ) ) . "'";
	}

	/**
	 * Hashes SHA-256 de los scripts inline ejecutables de un HTML. SOLO para el monitor y las pruebas (comparar lo que hay en el HTML con
	 * lo registrado); la política pública NO se arma con esto.
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	public static function inline_script_hashes( string $html ): array {
		$hashes = array();
		if ( ! preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}
		foreach ( $matches as $m ) {
			if ( preg_match( '/\bsrc\s*=/i', $m[1] ) ) {
				continue;
			}
			if ( preg_match( '/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $m[1], $t ) && ! preg_match( '#^(text|application)/(javascript|ecmascript)$|^module$#i', $t[1] ) ) {
				continue;
			}
			if ( '' === trim( $m[2] ) ) {
				continue;
			}
			$hashes[] = self::hash_of( $m[2] );
		}
		return array_values( array_unique( $hashes ) );
	}

	/**
	 * Nombre de la cabecera según el modo.
	 *
	 * @param string $mode Modo.
	 * @return string
	 */
	public static function header_name( string $mode ): string {
		return 'enforce' === $mode ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
	}

	/**
	 * Engancha la CSP.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'start' ), 0 );
		add_filter( 'wp_script_attributes', array( self::class, 'add_nonce_attribute' ) );
		add_filter( 'wp_inline_script_attributes', array( self::class, 'on_inline_script' ), 10, 2 );
	}

	/**
	 * Un handle (`wc-blocks-checkout-js`, `aralia-widget-js-after`...) puede recibir nonce.
	 *
	 * @param string $id Atributo id del script.
	 * @return bool
	 */
	public static function handle_may_have_nonce( string $id ): bool {
		$handle = preg_replace( '/-js(-module)?(-extra|-before|-after|-translations)?$/', '', $id );
		foreach ( self::nonce_handle_patterns() as $pattern ) {
			if ( 1 === preg_match( (string) $pattern, (string) $handle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * El `src` de un script es de este mismo sitio o de un tercero que la CSP ya permite (Wompi, Turnstile). Un script con un handle
	 * «wc-…» servido desde otro dominio NO recibe nonce (con strict-dynamic cargaría cualquier cosa).
	 *
	 * @param string $src URL del script.
	 * @return bool
	 */
	public static function src_is_trusted( string $src ): bool {
		// Cualquier carácter de control, espacio, barra invertida o arroba se rechaza: los navegadores los interpretan distinto que PHP
		// (`/\evil.example` se resuelve como `//evil.example`; `https://evil\@checkout.wompi.co` tiene otro host real).
		if ( '' === $src || preg_match( '/[\x00-\x20\x7f\\\\@]/', $src ) ) {
			return false;
		}
		// Ruta relativa al propio sitio: `/x`, nunca `//` (otro origen).
		if ( '/' === $src[0] ) {
			return ! isset( $src[1] ) || ( '/' !== $src[1] );
		}
		$parts = wp_parse_url( $src );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host ) {
			return false;
		}
		$own    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		// El propio sitio por http solo en local/test (wp-env sirve http); en cualquier otro entorno, todo es https.
		if ( $host === $own && 'http' === $scheme && \Aralia\Support\Environment::allows_test_tools() ) {
			return true;
		}
		if ( 'https' !== $scheme || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return false;
		}
		if ( $host === $own ) {
			return true;
		}
		foreach ( array_merge( self::WOMPI, self::TURNSTILE ) as $allowed ) {
			if ( strtolower( (string) wp_parse_url( $allowed, PHP_URL_HOST ) ) === $host ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Añade el nonce a los scripts de las páginas de pago, solo a los de la lista blanca.
	 *
	 * @param array<string,mixed> $attributes Atributos del script.
	 * @return array<string,mixed>
	 */
	public static function add_nonce_attribute( $attributes ) {
		if ( '' === self::$nonce || ! is_array( $attributes ) ) {
			return $attributes;
		}
		$id = isset( $attributes['id'] ) ? (string) $attributes['id'] : '';
		if ( '' !== $id && self::handle_may_have_nonce( $id ) && ( empty( $attributes['src'] ) || self::src_is_trusted( (string) $attributes['src'] ) ) ) {
			$attributes['nonce'] = self::$nonce;
		}
		return $attributes;
	}

	/**
	 * Script inline impreso por la API de WordPress: en páginas de pago recibe nonce (si su handle está en la lista blanca) y en
	 * páginas públicas se registra su hash.
	 *
	 * @param array<string,mixed> $attributes Atributos.
	 * @param string              $data       Contenido exacto que se imprimirá.
	 * @return array<string,mixed>
	 */
	public static function on_inline_script( $attributes, $data = '' ) {
		if ( self::$collecting && is_string( $data ) && '' !== trim( $data ) && count( self::$inline_hashes ) < self::MAX_HASHES * 2 ) {
			$type = is_array( $attributes ) && isset( $attributes['type'] ) ? (string) $attributes['type'] : '';
			if ( '' === $type || preg_match( '#^(text|application)/(javascript|ecmascript)$|^module$#i', $type ) ) {
				self::$inline_hashes[] = self::hash_of( $data );
			}
		}
		return self::add_nonce_attribute( $attributes );
	}

	/**
	 * Hashes recogidos en esta petición (para pruebas).
	 *
	 * @return string[]
	 */
	public static function collected_hashes(): array {
		return array_values( array_unique( self::$inline_hashes ) );
	}

	/**
	 * Estado limpio (para pruebas).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$nonce                = '';
		self::$inline_hashes        = array();
		self::$collecting           = false;
		self::$header_sender        = null;
		self::$headers_sent_checker = null;
	}

	/**
	 * Inicio de la petición: envía la CSP de pago ya, o abre el buffer para enviar la pública con los hashes que se hayan imprimido.
	 *
	 * @return void
	 */
	public static function start(): void {
		$mode = self::mode();
		$type = self::page_type();
		if ( 'off' === $mode || 'skip' === $type || self::headers_already_sent() ) {
			return;
		}
		if ( self::is_payment_type( $type ) ) {
			self::$nonce = rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' );
			self::send( $mode, self::policy( $type, self::$nonce ) );
			return;
		}
		self::$collecting = true;
		ob_start(
			static function ( $buffer ) use ( $mode ) {
				if ( self::headers_already_sent() ) {
					// Falla abierta si algo ya envió cabeceras: queda constancia.
					error_log( '[aralia] CSP pública no enviada: las cabeceras ya habían salido' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				} else {
					self::send( $mode, self::policy( 'public', '', self::collected_hashes() ) );
				}
				return $buffer;
			}
		);
	}

	/**
	 * Envía la cabecera de la política.
	 *
	 * @param string $mode   Modo.
	 * @param string $policy Política.
	 * @return void
	 */
	private static function send( string $mode, string $policy ): void {
		$line = self::header_name( $mode ) . ': ' . $policy;
		if ( null !== self::$header_sender ) {
			( self::$header_sender )( $line );
			return;
		}
		header( $line, false );
	}

	/**
	 * Ya se enviaron cabeceras.
	 *
	 * @return bool
	 */
	private static function headers_already_sent(): bool {
		return null !== self::$headers_sent_checker ? (bool) ( self::$headers_sent_checker )() : headers_sent();
	}
}
