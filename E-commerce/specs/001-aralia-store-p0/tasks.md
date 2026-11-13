---
description: "Tareas de implementación — Aralía Beauty Store P0"
---

# Tasks: Aralía Beauty Store — Tienda en línea (P0)

**Input**: Documentos de `specs/001-aralia-store-p0/` (plan iteración 3 aprobado por `logicraft-trace:architect`)
**Prerequisites**: plan.md, spec.md, research.md (v3), data-model.md (v3), contracts/, quickstart.md, threat-model.md (v1.2), compliance-mapping.md
**Tier TRACE**: T0 — cada fase pasa por los gates de `logidev` (arquitecto, code-reviewer, pruebas, security-reviewer, database-reviewer, verificación adversarial, sign-off humano).

**Tests**: OBLIGATORIOS. El spec (SC-010…SC-015), el contrato `contracts/wompi-integration.md` §6 y el tier T0 los exigen. Las pruebas de cada historia se escriben primero y deben fallar antes de implementar.

**Organización**: por historia de usuario (US1…US8 del spec). El pago y el catálogo son fundacionales porque todas las historias terminan en una compra.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: paralelizable (archivos distintos, sin dependencias pendientes)
- **[Story]**: US1…US8
- Rutas relativas a `E-commerce/`. Abreviaturas: `core/` = `wp-content/plugins/aralia-core/`, `guard/` = `wp-content/mu-plugins/aralia-wompi-guard/`, `theme/` = `wp-content/themes/aralia/`

## Correspondencia con las fases de entrega del plan

| Fase del plan | Fases de este archivo |
|---------------|-----------------------|
| 1. Base | Fase 1 (Setup) + Fase 2 (Fundacional) — puerta: T073 |
| 2. Tienda | Fase 3 (US1) — puerta: T098 |
| 3. Secciones | Fases 4–8 (US2, US3, US4, US5, US6) — puerta: T128, al final de US6 |
| 4. Automatización | Fase 9 (US7) + Fase 10 (US8) — puerta: tarea de puerta al final de US7 |
| 5. Apertura | Fase 11 (Pulido y apertura) |

**Desviación anotada respecto del plan**: la CSP en modo aplicado y el plan de incidentes (fase 4 del plan) quedan en la Fase 11, porque el inventario de scripts exige dos semanas en Report-Only **también con los dominios de producción** (R-11), que solo existen al acercarse la apertura. En la fase 4 la CSP corre en Report-Only en staging y el monitor ya alerta.

---

## Phase 1: Setup (infraestructura compartida)

**Purpose**: repositorio, entorno, CI, hosting y cuentas.

- [x] T001 Ratificar la constitución del proyecto con `/speckit.constitution` (principios 1.2, reglas de marca 1.3 y reglas T0 de plan.md §Constitution Check) en `.specify/memory/constitution.md`
- [x] T002 Crear la estructura de directorios del plan (`wp-content/{themes/aralia,plugins/aralia-core,mu-plugins}`, `tests/{php/{unit,integration,contract},e2e,load,fixtures}`, `tools/script-monitor`, `ops/{compliance,runbooks,templates}`) y `.gitignore` (secretos, `vendor/`, `node_modules/`, `.wp-env/`, `wp-content/uploads/`) en `.gitignore`
- [x] T003 Declarar con versión fija WordPress 7.1.x (7.1.4 al 2026-11-03; 6.9 y 7.0 sin soporte de seguridad), WooCommerce 11.2.x, Wompi Portal de Pagos 3.2.1, Wordfence y LiteSpeed Cache (wpackagist) en `composer.json`; documentar la instalación manual de UpdraftPlus Premium en `ops/inventory.md`
- [x] T004 [P] Configurar `@wordpress/scripts`, `@wordpress/env`, Playwright, `@axe-core/playwright` y `@lhci/cli` en `package.json`
- [x] T005 Configurar wp-env (PHP 8.3, plugins fijados, mapeo de `mu-plugins/`, inclusión de `aralia-secrets.php` de Sandbox) en `.wp-env.json`
- [x] T006 [P] Configurar PHPCS (WordPress-Extra + reglas de seguridad) y PHPStan nivel 6 con stubs de WP/WC en `phpcs.xml.dist` y `phpstan.neon`
- [x] T007 [P] Configurar gitleaks con los patrones de llaves de Wompi (`prv_`, `pub_prod_`, `prod_events_`, `prod_integrity_`, `test_*`) en `.gitleaks.toml`
- [x] T008 Crear el pipeline de CI (PHPCS, PHPStan, PHPUnit, prueba de contrato, Playwright + axe, Lighthouse CI, gitleaks) en `.github/workflows/ecommerce-ci.yml` (en la raíz del repo; acciones con SHA fijado, pasos condicionados por existencia, job `release` que arma el paquete)
- [x] T009 Crear el despliegue **local** `ops/deploy.sh` (package/staging/production; exige `main`, CI verde, checksum, staging previo y PR aprobado por `ops/payment-reviewers.txt`; `wp core verify-checksums` y `wp plugin verify-checksums` de la lista cerrada de plugins después de cada despliegue; el código propio se compara con `git archive`) con `ops/deploy.conf.sample` y `tests/ops/deploy_test.sh` (enmienda 1.1.0: el plan Free de GitHub no protege environments; `deploy.yml` eliminado). Incluye `rollback`, génesis de revisión, lista de revisoras del último despliegue con `ALTA-REVISORA` solo en el primer despliegue, y comparación del paquete con `git archive`
- [x] T010 [P] Crear la plantilla de secretos con todas las constantes de research R-16 (Wompi ×8, Brevo, Turnstile, Meta CAPI, GA4 MP, cuenta de servicio GA4, `ARALIA_PII_KEY`, llave HMAC) en `ops/templates/aralia-secrets.sample.php`
- [x] T011 Crear el repo privado en GitHub con 2FA obligatorio y revisión obligatoria de la revisora humana nombrada para `wp-content/mu-plugins/aralia-wompi-guard/`, `core/src/{Orders,Checkout,Cart,Rest}/` (R-15) en `.github/CODEOWNERS` y `ops/payment-reviewers.txt` (régimen transitorio: provisional en CODEOWNERS `@saraandradec`; `ops/payment-reviewers.txt` vacío a propósito; con plan Free lo hace cumplir `ops/deploy.sh`; el 2FA de colaboradoras se verifica en T014)
- [ ] T012 Contratar Hostinger Business/Cloud: PHP 8.3, SSH con llave, `DISABLE_WP_CRON` + cron real cada minuto, staging **limpio**, `aralia-secrets.php` fuera de `public_html` con permisos 600; documentarlo en `ops/baseline-config.md` (sección Hosting)
- [x] T013 [P] Escribir el inventario de componentes (versión, justificación, criterios PO.1.3) en `ops/inventory.md`
- [ ] T014 [P] Activar 2FA y contraseñas de 12 o más caracteres en Hostinger, registrador, correo, Wompi, Brevo, Meta Business, Google y GitHub; confirmar que no existe el usuario `admin`; checklist en `ops/compliance/accounts-2fa.md`
- [ ] T015 [P] Escribir la matriz de responsabilidades Wompi / Hostinger / Aralía y solicitar el AOC de Wompi en `ops/compliance/responsibility-matrix.md` y `ops/compliance/wompi-aoc.md`
- [ ] T016 [P] Verificar con Brevo el envío de SMS a Colombia (remitente, costo, plantillas) y registrar la decisión (Brevo o WhatsApp Business API) en `specs/001-aralia-store-p0/research.md` R-13 y `ops/inventory.md`
- [x] T017 [P] Escribir el procedimiento de cuentas temporales de soporte (alta, 2FA, baja, revisión semestral) en `ops/runbooks/access.md`
- [ ] T018 [P] Configurar SPF, DKIM y DMARC (`p=quarantine`) del dominio de envío y verificarlo en Brevo (R-13, TM S-4); evidencia en `ops/baseline-config.md` (sección Correo)
- [ ] T019 Preparar el entorno de pruebas de pago: staging de Hostinger con la URL de eventos de **Wompi Sandbox** apuntando a staging (los E2E con eventos reales corren ahí); en CI, eventos firmados por *replay* (`wp aralia wompi replay`) y avance de reloj de Action Scheduler para los casos que el Sandbox no produce a voluntad (T-PSE-ABANDON, T-HOLD-PENDING). Documentado en `quickstart.md` y `ops/baseline-config.md`

