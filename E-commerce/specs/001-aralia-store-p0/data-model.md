# Data Model — Aralía Beauty Store P0

**Feature**: [spec.md](./spec.md) · **Research**: [research.md](./research.md) · **Fecha**: 2026-11-03 · **Versión**: 3

Convenciones: `WC` = estructura nativa de WooCommerce; `CPT` = custom post type; `TAX` = taxonomía; `TBL` = tabla propia con prefijo `{$wpdb->prefix}aralia_`. Clasificación de datos (R/C/I/P) según `data-classification.md`.

---

## 1. Catálogo

### Producto — `WC product` (simple o variable)

| Campo | Almacenamiento | Validación | Clase | Origen |
|-------|----------------|------------|-------|--------|
| Nombre, descripción corta, cómo se usa, ingredientes | WC nativo / meta `_ara_how_to`, `_ara_ingredients` | obligatorio nombre | P | 8.1 |
| Categoría principal | TAX `product_cat` (Labios, Cejas, Pestañas, Ojos, Piel, Brochas, Accesorios) | exactamente 1 de las 7 de primer nivel | P | FR-002 |
| Subcategoría | TAX `product_cat` hija | obligatorio | P | 8.1 |
| Marca | TAX `product_brand` (nativo WC) | obligatorio, 1 | P | 8.1 |
| Depende del tono | meta `_ara_tone_dependent` (bool) | obligatorio; si true → producto **variable** por `pa_tono` | P | FR-019 |
| Familia de tono / subtono | atributo global `pa_tono` en variaciones; subtono en term meta | obligatorio si depende del tono | P | FR-068 |
| Color | atributo global `pa_color` | ≥ 1 | P | 8.1 |
| Look | atributo global `pa_look` (natural, clásico, noche, atrevido) | ≥ 1 | P | 8.1 |
| Secciones | TAX `aralia_seccion` | opcional | P | 4.3 |
| Paso de Full face | TAX `aralia_paso` (7 términos fijos) | obligatorio si puede entrar en Full face | P | FR-032 |
| Combina con | WC `cross_sell_ids` | exactamente 2 | P | FR-016 |
| Costo | meta privada `_ara_cost` (no registrada en REST, excluida de feeds) | número ≥ 0 | **I** | FR-067 |
| Precio, stock | WC nativo (`_price`, `_stock`, gestión de stock **activada siempre**) | precio > 0; stock entero ≥ 0 | P | 8.1 |
| Foto, foto en uso, swatch real | imagen destacada, galería, meta `_ara_swatch_id` (o swatch por variación) | foto obligatoria; swatch obligatorio si tiene color o tono | P | FR-066 |
| Registro sanitario | meta `_ara_invima` | texto ≤ 40; se muestra si existe | P | FR-014 |
| Precio anterior válido | meta `_ara_price_history` (lista de {precio, desde}) | tachado solo si el precio anterior duró ≥ 14 días | P | FR-061 |
| Fecha de publicación | WC nativo | etiqueta "Nuevo" si < 21 días | P | FR-003 |

**Regla de publicación (FR-066, R-09)**: solo en la **transición a `publish`** se exige foto, swatch donde aplica (cada variación con swatch), stock gestionado y stock > 0 en el producto o en al menos una variación. Un producto ya publicado que se agota **no** se despublica. El panel lista las incompletas (pantalla "Catálogo incompleto").

**Estados del producto**: `draft` (incompleto) → `publish` → (stock 0) `outofstock` visible con "Avísame".

### Familia de tono — término de `pa_tono`
Campos: nombre, slug, `swatch_id` (term meta), `subtono` (frío/neutro/cálido), `order`. Definidas una vez (FR-068).

### Paso de Full face — término de `aralia_paso`
Valores fijos: `piel-corrector`, `piel-rubor`, `piel-polvo`, `cejas`, `ojos`, `pestanas`, `labios`; `requires_tone` (term meta) true en corrector, rubor, cejas.

### Marca — término de `product_brand`
Term meta: `_ara_ff_visible` (bool), `_ara_ff_order` (int), `_ara_ff_featured` (bool, máx. 3), `_ara_ff_pins` (mapa paso → product_id fijado), `_ara_ff_image_id`.

---

## 2. Secciones y sets

