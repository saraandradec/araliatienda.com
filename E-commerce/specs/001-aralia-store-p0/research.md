# Research — Aralía Beauty Store P0

**Feature**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Fecha**: 2026-11-03 · **Tier**: T0 · **Versión**: 3 (corrige hallazgos de las revisiones de arquitectura, iteraciones 1 y 2)

Cada decisión sigue el formato Decisión / Justificación / Alternativas. Los IDs `R-xx` se citan desde `plan.md`, `data-model.md` y `contracts/`. Código verificado: plugin **Wompi Portal de Pagos 3.2.1** y **WooCommerce 11.2.1** (descargados de wordpress.org el 3 nov 2026).

---

## R-01 · Integración de pagos: plugin oficial de Wompi + `aralia-wompi-guard`

**Decisión** (confirmada por Sara el 3 nov 2026: "oficial + capa de blindaje"): el plugin oficial, con **versión fija (3.2.1)**, aporta lo que hace bien: registro del medio de pago en el checkout clásico y de bloques, pantalla de ajustes, creación del pedido y redirección a order-pay. El mu-plugin propio `aralia-wompi-guard` **toma el control de las dos piezas críticas** que el plugin hace mal o que no se pueden blindar desde afuera:

1. **Apertura del widget en order-pay**: el guard quita la acción del plugin (`remove_action('woocommerce_receipt_wompi', …)`) y abre el mismo `WidgetCheckout` de Wompi con una **referencia única por intento** (`<order_id>-<n>`) y captura el id de transacción en el callback.
2. **Recepción de eventos**: el guard se engancha a `woocommerce_api_wc_wompi` con **prioridad 1**, procesa el evento completo y termina la petición, de modo que el manejador del plugin **nunca** corre.

**Hallazgos del código del plugin 3.2.1 y cómo se resuelven**:

| # | Hallazgo (verificado) | Riesgo | Resolución |
|---|------------------------|--------|-----------|
| H1 | Usa el **Widget** (`checkout.wompi.co/widget.js` + `WidgetCheckout.open()` en order-pay), no Web Checkout | Página de la tienda carga JS del TPSP → criterio SAQ A de scripts (E7) con rigor en order-pay | R-11 (CSP estricta y monitoreo del contenido de `widget.js`); evidencia de iframe (R-19) |
| H2 | `'sslverify' => false` en **todas** las llamadas a la API, incluida la que envía la llave privada | Robo de la llave privada por MITM | Filtro `http_request_args` que fuerza `sslverify => true` para hosts `*.wompi.co`; prueba T-SSL. Las llamadas propias del guard usan TLS verificado |
| H3 | `apply_status()` llama a `$order->payment_complete()` **antes** del filtro `wc_wompi_order_status`; un DECLINED posterior pasa a `cancelled` un pedido pagado | Pagado sin control de stock; retroceso de estados | **Eliminado**: el manejador del plugin no corre (el guard procesa el evento con su máquina de estados, R-05) |
| H4 | `reference = order_id`; Wompi exige referencia **única** | El reintento tras un rechazo falla | **Eliminado**: el guard genera `<order_id>-<n>` por intento y resuelve el pedido desde la referencia |
| H5 | Checksum con `hash_equals` y comparación de monto | Correcto pero incompleto (no valida moneda ni consulta la API) | El guard reimplementa la verificación (contracts/wompi-integration.md §3) y agrega moneda, referencia y **consulta a la API** antes de marcar pagado |
| H6 | Guarda las 8 llaves en `wp_options` | Exposición en copias (I-1, I-6) | Filtros `option_woocommerce_wompi_settings` (lectura) y `pre_update_option_woocommerce_wompi_settings` (escritura: vacía los campos de llaves antes de guardar); llaves desde `aralia-secrets.php` (R-16) |
| H7 | Exige la **llave privada** (anulación `/transactions/{id}/void` y `/merchants`) | Credencial capaz de operar transacciones | Dato **Restringido** (FR-058); el guard retira cualquier acción de anulación/reembolso del panel |
| H8 | Responde 500 a checksum inválido | Ruido; sin registro | **Eliminado**: el guard registra todos los eventos (válidos o no) y responde 401 a checksum inválido |
| H9 | `update_post_meta` en el manejador | Incompatible con HPOS | **Eliminado** (el manejador no corre); el guard usa la API CRUD de pedidos |
| H10 | Calificación 2/5, soporte lento | Mantenimiento | Versión fija; prueba de contrato del guard (D-01) |
| **H11** | Declara compatibilidad `cart_checkout_blocks` y `custom_order_tables` y registra un `AbstractPaymentMethodType` para bloques | — | Checkout de bloques y HPOS habilitados (R-03, R-10) |
| **H12** | `process_payment()` **vacía el carrito** al crear el pedido y redirige a order-pay | La clienta "pierde" el carrito si el pago se rechaza | Diseño de reintento sobre el **mismo pedido** (order-pay con nueva referencia); "Volver a mi carrito" que reconstruye el carrito desde el pedido: desde `cancelled`, y desde `pending` tras un rechazo (cancela ese pedido para no dejar dos pendientes) |
| **H13** | Inventario completo de caminos que cambian estados (grep de `apply_status`, `payment_complete`, `update_status`, `set_status`, `add_action`, `add_filter`, `register_rest_route`, `$_GET/$_POST/$_REQUEST`): (1) `woocommerce_api_wc_wompi` → `apply_status` (único camino de `payment_complete`); (2) **estado propio `wc-voided` + `woocommerce_process_shop_order_meta`**: al guardar un pedido en el panel con estado "Anulado", el plugin llama a `POST /transactions/{id}/void` con la llave privada; (3) solo lectura: `woocommerce_thankyou_order_received_text`, `woocommerce_thankyou_order_key` (lee `$_GET['key']`), `woocommerce_admin_order_data_after_order_details`, `woocommerce_after_checkout_validation` (monto mínimo). No registra rutas REST ni lee `?id=` en el retorno | (2) permite anular desde la tienda, contra FR-058 | (1) interceptado (prioridad 1); (2) `remove_action('woocommerce_process_shop_order_meta', …)` del plugin y retiro de `wc-voided` de `wc_order_statuses` (el estado queda registrado solo para datos históricos); (3) sin acción. Prueba **T-NO-BYPASS** |

