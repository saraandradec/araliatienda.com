<?php
/**
 * Matriz de alertas (T029, R-22).
 *
 * @package Aralia
 */

namespace Aralia\Security;

use Aralia\Notifications\BrevoClient;
use Aralia\Privacy\Crypto;
use Aralia\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Cada evento tiene severidad (crítico = correo + SMS; aviso = solo correo) y, si aplica, un umbral por hora: la alerta salta UNA vez al
 * superarlo en la ventana. Los mensajes no llevan datos personales (solo la referencia: id de pedido, etc.).
 */
class Alerts {

	public const CRITICAL = 'critical';
	public const NOTICE   = 'notice';

	/**
	 * Matriz: evento => [severidad, umbral (null = cada vez), ventana en segundos, descripción].
	 *
	 * @return array<string,array{0:string,1:int|null,2:int,3:string}>
	 */
	public static function matrix(): array {
		return array(
			'nostock'             => array( self::CRITICAL, null, 0, 'Pedido pagado con productos sin stock (ara-nostock)' ),
			'blocked_transition'  => array( self::CRITICAL, 0, HOUR_IN_SECONDS, 'Transición de estado de pedido bloqueada' ),
			'checksum_invalid'    => array( self::CRITICAL, 5, HOUR_IN_SECONDS, 'Más de 5 eventos de Wompi con checksum inválido en una hora' ),
			'payment_declines'    => array( self::CRITICAL, 20, HOUR_IN_SECONDS, 'Más de 20 pagos rechazados en una hora' ),
			'deferred_unresolved' => array( self::CRITICAL, null, 0, 'Evento de pago diferido sin resolver por más de 15 minutos' ),
			'expiry_late'         => array( self::CRITICAL, 0, HOUR_IN_SECONDS, 'El vencimiento de pedidos (aralia_order_expiry) lleva más de 5 minutos atrasado' ),
			'script_critical'     => array( self::CRITICAL, null, 0, 'Nuevo <script src>, cambio de widget.js o de las cabeceras de seguridad' ),
			'script_inline'       => array( self::NOTICE, null, 0, 'Cambio en un script inline normalizado (revisar en 48 h)' ),
			'admin_role_change'   => array( self::CRITICAL, null, 0, 'Administrador creado, degradado o borrado, o cambio de rol, correo o contraseña de un administrador' ),
			'new_device_login'    => array( self::NOTICE, null, 0, 'Inicio de sesión en el panel desde un dispositivo nuevo' ),
			'vulnerability'       => array( self::CRITICAL, null, 0, 'Vulnerabilidad crítica o alta en un componente' ),
			'backup_failed'       => array( self::NOTICE, null, 0, 'La copia de seguridad falló' ),
			'low_stock'           => array( self::NOTICE, null, 0, 'Stock bajo (resumen diario)' ),
		);
	}

	/**
	 * Limitador (para los umbrales).
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Cripto (opcional: solo da la huella de los umbrales; sin ella se usa una sal de WordPress).
	 *
	 * @var Crypto|null
	 */
	private ?Crypto $crypto;

	/**
	 * Cliente de Brevo.
	 *
	 * @var BrevoClient
	 */
	private BrevoClient $brevo;

	/**
	 * Encolador asíncrono: fn(evento, severidad, ref): mixed (0/false si no se pudo encolar). Por defecto Action Scheduler.
	 *
	 * @var callable|null
	 */
	private $enqueuer;

	/**
	 * Constructor.
	 *
	 * @param RateLimiter $limiter Limitador.
	 * @param Crypto|null $crypto  Cripto.
	 * @param BrevoClient $brevo   Cliente de Brevo.
	 * @param callable|null $enqueuer Encolador (por defecto, Action Scheduler).
	 */
	public function __construct( RateLimiter $limiter, ?Crypto $crypto, BrevoClient $brevo, ?callable $enqueuer = null ) {
		$this->enqueuer = $enqueuer;
		$this->limiter  = $limiter;
		$this->crypto   = $crypto;
		$this->brevo    = $brevo;
	}