### Sección — TAX `aralia_seccion`
Términos: `full-face`, `principiantes`, `makeup-no-makeup`, `cejas-perfectas`, `labios`, `retoque`, `surprised-bag`, `neceser`. Term meta: imagen de encabezado, línea de propuesta, `visible` (bool, calculado para Full face).

### Set — CPT `aralia_set`
| Campo | Detalle |
|-------|---------|
| `title`, `for_whom` | nombre y "para quién es" |
| `section` | término de `aralia_seccion` |
| `kind` | `loose` (piezas sueltas) · `fixed` (Cejas/Labios: apunta a un producto simple con stock y precio propios) |
| `fixed_product_id` | solo si `kind = fixed`; los componentes informativos viven en el meta `_ara_components` (lista de product_id) del producto fijo |
| `steps` | lista ordenada de {`step_key`, `default_product_id`, `alternatives[]`} (solo `loose`) |
| `price_adjustment` | entero COP (puede ser 0 o negativo) |
| `image_id` | foto del set |
| `status` (calculado) | `available` · `available_with_change` (alguna pieza por defecto agotada con alternativa) · `soldout` |

**Precio del set (FR-030)**: Σ precio actual de piezas por defecto + `price_adjustment`, calculado en servidor. "Piezas por separado $X · Tu set $Y" solo si Y < X (FR-029). Al cambiar el precio de un producto, se listan en el panel los sets que lo usan (consulta inversa por `steps`).

**Reglas**: en `fixed` de Cejas/Labios no se repite marca entre los `_ara_components` (RN-02), validado al guardar. El precio del set fijo es el del producto; si cambia el precio de un componente, el panel avisa para revisar el ángulo de valor (FR-029).

### Full face (calculado, no persistido salvo caché)
`FullFaceLook { brand_id, steps: [{step_key, product_id|null, variation_required}], total, eligible }` — `eligible` si pasos con stock ≥ `min_steps` (opción `aralia_ff_min_steps`, defecto 4). La sección es visible si `count(eligible) ≥ 2`. Caché: transient `ara_ff_v{n}` invalidado por stock, precio, publicación, pins.

### Surprised bag — WC producto variable
Atributo `pa_tipo_bolsa`; stock por variación; contenido nunca expuesto (descripción pública sin lista).

### Neceser personalizado — WC producto **simple** marcado con meta `_ara_kind = neceser` (sin tipo de producto propio)
| Dato de línea del carrito | Validación |
|---------------------------|-----------|
| `_ara_vinyl_text` | 1–14 caracteres de `[A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .&'-]`; recortado; sin espacios dobles |
| `_ara_vinyl_color` | uno de la lista configurada |
| `_ara_neceser_size` | uno de la lista configurada (P0: 1) |
| `_ara_group_id` | agrupa el neceser y sus piezas (≤ 6 líneas de piezas) |
| `_ara_no_return_ack` | true obligatorio antes de pagar (FR-042) |

---

## 3. Carrito y pedido

### Línea de carrito / ítem de pedido — WC
Meta propia: `_ara_group_id` (uuid), `_ara_set_id`, `_ara_set_kind`, `_ara_tone_term` (copia legible del tono), datos de neceser; en el pedido, `_ara_missing` (bool) para líneas sin stock en `ara-nostock`. **Precio siempre del catálogo** (`woocommerce_before_calculate_totals` ignora cualquier precio entrante). Persistencia: sesión de WooCommerce extendida a **30 días** para invitadas (`wc_session_expiration` / `wc_session_expiring`) + carrito persistente de usuario (FR-045); la limpieza nativa de sesiones vencidas sigue activa y se monitorea el tamaño de `wc_sessions`. Tope de 3 unidades iguales por línea (R-12).

### Pedido — WC order (HPOS)
| Campo | Almacenamiento | Clase |
|-------|----------------|-------|
| Contacto y envío (nombre, celular, correo, ciudad, dirección, indicaciones) | WC billing/shipping + campos adicionales del checkout | C |
| Regalo: `is_gift`, destinataria, celular, mensaje (≤ 90, texto plano), fecha deseada, `gift_auth_declared` | campos adicionales (`_wc_other/aralia/...`) | C |
| `age_declared`, `terms_version`, `newsletter_optin` | campos adicionales | C |
| Envío (`aralia_city_rate`) | WC shipping line | P |
| Total | calculado en servidor | — |
| Intentos de pago | meta `_ara_attempts` (lista de {n, reference `<order_id>-<n>`, transaction_id?, status, at}) | C |
| Vencimiento propio | meta `_ara_expires_at` (UTC; creación + 30 min, extensible hasta + 2 h) | — |
| Analítica | meta `_ara_ga_client_id`, `_ara_fbp`, `_ara_fbc`, `_ara_purchase_sent` | C |
| Reembolso/reversión manual | meta `_ara_refund_log` (lista de {tipo: refund_total, refund_partial, reversal; monto, fecha, motivo, ref_wompi, usuario}) | C |

