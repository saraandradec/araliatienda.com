# Contrato REST propio — namespace `aralia/v1`

**Feature**: [../spec.md](../spec.md) · **Fecha**: 2026-11-03 · **Versión**: 3

Reglas comunes a todos los endpoints:
- Solo HTTPS. Respuestas JSON. Sin datos personales en la URL.
- Todo lo que modifica el carrito usa la **Store API de WooCommerce** (nonce por cabecera renovado en cada respuesta, compatible con páginas en caché, R-21). Los endpoints `aralia/v1` que modifican datos exigen nonce de WP (`X-WP-Nonce`) obtenido en tiempo de ejecución o la clave del pedido; sin ellos → `403` (CSRF).
- **Ningún** endpoint acepta precio, total, descuento ni costo desde el cliente; el servidor los calcula (FR-053).
- Errores: `{ "code": "<slug>", "message": "<texto de marca para la clienta>" }` sin detalles técnicos.
- Límites de frecuencia por IP + sesión (R-12): `429` con `Retry-After`.
- Endpoints públicos de lectura sin sesión (`fullface`, `sets`, `search/suggest`, `availability`): cacheables 60 s y purgados por etiqueta. Los de sesión o pedido nunca se cachean.

---

## 1. "Agregar todo" — `POST /wc/store/v1/cart/extensions` con `namespace: "aralia"`
Extensión oficial de la Store API (`ExtendSchema::register_update_callback`). Agrega un set, un Full face o un neceser como líneas separadas agrupadas. El cuerpo va en `data`:

**Request**
```json
{
  "source": "set" | "fullface" | "neceser",
  "source_id": 123,                       // set_id | brand term_id | neceser product_id
  "tone": "canela",                       // opcional; obligatorio si alguna pieza depende del tono
  "omit_steps": ["pestanas"],             // opcional (Cambiar piezas: quitar)
  "swaps": { "labios": 456 },             // opcional (Cambiar piezas: misma marca/paso o mismo paso)
  "neceser": { "vinyl_text": "Valen", "vinyl_color": "dorado", "size": "unico", "pieces": [11,12], "no_return_ack": true }
}
```
**Validaciones**: `source_id` existente y visible; para `fixed` se agrega 1 línea del producto del set; `swaps` solo con productos del mismo paso (y misma marca en Full face); stock disponible por pieza; si falta `tone` y alguna pieza lo requiere → `409 tone_required` con la lista de pasos; neceser: reglas de `data-model.md`; ≤ 6 piezas.
**Response 200**
```json
{ "group_id": "uuid", "lines_added": 5, "skipped": [{"step":"ojos","reason":"soldout","alternative_id":789}], "cart": { /* Store API cart */ } }
```
Si el tono elegido está agotado en un paso: el paso va en `skipped` con `reason: "tone_soldout"` y `alternatives: [{variation_id|product_id}]` (otro tono de la misma pieza u otra pieza de la misma marca y paso).
**Errores** (`RouteException`): `409 tone_required`, `409 soldout` (sin alternativa), `422 invalid_swap`, `422 invalid_vinyl_text`, `429`.
**Analítica**: emite `add_set` (servidor) con `event_id`.

## 2. `GET /aralia/v1/fullface`
Lista de looks elegibles. **Response**: `{ "visible": true, "looks": [{ "brand": {"slug":"atenea","name":"Atenea"}, "url": "/full-face/atenea", "image": "...", "steps": [{"step":"piel-corrector","product_id":1,"name":"...","price":45000,"requires_tone":true}], "total": 210000 }] }`. Nunca expone el número de marcas como texto; `visible=false` si < 2 elegibles.

## 3. `GET /aralia/v1/fullface/{brand_slug}`
Un look; `404` si la marca no es elegible u oculta.

## 4. `GET /aralia/v1/sets/{id}`
Set con piezas, alternativas por paso, `price_total`, `price_separate`, `show_value_angle` (bool), `status`.

## 5. `GET /aralia/v1/search/suggest?q=`
`q` 2–60 caracteres. **Response**: `{ "categories": [{"name","url"}], "products": [{"id","name","brand","price","image","url"}] }` (máx. 3 + 6). Tolerante a errores (R-09). Rate limit 30/min/IP.

