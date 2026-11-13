# Runbook — Despliegue (research R-15, constitución 1.1.0)

**Quién**: solo Sara, desde su equipo. **Por qué local**: GitHub Free no protege environments ni ramas en repos privados; la llave SSH no se guarda en GitHub.

## Requisitos (una vez)

1. `brew install rsync gh` (el rsync de macOS no sirve), `gh auth login` y `gh auth status` con permiso de lectura de Actions y PR.
2. Llave ed25519 de despliegue en el llavero (`ssh-add --apple-use-keychain`) y alias en `~/.ssh/config` (ver `ops/deploy.conf.sample`).
3. `cp ops/deploy.conf.sample ops/deploy.conf && chmod 600 ops/deploy.conf` y completar las rutas de Hostinger. El script no ejecuta este archivo: solo acepta líneas `CLAVE="valor"` validadas y exige que `REPO` coincida con el remoto `origin`.
4. Staging creado limpio (`ops/baseline-config.md` H5) y en una **cuenta de Hostinger distinta** de producción.
5. Rama `main` con protección de GitHub (Pro o Team). Sin esas dos condiciones el script se niega a desplegar a producción, salvo que descomentes `ALLOW_SHARED_STAGING` / `ALLOW_UNPROTECTED_MAIN` en `deploy.conf` (aceptación de riesgo por escrito; queda avisado en cada despliegue).

## Flujo

1. Fusionar el cambio en `main` mediante PR (siempre *squash*) y esperar el CI en verde (el job `release` arma `aralia-<sha>.tar.gz` y su `.sha256`).
2. `git fetch origin && git checkout main && git pull`.
3. Staging: `ops/deploy.sh staging <sha de 40 caracteres>`. Revisa la simulación (`rsync -n`) y responde `si`. El script verifica checksums de WordPress y plugins y registra el despliegue en `~/.aralia-deploy/staging.log` (**en tu equipo**, no en el servidor).
4. Probar en staging (recorridos de `quickstart.md`, pruebas de pago con Wompi Sandbox).
5. Producción: `ops/deploy.sh production <sha>`; confirma escribiendo `PRODUCCION <7 primeros caracteres>`.
6. **Si el despliegue cambia `ops/deploy.sh`**: producción ejecuta la puerta del *último despliegue*, no la del commit nuevo. El script te dará el comando exacto: `git show <último>:E-commerce/ops/deploy.sh > ops/deploy.last.sh && bash ops/deploy.last.sh production <sha>`. El `deploy.sh` nuevo entra en vigor en el despliegue siguiente.

## Qué comprueba el script antes de copiar

- `<sha>` está en `origin/main`; el script en ejecución es idéntico al de `<sha>` (staging) o al del último despliegue a producción (producción).
- Hay un CI en verde para `<sha>` y el SHA-256 del paquete coincide.
- **El paquete es idéntico a git** en aralia-core, el tema y los mu-plugins (incluido el cargador de secretos), sin componentes extra ni enlaces simbólicos; `build/` solo admite JS, CSS, JSON, mapas, imágenes y `*.asset.php` de una línea.
- Producción: el mismo `<sha>` ya se desplegó y verificó en staging.
- Producción: **todo** commit entre el último despliegue y `<sha>` (salvo `specs/` e `ops/inventory.md`; en el primer despliegue, desde `REVIEW_GENESIS`) viene de un PR fusionado en `main` y aprobado, sobre su último commit, por una revisora que (a) figura en la lista **del último despliegue** y sigue en la lista de `<sha>` (revocar surte efecto), (b) no es la dueña del repositorio ni escribió commits del PR, y (c) no tiene un cambio solicitado posterior.
- El alta de la primera revisora solo existe en el **primer** despliegue y exige que escribas `ALTA-REVISORA` viendo la lista propuesta.
- Staging y producción no comparten cuenta ni rutas (ver Requisitos 4 y 5) y `main` tiene protección.

**Régimen transitorio**: `ops/payment-reviewers.txt` está vacío a propósito; mientras lo esté, el script bloquea producción. Editar el script o la lista no lo evita: ambos cambios necesitan un PR aprobado. El trabajo se fusiona (*squash*) en la rama `integracion`, que no se despliega, y la revisora aprueba un único PR `integracion` → `main` (research R-15).

## Si falla algo en producción: vuelta atrás

1. Activar el modo mantenimiento de pagos (ocultar el medio de pago). El runbook de parcheo (`ops/runbooks/patching.md`) lo detalla cuando exista (tarea T149); mientras tanto: Wompi → desactivar el método de pago en WooCommerce → Ajustes → Pagos.
2. `ops/deploy.sh rollback <sha del último bueno>`. Solo admite SHA que figuren en `~/.aralia-deploy/production.log` y usa el paquete guardado en `~/.aralia-deploy/releases/` (los artefactos del CI caducan a los 90 días), con su SHA-256 verificado. No exige ascendencia ni revisión nueva (el código ya estuvo en producción) y queda registrado como `rollback`.
3. Anotar el incidente y sus causas en el ledger TRACE.

## Riesgos residuales aceptados (el threat model los referencia)

- **Registro local**: cualquier proceso con tu usuario puede editar `~/.aralia-deploy/*.log` y falsear "último despliegue". Mitigación: la protección de rama y el rollback exigen un SHA con paquete y checksum guardados; contraste futuro con una marca en el servidor.
- **Fusión con cambios extra**: un merge local con cambios añadidos hereda la aprobación del PR (git no garantiza que el árbol fusionado sea el aprobado). Mitigación real: protección de rama con "branch up to date" y solo *squash* (Pro/Team).
- **Terceros**: WordPress y los 4 plugins de wpackagist se verifican con `wp ... verify-checksums` *después* de copiar y `composer audit` no cubre sus vulnerabilidades (tarea T167).

## Lo que el script nunca toca

`.htaccess`, `.user.ini`, `wordfence-waf.php`, `.well-known`, `wp-config.php`, `uploads/`, `wflogs`, caché de LiteSpeed, idiomas, `updraft` y UpdraftPlus. UpdraftPlus Premium se instala y actualiza a mano.