**Máquina de estados (R-05)** — transiciones permitidas (cualquier otra se bloquea, se registra y alerta):

| Desde | Hacia | Disparador | Efecto |
|-------|-------|-----------|--------|
| `checkout-draft` | `pending` | Pedido creado | Descuenta stock; programa `aralia_order_expiry` (+30 min); correo "Pedido recibido" |
| `pending` | `pending` | Evento DECLINED / ERROR / VOIDED válido | Registra intento; correo "Pago rechazado" con reintento; ≤ 5 intentos |
| `pending` | `pending` | Vencimiento con Wompi PENDING | `_ara_expires_at` += 15 min (tope + 2 h) |
| `pending` | `processing` | APPROVED verificado (checksum + API + monto + moneda + referencia) | `payment_complete()` (no vuelve a descontar) |
| `pending` | `cancelled` | Vencimiento sin APPROVED ni PENDING (o tope de 2 h) | Restaura stock; correo "Reserva vencida" con "Volver a mi carrito" |
| `pending` | `cancelled` | Clienta: "Volver a mi carrito" tras un rechazo (sin intento PENDING) | Restaura stock; reconstruye el carrito |
| `cancelled` | `processing` | APPROVED verificado tardío, con stock para todas las líneas | Vuelve a descontar (`wc_reduce_stock_levels`); si el pedido tenía más de 24 h, alerta adicional |
| `cancelled` | `ara-nostock` | APPROVED verificado tardío, sin stock para alguna línea | `Orders/PartialStock`: descuenta lo disponible; `_ara_missing`; alerta + correo |
| `ara-nostock` | `processing` | Sara: "Resolver pedido → reemplazar" (igual o menor precio) | Descuenta el reemplazo; registra reembolso parcial de la diferencia |
| `ara-nostock` | `refunded` | Sara: reembolso total registrado | Restaura stock de lo descontado |
| `processing` | `ara-packed` | Sara: empacado + foto | Correo con foto |
| `ara-packed` | `ara-shipped` | Sara: transportadora + guía | Correo + SMS |
| `ara-shipped` | `completed` | Sara marca entregado, o automático a +10 días | — |
| `processing`, `ara-packed`, `ara-shipped`, `completed` | `refunded` | Reembolso total registrado (retracto, defecto, error) | Restaura stock solo si Sara indica que la pieza volvió en buen estado |
| cualquier pagado | (sin cambio) | Reembolso **parcial** registrado | Entrada en `_ara_refund_log` |
| `processing`, `ara-nostock`, `ara-packed`, `ara-shipped`, `completed` | `ara-reversed` | Reversión del pago registrada (Ley 1480 art. 51) | Alerta; conciliación |
| cualquier pagado | (sin cambio) | APPROVED verificado de **otro intento** del mismo pedido | Alerta crítica "posible doble cobro"; runbook `duplicate-charge` |
| `processing`, `ara-packed` | `refunded` | Evento VOIDED válido (anulación hecha por Sara en Wompi) | Alerta; registro automático en `_ara_refund_log` |
| `processing` o posterior | `cancelled` / `pending` | Evento DECLINED / ERROR posterior | **Bloqueada** + alerta (no hay retroceso) |

Estados "pagados" para WooCommerce: `processing`, `ara-nostock`, `ara-packed`, `ara-shipped`, `completed` (R-05).

### Reserva de stock
Descuento al crear el pedido y devolución al cancelarlo, con vencimiento propio por pedido (R-06). La reserva nativa de `wc_reserved_stock` solo protege la carrera durante el checkout.

---

## 4. Tablas propias (`aralia_`)

