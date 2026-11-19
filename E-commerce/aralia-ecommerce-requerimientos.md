# Aralía Beauty Store — Requerimientos del e-commerce

Versión 3.1 · 3 de noviembre de 2026 · Tienda estándar, funcional y orientada a la venta · Documento para ejecutar

Cambios frente a la versión 1.0: la tienda ya no gira alrededor de la "bandeja". Es una tienda normal con **categorías**, **secciones curadas** y **ángulos de venta** integrados en cada pantalla. La bandeja pasa a ser solo la presentación física de los sets de Cejas y Labios.

Cambios frente a la versión 2.0: (1) **Full face es por marca**: al entrar se ve "Full face con Atenea", "Full face con Montoc", etc. (4.4). (2) **La tienda vende solo producto propio** (Bodega Aralía). Los productos de Dropi se venden por marketplace y no entran a esta tienda (2.4 y 4.5); así todo pedido sale de un solo lugar, en un solo paquete.

---

## 0. Cómo usar este documento

- Cada requisito tiene un ID (`RF-xx` funcional, `RN-xx` regla de negocio, `RT-xx` técnico) y un criterio de aceptación verificable.
- Prioridad: **P0** = sin esto no se abre · **P1** = primeras 4 semanas tras abrir · **P2** = después.
- Valores marcados **[DEFINIR]** son decisiones de Sara (sección 14). Hay un valor por defecto para no frenar la obra.
- Precios, cupos y fechas de los ejemplos son ilustrativos.
- Complementa a: `Plan técnico – Compra asistida Aralía` (arquitectura y costos) y al prototipo de interacción.

---

## 1. Objetivo y principios

### 1.1 Objetivo
Una tienda de maquillaje clara y rápida, en la que la clienta compre sin pensar demasiado y sin escribir a nadie. No depende de que ella sepa qué necesita: la tienda **propone** (secciones, looks, sets, tandas) y **facilita** (agregar todo con un toque, tono guardado, pago en una página).

### 1.2 Principios
1. **Tienda normal, bien hecha.** Menú claro, categorías, filtros, ficha, carrito y pago sin sorpresas.
2. **Proponer antes que buscar.** La portada y las secciones muestran looks y sets completos, no solo un listado.
3. **Un toque para comprar un look completo** ("Agregar todo") y la opción de quitar lo que no quiera.
4. **El tono se pide solo donde importa** (Piel y Cejas). En Labios, Ojos y Pestañas no se pide.
5. **Escasez y urgencia solo si son reales.**
6. **Cada compra abre la siguiente:** complemento en el carrito, oferta tras el pago, aviso de la próxima tanda.
7. **Autoservicio:** WhatsApp solo para un pedido pagado con problema.

### 1.3 Reglas de marca (obligatorias en todo texto público)
- No usar "colombiano/a" para describir la tienda o los productos.
- No mencionar la cantidad de marcas.
- No usar "kits" en la bio de redes. En la tienda, el nombre de los conjuntos se define en la sección 14 (por defecto: **sets**).
- Tono premium, cálido y directo; sin signos de exclamación repetidos ni lenguaje de remate.
- Frase de marca: "Esta será tu tienda favorita. Todo tu maquillaje, en un solo lugar." Al abrir, la bio cambia de "Pronto, compra en línea" a "Compra en línea".

---

## 2. Alcance

**Incluido P0:** home, categorías, secciones, listado con filtros, ficha, carrito lateral, checkout de una página con Wompi, envíos por zona, correos y mensajes automáticos, panel de pedidos, analítica, páginas de confianza y legales, constructor del Neceser Aralía.
**Incluido P1:** asistente con IA, tandas con cupos, lista de acceso anticipado, recuperación de carrito, reseñas con foto, referidos, tarjeta de regalo, oferta posterior al pago, kit empresarial con cotización.
**Incluido P2:** suscripción, recomendaciones por historial, puntos, mayorista.
**Fuera de alcance:** app móvil, envíos internacionales, chat humano en vivo, **productos de Dropi** (se venden por marketplace), integración con Dropi y venta de esta tienda en marketplaces.

---

## 3. Plataforma

