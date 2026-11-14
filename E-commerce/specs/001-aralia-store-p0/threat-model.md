# Threat Model (STRIDE) — Aralía Beauty Store P0

**Feature**: [spec.md](./spec.md) · **Tier**: T0 · **Fecha**: 2026-11-03 · **Versión**: 1.3
**Método**: STRIDE por límite de confianza, sobre el diseño decidido (tienda WooCommerce, pago con el **widget alojado por Wompi** abierto desde la página order-pay de la tienda, eventos procesados por `aralia-wompi-guard`, una administradora).
**Cambios v1.3 (2026-11-13)**: RR-8 a RR-14 (despliegue local, solo código propio, terceros por Hostinger/wp-admin; ver `ops/runbooks/deploy.md`). **Cambios v1.2**: H13 (anulación desde el panel neutralizada), límite de rechazos por origen, intentos paralelos, RR-6 y RR-7. **Cambios v1.1**: el pago es por widget (no redirección) tras verificar el código del plugin (research R-01); LC-4, T-2 y RR-4 actualizados; nuevas amenazas T-10, T-11, S-7; controles alineados con research v2.

---

## 1. Alcance y diagrama de contexto

```text
                     ┌──────────────────────── Hosting (Hostinger) ───────────────────────┐
 Clienta (celular)   │                                                                     │
 ── HTTPS ────────►  │  Tienda pública (WP + WooCommerce + tema a medida + plugins)        │
   [LC-1]            │    · catálogo, carrito, checkout de una página                      │
                     │    · endpoint de eventos de pago  ◄──────── Wompi (eventos firmados) │ [LC-3]
                     │    · cron: liberación de reservas, avisos de stock                  │
 Sara (admin) ─2FA─► │  Panel /wp-admin                                                     │ [LC-2]
                     │  Base de datos (pedidos, PII, consentimientos, catálogo, costo)      │
                     │  Secretos: llaves privada/integridad/eventos de Wompi, SMTP, SMS     │
                     └───────┬───────────────┬──────────────────┬──────────────────────────┘
                             │ widget.js      │ API transacciones │ correo/SMS     │ analítica
                             ▼  [LC-4]        ▼  [LC-3]           ▼ [LC-5]         ▼ [LC-6]
                     Wompi Widget (iframe Wompi API          Proveedor correo   GA4 / Meta (navegador
                     (datos de tarjeta)   (consulta estado)  y SMS/WhatsApp     y API de conversiones)
                                                                                 + CSV de inventario [LC-7]
```

**Límites de confianza (LC)**

| ID | Límite | Qué cruza |
|----|--------|-----------|
| LC-1 | Navegador de la clienta ↔ tienda | Carrito, datos de contacto y envío, regalo, nombre de neceser, tono, códigos |
| LC-2 | Navegador de Sara ↔ panel | Sesión administradora, catálogo, precios y costo, pedidos con PII |
| LC-3 | Wompi ↔ tienda (servidor a servidor) | Eventos de transacción firmados; consulta de estado con llave |
| LC-4 | Página order-pay de la tienda ↔ widget de Wompi (`checkout.wompi.co/widget.js` + iframe alojado) | Referencia por intento, monto en centavos, moneda, firma de integridad, URL de retorno; id de transacción devuelto al callback |
| LC-5 | Tienda → proveedores de correo y SMS | PII de contacto y contenido del pedido |
| LC-6 | Navegador/servidor → analítica | Eventos de comercio; identificadores con hash |
| LC-7 | Archivo CSV → importador | Catálogo completo (incluye costo) |
| LC-8 | Cadena de suministro | Núcleo de WP, plugins (WooCommerce, Wompi, SMTP, anti-bots), tema, actualizaciones |

**Activos críticos**

| ID | Activo | Por qué importa |
|----|--------|-----------------|
| A1 | Estado "pagado" del pedido | Despachar sin cobro = pérdida directa |
| A2 | Monto cobrado | Pagar menos que el precio real |
| A3 | Llaves privada, de integridad y de eventos de Wompi | Firmar pagos falsos, consultar o alterar transacciones |
| A4 | Cuenta administradora | Control total: precios, pedidos, PII, plugins (ejecución de código) |
| A5 | PII de clientas y destinatarias | Ley 1581; reputación |
| A6 | Stock y reservas | Bloqueo de ventas por reservas falsas |
| A7 | Costo interno y margen | Confidencial comercial |
| A8 | Integridad de la página de checkout | Inyección de scripts que redirijan a una pasarela falsa (PCI 6.4.3 / 11.6.1) |
| A9 | Disponibilidad durante picos de redes | Ventas perdidas |