---

## Phase 2: Foundational (prerrequisitos bloqueantes)

**Purpose**: núcleo del plugin, seguridad base, modelo de catálogo, tema base y **todo el camino de pago**. Ninguna historia empieza antes de la puerta T073.

### Núcleo y datos

- [ ] T020 Crear el bootstrap de `aralia-core` (autoloader propio PSR-4 `Aralia\\` sin Composer en producción, registro de servicios, comando WP-CLI `wp aralia`) en `core/aralia-core.php` y `core/src/Plugin.php`, y agregar `aralia-core` y el tema `aralia` a `.wp-env.json`
- [ ] T162 [P] Implementar `wp aralia wompi replay <fixture.json>` (reproduce eventos firmados con el secreto de prueba) en `core/src/Cli/WompiReplayCommand.php`; lo usan T019 y el CI
- [ ] T163 [P] Implementar el avance de reloj de Action Scheduler para pruebas (`wp action-scheduler run --hooks=aralia_order_expiry` con fecha simulada; T-PSE-ABANDON, T-HOLD-PENDING) en `tests/php/integration/ClockTest.php` y `tests/e2e/support/clock.ts`
- [ ] T164 Implementar la bandera `--sample-catalog` de `wp aralia setup` (taxonomías, atributos y catálogo de ejemplo para local y CI) en `core/src/Cli/SetupCommand.php`
- [ ] T165 Fijar la imagen base de Docker de wp-env en el CI (la `wordpress:php8.3` de Debian trixie falla en `apt install git` por falta del grupo `_ssh`; ver `ops/inventory.md`) antes de T020, en `.wp-env.json`/`.github/workflows/ecommerce-ci.yml`
- [ ] T166 Condiciones de la primera salida a producción (decisiones de Sara): staging en una cuenta/usuario de Hostinger distinta de producción y GitHub Pro/Team con protección de `main`; `ops/deploy.sh` se niega a desplegar a producción sin ellas (salvo `ALLOW_SHARED_STAGING` / `ALLOW_UNPROTECTED_MAIN` en `deploy.conf`, aceptación escrita). Verificar antes de T160 en `ops/go-live-checklist.md` y `ops/compliance/accounts-2fa.md`
- [ ] T167 Añadir al CI un paso de avisos de vulnerabilidades de WordPress para WooCommerce, Wompi, Wordfence y LiteSpeed (Wordfence Intelligence, Patchstack o WPScan): `composer audit` no cubre wpackagist; y verificar el SHA-256 de los zips de wpackagist antes de copiar en `.github/workflows/ecommerce-ci.yml` y `ops/inventory.md`
- [ ] T021 Crear las migraciones versionadas de las 6 tablas `aralia_*` con sus índices únicos (data-model §4) en `core/migrations/`
- [ ] T022 [P] Escribir la prueba de migraciones e índices únicos en `tests/php/integration/MigrationsTest.php`
- [ ] T023 Configurar WooCommerce con el comando `wp aralia setup`: COP, `woocommerce_price_num_decimals = 0`, stock gestionado, `woocommerce_hold_stock_minutes = 30`, HPOS con sincronización, checkout de bloques, compra como invitada, registro solo con rol cliente. En `core/src/Cli/SetupCommand.php`

### Seguridad base

- [ ] T024 [P] Crear el mu-plugin de endurecimiento: `DISALLOW_FILE_EDIT`/`DISALLOW_FILE_MODS` en producción, sin XML-RPC, sin `/wp/v2/users` ni archivos de autor, depuración apagada y `uploads/` sin PHP. En `wp-content/mu-plugins/aralia-hardening.php`
- [ ] T025 [P] Implementar las cabeceras de seguridad: HSTS, `nosniff`, `Referrer-Policy`, `Permissions-Policy` y `frame-ancestors 'self'`. En `core/src/Security/Headers.php`
- [ ] T026 Implementar el limitador con incremento atómico (`INSERT … ON DUPLICATE KEY UPDATE`) en `core/src/Security/RateLimiter.php`, con su prueba en `tests/php/unit/RateLimiterTest.php`
- [ ] T027 [P] Implementar el registro de actividad (inicios de sesión, fallos, roles, usuarios, plugins, ajustes, precios, estados, reembolsos, transiciones bloqueadas) en `core/src/Security/AuditLog.php`
- [ ] T028 [P] Implementar el cliente de Brevo y la base de `WC_Email` con plantillas de marca en `core/src/Notifications/BrevoClient.php` y `theme/woocommerce/emails/`
- [ ] T029 Implementar la matriz de alertas de R-22 (correo y SMS, con su severidad) en `core/src/Security/Alerts.php` y documentarla en `ops/alerts.md`. Las alertas del monitor se separan en críticas (nuevo src, `widget.js`, cabeceras) y avisos (inline), según L-03 Depende de T028 (canal SMS de Brevo).
- [ ] T030 [P] Implementar el cifrado de datos personales (libsodium `secretbox` con `ARALIA_PII_KEY` y `subject_hash` HMAC) en `core/src/Privacy/Crypto.php`, con su prueba en `tests/php/unit/CryptoTest.php`
- [ ] T031 [P] Implementar el registro de consentimientos (propósito, versión del texto, canal, revocación, reclamo en trámite) en `core/src/Privacy/Consents.php`
- [ ] T032 Configurar Wordfence (WAF, 2FA de la administradora, escáner de malware y vulnerabilidades, alertas), documentarlo en `ops/baseline-config.md` y crear la verificación automática de la línea base con wp-cli en staging (`wp aralia baseline check`: sin `admin`, sin edición de archivos, sin XML-RPC, cabeceras, HSTS, uploads sin PHP) en `core/src/Cli/BaselineCheck.php`
- [ ] T033 Implementar la CSP por tipo de página (páginas de pago con nonce y `strict-dynamic`; páginas públicas con hashes calculados en el render mediante output buffer; modo Report-Only configurable) en `core/src/Security/Csp.php`
- [ ] T034 [P] Implementar `CacheInvalidator` (transients y purga por etiqueta en LiteSpeed y la CDN) y escribir la política de caché de R-21 en `core/src/Cache/CacheInvalidator.php` y `ops/cache-policy.md`. Criterio: LiteSpeed excluye checkout, order-pay, Gracias, carrito, cuenta, `/wc/store/*`, `?wc-api=*` y `aralia/v1` de sesión, y desactiva la optimización JS (combinar, diferir, retrasar) en las páginas de pago y para `widget.js`
- [ ] T035 [P] Implementar Turnstile del lado del servidor (token en `extensions.aralia` del checkout de la Store API, validado en `woocommerce_store_api_checkout_update_order_from_request`) en `core/src/Security/Turnstile.php`
- [ ] T036 Implementar los límites anti-abuso: pedidos pendientes por celular, correo e IP (señal blanda), unidades por línea, rechazos por origen (desafío a partir de 5 por hora, bloqueo a partir de 10) e intentos de código de descuento (≤ 10 por hora por sesión + IP, con Turnstile). En `core/src/Security/AbuseLimits.php`
- [ ] T037 [P] Escribir la prueba de redondeo de cupones porcentuales (7 %, 13 %, 33 %) a peso entero con `price_num_decimals = 0` y su efecto en `amount_in_cents` en `tests/php/unit/CouponRoundingTest.php`