| Elemento | Decisión | Nota |
| --- | --- | --- |
| CMS y tienda | WordPress + WooCommerce | Decidido el 28 de octubre |
| Tema | Tema de bloques ligero, a medida con los tokens de la sección 11 | Sin constructores pesados |
| Pagos | Plugin "Wompi Portal de Pagos" | Probar en Sandbox antes de abrir |
| Asistente | Servicio propio + API de Claude | P1; ver Plan técnico |
| Correo transaccional | **[DEFINIR proveedor]** | El correo por defecto de WordPress no basta |
| Analítica | GA4 + Meta Pixel + API de conversiones | Eventos en la sección 9 |
| Hosting | **[DEFINIR]**; HTTPS, copias diarias, CDN para imágenes | |

**RT-01 (P0).** LCP menor a 2,5 s en móvil con 4G y Lighthouse móvil de Rendimiento mayor o igual a 85. *Aceptación:* tres corridas en home, listado y ficha; valor mediano.
**RT-02 (P0).** Diseño móvil primero: el tráfico vendrá sobre todo de Instagram y TikTok en celular. *Aceptación:* el flujo entrar, elegir, pagar se completa con una mano en 360 px de ancho, sin zoom.
**RT-03 (P0).** Imágenes WebP/AVIF con carga diferida, salvo la primera pantalla.
**RT-04 (P0).** Copias diarias y una restauración probada antes de abrir.

---

## 4. Estructura de la tienda

### 4.1 Menú principal
**Categorías · Secciones · Tandas (P1) · Regalos · Cómo llega**, más buscador, cuenta y carrito. En móvil: menú tipo cajón con dos bloques, Categorías y Secciones.

### 4.2 Categorías (las iniciales)
| Categoría | Contenido | ¿Pide tono? |
| --- | --- | --- |
| Labios | Bálsamos, lápices, labiales, gloss, aceites | No |
| Cejas | Lápices, geles, serum, fijadores | Sí, en lápices |
| Pestañas | Pestañinas, serum de pestañas | No |
| Ojos | Delineadores, sombras | No |
| Piel | Corrector, base, rubor, polvo, iluminador, bronzer | Sí |
| Brochas | Brochas y esponjas | No |
| Accesorios | Charms de maquillaje, espejos llavero, neceseres | No |

Una pieza puede estar en una categoría principal y aparecer en secciones. Los productos nuevos aparecen primero con la etiqueta **Nuevo** durante 21 días **[DEFINIR días]**.

### 4.3 Secciones curadas
Las secciones son colecciones con una intención de compra. Muestran **sets** ya pensados y las piezas sueltas que los componen.

| Sección | Qué es | Cómo se compra |
| --- | --- | --- |
| **Full face** | Al entrar, se ve directamente un look completo por marca: "Full face con Atenea", "Full face con Montoc", etc. Se arma solo desde el catálogo (4.4) | "Agregar todo" y ajustar |
| **Para principiantes** | Lo mínimo para empezar, con el orden de uso | "Agregar todo" |
| **Makeup no makeup** | Look natural, de pocas piezas | "Agregar todo" |
| **Cejas perfectas** | Set fijo de Cejas | Producto compuesto |
| **Labios** | Set fijo de Labios | Producto compuesto |
| **Retoque** | Lo que cabe en el bolso para repasar el día | "Agregar todo" |
| **Surprised bag** | Bolsa sorpresa por categoría o look; la clienta elige el tipo y no ve el contenido | Producto con opciones |
| **Neceser Aralía** | Neceser transparente con cremallera y nombre en vinilo, con piezas a elección | Constructor (5.7) |
| **Empresarial** | Pedidos por volumen con nombre o logo | Formulario de cotización (P1) |

Reglas de negocio del catálogo de sets:
**RN-01.** Solo **Cejas** y **Labios** son sets fijos físicamente armados. Las demás secciones se componen de piezas sueltas seleccionadas, con el stock de cada una.
**RN-02.** En los sets de Cejas y Labios no se repite marca: una marca por producto. Los Full face son la excepción: todo es de una sola marca.
**RN-03.** El precio de un set sale del costo de compra más el margen definido, no del precio al público de otras tiendas.

### 4.4 Full face por marca
Al abrir **Full face**, la clienta no elige criterios: ve una fila por marca con su look completo ya armado.

