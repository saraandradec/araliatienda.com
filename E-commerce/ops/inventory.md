# Inventario de componentes (PCI 6.3.2, SSDF PW.4)

Actualizar en cada cambio de versión. Criterios para aceptar un plugin o librería nuevos (SSDF PO.1.3): mantenido activamente, actualizado en los últimos 6 meses, sin vulnerabilidades abiertas conocidas, proveedor identificable y justificación escrita aquí.

Última revisión: 2026-11-13.

## Núcleo y plugins de terceros

| Componente | Versión fijada | Publicada | Fuente | Justificación | Notas |
|------------|----------------|-----------|--------|---------------|-------|
| WordPress | 7.1.4 | — | wordpress.org (`roots/wordpress-no-content`) | Plataforma decidida | 6.9.x y 7.0.x están marcadas sin soporte de seguridad en wordpress.org |
| WooCommerce | 11.2.1 | 2026-10-09 | wordpress.org | Tienda | Exige WP ≥ 7.0 |
| Wompi Portal de Pagos | 3.2.1 | 2026-10-23 | wordpress.org (autor: Wompi) | Pasarela decidida | **Probado por su autor solo hasta WP 6.9.11.** La compatibilidad con 7.1 se verifica con la prueba de contrato y los E2E de la puerta T073. Blindado por `aralia-wompi-guard` (research R-01) |
| Wordfence | 9.0.2 | 2026-09-30 | wordpress.org | WAF, 2FA, escáner, alertas de vulnerabilidades | |
| LiteSpeed Cache | 7.9.2 | ☐ por confirmar (≥ 2 semanas) | wordpress.org | Caché de página y CDN de Hostinger | 7.9.3 salió el 2026-11-03; se evita por ser muy reciente |
| UpdraftPlus Premium | ☐ por fijar | — | updraftplus.com (licencia de pago) | Copias cifradas fuera de Hostinger (R-17) | Instalación manual: no está en Composer. Guardar el zip verificado y su versión aquí |

## Herramientas de desarrollo (no van a producción)

| Componente | Versión | Uso |
|------------|---------|-----|
| @wordpress/env | 12.1.0 | Entorno local y CI |
| @wordpress/scripts | 37.1.0 | Build de bloques |
| @playwright/test | 1.65.0 | E2E |
| @axe-core/playwright | 4.14.0 | Accesibilidad AA |
| @lhci/cli | 0.16.1 | Lighthouse CI |
| wp-coding-standards/wpcs | 3.4.1 | PHPCS |
| phpcompatibility/phpcompatibility-wp | 2.1.6 | Compatibilidad PHP |
| phpstan/phpstan + szepeviktor/phpstan-wordpress | 1.12.7 / 1.3.5 | Análisis estático |
| php-stubs/woocommerce-stubs | 11.2.1 | Stubs para PHPStan (misma versión que WC) |
| phpunit/phpunit + yoast/phpunit-polyfills | 9.6.39 / 2.0.2 | Pruebas PHP |
| gitleaks | 8.30.1 (descarga verificada con SHA-256 en el CI) | Secretos en CI |

Otros: `roots/wordpress-core-installer` 1.100.0 (instala WP en `wp/`); `composer audit` limpio al 2026-11-13.

**Avisos de `npm audit` aceptados (solo desarrollo)**: 13 avisos de severidad alta, todos en dependencias transitivas de `@wordpress/scripts`, `@wordpress/env` y `@lhci/cli`. Ninguna va a producción (no se despliega `node_modules/`). Mitigación: Dependabot semanal (`.github/dependabot.yml`) y revisión en cada actualización; si alguno afecta al CI (que ejecuta código del repositorio), subir el paquete o fijar `overrides`. Revisar de nuevo el 2026-12-13.

**Entorno local (Docker)**: la imagen `wordpress:php8.3` (Debian trixie) falla en `apt install git` por falta del grupo `_ssh`; en local se parcha con una imagen base propia (`groupadd -r _ssh`) re-etiquetada. En CI conviene fijar la imagen o aplicar el mismo parche.

## Servicios externos (encargados / TPSP)

| Servicio | Uso | Datos | Contrato / términos | Estado PCI |
|----------|-----|-------|---------------------|-----------|
| Wompi | Pasarela | Pago, PII de compra | ☐ | AOC ☐ (`ops/compliance/wompi-aoc.md`) |
| Hostinger | Hosting, copias, CDN | Todo el sitio | ☐ | — |
| Brevo | Correo, SMS, lista | PII de contacto | ☐ | — |
| Cloudflare Turnstile | Anti-bots | IP, huella | ☐ | — |
| Google (GA4) | Analítica | Seudónimos | ☐ | — |
| Meta | Pixel + CAPI | Hash de correo y celular | ☐ | — |
| Operador de envíos | Entregas | Nombre, dirección, celular | ☐ por elegir | — |

## Decisión de SMS (T016)

☐ Pendiente: verificar con Brevo el envío de SMS a Colombia (remitente, costo por mensaje, plantillas). Si no aplica, alternativa: WhatsApp Business API. Registrar la decisión en `specs/001-aralia-store-p0/research.md` R-13.
