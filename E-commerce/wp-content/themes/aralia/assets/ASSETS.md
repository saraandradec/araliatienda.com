# Activos de terceros del tema (auto-alojados)

Nada se carga desde un CDN en tiempo de ejecución (CSP y R-04). Las huellas son de los archivos tal como están en este repositorio.

| Archivo | Origen y versión | Licencia | SHA-256 |
|---|---|---|---|
| `fonts/cormorant-garamond-latin-300-italic.woff2` | @fontsource/cormorant-garamond 5.0.8 (subconjunto latin) | SIL OFL 1.1 | `1b2b00c0d755ac7c5c4727ac5ea65bac6e4b018696cff6130e1e439f58f369fb` |
| `fonts/cormorant-garamond-latin-500-normal.woff2` | ídem | SIL OFL 1.1 | `991c8aba7707066e8d0f351d53a4eedee3b5f00d1db335d9a2ee39903b4b0c8c` |
| `fonts/cormorant-garamond-latin-500-italic.woff2` | ídem | SIL OFL 1.1 | `66b937da1d31b12fa88c8ca30027b668961bc40421451700fc182f745a3f7a6e` |
| `fonts/cormorant-garamond-latin-300-normal.woff2` | ídem | SIL OFL 1.1 | `719eedba4ef25d38763e12efef4f1f8b8c8f4476ea379806decba7b5c2bd83b8` |
| `fonts/cormorant-garamond-latin-400-italic.woff2` | ídem | SIL OFL 1.1 | `effe3ef07d4e0d249d837344e70bdf50fc94ccc44870b02103dad9a75d4e003f` |
| `fonts/jost-latin-300-normal.woff2` | @fontsource/jost 5.0.8 (subconjunto latin) | SIL OFL 1.1 | `141942fc3c82aa33e8fba9f0d5e509b4f2c45cf6cf12e8598902c8845c4488eb` |
| `fonts/jost-latin-400-normal.woff2` | ídem | SIL OFL 1.1 | `a25da21fa46f9ef2ca9d169f57fbf9f605c976f9dfe924a27706900dc3228a8b` |
| `fonts/jost-latin-500-normal.woff2` | ídem | SIL OFL 1.1 | `c99aaa9d35986f17f4b940fdb18ad3eb19eaa6afca98f0ca60b3477c4bd0f819` |
| `js/gsap.min.js` | gsap 3.12.5 | GSAP Standard License (uso gratuito; sin plugins de pago) | `28033e449a31ebcc396e5be8b13b63152bf03094288fb5867034321927bce087` |
| `js/ScrollTrigger.min.js` | gsap 3.12.5 (ScrollTrigger) | GSAP Standard License | `ad33c2df9ada8a663c2147357828f980d0b7ca731ef33eb3c6e4f327c3b2cda5` |

Las fuentes `latin` no incluyen caracteres fuera del latín básico y el latín-1 (cubre el español). Pesos de Cormorant según §11 del documento de requerimientos (300, 400 cursiva, 500; se incluyen también 300 cursiva y 500 cursiva por el titular fino de la marca). Al actualizar un activo, actualizar esta tabla. **Deuda**: `ops/third-party.lock` y `ops/deploy.sh` aún no verifican estos archivos (T150/T170).