- **RF-F1 (P0).** La página muestra una tarjeta por marca elegible, con foto del look, piezas incluidas, precio total y "Agregar todo". Sin mencionar cuántas marcas hay.
- **RF-F2 (P0).** Cada marca tiene su página con URL estable: `/full-face/atenea`, `/full-face/montoc`.
- **RF-F3 (P0).** El look se arma solo desde el catálogo con el atributo **Paso de Full face**: Piel (corrector), Piel (rubor), Piel (polvo), Cejas, Ojos, Pestañas, Labios. Para cada paso se toma la pieza de esa marca con stock; si hay varias, la más vendida.
- **RF-F4 (P0).** Una marca aparece solo si cubre al menos **[DEFINIR; defecto 4]** de los 7 pasos con stock. Si le falta un paso, el look se muestra con los que sí tiene; no se completa con otra marca.
- **RF-F5 (P0).** Si menos de 2 marcas cumplen el mínimo, la sección Full face se oculta del menú y de la home hasta que haya stock suficiente.
- **RF-F6 (P0).** Desde el panel se puede fijar una pieza para un paso de una marca, ocultar una marca o cambiar el orden.
- **RF-F7 (P0).** Los pasos que dependen del tono (corrector, rubor, cejas) piden el tono una sola vez antes de agregar, en la misma página.
- **RF-F8 (P0).** "Cambiar piezas" permite quitar un paso o cambiar la pieza por otra de la misma marca y paso.
- **RF-F9 (P0).** Si una pieza se agota, el look se actualiza solo, y si la marca baja del mínimo deja de listarse sin intervención.

### 4.5 Origen del producto
**RN-07.** Todo lo que se vende en esta tienda es stock propio de Aralía: se despacha desde su bodega, con su empaque, en un solo paquete por pedido.
**RN-08.** Los productos de Dropi no se publican aquí. Se venden por marketplace, con su propio inventario, precios y reglas, y no comparten stock con esta tienda.
**RN-09.** Si más adelante se quiere incluir Dropi en la tienda, se hace como un cambio de alcance aparte (envíos por paquete, sin regalos ni sets con esas piezas, integración de pedidos y stock).

---

## 5. Páginas y requisitos

### 5.1 Home (P0)
Orden de bloques:
1. **Hero** con una propuesta única: la sección o tanda vigente, con un botón principal. Cambia desde el panel.
2. **Categorías:** 7 tarjetas con foto.
3. **Full face:** 3 marcas destacadas con su look completo y "Agregar todo" (si la sección está activa, RF-F5).
4. **Más vendidos** (6 piezas, por ventas reales).
5. **Para principiantes y Makeup no makeup:** dos tarjetas de sección.
6. **Sets de Cejas y Labios:** presentación con foto del empaque.
7. **Nuevo:** las últimas 6 piezas.
8. **Cómo llega:** empaque, envío, pago seguro.
9. **Reseñas con foto** (P1; en P0, fotos reales de pedidos).
10. **Lista de acceso anticipado:** "Entra a la lista y compra la próxima tanda 24 horas antes."
11. **Pie:** medios de pago, políticas, redes.

**RF-01 (P0).** Barra superior con un solo mensaje real (envío gratis desde un monto, tanda por cerrar o novedad), apagable desde el panel.
**RF-02 (P0).** Los bloques 1, 3, 4, 7 se alimentan del catálogo; no hay que reeditar la home por cada cambio. *Aceptación:* cambiar las marcas destacadas del Full face desde el panel actualiza la home sin tocar código.

### 5.2 Listado de categoría y de sección (P0)
- Encabezado con foto, una línea de propuesta y conteo de piezas.
- Filtros: marca, color, look, tono (donde aplica), precio, disponibilidad. Orden: recomendado, más vendidos, nuevos, precio.
- Tarjeta: foto, swatch, nombre, marca, precio, etiqueta (Nuevo, Últimas unidades, Agotado) y botón "Agregar".
- Selector de tono en la tarjeta solo en Piel y lápices de cejas.

**RF-03 (P0).** Filtros combinables, sin recargar la página y con URL compartible.
**RF-04 (P0).** Orden recomendado: afinidad con el tono y el look guardados (si existen), luego disponibles primero, luego más vendidos.
**RF-05 (P0).** "Agregar" en la tarjeta abre el carrito lateral con "Complétala"; no cambia de página.
**RF-06 (P0).** Una pieza agotada muestra "Avísame cuando vuelva" con correo o celular.

