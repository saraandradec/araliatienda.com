# Quickstart — Aralía Beauty Store P0 (desarrollo local)

**Requisitos**: Node 20+, Composer 2, PHP 8.3 CLI, Git y Docker Desktop (obligatorio para CI con wp-env; en local también sirve Local by Flywheel). Credenciales **Sandbox** de Wompi (nunca de producción en local).

```bash
cd "Documents/Aralía-web/E-commerce"

# 1. Dependencias
composer install          # solo para pruebas/wp-env: WP core, WooCommerce, Wompi 3.2.1 (no se despliegan)
npm ci

# 2. Secretos de Sandbox (carpeta ignorada por git; wp-env la monta en wp-content/aralia-private/)
mkdir -p .wp-env-private && cp ops/templates/aralia-secrets.sample.php .wp-env-private/aralia-secrets.php
#   → completar ARALIA_WOMPI_*_TEST, TURNSTILE_* de prueba, BREVO_* de prueba

# 3. Entorno
bash ops/docker/build-base.sh   # imagen base de wp-env parcheada (T165); antes de wp-env start
npx wp-env start          # http://localhost:8888 (admin en /wp-admin)
# El entorno de PRUEBAS (puerto 8889, tests-cli) es el que usan PHPUnit, Playwright y el reloj simulado; el de desarrollo es el 8888.
npx wp-env run tests-cli wp aralia setup --sample-catalog   # tablas, configuración de WooCommerce, catálogo de ejemplo
npx wp-env run cli wp aralia setup --sample-catalog         # (opcional) lo mismo en el sitio de desarrollo

# 4. Eventos de Wompi (ver ops/baseline-config.md §4)
#   Los E2E con eventos reales corren en STAGING, con la URL de eventos de Wompi Sandbox apuntando a staging.
#   En local y CI se usan eventos firmados con el secreto de prueba y avance de reloj de Action Scheduler.
#   Wompi no alcanza localhost: usar un túnel (cloudflared) y registrar la URL de eventos de Sandbox,
#   o reproducir eventos firmados:
#   Desde un contenedor de wp-env no se llega a localhost: se indica el contenedor con --base-url (solo local/test).
npx wp-env run tests-cli --env-cwd=wp-content/aralia-repo wp aralia wompi replay tests/fixtures/wompi/approved.json --base-url=http://tests-wordpress --reference=<pedido>-1 --amount=<centavos>
#   --tamper=checksum prueba T-EVT-FORGED; --amount distinto del pedido, T-EVT-AMOUNT (el monto se firma).

# 5. Pruebas
composer lint && composer stan
composer test             # PHPUnit
#   PHPUnit de integración REINSTALA la base de datos de pruebas (quita plugins activos y catálogo): antes de Playwright, repetir
#   npx wp-env run tests-cli wp plugin activate woocommerce aralia-core wompi-portal-de-pagos wordfence && npx wp-env run tests-cli wp aralia setup --sample-catalog
npx playwright test       # E2E 360 px contra el wp-env de pruebas (8889); los eventos reales de Wompi Sandbox corren en staging
npx lhci autorun          # Lighthouse
```

**Recorridos de validación manual (fase 2–3)**
1. Labios → "Agregar" → carrito lateral con Complétala → Pagar → widget Sandbox → aprobado → "Gracias" + correo.
2. Para principiantes → "Agregar todo" → pide tono una vez → quitar una pieza → pagar.
3. Full face → "Full face con <marca>" → Agregar todo; agotar piezas en el panel hasta bajar del mínimo → la marca desaparece.
4. Checkout con "Es un regalo" → hoja de empaque muestra mensaje y destinataria.
5. Neceser con nombre "Valen" color dorado → pedido muestra texto exacto.
6. Rechazo en Sandbox → "Intentar con otro medio" → nueva referencia `<id>-2` (T-PAY-RETRY).
7. Dejar vencer un pedido → "Volver a mi carrito" (T-CART-BACK).

**Staging / producción**: despliegue **local** con `~/.aralia-deploy/bin/deploy.sh staging|production <sha>` (lanzador de confianza, fuera del repo) desde el equipo de Sara (no por GitHub Actions; ver research R-15). Requiere `brew install rsync gh`, `~/.aralia-deploy/deploy.conf` (modo 600) y un CI en verde sobre `main`. Procedimiento completo en `ops/runbooks/deploy.md`.

**Nota Docker (macOS/arm64)**: si `npx wp-env start` falla en `apt-get install git` con `chgrp: invalid group '_ssh'`, ver `ops/inventory.md` (Entorno local).
