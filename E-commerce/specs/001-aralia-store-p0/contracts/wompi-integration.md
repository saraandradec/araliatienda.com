# Contrato de integración Wompi (plugin oficial 3.2.1 + `aralia-wompi-guard`)

**Feature**: [../spec.md](../spec.md) · **Research**: R-01, R-02, R-06, R-10, R-16 · **Fecha**: 2026-11-03 · **Versión**: 3

## 1. Reparto de responsabilidades

| Pieza | Responsable |
|-------|-------------|
| Registro del medio de pago (checkout clásico y de bloques), ajustes, `process_payment()` (crea el pedido y redirige a order-pay; vacía el carrito) | Plugin oficial |
| Estado "Anulado" (`wc-voided`) y anulación desde el panel (H13) | **Neutralizado** por el guard |
| Llaves (inyectadas desde `aralia-secrets.php`; nunca en la BD) | Guard |
| TLS verificado en toda llamada a `*.wompi.co` | Guard (`http_request_args`) |
| Apertura del widget en order-pay con referencia por intento y captura del id | **Guard** (reemplaza `woocommerce_receipt_wompi`) |
| Recepción y verificación de eventos, verificación doble por API, idempotencia, máquina de estados, stock | **Guard** (`woocommerce_api_wc_wompi`, prioridad 1, termina la petición) |
| Vencimiento y conciliación | Guard (Action Scheduler) |
| Reembolsos y anulaciones | **Sara en el panel de Wompi**; la tienda solo registra |

## 2. Flujo

```text
Checkout (bloques) ─ "Pagar" ─► plugin.process_payment(): pedido `pending`, carrito vaciado, → order-pay
  aralia-core (al crear): descuenta stock, _ara_expires_at = creación + 30 min, acción aralia_order_expiry
order-pay (guard):
  si algún intento con transaction_id está PENDING (consulta API) → "Tienes un pago en proceso", sin widget (R-02 §3b)
  si aperturas > 30 o rechazos ≥ 5 en la última hora → order-pay bloqueado
  n = mayor n existente + 1 (persistido ANTES de abrir el widget, por el POST `aralia/v1/order-pay/open`, bajo el candado del pedido; el último intento sin transacción de < 60 s se reutiliza); reference = "<order_id>-<n>"; firma = SHA256(reference + amount_in_cents + "COP" + integrity) calculada en el servidor
  no abre si: reserva vencida, pedido no `pending`, 30 aperturas/h, 5 rechazos/h, > 10 rechazos/h del origen (D-2), un intento de ESTE pedido con transacción PENDING/APPROVED, la API no responde, o la API aún no refleja un id verificado de < 10 min
  carga checkout.wompi.co/widget.js → new WidgetCheckout({publicKey, currency:"COP", amountInCents, reference,
        signature:{integrity}, redirectUrl: Gracias}).open(cb)
  cb(result): POST /aralia/v1/payment-attempt {order_id, order_key, reference, transaction_id} (pista)
  sin WidgetCheckout a los 8 s → mensaje de marca + reintentar
Wompi ─ POST transaction.updated ─► ?wc-api=wc_wompi → guard (prioridad 1):
  1 registrar (raw saneado) · 2 checksum · 3 resolver pedido desde reference · 4 idempotencia
  5 GET /v1/transactions/{id} → comparar status, amount_in_cents, currency, reference
  6 máquina de estados (data-model §3) · 7 responder y exit
Gracias: sin terceros; consulta /aralia/v1/order-status (no cambia estados)
aralia_order_expiry (creación + 30 min): consulta Wompi por cada transaction_id conocido →
  APPROVED → paso 5–6 · PENDING → +15 min (tope 2 h) · API caída o pista reciente que la API aún no conoce (`null`) → +5 min (tope 2 h) · resto, sin transacción, o todas las pistas ignoradas por falsas/ajenas (`NONE`) → cancelled (restaura stock)
Conciliación cada 5 min: pending y cancelled (≤ 24 h) con transaction_id cuyo estado verificado por la API no se refleja en el pedido → paso 5–6 (sin idem_key)
```

## 3. Verificación de un evento (guard)

1. Estructura: `event`, `data.transaction.{id, reference, amount_in_cents, currency, status}`, `signature.{properties[], checksum}`, `timestamp`. Inválida → registro + `400`.
   Los reenvíos de un evento cuyo resultado no ocupó `idem_key` (diferido, API atrasada, referencia desconocida) reutilizan UNA fila por transacción y estado (`PaymentEvents::reuse`, atómico, solo filas terminadas y sin alerta). **Registro saneado (lista blanca)**: solo se guardan en `raw_sanitized` `event`, `timestamp`, `data.transaction.{id, reference, amount_in_cents, currency, status, status_message, payment_method_type, created_at, finalized_at}` y `payment_method.extra.{brand, last_four}`. Se descartan `customer_email`, `customer_data`, `bin`, nombre del titular, `exp_*`, `shipping_address` y cualquier otro campo.