	/**
	 * Engancha la entrega asíncrona.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'aralia_deliver_alert', array( $this, 'on_deliver_action' ), 10, 3 );
	}

	/**
	 * Levanta una alerta. Devuelve verdadero si salta (no si está por debajo del umbral).
	 *
	 * @param string $event Evento de la matriz.
	 * @param string $ref   Referencia sin datos personales (p. ej. "pedido 123").
	 * @param bool   $sync  Entregar ahora (por defecto se encola en Action Scheduler si existe).
	 * @return bool
	 * @throws \InvalidArgumentException Si el evento no existe.
	 */
	public function raise( string $event, string $ref = '', bool $sync = false ): bool {
		$matrix = self::matrix();
		if ( ! isset( $matrix[ $event ] ) ) {
			throw new \InvalidArgumentException( 'Evento de alerta desconocido.' );
		}
		[ $severity, $threshold, $window, $label ] = $matrix[ $event ];
		if ( null !== $threshold ) {
			try {
				$key   = null !== $this->crypto ? $this->crypto->key_hash( 'alert', $event ) : hash_hmac( 'sha256', 'alert|' . $event, wp_salt( 'auth' ) );
				$count = $this->limiter->hit( $key, 'alert:' . substr( $event, 0, 30 ), $window );
			} catch ( RateLimitUnavailable $e ) {
				unset( $e );
				$count = $threshold + 1; // Si el contador no responde, la alerta salta: perderla es peor que repetirla.
			}
			// Salta UNA vez por ventana: al cruzar el umbral.
			if ( $count !== $threshold + 1 ) {
				return false;
			}
		}
		$ref    = substr( preg_replace( '/[^A-Za-z0-9 _.:#-]/', '', $ref ), 0, 80 );
		$queued = false;
		if ( ! $sync ) {
			$enqueue = $this->enqueuer ?? ( function_exists( 'as_enqueue_async_action' ) ? 'as_enqueue_async_action' : null );
			$queued  = null !== $enqueue && (bool) $enqueue( 'aralia_deliver_alert', array( $event, $severity, $ref ), 'aralia' );
		}
		if ( ! $queued ) {
			// Sin cola (o con la cola caída) se entrega ya: una alerta no puede perderse porque Action Scheduler no esté disponible.
			$this->deliver( $event, $severity, $ref );
		}
		return true;
	}

	/**
	 * Callback de la acción asíncrona (no devuelve nada).
	 *
	 * @param string $event    Evento.
	 * @param string $severity Severidad.
	 * @param string $ref      Referencia.
	 * @return void
	 */
	public function on_deliver_action( $event, $severity, $ref = '' ): void {
		if ( ! $this->deliver( $event, $severity, $ref ) ) {
			// Lanzar hace que Action Scheduler la marque como FALLIDA (visible en su pantalla), no como completada.
			throw new \RuntimeException( 'Alerta no entregada: ' . esc_html( (string) $event ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Entrega la alerta: correo siempre; SMS si es crítica.
	 *
	 * @param string $event    Evento.
	 * @param string $severity Severidad.
	 * @param string $ref      Referencia.
	 * @return bool Entregada por al menos un canal.
	 */
	public function deliver( $event, $severity, $ref = '' ): bool {
		$matrix = self::matrix();
		if ( ! isset( $matrix[ $event ] ) ) {
			return false;
		}
		// La severidad sale de la matriz, no de lo que venga en la cola de Action Scheduler.
		$severity = $matrix[ $event ][0];
		$label    = $matrix[ $event ][3];
		$subject  = ( self::CRITICAL === $severity ? '[CRÍTICO] ' : '[Aviso] ' ) . 'Aralía: ' . $label;
		$body     = $label . ( '' !== $ref ? ' — ' . $ref : '' ) . '. Hora (UTC): ' . gmdate( 'Y-m-d H:i' ) . '.';

		[ $email, $phone ] = self::destinations();
		$sent              = false;
		if ( '' !== $email ) {
			$sent = $this->brevo->send_email( $email, $subject, '<p>' . esc_html( $body ) . '</p>', $body );
			if ( ! $sent ) {
				// Respaldo si Brevo no está configurado o cae: una alerta no puede perderse en silencio.
				$sent = (bool) wp_mail( $email, $subject, $body );
			}
		} else {
			error_log( '[aralia] alerta sin correo de destino configurado: ' . $event ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		if ( self::CRITICAL === $severity ) {
			if ( '' === $phone ) {
				error_log( '[aralia] alerta crítica sin celular de destino configurado: ' . $event ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				$sent = $this->brevo->send_alert_sms( $phone, 'Aralía: ' . $label . ( '' !== $ref ? ' (' . $ref . ')' : '' ) ) || $sent;
			}
		}
		if ( ! $sent ) {
			error_log( '[aralia] alerta no entregada: ' . $event ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		( new AuditLog( $this->crypto ) )->record(
			'alert_raised',
			'alert',
			0,
			null,
			array(
				'evento'    => $event,
				'severidad' => $severity,
				'entregada' => $sent,
			),
			0
		);
		return $sent;
	}

	/**
	 * Destinos de las alertas. En entornos estrictos SOLO salen de las constantes de aralia-secrets.php: una opción editable desde
	 * wp-admin permitiría a quien robe una sesión desviar las alertas.
	 *
	 * @param string|null $env Entorno (por defecto, el actual).
	 * @return array{0:string,1:string} correo, celular.
	 */
	public static function destinations( ?string $env = null ): array {
		$email = defined( 'ARALIA_ALERT_EMAIL' ) ? (string) constant( 'ARALIA_ALERT_EMAIL' ) : '';
		$phone = defined( 'ARALIA_ALERT_PHONE' ) ? (string) constant( 'ARALIA_ALERT_PHONE' ) : '';
		if ( Environment::allows_test_tools( $env ) ) {
			$email = '' !== $email ? $email : (string) get_option( 'aralia_alert_email', get_option( 'admin_email' ) );
			$phone = '' !== $phone ? $phone : (string) get_option( 'aralia_alert_phone', '' );
		}
		return array( $email, $phone );
	}
}
