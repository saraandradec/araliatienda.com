# Política de caché (T034, research R-21)

## Nunca en caché
Checkout, order-pay, Gracias, carrito, mi cuenta, `/wp-json/aralia/v1/*` de sesión, `/wc/store/*` y `?wc-api=*`. `Aralia\Cache\PaymentPages` marca las páginas de pago con `nocache_headers()`, `DONOTCACHEPAGE` y `litespeed_control_set_nocache`, con independencia del modo de la CSP.

## En caché (LiteSpeed + CDN)
Home, listados, fichas y secciones, con TTL de 10 minutos. La disponibilidad (`GET /aralia/v1/availability?ids=`) es pública, cacheable 60 s y se purga por etiqueta. La Store API siempre vuelve a validar stock y precio al agregar.

## Etiquetas y purga (`Aralia\Cache\CacheInvalidator`)
| Etiqueta | Qué cubre |
|---|---|
| `ara_home` | home |
| `ara_p_<id>` | ficha del producto (una variación purga a su producto padre) |
| `ara_c_<id>` | categoría |
| `ara_s_<id>` | sección (`aralia_seccion`, T038) |

Las etiquetas se ADJUNTAN a las respuestas (`litespeed_tag_add` en la home, fichas y categorías); sin eso `litespeed_purge` no purgaría nada. Se purgan al guardar un producto, cambiar su stock, publicarlo o despublicarlo y cambiar sus términos. Las purgas de una petición se juntan y se hacen **una vez** al terminar; un cambio de stock NO purga `ara_home` (cada venta no puede vaciar la caché de la home). Se llama a `do_action( 'litespeed_purge', $etiqueta )` y a `do_action( 'aralia_cdn_purge', $etiquetas )` (la CDN engancha esa acción cuando se elija proveedor). Los transients de caché llevan versión (`aralia_cache_v<N>_<nombre>`, `CacheInvalidator::transient_key()`): cada purga sube la versión y las claves viejas dejan de leerse (funciona con caché de objetos persistente). Los transients de seguridad usan el prefijo `aralia_sec_` y la purga nunca los toca. Las etiquetas con otro formato se descartan.

## Configuración de LiteSpeed (manual, en Hostinger — T012)
Estas opciones no se pueden fijar desde el código; hay que hacerlas en el panel de LiteSpeed Cache y registrarlas en `ops/baseline-config.md`:
1. Excluir de la caché las rutas de la sección «Nunca en caché».
2. **Desactivar la optimización de JavaScript** (combinar, diferir, retrasar) en las páginas de pago y para `widget.js`.
2b. **Desactivar también la optimización de JavaScript inline** (minificar, combinar, diferir, retrasar) en las páginas públicas: LiteSpeed procesa el buffer después del nuestro y cambiaría los inline (p. ej. `type="litespeed/javascript"`), con lo que los hashes calculados dejarían de coincidir y en `enforce` se bloquearía JS público.
3. Activar la caché por etiquetas (cabecera `X-LiteSpeed-Tag`).
4. TTL de página: 10 minutos.

## Verificación
`T-CSP-CACHE` (Fase 2 del plan): una página pública cacheada debe seguir sirviendo una CSP cuyos hashes sean un SUBCONJUNTO de los scripts inline de su cuerpo, y cada inline ejecutable del cuerpo sin hash debe reportarse (los scripts que no pasan por la API de WordPress NO tienen hash a propósito).
