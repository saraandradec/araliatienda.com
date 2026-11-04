# Feature Specification: Aralía Beauty Store — Tienda en línea (alcance de apertura P0)

**Feature Branch**: `001-aralia-store-p0`

**Created**: 2026-11-03

**Status**: Approved (Sara Andrade, 2026-11-03; revisado en logiplan)

**Tier TRACE**: T0 (cobro de dinero real vía pasarela; ver `.trace/tier.json`)

**Input**: Documento fuente `aralia-ecommerce-requerimientos.md` v3.1 (3 nov 2026), limitado al alcance **P0** (todo lo necesario para abrir la tienda). Decisiones tomadas en la elicitación del 3 nov 2026: solo P0; pago en el componente alojado por la pasarela (widget oficial; ver research.md R-01); una única administradora (Sara); pago aprobado tras vencer la reserva con la pieza ya vendida → aviso automático y reembolso manual; reembolsos manuales desde el panel de la pasarela; anti-fraude invisible para la clienta.

**Anexos T0**: [`threat-model.md`](./threat-model.md) · [`data-classification.md`](./data-classification.md) · [`compliance-mapping.md`](./compliance-mapping.md)

> Convención: cada requisito funcional (FR) indica entre corchetes el ID del documento fuente que respeta (p. ej. `[RF-18]`). Los requisitos nuevos surgidos de la elicitación o del análisis de riesgo llevan `[ELIC]` o `[TM]` (threat model). Las prioridades de las historias (P1, P2…) ordenan el valor *dentro* de la apertura; todas son alcance P0 del documento fuente.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Comprar una pieza suelta desde el celular y pagar sin ayuda (Priority: P1)

Una clienta llega desde Instagram o TikTok al celular, entra a una categoría (p. ej. Labios), filtra, abre una ficha, agrega la pieza al carrito lateral, paga como invitada en una sola página y recibe la confirmación por correo y celular. No escribe a nadie.

**Why this priority**: Es el embudo mínimo de venta. Sin esto no hay tienda; todas las demás historias lo reutilizan (carrito, checkout, pago, confirmación).

**Independent Test**: Con una sola pieza publicada con stock, una persona de prueba completa la compra en un celular de 360 px de ancho con una mano, usando cada medio de pago en ambiente de pruebas, y el pedido aparece pagado en el panel.

**Acceptance Scenarios**:

1. **Given** una pieza de Labios con stock, **When** la clienta toca "Agregar" en la tarjeta del listado, **Then** se abre el carrito lateral con la pieza y la sugerencia "Complétala", sin cambiar de página, y en ningún momento se le pide tono.
2. **Given** un carrito con piezas, **When** la clienta toca "Pagar" y completa nombre, celular, correo, ciudad, dirección e indicaciones, **Then** ve el costo de envío de su ciudad antes de pagar y pasa a la pasarela en un solo paso, sin páginas intermedias.
3. **Given** que la pasarela confirma el pago como aprobado, **When** la clienta vuelve a la tienda, **Then** ve la página de Gracias con resumen, tiempos y qué sigue, y recibe "Pago aprobado" por correo y celular (celular solo si lo aceptó).
4. **Given** que la pasarela rechaza el pago, **When** la clienta vuelve a la tienda, **Then** el pedido queda pendiente con sus piezas, puede reintentar con otro medio o devolver las piezas a su carrito, y recibe el correo "Pago rechazado" con enlace para reintentar.
5. **Given** una pieza agotada, **When** la clienta abre su ficha, **Then** ve "Esta pieza se agotó. Avísame cuando vuelva." y puede dejar correo o celular, con la autorización de tratamiento de datos.
6. **Given** que la clienta escribe "corectol" en el buscador, **When** ve las sugerencias, **Then** aparece "corrector".

---

### User Story 2 — Comprar un look completo de una sección con "Agregar todo" (Priority: P2)

La clienta entra a una sección curada (Para principiantes, Makeup no makeup, Retoque, Cejas perfectas, Labios), ve los sets con sus piezas, el precio total y el precio de las piezas por separado, toca "Agregar todo", elige su tono una sola vez si alguna pieza lo necesita, y puede quitar o cambiar piezas antes de pagar.

**Why this priority**: Es el ángulo de venta principal ("proponer antes que buscar", "un toque para comprar un look completo") y sube el ticket promedio.

**Independent Test**: Con una sección y un set publicado, una persona de prueba lleva el set completo al carrito desde la home en 2 toques, cambia una pieza, quita otra y paga.

**Acceptance Scenarios**:

1. **Given** un set sin piezas que dependan del tono, **When** la clienta toca "Agregar todo", **Then** todas las piezas entran al carrito como líneas separadas, agrupadas visualmente como un set.
2. **Given** un set que incluye un corrector (depende del tono) y la clienta no tiene tono guardado, **When** toca "Agregar todo", **Then** se abre el selector de tono en la misma página ("En esta pieza el tono sí importa.") y nada se agrega hasta que lo elija.
3. **Given** un set en el carrito, **When** la clienta quita una pieza, **Then** las demás siguen agrupadas y el total se recalcula.
4. **Given** un set, **When** la clienta usa "Cambiar piezas" y sustituye el gloss por otro gloss, **Then** el precio se recalcula con la pieza nueva.
5. **Given** un set con una pieza agotada, **When** la clienta abre la sección, **Then** el set aparece como "Disponible con cambio" y propone el reemplazo.
6. **Given** un set cuyo precio no es menor que la suma de sus piezas, **When** se muestra, **Then** no aparece el mensaje "Piezas por separado $X · Tu set $Y" como ahorro (el ángulo solo se muestra si es cierto).

---

### User Story 3 — Ver un Full face por marca ya armado y llevarlo entero (Priority: P3)