### 5.3 Página de sección con sets (P0)
Cada set de una sección muestra: nombre, para quién es, piezas incluidas con foto, precio total, precio de las piezas por separado, "Agregar todo" y "Cambiar piezas".

**RF-07 (P0).** "Agregar todo" agrega todas las piezas al carrito como líneas separadas, con sus tonos si aplican, y las agrupa visualmente como un set. *Aceptación:* se puede quitar una pieza sin romper el set.
**RF-08 (P0).** Si el set incluye una pieza que depende del tono y no se ha elegido, se abre un selector de tono en la misma página antes de agregar.
**RF-09 (P0).** "Cambiar piezas" permite sustituir una pieza por otra del mismo paso (por ejemplo otro gloss), recalculando el precio.
**RF-10 (P0).** Si una pieza del set se agota, el set se marca "Disponible con cambio" y propone el reemplazo.

### 5.4 Ficha de producto (P0)
Galería (producto, en uso, swatch real), nombre, marca, precio, selector de tono con swatch, disponibilidad real, descripción corta, cómo se usa, ingredientes y registro sanitario **[DEFINIR si se muestra]**, "Combina con" (2 piezas), "Aparece en estos sets", bloque "Cómo llega", reseñas (P1), botón de compra fijo en móvil.

**RF-11 (P0).** Botón fijo al pie en móvil con precio.
**RF-12 (P0).** "Combina con" se define en el catálogo por look y color, no al azar.
**RF-13 (P0).** "Aparece en estos sets" enlaza a las secciones y agrega el set con un toque.
**RF-14 (P0).** Compartir por WhatsApp, Instagram y copiar enlace.

### 5.5 Carrito lateral (P0)
- Líneas con foto, nombre, tono, cantidad y quitar; sets agrupados.
- Barra de progreso hacia el envío gratis **[DEFINIR monto]**.
- "Complétala": 2 piezas con un toque.
- Campo de código, plegado.
- Botón "Pagar" con el total, y debajo medios de pago y garantía.

**RF-15 (P0).** El carrito persiste 30 días y se recupera al iniciar sesión.
**RF-16 (P0).** Si una pieza se agota antes del pago, el carrito lo avisa y ofrece una alternativa del mismo tipo.

### 5.6 Checkout de una página (P0)
- Compra como invitada; crear cuenta es opcional y posterior.
- Campos: nombre, celular, correo, ciudad, dirección, indicaciones.
- Autocompletado y validación de ciudad contra las zonas de envío.
- Casilla **"Es un regalo"**: nombre y celular de quien recibe, mensaje de tarjeta (90 caracteres), empaque sin precios, fecha deseada.
- Resumen visible siempre.
- Medios: tarjeta, PSE, Botón Bancolombia y Nequi (los que Wompi habilite).
- Casillas: términos y privacidad (obligatoria); recibir novedades (opcional, sin marcar).

**RF-17 (P0).** De "Pagar" a Wompi, un solo paso, sin páginas intermedias.
**RF-18 (P0).** El pedido pasa a pagado solo tras confirmar con Wompi el estado APPROVED. DECLINED, VOIDED y ERROR dejan el pedido pendiente con opción de reintentar con otro medio, sin vaciar el carrito.
**RF-19 (P0).** Costo de envío por ciudad visible antes de pagar. **[DEFINIR tarifas]**
**RF-20 (P0).** Reserva de stock al crear el pedido pendiente y liberación automática a los **[DEFINIR; defecto 30]** minutos sin pago.
**RF-21 (P0).** Etiquetas y mensaje de regalo se guardan en el pedido y aparecen en la hoja de empaque del panel.

### 5.7 Neceser Aralía (constructor, P0)
Producto personalizado, no un armador general:
1. Elegir tamaño o tipo de neceser **[DEFINIR opciones]**.
2. Escribir el nombre que va en vinilo (hasta 14 caracteres) y elegir color de vinilo.
3. Elegir hasta N piezas de las categorías, con "Agregar todo" desde un look sugerido.
4. Ver el precio total y agregar al carrito.

**RF-22 (P0).** El nombre se valida (caracteres permitidos, longitud) y se guarda en el pedido para cortarlo en la Cricut. *Aceptación:* el pedido muestra el texto exacto y el color.
**RF-23 (P0).** Los neceseres personalizados no admiten cambio, y se avisa antes de pagar.