**Superficie del plugin de la que depende el guard** (prueba de contrato D-01, falla si algo cambia): id de gateway `wompi`, opción `woocommerce_wompi_settings` y nombres de sus claves, acciones `woocommerce_receipt_wompi`, `woocommerce_api_wc_wompi` y `woocommerce_process_shop_order_meta`, estado `wc-voided`, clase de bloques registrada, y que **no existan otros llamados** a `payment_complete`, `update_status` o `set_status` fuera de los inventariados en H13 (la prueba hace el mismo grep sobre la versión instalada). **Criterio objetivo para activar R-01b**: falla la prueba de contrato en una versión con un parche de seguridad necesario, o una vulnerabilidad del plugin sin corrección en 7 días.

**R-01b (plan B, no se construye en P0)**: gateway propio completo por Web Checkout (redirección), reutilizando el guard.

**Fuentes**: wordpress.org/plugins/wompi-portal-de-pagos; docs.wompi.co (widget-checkout-web, eventos, seguimiento-de-transacciones).

---

## R-02 · Verificación de pago y conciliación

**Decisión**:
1. **Evento** `transaction.updated` → guard (prioridad 1): registra el cuerpo saneado en `aralia_payment_events`; valida estructura y checksum (secreto de eventos, `hash_equals`); resuelve el pedido desde la referencia `<order_id>-<n>`; **idempotencia** por (`transaction_id`, `status`); **verificación doble**: `GET /v1/transactions/{id}` y comparación de estado, monto, moneda (`COP`) y referencia contra el pedido; aplica la máquina de estados (R-05). Si la API de Wompi no responde, el evento queda `deferred` y lo resuelve la conciliación; se responde 200 a Wompi una vez registrado.
2. **Callback del widget**: al cerrarse el widget, el JS del guard envía `{transaction_id, reference}` a `POST /aralia/v1/payment-attempt` (clave del pedido). Es solo una **pista** para consultar; nunca cambia estados. Cubre el cierre del navegador **después** de que el widget devolvió el resultado. **No** cubre a quien sale al banco (PSE, Botón Bancolombia) y nunca vuelve: ese pedido se cancela al vencer y, si luego llega el APPROVED, entra por la ruta de aprobado tardío → riesgo residual **RR-6** (threat model), medido con el KPI "tasa de `ara-nostock`" y la prueba T-PSE-ABANDON.
2b. **Persistencia del id**: todo id de transacción que llegue por evento (incluso `deferred`) se guarda en `_ara_attempts`, para que la conciliación lo encuentre. La clave de idempotencia es (`transaction_id`, estado **verificado por la API**).
3. **Conciliación** (Action Scheduler cada 5 min, cron real): pedidos `pending`, y `cancelled` de las últimas 24 h, con al menos un id de transacción conocido sin estado final → consulta a la API → mismo pipeline. Un APPROVED que llega por evento sobre un `cancelled` de **más** de 24 h sigue el mismo camino que el aprobado tardío (`processing` o `ara-nostock`) con alerta adicional.
3b. **Intentos paralelos (doble cobro)**: antes de abrir un intento nuevo, el guard consulta a la API los intentos con id sin estado final; si alguno está PENDING, order-pay muestra "Tienes un pago en proceso. Te avisaremos apenas se confirme." y no abre el widget. Si aun así llegan dos APPROVED para el mismo pedido, el segundo no cambia el estado: alerta crítica "posible doble cobro" y `ops/runbooks/duplicate-charge.md` (reembolso manual del segundo). Prueba T-PAY-DOUBLE.
4. **Regreso del navegador** (`?id=`): solo pista para consultar; nunca cambia estados (S-2).
5. **Llave para consultar**: la documentación de Wompi es contradictoria (la página de seguimiento dice "solo llave privada"; otras fuentes, llave pública). **Prueba T-API-AUTH en la fase 1**: si la consulta funciona con la pública, el guard usa la pública; si no, la privada (ya clasificada R).

**Búsqueda por referencia**: no está documentada por Wompi; no se usa. Un pago en el que la clienta cerró el navegador **antes** de que se creara la transacción no existe para Wompi, así que no hay nada que conciliar.

---

## R-03 · Plataforma, versiones y hosting