Al entrar a Full face, la clienta ve una tarjeta por marca ("Full face con Atenea", "Full face con Montoc"…) con el look completo ya armado desde el catálogo, sin elegir criterios, y lo lleva al carrito con "Agregar todo".

**Why this priority**: Es la sección diferenciadora, pero depende del catálogo completo con el atributo de paso y del mecanismo de sets de la historia 2.

**Independent Test**: Con piezas de al menos 2 marcas que cubran el mínimo de pasos con stock, la página Full face muestra una tarjeta por marca; al agotar piezas hasta que una marca quede bajo el mínimo, la marca desaparece sola; con menos de 2 marcas elegibles, la sección desaparece del menú y de la home.

**Acceptance Scenarios**:

1. **Given** marcas con piezas en los 7 pasos, **When** la clienta abre Full face, **Then** ve una tarjeta por marca elegible con foto del look, piezas, precio total y "Agregar todo", sin ninguna mención a cuántas marcas hay.
2. **Given** una marca con varias piezas con stock para el mismo paso, **When** se arma su look, **Then** se toma la más vendida, salvo que la administradora haya fijado otra.
3. **Given** una marca que cubre 5 de 7 pasos, **When** se muestra su look, **Then** aparece con esos 5 pasos y no se completa con piezas de otra marca.
4. **Given** que se agota la única pieza de un paso de una marca y esta baja del mínimo, **When** cualquier persona carga la página, **Then** la marca ya no aparece, sin intervención manual.
5. **Given** que menos de 2 marcas cumplen el mínimo, **When** se carga la tienda, **Then** Full face no aparece en el menú ni en la home.
6. **Given** cada marca, **When** se comparte el enlace, **Then** tiene URL estable (`/full-face/<marca>`).
7. **Given** un Full face con corrector, rubor y cejas, **When** la clienta toca "Agregar todo", **Then** se le pide el tono una sola vez en la misma página.

---

### User Story 4 — Comprar para regalar (Priority: P4)

La clienta marca "Es un regalo" en el checkout, deja nombre y celular de quien recibe, un mensaje de tarjeta de hasta 90 caracteres, una fecha deseada, y el pedido sale con empaque sin precios.

**Why this priority**: Es un ángulo de venta P0 (A6) que reutiliza el checkout; es barato y abre el menú Regalos.

**Independent Test**: Una compra de prueba con "Es un regalo" muestra en la hoja de empaque del panel el mensaje exacto, los datos de quien recibe y la marca "sin precios".

**Acceptance Scenarios**:

1. **Given** el checkout, **When** la clienta marca "Es un regalo", **Then** aparecen los campos de quien recibe, mensaje (contador de 90 caracteres) y fecha deseada, y el texto "Llega empacada, lista para regalar."
2. **Given** un pedido de regalo pagado, **When** la administradora abre la hoja de empaque, **Then** ve el mensaje tal cual se escribió, los datos de quien recibe y la indicación de empaque sin precios.

---

### User Story 5 — Armar un Neceser Aralía personalizado (Priority: P5)

La clienta elige el tipo de neceser, escribe el nombre que irá en vinilo (hasta 14 caracteres) y su color, elige hasta N piezas (o "Agregar todo" desde un look sugerido), ve el total y lo agrega al carrito, sabiendo antes de pagar que no admite cambio.

**Why this priority**: Es un producto propio de alto valor percibido, pero de menor volumen y con trabajo manual de producción.

**Independent Test**: Un pedido de prueba de neceser muestra en el panel el texto exacto y el color del vinilo listos para cortar, y el aviso de no cambio apareció antes del pago.

**Acceptance Scenarios**:

1. **Given** el constructor, **When** la clienta escribe un nombre con más de 14 caracteres o con caracteres no permitidos, **Then** el sistema no lo acepta y le explica qué cambiar.
2. **Given** un neceser en el carrito, **When** la clienta va a pagar, **Then** ve el aviso de que los neceseres personalizados no admiten cambio.
3. **Given** un pedido pagado con neceser, **When** la administradora lo abre, **Then** ve el texto exacto, el color del vinilo, el tipo de neceser y las piezas.

---

### User Story 6 — Comprar una Surprised bag (Priority: P6)

La clienta elige el tipo de bolsa sorpresa (por categoría o look) sin ver su contenido y la compra como cualquier producto.

**Why this priority**: Ángulo de venta P0 (A9), simple de implementar como producto con opciones.

**Independent Test**: Una compra de prueba de cada tipo de bolsa llega al panel con el tipo elegido y descuenta el stock de la bolsa.

**Acceptance Scenarios**:

1. **Given** la sección Surprised bag, **When** la clienta elige un tipo y agrega, **Then** el carrito muestra el tipo elegido y en ningún lugar público se lista su contenido.

---

### User Story 7 — Operar la tienda: catálogo, pedidos, empaque y mensajes (Priority: P7)

Sara carga el catálogo desde la hoja de Inventario (CSV) o con alta rápida, ve en el panel solo los pedidos realmente pagados, imprime la hoja de empaque con piezas, tonos, regalo y vinilo, marca empacado (con foto) y despachado (con guía), y la clienta recibe cada mensaje sin que Sara escriba nada.

**Why this priority**: Sin operación no hay entrega; es la puerta de salida de la fase de automatización.

**Independent Test**: Un pedido de prueba recorre recibido → pagado → empacado → despachado y la clienta recibe los 4 mensajes correctos, sin intervención manual distinta a marcar los estados físicos.

**Acceptance Scenarios**:

1. **Given** una fila del CSV sin foto o sin swatch (si tiene color o tono) o sin stock, **When** se importa, **Then** la pieza queda en la lista de incompletas y no se puede publicar.
2. **Given** un pedido creado y no pagado, **When** pasan 30 minutos sin confirmación de la pasarela, **Then** su stock reservado se libera solo.
3. **Given** un pedido, **When** la tienda recibe una confirmación de pago sin firma válida o con un monto o moneda distintos al pedido, **Then** el pedido no pasa a pagado y queda registro del evento.
4. **Given** un pedido pagado, **When** Sara marca "Empacado" con foto y luego "Despachado" con guía, **Then** la clienta recibe los mensajes correspondientes.
5. **Given** una pieza que vuelve a tener stock, **When** se actualiza, **Then** todas las personas que pidieron aviso lo reciben una sola vez.
6. **Given** que una pieza cambia de precio, **When** Sara guarda el cambio, **Then** el panel avisa qué sets se ven afectados.
7. **Given** el panel, **When** Sara lo consulta, **Then** ve visitas, conversión, ticket promedio, piezas y sets más vendidos, carritos abandonados, rechazos de pago por medio y stock bajo.

---

### User Story 8 — Confiar en la tienda antes de pagar (Priority: P8)

La clienta, antes de comprar, revisa "Cómo llega", cambios y garantías, términos, privacidad, envíos, quiénes somos y los datos del vendedor, y ve sellos de pago seguro en todo el embudo.

**Why this priority**: Requisito legal y de conversión para abrir (A8, RF-70 a RF-73).

**Independent Test**: Todas las páginas legales y de confianza están publicadas, enlazadas desde el pie y el checkout, y los datos del vendedor son visibles.

**Acceptance Scenarios**:

1. **Given** cualquier formulario que pida correo o celular, **When** la clienta lo envía, **Then** antes aceptó explícitamente la autorización de tratamiento de datos con enlace a la política.
2. **Given** el checkout, **When** la clienta no marca términos y privacidad, **Then** no puede pagar; la casilla de novedades viene sin marcar.

---

### Edge Cases

- **Pago aprobado después de vencer la reserva y con la pieza ya vendida** [ELIC]: el pedido queda en un estado visible "Pagado sin stock" (se descuentan las piezas que sí hay), Sara recibe una alerta inmediata y la clienta un correo automático con dos opciones y un enlace de contacto (caso permitido por el principio 7: pedido pagado con problema): cambiar la pieza faltante por otra del mismo tipo de igual o menor precio (la diferencia se reembolsa) o recibir el reembolso total. Sara lo resuelve en el panel; el reembolso lo ejecuta manualmente en la pasarela y lo registra en el pedido.
- **Pago todavía en proceso al vencer la reserva** (PSE o Nequi pendientes): la reserva se extiende en tramos de 15 minutos mientras la pasarela informe "pendiente", hasta un máximo de 2 horas; luego se cancela y, si llega un aprobado tardío, aplica el caso anterior.
- **Pago aprobado después de vencer la reserva pero con stock disponible**: el pedido pasa a pagado normalmente y el stock se vuelve a descontar.
- **Confirmación de pago duplicada o fuera de orden** (la pasarela reenvía el evento, o llega "rechazado" después de "aprobado"): el pedido se actualiza una sola vez; un estado final aprobado no retrocede por un evento posterior sin que Sara lo revise.
- **Dos intentos de pago aprobados para el mismo pedido** (p. ej. un PSE en proceso y luego una tarjeta): la tienda no abre un nuevo intento mientras haya uno en proceso; si aun así se aprueban dos, el pedido no cambia, Sara recibe una alerta crítica y reembolsa el cobro duplicado siguiendo su procedimiento.
- **La clienta cierra el navegador en la pasarela**: el estado del pedido lo define la confirmación de la pasarela recibida en el servidor, no el regreso del navegador; si no llega confirmación en el tiempo de reserva, se consulta el estado a la pasarela antes de liberar.
- **Dos clientas compran la última unidad a la vez**: solo una reserva se confirma; la segunda ve el aviso de agotado y una alternativa del mismo tipo.
- **Pieza que se agota mientras está en un carrito** [RF-16]: el carrito lo avisa y ofrece una alternativa del mismo tipo.
- **Manipulación de precio o de cantidad desde el navegador** [TM]: el total siempre se recalcula en el servidor con precios del catálogo; el monto enviado a la pasarela es el del servidor.
- **Pedidos falsos para bloquear stock** [ELIC/TM]: límite de pedidos pendientes simultáneos por celular, correo y origen; tope de unidades iguales por pedido; verificación anti-bots invisible.
- **Pruebas masivas de tarjetas robadas** [TM]: límite de intentos de pago por pedido y por origen; los rechazos repetidos bloquean temporalmente nuevos pedidos desde ese origen.
- **Ciudad fuera de las zonas de envío**: se avisa en el checkout antes de pagar y no se permite continuar.
- **Nombre del neceser con emojis, HTML o caracteres no cortables**: se rechaza con explicación; solo letras (incluidas tildes y ñ), números, espacio y un conjunto corto de signos.
- **Mensaje de regalo con enlaces o código**: se guarda como texto plano y se muestra escapado en el panel y la hoja de empaque.
- **Set cuyas piezas se agotan todas**: se marca agotado y ofrece "Avísame cuando vuelva".
- **Una sola marca cumple el mínimo de Full face**: la sección se oculta (mínimo 2).
- **Clienta sin tono guardado entra a Labios**: nunca se le pide tono.
- **Precio tachado**: solo aparece si el producto estuvo a ese precio al menos 14 días antes [RN-05].
- **Pasarela caída**: la página de pago muestra un mensaje claro de reintento, sin mensaje técnico, y las piezas elegidas no se pierden (siguen en el pedido y pueden volver al carrito).

---

## Requirements *(mandatory)*

### Functional Requirements

**Navegación, catálogo público y búsqueda**