## 6. `POST /aralia/v1/stock-alert` — "Avísame cuando vuelva"
**Request**: `{ "product_id", "variation_id"?, "channel": "email"|"sms", "contact", "consent": true, "turnstile_token" }`.
**Validaciones**: Turnstile válido; `consent` true; correo RFC / celular colombiano `^3\d{9}$`; producto agotado; deduplicación por (producto, variación, contacto).
**Response**: `202 { "status": "ok" }` (misma respuesta si ya existía, para no filtrar existencia). Correo: doble confirmación.

## 7. `POST /aralia/v1/early-access`
**Request**: `{ "email"?, "phone"?, "consent_marketing": true, "consent_sms"?: bool, "turnstile_token" }` (al menos uno de correo o celular). **Response** `202`. Doble confirmación por correo.

## 7b. `GET /aralia/v1/availability?ids=1,2,3`
Disponibilidad pública para páginas cacheadas: `{ "<id>": { "in_stock": true, "low": false } }` (`low` = stock ≤ umbral, FR-071). Máx. 48 ids. Cacheable 60 s; purgado por `CacheInvalidator`. No expone cantidades exactas.

## 8. `POST /aralia/v1/order-status`
Cuerpo `{ "order", "key" }`. Para la página "Gracias" mientras se espera la confirmación del evento. Requiere `order_key` (no adivinable) **y** la cookie de sesión de WooCommerce con la que se creó el pedido; sin datos personales en la URL; **nunca** cambia el estado. **Response**: `{ "status": "pending"|"paid"|"declined_retry"|"expired"|"nostock" }` (`declined_retry` = último intento rechazado, pedido aún `pending`). Rate limit 20/min.

## 8b. `POST /aralia/v1/payment-attempt` — pista desde el callback del widget
**Request**: `{ "order_id", "order_key", "reference", "transaction_id" }`. Valida `order_key`, que `reference` sea un intento del pedido y el formato del id. Solo guarda el `transaction_id` en `_ara_attempts` y programa una consulta inmediata a Wompi; **nunca** cambia estados. `202`. Rate limit 10/min por pedido.

## 8c. `POST /aralia/v1/order-again` — "Volver a mi carrito"
**Request**: `{ "order_id", "order_key" }`. Para pedidos propios `cancelled`, o `pending` con el último intento rechazado y ningún intento PENDING (en ese caso primero cancela el pedido y restaura el stock). Reconstruye el carrito (mismas piezas, tonos y agrupación) con precios y stock actuales. `200` con el carrito.

## 9. Endpoints de administración (`manage_woocommerce`, nonce, auditados)
- `POST /aralia/v1/admin/orders/{id}/packed` — `{ "photo_id" }` → `ara-packed` + correo con foto.
- `POST /aralia/v1/admin/orders/{id}/shipped` — `{ "carrier", "tracking" }` → `ara-shipped` + correo/SMS.
- `POST /aralia/v1/admin/orders/{id}/refund-log` — `{ "type": "refund_total"|"refund_partial"|"reversal", "amount", "reason", "wompi_ref", "restock": bool }` → solo **registra** (no llama a Wompi, FR-058); `refund_total` → `refunded`; `reversal` → `ara-reversed`; `refund_partial` no cambia el estado.
- `POST /aralia/v1/admin/orders/{id}/resolve-nostock` — `{ "replacements": {item_id: product_id} }` o `{ "refund_total": true }` (R-24).
- `POST /aralia/v1/admin/orders/{id}/delivered` → `completed`.
- `POST /aralia/v1/admin/fullface/{brand}` — `{ "visible", "order", "featured", "pins": {step: product_id} }`.

Todas las transiciones pasan por la máquina de estados (data-model §3).

---

## 10. Endpoints que se **deshabilitan o restringen**
- `GET /wp/v2/users` y archivos de autor públicos → `404` (I-7).
- XML-RPC → deshabilitado.
- `wc/store/v1/products` y `wc/v3/*`: verificar que `_ara_cost` no aparece (prueba I-3).
- Acciones de reembolso/anulación del gateway Wompi en el panel → retiradas (H7).