**Decisión**: WordPress **7.1.x** (corrección del 2026-11-03 al iniciar la implementación: wordpress.org marca 6.9.x y 7.0.x sin soporte de seguridad y WooCommerce 11.2.1 exige WP ≥ 7.0. **Riesgo**: Wompi Portal de Pagos 3.2.1 declara pruebas solo hasta WP 6.9.11; su compatibilidad con 7.1 la verifican la prueba de contrato y los E2E de la puerta de la fase 1) · **WooCommerce 11.2.x** (fijada en `ops/inventory.md`) · PHP 8.3 · MySQL/MariaDB del hosting · **HPOS** como almacenamiento autoritativo; sincronización de compatibilidad activa durante las fases 1–4 y **desactivada antes de abrir** si los E2E de los cuatro medios pasan con HPOS solo (H9 ya no aplica) · Hostinger **Business o Cloud Startup** (staging, SSH, cron real, copias diarias, CDN, LiteSpeed).

**Precios en pesos enteros**: `woocommerce_price_num_decimals = 0`; cupones porcentuales redondean a peso entero (prueba con 7%, 13%, 33%). Así `amount_in_cents = total × 100` es exacto (R-12 de la revisión).

---

## R-04 · Tema y frontend

**Decisión**: tema de bloques propio `aralia` (FSE) con `theme.json` de tokens (sección 11 del documento fuente; champagne oscurecido para texto sobre crema), Cormorant Garamond y Jost **auto-alojadas**, Interactivity API, **GSAP auto-alojado** solo en páginas públicas que lo usan, con `prefers-reduced-motion`; nunca en checkout, order-pay ni Gracias. Sin scripts inline propios (todo en archivos `.js` servidos desde el dominio).

---

## R-05 · Máquina de estados del pedido

**Decisión**: estados nativos + 4 propios (`ara-nostock`, `ara-packed`, `ara-shipped`, `ara-reversed`). **No se usa `failed`**: un intento rechazado deja el pedido en `pending` (FR-052). Tabla completa de transiciones en `data-model.md` §3. Toda transición no listada se bloquea, se registra en `aralia_audit_log` y genera alerta (R-22).

Estados propios declarados como pagados con `woocommerce_order_is_paid_statuses` (`ara-nostock`, `ara-packed`, `ara-shipped`) e incluidos en `woocommerce_analytics_actionable_statuses` / informes (`woocommerce_reports_order_statuses`), para que `is_paid()`, Analytics y `total_sales` funcionen (D-02). `ara-reversed` se excluye de ventas.

---

## R-06 · Reserva y liberación de stock (rediseño)

**Hallazgo en WooCommerce 11.2.1**: `wc_cancel_unpaid_orders` corre cada *hold_minutes* (peor caso ≈ 2 × 30 min), filtra por `date_updated_gmt` (cualquier nota reinicia el reloj) y la reserva de `wc_reserved_stock` vence sola por tiempo, sin pasar por el filtro de cancelación.

**Decisión**: la tienda **descuenta el stock al crear el pedido** y lo **devuelve al cancelarlo**, con un vencimiento propio por pedido:
1. Al crear el pedido (`woocommerce_checkout_order_processed` y `woocommerce_store_api_checkout_order_processed`): `wc_reduce_stock_levels($order)` (descuento atómico; escribe `_reduced_stock` por ítem y `_order_stock_reduced`, así `payment_complete` y `woocommerce_order_status_processing` no vuelven a descontar) **e inmediatamente `wc_release_stock_for_order($order)`**: WooCommerce 11.2.1 solo libera la fila de `wc_reserved_stock` en `woocommerce_payment_complete` y en los estados processing/completed/on-hold/cancelled, así que sin esta llamada la unidad quedaría contada dos veces (descontada y reservada). Luego se programa la acción única `aralia_order_expiry` a **creación + 30 min** (`_ara_expires_at`, independiente de `date_modified`).
   - `woocommerce_hold_stock_minutes` se fija en **30** (controla la reserva durante el checkout; el cron nativo no cancela pedidos `wompi` por el punto 2).
2. La cancelación nativa se desactiva para pedidos `wompi` (`woocommerce_cancel_unpaid_order` → false); la reserva nativa del checkout sigue protegiendo la carrera de la última unidad *durante* el checkout.
3. En `aralia_order_expiry`: si hay ids de transacción, consulta a Wompi → APPROVED: pipeline de pago · PENDING: extiende 15 min (`_ara_expires_at` += 15) hasta el tope de 2 h · DECLINED/ERROR/VOIDED o sin transacción: `cancelled` (WooCommerce restaura el stock con `wc_maybe_increase_stock_levels`).
4. **APPROVED tardío** sobre `cancelled` (la cancelación ejecutó `wc_increase_stock_levels`, que borra `_reduced_stock` y pone `_order_stock_reduced` en falso): si hay stock para todas las líneas → `wc_reduce_stock_levels` nativo → `processing`. Si falta algo → servicio `Orders/PartialStock`: por cada línea disponible, `wc_update_product_stock(…, 'decrease')` + `_reduced_stock` en el ítem; líneas faltantes con `_ara_missing`; `_order_stock_reduced = true` → `ara-nostock` (FR-057). Los reemplazos de R-24 usan el mismo servicio. Así, una cancelación o un reembolso posteriores restauran exactamente lo descontado.
4b. **Widget abierto cerca del vencimiento** (N-04): order-pay muestra el tiempo restante de la reserva. Si al abrir el widget quedan menos de 5 min, `_ara_expires_at` se extiende **una sola vez** a ahora + 5 min, sin pasar de creación + 35 min (SC-013). Una transacción creada después del vencimiento entra por la ruta de aprobado tardío (riesgo residual RR-7).
5. Cron real del servidor cada minuto (`DISABLE_WP_CRON`), y alerta si alguna acción `aralia_order_expiry` está atrasada más de 5 min (R-22).

