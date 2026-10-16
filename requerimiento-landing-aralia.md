# Requerimiento: página de entrada de Aralía Beauty Store

## 1. Objetivo
Publicar una página de entrada única ("coming soon") mientras se construye la tienda. Debe comunicar que la tienda llega pronto y transmitir la marca con un aire premium.

## 2. Alcance
- Una sola página, de una sola pantalla.
- Sin barra de navegación, menú, pie de página, carrito ni enlaces.
- Sin formulario de registro ni redes sociales en esta versión.

## 3. Contenido (texto exacto)
| Elemento | Texto |
|---|---|
| Logo | Wordmark "ARALÍA" en crema (SVG) |
| Titular | Esta será tu tienda favorita. |
| Subtítulo (mayúsculas) | Todo tu maquillaje, en un solo lugar |
| Etiqueta inferior (mayúsculas) | Próximamente |

El texto alternativo del logo es "Aralía Beauty Store". El título de la pestaña del navegador es "Aralía Beauty Store".

## 4. Estilo
**Sensación:** sobria, con mucho espacio libre y pocos elementos. Premium por contención, sin ornamentos.

**Colores**
| Uso | Color |
|---|---|
| Fondo (degradado radial) | Centro #5D2044, bordes #3A0F28 |
| Logo y subtítulo | Crema #F3F7EF |
| Titular | Rosa empolvado #E2B4C4 |
| Línea vertical y "Próximamente" | Champagne #C8A46A |

**Tipografías (Google Fonts)**
- Cormorant Garamond Light Italic para el titular.
- Jost Light y Medium para subtítulo y etiqueta, en mayúsculas con espaciado entre letras.
- Alternativas si se usan licenciadas: Canela o GT Super y Neue Montreal.

**Elementos gráficos**
- Una línea vertical fina con degradado champagne entre el logo y el titular.
- Una sola fronda de aralia en línea fina champagne (trazo 0,7), con opacidad de 0,4 a 0,5, que asoma por la esquina inferior derecha. Es la hoja pinnada de la marca: eje central, tres pares de hojuelas a -42° y -138° y una terminal.
- Sin patrones de fondo, marcos, arcos ni adornos adicionales.

**Composición**
- Contenido centrado en vertical y horizontal: logo, línea, titular y subtítulo.
- "Próximamente" fijo al pie, entre dos filetes champagne cortos.

## 5. Tamaños de referencia
- Logo: ancho `min(80vw, 540px)`.
- Titular: `clamp(34px, 6vw, 56px)`, interlineado 1,08.
- Subtítulo: `clamp(14px, 1.9vw, 17px)`, espaciado 0,22 em.
- Etiqueta "Próximamente": 11 px, espaciado 0,38 em.
- Línea vertical: 1 px de ancho, 56 px de alto (44 px en móvil).

## 6. Comportamiento y responsive
- Se adapta a cualquier ancho, con mínimo de 320 px, sin scroll horizontal.
- Margen lateral mínimo de 24 px.
- En móvil (hasta 480 px) la fronda se desplaza hacia la esquina para no tocar "Próximamente", y se reducen los espacios.
- Animación de entrada: aparición suave con desplazamiento de 8 px, de 1,4 s. Se desactiva con `prefers-reduced-motion`.
- Respeta las zonas seguras de iPhone (`env(safe-area-inset-*)`).

## 7. Técnico
- HTML y CSS estáticos, sin dependencias de JavaScript ni librerías.
- Un solo archivo; el logo va incrustado en SVG.
- Recurso externo: solo Google Fonts.
- Metadatos que debe agregar el equipo (la maqueta no los incluye): `lang="es"`, etiqueta `viewport`, descripción y vista previa para compartir (Open Graph).
- Texto alternativo en el logo; la fronda es decorativa (`aria-hidden`).
- Contraste: crema y rosa sobre ciruela cumplen AA; el champagne se usa solo en elementos decorativos y en una etiqueta pequeña.

## 8. Fuera de alcance (versiones siguientes)
- Registro de correo o lista de espera.
- Enlace a Instagram y a WhatsApp.
- Cuenta regresiva.
- Pixel de Meta y de TikTok.
- Plantilla completa de la tienda: inicio, categorías, producto, carrito y checkout.

## 9. Criterios de aceptación
- [ ] Se ve igual que la referencia aprobada en computador y celular.
- [ ] Los textos coinciden exactamente con la sección 3.
- [ ] No hay scroll horizontal en ningún ancho.
- [ ] Las fuentes cargan; si fallan, se usa la fuente de respaldo (Georgia o Arial) sin romper la composición.
- [ ] Carga en menos de 2 segundos con conexión móvil estándar.

## 10. Referencia
Archivo adjunto: `index.html`, con la maqueta aprobada.