- **FR-001** [4.1]: La tienda MUST ofrecer un menú principal con Categorías, Secciones, Regalos y Cómo llega, más buscador, cuenta y carrito; en móvil, un cajón con dos bloques: Categorías y Secciones.
- **FR-002** [4.2]: La tienda MUST organizar el catálogo en las categorías Labios, Cejas, Pestañas, Ojos, Piel, Brochas y Accesorios; una pieza tiene una categoría principal y puede aparecer en varias secciones.
- **FR-003** [4.2]: Las piezas nuevas MUST mostrar la etiqueta "Nuevo" durante 21 días desde su publicación.
- **FR-004** [5.2, RF-03]: Los listados MUST permitir filtrar por marca, color, look, tono (donde aplica), precio y disponibilidad, de forma combinable, sin recargar la página y con URL compartible; y ordenar por recomendado, más vendidos, nuevos y precio.
- **FR-005** [RF-04]: El orden "recomendado" MUST priorizar la afinidad con el tono y el look guardados (si existen), luego disponibles, luego más vendidos.
- **FR-006** [5.2]: La tarjeta de producto MUST mostrar foto, swatch, nombre, marca, precio, etiqueta (Nuevo, Últimas unidades, Agotado) y botón "Agregar"; el selector de tono en la tarjeta solo aparece en Piel y lápices de cejas.
- **FR-007** [RF-05]: "Agregar" en una tarjeta MUST abrir el carrito lateral con "Complétala" sin cambiar de página.
- **FR-008** [RF-06]: Una pieza agotada MUST ofrecer "Avísame cuando vuelva" con correo o celular.
- **FR-009** [5.8]: El buscador MUST sugerir categorías y productos y tolerar errores de escritura ("corectol" encuentra "corrector").

**Home**

- **FR-010** [5.1]: La home MUST mostrar, en este orden: hero con una propuesta y un botón principal (editable desde el panel); 7 tarjetas de categoría; Full face con 3 marcas destacadas (si la sección está activa); 6 más vendidos por ventas reales; tarjetas de Para principiantes y Makeup no makeup; sets de Cejas y Labios con foto del empaque; las últimas 6 piezas nuevas; Cómo llega; fotos reales de pedidos; registro a la lista de acceso anticipado; pie con medios de pago, políticas y redes.
- **FR-011** [RF-01]: La tienda MUST tener una barra superior con un solo mensaje real, apagable desde el panel.
- **FR-012** [RF-02]: Hero, Full face, Más vendidos y Nuevo MUST alimentarse del catálogo y del panel, sin editar código.
- **FR-013** [5.1 bloque 10]: El formulario de lista de acceso anticipado MUST capturar correo y/o celular con autorización de tratamiento de datos. (El beneficio de comprar 24 h antes es P1; en P0 solo se captura la lista.)

**Ficha de producto**

- **FR-014** [5.4]: La ficha MUST mostrar galería (producto, en uso, swatch real), nombre, marca, precio, selector de tono con swatch (si aplica), disponibilidad real, descripción corta, cómo se usa, ingredientes, registro sanitario cuando el proveedor lo tenga, "Combina con" (2 piezas), "Aparece en estos sets" y el bloque "Cómo llega".
- **FR-015** [RF-11]: En móvil, la ficha MUST tener un botón de compra fijo al pie con el precio.
- **FR-016** [RF-12]: "Combina con" MUST venir del catálogo (definido por look y color), nunca al azar.
- **FR-017** [RF-13]: "Aparece en estos sets" MUST enlazar a las secciones y permitir agregar el set con un toque.
- **FR-018** [RF-14]: La ficha MUST permitir compartir por WhatsApp, Instagram y copiar enlace.

**Tono**

- **FR-019** [1.2-4, 15-3]: La tienda MUST pedir tono solo en piezas marcadas "Depende del tono" (Piel y lápices de Cejas) y nunca en Labios, Ojos ni Pestañas. Una pieza que depende del tono MUST NOT entrar al carrito sin tono elegido.
- **FR-020** [A7]: El tono elegido MUST guardarse en el dispositivo de la clienta (sin datos sensibles) y mostrarse como "Tu tono: <familia>" en Piel y Cejas, ordenando las piezas por afinidad.

**Secciones y sets**

- **FR-021** [4.3]: La tienda MUST ofrecer las secciones Full face, Para principiantes, Makeup no makeup, Cejas perfectas, Labios, Retoque, Surprised bag y Neceser Aralía. (Empresarial es P1 y queda fuera.)
- **FR-022** [RN-01]: Solo Cejas perfectas y Labios MUST ser sets fijos físicamente armados (producto compuesto, con su propio stock y foto del empaque); las demás secciones se componen de piezas sueltas con el stock de cada una.
- **FR-023** [RN-02]: En los sets de Cejas y Labios no MUST repetirse marca (una marca por producto).
- **FR-024** [5.3]: Cada set MUST mostrar nombre, para quién es, piezas con foto, precio total, precio de las piezas por separado, "Agregar todo" y "Cambiar piezas" (este último solo en sets de piezas sueltas, ver FR-027).
- **FR-025** [RF-07]: "Agregar todo" MUST agregar cada pieza como línea separada, con sus tonos, agrupadas visualmente como set; quitar una pieza no rompe el set.
- **FR-026** [RF-08]: Si el set incluye una pieza con tono y no hay tono elegido, MUST abrirse el selector en la misma página antes de agregar.
- **FR-027** [RF-09]: "Cambiar piezas" MUST permitir sustituir una pieza por otra del mismo paso, recalculando el precio. Aplica a los sets de piezas sueltas y a Full face; los sets fijos de Cejas y Labios (FR-022) se venden como unidad física y no se modifican.
- **FR-028** [RF-10]: Un set con una pieza agotada MUST marcarse "Disponible con cambio" y proponer el reemplazo.
- **FR-029** [A5, decisión 14-4]: "Piezas por separado $X · Tu set $Y" MUST mostrarse solo si Y es menor que X.
- **FR-030** [RF-44, RN-03]: El precio de un set MUST calcularse desde sus piezas con un ajuste manual opcional; si una pieza cambia de precio, el panel MUST avisar los sets afectados.

