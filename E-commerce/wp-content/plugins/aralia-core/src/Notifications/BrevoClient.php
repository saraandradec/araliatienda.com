<?php
/**
 * Cliente de Brevo para correo y SMS transaccionales (T028, R-13).
 *
 * @package Aralia
 */

namespace Aralia\Notifications;

use Aralia\Privacy\Crypto;
use Aralia\Security\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Envía por la API v3 de Brevo. La llave no se registra ni se devuelve en ningún mensaje. SMS: solo móviles colombianos (+57 3XX) y un tope
 * de 3 por número y día (R-12, contra el abuso de SMS). Un fallo devuelve `false`; quien llama decide qué hacer.
 */
class BrevoClient {

	public const EMAIL_URL         = 'https://api.brevo.com/v3/smtp/email';
	public const SMS_URL           = 'https://api.brevo.com/v3/transactionalSMS/sms';
	public const SMS_PER_DAY       = 3;
	public const SMS_MAX_CHARS     = 320;
	public const ALERT_SMS_PER_DAY = 30; // Tope GLOBAL de SMS de alertas (la administradora no comparte el tope por número de las clientas).

	/**
	 * Llave de API.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Cliente HTTP (url, args) => array|WP_Error.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Limitador de SMS (opcional).
	 *
	 * @var RateLimiter|null
	 */
	private ?RateLimiter $limiter;

	/**
	 * Cripto para la huella del número (opcional con el limitador).
	 *
	 * @var Crypto|null
	 */
	private ?Crypto $crypto;

	/**
	 * Constructor.
	 *
	 * @param string|null      $api_key Llave (por defecto, ARALIA_BREVO_API_KEY).
	 * @param callable|null    $http    Cliente HTTP.
	 * @param RateLimiter|null $limiter Limitador de SMS.
	 * @param Crypto|null      $crypto  Cripto.
	 */
	public function __construct( ?string $api_key = null, ?callable $http = null, ?RateLimiter $limiter = null, ?Crypto $crypto = null ) {
		$this->api_key = $api_key ?? ( defined( 'ARALIA_BREVO_API_KEY' ) ? (string) constant( 'ARALIA_BREVO_API_KEY' ) : '' );
		$this->http    = $http ?? 'wp_remote_post';
		$this->limiter = $limiter;
		$this->crypto  = $crypto;
	}

	/**
	 * Hay llave configurada.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Envía un correo transaccional.
	 *
	 * @param string      $to      Destinatario.
	 * @param string      $subject Asunto.
	 * @param string      $html    Cuerpo HTML.
	 * @param string|null $text    Cuerpo en texto plano.
	 * @return bool
	 */
	public function send_email( string $to, string $subject, string $html, ?string $text = null ): bool {
		if ( ! $this->is_configured() || ! is_email( $to ) || '' === trim( $subject ) ) {
			return false;
		}
		$payload = array(
			'sender'      => array(
				'name'  => 'Aralía Beauty Store',
				// Remitente del dominio verificado (SPF/DKIM/DMARC, T018); admin_email solo como último recurso.
				'email' => (string) apply_filters( 'aralia_mail_from', defined( 'ARALIA_MAIL_FROM' ) ? constant( 'ARALIA_MAIL_FROM' ) : get_option( 'admin_email' ) ),
			),
			'to'          => array( array( 'email' => $to ) ),
			'subject'     => wp_strip_all_tags( $subject ),
			'htmlContent' => $html,
		);
		if ( null !== $text ) {
			$payload['textContent'] = $text;
		}
		return $this->post( self::EMAIL_URL, $payload );
	}

	/**
	 * Envía un SMS transaccional a un móvil colombiano.
	 *
	 * @param string $phone   Celular.
	 * @param string $content Texto (máx. 320 caracteres).
	 * @return bool
	 */
	public function send_sms( string $phone, string $content ): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}
		// Sin limitador y cripto NO se envía: el tope por número es lo que frena el abuso de SMS (el comportamiento por defecto no puede ser "sin tope").
		if ( null === $this->limiter || null === $this->crypto ) {
			return false;
		}
		$normalized = $this->clean_sms( $phone, $content );
		if ( null === $normalized ) {
			return false;
		}
		try {
			$hits = $this->limiter->hit( $this->crypto->phone_hash( $normalized[0] ), 'sms', DAY_IN_SECONDS );
		} catch ( \RuntimeException $e ) {
			unset( $e );
			return false; // Falla cerrado si el limitador no responde.
		}
		if ( $hits > self::SMS_PER_DAY ) {
			return false;
		}
		return $this->post_sms( $normalized[0], $normalized[1] );
	}

	/**
	 * SMS de una ALERTA a la administradora: sin el tope por número de las clientas, con un tope global propio.
	 *
	 * @param string $phone   Celular de la administradora.
	 * @param string $content Texto.
	 * @return bool
	 */
	public function send_alert_sms( string $phone, string $content ): bool {
		if ( ! $this->is_configured() || null === $this->limiter || null === $this->crypto ) {
			return false;
		}
		$clean = $this->clean_sms( $phone, $content );
		if ( null === $clean ) {
			return false;
		}
		try {
			$hits = $this->limiter->hit( $this->crypto->key_hash( 'sms-alert', 'global' ), 'sms_alert', DAY_IN_SECONDS );
		} catch ( \RuntimeException $e ) {
			unset( $e );
			$hits = 0; // Una alerta crítica se intenta enviar aunque el limitador falle: perderla es peor que un exceso.
		}
		if ( $hits > self::ALERT_SMS_PER_DAY ) {
			return false;
		}
		return $this->post_sms( $clean[0], $clean[1] );
	}

	/**
	 * Valida y normaliza destinatario y texto.
	 *
	 * @param string $phone   Celular.
	 * @param string $content Texto.
	 * @return array{0:string,1:string}|null
	 */
	private function clean_sms( string $phone, string $content ): ?array {
		try {
			$normalized = Crypto::normalize_phone( $phone );
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			return null;
		}
		$content = trim( wp_strip_all_tags( $content ) );
		if ( '' === $content || mb_strlen( $content ) > self::SMS_MAX_CHARS ) {
			return null;
		}
		return array( $normalized, $content );
	}

	/**
	 * Envía el SMS ya validado.
	 *
	 * @param string $normalized Celular normalizado (+57...).
	 * @param string $content    Texto.
	 * @return bool
	 */
	private function post_sms( string $normalized, string $content ): bool {
		return $this->post(
			self::SMS_URL,
			array(
				'sender'    => 'Aralia',
				'recipient' => ltrim( $normalized, '+' ),
				'content'   => $content,
				'type'      => 'transactional',
			)
		);
	}

	/**
	 * POST autenticado. Éxito = 2xx.
	 *
	 * @param string              $url     URL.
	 * @param array<string,mixed> $payload Cuerpo.
	 * @return bool
	 */
	private function post( string $url, array $payload ): bool {
		$response = ( $this->http )(
			$url,
			array(
				'timeout' => 8,
				'headers' => array(
					'api-key'      => $this->api_key,
					'accept'       => 'application/json',
					'content-type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			error_log( '[aralia] Brevo: error de red' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- sin llave ni destinatario.
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( '[aralia] Brevo: respuesta ' . $code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
		return true;
	}
}