### `aralia_payment_events` — registro de eventos de pago (FR-054)
| Columna | Tipo | Nota |
|---------|------|------|
| `id` | bigint PK | |
| `received_at` | datetime UTC | |
| `source` | enum(`webhook`,`reconcile`,`widget_callback`,`return`,`expiry`) | |
| `transaction_id` | varchar(64) | índice **único** con `status_received` (idempotencia); nulo si el evento no trae id válido |
| `reference` | varchar(64) | |
| `order_id` | bigint | índice |
| `status_received` | enum(APPROVED, DECLINED, VOIDED, ERROR, PENDING) | |
| `amount_in_cents`, `currency` | bigint, char(3) | |
| `payment_method_type` | varchar(32) | |
| `checksum_valid` | bool | |
| `api_verified` | enum(`yes`,`no`,`deferred`,`n/a`) | verificación doble contra la API |
| `amount_match`, `currency_match`, `reference_match` | bool | |
| `raw_sanitized` | json | cuerpo sin datos de tarjeta (solo marca y últimos 4) |
| `action_taken` | varchar(64) | p. ej. `to_processing`, `blocked_regression`, `duplicate_ignored` |
| Retención | 5 años | sin datos de tarjeta (solo marca y últimos 4 si llegan) |

### `aralia_consents` — prueba de autorización (FR-075)
`id`, `subject_hash` (HMAC-SHA256 de correo/celular normalizado, R-23), `contact_enc` (cifrado con `ARALIA_PII_KEY`), `purpose` (enum: `order`, `stock_alert`, `early_access`, `newsletter`, `sms`), `text_version`, `channel`, `ip_hash`, `created_at`, `revoked_at`, `claim_in_progress` (bool, FR-083). Retención: tratamiento + 5 años.

### `aralia_stock_alerts` — "Avísame cuando vuelva" (FR-008)
`id`, `product_id`, `variation_id`, `subject_hash` (HMAC), `channel` (email/sms), `contact_enc`, `consent_id`, `created_at`, `notified_at`. Único (`product_id`, `variation_id`, `subject_hash`). Un set agotado registra un aviso por cada pieza agotada del set. Purga: `notified_at` + 90 días.

### `aralia_early_access` — lista anticipada (FR-013)
`id`, `subject_hash` (HMAC del correo; y `phone_hash`), `email_enc`, `phone_enc`, `consent_id`, `email_confirmed_at`, `phone_confirmed_at`, `created_at`, `brevo_synced_at`. Doble confirmación por correo; celular confirmado por código antes de cualquier SMS (R-12).

### `aralia_audit_log` — registro de actividad (R-3 del TM)
`id`, `at`, `user_id`, `ip_hash`, `action` (login, login_failed, price_change, order_status_change, refund_logged, reversal_logged, role_change, user_created, plugin_change, settings_change, blocked_transition), `object_type`, `object_id`, `before`, `after`. Retención ≥ 1 año; exportación semanal cifrada fuera del sitio (8.15).

### `aralia_rate_limits` — límites con incremento atómico (R-12)
`key_hash` + `bucket` (PK compuesta), `count`, `window_start`. Escritura con `INSERT … ON DUPLICATE KEY UPDATE`. Purga de ventanas vencidas cada hora.

**Total: 6 tablas propias** (`payment_events`, `consents`, `stock_alerts`, `early_access`, `audit_log`, `rate_limits`). Cifrado con `ARALIA_PII_KEY` y `subject_hash` con HMAC (R-23).

---

## 5. Configuración (opciones `aralia_*`)

| Opción | Defecto | Fuente |
|--------|---------|--------|
| `aralia_ff_min_steps` | 4 | 14-1 |
| `aralia_ff_min_brands` | 2 | RF-F5 |
| `aralia_new_days` | 21 | 14-14 |
| `aralia_free_shipping_threshold` | vacío (sin envío gratis) | 14-5 |
| `aralia_hold_minutes` | 30 | 14-9 |
| `aralia_hold_pending_step` / `aralia_hold_max` | 15 / 120 min | FR-056 |
| `aralia_max_pay_attempts` | 5 | R-12 |
| `aralia_auto_complete_days` | 10 | R-13 |
| `aralia_low_stock_label` | 3 | FR-071 |
| `aralia_neceser_max_pieces` | 6 | 14-7 |
| `aralia_rates` | {metro: [DEFINIR], nacional: [DEFINIR]} | 14-6 |
| `aralia_topbar` | {activo, texto, enlace} | FR-011 |
| `aralia_hero` | {sección o set, imagen, texto, botón} | FR-010 |

Las llaves y secretos **no** son opciones: viven en `aralia-secrets.php` (R-16).
