# Implementation Plan: Aralía Beauty Store — Tienda en línea (P0)

**Branch**: `001-aralia-store-p0` | **Date**: 2026-11-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/001-aralia-store-p0/spec.md` + anexos T0 ([threat-model.md](./threat-model.md) v1.1, [data-classification.md](./data-classification.md), [compliance-mapping.md](./compliance-mapping.md))

**Tier TRACE**: T0 · **Iteración del plan**: 4 (ver §Registro de iteraciones)

## Summary

Tienda WooCommerce de producto propio, móvil primero, que **propone** looks completos (secciones curadas, sets, Full face por marca autogenerado desde el catálogo) y permite comprarlos con un toque ("Agregar todo" vía extensión de la Store API), pidiendo tono solo en Piel y lápices de Cejas. El pago usa el **plugin oficial de Wompi 3.2.1** para registrar el medio de pago y crear el pedido, y un mu-plugin propio, **`aralia-wompi-guard`**, que toma el control de lo crítico: abre el widget alojado por Wompi con una referencia única por intento, recibe y verifica los eventos antes que el plugin (checksum, API, monto, moneda, referencia, idempotencia), impone una máquina de estados sin retrocesos, fuerza TLS verificado, saca las llaves de la base de datos y concilia pagos. El stock se descuenta al crear el pedido y se libera con un vencimiento propio de 30 minutos (extensible si Wompi informa un pago en proceso). Lógica de negocio en `aralia-core`, presentación en el tema de bloques `aralia`, y los controles operativos PCI SAQ A y de la Ley 1581 como entregables de `ops/`.

## Technical Context

**Language/Version**: PHP 8.3 (mínimo 8.2 por el plugin de Wompi) · JavaScript ES2022 (Interactivity API; `@wordpress/scripts` para bloques) · CSS con `theme.json`

**Primary Dependencies**: WordPress 7.1.x · WooCommerce 11.2.x (HPOS, checkout de bloques, Store API + ExtendSchema, Product Collection/Filters, Additional Checkout Fields API, Action Scheduler) · Wompi Portal de Pagos **3.2.1 fijado** · Wordfence · LiteSpeed Cache · UpdraftPlus Premium · GSAP (auto-alojado) · Cloudflare Turnstile (integración propia) · Brevo API · GA4 (gtag + Measurement Protocol + Data API de solo lectura) · Meta Pixel + Conversions API · libsodium (PHP)

**Storage**: MySQL/MariaDB de Hostinger: WordPress/WooCommerce con HPOS autoritativo + **6 tablas propias** `aralia_*` ([data-model.md](./data-model.md) §4). Medios en `uploads/` (sin ejecución de PHP) servidos por CDN. Secretos y llave de cifrado de datos personales en `aralia-secrets.php` fuera de `public_html` (R-16).

**Testing**: PHPUnit (WP test suite vía `@wordpress/env`), integración REST y Store API, Playwright E2E 360×740 con Wompi Sandbox + axe-core, prueba de contrato del guard, PHPCS (WordPress-Extra + seguridad), PHPStan 6, Lighthouse CI, gitleaks, OWASP ZAP baseline y k6 (10×) contra staging.

**Target Platform**: Hostinger Business/Cloud (LiteSpeed, PHP 8.3, SSH, staging, cron real, CDN). iOS Safari 16+, Chrome Android 110+, escritorio evergreen.

**Project Type**: Web (WordPress: tema + plugin + mu-plugins) con operaciones documentadas (`ops/`).

**Performance Goals**: LCP < 2,5 s móvil 4G y Lighthouse Rendimiento ≥ 85 (mediana de 3) en home, listado y ficha (SC-008); sugerencias de búsqueda < 300 ms en servidor; "Agregar todo" < 800 ms en servidor; 10× el tráfico promedio sin errores en checkout (k6).

**Constraints**: SAQ A (ningún dato de pago en el DOM ni en el servidor de la tienda; scripts controlados en todo el sitio) · sin terceros en checkout (salvo Turnstile, excepción aprobada en FR-073), order-pay (solo Wompi) y Gracias (ninguno) · 360 px con una mano · AA · una administradora permanente · operación proporcional a un negocio unipersonal · reglas de marca 1.3.

**Scale/Scope**: < 2.000 piezas, 7 categorías, 8 secciones, decenas de pedidos/día; 8 historias de usuario, ~90 FR.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

La constitución del repo fue **ratificada v1.0.0 el 2026-11-13** y **enmendada a v1.1.0** el mismo día (despliegue local por plan Free de GitHub; régimen transitorio de la revisora) y la **v1.2.0 está propuesta** (revisión humana de todo el repo; lanzador de confianza) a la espera de la aprobación de Sara (ver §Registro de iteraciones, iteración 4). La constitución provisional C1–C8 / T0-1…T0-5 (R-20) coincide con I–X. Un ✅ aquí significa "el diseño lo cubre"; las verificaciones que dependen de pruebas en Sandbox se listan como puertas de fase, no como supuestos.

| # | Principio / regla | Cómo lo cumple el plan | Post-diseño (iter. 2) |
|---|-------------------|------------------------|-----------------------|
| C1 | Tienda normal, bien hecha | Componentes nativos de WooCommerce; lógica propia solo donde no hay nativo | ✅ |
| C2 | Proponer antes que buscar | Home y secciones alimentadas por catálogo (FR-012), `FullFaceBuilder`, sets | ✅ |
| C3 | Un toque para un look | "Agregar todo" por `cart/extensions` de la Store API (contracts/rest-api.md §1) | ✅ |
| C4 | Tono solo donde importa | Productos variables por `pa_tono` + validación en servidor (R-08) | ✅ |
| C5 | Escasez y urgencia solo reales | Estado dinámico por Store API sobre HTML cacheado (R-21); `total_sales` real; precio tachado con historial | ✅ |
| C6 | Cada compra abre la siguiente | Complétala, avisos de stock | ✅ |
| C7 | Autoservicio | Mensajes automáticos; contacto solo para pedidos pagados con problema (incluye `ara-nostock`) | ✅ |
| C8 | Reglas de marca 1.3 | Prueba de contenido + revisión en checklist de apertura | ✅ |
| T0-1 | Ningún dato de pago en la tienda | Widget alojado por Wompi (R-01) + evidencia de iframe por medio de pago como **puerta de la fase 1** (R-19); si falla → R-01b | ✅ (condicionado a la puerta) |
| T0-2 | Pago confirmado solo en servidor y con verificación doble | Guard: checksum + API + monto/moneda/referencia + idempotencia + máquina de estados (R-02, R-05) | ✅ |
| T0-3 | Secretos fuera del navegador, la BD y el repo | R-16, H6 | ✅ |
| T0-4 | Threat model y mapeo de controles cubiertos | §Trazabilidad de seguridad | ✅ |
| T0-5 | Revisión independiente del camino de pago | Gates de `logidev` T0 + revisora humana nombrada antes de abrir (R-15) | ✅ |

## Project Structure

### Documentation (this feature)

```text
specs/001-aralia-store-p0/
├── spec.md · threat-model.md (v1.2) · data-classification.md · compliance-mapping.md (con §7)
├── plan.md                  # Este archivo (iteración 4)
├── research.md              # Fase 0 (R-01…R-24, v3)
├── data-model.md            # Fase 1 (v3)
├── quickstart.md            # Fase 1
├── contracts/
│   ├── rest-api.md (v3) · wompi-integration.md (v3) · inventory-csv.md · analytics-events.md (v2)
├── checklists/requirements.md
└── tasks.md                 # Fase 2 (/speckit.tasks)
```

### Source Code (raíz: `E-commerce/`)

```text
E-commerce/
├── composer.json · package.json · .wp-env.json
├── phpcs.xml.dist · phpstan.neon · .gitleaks.toml · .gitignore
├── ops/deploy.sh                    # despliegue local (R-15, enmienda 1.1.0)
├── (raíz del repo) .github/workflows/ecommerce-ci.yml · CODEOWNERS · dependabot.yml
├── wp-content/
│   ├── themes/aralia/                # theme.json (tokens claro/oscuro), templates, parts, patterns, fonts, assets/js (GSAP, availability), emails
│   ├── plugins/aralia-core/
│   │   ├── src/
│   │   │   ├── Catalog/              # Taxonomías, atributos, regla de publicación, Importer (CRUD) + Validator, Alternatives, precio tachado
│   │   │   ├── Tone/ · Sets/ · FullFace/ · Neceser/
│   │   │   ├── Cart/                 # Extensión Store API (add-group), agrupación, Complétala, tope, order-again
│   │   │   ├── Checkout/             # Additional Checkout Fields, regalo, declaraciones, Turnstile en Store API
│   │   │   ├── Shipping/             # aralia_city_rate + lista DANE
│   │   │   ├── Orders/               # Estados propios, StateMachine, Expiry (Action Scheduler), stock al crear + liberación de reserva, PartialStock, NoStock resolver, hoja de empaque
│   │   │   ├── Notifications/        # WC_Email propios, Brevo (correo/SMS), avisos de stock, lista anticipada
│   │   │   ├── Search/ · Analytics/ · Admin/ · Rest/ · Cli/ (setup, catalog, baseline, anonymize, wompi replay/reconcile-report)
│   │   │   ├── Security/             # CSP por tipo de página, cabeceras, rate limits atómicos, audit log, alertas (R-22)
│   │   │   ├── Privacy/              # Consentimientos, cifrado PII, derechos de la titular, purgas, anonimización de staging
│   │   │   └── Cache/                # CacheInvalidator (transients + purga LiteSpeed/CDN por etiqueta)
│   │   ├── assets/                   # dane-municipios.json, checkout-turnstile.js
│   │   ├── blocks/                   # (fuente; NO viaja en el paquete: se compila a build/) Carrito lateral, set card, Full face, selector de tono, constructor de neceser, barra superior, Cómo llega
│   │   └── migrations/               # Tablas aralia_* versionadas
│   └── mu-plugins/
│       ├── aralia-wompi-guard/       # Llaves, TLS, order-pay propio, manejador de eventos, conciliación, neutralización de wc-voided (H13), prueba de contrato
│       └── aralia-hardening.php      # DISALLOW_FILE_EDIT (sin DISALLOW_FILE_MODS: se actualiza desde wp-admin), sin XML-RPC, sin enumeración de usuarios, uploads sin PHP
├── tests/php/{unit,integration,contract}/ · tests/e2e/ · tests/load/ (k6) · tests/fixtures/ (eventos Wompi firmados, CSV)
├── tools/script-monitor/             # Scripts, hashes inline, contenido de widget.js, cabeceras
└── ops/
    ├── inventory.md · script-inventory.md · cache-policy.md · baseline-config.md · go-live-checklist.md · alerts.md
    ├── templates/aralia-secrets.sample.php
    ├── compliance/                   # saq-a-widget-evidence, wompi-aoc, responsibility-matrix, accounts-2fa, saq-a, phase-gates, pre-launch-tests, asv/
    └── runbooks/                     # incident-response (SIC + causa raíz), restore, rotate-keys, access, patching (emergencia), refunds-reversals, nostock, duplicate-charge, sentinel, data-subject-requests, customer-replies
