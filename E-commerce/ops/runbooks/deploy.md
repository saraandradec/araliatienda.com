# Runbook — Despliegue (research R-15, constitución 1.2.0 propuesta)

**Quién**: solo Sara, desde su equipo. **Por qué local**: GitHub Free no protege environments ni ramas en repos privados; la llave SSH no se guarda en GitHub.

## Qué despliega (y qué no)

Este runbook despliega **solo el código propio**: el plugin `aralia-core`, el tema `aralia` y los mu-plugins `aralia-*`. WordPress, WooCommerce, Wompi, Wordfence y LiteSpeed los instalas y actualizas tú en Hostinger/wp-admin; el script no los toca. Reglas para esas actualizaciones (research R-15): copia previa con UpdraftPlus, prueba en staging, y **el plugin de Wompi con la actualización automática desactivada** (solo se actualiza tras la prueba de contrato).

## Cómo funciona la confianza

`staging`, `production` y `rollback` solo corren desde el **lanzador de confianza** `~/.aralia-deploy/bin/deploy.sh` (fuera del repositorio, permisos 700). **Nunca** ejecutes `ops/deploy.sh` del árbol de trabajo para desplegar: ese archivo puede venir de un commit sin revisar y correría con la llave de producción en tu ssh-agent. El lanzador se niega a arrancar si no está en `bin/` o si otros pueden escribirlo.

## Instalar el lanzador (una vez, tras aprobar el PR `integracion` → `main`)

1. Verifica en GitHub que el PR fue aprobado por la revisora y anota el SHA del commit fusionado en `main`.
2. `mkdir -p ~/.aralia-deploy/bin && chmod 700 ~/.aralia-deploy ~/.aralia-deploy/bin`
3. `git -C <clon> show <sha>:E-commerce/ops/deploy.sh > ~/.aralia-deploy/bin/deploy.sh && chmod 700 ~/.aralia-deploy/bin/deploy.sh`
4. `shasum -a 256 ~/.aralia-deploy/bin/deploy.sh` y registra el valor en el ledger TRACE al firmar la fase.
5. A partir de ahí el lanzador se actualiza solo: tras cada despliegue a producción **verificado**, pasa a ser el `deploy.sh` de ese commit (que la puerta exigió revisado) y lo imprime con su SHA-256.

## Requisitos (una vez)

1. `brew install rsync gh` (el rsync de macOS no sirve), `gh auth login` y `gh auth status`.
2. Llave ed25519 de despliegue en el llavero (`ssh-add --apple-use-keychain`) y alias en `~/.ssh/config` (ver `ops/deploy.conf.sample`).
3. `cp ops/deploy.conf.sample ~/.aralia-deploy/deploy.conf && chmod 600 ~/.aralia-deploy/deploy.conf` y completar rutas y `REPO_DIR`. El script no ejecuta este archivo: solo acepta líneas `CLAVE="valor"` validadas, y exige que `REPO` coincida con el remoto `origin`.
4. Staging creado limpio (`ops/baseline-config.md` H5) y en una **cuenta de Hostinger distinta** de producción.
5. Rama `main` con protección de GitHub (Pro o Team): PR con revisión obligatoria y sin force-push. Si 4 o 5 no se cumplen, el script se niega a desplegar, salvo que descomentes `ALLOW_SHARED_STAGING` / `ALLOW_UNPROTECTED_MAIN` en `deploy.conf` (aceptación de riesgo por escrito: queda avisado en cada despliegue y anotado en el registro).

## Flujo

1. Fusionar el cambio en `main` mediante PR (siempre *squash*) y esperar el CI en verde (el job `release` arma `aralia-<sha>.tar.gz` y su `.sha256`).
2. `git -C <clon> fetch origin`.
3. Staging: `~/.aralia-deploy/bin/deploy.sh staging <sha de 40 caracteres>`. Revisa la simulación (`rsync -n`) y responde `si`. Se registra en `~/.aralia-deploy/staging.log` (**en tu equipo**).
4. Probar en staging (recorridos de `quickstart.md`, pruebas de pago con Wompi Sandbox).
5. Producción: `~/.aralia-deploy/bin/deploy.sh production <sha>`; confirma escribiendo `PRODUCCION <7 primeros caracteres>`.

