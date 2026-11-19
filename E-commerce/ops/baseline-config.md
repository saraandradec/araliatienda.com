# Línea base de configuración — Aralía Beauty Store

Fuente: research R-03, R-12, R-13, R-15, R-16, R-17, R-21; compliance-mapping 2.2.x / 8.9. La verificación automática es `wp aralia baseline check` (se implementa en la fase 2, T032) y corre después de cada despliegue; en producción `ops/deploy.sh` se niega a desplegar mientras el paquete no lo traiga (se comprueba el paquete, no el servidor).

Estado: ☐ pendiente · ☑ verificado (fecha, quién).

---

## 1. Hosting (T012)

| # | Requisito | Valor | Estado |
|---|-----------|-------|--------|
| H1 | Plan | Hostinger **Business** o **Cloud Startup** (staging, SSH, cron, copias diarias, CDN, LiteSpeed) | ☐ |
| H2 | PHP | 8.3 (mínimo 8.2 por el plugin de Wompi) | ☐ |
| H3 | SSH | Acceso solo con llave ed25519; sin contraseña; llave del despliegue distinta de la personal, **guardada solo en el equipo de Sara (ssh-agent), nunca en GitHub** (R-15); alias en `~/.ssh/config` según `ops/deploy.conf.sample` | ☐ |
| H4 | Cron | `DISABLE_WP_CRON = true` en `wp-config.php` + cron del panel cada minuto: `cd <ruta> && php wp-cron.php` (o `wp cron event run --due-now`) | ☐ |
| H5 | Staging | Creado **limpio** (sin clonar producción) o anonimizado con `wp aralia anonymize` antes de habilitar el acceso (R-17) | ☐ |
| H6 | Secretos | `aralia-secrets.php` **fuera de `public_html`**, permisos `600`; `wp-config.php` define `ARALIA_SECRETS_PATH`, que lee el mu-plugin `aralia-secrets-loader` (R-16); `ops/deploy.sh` nunca lo toca | ☐ |
| H7 | HTTPS | Certificado activo; redirección 301 a HTTPS; HSTS 1 año (lo envía `aralia-core`) | ☐ |
| H8 | `wp-config.php` | `DISALLOW_FILE_EDIT` (producción), `WP_DEBUG = false`, prefijo de tablas propio (no `wp_`), sales únicas. **Sin** `DISALLOW_FILE_MODS`: WordPress y los plugins se actualizan desde wp-admin (research R-15) | ☐ |
| H9 | Base de datos | Usuario y contraseña propios (≥ 12 caracteres), no los del instalador | ☐ |
| H10 | 2FA del panel de Hostinger | Activo (ver `ops/compliance/accounts-2fa.md`) | ☐ |

## 2. WordPress y WooCommerce

| # | Requisito | Valor | Estado |
|---|-----------|-------|--------|
| W1 | Versiones | WordPress, WooCommerce, Wompi, Wordfence y LiteSpeed los instala y actualiza Hostinger; la versión probada (WP 7.1.4, WooCommerce 11.2.1, Wompi 3.2.1, Wordfence 9.0.2, LiteSpeed 7.9.2 al 2026-11-13) se registra en `ops/inventory.md` | ☐ |
| W1b | Actualizaciones | Actualización automática **desactivada** en el plugin de Wompi; automáticas menores de WordPress activas (`ops/third-party.lock` admite `core 7.1.*`; subir a 7.2 exige PR + staging); copia previa (UpdraftPlus) y prueba en staging antes de actualizar WooCommerce/Wordfence/LiteSpeed (T168) | ☐ |
| W2 | Usuarios | Sin usuario `admin`; una sola administradora permanente (Sara); cuentas temporales según `ops/runbooks/access.md` | ☐ |
| W3 | Registro | Clientas solo con rol `customer` | ☐ |
| W4 | XML-RPC, `/wp/v2/users`, archivos de autor | Deshabilitados (`aralia-hardening.php`) | ☐ |
| W5 | `uploads/` | Sin ejecución de PHP | ☐ |
| W6 | WooCommerce | COP, `price_num_decimals = 0`, stock gestionado, `hold_stock_minutes = 30`, HPOS (sincronización activa hasta T157), checkout de bloques, compra como invitada | ☐ |
| W7 | Wordfence | WAF activo, 2FA para la administradora, escáner programado, alertas a Sara | ☐ |
| W8 | LiteSpeed | Exclusiones y optimización JS desactivada en páginas de pago según `ops/cache-policy.md` | ☐ |

