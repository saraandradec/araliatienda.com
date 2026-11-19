<?php
/**
 * Plantilla de secretos de Aralía (research R-16).
 *
 * - Producción y staging: copiar como `aralia-secrets.php` FUERA de `public_html`, permisos 600,
 *   e incluirlo desde wp-config.php:  define( 'ARALIA_SECRETS_PATH', '/home/<usuario>/aralia-secrets.php' );
 * - Local (wp-env): copiar a `.wp-env-private/aralia-secrets.php` (ignorado por git) solo con valores de Sandbox.
 * - Nunca subir este archivo con valores reales al repositorio. Copia de los valores en el gestor de contraseñas de Sara.
 *
 * Clasificación: todo lo marcado R es Restringido (data-classification.md).
 */

defined( 'ABSPATH' ) || exit;

// Entorno de Wompi activo: 'test' (Sandbox) o 'prod'.
define( 'ARALIA_WOMPI_MODE', 'test' );

// Wompi — Sandbox.
define( 'ARALIA_WOMPI_PUB_TEST', 'pub_test_xxxxxxxxxxxxxxxx' );             // P
define( 'ARALIA_WOMPI_PRV_TEST', 'prv_test_xxxxxxxxxxxxxxxx' );             // R
define( 'ARALIA_WOMPI_EVENTS_TEST', 'test_events_xxxxxxxxxxxxxxxx' );       // R
define( 'ARALIA_WOMPI_INTEGRITY_TEST', 'test_integrity_xxxxxxxxxxxxxxxx' ); // R

// Wompi — Producción (solo en el servidor de producción).
define( 'ARALIA_WOMPI_PUB_PROD', '' );       // P
define( 'ARALIA_WOMPI_PRV_PROD', '' );       // R
define( 'ARALIA_WOMPI_EVENTS_PROD', '' );    // R
define( 'ARALIA_WOMPI_INTEGRITY_PROD', '' ); // R

// Brevo (correo transaccional, SMS, lista).
define( 'ARALIA_BREVO_API_KEY', '' ); // R

// Cloudflare Turnstile.
define( 'ARALIA_TURNSTILE_SITE_KEY', '' );   // P
define( 'ARALIA_TURNSTILE_SECRET_KEY', '' ); // R

// Meta Conversions API.
define( 'ARALIA_META_PIXEL_ID', '' );      // P
define( 'ARALIA_META_CAPI_TOKEN', '' );    // R

// GA4: Measurement Protocol y Data API (cuenta de servicio de SOLO LECTURA).
define( 'ARALIA_GA4_MEASUREMENT_ID', '' );      // P
define( 'ARALIA_GA4_MP_API_SECRET', '' );       // R
define( 'ARALIA_GA4_PROPERTY_ID', '' );         // I
define( 'ARALIA_GA4_SERVICE_ACCOUNT_JSON', '' ); // R (JSON en una línea)

// Datos personales (R-23): libsodium secretbox (32 bytes en base64) y llave HMAC para subject_hash.
// Generar con: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
// Alertas (R-22): obligatorias fuera de local/test (no se toman de opciones editables desde wp-admin). Remitente de los correos: del dominio verificado (SPF/DKIM/DMARC).
define( 'ARALIA_ALERT_EMAIL', '' ); // R
define( 'ARALIA_ALERT_PHONE', '' ); // R (móvil colombiano)
define( 'ARALIA_MAIL_FROM', '' );   // I
// IPs o CIDR (separados por comas) de los proxies de confianza delante del sitio (Hostinger/CDN); vacío = no se confía en X-Forwarded-For.
// DESCOMENTAR tras verificar en staging (T012): sin esta constante `wp aralia baseline check` falla (trusted_proxies).
// define( 'ARALIA_TRUSTED_PROXIES', '' ); // I
// Marca de salida a producción (T160): desde ahí la línea base exige la CSP en enforce.
// define( 'ARALIA_GO_LIVE', true ); // I

// Llaves de privacidad: base64 de 32 bytes aleatorios, DISTINTAS entre sí (`php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`).
// Sin ellas `wp aralia baseline check` falla (privacy_keys) y no hay huellas, consentimientos ni alertas por SMS.
define( 'ARALIA_PII_KEY', '' );  // R
define( 'ARALIA_HMAC_KEY', '' ); // R