### Catálogo

- [ ] T038 Registrar las 7 categorías, `aralia_seccion` (8), `aralia_paso` (7, con `requires_tone`) y los atributos globales `pa_tono` (swatch, subtono, orden), `pa_color`, `pa_look` y `pa_tipo_bolsa` en `core/src/Catalog/Taxonomies.php`
- [ ] T039 Registrar los metadatos de producto (`_ara_tone_dependent`, `_ara_cost` privado y fuera de REST, `_ara_swatch_id`, `_ara_invima`, `_ara_how_to`, `_ara_ingredients`, `_ara_kind`, `_ara_components`, `_ara_price_history`) en `core/src/Catalog/ProductMeta.php`
- [ ] T040 [P] Escribir la prueba de que `_ara_cost` no aparece en la Store API, `wc/v3`, los feeds ni el HTML en `tests/php/integration/CostExposureTest.php`
- [ ] T041 Implementar la regla de publicación (solo en la transición a `publish`; nunca despublica por agotamiento) y la pantalla "Catálogo incompleto" en `core/src/Catalog/PublishRule.php` y `core/src/Admin/IncompleteCatalog.php`, con su prueba en `tests/php/unit/PublishRuleTest.php`
- [ ] T042 Crear el alta rápida de producto con todos los atributos de 8.1 en una sola pantalla (FR-065, FR-067) y la edición de las familias de tono con su swatch, subtono y orden (FR-068) en `core/src/Admin/QuickProduct.php` y `core/src/Admin/ToneFamilies.php`
- [ ] T043 Implementar el validador del CSV (columnas, listas controladas, inyección de fórmulas, diferencias de precio mayores al 20%) y `wp aralia catalog validate` en `core/src/Catalog/Validator.php`
- [ ] T044 Implementar el importador con la API CRUD: variaciones por tono, marca, swatches, cross-sells por SKU, fotos por nombre y borrador si la fila está incompleta. En `core/src/Catalog/Importer.php`
- [ ] T045 Implementar la pantalla "Importar inventario" (CSV + ZIP con validación de MIME real, sin rutas relativas ni absolutas, máx. 200 archivos y 100 MB, extracción fuera de `uploads/`, vista previa y confirmación; L-06) en `core/src/Admin/ImportScreen.php`
- [ ] T046 [P] Escribir las pruebas del validador, del importador y del ZIP (incluye un ZIP con zip slip) con fixtures en `tests/php/integration/ImportTest.php` y `tests/fixtures/inventory/`

### Tema base

- [ ] T047 Crear el tema de bloques: `theme.json` con los tokens claro y oscuro (champagne oscurecido para texto sobre crema), Cormorant Garamond y Jost auto-alojadas, plantillas base, encabezado, pie y cajón móvil con Categorías y Secciones (FR-001); GSAP auto-alojado solo en páginas públicas que lo usan, con `prefers-reduced-motion` y nunca en checkout, order-pay ni Gracias (R-04). En `theme/`
- [ ] T048 Crear la hoja de empaque base imprimible (piezas, tonos, cantidades, agrupación por `_ara_group_id`, datos de envío) en `core/src/Orders/PackingSlip.php`; US4 y US5 la extienden
- [ ] T049 Configurar el checkout provisional de la puerta de la fase 1: checkout de bloques nativo con un envío de tarifa plana temporal y la página Gracias nativa conectada a `order-status`. Se reemplaza en US1 (T089–T091)

### Camino de pago (guard + pedidos)

- [ ] T050 Implementar la inyección de llaves desde constantes y el vaciado de esos campos en la BD (`option_` y `pre_update_option_woocommerce_wompi_settings`, H6) en `guard/Keys.php`
- [ ] T051 [P] Forzar `sslverify => true` para `*.wompi.co` con `http_request_args` (H2) en `guard/Tls.php`
- [ ] T052 [P] Neutralizar el plugin: `remove_action('woocommerce_process_shop_order_meta')`, quitar `wc-voided` de `wc_order_statuses`, sin reembolso ni anulación en las acciones del pedido (H7, H13). En `guard/Neutralize.php`
- [ ] T053 Registrar `ara-nostock`, `ara-packed`, `ara-shipped` y `ara-reversed`, más los filtros de estados pagados y de informes (R-05), en `core/src/Orders/Statuses.php`
- [ ] T054 Implementar la máquina de estados de data-model §3 (las transiciones bloqueadas se registran en la auditoría y generan alerta) en `core/src/Orders/StateMachine.php`
- [ ] T055 [P] Escribir una prueba por cada transición permitida y por cada transición bloqueada en `tests/php/unit/StateMachineTest.php`
- [ ] T056 Implementar el descuento de stock al crear el pedido: `wc_reduce_stock_levels` + `wc_release_stock_for_order` en `woocommerce_checkout_order_processed` **y** en `woocommerce_store_api_checkout_order_processed` (L-01), y programar `aralia_order_expiry`. En `core/src/Orders/StockOnCreate.php`
- [ ] T057 [P] Implementar el descuento parcial línea por línea con `_reduced_stock`, `_ara_missing` y `_order_stock_reduced` en `core/src/Orders/PartialStock.php`
- [ ] T058 Implementar el vencimiento propio en `core/src/Orders/Expiry.php`:
  - Consulta a Wompi antes de cancelar.
  - PENDING: +15 min, con tope de 2 h.
  - Extensión única a +5 min al abrir el widget, hasta los 35 min.
  - Cancelación nativa desactivada para `wompi`.
