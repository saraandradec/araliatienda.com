# Runbook — Despliegue (research R-15, constitución 1.1.0)

**Quién**: solo Sara, desde su equipo. **Por qué local**: GitHub Free no protege environments ni ramas en repos privados; la llave SSH no se guarda en GitHub.

## Requisitos (una vez)

1. `brew install rsync gh` (el rsync de macOS no sirve) y `gh auth login`.
2. Llave ed25519 de despliegue en el llavero (`ssh-add --apple-use-keychain`) y alias en `~/.ssh/config` (ver `ops/deploy.conf.sample`).
3. `cp ops/deploy.conf.sample ops/deploy.conf` y completar rutas de Hostinger (el archivo está ignorado por git).
4. Staging creado limpio (`ops/baseline-config.md` H5).

## Flujo

1. Fusionar el cambio en `main` mediante PR y esperar el CI en verde (el job `release` arma `aralia-<sha>.tar.gz` y su `.sha256`).
2. `git fetch origin && git checkout main && git pull`.
3. Staging: `ops/deploy.sh staging <sha de 40 caracteres>`. Revisar la simulación (`rsync -n`) y responder `si`. El script verifica checksums de WordPress y plugins y registra el despliegue en `~/.aralia-deploy/staging.log` (del servidor).
4. Probar en staging (recorridos de `quickstart.md`, pruebas de pago con Wompi Sandbox).
5. Producción: `ops/deploy.sh production <sha>`; confirmar escribiendo `PRODUCCION <7 primeros caracteres>`.

## Qué comprueba el script antes de copiar

- `<sha>` está en `origin/main` y el script local es idéntico al de ese commit.
- Hay un CI en verde para `<sha>` y el SHA-256 del paquete coincide.
- Producción: el mismo `<sha>` ya se desplegó y verificó en staging.
- Producción: **todo** commit desde el último despliegue (salvo `specs/`, `ops/runbooks/`, `ops/compliance/`, `ops/inventory.md`) viene de un PR fusionado en `main` y aprobado, sobre su último commit y sin cambios solicitados posteriores, por alguien de la lista de revisoras **del último despliegue** (no del commit a desplegar), distinta de la autora.
- El alta de la primera revisora exige que escribas `ALTA-REVISORA` viendo la lista propuesta.
- El paquete se compara con `git archive <sha>` (aralia-core, tema, mu-plugins `aralia-*`): un workflow alterado no puede colar código.
- El registro de despliegues está en tu equipo (`~/.aralia-deploy/*.log`), no en el servidor.

**Régimen transitorio**: `ops/payment-reviewers.txt` está vacío a propósito; mientras lo esté, el script bloquea producción para cualquier cambio. Editar el script o la lista no lo evita: ambos cambios necesitan un PR aprobado por alguien de la lista del último despliegue. El trabajo se fusiona en la rama `integracion` (no se despliega) y la revisora aprueba un único PR `integracion` → `main` (research R-15). Fusionar siempre con *squash*.

## Si la verificación falla en producción

1. Activar el modo mantenimiento de pagos (ocultar el medio de pago; `ops/runbooks/patching.md`).
2. Volver a desplegar el último `<sha>` bueno. Los artefactos del CI caducan a los 90 días: por eso cada despliegue a producción guarda el paquete verificado en `~/.aralia-deploy/releases/`.
3. Anotar el incidente y sus causas en el ledger TRACE.

## Lo que el script nunca toca

`.htaccess`, `.user.ini`, `wordfence-waf.php`, `.well-known`, `wp-config.php`, `uploads/`, `wflogs`, caché de LiteSpeed, idiomas, `updraft` y UpdraftPlus. UpdraftPlus Premium se instala y actualiza a mano.