### 5.8 Páginas de apoyo (P0)
**Gracias:** resumen, tiempos y qué sigue; oferta posterior (P1).
**Cuenta (P1):** pedidos, estado, direcciones, tono guardado, código de referidos.
**Cómo llega:** fotos reales del empaque, tiempos por ciudad, seguimiento.
**Ayuda:** preguntas por tema, cambios y garantías, términos, privacidad. Un enlace de contacto solo para pedidos ya pagados.
**Buscador:** con sugerencias por categoría y producto, tolera errores de escritura. *Aceptación:* "corectol" encuentra "corrector".

### 5.9 Tandas (P1)
Lanzamientos por tiempo o por cupos de piezas o sets, con página propia, cupos reales y cierre. Al agotarse: lista de espera. Es una sección más del menú, no la portada.

**RF-24.** Una tanda abre y cierra sola a la hora definida (hora de Colombia).
**RF-25.** Dos personas que compran el último cupo a la vez: solo una compra se confirma.

---

## 6. Ángulos de venta

| # | Ángulo | Qué ve la clienta | Dónde | Prioridad |
| --- | --- | --- | --- | --- |
| A1 | **Look completo en un toque** | "Full face con…" con piezas y "Agregar todo" | Home, secciones | P0 |
| A2 | **Más vendidos** | Las 6 piezas que más se compran | Home, categorías | P0 |
| A3 | **Complétala** | 2 piezas que combinan en el carrito y la ficha | Carrito, ficha | P0 |
| A4 | **Envío gratis con barra de progreso** | "Te faltan $X para el envío gratis" | Carrito, ficha | P0 |
| A5 | **Valor del set** | "Piezas por separado $X · Tu set $Y" solo si es cierto | Secciones | P0 |
| A6 | **Regalo** | Casilla "Es un regalo", mensaje, empaque sin precios | Checkout, menú Regalos | P0 |
| A7 | **Tono guardado** | "Tu tono: Canela" y piezas ordenadas para ti | Piel, Cejas | P0 |
| A8 | **Confianza** | Pago seguro, "Cómo llega", política clara | Todo el embudo | P0 |
| A9 | **Surprised bag** | Bolsa sorpresa por tipo | Sección propia | P0 |
| A10 | **Tandas con cupos** | "Quedan 7 de 20 · cierra en 5 días" | Menú, home | P1 |
| A11 | **Acceso anticipado** | Lista para comprar 24 horas antes | Home, pie | P1 |
| A12 | **Recuperación de carrito** | Correo a 1 h y 24 h con las piezas | Automatización | P1 |
| A13 | **Oferta tras el pago** | "Agrega a tu pedido en 30 minutos" con envío incluido | Gracias, correo | P1 |
| A14 | **Prueba social** | Reseñas con foto, publicaciones de clientas | Ficha, home | P1 |
| A15 | **Referidos** | Código para regalar y recibir descuento | Correo de entrega, cuenta | P1 |
| A16 | **Segundo set** | Descuento por segundo set **[DEFINIR %]** | Carrito | P1 |
| A17 | **Tarjeta de regalo** | Valor fijo con código | Regalos | P1 |
| A18 | **Siguiente tanda** | Aviso a compradoras y lista | Correo | P1 |

**RN-04.** Ningún contador, cupo o "últimas unidades" se muestra sin datos reales. *Aceptación:* cada valor coincide con el inventario y con la fecha del servidor.
**RN-05.** El precio tachado solo aparece si el producto estuvo a ese precio al menos 14 días antes.
**RN-06.** Una pieza solo se marca "Más vendido" con ventas reales de la tienda, nunca por defecto.

---

## 7. Asistente de compra (P1)

**RF-30.** Cuadro "Cuéntame qué buscas" en la home y en las secciones. Entiende ocasión, color, para quién es y tono, y devuelve un look con piezas que tienen stock, con "Agregar todo".
**RF-31.** Solo recomienda piezas devueltas por el catálogo; precios y stock vienen de WooCommerce.
**RF-32.** Si falla o no entiende, la página sigue funcionando con categorías y secciones, sin mensaje técnico.
**RF-33.** Límite de mensajes por sesión y por IP, y tope mensual de gasto.
**RF-34.** Crea el carrito o un pedido pendiente; el pago siempre pasa por el checkout.
Detalle técnico y costos: `Plan técnico – Compra asistida Aralía`.

---