- [ ] T059 Implementar el cliente de la API de Wompi (`GET /v1/transactions/{id}`, timeout de 8 s, TLS verificado) en `guard/WompiClient.php`. Ejecutar **T-API-AUTH** en Sandbox y registrar la llave elegida en `specs/001-aralia-store-p0/research.md` R-02
- [ ] T060 Implementar el manejador de eventos en `woocommerce_api_wc_wompi` con prioridad 1 (`exit` al final; contracts/wompi §3). En `guard/EventHandler.php`:
  - Registro saneado con lista blanca de campos.
  - Checksum con `hash_equals`.
  - Referencia `^\d+-\d{1,3}$`.
  - Idempotencia por estado verificado.
  - Verificación doble con la API.
  - Máquina de estados.
  - Guardar el `transaction_id` en `_ara_attempts` aunque el evento quede `deferred` (R-02 §2b).
- [ ] T061 Implementar el order-pay propio en `guard/OrderPay.php` y `guard/assets/order-pay.js`:
  - Quitar la acción `woocommerce_receipt_wompi` del plugin.
  - Persistir `<id>-<n>` antes de renderizar y firmar en el servidor.
  - No abrir el widget si hay un intento PENDING.
  - Tope de 30 aperturas y de 5 rechazos.
  - Tiempo restante visible.
  - Plan alterno a los 8 s si el widget no carga.
  - Callback del widget a `payment-attempt`.
- [ ] T062 [P] Implementar `POST /aralia/v1/payment-attempt` y `POST /aralia/v1/order-status` (contracts/rest-api §8 y §8b) en `core/src/Rest/PaymentAttempt.php` y `core/src/Rest/OrderStatus.php`
- [ ] T063 Implementar la conciliación cada 5 min (pedidos `pending` y `cancelled` de 24 h o menos; los aprobados de más de 24 h, con alerta) en `guard/Reconciler.php`
- [ ] T064 [P] Implementar la detección de doble cobro y escribir su runbook en `core/src/Orders/DoubleCharge.php` y `ops/runbooks/duplicate-charge.md`
- [ ] T065 [P] Implementar `POST /aralia/v1/order-again` (pedidos `cancelled`, o `pending` tras un rechazo sin intento PENDING) en `core/src/Rest/OrderAgain.php`
- [ ] T066 [P] Escribir la prueba de contrato de la superficie del plugin (hooks, opción, estado `wc-voided`, id del gateway, y grep de `payment_complete`/`update_status`/`set_status` fuera de los puntos de H13) en `tests/php/contract/WompiPluginSurfaceTest.php`
- [ ] T067 [P] Crear los fixtures de eventos firmados con el secreto de prueba (aprobado, rechazado, anulado, pendiente, monto alterado, moneda alterada, referencia ajena, checksum alterado) en `tests/fixtures/wompi/`
- [ ] T068 Escribir las pruebas de integración de contracts/wompi §6 en `tests/php/integration/Wompi/`: T-EVT-FORGED, -AMOUNT, -CURRENCY, -REF, -DUP, -REGRESSION, -API-DOWN, -LATE-NOSTOCK, -LATE-STOCK, T-RECONCILE, T-SSL, T-PAY-DOUBLE, T-STOCK-DOUBLECOUNT (con checkout de bloques), T-STOCK-FLAGS, T-HOLD-TIMING, T-HOLD-PENDING, T-KEYS y **T-STOCK-RACE** (dos checkouts simultáneos por la última unidad, con concurrencia real)
- [ ] T069 Escribir las pruebas E2E en Wompi Sandbox en `tests/e2e/payments/`. Corren en staging (T019) sobre el checkout provisional (T049); se repiten completas en T098 y antes de T160:
  - T-PAY-OK y T-PAY-DECLINED con tarjeta, PSE, Botón Bancolombia y Nequi.
  - T-PAY-RETRY, T-PAY-MAXATTEMPTS, T-PAY-ORIGIN-LIMIT.
  - T-WIDGET-DOWN, T-CART-BACK, T-CART-BACK-PENDING.
  - T-HOLD-WIDGET-OPEN, T-PSE-ABANDON y T-NO-BYPASS (retorno con `?id=` y guardado como "Anulado" desde el panel).
- [ ] T070 Implementar los correos del pago: Pedido recibido; Pago aprobado (con SMS si hubo opt-in, con enlace de baja en cada SMS; FR-063); Pago rechazado con reintento; Reserva vencida con "Volver a mi carrito". En `core/src/Notifications/Emails/`
- [ ] T071 Documentar con capturas, por cada medio de pago, que los campos de pago viven en un iframe de `checkout.wompi.co`, y anexar la confirmación escrita de Wompi sobre SAQ A, en `ops/compliance/saq-a-widget-evidence.md`. **Si no se confirma, detener el proyecto y activar R-01b**
- [ ] T072 Escribir las pruebas negativas de seguridad: IDOR de pedido de invitada: otra `order_key` → 403 en `order-status`, `payment-attempt` y `order-again`; otra sesión → 403 solo en `order-status` (el reintento desde el correo y "Volver a mi carrito" en otro dispositivo funcionan con la clave); límite de frecuencia en los tres; CSRF sin nonce en `aralia/v1` y Store API; CSP por tipo de página (pago con nonce, públicas con hashes, sin terceros en checkout salvo Turnstile, solo Wompi en order-pay, ninguno en Gracias). En `tests/e2e/security-negative.spec.ts`
- [ ] T073 **PUERTA DE LA FASE 1 DEL PLAN**: todo en verde: T066, T068, T069, T071 y T072, T-API-AUTH registrada y un catálogo de prueba importado sin incompletas publicadas. Registro en el ledger TRACE

**Checkpoint**: el pago es seguro y el catálogo carga. Las historias pueden empezar.

---

## Phase 3: User Story 1 — Comprar una pieza suelta desde el celular (Priority: P1) 🎯 MVP

**Goal**: entrar → elegir → pagar con una mano en 360 px, sin escribir a nadie.
**Independent Test**: con una pieza publicada con stock, una persona de prueba completa la compra en 360 px con cada medio en Sandbox y el pedido aparece pagado.

### Tests (primero)

- [ ] T074 [P] [US1] Escribir el E2E de la compra de una pieza suelta en 360×740 (Labios sin tono → carrito lateral → checkout → widget → Gracias → correo) en `tests/e2e/us1-single-item.spec.ts`
- [ ] T075 [P] [US1] Escribir el E2E del buscador ("corectol" → "corrector") y de "Avísame cuando vuelva" en `tests/e2e/us1-search-and-alerts.spec.ts`
- [ ] T076 [P] [US1] Escribir la prueba de tono: Piel y lápices de Cejas no se agregan sin tono; Labios, Ojos y Pestañas nunca lo piden (SC-003). En `tests/e2e/us1-tone-rules.spec.ts`
- [ ] T077 [P] [US1] Escribir las pruebas unitarias de envío por ciudad, precio tachado (14 días) y orden recomendado en `tests/php/unit/{CityRateTest,PriceHistoryTest,RecommendedOrderTest}.php`
- [ ] T078 [P] [US1] Escribir la prueba de payload de analítica: sin PII en claro hacia GA4 ni Meta, `em`/`ph` con hash, `page_location` sin `key=`, ningún tag en checkout, order-pay ni Gracias, y `purchase` una sola vez (SC-012). En `tests/php/integration/AnalyticsPayloadTest.php`