**Full face por marca**

- **FR-031** [RF-F1, RF-F2]: Full face MUST mostrar una tarjeta por marca elegible (foto del look, piezas, precio total, "Agregar todo") sin mencionar el número de marcas, y cada marca MUST tener URL estable `/full-face/<marca>`.
- **FR-032** [RF-F3]: El look de cada marca MUST armarse solo desde el catálogo con el atributo "Paso de Full face" (Piel corrector, Piel rubor, Piel polvo, Cejas, Ojos, Pestañas, Labios): por paso, la pieza de esa marca con stock; si hay varias, la más vendida.
- **FR-033** [RF-F4]: Una marca MUST aparecer solo si cubre al menos 4 de los 7 pasos con stock; los pasos faltantes no se completan con otra marca.
- **FR-034** [RF-F5]: Si menos de 2 marcas cumplen el mínimo, Full face MUST ocultarse del menú y de la home.
- **FR-035** [RF-F6]: Desde el panel MUST poderse fijar una pieza para un paso de una marca, ocultar una marca y cambiar el orden; y elegir las 3 marcas destacadas de la home.
- **FR-036** [RF-F7]: Los pasos con tono MUST pedir el tono una sola vez antes de agregar, en la misma página.
- **FR-037** [RF-F8]: "Cambiar piezas" en Full face MUST permitir quitar un paso o cambiar la pieza por otra de la misma marca y paso.
- **FR-038** [RF-F9]: El look MUST actualizarse solo cuando una pieza se agota, y la marca deja de listarse al bajar del mínimo, sin intervención.

**Surprised bag y Neceser**

- **FR-039** [4.3]: La Surprised bag MUST venderse como producto con opciones (tipo por categoría o look) sin mostrar su contenido.
- **FR-040** [5.7]: El Neceser Aralía MUST permitir elegir el tipo de neceser (inicialmente un tamaño), escribir un nombre de hasta 14 caracteres, elegir color de vinilo, elegir hasta 6 piezas (o "Agregar todo" desde un look sugerido) y ver el total.
- **FR-041** [RF-22]: El nombre del neceser MUST validarse (longitud y caracteres permitidos) y guardarse exacto, con el color, en el pedido.
- **FR-042** [RF-23]: El sistema MUST avisar antes del pago que los neceseres personalizados no admiten cambio.

**Carrito**

- **FR-043** [5.5]: El carrito lateral MUST mostrar líneas con foto, nombre, tono, cantidad y quitar; sets agrupados; "Complétala" con 2 piezas de un toque; campo de código plegado; botón "Pagar" con el total y, debajo, medios de pago y garantía.
- **FR-044** [RF-53, A4]: El carrito y la ficha MUST mostrar la barra de progreso hacia el envío gratis solo si hay un monto de envío gratis configurado (por defecto, no hay).
- **FR-045** [RF-15]: El carrito MUST persistir 30 días en el dispositivo y recuperarse al iniciar sesión si la clienta tiene cuenta.
- **FR-046** [RF-16]: Si una pieza se agota antes del pago, el carrito MUST avisarlo y ofrecer una alternativa del mismo tipo.

**Checkout y pago**