```

**Structure Decision**: estructura WordPress estándar con solo código propio versionado; terceros (WordPress, WooCommerce, plugins) instalados y actualizados en Hostinger; Composer solo para pruebas y wp-env. Lógica en `aralia-core` (independiente del tema); el blindaje de pagos es un mu-plugin (no se puede desactivar desde el panel).

## Fases de entrega

| Fase | Contenido | Puerta de salida (verificable) |
|------|-----------|--------------------------------|
| **1. Base** | Ratificar constitución; repo + CI + wp-env; Hostinger (staging limpio, SSH, cron real, PHP 8.3); WooCommerce 11.2 + HPOS (sync activo); `price_num_decimals = 0`; tema con tokens; taxonomías/atributos; importador + validador + regla de publicación; Wompi 3.2.1 en Sandbox + `aralia-wompi-guard` completo (llaves, TLS, order-pay, eventos, verificación doble, estados, stock al crear, vencimiento, conciliación); endurecimiento + Wordfence + 2FA + sin `admin`; inventario, matriz de responsabilidades, AOC solicitado; verificación de SMS de Brevo a Colombia | Todas las pruebas de `contracts/wompi-integration.md` §6 en verde (incluidas **T-PAY-RETRY**, **T-API-AUTH** con decisión registrada en R-02, **T-NO-BYPASS**, T-PAY-DOUBLE, T-PAY-ORIGIN-LIMIT, T-HOLD-*, T-STOCK-*, T-RECONCILE, T-CONTRACT); **evidencia SAQ A del widget** en `ops/compliance/` (si falla → R-01b antes de seguir); catálogo de prueba importado sin incompletas publicadas |
| **2. Tienda** | Home, categorías, listados con filtros y orden recomendado (variantes por tono), ficha, tono, carrito lateral con Complétala, checkout de una página (campos, declaraciones, Turnstile), envíos por ciudad, búsqueda tolerante, "Volver a mi carrito", política de caché aplicada | Persona de prueba compra una pieza sin ayuda en 360 px (SC-009); "corectol" → "corrector"; axe-core sin violaciones AA; Lighthouse ≥ 85 (incluido el efecto de `?tono=`); T-CSP-CACHE; k6 de humo 3× |
| **3. Secciones** | Regalo (US4); sets sueltos y fijos, "Agregar todo", Cambiar piezas, Disponible con cambio, Full face + panel, tono agotado en Full face, Surprised bag, Neceser, alternativas | Cada sección con un set vendible de punta a punta; pruebas RF-F1…F9; **SC-001**: 5 de 5 personas de prueba compran un look completo desde un enlace de Instagram en el celular, sin ayuda |
| **4. Automatización** | Estados propios + hoja de empaque + resolución de `ara-nostock`; correos y SMS P0; avisos de stock; lista anticipada; analítica solo servidor en pago; escritorio con KPIs (incluye visitas y conversión de GA4); CSP aplicada + monitor de scripts y `widget.js`; audit log; matriz de alertas; cifrado PII y purgas; copias externas cifradas; plan de incidentes | Un pedido recorre todos los estados (US7); SC-012 sin duplicados; restauración probada (RTO ≤ 8 h); simulacro de alerta de cambio de script |
| **5. Apertura** | Llaves de producción, compra real y retiro; CSP verificada con dominios de producción por medio de pago; legales; ASV aprobado; ZAP sin altos; k6 10×; revisión humana independiente del camino de pago; checklist de salida (6.5.6); HPOS sin sync; `security.txt`; textos contra reglas de marca | `ops/go-live-checklist.md` completo y firmado por Sara (sign-off T0) |

## Trazabilidad de seguridad (threat model y cumplimiento → plan)

| Requisito | Dónde se implementa | Prueba / evidencia |
|-----------|---------------------|--------------------|
| TM §4.1 Máquina de estados (S-1, S-2, T-8) | `Orders/StateMachine`, guard | T-EVT-REGRESSION, T-EVT-LATE-*, prueba por transición de `data-model.md` §3 |
| TM §4.2 Verificación doble (S-1) | Guard, paso 5 del evento (R-02) | T-EVT-AMOUNT, T-EVT-CURRENCY, T-EVT-API-DOWN |
| T-2 integridad de parámetros | Firma en servidor; comparación evento + API | T-EVT-AMOUNT |
| TM §4.3 Scripts en todo el sitio (E7, T-3, T-10, T-11) | `Security/` CSP por tipo de página, `tools/script-monitor`, `ops/script-inventory.md` | Prueba de CSP por página; simulacro de cambio de `widget.js`; evidencia de iframe (R-19) |
| S-3, E-1, E-2, I-5, I-7 Endurecimiento | `aralia-hardening.php`, Wordfence, `ops/baseline-config.md` | Checklist wp-cli en staging |
| S-6, T-7, D-1, D-2 Anti-abuso | `Security/` rate limits atómicos + Turnstile + rechazos por origen (R-12) | E2E de límites; T-PAY-MAXATTEMPTS; T-PAY-ORIGIN-LIMIT |
| T-12 Anulación desde el panel | Guard (H13) | T-NO-BYPASS |
| T-13 Doble cobro | Guard (R-02 §3b) + runbook | T-PAY-DOUBLE |
| RR-6, RR-7 | KPI tasa de `ara-nostock` en el escritorio | T-PSE-ABANDON, T-HOLD-WIDGET-OPEN |
| S-7 Clickjacking | `frame-ancestors 'self'` | Prueba de cabecera |
| D-3 Picos | Caché (R-21) + CDN | k6 10× (fase 5) |
| D-5 Spam y costo de SMS | Confirmación de correo, luego código SMS; límite por número (R-12) | E2E |
| D-6 Cron | Cron real + alerta de acciones atrasadas (R-06, R-22) | T-HOLD-TIMING |
| D-7 / RR-5 Endpoint de eventos | Registro inmediato, `deferred`, conciliación | T-RECONCILE, T-EVT-API-DOWN |
| R-1, R-3, R-4 Registros | `aralia_payment_events`, `aralia_audit_log`, conciliación semanal | Pruebas de integración |
| I-1 Secretos | R-16 | T-KEYS |
| I-2, E-3 IDOR | `order_key` + límite de frecuencia en order-status, payment-attempt y order-again; además la sesión del pedido en order-status | E2E negativas (T072) |
| I-3 Costo | Meta privada no expuesta | Prueba sobre Store API y REST |
| I-4, FR-073 PII a terceros | Sin etiquetas en pago; hash en CAPI; sin advanced matching | Prueba de CSP y de payload |
| I-6 Exportaciones | Enlaces que caducan en 24 h (R-23) | Prueba |
| T-5 XSS | Listas blancas + escape | Cargas XSS en regalo, vinilo, indicaciones |
| T-6, E-5 Subidas | Validador CSV, MIME real, uploads sin PHP (R-09, R-23) | Pruebas |
| T-9 Cadena de suministro | Terceros gestionados en Hostinger con Wompi sin actualización automática, staging, contrato del guard, SLA (R-15) | CI |
| E-4 Cuentas externas | `ops/compliance/accounts-2fa.md` | Checklist |
| CM §5 ítems 1–7 | R-19, R-11, R-15 | Puertas de fases 1 y 5 |
| CM §5 ítems 8–13 | R-15, R-19, `baseline-config.md` | CI + checklists |
| CM §5 ítems 14–19 | R-17, R-18, R-22, R-23, R-19 | Fases 4–5 |

## Registro de iteraciones

### Iteración 4 — implementación de la Fase 1, enmienda 1.1.0 y propuesta 1.2.0 (2026-11-13)

| Hallazgo | Resolución | Artefacto |
|----------|-----------|-----------|
| GitHub Free no ofrece environments protegidos ni protección de rama en repos privados | Despliegue **local** `ops/deploy.sh`; la llave SSH no vive en GitHub; el CI solo arma el paquete | Constitución 1.1.0, R-15, T009 |
| Gates T0 de la Fase 1, vueltas 2 y 3 (security, code-reviewer, architect + 6 verificaciones adversariales) | Criterio invertido: todo commit desde el último despliegue exige PR aprobado (salvo documentación exenta); lista de revisoras leída del último despliegue; registro de despliegues local; el paquete se compara con `git archive`; CI sin suites omitidas en silencio; cargador de secretos falla cerrado | `ops/deploy.sh`, `ecommerce-ci.yml`, `aralia-secrets-loader.php`, `tests/ops/deploy_test.sh` |
| Gates T0 de la Fase 1, vuelta 4 (security, code-reviewer, architect) | Lanzador de confianza fuera del repo (`~/.aralia-deploy/bin/deploy.sh`) que se actualiza tras cada despliegue verificado; `REVIEW_GENESIS` = commit inicial; `rollback`; `build/` y componentes con lista blanca; destinos y protección de `main` exigidos, con `ALLOW_*` registrados; T166 y T167; RR-8 a RR-10 | `ops/deploy.sh`, threat-model §3, constitución 1.2.0 (propuesta) |

### Iteración 2 — respuesta a la revisión de arquitectura (iteración 1)

| Hallazgo | Resolución | Artefacto |
|----------|-----------|-----------|
| **G-01** liberación de reserva (bloqueante) | Verificado en WC 11.2.1. Stock descontado al crear el pedido + vencimiento propio a creación + 30 min (Action Scheduler) + cancelación nativa desactivada para `wompi` | R-06, data-model §3, T-HOLD-TIMING |
| **R-01** bloques y HPOS (bloqueante) | Verificado: el plugin declara ambos y registra el método para bloques (H11) | R-01, R-03 |
| G-02 navegador cerrado | El guard abre el widget y captura el id en el callback (`payment-attempt`); búsqueda por referencia no documentada → no se usa | R-02, rest-api §8b |
| G-03 PENDING | Extensión de 15 min hasta 2 h; FR-056 y SC-013 actualizados | R-06, spec |
| G-04 reembolsos tras despacho y parciales | Transiciones a `refunded` desde enviado y entregado; parciales sin cambio de estado | data-model §3, FR-070 |
| G-05 nostock parcial | Descuento parcial, `_ara_missing`, resolución en el panel (igual o menor precio) | R-24, rest-api §9 |
| G-06, G-07, T0-1 intercepción, moneda, referencia, verificación doble | Guard en `woocommerce_api_wc_wompi` prioridad 1; manejador del plugin no corre | contracts/wompi §3 |
| G-08 carrito de invitada 30 días | Sesión de WC extendida a 30 días | data-model §3 |
| G-09 "mismo tipo" | `Catalog/Alternatives` | R-09 |
| G-10 tono agotado en Full face | `skipped: tone_soldout` + alternativas | R-07, rest-api §1 |
| G-11 aviso de set agotado | Aviso por cada pieza agotada | data-model §4 |
| G-12 sets fijos | `_ara_components`, precio propio, aviso de valor | R-07, data-model §2 |
| G-13 importación en el panel | Pantalla + importador propio con CRUD | R-09 |
| G-14 regla de publicación | Solo en la transición a `publish`; no despublica por agotamiento | R-09, data-model §1 |
| G-15 widget no carga | Mensaje de marca + reintento a los 8 s | R-10, T-WIDGET-DOWN |
| G-16 carrito tras rechazo | Reintento sobre el mismo pedido; "Volver a mi carrito" desde `cancelled` (H12 verificado: el plugin vacía el carrito) | R-01, R-10, T-CART-BACK |
| G-17 visitas y conversión en el panel | GA4 Data API de solo lectura en el widget | R-14 |
| G-18 Pedido recibido y Entregado | Correo propio; entregado manual o a +10 días | R-13 |
| G-19 AA | axe-core en Playwright | R-15, R-18 |
| G-20 fotos de pedidos | Galería curada manual, sin guías ni etiquetas | tarea de fase 2 |
| G-21 carga | k6 10× en fase 5 | R-18 |
| D-01 dependencia del plugin | Superficie mínima + prueba de contrato + criterio para R-01b | R-01 |
| D-02 estados pagados | Filtros de estados pagados e informes | R-05 |
| D-03 / R-06 HPOS | HPOS autoritativo; sync apagado antes de abrir | R-03 |
| D-04 rate limits | Tabla con incremento atómico | R-12, data-model §4 |
| D-05 Turnstile en bloques | Token en `extensions` + validación en Store API | R-10 |
| D-06 invalidación | `CacheInvalidator` único | R-07, R-21 |
| D-07 cifrado PII | `ARALIA_PII_KEY` en secretos + `subject_hash` HMAC | R-16, R-23 |
| D-08 UpdraftPlus | Premium presupuestado; secretos desde el gestor de contraseñas | R-15, R-17 |
| R-02 payment_complete | Verificado; resuelto con la intercepción | R-01 H3 |
| R-03 retry / referencia | Referencia `<order_id>-<n>` propia del guard; sin spike bloqueante | R-01 H4 |
| R-04 caché | Política de caché | R-21 |
| R-05 CSP incompleta | Directivas completas e inventario con cada medio | R-11 |
| R-07 llave de consulta | T-API-AUTH decide | R-02 |
| R-08 CGNAT | IP como señal blanda; intentos por pedido | R-12 |
| R-09 SMS pumping | Confirmación de correo y código SMS; límite por número | R-12 |
| R-10 staging con PII | Staging limpio o anonimizado | R-17 |
| R-11 cola | Aceptado como RR-5 | threat-model |
| R-12 centavos | `price_num_decimals = 0` + prueba de cupones | R-03 |
| I-01 `failed` | Eliminado; rechazo deja `pending` | R-05, data-model §3 |
| I-02 VOIDED | Regla única en la máquina de estados | data-model §3 |
| I-03 TM con redirección | Threat model v1.1 con el widget; E3 corregido | threat-model, compliance |
| I-04 Turnstile en order-pay | Solo en checkout, excepción en FR-073 | R-11, spec |
| I-05 terceros en Gracias | Gracias sin terceros; `purchase` solo servidor | R-14, analytics |
| I-06 tablas | 6 tablas; componentes en meta | data-model §4 |
| I-07 hook de llaves | Unificado | R-01, contracts/wompi §4 |
| I-08 alcance de conciliación | Unificado (`pending` y `cancelled` ≤ 24 h) | R-02, contracts/wompi |
| I-09 `subject_hash` | Agregado | data-model §4 |
| I-10 neceser | Producto simple con `_ara_kind` | data-model §2 |
| I-11 alineaciones menores | SMS en fase 1; E2E historias 1–8; Docker solo obligatorio en CI; plantilla de secretos en `ops/templates/` | R-13, R-18, estructura |
| I-12 trazabilidad | Tabla rehecha | este plan |
| CM ítem 1 evidencia del iframe | Puerta de la fase 1 | R-19 |
| CM ítem 3 hash de `widget.js` | Monitor del contenido | R-11 |
| CM ítem 11 revisión independiente | Gates de logidev + revisora humana nombrada | R-15 |
| CM 5, 8, 12, 17, 18, 19 | ASV tras cambios, parche de emergencia, sin `admin` y ≥ 12, matriz de alertas, purgas (IP y regalo), causa raíz | R-15, R-19, R-22, R-23 |

### Iteración 3 — respuesta a la revisión de arquitectura (iteración 2)

| Hallazgo | Resolución | Artefacto |
|----------|-----------|-----------|
| **N-01** caminos de bypass del plugin (Alta) | Inventario completo por grep (H13): único `payment_complete` en el manejador de eventos; **estado `wc-voided` + anulación desde el panel** neutralizados; retorno y "Gracias" solo leen | research R-01 H13, contracts/wompi §1/§4, T-NO-BYPASS, T-CONTRACT |
| **N-02** límite por origen (Alta) | > 5 rechazos/h por IP del pedido → desafío visible; > 10/h → bloqueo 1 h; FR-060 aclarado | R-12, spec FR-060, TM D-2, T-PAY-ORIGIN-LIMIT |
| N-03 doble cobro | No se abre un intento con otro PENDING; fila en la máquina de estados; runbook | R-02 §3b, data-model §3, TM T-13, T-PAY-DOUBLE |
| N-04 widget abierto al vencer | Tiempo visible y extensión única hasta 35 min; RR-7 aceptado | R-06 §4b, TM RR-7 |
| N-05 contabilidad de stock | `wc_release_stock_for_order` tras descontar (verificado en WC 11.2.1); hold = 30; `PartialStock` con `_reduced_stock` | R-06, T-STOCK-DOUBLECOUNT, T-STOCK-FLAGS |
| N-06 PSE abandonado | RR-6 aceptado por Sara, con KPI y disparador | TM RR-6, R-02 §2 |
| N-07 caché en picos | Endpoint `availability` cacheable 60 s; k6 de humo en fase 2 | R-21, rest-api §7b |
| N-08 hashes y monitor | Hashes calculados en el render y cacheados con la cabecera; monitor con inline normalizado y severidades | R-11, T-CSP-CACHE |
| N-09 saneamiento y clasificación | Lista blanca de campos del evento; nuevos datos clasificados; `client_id`/`fbp` solo con consentimiento | contracts/wompi §3, data-classification, R-14 |
| N-10 compliance desactualizado | Filas corregidas + §7 "Estado tras el plan" | compliance-mapping |
| N-11 centinela | Pedido centinela mensual en producción, aislado | R-11, runbook sentinel |
| N-12 revisión humana después de abrir | Decidido por Sara: revisión humana obligatoria en cada cambio del camino de pago (CODEOWNERS; con plan Free lo hace cumplir `ops/deploy.sh`) | R-15 |
| B-01, B-02 | Texto del spec alineado (AC 1.4, borde de pasarela caída, FR-060) | spec |
| B-03 | `n` en cada apertura, 1–999, tope de 30 aperturas | R-10, contracts/wompi §3 |
| B-04 | id persistido aunque esté `deferred`; idempotencia por estado verificado | R-02 §2b, contracts/wompi §3 |
| B-05 | APPROVED sobre `cancelled` de más de 24 h: misma ruta con alerta | R-02 §3, data-model §3 |
| B-06 | `ara-nostock → ara-reversed` | data-model §3 |
| B-07 | Flujo del panel en el contrato CSV | contracts/inventory-csv |
| B-08 | `order-status` por POST con sesión | rest-api §8 |
| B-09 | "Entregado" automático vs. retracto: consulta al abogado | R-19 (pendientes legales), tasks |
| B-10 | Medir `?tono=` en Lighthouse | puerta fase 2 |
| B-11 | "Volver a mi carrito" también desde `pending` tras rechazo | R-10, data-model §3 |
| B-12 | SRI solo en scripts propios; `widget.js` por T-10 | TM T-3 |

**Riesgos residuales**: RR-1 a RR-7 aceptados por Sara Andrade el 2026-11-03 (threat-model §3).

## Riesgos del plan

| Riesgo | Impacto | Mitigación |
|--------|---------|------------|
| La evidencia SAQ A del widget no se confirma (campos en el DOM o Wompi no lo respalda) | Cambiaría a SAQ A-EP | Puerta de la fase 1; activar R-01b (redirección) antes de construir la tienda |
| Una actualización del plugin cambia hooks u opciones | Rompe el pago o el blindaje | Versión fija + prueba de contrato + staging |
| Brevo sin SMS a Colombia | Mensajes por celular P0 | Se verifica en la fase 1; alternativa WhatsApp Business API |
| Fotos, swatches o inventario incompletos | Bloquea la apertura (no el desarrollo) | Regla de publicación + catálogo de prueba |
| Rendimiento de WooCommerce en Hostinger | SC-008 | Caché + CDN + AVIF/WebP + presupuesto de JS; medición desde la fase 2 |
| Disponibilidad de una revisora humana para T0-5 | Retrasa la apertura | Contratarla en la fase 3 |

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| mu-plugin que reemplaza el order-pay y el manejador de eventos del plugin oficial | El plugin tiene `sslverify=false`, retrocede estados, completa el pago sin control de stock y usa una referencia que impide reintentar (R-01 H2–H4) | Usarlo tal cual viola T0-2; reescribir toda la pasarela (R-01b) agrega más código de pago |
| Vencimiento de pedidos propio | La cancelación nativa de WC no cumple FR-056 (R-06) | La reserva nativa vence sin consultar a Wompi |
| Búsqueda propia | "corectol" → "corrector" (distancia 2) | Los plugins no la cubren sin un servicio externo |
| 6 tablas propias | Idempotencia, consentimientos, auditoría, avisos y límites atómicos | Post meta no da unicidad ni atomicidad |
| `ops/deploy.sh` (~650 líneas propias, con ~210 pruebas) | GitHub Free no protege environments ni ramas; una llave SSH en GitHub quedaría al alcance de cualquier rama (R-15, enmienda 1.1.0) | Actions + environment de producción: no existe en el plan Free. GitHub Pro/Team lo resolvería: es condición de la primera salida a producción (`deploy.sh` se niega sin protección de rama; T166) |