### Implementation

- [ ] T079 [US1] Implementar el selector de tono, `localStorage` (`aralia_tone`) y el rechazo en el servidor de una pieza variable sin variación en `core/src/Tone/` y `core/blocks/tone-selector/`
- [ ] T080 [US1] Implementar el orden recomendado (afinidad de tono y look → disponible → `total_sales`; variante `?tono=`) en `core/src/Catalog/RecommendedOrder.php`
- [ ] T081 [P] [US1] Implementar el precio tachado con historial de 14 días o más (RN-05) en `core/src/Catalog/PriceHistory.php`
- [ ] T082 [P] [US1] Implementar `GET /aralia/v1/availability` (cacheable 60 s, purga por etiqueta) y la hidratación en el navegador en `core/src/Rest/Availability.php` y `theme/assets/js/availability.js`
- [ ] T083 [US1] Crear el bloque de tarjeta de producto: swatch antes del nombre, etiquetas Nuevo (21 días), Últimas unidades (3 o menos, real) y Agotado, botón "Agregar", y selector de tono solo en Piel y lápices de cejas. En `core/blocks/product-card/`
- [ ] T084 [US1] Crear la plantilla de listado: encabezado con foto, propuesta y conteo; Product Collection + Product Filters (marca, color, look, tono, precio, disponibilidad) con URL compartible; orden. En `theme/templates/archive-product.html` y `theme/templates/taxonomy-product_cat.html`
- [ ] T085 [US1] Crear la ficha en `theme/templates/single-product.html` y `core/blocks/`:
  - Galería, tono con swatch y disponibilidad real.
  - Descripción, cómo se usa, ingredientes e INVIMA si existe.
  - "Combina con", "Cómo llega", compartir y botón fijo en móvil con el precio.
  - Barra de progreso hacia el envío gratis, solo si hay monto configurado (FR-044).
- [ ] T086 [US1] Implementar las reglas del carrito: precio siempre del catálogo, tope de 3 unidades iguales y sesión de invitada de 30 días (`wc_session_expiration`). En `core/src/Cart/CartRules.php`
- [ ] T087 [P] [US1] Implementar las alternativas "del mismo tipo" (misma subcategoría y un color en común, con stock, ordenadas por ventas) en `core/src/Catalog/Alternatives.php`
- [ ] T088 [US1] Crear el carrito lateral con la Store API en `core/blocks/side-cart/`:
  - Líneas con tono y cantidad; quitar.
  - "Complétala" con 2 piezas, aviso de pieza agotada con alternativa y barra de envío gratis solo si hay monto configurado.
  - Código plegado; "Pagar" con el total y sellos de pago.
- [ ] T089 [US1] Implementar los campos del checkout con la Additional Checkout Fields API (ciudad con autocompletado DANE, indicaciones en texto plano con prueba de carga XSS, términos obligatorios con declaración de mayoría de edad, novedades sin marcar) y el widget de Turnstile del lado del navegador que envía el token en `extensions.aralia`. Reemplaza el checkout provisional T049. En `core/src/Checkout/Fields.php`, `core/assets/checkout-turnstile.js` y `core/assets/dane-municipios.json`
- [ ] T090 [US1] Implementar el método de envío `aralia_city_rate` (10 municipios metropolitanos y tarifa nacional; una ciudad fuera de la lista bloquea el pago) en `core/src/Shipping/CityRate.php`
- [ ] T091 [US1] Crear la página Gracias sin terceros, con consulta a `order-status`, tiempos y qué sigue, en `theme/templates/order-confirmation.html` y `core/src/Checkout/ThankYou.php`
- [ ] T092 [US1] Ofrecer crear una cuenta opcional después de comprar y recuperar el carrito persistente al iniciar sesión (FR-045; la página de cuenta completa es P1) en `core/src/Checkout/PostPurchaseAccount.php`
- [ ] T093 [US1] Implementar el índice de búsqueda tolerante a errores y `GET /aralia/v1/search/suggest` en `core/src/Search/` y `core/src/Rest/SearchSuggest.php`
- [ ] T094 [US1] Implementar "Avísame cuando vuelva" en `core/src/Rest/StockAlert.php` y `core/src/Notifications/StockAlerts.php`:
  - Turnstile, consentimiento y doble confirmación por correo.
  - Código antes de cualquier SMS y límite de 3 SMS por número al día.
- [ ] T095 [US1] Implementar la home en `theme/patterns/home-*.php` y `core/src/Admin/HomeSettings.php`:
  - Hero editable y barra superior apagable.
  - 7 categorías, 6 más vendidos (ventas reales), 6 nuevos y "Cómo llega".
  - Galería curada de fotos de pedidos, sin guías ni etiquetas (G-20).
  - Formulario de lista anticipada y pie con medios de pago, políticas y redes.
- [ ] T096 [US1] Implementar la lista de acceso anticipado (`POST /aralia/v1/early-access` con `turnstile_token`, doble confirmación, sincronización con Brevo y evento de servidor `join_early_access`) en `core/src/Rest/EarlyAccess.php`
- [ ] T097 [US1] Implementar la analítica en `core/src/Analytics/`:
  - Banner de consentimiento.
  - `gtag` y Pixel solo en páginas públicas y sin advanced matching.
  - Eventos de navegador: `view_item_list`, `view_item`, `select_tone`, `add_to_cart`, `view_cart`.
  - Eventos de servidor con `event_id`: `begin_checkout`, `add_payment_info`, `purchase`.
  - `client_id`, `fbp` y `fbc` solo con consentimiento.
- [ ] T098 [US1] **PUERTA DE LA FASE 2 DEL PLAN** en `tests/load/smoke.js`, `lighthouserc.json` y `ops/compliance/phase-gates.md`:
  - Lighthouse en home, listado y ficha, incluido el efecto de `?tono=` (B-10).
  - axe-core, k6 de humo 3× y **T-CSP-CACHE**.
  - Repetición completa de T069 y T072 sobre el checkout definitivo.
  - **Prueba con una persona** que compra una pieza sin ayuda en 360 px con una mano (SC-009).

**Checkpoint**: US1 funciona sola. Es el MVP.

---

## Phase 4: User Story 2 — Look completo de una sección con "Agregar todo" (Priority: P2)

**Goal**: un set completo al carrito en 2 toques, con tono una sola vez, y quitar o cambiar piezas.
**Independent Test**: con una sección y un set publicados, se lleva el set desde la home en 2 toques, se cambia una pieza, se quita otra y se paga.

### Tests (primero)

- [ ] T099 [P] [US2] Escribir las pruebas de integración de "Agregar todo" (`tone_required`, `invalid_swap`, `soldout` con alternativa y precio recalculado en el servidor) en `tests/php/integration/AddGroupSetTest.php`
- [ ] T100 [P] [US2] Escribir la prueba unitaria del precio de los sets (suma + ajuste; ángulo de valor solo si Y < X; aviso de sets afectados) en `tests/php/unit/SetPricingTest.php`
- [ ] T101 [P] [US2] Escribir el E2E: 2 toques desde la home, tono una vez, quitar sin romper el grupo, cambiar el gloss y "Disponible con cambio". En `tests/e2e/us2-sets.spec.ts`

