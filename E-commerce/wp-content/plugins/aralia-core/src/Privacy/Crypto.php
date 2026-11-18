<?php
/**
 * Cifrado de datos personales y huellas con clave (T030, R-23).
 *
 * @package Aralia
 */

namespace Aralia\Privacy;

defined( 'ABSPATH' ) || exit;

// base64 aquí codifica llaves, nonces y texto cifrado (no ofusca código).
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions

/**
 * - Cifrado: libsodium `secretbox` (XSalsa20-Poly1305) con `ARALIA_PII_KEY`; nonce aleatorio por mensaje; formato `v1:` + base64(nonce . texto cifrado).
 * - Huellas: HMAC-SHA256 con `ARALIA_HMAC_KEY` (llave distinta de la de cifrado) y separación de dominio por tipo (correo, celular, IP),
 *   de modo que la misma cadena no produzca la misma huella en dos usos. Un SHA-256 sin clave se revierte por fuerza bruta (espacio IPv4/celulares pequeño).
 * - Falla cerrado: llaves ausentes, mal formadas o iguales entre sí lanzan excepción; nada se cifra "a medias".
 * Las llaves son base64 de 32 bytes aleatorios (`php -r 'echo base64_encode(random_bytes(32));'`).
 */
final class Crypto {

	public const PREFIX = 'v1:';

	/**
	 * Llave de cifrado.
	 *
	 * @var string
	 */
	private string $enc_key;

	/**
	 * Llave HMAC.
	 *
	 * @var string
	 */
	private string $hmac_key;

	/**
	 * Constructor.
	 *
	 * @param string $pii_key_b64  Llave de cifrado (base64 de 32 bytes).
	 * @param string $hmac_key_b64 Llave de huellas (base64 de 32 bytes, distinta).
	 * @throws \InvalidArgumentException Si alguna llave no es válida.
	 */
	public function __construct( string $pii_key_b64, string $hmac_key_b64 ) {
		$this->enc_key  = self::decode_key( $pii_key_b64, 'ARALIA_PII_KEY' );
		$this->hmac_key = self::decode_key( $hmac_key_b64, 'ARALIA_HMAC_KEY' );
		if ( hash_equals( $this->enc_key, $this->hmac_key ) ) {
			throw new \InvalidArgumentException( 'ARALIA_PII_KEY y ARALIA_HMAC_KEY deben ser distintas.' );
		}
	}

	/**
	 * Construye desde las constantes de aralia-secrets.php.
	 *
	 * @return self
	 * @throws \RuntimeException Si faltan las constantes o son inválidas.
	 */
	public static function from_constants(): self {
		if ( ! defined( 'ARALIA_PII_KEY' ) || ! defined( 'ARALIA_HMAC_KEY' ) ) {
			throw new \RuntimeException( 'Faltan ARALIA_PII_KEY o ARALIA_HMAC_KEY.' );
		}
		try {
			return new self( (string) constant( 'ARALIA_PII_KEY' ), (string) constant( 'ARALIA_HMAC_KEY' ) );
		} catch ( \InvalidArgumentException $e ) {
			throw new \RuntimeException( 'Las llaves de privacidad no son válidas.', 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Decodifica una llave sin revelar su valor en los mensajes de error.
	 *
	 * @param string $value Base64.
	 * @param string $name  Nombre de la constante (solo para el mensaje).
	 * @return string
	 */
	private static function decode_key( string $value, string $name ): string {
		$raw = base64_decode( $value, true );
		if ( false === $raw || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $raw ) ) {
			throw new \InvalidArgumentException( $name . ' debe ser base64 de exactamente 32 bytes.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- nombre fijo.
		}
		return $raw;
	}

	/**
	 * Cifra un texto.
	 *
	 * @param string $plain Texto.
	 * @return string
	 */
	public function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->enc_key ) );
	}