2. **Checksum**: concatenar los valores de `signature.properties` (en orden) + `timestamp` + secreto de eventos → SHA256 (hex, **normalizado a minúsculas** antes de comparar: la documentación de Wompi lo muestra en mayúsculas) → `hash_equals` con `signature.checksum` y con la cabecera `X-Event-Checksum` si viene. Inválido → registro (`checksum_valid=false`) + `401` + contador de alerta (R-22).
3. **Referencia**: debe cumplir `^\d+-\d{1,3}$`, el pedido debe existir, ser del gateway `wompi` y tener ese intento en `_ara_attempts`. Si no → registro + `200` (no reintentar) + alerta.
4. **Idempotencia** (atajo, ANTES del registro y solo contra filas TERMINADAS: un duplicado no deja fila nueva; la decisión final la toma la escritura de `idem_key` tras el paso 5): si ya existe una fila con `idem_key = "<transaction_id>|<estado>"` → `200`, `action_taken=duplicate_ignored`. El `transaction_id` se guarda en `_ara_attempts` aunque el evento quede `deferred`.
   **Regla de `idem_key`** (`aralia_payment_events.idem_key`, único): solo se escribe DESPUÉS del paso 5, cuando el checksum es válido y la API confirmó el estado, con `UPDATE` de la fila registrada en el paso 1. Si el `UPDATE` choca con el índice único (dos entregas simultáneas) → `duplicate_ignored` sin cambiar el pedido. Orden: primero se ocupa `idem_key` y después se cambia el estado del pedido (así dos procesos no lo cambian dos veces); si el proceso cae entre ambos pasos, el reintento NO se descarta como duplicado: mientras la fila no esté terminada responde 503 `idem_busy` (Wompi reintenta); la libera el cierre del proceso o, si murió sin cierre, la recuperación a los 15 min, y la conciliación (T063) lo repara si nada llega, que NO ocupa ni consulta `idem_key`: decide por el estado del pedido mediante la máquina de estados. Los eventos falsos o diferidos nunca llevan `idem_key`, así que no bloquean al legítimo.
5. **Verificación doble**: `GET /v1/transactions/{id}` (llave según T-API-AUTH, timeout 8 s, TLS verificado). Debe coincidir: `status`, `amount_in_cents` = total del pedido × 100, `currency = COP`, `reference`. Si la API no responde → `api_verified=deferred`, `200`; la conciliación termina el trabajo. Si no coincide → sin cambio + alerta crítica.
6. **Máquina de estados**: transición según `data-model.md` §3; bloqueadas → registro + alerta.
7. Respuesta `200` en cualquier caso procesado o diferido.

## 4. Hooks del guard

| Hook | Propósito |
|------|-----------|
| `http_request_args` (solo hosts `*.wompi.co`) | `sslverify => true` (H2) |
| `option_woocommerce_wompi_settings` / `pre_update_option_woocommerce_wompi_settings` | Leer llaves desde constantes; vaciar los campos de llaves antes de guardar (H6) |
| `woocommerce_receipt_wompi` | `remove_action` del plugin + render propio (H4) |
| `woocommerce_api_wc_wompi` (prioridad 1) | Manejador propio de eventos; `exit` (H3, H5, H8, H9) |
| `woocommerce_cancel_unpaid_order` | `false` para pedidos `wompi` (R-06) |
| `woocommerce_checkout_order_processed`, `woocommerce_store_api_checkout_order_processed` | Descuento de stock + programación del vencimiento |
| `woocommerce_payment_gateways` / `woocommerce_order_actions` | Sin reembolsos ni anulación desde la tienda (H7) |
| `woocommerce_valid_order_statuses_for_order_again` | Permitir "Volver a mi carrito" desde `cancelled` (H12) |
| `woocommerce_process_shop_order_meta` (`remove_action` del plugin) + `wc_order_statuses` (quitar `wc-voided`) | Sin anulación desde la tienda (H13) |
| `woocommerce_checkout_order_processed` y `woocommerce_store_api_checkout_order_processed` (después de `wc_reduce_stock_levels`) | `wc_release_stock_for_order()` para no contar dos veces (R-06); T-STOCK-DOUBLECOUNT corre con el checkout de bloques |
| `woocommerce_order_status_changed` | Auditoría y mensajes |