**Pruebas**: T-HOLD-TIMING (se libera entre 30 y 35 min aunque el pedido se modifique), T-HOLD-PENDING (extensión por PSE pendiente), T-HOLD-WIDGET-OPEN (widget abierto a los 27 min), T-STOCK-RACE (dos checkouts por la última unidad), **T-STOCK-DOUBLECOUNT** (tras crear el pedido, el stock disponible baja una sola vez y no queda fila en `wc_reserved_stock`), **T-STOCK-FLAGS** (crear → cancelar → aprobado tardío parcial → reembolso: el stock final coincide en cada paso).

**Alternativas**: reserva nativa + filtro (rechazada por los tres problemas del hallazgo).

---

## R-07 · Sets, "Agregar todo" y Full face

**Decisión**:
- **Sets fijos** (Cejas perfectas, Labios): producto **simple** con stock y precio propios; sus componentes informativos en el meta `_ara_components` (lista de product_id). Al guardar se valida que no se repita marca (RN-02). Si cambia el precio de un componente, el panel avisa los sets fijos afectados para revisar si el ángulo de valor (FR-029) sigue siendo cierto. "Cambiar piezas" no aplica (FR-027).
- **Sets de piezas sueltas** y **Full face**: CPT `aralia_set` (sueltos) y `FullFaceBuilder` (calculado). "Agregar todo" se implementa con la **extensión oficial de la Store API**: `POST /wc/store/v1/cart/extensions` con `namespace: "aralia"` y `ExtendSchema::register_update_callback`, de modo que el nonce y el `Cart-Token` los gestiona WooCommerce aunque la página esté en caché (R-21). Cada pieza entra como línea separada con `_ara_group_id`.
- **Full face con tono**: si el tono elegido está agotado en un paso, el paso se omite con el aviso "Tu tono en <pieza> se agotó" y se ofrecen otro tono de la misma pieza o otra pieza de la misma marca y paso (`skipped[].reason = tone_soldout`).
- **Caché del Full face**: servicio único `CacheInvalidator` (D-06) que escucha `woocommerce_product_set_stock`, `woocommerce_variation_set_stock`, `save_post_product`, cambios de término (`edited_product_brand`, `set_object_terms`) y de pins; invalida el transient y **purga la caché de página** por etiqueta (R-21).

---

## R-08 · Tono

**Decisión**: atributo global `pa_tono` (familias) con term meta (swatch, subtono, orden); productos que dependen del tono = **variables** por `pa_tono`. Tono en `localStorage` (`aralia_tone`, 12 meses). El orden "recomendado" por tono se sirve como variante de URL (`?tono=<slug>`, máx. 6 variantes cacheables); el JS agrega el parámetro si hay tono guardado. El servidor rechaza agregar una pieza variable sin variación.

---

## R-09 · Catálogo, filtros, búsqueda, alternativas e importación

**Decisión**:
- Listados con **Product Collection** + **Product Filters** nativos (URL compartible).
- Búsqueda tolerante a errores propia (Levenshtein normalizado + trigramas sobre un índice en transient; "corectol" → "corrector").
- **Alternativas "del mismo tipo"** (FR-046, FR-028, FR-057): servicio `Catalog/Alternatives` = misma subcategoría **y** al menos un color en común (o, en sets, mismo paso), con stock, ordenadas por `total_sales`; precio igual o menor cuando se usa para resolver un `ara-nostock`.
- **Importación desde el panel** (FR-065): pantalla "Importar inventario" → subir CSV → validación (contracts/inventory-csv.md) → vista previa de altas, cambios y diferencias de precio → confirmar → **importador propio** que usa la API CRUD de WooCommerce (no el importador nativo, que no entiende las columnas en español, la marca, los swatches, los cross-sells por SKU ni las fotos por nombre). El mismo validador existe en WP-CLI.
- **Regla de publicación** (FR-066): se aplica solo a la **transición a `publish`** (desde borrador o pendiente): exige foto, swatch donde aplica y stock > 0 en el producto o en al menos una variación. Un producto publicado que se agota **no se despublica** (sigue mostrando "Avísame").

---

## R-10 · Checkout, envíos, regalo y reintento

**Decisión**: checkout de bloques (H11) con la **Additional Checkout Fields API** (ciudad con autocompletado DANE, indicaciones, regalo, declaraciones de autorización y mayoría de edad, novedades). Método de envío `aralia_city_rate` (metropolitana / nacional; ciudad fuera de lista bloquea). Turnstile: el token viaja en `extensions.aralia.turnstile` del checkout de la Store API y se valida en `woocommerce_store_api_checkout_update_order_from_request` (D-05).