### Implementation

- [ ] T102 [US2] Crear el CPT `aralia_set` con su metabox (para quién, pasos, alternativas, ajuste, imagen) en `core/src/Sets/SetPostType.php`
- [ ] T103 [US2] Implementar el precio y el estado de los sets (`available`, `available_with_change`, `soldout`) y el aviso de sets afectados al cambiar el precio de un producto en `core/src/Sets/Pricing.php`
- [ ] T104 [US2] Implementar los sets fijos de Cejas y Labios (producto simple con `_ara_components`, validación de marca no repetida RN-02 y aviso de valor) en `core/src/Sets/FixedSets.php`
- [ ] T105 [US2] Implementar "Agregar todo" como extensión de la Store API (`cart/extensions`, `namespace: aralia`, fuente `set`) en `core/src/Cart/AddGroup.php`
- [ ] T106 [US2] Mostrar la agrupación visual por `_ara_group_id` en el carrito lateral y en el pedido (la hoja de empaque base T048 ya agrupa) en `core/blocks/side-cart/` y `core/src/Orders/ItemDisplay.php`
- [ ] T107 [US2] Crear el bloque de set y la página de sección (Cambiar piezas solo en sets sueltos; selector de tono en la misma página) en `core/blocks/set-card/` y `theme/templates/taxonomy-aralia_seccion.html`
- [ ] T108 [US2] Agregar a la home y la ficha las tarjetas de Principiantes y Makeup no makeup, los sets de Cejas y Labios con la foto del empaque, y "Aparece en estos sets" con un toque. En `theme/patterns/` y `theme/templates/single-product.html`
- [ ] T109 [P] [US2] Implementar el aviso de set agotado como un aviso por cada pieza agotada (G-11) y el evento de servidor `add_set` en `core/src/Notifications/StockAlerts.php` y `core/src/Analytics/ServerEvents.php`

**Checkpoint**: US1 y US2 funcionan cada una por su lado.

---

## Phase 5: User Story 3 — Full face por marca (Priority: P3)

**Goal**: al entrar a Full face, ver el look de cada marca ya armado y llevarlo entero.
**Independent Test**: con 2 marcas elegibles hay una tarjeta por marca; al agotar piezas, la marca desaparece; con menos de 2, la sección se oculta del menú y de la home.

### Tests (primero)

- [ ] T110 [P] [US3] Escribir las pruebas unitarias de `FullFace\Builder` en `tests/php/unit/FullFaceBuilderTest.php`:
  - Mínimo de 4 pasos y de 2 marcas; pieza más vendida; pins.
  - Pasos faltantes sin completar con otra marca.
  - Tono agotado e invalidación de la caché.
- [ ] T111 [P] [US3] Escribir el E2E de RF-F1 a RF-F9: URL estable, tono una vez, Cambiar piezas de la misma marca y paso, y ocultamiento automático. En `tests/e2e/us3-fullface.spec.ts`

### Implementation

- [ ] T112 [US3] Implementar `FullFace\Builder` con caché e invalidación por `CacheInvalidator` en `core/src/FullFace/Builder.php`
- [ ] T113 [US3] Crear la pantalla de administración de Full face (visible, orden, destacadas máx. 3, pins por paso) en `core/src/Admin/FullFaceScreen.php`
- [ ] T114 [US3] Crear las rutas `/full-face` y `/full-face/<marca>` y sus plantillas, y ocultar la sección del menú y la home con menos de 2 marcas, en `core/src/FullFace/Routes.php` y `theme/templates/`
- [ ] T115 [P] [US3] Implementar `GET /aralia/v1/fullface` y `GET /aralia/v1/fullface/{brand}` en `core/src/Rest/FullFace.php`
- [ ] T116 [US3] Añadir la fuente `fullface` a "Agregar todo" (tono una vez; `omit_steps`/`swaps` de la misma marca y paso; `skipped: tone_soldout` con alternativas) en `core/src/Cart/AddGroup.php`
- [ ] T117 [US3] Crear el bloque Full face de la home con las 3 marcas destacadas en `theme/patterns/home-fullface.php`

**Checkpoint**: US1 a US3 funcionan cada una por su lado.

---

## Phase 6: User Story 4 — Comprar para regalar (Priority: P4)

**Goal**: pedido de regalo con mensaje, destinataria y empaque sin precios.
**Independent Test**: una compra con "Es un regalo" muestra en la hoja de empaque el mensaje exacto, la destinataria y "sin precios".

- [ ] T118 [P] [US4] Escribir las pruebas de los campos de regalo (90 caracteres, declaración obligatoria, carga XSS mostrada escapada) en `tests/php/integration/GiftFieldsTest.php` y `tests/e2e/us4-gift.spec.ts`
- [ ] T119 [US4] Implementar los campos de regalo (destinataria, celular, mensaje de 90 caracteres en texto plano, fecha, declaración de autorización y uso solo para la entrega) en `core/src/Checkout/GiftFields.php`
- [ ] T120 [US4] Extender la hoja de empaque base (T048) con los datos de regalo y la marca "empaque sin precios" en `core/src/Orders/PackingSlip.php`
- [ ] T121 [P] [US4] Crear la página del menú Regalos ("Llega empacada, lista para regalar.") y el evento de servidor `gift_mode_on` en `theme/templates/page-regalos.html` y `core/src/Analytics/ServerEvents.php`

---

## Phase 7: User Story 5 — Neceser Aralía personalizado (Priority: P5)

**Goal**: neceser con nombre en vinilo y piezas elegidas, listo para la Cricut.
**Independent Test**: el pedido de un neceser muestra en el panel el texto exacto y el color, y el aviso de no cambio apareció antes de pagar.

- [ ] T122 [P] [US5] Escribir las pruebas del vinilo (14 caracteres, lista blanca, emojis y HTML rechazados), del máximo de 6 piezas y de `no_return_ack` en `tests/php/unit/NeceserValidationTest.php` y `tests/e2e/us5-neceser.spec.ts`
- [ ] T123 [US5] Crear el producto neceser (`_ara_kind = neceser`) y el bloque constructor (tamaño, texto, color, piezas, "Agregar todo" desde un look sugerido, total) en `core/src/Neceser/` y `core/blocks/neceser-builder/`
- [ ] T124 [US5] Añadir la fuente `neceser` a "Agregar todo" y el aviso de no cambio antes de pagar (FR-042) en `core/src/Cart/AddGroup.php` y `core/src/Checkout/NoReturnNotice.php`
- [ ] T125 [US5] Mostrar el texto exacto, el color y el tipo del neceser en el pedido y en la hoja de empaque en `core/src/Orders/PackingSlip.php`

---

## Phase 8: User Story 6 — Surprised bag (Priority: P6)

**Goal**: bolsa sorpresa por tipo, sin contenido visible.
**Independent Test**: una compra por cada tipo llega al panel con el tipo y descuenta el stock de la variación.