- **FR-047** [5.6]: El checkout MUST ser de una página, permitir compra como invitada, pedir nombre, celular, correo, ciudad, dirección e indicaciones, validar la ciudad contra las zonas de envío con autocompletado y mostrar el resumen siempre.
- **FR-048** [5.6, A6, CM §6]: El checkout MUST ofrecer "Es un regalo" con nombre y celular de quien recibe, mensaje de tarjeta (máx. 90 caracteres), empaque sin precios y fecha deseada. Al activar la opción, la compradora MUST declarar que cuenta con autorización de quien recibe para compartir sus datos, y esos datos MUST usarse solo para la entrega (nunca para marketing).
- **FR-049** [5.6, RF-71, CM §6]: El checkout MUST exigir la aceptación de términos y privacidad (casilla obligatoria), con la declaración de ser mayor de edad o comprar con autorización de su representante legal, y ofrecer recibir novedades (opcional, sin marcar).
- **FR-050** [RF-17, ELIC]: De "Pagar" a la pasarela MUST haber un solo paso; el formulario de pago de la pasarela se abre automáticamente y los datos de pago se capturan solo dentro del componente alojado por la pasarela. La tienda MUST NOT recibir, procesar ni almacenar datos de tarjeta.
- **FR-051** [5.6, RF-50]: Los medios de pago MUST ser los que la pasarela habilite para la cuenta (tarjeta, PSE, Botón Bancolombia, Nequi), en pesos colombianos.
- **FR-052** [RF-18]: Un pedido MUST pasar a pagado solo cuando el servidor confirme con la pasarela el estado APPROVED mediante un evento con firma válida (o una consulta directa a la pasarela), y el monto, la moneda y la referencia coincidan con el pedido. DECLINED, VOIDED y ERROR sobre un pedido no pagado lo dejan pendiente con opción de reintentar con otro medio sin perder las piezas elegidas (el pedido las conserva; si la reserva ya venció, la clienta puede devolverlas a su carrito con un toque).
- **FR-053** [TM]: El monto cobrado MUST calcularse en el servidor a partir del catálogo, el envío y los códigos válidos; ningún precio, descuento ni total enviado por el navegador se acepta como verdad.
- **FR-054** [TM]: Cada confirmación de pago MUST procesarse de forma idempotente (una misma transacción no cambia el pedido dos veces) y quedar registrada con fecha, estado recibido y resultado de la verificación.
- **FR-055** [RF-19, RF-52]: El costo de envío por ciudad MUST ser visible antes de pagar, con una tarifa para Medellín y área metropolitana y una nacional.
- **FR-056** [RF-20]: Al crear el pedido pendiente, el stock MUST reservarse y liberarse automáticamente a los 30 minutos (contados desde la creación) sin pago confirmado, previa consulta del estado a la pasarela; si la pasarela informa un pago en proceso, la reserva se extiende en tramos de 15 minutos hasta un máximo de 2 horas.
- **FR-057** [ELIC]: Si llega un pago aprobado para un pedido cuya reserva venció y alguna pieza ya no tiene stock, el pedido MUST quedar en el estado "Pagado sin stock", alertar a Sara y enviar a la clienta un correo con las opciones de cambio o reembolso total. El reembolso se ejecuta manualmente en la pasarela y se registra en el pedido.
- **FR-058** [ELIC, CM §2]: Los reembolsos y anulaciones MUST hacerse manualmente desde el panel de la pasarela; la tienda MUST permitir registrarlos en el pedido (monto, fecha, motivo) y MUST NOT ofrecer ninguna función que ejecute reembolsos o anulaciones. La tienda MUST guardar solo las credenciales de la pasarela que la integración necesite; cualquier credencial capaz de operar transacciones se trata como dato Restringido (ver `data-classification.md`).
- **FR-058a** [CM §6, Ley 1480 art. 51]: El pedido MUST poder registrar una reversión del pago solicitada por la clienta a su banco o emisor (estado "Reversado"), con fecha, monto y motivo, y conciliarse con el reporte de la pasarela.
- **FR-059** [RF-21]: Etiquetas, mensaje de regalo y datos del neceser MUST guardarse en el pedido y aparecer en la hoja de empaque.
- **FR-060** [ELIC/TM]: El checkout MUST aplicar controles anti-abuso invisibles para la clienta normal: verificación anti-bots sin desafío visible (un desafío visible solo aparece ante señales de abuso), máximo de pedidos pendientes simultáneos por celular, correo y origen, tope de unidades iguales por pedido, límite de intentos de pago por pedido y límite de intentos de pago fallidos por origen que bloquea temporalmente nuevos pedidos desde ese origen.
- **FR-061** [RN-05]: El precio tachado MUST mostrarse solo si el producto estuvo a ese precio al menos 14 días antes.

**Mensajes automáticos**

- **FR-062** [9.2]: El sistema MUST enviar sin intervención: Pedido recibido (correo), Pago aprobado (correo y celular), Pedido empacado con foto (correo), Despachado con guía (correo y celular), Pago rechazado con enlace para reintentar (correo), Pieza disponible otra vez (correo o celular), el aviso de "Pagado sin stock" (correo) y "Reserva vencida" con la opción de devolver las piezas al carrito (correo).
- **FR-063** [RF-57]: Los mensajes por celular MUST enviarse solo a quien los aceptó y ofrecer baja en cada uno.
- **FR-064** [RF-56, RF-58]: Todos los mensajes MUST usar plantillas de marca, cumplir las reglas de marca (1.3) y tener una sola acción principal.

**Panel de administración y catálogo**

- **FR-065** [8.1, RF-42, RF-43]: El panel MUST permitir cargar el catálogo por CSV desde la hoja de Inventario (atributos y secciones) y dar de alta un producto con todos sus atributos en una sola pantalla.
- **FR-066** [RF-40]: Una pieza sin foto, sin swatch (si tiene color o tono) o sin stock MUST NOT publicarse; el panel lista las incompletas.
- **FR-067** [8.1]: Cada producto MUST tener los atributos de la tabla 8.1 (categoría, subcategoría, marca, depende del tono, familia de tono, subtono, color, look, secciones, combina con, costo interno, precio, stock, fotos, swatch, paso de Full face, registro sanitario); el costo MUST NOT ser visible en ninguna página pública.
- **FR-068** [RF-41]: Las familias de tono (Porcelana, Marfil, Miel, Canela, Caramelo, Espresso como provisionales) MUST definirse una sola vez, con su swatch, y ser editables desde el panel.
- **FR-069** [RF-61, RN-06]: El panel MUST mostrar visitas, conversión, ticket promedio, piezas y sets más vendidos, carritos abandonados, rechazos de pago por medio y stock bajo; "Más vendido" se calcula solo con ventas reales de la tienda.
- **FR-070** [7.4]: El panel MUST mostrar una lista de pedidos por estado (pendiente, pagado, pagado sin stock, empacado, despachado, entregado, reembolsado, reversado, cancelado) con hoja de empaque imprimible. Los reembolsos parciales se registran en el pedido sin cambiar su estado.
- **FR-071** [RN-04]: Ningún contador, cupo o "Últimas unidades" MUST mostrarse sin datos reales de inventario; "Últimas unidades" se muestra solo con stock real de 3 unidades o menos.

**Analítica**

- **FR-072** [9.3, RF-60]: La tienda MUST registrar los eventos `view_item_list`, `view_item`, `select_tone`, `add_to_cart`, `add_set`, `view_cart`, `begin_checkout`, `add_payment_info`, `purchase` y `gift_mode_on` (más `join_early_access` al registrarse en la lista), desde el navegador y el servidor con un identificador común para evitar duplicados; una compra de prueba aparece una sola vez en cada herramienta.
- **FR-073** [RF-71, TM]: Las etiquetas de analítica y de terceros MUST NOT cargarse en checkout, en la página de pago ni en la página de Gracias, ni recibir datos personales en claro (correo y celular solo como hash cuando la herramienta lo exige). Única excepción aprobada: la verificación anti-bots del checkout (FR-060), justificada en el inventario de scripts.