**order-pay** (página propia del guard): abre el widget; si `WidgetCheckout` no está disponible a los 8 s (Wompi caído o bloqueado), muestra "No pudimos abrir el pago. Inténtalo de nuevo en un momento." con botón de reintento (G-15). Tras un rechazo: mensaje de marca + "Intentar con otro medio" (nuevo intento, nueva referencia) + correo "Pago rechazado" con enlace a order-pay (`order_key`). Tope de 5 intentos de pago rechazados por pedido; luego order-pay se bloquea 1 h (R-12). Tras un rechazo también se ofrece "Volver a mi carrito" (cancela el pedido y reconstruye el carrito). Pedido ya `cancelled` → "Tu reserva venció" + "Volver a mi carrito" (H12).

**Referencia por intento**: `n` aumenta en **cada apertura** del widget (se persiste en `_ara_attempts` antes de renderizar, porque una transacción creada sin callback dejaría la referencia ya usada en Wompi); formato `<order_id>-<n>` con `n` de 1 a 999 (regex `^\d+-\d{1,3}$`); más de 30 aperturas por pedido → order-pay bloqueado (abuso).

---

## R-11 · Seguridad de scripts (criterio SAQ A E7), CSP y cabeceras

**Decisión** por tipo de página:

| Tipo | Caché | CSP | Terceros permitidos |
|------|-------|-----|---------------------|
| **Pago**: checkout, order-pay, Gracias, `/aralia/v1/*` de sesión | Nunca | **Estricta con nonce por petición**: `script-src 'self' 'nonce-…' 'strict-dynamic'`; `frame-src`, `connect-src`, `img-src`, `form-action` con los dominios de Wompi inventariados por medio de pago (sandbox y producción); `frame-ancestors 'self'` | checkout: Turnstile (`challenges.cloudflare.com`, excepción justificada). order-pay: solo Wompi. Gracias: ninguno |
| **Públicas cacheadas** (home, listados, ficha, secciones) | Sí (R-21) | **Lista de hosts + hashes calculados en el render**: un output buffer de `aralia-core` calcula el SHA-256 de cada script inline de la respuesta y arma `script-src 'self' <inventario> 'sha256-…'`; LiteSpeed cachea cabecera y cuerpo juntos, de modo que siempre coinciden (prueba T-CSP-CACHE). Sin `'unsafe-eval'` ni `'unsafe-inline'`; `connect-src`, `img-src`, `form-action` restringidos al inventario para impedir la exfiltración | GA4, Meta Pixel (con consentimiento) |