## 8. Catálogo y datos

### 8.1 Atributos por producto (P0)
| Atributo | Valores | Obligatorio |
| --- | --- | --- |
| Categoría | Labios, Cejas, Pestañas, Ojos, Piel, Brochas, Accesorios | Sí |
| Subcategoría | Bálsamo, lápiz, gloss, corrector, rubor… | Sí |
| Marca | Texto | Sí |
| Depende del tono | Sí / No | Sí |
| Familia de tono | Porcelana, Marfil, Miel, Canela, Caramelo, Espresso | Si depende del tono |
| Subtono | Frío, neutro, cálido | Si depende del tono |
| Color | Nudes, rosas, rojos, berry, cobre y dorados, light, medium | Sí |
| Look | Natural, clásico, noche, atrevido | Sí |
| Secciones | Una o más | No |
| Combina con | 2 productos | Sí |
| Costo, precio de venta, stock | Números; el costo es interno | Sí |
| Foto, foto en uso, swatch real | Imagen | Foto y swatch obligatorios |
| Paso de Full face | Piel (corrector), Piel (rubor), Piel (polvo), Cejas, Ojos, Pestañas, Labios | Sí, si puede entrar en un Full face |
| Registro sanitario | Texto | **[DEFINIR si se exige antes de publicar]** |

**RF-40 (P0).** Una pieza sin foto, swatch (si tiene color o tono) o stock no se publica. *Aceptación:* el panel lista las incompletas y bloquea su publicación.
**RF-41 (P0).** Los nombres de las familias de tono se definen una vez, con swatches reales fotografiados. Los del prototipo son provisionales.
**RF-42 (P0).** La hoja de Inventario es la fuente de carga: columnas de atributos y de secciones, importación por CSV.
**RF-43 (P0).** Alta rápida de producto en el panel con todos los atributos en una sola pantalla.

### 8.2 Sets
Campos: nombre, sección, piezas por paso (con alternativas), precio, imagen del set, estado.
**RF-44 (P0).** El precio del set se calcula a partir de las piezas y puede tener un ajuste manual. Si una pieza cambia de precio, el panel avisa los sets afectados.

---

## 9. Pagos, envíos, mensajes y analítica

### 9.1 Pagos, envíos e impuestos
**RF-50 (P0).** Pagos con Wompi en COP. Llaves de Sandbox y producción y URL de eventos en ambos ambientes.
**RF-51 (P0).** Pruebas antes de abrir: una compra aprobada y una rechazada por cada medio (tarjeta, PSE, Botón Bancolombia, Nequi) y un retiro pequeño a la cuenta bancaria.
**RF-52 (P0).** Envíos por zona: Medellín y área metropolitana, resto del país **[DEFINIR operador, tarifas y tiempos]**.
**RF-53 (P0).** Envío gratis sobre un monto **[DEFINIR; defecto: sin envío gratis hasta conocer el ticket promedio]**.
**RF-54 (P0).** Precios mostrados con la tributación que confirme el contador **[DEFINIR; hoy Aralía es persona natural y el régimen de IVA está por confirmar]**.
**RF-55 (P1).** Cuotas: no al lanzar; revisar con datos.

### 9.2 Mensajes automáticos
| Evento | Canal | Prioridad |
| --- | --- | --- |
| Pedido recibido | Correo | P0 |
| Pago aprobado | Correo y celular | P0 |
| Pedido empacado, con foto | Correo | P0 |
| Despachado con guía | Correo y celular | P0 |
| Pago rechazado, con enlace para reintentar | Correo | P0 |
| Pieza agotada disponible otra vez | Correo o celular | P0 |
| Entregado + 3 días: reseña y código de referidos | Correo | P1 |
| Carrito abandonado a 1 h y 24 h | Correo (celular con opt-in) | P1 |
| Tanda por cerrar (24 h) | Correo a lista y carritos | P1 |
| Apertura de tanda | Correo y celular a la lista | P1 |

**RF-56 (P0).** Todos salen del panel sin intervención manual, con plantillas de marca.
**RF-57 (P0).** Mensajes por celular solo a quien aceptó, con opción de baja en cada uno.
**RF-58 (P0).** Textos redactados con las reglas de marca (1.3), con una sola acción principal por mensaje.