---

## 2. Amenazas STRIDE

Escala: Probabilidad (B/M/A) × Impacto (B/M/A). **Control** = requisito del spec o control a diseñar en el plan.

### S — Spoofing (suplantación)

| ID | Amenaza | LC | P×I | Control |
|----|---------|----|-----|---------|
| S-1 | Atacante envía un evento de pago "APPROVED" falso al endpoint de eventos | LC-3 | A×A | FR-052: verificar checksum SHA-256 del evento con el secreto de eventos; además, consultar el estado a la API de Wompi por id de transacción antes de marcar pagado; comparar monto, moneda y referencia (FR-052, SC-010) |
| S-2 | Atacante manipula la URL de retorno (`?id=…&status=APPROVED`) para ver "Gracias" o marcar pagado | LC-1 | A×A | El retorno del navegador nunca cambia estado; solo se consulta al servidor (Edge cases; FR-052) |
| S-3 | Robo o adivinación de credenciales de la administradora (fuerza bruta, phishing) | LC-2 | M×A | FR-078: 2FA obligatorio (TOTP/llave), límite de intentos, URL de login no estándar opcional, alertas de inicio de sesión, contraseña única de gestor |
| S-4 | Suplantación de la tienda por correo (phishing a clientas usando el dominio) | LC-5 | M×M | SPF, DKIM y DMARC (p=quarantine o mayor) en el dominio de envío |
| S-5 | Página de pago falsa por phishing en redes (dominio parecido) | externo | M×M | Mensajes con un solo enlace al dominio oficial; política pública de "nunca pedimos pagos por WhatsApp" |
| S-6 | Bots que se hacen pasar por clientas para crear pedidos pendientes | LC-1 | A×M | FR-060: verificación anti-bots invisible y límites por celular, correo y origen |
| S-7 | Clickjacking: la tienda (order-pay o checkout) embebida en un sitio ajeno | LC-1 | B×M | `frame-ancestors 'self'` en la CSP (R-11) |

### T — Tampering (alteración)

| ID | Amenaza | LC | P×I | Control |
|----|---------|----|-----|---------|
| T-1 | Clienta altera precio, descuento, envío o total en la petición | LC-1 | A×A | FR-053: total calculado solo en servidor; set y Full face recalculados desde catálogo |
| T-2 | Alteración del monto o la referencia en los parámetros del widget | LC-4 | A×A | Firma de integridad (SHA-256 de referencia+monto+moneda+secreto) generada en servidor; el guard compara monto, moneda y referencia del evento **y** de la API contra el pedido (R-02) |
| T-10 | `widget.js` de Wompi alterado (compromiso del TPSP o de su CDN) o reemplazado por un script de la tienda | LC-4/LC-8 | B×A | Monitoreo diario del **contenido** de `widget.js` y del conjunto de scripts de order-pay (R-11); CSP estricta con nonce en order-pay; alerta crítica |
| T-11 | Modal falso superpuesto (script inyectado que imita el widget y captura la tarjeta en el DOM de la tienda) | LC-4 | B×A | CSP estricta sin terceros en order-pay (solo Wompi), `connect-src`/`form-action` restringidos, monitoreo de scripts (R-11); evidencia de que los campos reales viven en un iframe de Wompi (R-19) |
| T-3 | Inyección de scripts en el checkout (plugin vulnerable, tercero comprometido) que capture datos o redirija a otra pasarela | LC-8 | M×A | FR-073: sin etiquetas de terceros en checkout, order-pay ni Gracias; inventario de scripts + CSP en todo el sitio + SRI en los scripts propios versionados (no aplica a `widget.js`, cubierto por T-10); monitoreo diario (research R-11) |
| T-12 | Anulación de un pago desde el panel de la tienda con la llave privada (estado "Anulado" del plugin) | LC-2 | M×A | Neutralizado (research R-01 H13); prueba T-NO-BYPASS |
| T-13 | Doble cobro por dos intentos aprobados del mismo pedido | LC-4 | M×M | No se abre un intento con otro PENDING conocido; si el primer intento no dejó id (callback no llegó), el caso solo se cubre por **detección**: alerta crítica y runbook de reembolso (research R-02 §3b) |
| T-4 | Cambio de cantidades para comprar más que el stock o el tope | LC-1 | M×M | Validación de stock y tope por línea en servidor; FR-060 |
| T-5 | XSS almacenado vía mensaje de regalo, nombre del neceser, indicaciones o campos de aviso, ejecutado en el panel de Sara | LC-1→LC-2 | M×A | FR-041 (lista blanca de caracteres), texto plano con escape en salida en panel, hoja de empaque y correos; longitud máxima por campo |
| T-6 | CSV malicioso (fórmulas, scripts, tipos inválidos) altera precios o inyecta contenido | LC-7 | B×A | Importador con validación por columna, vista previa con diferencias de precio antes de aplicar, sanitización, solo administradora |
| T-7 | Código de descuento forzado por fuerza bruta | LC-1 | M×M | Límite de intentos por origen y sesión; códigos largos no adivinables; vencimiento y usos máximos |
| T-8 | Evento de pago repetido (replay) o fuera de orden cambia el estado | LC-3 | M×A | FR-054: idempotencia por id de transacción; máquina de estados que no retrocede de pagado sin revisión |
| T-9 | Actualización maliciosa o vulnerable de plugin/tema | LC-8 | M×A | Lista mínima de plugins de proveedores confiables; staging para actualizar; escáner de vulnerabilidades; edición de archivos desde el panel deshabilitada |