**Confianza, legal y seguridad**

- **FR-074** [RF-70, CM §6]: La tienda MUST publicar términos, privacidad, cambios y garantías, envíos, quiénes somos, Cómo llega, Ayuda (preguntas por tema) y un contacto solo para pedidos pagados. La política de tratamiento de datos MUST incluir como mínimo: responsable y sus datos de contacto, tratamiento y finalidades, derechos de la titular, canal y procedimiento para ejercerlos, encargados y transmisiones internacionales, y fecha de vigencia.
- **FR-075** [RF-71]: Todo registro de correo o celular MUST incluir aviso de privacidad y autorización de tratamiento de datos, y guardar la prueba de esa autorización (fecha, texto aceptado, canal).
- **FR-076** [RF-72, decisión 14-10]: La política de cambios MUST reflejar el retracto de ventas a distancia y la excepción por higiene de productos abiertos (sin cambio salvo defecto o error).
- **FR-077** [RF-73]: Los datos del vendedor (nombre o razón social, NIT o cédula, ciudad, contacto) MUST ser visibles en el pie y en las páginas legales.
- **FR-078** [RT-10, CM §1]: El panel de administración MUST exigir doble factor y tener una sola cuenta administradora permanente (Sara), nunca compartida; si soporte o desarrollo necesitan acceso, MUST usar una cuenta nominativa, temporal y con 2FA, que se da de baja al terminar. Los accesos MUST registrarse.
- **FR-079** [RT-10, RT-11]: Ninguna llave privada, de integridad o de eventos de la pasarela MUST estar en el navegador ni en el código público.
- **FR-080** [RT-12]: La tienda MUST cumplir contraste AA, foco visible, textos alternativos, formularios con etiqueta y respeto a movimiento reducido.
- **FR-081** [RT-13]: El tono y el carrito guardados en el dispositivo MUST contener solo datos no sensibles.
- **FR-082** [RT-04, TM]: La tienda MUST tener copias diarias y una restauración probada antes de abrir.
- **FR-083** [TM, CM §6]: Los datos personales de clientas, destinatarias de regalo y personas en lista de aviso MUST poder consultarse, corregirse y suprimirse a solicitud (derechos de la titular), respondiendo consultas en máximo 10 días hábiles y reclamos en máximo 15 días hábiles, con marca de "reclamo en trámite" mientras se resuelve; los pedidos se conservan el tiempo que exija la ley contable.
- **FR-083a** [CM §1 E5]: Aralía MUST NOT recibir, anotar ni procesar datos de tarjeta por ningún canal distinto al checkout de la pasarela (WhatsApp, redes, correo, teléfono, papel); la política pública y las respuestas modelo de atención lo MUST indicar.
- **FR-083b** [CM §5.6]: Ante un incidente de seguridad que afecte datos personales o pagos, Aralía MUST seguir un plan de respuesta documentado que incluya el aviso a la autoridad de protección de datos y a las titulares afectadas en los plazos legales.

**Reglas de marca**

- **FR-084** [1.3]: Todo texto público MUST respetar las reglas de marca: no usar "colombiano/a", no mencionar la cantidad de marcas, conjuntos llamados "sets", tono premium y cálido sin signos de exclamación repetidos ni lenguaje de remate, y la frase "Esta será tu tienda favorita. Todo tu maquillaje, en un solo lugar."
- **FR-085** [RN-07, RN-08]: La tienda MUST vender solo stock propio despachado desde la bodega de Aralía en un solo paquete por pedido; no se publican productos de terceros ni de Dropi.

### Key Entities