### 9.3 Analítica
Eventos: `view_item_list`, `view_item`, `select_tone`, `add_to_cart`, `add_set`, `view_cart`, `begin_checkout`, `add_payment_info`, `purchase`, `join_early_access`, `add_upsell`, `gift_mode_on`, `assistant_used`.
**RF-60 (P0).** Eventos enviados desde navegador y servidor con identificador para evitar duplicados. *Aceptación:* una compra de prueba aparece una sola vez en GA4 y en Meta.
**RF-61 (P0).** Panel con visitas, conversión, ticket promedio, piezas y sets más vendidos, carritos abandonados, rechazos de pago por medio, stock bajo.

**Metas de partida** (hipótesis mías, a validar en los primeros 30 días): conversión de 1,5% a 2,5%; complemento agregado en 1 de cada 4 pedidos; rechazos de pago menores al 15%.

---

## 10. Confianza, legal y seguridad

**RF-70 (P0).** Páginas: términos, privacidad, cambios y garantías, envíos, quiénes somos, contacto para pedidos pagados.
**RF-71 (P0).** Aviso de privacidad y autorización de tratamiento de datos al registrar correo y celular (normativa colombiana de protección de datos). **Revisar con un abogado antes de abrir.**
**RF-72 (P0).** Política de cambios y retracto para ventas a distancia (Estatuto del Consumidor). Los productos de cuidado personal abiertos pueden tener excepción por higiene **[DEFINIR]**. **Revisar con un abogado.**
**RF-73 (P0).** Datos del vendedor visibles (nombre o razón social, NIT o cédula, ciudad, contacto) **[DEFINIR según la figura legal]**.
**RT-10 (P0).** HTTPS en todo el sitio, doble factor en el panel, ninguna llave de Wompi o de la API de IA en el navegador.
**RT-11 (P0).** Firmas de pago y llaves solo en el servidor. *Aceptación:* revisión del código de la página sin encontrar llaves privadas.
**RT-12 (P0).** Accesibilidad básica: contraste AA, foco visible, textos alternativos, formularios con etiquetas, respeto a movimiento reducido.
**RT-13 (P0).** El tono y el carrito solo guardan datos no sensibles; no se guardan datos de tarjeta en el sitio.

---

## 11. Diseño

### 11.1 Tokens (de la landing actual)
| Token | Valor |
| --- | --- |
| Fondo vino | `#3A0F28` |
| Vino claro | `#5D2044` |
| Crema | `#F3F7EF` |
| Rosa | `#E2B4C4` |
| Champagne | `#C8A46A` |
| Títulos | Cormorant Garamond (300, 400 cursiva, 500) |
| Texto | Jost (300, 400, 500) |

Versión clara por defecto (fondo crema, texto vino) y versión oscura (fondo vino, texto crema). Champagne oscurecido para texto sobre crema, por contraste.

### 11.2 Estilo
- Tienda editorial limpia: mucho aire, fotos grandes de producto sobre fondo liso, títulos en cursiva fina, filetes finos en champagne, botones redondeados.
- Un botón principal por vista: relleno vino (claro) o champagne (oscuro). Secundarios con borde.
- Swatch real siempre visible; el color del producto se ve antes que el nombre.
- Sin banners de descuento a pantalla completa ni cuentas regresivas decorativas.

### 11.3 Componentes (biblioteca única)
Tarjeta de categoría, tarjeta de producto, tarjeta de set, selector de tono, filtros, carrito lateral, barra de progreso de envío, bloque "Cómo llega", reseña, formulario de lista, aviso superior, constructor del Neceser.

### 11.4 Microcopy de ejemplo
- Botón de set: "Agregar todo".
- Piezas por separado: "Piezas por separado $X · Tu set $Y".
- Tono requerido: "En esta pieza el tono sí importa." Tono no requerido: sin mención.
- Agotado: "Esta pieza se agotó. Avísame cuando vuelva."
- Regalo: "Llega empacada, lista para regalar."
- Sticker del pedido: "Gracias por tu buen gusto."

---

## 12. Contenido necesario antes de construir

- [ ] Foto de producto sobre fondo liso y swatch real por tono y color
- [ ] Fotos de los sets de Cejas y Labios y del empaque
- [ ] Un look completo para cada sección (Full face con…, Principiantes, Makeup no makeup, Retoque)
- [ ] Precio de venta por pieza y por set
- [ ] Tarifas y tiempos de envío
- [ ] Textos legales revisados por un abogado
- [ ] Plantillas de correo (10 de la sección 9.2)
- [ ] Logos en versiones vino, crema, dorado, ciruela y rosa (ya existen)
- [ ] Hoja de Inventario con atributos completos