### R — Repudiation (repudio)

| ID | Amenaza | LC | P×I | Control |
|----|---------|----|-----|---------|
| R-1 | Clienta niega haber pagado o haber recibido; disputa (contracargo) | LC-3 | M×M | FR-054: registro de cada evento de pago; guía y foto de empaque (FR-062); evidencia de aceptación de términos |
| R-2 | Titular niega haber autorizado el tratamiento de datos | LC-1 | M×M | FR-075: prueba de consentimiento (texto, versión, fecha, canal) |
| R-3 | No hay forma de saber quién cambió un precio, un pedido o un reembolso | LC-2 | M×M | Registro de actividad del panel (cambios de precio, estado de pedido, reembolsos registrados, inicios de sesión) con retención ≥ 1 año |
| R-4 | Reembolso manual en Wompi no reflejado en la tienda | LC-2 | M×B | FR-058: registro del reembolso en el pedido; conciliación semanal con el reporte de Wompi |

### I — Information Disclosure (divulgación)

| ID | Amenaza | LC | P×I | Control |
|----|---------|----|-----|---------|
| I-1 | Llaves de Wompi expuestas en el navegador, el repositorio o copias de seguridad públicas | LC-8 | M×A | FR-079: llaves solo en el servidor (fuera de la raíz web), nunca en el repo; la pública es la única en el navegador; SC-015 |
| I-2 | Enumeración de pedidos de otras clientas (IDs secuenciales en "Gracias" o seguimiento) | LC-1 | A×A | Acceso a pedido de invitada con la clave de pedido no adivinable (`order_key`) y límite de frecuencia; para ver el estado en Gracias (`order-status`) se exige además la sesión con la que se creó el pedido; sin listados públicos (contracts/rest-api §8–§8c) |
| I-3 | Costo interno visible en la API pública, HTML o feeds | LC-1 | M×M | FR-067: costo como metadato privado, excluido de la API REST pública y de feeds |
| I-4 | PII enviada a analítica o a Meta en claro | LC-6 | M×A | FR-073: solo hash SHA-256 de correo/celular en la API de conversiones; sin PII en parámetros de URL |
| I-5 | Mensajes de error técnicos (rutas, versiones) | LC-1 | M×B | Modo depuración apagado en producción; páginas de error de marca |
| I-6 | Fuga de PII por copias de seguridad o exportaciones descargadas | LC-8 | B×A | Copias cifradas en el proveedor, acceso solo administradora; exportaciones con caducidad |
| I-7 | Enumeración de usuarios de WordPress (autores, REST `/users`) | LC-1 | A×M | Deshabilitar endpoints de usuarios y archivos de autor públicos |
| I-8 | Correo de "Pagado sin stock" o "Pago rechazado" con datos de más | LC-5 | B×M | Plantillas con mínimo de datos; sin dirección completa ni teléfono en enlaces |

### D — Denial of Service (denegación)