- **Inventario** en `ops/script-inventory.md` (fuente, propósito, justificación, dueña), levantado con la CSP en modo `Report-Only` en staging durante 2 semanas, **con cada medio de pago en Sandbox y luego en producción** (dominios de 3DS, PSE y bancos incluidos).
- **Monitoreo** (11.6.1 como medio, diario, **en producción**): `tools/script-monitor` descarga checkout, order-pay de un **pedido centinela** y una ficha; compara contra una línea base: (a) conjunto de `<script src>`; (b) scripts inline **normalizados** (se reemplazan por marcadores los valores dinámicos conocidos: nonces, `wcSettings`, cadenas i18n, ids) y se compara su estructura; (c) **hash del contenido de `checkout.wompi.co/widget.js`** (no admite SRI); (d) cabeceras de seguridad. Severidad: nuevo `src`, cambio de `widget.js` o de cabeceras → **crítico**; cambio en un inline normalizado → aviso (revisión en 48 h). Un cambio de `widget.js` se valida contra el changelog de Wompi y se re-firma la línea base.
- **Pedido centinela** (N-11): un único pedido `pending` por mes creado por WP-CLI con `created_via = aralia-sentinel` sobre un producto oculto **sin gestión de stock**, excluido de vencimiento, correos, KPIs, analítica, límites y listados del panel; se reemplaza cada mes. Sus aperturas de order-pay no abren el widget (la página se descarga sin ejecutar JS) y no cuentan como intentos.
- Cabeceras: HSTS (1 año, includeSubDomains), `X-Content-Type-Options`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`.

---

## R-12 · Anti-abuso

**Decisión**: Turnstile invisible solo en checkout (crear pedido), códigos, "Avísame" y lista anticipada. Límites en la tabla `aralia_rate_limits` con incremento atómico (`INSERT … ON DUPLICATE KEY UPDATE`, D-04):

| Límite | Valor | Clave |
|--------|-------|-------|
| Pedidos pendientes simultáneos | ≤ 2 | celular normalizado; ≤ 2 por correo |
| Pedidos pendientes por IP (señal blanda, CGNAT) | ≤ 8; por encima exige desafío visible de Turnstile | IP |
| Unidades iguales por línea | ≤ 3 | línea |
| Intentos de pago por pedido | ≤ 5, luego order-pay bloqueado 1 h | pedido (eventos DECLINED) |
| **Rechazos por origen** (D-2, FR-060) | > 5 / h → crear pedidos desde esa IP exige el desafío visible de Turnstile; > 10 / h → creación bloqueada 1 h | IP guardada en el pedido al crearlo (los eventos de Wompi se asocian a la IP del pedido) |
| Intentos de código | ≤ 10 / h | sesión + IP |
| SMS por número | ≤ 3 / día; solo +57 3xx | número |
| Alerta de rechazos | > 20 / h en toda la tienda | — |

**SMS** solo a quien (a) tiene un pedido pagado o (b) confirmó su correo y luego su celular con código (R-09 de la revisión: evita SMS pumping).

---

## R-13 · Correo, SMS y lista

**Decisión**: **Brevo** (correo transaccional, SMS, lista). SPF/DKIM/DMARC. Correos: plantillas de WooCommerce sobreescritas + `WC_Email` propios: **Pedido recibido** (al crear el pedido), Pago rechazado con reintento, Empacado con foto, Despachado con guía, Pagado sin stock, Pieza disponible, Reserva vencida (con "Volver a mi carrito"). "Entregado": Sara lo marca en el panel o se marca automáticamente 10 días después de `ara-shipped` si no hay novedad. Verificación de SMS a Colombia en la **fase 1** (si no aplica: WhatsApp Business API del proveedor que Sara elija).

---

## R-14 · Analítica sin duplicados

**Decisión**: GA4 (`gtag.js` directo) + Meta Pixel **solo en páginas públicas con consentimiento**; **ninguna etiqueta en checkout, order-pay ni Gracias** (FR-073). `begin_checkout`, `add_payment_info`, `add_set`, `gift_mode_on` y `purchase` se envían **solo desde el servidor** (GA4 Measurement Protocol, Meta CAPI) con `event_id` estable; `purchase` una sola vez por pedido (`_ara_purchase_sent`). Advanced matching automático del Pixel **desactivado**; `page_location` nunca incluye `key=` ni datos personales. Para unir la sesión con la compra se guarda en el pedido el `client_id` de GA4 y el `fbp`/`fbc` leídos en el checkout (cookies propias del dominio, sin cargar las etiquetas), **solo si la clienta dio consentimiento de analítica**; se purgan con la IP a los 90 días (R-23).

**Panel (FR-069)**: WooCommerce Analytics (piezas y sets fijos, que son productos) + widget propio (rechazos por medio, carritos abandonados, reservas vencidas, y **sets más vendidos** de piezas sueltas y Full face: conteo de grupos distintos `_ara_group_id` por `_ara_set_id` —y por marca en Full face— en ítems de pedidos en estados pagados, últimos 30 días) + **visitas y conversión**: el widget muestra las cifras de los últimos 30 días leídas una vez al día de la GA4 Data API con una cuenta de servicio de **solo lectura** (credencial R en `aralia-secrets.php`). Resuelve G-17 sin desviarse del spec.

---

## R-15 · Cadena de suministro, actualizaciones, despliegue y revisión

**Decisión**:
- Repo `Aralía-web` en GitHub **privado** con 2FA; `E-commerce/` solo con código propio; terceros por Composer con versiones fijas; `gitleaks` en CI.
- **Plugins permitidos (lista cerrada)**: WooCommerce, Wompi Portal de Pagos, Wordfence, LiteSpeed Cache, UpdraftPlus **Premium** (cifrado de BD, D-08). Cualquier otro: justificación en `ops/inventory.md` (criterios PO.1.3).
- CI: PHPCS (WordPress-Extra + seguridad), PHPStan 6, PHPUnit, Playwright + **axe-core**, Lighthouse CI, gitleaks, **prueba de contrato del guard** (D-01).
- Despliegue (enmienda 1.1.0): **local, no desde GitHub Actions**. El plan Free no ofrece environments protegidos ni protección de rama en repos privados, así que una llave SSH guardada en GitHub quedaría al alcance de cualquier rama. El CI solo arma el paquete (`ops/deploy.sh package`, con SHA-256); Sara ejecuta `ops/deploy.sh staging|production <sha>` desde su equipo (llave en ssh-agent). El script exige: `<sha>` en `origin/main`, CI verde, checksum correcto, staging previo para producción, PR aprobado por `ops/payment-reviewers.txt` (distinto de la autora) para el camino de pago, y una simulación `rsync -n` confirmada. Solo sincroniza carpetas propias o fijadas por Composer (nunca `.htaccess`, WAF, uploads, UpdraftPlus). macOS requiere `brew install rsync`. `DISALLOW_FILE_EDIT` y `DISALLOW_FILE_MODS` en producción.
- **SLA de parcheo**: crítico/alto ≤ 7 días; medio ≤ 30. **Parche de emergencia** (`ops/runbooks/patching.md`): si no hay desarrolladora disponible en 48 h ante un crítico explotado, Sara activa el modo mantenimiento de pagos (oculta el medio de pago) hasta parchear.
- **Revisión independiente del camino de pago** (CM ítem 11, PCI 6.2.3): todo cambio en `aralia-wompi-guard`, `Orders/`, `Checkout/`, `Cart/` y `Rest/` pasa, antes de producción, por los gates de `logidev` T0 (code-reviewer + security-reviewer + 3 verificaciones adversariales, todos distintos del agente autor) **y** por una **revisora humana técnica nombrada**, antes de la apertura y **en cada cambio posterior** del camino de pago (CODEOWNERS con esa persona para esas rutas —requiere permiso Write en el repo— y `ops/payment-reviewers.txt`, que `ops/deploy.sh` hace cumplir; contratada por horas; cuenta temporal según `ops/runbooks/access.md`). **Régimen transitorio**: Sara revisa en la Fase 2; la revisora revisa todo lo hecho antes de la primera salida; producción bloqueada hasta entonces. Fuera de eso, el camino de pago queda congelado. Evidencia en el ledger TRACE.
- Verificación de integridad en cada despliegue: `wp core verify-checksums` y `wp plugin verify-checksums` de la lista cerrada de plugins de terceros; el código propio (aralia-core, tema, mu-plugins `aralia-*`) se compara con `git archive <sha>` antes de copiar (el hash del paquete solo prueba integridad del transporte, no autenticidad).
- **Alcance de la revisión (criterio invertido, T0 vuelta 2)**: `ops/deploy.sh` exige PR aprobado para TODO commit desde el último despliegue a producción, salvo `specs/`, `ops/runbooks/`, `ops/compliance/` e `ops/inventory.md`. La lista de revisoras se lee del último despliegue (no del commit a desplegar); el alta de la primera exige confirmación escrita `ALTA-REVISORA`. El registro de despliegues está en el equipo de Sara (`~/.aralia-deploy/`), no en el servidor.
- **Cómo funciona el régimen transitorio**: GitHub no deja a la autora aprobar su propio PR ni aprobar PR ya fusionados, así que antes de contratar a la revisora el trabajo se fusiona (squash) en una rama `integracion`, que no se despliega. Al contratarla, un único PR `integracion` → `main` lo aprueba la revisora sobre su último commit; ese commit es el que `ops/deploy.sh` acepta. Sara revisa "en la Fase 2" = durante la fase Fundacional de `tasks.md` (donde nace el guard), dejando en el ledger TRACE su sign-off de cada vuelta de gates.

---

## R-16 · Gestión de secretos

**Decisión**: `aralia-secrets.php` **fuera de `public_html`** (permisos 600), incluido desde `wp-config.php`: llaves de Wompi (8), Brevo, Turnstile, Meta CAPI, GA4 MP, cuenta de servicio GA4 (solo lectura) y la **llave de cifrado de datos personales** (`ARALIA_PII_KEY`, libsodium `secretbox`; D-07). Copia de este archivo en el gestor de contraseñas de Sara (no en las copias del sitio). Rotación: `ops/runbooks/rotate-keys.md` (anual o ante incidente; la llave PII se rota con re-cifrado por lotes). Prueba T-KEYS.

**Cargador (mu-plugin)**: `wp-content/mu-plugins/aralia-secrets-loader.php` lee la ruta de `ARALIA_SECRETS_PATH` (definida en `wp-config.php` o por wp-env), valida que el archivo exista, esté fuera del docroot y —fuera de local— tenga permisos `600`, y define las constantes antes de que carguen los plugins; nunca registra valores en logs. Cubierto por `tests/php/unit/SecretsLoaderTest.php` (13 pruebas). En local, wp-env monta `.wp-env-private/` (ignorada por git) en `wp-content/aralia-private/`.

---

## R-17 · Copias y continuidad

**Decisión**: copias diarias de Hostinger + **UpdraftPlus Premium** (BD diaria cifrada, archivos semanales) a almacenamiento externo con 2FA. **RPO 24 h, RTO 8 h.** `ops/runbooks/restore.md` incluye recuperar `aralia-secrets.php` desde el gestor de contraseñas. Restauración probada antes de abrir y trimestral.

**Staging sin datos reales** (R-10 de la revisión): staging se crea limpio o, si se clona producción, se ejecuta `wp aralia anonymize` (correos, celulares, direcciones, mensajes de regalo, consentimientos) antes de habilitar el acceso; incluido en `baseline-config.md`.

---

## R-18 · Pruebas y entorno local

**Decisión**: `@wordpress/env` (Docker) obligatorio para CI; en local, wp-env o Local by Flywheel. PHPUnit, integración REST/Store API, Playwright 360 px (historias 1–8 y negativas), axe-core (AA), OWASP ZAP baseline en staging, **k6 a 10× el tráfico promedio** contra staging en la fase 5 (D-3). Pruebas de contrato de Wompi en `contracts/wompi-integration.md` §6.

---

## R-19 · Controles operativos PCI / legales

| Control | Decisión | Dueña | Fase |
|---------|----------|-------|------|
| **Evidencia SAQ A del widget** | Inspección del DOM por medio de pago: los campos de tarjeta y de cuenta viven en un `iframe` de `checkout.wompi.co`, no en el DOM de la tienda; capturas en `ops/compliance/saq-a-widget-evidence.md` + confirmación escrita de Wompi de que el widget es elegible para SAQ A. **Si no se confirma → activar R-01b antes de abrir** | Desarrolladora + Sara | 1 |
| AOC de Wompi | Solicitar y archivar | Sara | 1 |
| Escaneo ASV | Trimestral y tras cambios significativos (cambio de hosting, de gateway, de tema mayor) | Sara | 5 |
| Matriz de responsabilidades | Wompi / Hostinger / Aralía | Desarrolladora + Sara | 1 |
| Plan de incidentes | `ops/runbooks/incident-response.md` con aviso a la SIC, a titulares y **nota de causa raíz** | Desarrolladora + abogado | 4 |
| Tarjetas por otros canales | Política + respuestas modelo | Sara | 5 |
| Cuentas y contraseñas | 2FA y contraseñas ≥ 12 en todas las cuentas; **sin usuario `admin`**; checklist `ops/compliance/accounts-2fa.md` | Sara | 1 |
| Legales, RNBD, Ley 2300 | Abogado | Sara | antes de 5 |
| `security.txt` | `/.well-known/security.txt` | Desarrolladora | 5 |

---

## R-20 · Constitución del proyecto

Constitución provisional (principios 1.2 y 1.3 + reglas T0) hasta ratificarla con `/speckit.constitution` como primera tarea de la fase 1.

---

## R-21 · Política de caché (nuevo)

**Decisión** (`ops/cache-policy.md`):
- **Nunca en caché**: checkout, order-pay, Gracias, carrito, mi cuenta, `/wp-json/aralia/v1/*` de sesión, `/wc/store/*`, `?wc-api=*`.
- **Caché de página** (LiteSpeed + CDN) para home, listados, fichas y secciones; TTL 10 min; **purga por etiqueta** desde `CacheInvalidator` al cambiar stock, precio, publicación, términos o Full face (producto, sus categorías, secciones y home).
- **Estado dinámico**: la disponibilidad y la etiqueta "Últimas unidades" se piden a `GET /aralia/v1/availability?ids=` (respuesta **pública cacheable 60 s**, purgada por etiqueta por `CacheInvalidator`), no a la Store API; así un pico de tráfico no genera PHP por cada vista (N-07). El carrito y el checkout sí usan la Store API (sin caché). El HTML cacheado y la disponibilidad cacheada nunca son la verdad al agregar: la Store API vuelve a validar stock y precio.
- **Nonces**: el HTML cacheado no lleva nonces válidos de forma confiable; antes de la primera mutación, el JS hace `GET /wc/store/v1/cart` y toma el `Nonce` de la cabecera (renovado en cada respuesta). Los nonces de WP para `aralia/v1` se obtienen igual en tiempo de ejecución.
- **Medición temprana**: prueba k6 de humo (3× el promedio) en la fase 2, además de la de 10× en la fase 5.
- **Tono**: variante por `?tono=` (R-08).
- **Optimización JS de LiteSpeed** (combinar, diferir, retrasar) **desactivada** en las páginas de pago y para `widget.js`.

---

## R-22 · Matriz de alertas (nuevo)

Destinataria: Sara. Canal: correo + SMS (crítico) o solo correo (aviso). Fuente: `aralia-core` (Action Scheduler) y Wordfence.

| Evento | Umbral | Severidad |
|--------|--------|-----------|
| Pedido en `ara-nostock` | cada uno | Crítico |
| Transición bloqueada (R-05) | cada una | Crítico |
| Checksum inválido | > 5 / h | Crítico |
| Rechazos de pago | > 20 / h | Crítico |
| Evento `deferred` sin resolver | > 15 min | Crítico |
| `aralia_order_expiry` atrasada | > 5 min | Crítico |
| Nuevo `<script src>`, cambio de `widget.js` o de cabeceras de seguridad (R-11) | cualquiera | Crítico |
| Cambio en un script inline normalizado (R-11) | cualquiera | Aviso (revisión en 48 h) |
| Usuario con rol administrador creado o cambio de rol | cualquiera | Crítico |
| Inicio de sesión en el panel desde un dispositivo nuevo | cualquiera | Aviso |
| Vulnerabilidad en un componente (Wordfence) | crítica/alta | Crítico |
| Copia de seguridad fallida | cualquiera | Aviso |
| Stock bajo | ≤ 3 | Aviso (resumen diario) |

---

## R-23 · Datos personales: cifrado, búsqueda y purgas (nuevo)

**Decisión**: columnas `*_enc` cifradas con `ARALIA_PII_KEY` y acompañadas de `subject_hash` (HMAC-SHA256 con llave propia) en `aralia_consents`, `aralia_stock_alerts` y `aralia_early_access`, para atender derechos de la titular sin descifrar todo. **Purgas** (Action Scheduler, diarias): avisos de stock enviados + 90 días; IP de los pedidos de WooCommerce (`customer_ip_address`) a 90 días; datos de quien recibe el regalo (celular y mensaje) 90 días después de `completed`; registros de seguridad a 90 días; exportaciones del panel con enlace que caduca en 24 h. **Subidas**: solo imágenes (validación de MIME real) y CSV; `uploads/` sin ejecución de PHP (`.htaccess` / regla de LiteSpeed). **ZIP de fotos de la importación**: solo entradas de imagen con MIME real, sin rutas relativas ni absolutas (zip slip), máx. 200 archivos y 100 MB, extracción en un directorio temporal fuera de `uploads/` y luego carga a la biblioteca de medios con la API de WordPress.

---

## R-24 · Pagado sin stock: resolución (nuevo)

**Decisión**: al pasar a `ara-nostock` se descuentan las líneas disponibles y se marcan las faltantes. La clienta recibe un correo con el detalle, las dos opciones y un enlace de contacto (caso permitido por el principio 7). Sara resuelve en el panel con la acción "Resolver pedido": **reemplazar** una línea faltante por una alternativa (R-09) de igual o menor precio (la diferencia se registra como reembolso parcial y se ejecuta en Wompi) → `processing`; o **reembolso total** registrado → `refunded`.