- [ ] T126 [P] [US6] Escribir la prueba de que el contenido no se expone en HTML, Store API ni REST y de que el stock se descuenta por variación en `tests/php/integration/SurprisedBagTest.php`
- [ ] T127 [US6] Configurar la Surprised bag como producto variable por `pa_tipo_bolsa` y crear la plantilla de su sección en `core/src/Catalog/SurprisedBag.php` y `theme/templates/`

- [ ] T128 [US6] **PUERTA DE LA FASE 3 DEL PLAN** (con US2, US3, US4, US5 y US6 terminadas): cada sección con al menos un set vendible de punta a punta, T111 (RF-F1…F9) en verde y **SC-001**: 5 de 5 personas de prueba compran un look completo desde un enlace de Instagram en el celular, sin ayuda; registro en `ops/compliance/phase-gates.md`
---

## Phase 9: User Story 7 — Operar la tienda (Priority: P7)

**Goal**: pedidos que recorren sus estados, mensajes automáticos y panel útil.
**Independent Test**: un pedido de prueba pasa por recibido → pagado → empacado → despachado y la clienta recibe los 4 mensajes sin intervención, salvo empacar y despachar.

### Tests (primero)

- [ ] T129 [P] [US7] Escribir el E2E del recorrido completo de estados y mensajes en `tests/e2e/us7-operations.spec.ts`
- [ ] T130 [P] [US7] Escribir las pruebas de reembolsos y reversiones (total, parcial, reversión, restock) y de la resolución de `ara-nostock` en `tests/php/integration/RefundLogTest.php`

### Implementation

- [ ] T131 [US7] Implementar las acciones del panel: empacado con foto (MIME real), despachado con transportadora y guía, entregado manual y automático a los 10 días. En `core/src/Rest/Admin/OrderActions.php`
- [ ] T132 [US7] Implementar los correos Empacado (con foto), Despachado (+SMS con enlace de baja), Pagado sin stock (con contacto) y Pieza disponible (una sola vez; con enlace de baja si va por SMS) en `core/src/Notifications/Emails/`
- [ ] T133 [US7] Implementar "Resolver pedido" para `ara-nostock` (reemplazo de igual o menor precio con reembolso parcial registrado, o reembolso total) y su runbook en `core/src/Rest/Admin/ResolveNoStock.php` y `ops/runbooks/nostock.md`
- [ ] T134 [US7] Implementar el registro de reembolsos y reversiones (sin llamar a Wompi) y su runbook en `core/src/Rest/Admin/RefundLog.php` y `ops/runbooks/refunds-reversals.md`. El runbook incluye qué hacer ante un VOIDED bloqueado sobre `ara-shipped`, `completed` o `ara-nostock` (L-07)
- [ ] T135 [US7] Crear la lista de pedidos por estado con filtros en `core/src/Admin/OrdersScreen.php`
- [ ] T136 [US7] Crear el escritorio de KPIs en `core/src/Admin/Dashboard.php`:
  - Sets más vendidos: sets fijos por producto; sets de piezas sueltas y Full face por `_ara_set_id` (y marca) en pedidos pagados (research R-14).
  - Rechazos por medio, carritos abandonados y reservas vencidas.
  - Tasa de `ara-nostock` (RR-6, RR-7) y stock bajo.
  - Visitas y conversión de la GA4 Data API, con una cuenta de solo lectura.
- [ ] T137 [P] [US7] Implementar la conciliación semanal del reporte de Wompi contra los pedidos (`wp aralia wompi reconcile-report`) en `core/src/Cli/ReconcileReport.php`
- [ ] T138 [US7] Implementar el monitor de scripts (src, inline normalizado, contenido de `widget.js`, cabeceras) y el pedido centinela mensual aislado, con su runbook, en `tools/script-monitor/` y `ops/runbooks/sentinel.md`
- [ ] T139 [P] [US7] Implementar las purgas de R-23 y `wp aralia anonymize` para staging en `core/src/Privacy/Purge.php` y `core/src/Cli/AnonymizeCommand.php`:
  - Avisos de stock: 90 días después de enviados.
  - IP y `client_id`: 90 días.
  - Regalo: 90 días después de la entrega.
  - Exportaciones: 24 h.
- [ ] T140 [P] [US7] Implementar los derechos de la titular (consultar, corregir, suprimir; plazos de 10 y 15 días hábiles; marca de reclamo en trámite) y su runbook en `core/src/Privacy/DataSubject.php` y `ops/runbooks/data-subject-requests.md`
- [ ] T141 [US7] Configurar UpdraftPlus Premium (BD diaria cifrada, archivos semanales, almacenamiento externo con 2FA), escribir `ops/runbooks/restore.md` (incluye recuperar los secretos desde el gestor de contraseñas) y **probar una restauración en staging** (RTO ≤ 8 h)
- [ ] T142 [P] [US7] Implementar la exportación semanal cifrada del registro de actividad fuera del sitio en `core/src/Security/AuditExport.php`
- [ ] T143 [US7] **PUERTA DE LA FASE 4 DEL PLAN**: T129 en verde; SC-012 (una compra de prueba aparece una sola vez en GA4 y en Meta, verificado en sus consolas); restauración de T141 dentro del RTO; **simulacro de alerta**: cambiar un script en staging y comprobar que el monitor (T138) alerta a Sara. Registro en `ops/compliance/phase-gates.md`

**Checkpoint**: puerta de la fase 4 del plan (US7 de punta a punta, SC-012 sin duplicados, restauración probada).

---

## Phase 10: User Story 8 — Confianza antes de pagar (Priority: P8)

**Goal**: páginas legales y de confianza completas y visibles.
**Independent Test**: todas las páginas están publicadas y enlazadas desde el pie y el checkout, y los datos del vendedor son visibles.

- [ ] T144 [P] [US8] Escribir la prueba de que todo formulario con correo o celular registra el consentimiento y de que el checkout no avanza sin aceptar los términos, en `tests/e2e/us8-consent.spec.ts`
- [ ] T145 [US8] Crear las páginas de términos, privacidad (contenido mínimo de FR-074), cambios y garantías (retracto y excepción por higiene), envíos, quiénes somos, Cómo llega y Ayuda, más el contacto solo para pedidos pagados, en `theme/templates/` y `theme/patterns/legal-*.php`. Los textos son del abogado
- [ ] T146 [US8] Mostrar los datos del vendedor y los sellos de pago seguro en el pie y en el embudo en `theme/parts/footer.html`
- [ ] T147 [P] [US8] Escribir la política "no recibimos datos de tarjeta por WhatsApp, redes, correo ni teléfono" y las respuestas modelo para atención (FR-083a) en `ops/runbooks/customer-replies.md`

---

## Phase 11: Polish & apertura (transversal)

**Purpose**: controles operativos T0, verificación final y sign-off.