| ID | Amenaza | LC | P×I | Control |
|----|---------|----|-----|---------|
| D-1 | Reservas falsas masivas agotan el stock visible | LC-1 | A×A | FR-056 (reserva 30 min), FR-060 (límites y anti-bots), tope de unidades iguales por pedido |
| D-2 | Pruebas de tarjetas (card testing) generan rechazos, bloqueo de la cuenta Wompi o costos | LC-1/LC-4 | M×A | FR-060: ≤ 5 rechazos por pedido; por origen > 5/h → desafío visible y > 10/h → bloqueo 1 h; Turnstile antes de crear el pedido; alerta por > 20 rechazos/h (research R-12) |
| D-3 | Pico de tráfico por una publicación viral tumba el sitio | LC-1 | M×M | Caché de páginas públicas + CDN; carrito y checkout fuera de caché; prueba de carga 10× |
| D-4 | Abuso del buscador o filtros (consultas costosas) | LC-1 | M×M | Límite de frecuencia; índice de búsqueda; respuestas cacheadas |
| D-5 | Inundación de "Avísame cuando vuelva" o lista anticipada (spam, costo de SMS) | LC-1 | M×M | Anti-bots, doble confirmación por correo, límite por destinatario; SMS solo tras opt-in verificado |
| D-6 | El cron de liberación de reservas no corre (WP-Cron depende de visitas) | interno | M×A | Cron real del servidor; monitoreo de reservas vencidas no liberadas |
| D-7 | El endpoint de eventos de pago no responde y Wompi deja de reintentar (máx. 3 reintentos en 24 h) | LC-3 | B×A | Registro inmediato del evento, verificación con timeout y estado `deferred`; conciliación cada 5 min con los ids capturados en el callback del widget (R-02); ver RR-5 |

### E — Elevation of Privilege (escalada)

| ID | Amenaza | LC | P×I | Control |
|----|---------|----|-----|---------|
| E-1 | Vulnerabilidad de un plugin da ejecución de código o crea un administrador | LC-8 | M×A | T-9; WAF; alerta ante creación de usuarios con rol administrador; solo una administradora (FR-078) |
| E-2 | Registro público de cuentas con rol elevado | LC-1 | B×A | Registro solo con rol "cliente"; el rol por defecto no es configurable por la clienta |
| E-3 | Clienta accede al pedido, carrito o cuenta de otra | LC-1 | M×A | Verificación de propiedad en servidor; I-2 |
| E-4 | Cuenta del hosting comprometida (acceso a archivos y base) | LC-8 | B×A | 2FA en Hostinger, en el registrador de dominio y en el correo de Sara; SFTP/SSH con llave; sin cuentas compartidas |
| E-5 | Subida de archivos maliciosos (foto de empaque, importación) | LC-2 | B×A | Solo tipos de imagen y CSV permitidos, validación de contenido, sin ejecución en la carpeta de subidas |

---

## 3. Riesgos residuales aceptados (P0)

| ID | Riesgo | Por qué se acepta | Revisión |
|----|--------|-------------------|----------|
| RR-1 | Una sola administradora: no hay segregación de funciones (quien carga precios también reembolsa) | Negocio unipersonal; se compensa con registro de actividad (R-3) y conciliación semanal (R-4) | Al sumar personal |
| RR-2 | Reembolso manual: demora y error humano | Menos superficie (sin llave con permiso de reembolso en el servidor) | Si hay > 10 reembolsos/mes |
| RR-3 | Hosting compartido o administrado: control limitado del servidor | Costo; se compensa con WAF, copias y restauración probada | Si hay incidentes o crecimiento |
| RR-4 | Dependencia de que Wompi mantenga seguros su widget y su iframe | El widget mantiene los datos de pago dentro del componente alojado por Wompi (SAQ A), condicionado a la evidencia de R-19 | Anual (AOC) y ante cada cambio de `widget.js` |
| RR-5 | El manejador de eventos procesa en línea (no encola) | Volumen bajo; la conciliación cada 5 min cubre fallas (R-02) | Si hay > 500 pedidos/día |
| RR-6 | PSE o Botón Bancolombia: la clienta sale al banco y no vuelve; sin id de transacción, el pedido se cancela al vencer (o a las 2 h) y un aprobado posterior puede terminar en "Pagado sin stock" | Wompi no ofrece búsqueda por referencia documentada; el caso queda visible, con alerta y resolución en el panel (R-24) | Mensual: si la tasa de `ara-nostock` supera el 1% de los pedidos, evaluar R-01b o reservas más largas para PSE |
| RR-7 | Una transacción creada después de vencer la reserva (widget abierto más de 35 min) entra por la ruta de aprobado tardío | Mantener 30–35 min es requisito (RF-20); la extensión única cubre el caso común | Mismo KPI que RR-6 |
| RR-8 | El registro de despliegues (`~/.aralia-deploy/*.log`) y el lanzador viven en el equipo de Sara: quien controle su usuario puede falsear "último despliegue" o sustituir el lanzador | Permisos 700 y comprobación de 022 del lanzador; cada despliegue anota `launcher=<sha256>` y los `ALLOW_*` vigentes; sin protección de rama el riesgo sube | Al activar GitHub Pro/Team (T166) y en cada revisión semestral |
| RR-9 | Un merge hecho en local con cambios extra hereda la aprobación del PR (git no garantiza que el árbol fusionado sea el aprobado) | Fusionar siempre con *squash*; protección de rama con "branch up to date" cuando exista (T166) | Al activar GitHub Pro/Team |
| RR-10 | WordPress y los plugins de terceros los instala y actualiza Hostinger/wp-admin: una actualización maliciosa o rota, o un plugin añadido sin revisar, llega al sitio sin pasar por el pipeline; el JS de `build/` tampoco se verifica contra una fuente independiente | Una sola administradora con 2FA; lista cerrada en `ops/inventory.md`; Wompi sin actualización automática y con prueba de contrato; copia previa y staging antes de actualizar; `wp core/plugin verify-checksums` tras cada despliegue; Wordfence; `build/` restringido a tipos no ejecutables (T167 y T168) | Al completar T167/T168; PCI 6.3.3 y 6.4.3/11.6.1 para scripts de la página de pago |
| RR-11 | wp-admin/hPanel es un canal de código sin revisión (sesión de administradora comprometida) y los terceros pueden derivar entre despliegues; los mu-plugins y drop-ins de Hostinger corren en el mismo proceso que el guard | 2FA; lista cerrada y versiones exactas comprobadas en cada despliegue (`ops/third-party.lock`); Wordfence; T170 (comprobación periódica con alerta y guard que falla cerrado si la versión de Wompi no está en la lista) | Al completar T170 |
| RR-12 | El `rsync` no es atómico: `aralia-core` y el guard pueden quedar un instante en versiones distintas | Desplegar fuera de horas de pago con el medio de pago oculto (modo mantenimiento) | Al contar con despliegue atómico |
| RR-13 | Las comprobaciones posteriores al despliegue corren dentro del código recién desplegado | Las de terceros corren antes de copiar y con `--skip-plugins --skip-themes` | Permanente (aceptado) |
| RR-14 | Secretos Sandbox accesibles a PR de ramas del mismo repo en el CI; la revisora se identifica solo por su login de GitHub | Solo llaves Sandbox; registrar nombre real y evidencia de identidad de la revisora en `ops/compliance/` | Al activar environments (GitHub Pro/Team) |