- **Producto (pieza)**: artículo vendible con los atributos de 8.1; tiene variantes por tono cuando depende del tono; costo interno, precio, stock, estado de publicación (incompleto / publicado / agotado).
- **Familia de tono**: nombre, subtono y swatch; se asigna a variantes de productos que dependen del tono.
- **Marca**: nombre, slug para la URL de Full face, visibilidad y orden en Full face, destacada en home.
- **Paso de Full face**: uno de los 7 pasos; relación marca → paso → pieza fijada (opcional).
- **Sección**: colección curada con intención de compra; contiene sets y piezas sueltas.
- **Set**: nombre, sección, para quién es, piezas por paso con alternativas, precio calculado + ajuste, imagen, estado (disponible, disponible con cambio, agotado). Los sets fijos de Cejas y Labios son productos compuestos con stock propio.
- **Surprised bag**: producto con opción de tipo y stock por tipo.
- **Neceser personalizado**: tipo, texto de vinilo, color de vinilo, piezas elegidas (máx. 6).
- **Carrito**: líneas (pieza, tono, cantidad, set de origen), código, persistencia 30 días.
- **Pedido**: datos de contacto y envío, líneas con tonos y agrupación por set, datos de regalo, datos del neceser, envío, total calculado en servidor, estado, reserva de stock con vencimiento, transacciones de pago, registro de reembolso manual.
- **Transacción de pago / evento de pasarela**: referencia, monto, moneda, medio, estado recibido, firma verificada sí/no, fecha, resultado.
- **Zona de envío**: ciudades, tarifa, tiempo estimado.
- **Suscripción a aviso de stock**: pieza, correo o celular, consentimiento, enviado sí/no.
- **Registro en lista de acceso anticipado**: correo y/o celular, consentimiento, fecha.
- **Consentimiento**: titular, texto aceptado, versión, canal, fecha.
- **Contenido editable**: hero, barra superior, marcas destacadas, textos de páginas de confianza.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001** [15-1]: Una clienta nueva, desde Instagram en el celular, compra un look completo sin escribir a nadie; 5 de 5 personas de prueba lo logran sin ayuda.
- **SC-002** [15-2]: Desde la home, un look completo llega al carrito en 2 toques (3 si se debe elegir tono).
- **SC-003** [15-3]: En el 100% de los productos de Piel y lápices de Cejas no se puede agregar sin tono, y en el 0% de Labios, Ojos y Pestañas se pide tono.
- **SC-004** [15-4]: El 100% de los pedidos pagados llega al panel con piezas, tono, mensaje de regalo y nombre del neceser, listo para empacar sin consultar a la clienta.
- **SC-005** [15-5, RF-40]: El 0% de las piezas publicadas carece de foto, swatch (si aplica) o stock.
- **SC-006** [15-6]: Al entrar a Full face se ve el look de cada marca ya armado, sin ningún paso de selección.
- **SC-007** [15-7]: El 100% de los pedidos sale de un solo lugar, en un solo paquete.
- **SC-008** [RT-01]: En celular con 4G, la página principal, un listado y una ficha muestran su contenido principal en menos de 2,5 s (mediana de 3 mediciones) y obtienen 85 o más en la medición estándar de rendimiento móvil.
- **SC-009** [RT-02]: El flujo entrar → elegir → pagar se completa con una mano en 360 px de ancho, sin zoom.
- **SC-010** [RF-18, TM]: El 0% de los pedidos pasa a pagado sin una confirmación verificada de la pasarela con monto y moneda coincidentes; en pruebas, el 100% de las confirmaciones falsificadas o alteradas se rechazan.
- **SC-011** [RF-51]: Antes de abrir, cada medio de pago tiene una compra aprobada y una rechazada exitosas en pruebas, una compra real pequeña y un retiro a la cuenta bancaria.
- **SC-012** [RF-60]: Una compra de prueba aparece exactamente una vez en cada herramienta de analítica.
- **SC-013** [RF-20]: El 100% de las reservas de pedidos sin pago y sin transacción en proceso se liberan entre 30 y 35 minutos después de crear el pedido, aunque el pedido se haya modificado; las que tienen una transacción en proceso se liberan a más tardar a las 2 horas.
- **SC-014** [9.3 metas]: En los primeros 30 días, conversión entre 1,5% y 2,5%, complemento agregado en 1 de cada 4 pedidos y rechazos de pago menores al 15% (hipótesis a validar, no puerta de apertura).
- **SC-015** [TM]: Una revisión del código público de la tienda no encuentra ninguna llave privada ni de integridad.

---

## Assumptions

- **Alcance**: este spec cubre solo P0 (fases 1 a 5 del plan de entrega del documento fuente). Quedan fuera y se planificarán como features aparte: asistente con IA, tandas con cupos, beneficio de acceso anticipado 24 h, recuperación de carrito, reseñas con foto, referidos, tarjeta de regalo, oferta posterior al pago, segundo set con descuento, empresarial con cotización, cuenta completa de clienta (P1) y todo lo P2. En P0 la clienta puede crear cuenta de forma opcional después de comprar solo para recuperar su carrito; la página de cuenta completa es P1.
- **Plataforma y proveedores** (decisión del documento fuente y de la elicitación, detallada en el plan): WordPress + WooCommerce; pasarela Wompi con su plugin oficial (widget alojado por Wompi) más una capa de blindaje propia (decisión del 3 nov 2026, ver research.md R-01); hosting Hostinger; GA4 + Meta Pixel + API de conversiones.
- **Administración**: una sola cuenta administradora permanente (Sara). No hay roles adicionales en P0, salvo cuentas temporales de soporte (FR-078).
- **Cumplimiento PCI**: el objetivo es mantener la elegibilidad al cuestionario SAQ A (ver `compliance-mapping.md`). Los controles operativos que exige (AOC de la pasarela, escaneo ASV trimestral, monitoreo de scripts, plan de incidentes, parcheo) se resuelven en el plan.
- **Valores por defecto de la sección 14 del documento fuente** (Sara puede cambiarlos sin cambiar el diseño): mínimo 4 de 7 pasos para Full face; conjuntos llamados "sets"; precio ≈ costo × 2,2 redondeado a 500; el set no cuesta menos que la suma (se muestra el valor de la presentación); sin envío gratis hasta conocer el ticket promedio; una tarifa metropolitana y una nacional; un tamaño de neceser con hasta 6 piezas; reserva de 30 minutos; sin cambio por higiene en productos abiertos salvo defecto o error; registro sanitario visible si el proveedor lo tiene; etiqueta "Nuevo" 21 días.
- **"Últimas unidades"**: se muestra con stock real de 3 unidades o menos (umbral editable).
- **Códigos de descuento**: en P0 el campo de código existe y acepta códigos creados manualmente por Sara (porcentaje o valor fijo, con fecha de vencimiento y uso máximo); no hay referidos ni descuentos automáticos.
- **Mensajes por celular**: en P0 se envían por un canal transaccional de SMS o WhatsApp con plantillas aprobadas; el proveedor se elige en el plan junto con el de correo (decisión 14-13).
- **Dependencias externas que bloquean la apertura, no el desarrollo**: aprobación de la cuenta Wompi y llaves de producción; textos legales revisados por un abogado (RF-71, RF-72); figura legal, IVA y datos del vendedor (decisión 14-11); operador, tarifas y tiempos de envío; fotos de producto, swatches, sets y empaque; hoja de Inventario completa; plantillas de correo.
- **Impuestos**: los precios se muestran como precio final al público; el tratamiento de IVA se ajusta cuando el contador lo confirme, sin cambiar el flujo.
- **Volumen inicial**: tráfico de lanzamiento de una tienda pequeña (cientos de visitas al día, decenas de pedidos al día); picos por publicaciones en redes de hasta 10 veces el promedio.
- **Zona horaria**: hora de Colombia (UTC−5) para fechas, reservas y etiquetas.
- **Idioma y moneda**: español; pesos colombianos (COP).