---

## 13. Plan de entrega

| Fase | Contenido | Puerta de salida |
| --- | --- | --- |
| 1. Base | WordPress, tema, tokens, WooCommerce, importación de catálogo con atributos, Wompi en Sandbox | Compra de prueba aprobada y rechazada con cada medio |
| 2. Tienda | Home, categorías, listados con filtros, ficha, carrito lateral, checkout de una página, regalo | Una persona de prueba compra una pieza y un look completo sin ayuda |
| 3. Secciones | Full face por marca, sets, "Agregar todo", Surprised bag, Neceser Aralía, "Complétala" | Cada sección con al menos un set vendible de punta a punta |
| 4. Automatización | Mensajes P0, reserva de stock, panel, analítica | Un pedido recorre todos los estados sin intervención |
| 5. Apertura | Pagos en producción, retiro de prueba, legales, lista de acceso anticipado | Lista de la sección 13.1 completa |
| 6. P1 | Asistente, tandas, recuperación, reseñas, referidos, tarjeta de regalo, oferta tras el pago | Cada pieza con su criterio |

### 13.1 Verificación antes de abrir
- [ ] Wompi aprobado, llaves de producción, URL de eventos, compra real pequeña y retiro hechos
- [ ] Una compra por medio de pago y un pago rechazado
- [ ] "Agregar todo" de cada sección funciona y permite quitar piezas
- [ ] Cada Full face por marca se arma solo, una pieza agotada lo actualiza y la sección se oculta si hay menos de 2 marcas
- [ ] Pieza con tono: no se agrega sin tono (Piel y Cejas) y no lo pide en las demás
- [ ] Reserva de stock liberada a los 30 minutos
- [ ] Mensajes P0 con datos correctos
- [ ] Analítica sin duplicados
- [ ] 360 px: flujo completo con una mano
- [ ] Rendimiento (RT-01) y copia restaurada (RT-04)
- [ ] Textos legales publicados
- [ ] Textos revisados contra las reglas de marca (1.3)

---

## 14. Decisiones pendientes de Sara

| # | Decisión | Por defecto si no se define |
| --- | --- | --- |
| 1 | Mínimo de pasos con stock para que una marca tenga Full face | 4 de 7 |
| 2 | Nombre en la tienda para los conjuntos: set, kit o look | Set |
| 3 | Precio de cada pieza y de cada set | Costo × 2,2 aprox., redondeado a 500 |
| 4 | ¿El set cuesta menos que la suma de sus piezas? | No; se muestra el valor de la presentación |
| 5 | Monto de envío gratis | Sin envío gratis hasta conocer el ticket promedio |
| 6 | Operador, tarifas y tiempos de envío | Una tarifa metropolitana y una nacional |
| 7 | Tamaños de Neceser y número máximo de piezas | Un tamaño, hasta 6 piezas |
| 8 | Descuento por segundo set | 10%, solo P1 |
| 9 | Minutos de reserva de stock | 30 |
| 10 | Política de cambios para productos abiertos | Sin cambio por higiene; cambio por defecto o error |
| 11 | Figura legal, IVA y datos del vendedor | Se completa antes de abrir |
| 12 | Registro sanitario visible en ficha | Sí, si está disponible por proveedor |
| 13 | Proveedor de correo transaccional | Evaluar dos opciones antes de la fase 4 |
| 14 | Días de la etiqueta "Nuevo" | 21 |
| 15 | Fecha de apertura | Depende de Wompi, fotos y stock |

---

## 15. Criterios de éxito

1. Una clienta nueva, desde Instagram en el celular, compra un look completo sin escribir a nadie.
2. Desde la home, un look completo llega al carrito en 2 toques.
3. Piel y Cejas piden tono; Labios, Ojos y Pestañas nunca.
4. Cada pedido llega al panel con piezas, tono, mensaje de regalo y nombre del neceser, listo para empacar.
5. Ninguna pieza se publica sin foto, swatch y stock.
6. Al entrar a Full face se ve el look de cada marca ya armado, sin elegir nada.
7. Todo pedido sale de un solo lugar y en un solo paquete.
