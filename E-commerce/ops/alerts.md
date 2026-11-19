# Matriz de alertas (T029, research R-22)

Destinataria: Sara. Fuente: `aralia-core` (clase `Aralia\Security\Alerts`) y Wordfence. **Crítico** = correo + SMS; **aviso** = solo correo.

| Evento (`id`) | Umbral | Severidad | Quién lo levanta |
|---|---|---|---|
| `nostock` | cada pedido en `ara-nostock` | Crítico | Orders (T053/T056) |
| `blocked_transition` | una por hora (agregada) | Crítico | StateMachine (T054) |
| `checksum_invalid` | > 5 por hora | Crítico | Guard (T060) |
| `payment_declines` | > 20 por hora en toda la tienda | Crítico | Guard / AbuseLimits |
| `pending_unconfirmed_at_cap` | pedido cancelado por el tope de 2 h estando «en proceso» (pista del widget sin evento firmado) | Alto | `Expiry` (T058); revisar en Wompi si el pago fue real |
| `deferred_unresolved` | evento diferido sin resolver > 15 min | Crítico | Conciliación (`Reconciler`, T063; T060 solo marca el evento como diferido) |
| `expiry_late` | `aralia_order_expiry` atrasada > 5 min | Crítico | Vigilancia de Action Scheduler (T058: cablear `Alerts::raise( 'expiry_late' )`) |
| `payment_mismatch` | cada evento cuyo monto, moneda, referencia o estado no coincide con el pedido o con la API de Wompi, o con referencia desconocida | Crítico | Guard (T060, **cableado**) |
| `duplicate_charge` | otro intento APPROVED sobre un pedido ya pagado (posible doble cobro; runbook `duplicate-charge`, T064) | Crítico | Guard (T060, **cableado**) |
| `payment_voided` | evento VOIDED verificado sobre un pedido pagado | Crítico | Guard (T060, **cableado**) |
| `late_payment` | pago aprobado tardío en un pedido cancelado de más de 24 h | Aviso | Guard (T060, **cableado**) |
| `script_critical` | nuevo `<script src>`, cambio de `widget.js` o de las cabeceras de seguridad | Crítico | `tools/script-monitor` |
| `script_inline` | cambio en un script inline normalizado | Aviso (revisar en 48 h) | `tools/script-monitor` |
| `admin_role_change` | administrador creado, o cambio de rol, de correo o de contraseña (incluido «olvidé mi contraseña» y `wp_set_password`), o borrado, de un administrador; también al DEGRADAR a uno (`set_role`, `remove_role`), cuando las capacidades se escriben como metadato y CONCEDEN privilegios (`add_cap`, `update_user_meta`; se evalúan las claves como lo hace WordPress, aunque el valor sea `false`), cuando un rol gana una capacidad privilegiada en la opción `{prefijo}user_roles` y cuando cambia el rol administrador. Capacidades privilegiadas: `manage_options`, `promote_users`, `edit_users`, `create_users`, `delete_users`, `install_plugins`, `activate_plugins`, `edit_plugins`, `update_plugins`, `unfiltered_html`, `update_core`. El nombre de la clave del metadato y de la opción de roles se compara sin distinguir mayúsculas ni espacios finales (la colación de MySQL los trata como iguales). También alerta borrar la opción de roles o quitar el rol administrador de la definición. NO alerta al QUITAR privilegios por metadato ni ante escrituras directas con `$wpdb` (RR-18). Una sola alerta por usuario y petición (la marca vive lo que viva la instancia: en una ejecución larga de WP-CLI o Action Scheduler un segundo evento sobre el mismo administrador no vuelve a alertar). Un restablecimiento de contraseña deja dos filas `user_changed` (`wp_set_password` y `after_password_reset`) y una sola alerta | Crítico | AuditLog (**cableado**: salta al crear un usuario administrador o cambiar a uno) |
| `new_device_login` | inicio de sesión en el panel desde un dispositivo nuevo | Aviso | Wordfence |
| `vulnerability` | vulnerabilidad crítica o alta en un componente | Crítico | Wordfence |
| `backup_failed` | cualquiera | Aviso | UpdraftPlus |
| `low_stock` | ≤ 3 unidades | Aviso (resumen diario) | Catálogo |

## Cómo funciona

- `Alerts::raise( $evento, $referencia )` valida el evento contra esta matriz (uno desconocido lanza excepción) y, si tiene umbral, cuenta en `aralia_rate_limits`: **salta una sola vez por ventana**, al cruzar el umbral.
- La entrega se encola en Action Scheduler (`aralia_deliver_alert`) para no frenar la petición. **Excepciones**: las alertas de identidad (`admin_role_change`) se entregan al instante, sin cola (quien tenga una sesión robada podría borrar la acción pendiente); y si la cola no está disponible o falla al encolar, la alerta se entrega al instante. Una entrega fallida deja la acción como **fallida** en Action Scheduler, que NO la reintenta: revisar Herramientas → Acciones programadas → Fallidas. Cada alerta queda en el registro de actividad (`alert_raised`).
- **Sin datos personales**: el mensaje lleva el nombre del evento y una referencia saneada (p. ej. «pedido 123»). Nunca correos, celulares ni nombres.
- Destinos: constantes `ARALIA_ALERT_EMAIL` y `ARALIA_ALERT_PHONE` en `aralia-secrets.php`. Las opciones `aralia_alert_email` y `aralia_alert_phone` solo valen en local y test. Sin celular, una alerta crítica sale solo por correo y queda un `error_log`.
- SMS por Brevo: solo móviles colombianos (+57 3XX). Las clientas tienen un tope de 3 por número y día; las alertas a la administradora, uno global de 30 por día, aparte.

## Estado del cableado

Conectados hoy: `admin_role_change` (AuditLog), `blocked_transition`, `nostock`, `expiry_late`, `pending_unconfirmed_at_cap` y `deferred_unresolved` (Orders, T053–T058) y `checksum_invalid`, `payment_mismatch`, `duplicate_charge`, `payment_voided`, `late_payment` y `payment_declines` (guard, T060). Los demás eventos los levantarán las tareas de su flujo: `deferred_unresolved` y `late_payment` (aprobado de más de 24 h) también desde la conciliación (T063), `script_*` (T138), `low_stock` (T094), copias (T149). Hasta entonces, **no hay alerta** para ellos.

## Entrega y destinos

- Los destinos salen **solo de constantes** de `aralia-secrets.php` fuera de local/test (`ARALIA_ALERT_EMAIL`, `ARALIA_ALERT_PHONE`): una opción editable desde wp-admin permitiría desviar las alertas con una sesión robada. `wp aralia baseline check` lo exige (`alert_destinations`).
- Correo: Brevo; si Brevo falla o no está configurado, respaldo con `wp_mail`. SMS: tope **global** de 30 por día para alertas, aparte del tope de 3 por número y día de las clientas.
- La severidad sale de la matriz, no de lo que traiga la cola de Action Scheduler.

## Pendiente de configurar (T012, T016)

Cuenta de Brevo y remitente; `ARALIA_BREVO_API_KEY`; confirmar el costo y el remitente de SMS a Colombia (T016); `ARALIA_ALERT_EMAIL` y `ARALIA_ALERT_PHONE` de Sara.