## Qué comprueba el script antes de copiar

- Se ejecuta el lanzador de confianza; `<sha>` está en `origin/main`; hay un CI en verde para `<sha>` y el SHA-256 del paquete coincide.
- Antes de extraer el paquete: solo archivos y directorios (nada de enlaces ni dispositivos), sin rutas absolutas ni `..`.
- **El paquete es idéntico a git** en aralia-core, el tema y los mu-plugins (incluido el cargador de secretos), sin componentes extra (ningún plugin de terceros), sin nombres raros y sin enlaces simbólicos; `build/` solo admite JS, CSS, JSON, mapas, PNG/JPG, WOFF2 y `*.asset.php` de una línea con la forma exacta de `@wordpress/scripts`, sin dobles extensiones (`x.php.js`) ni SVG. El artefacto del CI se descarga a un directorio aparte (solo se adoptan el `.tar.gz` y su `.sha256`) y los directorios de verificación se crean nuevos.
- El paquete debe traer **todo** el código propio que git tiene en `<sha>` (si faltara un componente, se borraría del servidor).
- Destino sin enlaces simbólicos en `wp-content`, `plugins`, `themes`, `mu-plugins`, `aralia-core`, el tema ni los `aralia-*`: un enlace convertiría el `--delete` de `rsync` en un borrado del sitio.
- Producción no se despliega hasta que exista `wp aralia baseline check` (Fase 2, T032).
- Código propio retirado del repo (mu-plugin renombrado o eliminado, rollback a un SHA anterior a `aralia-core` o al tema) **se borra del servidor**; la simulación lo lista como `*deleting`.
- `ssh` y `rsync` van sin reenvío de agente (`ForwardAgent=no`), sin túneles y sin preguntas interactivas.
- **Terceros**: el servidor se compara con `ops/third-party.lock` del commit **antes de copiar** y otra vez **después** (la huella no puede cambiar): inventario por archivos (carpetas de plugins, temas, mu-plugins y `*.php` sueltos de `wp-content`, sin ejecutar PHP), núcleo (exacto o de la serie `7.1.*`), plugins con versión exacta, WooCommerce, Wompi y Wordfence activos, Wompi con `auto_update` apagado y `verify-checksums` sin avisos. `wp` carga los mu-plugins aunque se use `--skip-plugins` (RR-13). En producción, la huella (`tp=` en el registro) debe ser la misma que staging tuvo para ese SHA. Si Hostinger inyecta algo no listado, el despliegue falla cerrado: añádelo al manifiesto con su justificación en un PR.
- Staging y producción no comparten cuenta ni rutas (se resuelve con `ssh -G`, no por el nombre del alias).
- Producción: `<sha>` ya se desplegó y verificó en staging; `main` tiene protección efectiva.
- Producción: **todo** commit entre el último despliegue verificado y `<sha>` (salvo `specs/` e `ops/inventory.md`; en el primer despliegue, desde `REVIEW_GENESIS`, que debe ser el commit inicial) viene de un PR fusionado en `main` (el commit debe ser **el commit de fusión** del PR, creado y firmado por GitHub: solo *squash* desde la web; un merge local se bloquea) y aprobado, sobre su último commit, por una revisora que (a) figura en la lista **del último despliegue** y sigue en la de `<sha>` (revocar surte efecto), (b) no es la dueña del repositorio ni escribió commits del PR (si algún commit no tiene cuenta de GitHub asociada, se bloquea), y (c) no tiene un cambio solicitado posterior.
- El alta de la primera revisora solo existe en el **primer** despliegue y exige que escribas `ALTA-REVISORA` viendo la lista propuesta.

**Régimen transitorio**: `ops/payment-reviewers.txt` está vacío a propósito; mientras lo esté, el script bloquea producción. El trabajo se fusiona (*squash*) en la rama `integracion`, que no se despliega, y la revisora aprueba un único PR `integracion` → `main` (research R-15).