## 5. Llaves (fuera de la BD y del repo)

| Constante | Uso | Clase |
|-----------|-----|-------|
| `ARALIA_WOMPI_PUB_{TEST,PROD}` | Widget (navegador); consulta si T-API-AUTH lo permite | P |
| `ARALIA_WOMPI_PRV_{TEST,PROD}` | Consulta de transacción si la pública no basta; `/merchants` del plugin | **R** |
| `ARALIA_WOMPI_EVENTS_{TEST,PROD}` | Checksum de eventos | **R** |
| `ARALIA_WOMPI_INTEGRITY_{TEST,PROD}` | Firma de integridad (servidor) | **R** |

## 6. Pruebas de contrato (obligatorias)

| ID | Escenario | Resultado esperado |
|----|-----------|--------------------|
| T-PAY-OK | Aprobada por cada medio (tarjeta, PSE, Botón Bancolombia, Nequi) | `processing`; correo y SMS |
| T-PAY-DECLINED | Rechazada por cada medio | `pending`; intento registrado; correo de reintento |
| T-PAY-RETRY | Rechazo y reintento con otro medio en el mismo pedido | Nueva referencia `<id>-2`; aprobado → `processing` |
| T-PAY-MAXATTEMPTS | 6.º intento | order-pay bloqueado 1 h |
| T-API-AUTH | `GET /v1/transactions/{id}` con llave pública y con privada (Sandbox) | Decide la llave de consulta (R-02) |
| T-EVT-FORGED | Checksum alterado | `401`; sin cambio; registro `checksum_valid=false` |
| T-EVT-AMOUNT | Checksum válido (secreto de prueba) y monto distinto; o API devuelve otro monto | Sin cambio; alerta |
| T-EVT-CURRENCY | Moneda ≠ COP | Sin cambio; alerta |
| T-EVT-REF | Referencia inexistente o de otro gateway | `200`; sin cambio; alerta |
| T-EVT-DUP | Mismo evento dos veces | Un solo cambio |
| T-EVT-REGRESSION | DECLINED después de APPROVED | Bloqueado; alerta |
| T-EVT-API-DOWN | API de Wompi caída durante el evento | `deferred`; la conciliación lo resuelve |
| T-EVT-LATE-NOSTOCK | APPROVED tras `cancelled` sin stock | `ara-nostock`; descuento parcial; correo; alerta |
| T-EVT-LATE-STOCK | APPROVED tras `cancelled` con stock | `processing` |
| T-HOLD-TIMING | Pedido sin pago, modificado a los 20 min | `cancelled` entre 30 y 35 min desde la creación |
| T-HOLD-PENDING | PSE pendiente al vencer | Extensión de 15 min; cancelación a las 2 h si sigue pendiente |
| T-RECONCILE | Endpoint caído durante la aprobación | `processing` en ≤ 10 min por conciliación |
| T-WIDGET-DOWN | `checkout.wompi.co` bloqueado | Mensaje de marca + reintento |
| T-CART-BACK | Pedido `cancelled` → "Volver a mi carrito" | Carrito reconstruido con las mismas piezas y tonos |
| T-CART-BACK-PENDING | Pedido `pending` tras un rechazo, sin intento PENDING → "Volver a mi carrito" | Pedido `cancelled`, stock restaurado, carrito reconstruido |
| T-NO-BYPASS | Retorno con `?id=` de una transacción APPROVED; guardar en el panel con estado "Anulado" | Solo el guard cambia estados; no aparece "Anulado" ni se llama a `/void` |
| T-PAY-DOUBLE | Intento 1 PSE PENDING; intentar abrir intento 2 | No abre; si se fuerzan dos APPROVED, el segundo genera alerta y no cambia el estado |
| T-PAY-ORIGIN-LIMIT | 6 y 11 rechazos en 1 h desde una IP con pedidos distintos | Desafío visible; luego bloqueo 1 h |
| T-PSE-ABANDON | PSE iniciado, la clienta no vuelve, aprobado a los 45 min | Pedido cancelado → aprobado tardío (`processing` o `ara-nostock`); KPI registrado |
| T-HOLD-WIDGET-OPEN | Widget abierto a los 27 min | Extensión única a +5 min, sin pasar de 35 min |
| T-STOCK-DOUBLECOUNT / T-STOCK-FLAGS | Ver R-06 | Stock exacto en cada paso |
| T-SSL | Cualquier petición a `*.wompi.co` | `sslverify=true` |
| T-KEYS | HTML, JS, BD exportada y repo | Sin llaves R (SC-015) |
| T-CONTRACT | Superficie del plugin (R-01) | La prueba falla si cambia un hook, opción o id |