### Verificación automática y configuración manual de Wordfence (T032)

`wp aralia baseline check` (staging y producción, tras cada despliegue) comprueba (ids: `env_defined`, `env_matches_host`, `no_admin_user`, `file_edit_disabled`, `file_mods_allowed`, `xmlrpc_disabled`, `app_passwords_disabled`, `users_endpoint_hidden`, `security_headers`, `headers_registered`, `headers_real`, `hsts_https`, `csp_mode`, `csp_enforce`, `trusted_proxies`, `turnstile_secret`, `brevo_key`, `alert_destinations`, `uploads_no_php`, `debug_off`, `secrets_loaded`, `privacy_keys`, `store_config`, `db_current`, `wordfence_active`). Notas: en producción las cabeceras se leen de la respuesta REAL de la home; `csp_enforce` solo se exige desde el go-live (`ARALIA_GO_LIVE`, T160) para no impedir despliegues durante el inventario de la CSP (T150); `trusted_proxies` exige que `ARALIA_TRUSTED_PROXIES` esté definida (vacía solo tras verificar en staging que `REMOTE_ADDR` ya es la IP real). Comprobaciones: variable `ARALIA_ENV` válida; sin usuario `admin`; `DISALLOW_FILE_EDIT` activo (y `DISALLOW_FILE_MODS` NO); XML-RPC apagado; `/wp/v2/users` oculto a visitantes; cabeceras de seguridad (HSTS, nosniff, Referrer-Policy, Permissions-Policy, `frame-ancestors 'self'`) y que estén enganchadas; sitio por HTTPS; `uploads/` sin ejecución de PHP; `WP_DEBUG` apagado; secretos cargados; llaves de privacidad válidas; configuración de la tienda conforme (`StoreConfig`); base de datos al día y sin errores de migración; Wordfence activo. En local y test las reglas de producción se marcan «omitido».

**Lo que NO se puede verificar desde el código y debe hacer Sara en staging/producción** (marcar en la tabla de arriba, W7): WAF de Wordfence en modo «Enabled and Protecting», 2FA de la administradora, escáner programado (malware y vulnerabilidades) y alertas por correo/SMS a Sara. Hasta que estén hechos, T032 queda parcial.

## 3. Correo (T018)

| # | Requisito | Valor | Estado |
|---|-----------|-------|--------|
| C1 | Dominio de envío | `araliatienda.com` (o subdominio `mail.`), autenticado en Brevo | ☐ |
| C2 | SPF | Registro TXT que incluya a Brevo (`include:spf.brevo.com`) sin superar 10 búsquedas DNS | ☐ |
| C3 | DKIM | Registros que entrega Brevo, verificados en su panel | ☐ |
| C4 | DMARC | `_dmarc` TXT: `v=DMARC1; p=quarantine; rua=mailto:<buzón de Sara>; adkim=s; aspf=r` | ☐ |
| C4b | Alineación SPF | `aspf=r` (relajada): Brevo envía con un dominio de rebote en subdominio; con `aspf=s` DMARC fallaría aunque SPF pase. DKIM alineado (`adkim=s`) es el que garantiza el `pass` | ☐ |
| C5 | Prueba | Correo de prueba a Gmail y Outlook con SPF, DKIM y DMARC = `pass` en las cabeceras | ☐ |

## 4. Entorno de pruebas de pago (T019)

| # | Requisito | Valor | Estado |
|---|-----------|-------|--------|
| P1 | Staging con Wompi Sandbox | Llaves `*_TEST` en `aralia-secrets.php` de staging; `ARALIA_WOMPI_MODE = 'test'` | ☐ |
| P2 | URL de eventos de Sandbox | Panel de Wompi (ambiente de pruebas) → URL de eventos = `https://<staging>/?wc-api=wc_wompi` | ☐ |
| P3 | E2E con eventos reales | Corren en staging (`BASE_URL` de staging en Playwright) | ☐ |
| P4 | CI sin eventos reales | wp-env + eventos firmados con el secreto de prueba (`wp aralia wompi replay <fixture>`) | ☐ |
| P5 | Casos de tiempo | Avance de reloj de Action Scheduler en CI (tarea T163) (`wp action-scheduler run --hooks=aralia_order_expiry` con fecha simulada) para T-PSE-ABANDON y T-HOLD-PENDING | ☐ |
| P6 | Sin datos reales | Solo datos ficticios en staging y CI (data-classification regla 8) | ☐ |