---

**Aceptación de riesgos residuales**: **RR-8 a RR-14 (2026-11-13) están PROPUESTOS: su aceptación por Sara Andrade queda pendiente de registrar en el ledger.** RR-1 a RR-7 aceptados explícitamente por **Sara Andrade** (responsable de Aralía) el **2026-11-03**, durante la planificación (logiplan, iteración 3 del plan). También se decidió exigir revisión humana en cada cambio posterior del camino de pago, por lo que no hay riesgo residual por revisión, salvo los RR-8 a RR-14 de la Fase 1. Revisión de los riesgos: anual o al cumplirse su disparador.

## 4. Requisitos de seguridad derivados para el plan

1. Máquina de estados de pedido explícita con transiciones permitidas y prueba por transición (S-1, S-2, T-8, FR-057) — `data-model.md` §3.
2. Verificación doble de pago: firma del evento **y** consulta a la API por id de transacción, en el camino del evento (S-1) — research R-02.
3. Política de scripts en **todo el sitio** (inventario justificado, CSP global y estricta con nonce en checkout, order-pay y Gracias, SRI en scripts propios, detección diaria de cambios de scripts, contenido de `widget.js` y cabeceras con alerta a Sara; research R-11) para sostener el criterio de elegibilidad SAQ A sobre scripts (antes 6.4.3 / 11.6.1; ver `compliance-mapping.md` §0) (T-3).
4. Controles operativos PCI SAQ A: AOC de Wompi archivado, escaneo ASV trimestral, plan de respuesta a incidentes, SLA de parcheo (ver `compliance-mapping.md` §5).
5. Endurecimiento de WordPress: 2FA, sin edición de archivos, sin XML-RPC, sin enumeración de usuarios, modo depuración apagado, WAF (S-3, I-5, I-7, E-1).
6. Límites de frecuencia y anti-bots en crear pedido, códigos, avisos y buscador (S-6, T-7, D-1, D-2, D-4, D-5).
7. Cron real del servidor y conciliación periódica con Wompi (D-6, D-7).
8. Registro de actividad del panel y de eventos de pago, con retención (R-1, R-3).
9. Gestión de secretos fuera de la raíz web y fuera del repositorio; rotación documentada (I-1).
10. Escape de salida y listas blancas en todos los campos de texto libre (T-5).
11. Proceso de actualización de plugins en staging con escaneo de vulnerabilidades (T-9, E-1).