	/**
	 * Descifra. No distingue la causa del fallo (formato, llave o manipulación) para no ayudar a un atacante.
	 *
	 * @param string $encoded Valor cifrado.
	 * @return string
	 * @throws \RuntimeException Si no se puede descifrar.
	 */
	public function decrypt( string $encoded ): string {
		$fail = new \RuntimeException( 'No se pudo descifrar.' );
		if ( 0 !== strpos( $encoded, self::PREFIX ) ) {
			throw $fail;
		}
		$raw = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			throw $fail;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->enc_key );
		if ( false === $plain ) {
			throw $fail;
		}
		return $plain;
	}

	/**
	 * Huella con separación de dominio.
	 *
	 * @param string $kind  Dominio (email, phone, ip, ip-key...).
	 * @param string $value Valor ya normalizado.
	 * @return string 64 caracteres hexadecimales.
	 */
	private function mac( string $kind, string $value ): string {
		return hash_hmac( 'sha256', $kind . "\0" . $value, $this->hmac_key );
	}

	/**
	 * Huella de un correo.
	 *
	 * @param string $email Correo.
	 * @return string
	 */
	public function email_hash( string $email ): string {
		return $this->mac( 'email', self::normalize_email( $email ) );
	}

	/**
	 * Huella de un celular colombiano.
	 *
	 * @param string $phone Celular.
	 * @return string
	 */
	public function phone_hash( string $phone ): string {
		return $this->mac( 'phone', self::normalize_phone( $phone ) );
	}

	/**
	 * Huella de una IP para ALMACENAR (consentimientos, 5 años): la IP se trunca (IPv4 /24, IPv6 /48) antes del HMAC.
	 *
	 * @param string $ip IP.
	 * @return string
	 */
	public function ip_hash( string $ip ): string {
		return $this->mac( 'ip', self::truncate_ip( $ip ) );
	}

	/**
	 * Huella de una IP completa para límites efímeros (rate limits): no se almacena a largo plazo.
	 *
	 * @param string $ip IP.
	 * @return string
	 */
	public function ip_key( string $ip ): string {
		$packed = self::unmapped_packed_ip( $ip );
		// IPv6 a /64: una persona con un /64 tiene 2^64 direcciones y no debe poder rotarlas para esquivar los límites.
		$key = 16 === strlen( $packed ) ? substr( $packed, 0, 8 ) : $packed;
		return $this->mac( 'ip-key', bin2hex( $key ) );
	}

	/**
	 * Huella de un correo para LÍMITES: además de normalizar, ignora los alias (`+etiqueta` y, en Gmail, los puntos), que de otro modo
	 * permitirían infinitos "correos distintos" que son el mismo buzón. NO se usa para consentimientos (ahí la huella es del correo tal cual).
	 *
	 * @param string $email Correo.
	 * @return string
	 */
	public function email_limit_hash( string $email ): string {
		[ $local, $domain ] = explode( '@', self::normalize_email( $email ) );
		$local              = explode( '+', $local )[0];
		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}
		return $this->mac( 'email-limit', $local . '@' . $domain );
	}

	/**
	 * Huella de una clave de límite arbitraria (sesión, referencia...).
	 *
	 * @param string $kind  Dominio.
	 * @param string $value Valor.
	 * @return string
	 */
	public function key_hash( string $kind, string $value ): string {
		return $this->mac( 'key:' . $kind, $value );
	}

	/**
	 * Correo normalizado: sin espacios, en minúsculas y con una sola arroba.
	 *
	 * @param string $email Correo.
	 * @return string
	 * @throws \InvalidArgumentException Si no parece un correo.
	 */
	public static function normalize_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( 1 !== substr_count( $email, '@' ) || strlen( $email ) > 254 || preg_match( '/\s/', $email ) ) {
			throw new \InvalidArgumentException( 'Correo inválido.' );
		}
		[ $local, $domain ] = explode( '@', $email );
		if ( '' === $local || '' === $domain || false === strpos( $domain, '.' ) ) {
			throw new \InvalidArgumentException( 'Correo inválido.' );
		}
		return $email;
	}

	/**
	 * Celular colombiano normalizado a +57 3XXXXXXXXX (solo móviles, R-12).
	 *
	 * @param string $phone Celular con o sin +57, espacios o guiones.
	 * @return string
	 * @throws \InvalidArgumentException Si no es un móvil colombiano.
	 */
	public static function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/[\s\-().]/', '', trim( $phone ) );
		$digits = ltrim( (string) $digits, '+' );
		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '57' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( ! preg_match( '/^3\d{9}$/', $digits ) ) {
			throw new \InvalidArgumentException( 'Celular inválido: solo móviles colombianos (+57 3XX).' );
		}
		return '+57' . $digits;
	}

	/**
	 * IP en binario, desmapeando IPv4 dentro de IPv6 (::ffff:a.b.c.d => a.b.c.d).
	 *
	 * @param string $ip IP.
	 * @return string 4 o 16 bytes.
	 * @throws \InvalidArgumentException Si no es una IP.
	 */
	private static function unmapped_packed_ip( string $ip ): string {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- avisa con IP inválida; se valida abajo.
		if ( false === $packed ) {
			throw new \InvalidArgumentException( 'IP inválida.' );
		}
		if ( 16 === strlen( $packed ) && "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( $packed, 0, 12 ) ) {
			return substr( $packed, 12 );
		}
		return $packed;
	}

	/**
	 * IP truncada: IPv4 a /24 y IPv6 a /48 (las IPv4 mapeadas se tratan como IPv4).
	 *
	 * @param string $ip IP.
	 * @return string
	 * @throws \InvalidArgumentException Si no es una IP.
	 */
	public static function truncate_ip( string $ip ): string {
		$packed = self::unmapped_packed_ip( $ip );
		$keep   = 4 === strlen( $packed ) ? 3 : 6;
		$masked = substr( $packed, 0, $keep ) . str_repeat( "\0", strlen( $packed ) - $keep );
		return bin2hex( $masked );
	}
}