- [ ] T148 Escribir el plan de respuesta a incidentes (escenarios, contactos, aislar y restaurar, aviso a la SIC y a las titulares, nota de causa raíz) y hacer un simulacro de mesa en `ops/runbooks/incident-response.md`
- [ ] T149 [P] Escribir los runbooks de parcheo (SLA de 7 días; emergencia: ocultar el medio de pago) y de rotación de llaves (incluye `ARALIA_PII_KEY` con re-cifrado) en `ops/runbooks/patching.md` y `ops/runbooks/rotate-keys.md`
- [ ] T150 Completar el inventario de scripts con la CSP en Report-Only durante 2 semanas, con cada medio en Sandbox **y en producción**, y pasar la CSP a modo aplicado, en `ops/script-inventory.md`
- [ ] T151 Obtener y archivar el escaneo ASV aprobado del dominio en `ops/compliance/asv/`
- [ ] T152 Ejecutar OWASP ZAP baseline contra staging (sin hallazgos altos), k6 a 10× (`tests/load/peak.js`), Lighthouse y axe finales; resultados en `ops/compliance/pre-launch-tests.md`
- [ ] T153 Obtener la revisión humana independiente del camino de pago (guard, `Orders/`, `Checkout/`, `Cart/`, `Rest/`) con evidencia en el ledger TRACE
- [ ] T154 [P] Escribir la prueba de contenido de las reglas de marca (sin "colombian", sin "kit", sin "!!", sin el número de marcas) y hacer la revisión manual de textos en `tests/php/integration/BrandRulesTest.php`
- [ ] T155 Cerrar los pendientes legales con el abogado: política de datos, contratos de transmisión, RNBD, Ley 2300 y efecto del "Entregado" automático sobre el retracto (B-09). Si el abogado lo pide, ajustar `aralia_auto_complete_days`
- [ ] T156 Desactivar la sincronización de compatibilidad de HPOS tras los E2E de los cuatro medios con HPOS como único almacenamiento (R-03)
- [ ] T157 Cargar las llaves de producción y la URL de eventos de producción, hacer una compra real pequeña y un retiro a la cuenta bancaria (RF-51), retirar las llaves de Sandbox y borrar pedidos, usuarios y datos de prueba (6.5.6); repetir T069 con los medios reales
- [ ] T158 [P] Publicar `/.well-known/security.txt` en `theme/` o en la regla de reescritura de `core/src/Security/SecurityTxt.php`
- [ ] T159 Completar el cuestionario SAQ A v4.0.1 y la atestación de cumplimiento del comercio (criterios de elegibilidad E1–E7 y requisitos aplicables, con referencia a la evidencia de `ops/compliance/`) en `ops/compliance/saq-a.md`
- [ ] T160 Completar `ops/go-live-checklist.md` (incluye las condiciones de T166; 13.1 del documento fuente + 6.5.6 + AOC + ASV + evidencia y cuestionario SAQ A) y conseguir la **firma de Sara** (sign-off T0)
- [ ] T161 Cambiar la bio de redes de "Pronto, compra en línea" a "Compra en línea" el día de la apertura (documento fuente 1.3, frase de marca; spec FR-084)

---

## Dependencies & Execution Order

### Dependencias entre fases

- **Setup (F1)**: sin dependencias.
- **Fundacional (F2)**: depende de F1 y **bloquea todas las historias** hasta la puerta T073.
- **US1 (F3)**: depende de T073. Es el MVP.
- **US2 (F4)**: depende de T073. Usa el carrito lateral de US1 (T088) para mostrar los grupos; si se hace en paralelo, T106 espera a T088.
- **US3 (F5)**: depende de US2 (T105, "Agregar todo").
- **US4 (F6)**: depende de T073 y del checkout de US1 (T089).
- **US5 (F7)**: depende de US2 (T105); extiende la hoja de empaque base (T048).
- **US6 (F8)**: depende de T073 y del carrito y checkout de US1 (T088, T089).
- **US7 (F9)**: depende de T073 y de la hoja de empaque base (T048).
- **US8 (F10)**: depende de T073 y del checkout de US1 (T089); los textos dependen del abogado (T155).
- **Puerta de la fase 3 del plan** (última tarea de F8): depende de US2, US3, US4, US5 y US6.
- **Pulido (F11)**: depende de todas las historias que entren en la apertura.

### Dentro de cada historia

Pruebas (que fallen) → modelos o servicios → endpoints → bloques y plantillas → integración. Todo commit pasa por un PR aprobado por la revisora; lo hace cumplir `ops/deploy.sh` (CODEOWNERS no bloquea con el plan Free; T011).

### Oportunidades de paralelismo

- F1: T004, T006, T007, T010 y T013–T017 en paralelo.
- F2: tareas [P] (pueden ir en paralelo una vez cumplidas las dependencias de su bloque): T022, T024, T025, T027, T028, T030, T031, T034, T035, T037, T040, T046, T051, T052, T055, T057, T062, T064, T065, T066, T067. T029 espera a T028; en el camino de pago, las [P] esperan a T050 y T054.
- Después de T073: US1, US2 y US7 pueden avanzar en paralelo (US2 espera a T088 solo para T106); US6 y US8 empiezan cuando están T088 y T089; US3 y US5 siguen a US2.
- En F2, T028 (Brevo) va antes de T029 (alertas por SMS).

### Ejemplo de paralelo: User Story 1

```bash
# Pruebas primero, en paralelo:
T074 tests/e2e/us1-single-item.spec.ts
T075 tests/e2e/us1-search-and-alerts.spec.ts
T076 tests/e2e/us1-tone-rules.spec.ts
T077 tests/php/unit/{CityRateTest,PriceHistoryTest,RecommendedOrderTest}.php
# Luego servicios independientes en paralelo:
T081 core/src/Catalog/PriceHistory.php
T082 core/src/Rest/Availability.php
T087 core/src/Catalog/Alternatives.php
```

---

## Implementation Strategy

### MVP primero

1. Fase 1 (Setup) y Fase 2 (Fundacional) hasta la **puerta T073**: pago seguro y verificado.
2. Fase 3 (US1): compra de una pieza suelta.
3. **Parar y validar** con una persona de prueba en 360 px (SC-009).

### Entrega incremental

US1 → US2 → US3 (diferenciador) → US4 + US6 → US5 → US7 → US8 → apertura (F11). Cada incremento pasa por los gates T0 de `logidev` antes de seguir.

### Bloqueos externos (no detienen el desarrollo, sí la apertura)

Cuenta Wompi aprobada y AOC (T015), confirmación de Wompi sobre SAQ A (T071), abogado (T145, T155), fotos y swatches reales e inventario completo, operador y tarifas de envío (`aralia_rates`), figura legal, IVA y datos del vendedor (T146).

---

## Notes

- Total: **167 tareas**: Setup 19, Fundacional 60, US1 25, US2 11, US3 8, US4 4, US5 4, US6 3, US7 15, US8 4, Pulido 14.
- Las bajas de la revisión de la iteración 3 quedaron así: L-01 en T056 y T068; L-02 en T065 y T069; L-03 en T029; L-06 en T045; L-07 en T134. L-04, L-05 y L-08 ya se corrigieron en los artefactos.
- Commit al cerrar cada tarea o grupo lógico; ninguna tarea del camino de pago se mezcla en el mismo commit con cambios de otra área.
