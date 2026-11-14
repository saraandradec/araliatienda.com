<!--
Sync Impact Report
- Version change: 1.0.0 → 1.2.0 (1.1.0 MINOR, 2026-11-13: régimen transitorio de la revisora humana; despliegue local por plan Free de GitHub. 1.2.0 MINOR, 2026-11-13: la revisión humana pasa de rutas del camino de pago a todo el repositorio; lanzador de confianza)
- Enmienda 1.1.0: principio IX y Flujo de desarrollo ajustados (ver sección Enmiendas). Aprobada por Sara Andrade el 2026-11-13.
- Principios: I. Tienda normal, bien hecha · II. Proponer antes que buscar · III. Un toque para un look · IV. Tono solo donde importa · V. Escasez y urgencia solo reales · VI. Cada compra abre la siguiente · VII. Autoservicio · VIII. Reglas de marca · IX. Dinero y datos seguros (T0) · X. Pruebas primero
- Secciones agregadas: Restricciones técnicas · Flujo de desarrollo y gates · Governance
- Secciones eliminadas: ninguna
- Plantillas: ✅ plan-template.md (Constitution Check genérico, compatible) · ✅ spec-template.md (sin cambios) · ✅ tasks-template.md (pruebas obligatorias por X) · ✅ specs/001-aralia-store-p0/plan.md (la constitución provisional C1–C8 + T0-1…T0-5 coincide con I–X)
- Estado: v1.0.0 RATIFICADA y v1.1.0 aprobada por Sara Andrade el 2026-11-13. **v1.2.0 PROPUESTA tras los gates T0: pendiente de aprobación explícita de Sara y registro en el ledger.**
-->

# Aralía Beauty Store Constitution

Constitución del e-commerce de Aralía (`E-commerce/`). Fuente: principios 1.2 y reglas de marca 1.3 del documento `aralia-ecommerce-requerimientos.md`, más las reglas del tier TRACE T0 (`.trace/tier.json`).

## Core Principles

### I. Tienda normal, bien hecha
Menú claro, categorías, filtros, ficha, carrito y pago sin sorpresas. Se usan los componentes nativos de WordPress y WooCommerce; el código propio se escribe solo donde no existe uno nativo, y cada excepción se justifica en `plan.md` (Complexity Tracking).

### II. Proponer antes que buscar
La home y las secciones MUST mostrar looks y sets completos alimentados por el catálogo, sin editar código por cada cambio de contenido.

### III. Un toque para un look completo
Todo set y todo Full face MUST poder llevarse al carrito con "Agregar todo", y la clienta MUST poder quitar o cambiar piezas sin romper el grupo.

### IV. Tono solo donde importa
El tono MUST pedirse solo en piezas que dependen del tono (Piel y lápices de Cejas) y MUST NOT pedirse en Labios, Ojos ni Pestañas. Una pieza que depende del tono MUST NOT entrar al carrito sin tono. El servidor lo valida; la interfaz no es suficiente.

### V. Escasez y urgencia solo si son reales
Ningún contador, cupo, "Últimas unidades", "Más vendido" ni precio tachado MUST mostrarse sin datos reales de inventario, ventas o historial de precio.

### VI. Cada compra abre la siguiente
"Complétala", "Combina con" y los avisos de disponibilidad MUST salir del catálogo (por look y color), nunca al azar.

### VII. Autoservicio
La clienta MUST poder comprar sin escribir a nadie. El contacto humano se ofrece solo para pedidos pagados con problema.

### VIII. Reglas de marca (obligatorias en todo texto público)
Todo texto público MUST NOT usar "colombiano/a" para describir la tienda ni los productos, MUST NOT mencionar la cantidad de marcas, llama "sets" a los conjuntos, usa un tono premium, cálido y directo, sin signos de exclamación repetidos ni lenguaje de remate. Una prueba automática de contenido y una revisión manual lo verifican antes de cada apertura o lanzamiento.

### IX. Dinero y datos seguros (T0, NO NEGOCIABLE)
- Ningún dato de tarjeta o de cuenta bancaria MUST tocar el DOM ni el servidor de la tienda: los datos de pago viven solo en el componente alojado por la pasarela (SAQ A).
- Un pedido MUST pasar a pagado solo con verificación en el servidor: checksum del evento, consulta a la API de la pasarela y coincidencia de monto, moneda y referencia, con idempotencia y una máquina de estados que no retrocede.
- Llaves y secretos MUST NOT estar en el navegador, la base de datos ni el repositorio.
- Los datos personales se tratan según la Ley 1581: consentimiento probado, minimización, cifrado, purgas y derechos de la titular.
- Todo cambio que llegue a producción (no solo el camino de pago: en WordPress todo el PHP corre en el mismo proceso) MUST venir de un PR aprobado por la revisora técnica humana nombrada en `ops/payment-reviewers.txt`, con las excepciones de documentación que fija `ops/deploy.sh` (`specs/` e `ops/inventory.md`). **Régimen transitorio (1.1.0)**: hasta contratar a la revisora, el trabajo se fusiona en la rama `integracion` (no se despliega) y ella aprueba un único PR `integracion` → `main`; mientras la lista esté vacía, producción queda bloqueada. Con el plan Free de GitHub, CODEOWNERS no bloquea merges: lo hace cumplir `ops/deploy.sh`, que solo corre desde un lanzador de confianza fuera del repositorio.