## Si falla algo en producción: vuelta atrás

1. Activar el modo mantenimiento de pagos (ocultar el medio de pago). El runbook de parcheo (`ops/runbooks/patching.md`) lo detallará cuando exista (T149); mientras tanto: WooCommerce → Ajustes → Pagos → desactivar Wompi.
2. `~/.aralia-deploy/bin/deploy.sh rollback <sha del último bueno>`. Los terceros se comprueban contra el `third-party.lock` del **último despliegue verificado** (lo que debe estar instalado ahora), no contra el del SHA al que vuelves; si hace falta volver también a otra versión de un tercero, restáuralo con la copia de UpdraftPlus antes. Solo admite SHA con despliegue verificado en `~/.aralia-deploy/production.log`; usa el paquete guardado en `~/.aralia-deploy/releases/` (los artefactos del CI caducan a los 90 días), vuelve a comprobar su SHA-256 y que sea idéntico a git, y queda registrado como `rollback`. **No mueve el ancla de confianza**: el siguiente despliegue debe descender del último `verified`.
3. Anotar el incidente y sus causas en el ledger TRACE.

## Riesgos residuales (threat-model RR-8 a RR-14; aceptación de Sara pendiente)

- **RR-8 · Registro y lanzador locales**: quien controle tu usuario del equipo puede falsear `~/.aralia-deploy/*.log` o sustituir el lanzador. Mitigación parcial: permisos 700, el lanzador y su carpeta se rechazan si no son tuyos, son enlaces simbólicos o permiten escribir a otros, y cada despliegue anota `launcher=<sha256>` y los `ALLOW_*` vigentes. La protección de rama (T166) reduce el riesgo.
- **RR-9 · Fusión con cambios extra**: la puerta exige que el commit sea el de fusión del PR y esté firmado por GitHub, lo que descarta un merge local con cambios añadidos. Queda que quien controle tu cuenta de GitHub puede aprobar/fusionar desde la web; la protección de rama (T166) lo reduce.
- **RR-10 · Terceros y JS de `build/`**: WordPress y los plugins de terceros se actualizan en Hostinger sin pasar por este pipeline; `ops/third-party.lock` se comprueba antes y después de cada despliegue, y `composer audit` no cubre wpackagist. Mitigación: Wompi sin actualización automática, copia previa y staging, Wordfence, y las tareas T167 y T168.
- **RR-11 · wp-admin como canal de código y deriva de versiones**: quien tenga una sesión de administradora puede instalar o actualizar plugins por wp-admin/hPanel sin revisión, con los secretos en el mismo proceso PHP. Mitigación: 2FA, lista cerrada y versiones exactas comprobadas en cada despliegue (`ops/third-party.lock`), Wordfence; **no se detecta entre despliegues** hasta que T170 añada la comprobación periódica con alerta. Desactiva también las actualizaciones automáticas en hPanel.
- **RR-12 · Despliegue no atómico**: durante el `rsync`, `aralia-core` y el guard pueden quedar un instante en versiones distintas. Mitigación: desplegar fuera de horas de pago y desactivar el medio de pago mientras dura (modo mantenimiento).
- **RR-13 · Comprobación posterior dentro del código recién desplegado**: `wp aralia baseline check` arranca WordPress con los mu-plugins nuevos. Las comprobaciones de terceros se hacen antes de copiar y sin cargar plugins; el baseline posterior es solo indicativo.
- **RR-14 · Secretos Sandbox en el CI y revisora**: el job `wp` entrega llaves Sandbox a PR de ramas del mismo repo (daño acotado a pruebas); la identidad de la revisora es un login de GitHub: registra su nombre real y evidencia en `ops/compliance/` (PCI 6.2.3 es política, no solo técnica).

## Lo que el script nunca toca

`.htaccess`, `.user.ini`, `wordfence-waf.php`, `.well-known`, `wp-config.php`, `uploads/`, `wflogs`, caché de LiteSpeed, idiomas, `updraft` y UpdraftPlus. UpdraftPlus Premium se instala y actualiza a mano.
