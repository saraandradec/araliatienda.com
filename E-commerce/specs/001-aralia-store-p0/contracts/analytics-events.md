# Contrato de analítica — eventos GA4 / Meta

**Feature**: [../spec.md](../spec.md) · FR-072, FR-073, R-14 · **Fecha**: 2026-11-03 · **Versión**: 2

| Evento | Dónde se dispara | Navegador | Servidor | `event_id` | Parámetros |
|--------|------------------|-----------|----------|------------|-----------|
| `view_item_list` | Listados, secciones | Sí | No | — | `item_list_id`, items[≤ 12] |
| `view_item` | Ficha | Sí | No | — | item |
| `select_tone` | Selector de tono | Sí | No | — | `tone_family`, `context` |
| `add_to_cart` | "Agregar" | Sí | No | `ara-atc-<uuid>` | item, `quantity` |
| `add_set` | "Agregar todo" | No | Sí | `ara-set-<group_id>` | `source`, `source_id`, items |
| `view_cart` | Abrir carrito lateral | Sí | No | — | items, `value` |
| `begin_checkout` | Carga del checkout | **No** (página sin terceros) | Sí | `ara-<order_or_session>-bc` | items, `value` |
| `add_payment_info` | Pedido creado → order-pay | **No** | Sí | `ara-<order>-api` | `payment_type`=wompi |
| `purchase` | Primer paso del pedido a un estado pagado (`processing` o `ara-nostock`) | **No** (Gracias sin terceros) | Sí | `ara-<order>-purchase` | `transaction_id`=order, `value`, `shipping`, items; `client_id` GA4 y `fbp`/`fbc` guardados en el pedido |
| `gift_mode_on` | Activar "Es un regalo" | No | Sí (al crear el pedido) | `ara-<order>-gift` | — |
| `join_early_access` | Registro confirmado | No | Sí | `ara-ea-<id>` | `channel` |

**Reglas**
- Nunca se carga `gtag.js` ni el Pixel en checkout, order-pay ni Gracias (la CSP de R-11 lo impide de todos modos). Advanced matching automático del Pixel desactivado; `page_location` sin parámetros `key=` ni datos personales.
- Datos de usuario a Meta CAPI: `em` y `ph` normalizados y con hash SHA-256; nada en claro; sin dirección.
- `purchase` se envía una sola vez por pedido, en el primer paso a un estado pagado (bandera `_ara_purchase_sent`); un reembolso posterior no genera un segundo `purchase`; prueba SC-012.
- Consentimiento: sin consentimiento de analítica, el navegador no carga etiquetas; los eventos de servidor se envían sin identificadores de usuario (solo agregados de comercio).