### X. Pruebas primero
Las pruebas de cada tarea se escriben antes que el código y deben fallar antes de implementar. En el camino de pago y la seguridad, las pruebas MUST incluir casos de abuso (entrada maliciosa, actor no autorizado, fallo de una dependencia externa), no solo el caso feliz. Ningún resultado de prueba se da por bueno sin haberse ejecutado.

## Restricciones técnicas

- Plataforma: WordPress + WooCommerce en Hostinger; pasarela Wompi con el plugin oficial en versión fija + `aralia-wompi-guard`. Cambiar cualquiera de estas piezas es un cambio de alcance y exige reclasificar con `trace-classify`.
- Terceros (WordPress, WooCommerce, Wompi, Wordfence, LiteSpeed): los instala y actualiza Hostinger/wp-admin; la versión probada se registra en `ops/inventory.md` y un plugin nuevo requiere justificación allí. El plugin de Wompi MUST tener la actualización automática desactivada y solo se actualiza tras pasar la prueba de contrato. Composer se usa únicamente para pruebas y wp-env.
- Móvil primero (360 px con una mano), AA de accesibilidad y LCP < 2,5 s en móvil 4G.
- Sin etiquetas de terceros en checkout, order-pay ni Gracias, salvo las excepciones aprobadas en el spec.
- Animaciones con GSAP auto-alojado y respeto a `prefers-reduced-motion`.

## Flujo de desarrollo y gates

- Planificación con `logiplan` (spec → plan → tareas, cada una aprobada por `logicraft-trace:architect`) e implementación con `logidev` por fases.
- Gates T0 por fase: architect, security-reviewer, database-reviewer (si hay datos), code-reviewer, pruebas (unitarias, integración y E2E con casos de abuso), verificación adversarial (3 corridas) de los hallazgos de seguridad y **sign-off humano nombrado** de Sara antes de cerrar la fase.
- Ramas por feature; producción solo con aprobación manual de Sara tras pasar en staging.
- Toda decisión de gate se registra en `.trace/ledger.jsonl`.

## Governance

Esta constitución prevalece sobre cualquier otra práctica del proyecto. Una enmienda requiere: propuesta escrita con su motivo, aprobación de Sara Andrade, actualización de los artefactos que dependan de ella (plan, plantillas, tareas) y registro en el ledger. Versionado semántico: MAJOR si se elimina o redefine un principio, MINOR si se agrega uno o se amplía materialmente, PATCH para aclaraciones. Cada revisión de `logidev` verifica el cumplimiento; toda complejidad adicional se justifica en `plan.md`.

### Enmiendas

- **1.2.0 (2026-11-13, MINOR — ampliación material; PROPUESTA, pendiente de aprobación de Sara y de registro en el ledger)** — Tras los gates T0 de la Fase 1: (1) el alcance de la revisión humana pasa de una lista de rutas a **todo el repositorio** salvo `specs/` e `ops/inventory.md`; (2) `ops/deploy.sh` solo se ejecuta desde un lanzador de confianza fuera del repo, que se actualiza tras cada despliegue a producción verificado; (3) la lista de revisoras se lee del último despliegue y debe seguir vigente en el commit a desplegar; (4) el alta inicial de la revisora solo existe en el primer despliegue (`ALTA-REVISORA`); (5) régimen transitorio operativo con la rama `integracion`; (6) condiciones de la primera salida (T166): staging en cuenta separada y protección de rama de GitHub, que el script exige salvo aceptación escrita (`ALLOW_*`, registrada en el despliegue); (7) riesgos residuales RR-8 a RR-10 del threat model; (8) **el despliegue lleva solo código propio** (aralia-core, tema, mu-plugins `aralia-*`): WordPress y los plugins de terceros se instalan y actualizan en Hostinger, no se fijan ni se empaquetan desde el repositorio (cambia la regla de «terceros por Composer con versión fija» de Restricciones técnicas).

- **1.1.0 (2026-11-13, Sara Andrade)** — *Motivo*: el repositorio privado está en el plan Free de GitHub, sin environments protegidos ni protección de rama, y la revisora técnica aún no está contratada. *Cambios*: (1) el despliegue se hace con `ops/deploy.sh` desde el equipo de Sara (la llave SSH no vive en GitHub) y exige `main`, CI verde, checksum, staging previo y un PR aprobado por alguien de `ops/payment-reviewers.txt` distinto de la autora; (2) régimen transitorio de la revisora descrito en el principio IX. Artefactos actualizados: research R-15 y R-16, plan.md, tasks.md, quickstart.md, `ops/`.

**Version**: 1.2.0 (propuesta; 1.1.0 vigente hasta su aprobación) | **Ratified**: 2026-11-13 (Sara Andrade) | **Last Amended**: 2026-11-13
