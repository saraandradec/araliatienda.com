# Contrato CSV — Hoja de Inventario → catálogo

**Feature**: [../spec.md](../spec.md) · FR-065, FR-066, FR-067 · **Fecha**: 2026-11-03

Formato: UTF-8, separador `,`, primera fila = encabezados exactos. Una fila por producto simple o por **variación** (tono); las variaciones comparten `parent_sku`.

| Columna | Obligatoria | Formato / valores |
|---------|-------------|-------------------|
| `sku` | Sí | `[A-Z0-9-]{3,32}`, único |
| `parent_sku` | Si es variación | SKU del producto padre |
| `nombre` | Sí | 3–120 caracteres |
| `categoria` | Sí | Labios, Cejas, Pestañas, Ojos, Piel, Brochas, Accesorios |
| `subcategoria` | Sí | texto de la lista controlada |
| `marca` | Sí | texto; crea el término si no existe (avisa) |
| `depende_tono` | Sí | `si` / `no` |
| `tono` | Si `depende_tono=si` | Porcelana, Marfil, Miel, Canela, Caramelo, Espresso (lista vigente) |
| `subtono` | Si `depende_tono=si` | frío, neutro, cálido |
| `color` | Sí | lista separada por `|`: nudes, rosas, rojos, berry, cobre y dorados, light, medium |
| `look` | Sí | lista `|`: natural, clásico, noche, atrevido |
| `secciones` | No | lista `|` de slugs de sección |
| `paso_full_face` | Si aplica | piel-corrector, piel-rubor, piel-polvo, cejas, ojos, pestanas, labios |
| `combina_con` | Sí | exactamente 2 SKU separados por `|` |
| `costo` | Sí | entero COP ≥ 0 (interno) |
| `precio` | Sí | entero COP > 0, múltiplo de 500 (recomendado; aviso si no) |
| `stock` | Sí | entero ≥ 0 |
| `foto`, `foto_uso`, `swatch` | foto y swatch obligatorios (swatch si tiene color o tono) | nombre de archivo en la carpeta de medios importada |
| `registro_sanitario` | No | ≤ 40 caracteres |
| `descripcion_corta`, `como_se_usa`, `ingredientes` | No | texto plano |

**Flujo en el panel** (FR-065, R-09): *Productos → Importar inventario* → subir CSV (+ ZIP de fotos opcional) → validación → vista previa (altas, cambios, diferencias de precio > 20% a confirmar, filas con error) → confirmar → importador propio con la API CRUD de WooCommerce. La misma validación existe por WP-CLI (`wp aralia catalog validate archivo.csv`).

**Validación previa**:
- Rechaza celdas que empiecen por `=`, `+`, `-`, `@` (inyección de fórmulas) salvo números válidos.
- Lista errores por fila; no importa nada si hay errores bloqueantes.
- Muestra diferencias de precio > 20% contra el catálogo actual para confirmar (T-6).
- Las filas válidas sin foto/swatch/stock se importan como **borrador** y aparecen en "Catálogo incompleto" (FR-066).
