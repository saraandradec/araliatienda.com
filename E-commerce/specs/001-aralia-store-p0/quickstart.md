# Quickstart — Aralía Beauty Store P0 (desarrollo local)

**Requisitos**: Node 20+, Composer 2, PHP 8.3 CLI, Git y Docker Desktop (obligatorio para CI con wp-env; en local también sirve Local by Flywheel). Credenciales **Sandbox** de Wompi (nunca de producción en local).

```bash
cd "Documents/Aralía-web/E-commerce"

# 1. Dependencias
composer install          # WP core, WooCommerce, Wompi 3.2.1, plugins fijados
npm ci

# 2. Secretos de Sandbox (archivo ignorado por git)
cp ops/templates/aralia-secrets.sample.php .wp-env/aralia-secrets.php
#   → completar ARALIA_WOMPI_*_TEST, TURNSTILE_* de prueba, BREVO_* de prueba

# 3. Entorno
npx wp-env start          # http://localhost:8888 (admin en /wp-admin)
npx wp-env run cli wp aralia setup --sample-catalog   # taxonomías, atributos, catálogo de ejemplo

# 4. Eventos de Wompi en local
#   Wompi no alcanza localhost: usar un túnel (cloudflared) y registrar la URL de eventos de Sandbox,
#   o reproducir eventos firmados:
npx wp-env run cli wp aralia wompi replay tests/fixtures/wompi/approved.json

# 5. Pruebas
composer lint && composer stan
composer test             # PHPUnit
npx playwright test       # E2E 360 px contra wp-env + Wompi Sandbox
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

**Staging / producción**: despliegue por GitHub Actions (`deploy.yml`); producción requiere aprobación de Sara. Ver `ops/runbooks/`.
