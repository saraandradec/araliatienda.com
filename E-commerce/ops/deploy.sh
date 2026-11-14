#!/usr/bin/env bash
# Despliegue de Aralía (research R-15, constitución 1.2.0).
#
#   ops/deploy.sh package    <sha>   # lo usa el CI: arma release/aralia-<sha>.tar.gz (+ .sha256)
#   ops/deploy.sh staging    <sha>   # Sara, desde su equipo
#   ops/deploy.sh production <sha>   # Sara, desde su equipo
#   ops/deploy.sh rollback   <sha>   # vuelta atrás a un SHA ya desplegado antes en producción (paquete guardado local)
#
# staging, production y rollback SOLO se ejecutan desde el lanzador de confianza ~/.aralia-deploy/bin/deploy.sh
# (fuera del repositorio, permisos 700). Si corrieran desde el árbol de trabajo, un commit sin revisar podría
# reescribir las comprobaciones que lo revisan, con la llave de producción en el ssh-agent. El lanzador se instala a
# mano la primera vez desde el commit aprobado (ops/runbooks/deploy.md) y se actualiza solo tras un despliegue a
# producción verificado, con el deploy.sh de ese commit (que la propia puerta exigió revisado). `package` (CI) no lo necesita.
#
# Por qué local y no en GitHub Actions: con el plan Free en un repo privado no hay environments protegidos
# ni protección de rama, así que una llave SSH guardada en GitHub quedaría al alcance de cualquier rama.
# La llave vive solo en el equipo de Sara (ssh-agent) y la "aprobación manual" es que ella ejecute esto.
#
# Antes de copiar un solo archivo, el script exige:
#   1. que <sha> esté en origin/main y que se ejecute el lanzador de confianza (ver arriba);
#   2. CI en verde para <sha> (push a main) y el paquete de ese run con su SHA-256 correcto;
#   3. producción: que <sha> ya se haya desplegado y verificado en staging;
#   4. producción: que TODO commit desde el último despliegue (salvo documentación exenta, ver REVIEW_EXEMPT)
#      venga de un PR fusionado en main y aprobado, sobre su último commit, por alguien de la lista de revisoras
#      DISTINTO de la autora. La lista se lee del último despliegue a producción (no del commit a desplegar);
#      el alta de la primera revisora exige una confirmación escrita de Sara (ALTA-REVISORA);
#   5. los terceros del servidor (núcleo, plugins, mu-plugins, drop-ins y versiones) coinciden con ops/third-party.lock
#      del commit, y en producción con los que se probaron en staging para ese SHA;
#   6. una simulación (rsync -n) que Sara revisa y confirma.
# Solo despliega CÓDIGO PROPIO: el plugin aralia-core, el tema aralia y los mu-plugins aralia-*, cada uno con --delete
# acotado a sí mismo. WordPress, WooCommerce, Wompi, Wordfence y LiteSpeed los instala y actualiza Hostinger/wp-admin;
# nunca se tocan, ni .htaccess, .user.ini, wordfence-waf.php, .well-known, wp-config.php, uploads, wflogs,
# caché de LiteSpeed, idiomas, updraft ni UpdraftPlus.
#
# Configuración (no secreta, fuera del repositorio, modo 600): ~/.aralia-deploy/deploy.conf, a partir de ops/deploy.conf.sample.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
SELF="$SCRIPT_DIR/$(basename "${BASH_SOURCE[0]}")"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd -P)"     # E-commerce/
PROJECT_REL="E-commerce"
REPO_ROOT="$(cd "$PROJECT_DIR/.." && pwd -P)"
# Compatible con el bash 3.2 de macOS (sin mapfile ni arrays asociativos).

# Plugins de terceros que se instalan y actualizan en Hostinger (lista cerrada, R-15). Este script NO los despliega ni
# los toca: solo comprueba su integridad en el servidor tras desplegar. UpdraftPlus Premium se instala a mano.
THIRD_PARTY_PLUGINS=(woocommerce wompi-portal-de-pagos wordfence litespeed-cache)

# Revisión obligatoria para TODO el repo salvo documentación que no se despliega ni controla el despliegue.
# (Criterio invertido tras la revisión T0 del 2026-11-13: una lista de "rutas de pago" se desalineaba de CODEOWNERS
# y en WordPress todo el PHP corre en el mismo proceso. Lo nuevo queda protegido por defecto.)
# Los runbooks y ops/compliance NO están exentos: contienen las instrucciones y condiciones de la propia puerta.
REVIEW_EXEMPT=(
	":(exclude)$PROJECT_REL/specs"
	":(exclude)$PROJECT_REL/ops/inventory.md"
)

# Primer commit que entra en la revisión: lo anterior ya estaba publicado en main sin PR (landing "coming soon",
# nada de lo que se despliega) y GitHub no permite aprobar a posteriori. Cambiarlo requiere PR aprobado como todo.
REVIEW_GENESIS="e982219344a1a620f0efbc731c5b926f011e4092"

# Registro de despliegues: EN EL EQUIPO DE SARA, no en el servidor (código desplegado en staging o producción
# podría escribir en un registro del servidor y falsear "último despliegue").
LOCAL_LOG_DIR="$HOME/.aralia-deploy"
TRUSTED_DIR="$LOCAL_LOG_DIR/bin"

die()  { printf '✖ %s\n' "$*" >&2; exit 1; }
info() { printf '• %s\n' "$*"; }

sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

valid_sha() { [[ "${1:-}" =~ ^[0-9a-f]{40}$ ]]; }

# Modo octal de un archivo. Primero la sintaxis de GNU: `stat -f` en Linux imprime el estado del sistema de archivos y sale con 0.
file_mode() { stat -c %a "$1" 2>/dev/null || stat -f %Lp "$1"; }

# El rsync de macOS (openrsync) no soporta --chmod: hace falta rsync 3 (brew install rsync).
require_rsync3() {
	local v
	v="$(rsync --version 2>/dev/null || true)"   # sin tubería: con pipefail, head cortaría rsync (SIGPIPE)
	[[ "${v%%$'\n'*}" =~ rsync\ +version\ +3\. ]] \
		|| die "Hace falta rsync 3.x (en macOS: brew install rsync y abrir una terminal nueva)"
}

# ---------------------------------------------------------------------------------------------- package

package_release() {
	local sha="$1" out="$PROJECT_DIR/release" stage m
	valid_sha "$sha" || die "SHA inválido"

	stage="$(mktemp -d)"
	mkdir -p "$stage/wp-content/plugins" "$stage/wp-content/themes" "$stage/wp-content/mu-plugins" "$out"

	# Solo código propio. El núcleo de WordPress y los plugins de terceros los instala y actualiza Hostinger.
	if [ -f "$PROJECT_DIR/wp-content/plugins/aralia-core/aralia-core.php" ]; then
		cp -R "$PROJECT_DIR/wp-content/plugins/aralia-core" "$stage/wp-content/plugins/aralia-core"
		rm -rf "$stage/wp-content/plugins/aralia-core/blocks" "$stage/wp-content/plugins/aralia-core/node_modules"
	fi
	if [ -f "$PROJECT_DIR/wp-content/themes/aralia/style.css" ]; then
		cp -R "$PROJECT_DIR/wp-content/themes/aralia" "$stage/wp-content/themes/aralia"
	fi
	for m in "$PROJECT_DIR"/wp-content/mu-plugins/aralia-*; do
		[ -e "$m" ] || continue
		case "$(basename "$m")" in
			aralia-secrets-loader.php) ;;
			aralia-secrets*) die "Archivo de secretos dentro de mu-plugins: $(basename "$m")" ;;
		esac
		cp -R "$m" "$stage/wp-content/mu-plugins/"
	done
	find "$stage" -name '.gitkeep' -delete
	[ -n "$(find "$stage/wp-content" -type f)" ] || die "No hay código propio que empaquetar"

	{
		echo "sha=$sha"
		echo "built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
		echo "scope=own-code"
	} > "$stage/MANIFEST"

	tar -czf "$out/aralia-$sha.tar.gz" -C "$stage" .
	( cd "$out" && printf '%s  %s\n' "$(sha256_of "aralia-$sha.tar.gz")" "aralia-$sha.tar.gz" > "aralia-$sha.tar.gz.sha256" )
	rm -rf "$stage"
	info "Paquete: release/aralia-$sha.tar.gz"
}

# ------------------------------------------------------------------------------------------------- sync

# Ejecuta un comando en el destino ("" = local, para las pruebas).
# ssh nunca reenvía el agente (el código de staging, sin revisión de producción, no debe poder usar la llave de producción).
SSH_OPTS="-o StrictHostKeyChecking=yes -o ForwardAgent=no -o ClearAllForwardings=yes -o BatchMode=yes"
on_target() {
	local host="$1"; shift
	# shellcheck disable=SC2086  # SSH_OPTS son opciones separadas a propósito
	if [ -z "$host" ]; then bash -c "$*"; else ssh $SSH_OPTS "$host" "$*"; fi
}

# Copia el paquete extraído ($1) a la raíz de WordPress del destino. $2 host ("" local), $3 ruta, $4 "-n" para simular.
sync_release() {
	local src="$1" host="$2" path="$3" dry="${4:-}" dest d n links
	if [ -z "$path" ] || [ "$path" = "/" ]; then die "Ruta de destino vacía o /"; fi
	[ -d "$src/wp-content" ] || die "Paquete sin wp-content/"
	if [ -z "$host" ]; then dest="$path"; else dest="$host:$path"; fi
	require_rsync3

	on_target "$host" "test -f '$path/wp-config.php'" || die "No hay wp-config.php en $path (¿ruta equivocada?)"
	# Un enlace simbólico en el destino (p. ej. plugins/aralia-core -> ../..) convertiría el --delete en un borrado de todo el sitio.
	links="$(on_target "$host" "cd '$path' && for p in wp-content wp-content/plugins wp-content/themes wp-content/mu-plugins wp-content/plugins/aralia-core wp-content/themes/aralia; do [ -L \"\$p\" ] && echo \"\$p\"; done; find wp-content/mu-plugins -maxdepth 1 -name 'aralia-*' -type l 2>/dev/null; true")" || return 1
	[ -z "$links" ] || die "Enlaces simbólicos en el destino (no se copia nada): $(echo "$links" | tr '\n' ' ')"
	on_target "$host" "mkdir -p '$path/wp-content/plugins' '$path/wp-content/themes' '$path/wp-content/mu-plugins'" || return 1

	# --checksum: compara contenido, no tamaño+hora (un archivo con igual tamaño y hora no se saltaría).
	local -a R=(rsync -rlptz --checksum --chmod='D755,F644' --itemize-changes)
	[ "$dry" = "-n" ] && R+=(-n)
	export RSYNC_RSH="ssh $SSH_OPTS"

	# Solo código propio, cada pieza con --delete acotado a sí misma. Cada orden termina en "|| return 1":
	# dentro de una función llamada con "|| die", bash ignora set -e.
	for d in "$src"/wp-content/plugins/*/ "$src"/wp-content/themes/*/; do
		[ -d "$d" ] || continue
		n="${d#"$src"/}"
		"${R[@]}" --delete "$d" "$dest/$n" || return 1
	done
	for d in "$src"/wp-content/mu-plugins/aralia-*; do
		[ -e "$d" ] || continue
		n="wp-content/mu-plugins/$(basename "$d")"
		if [ -d "$d" ]; then "${R[@]}" --delete "$d/" "$dest/$n/" || return 1; else "${R[@]}" "$d" "$dest/$n" || return 1; fi
	done
	remove_orphans "$src" "$host" "$path" "$dry" || return 1
	return 0
}

# Código propio que ya no viaja en el paquete (mu-plugin renombrado o retirado, rollback a un SHA anterior al componente)
# se borra del destino: un mu-plugin viejo seguiría ejecutándose. Solo nombres aralia-* validados; con -n solo se lista.
remove_orphans() {
	local src="$1" host="$2" path="$3" dry="${4:-}" listing name rel
	listing="$(on_target "$host" "ls -1 '$path/wp-content/mu-plugins' 2>/dev/null | grep '^aralia-' || true")" || return 1
	while IFS= read -r name; do
		[ -n "$name" ] || continue
		[[ "$name" =~ ^aralia-[A-Za-z0-9._-]+$ ]] || die "Nombre no permitido en mu-plugins del servidor: $name"
		[ -e "$src/wp-content/mu-plugins/$name" ] && continue
		orphan_delete "$host" "$path" "wp-content/mu-plugins/$name" "$dry" || return 1
	done <<<"$listing"
	for rel in wp-content/plugins/aralia-core wp-content/themes/aralia; do
		[ -e "$src/$rel" ] && continue
		on_target "$host" "test -e '$path/$rel'" 2>/dev/null || continue
		orphan_delete "$host" "$path" "$rel" "$dry" || return 1
	done
	return 0
}

orphan_delete() {
	local host="$1" path="$2" rel="$3" dry="$4"
	if [ "$dry" = "-n" ]; then printf '*deleting   %s (huérfano: ya no está en el paquete)\n' "$rel"; return 0; fi
	on_target "$host" "rm -rf '$path/$rel'" || return 1
}

# ---------------------------------------------------------------------------------------------- deploy

REPO=""; REPO_DIR=""; STAGING_SSH=""; STAGING_PATH=""; PRODUCTION_SSH=""; PRODUCTION_PATH=""
ALLOW_SHARED_STAGING=""; ALLOW_UNPROTECTED_MAIN=""

# Lee KEY="valor" sin ejecutar nada (deploy.conf no es código) y valida cada valor.
parse_conf() {
	local conf="$1" line key val
	[ -f "$conf" ] || die "Falta $conf (copiar de ops/deploy.conf.sample)"
	while IFS= read -r line || [ -n "$line" ]; do
		line="${line%$'\r'}"
		case "$line" in ''|'#'*) continue ;; esac
		[[ "$line" =~ ^([A-Z_]+)=\"([^\"]*)\"$ ]] || die "deploy.conf: línea no válida (solo CLAVE=\"valor\", sin espacios finales ni CRLF): $line"
		key="${BASH_REMATCH[1]}"; val="${BASH_REMATCH[2]}"
		case "$key" in
			REPO)                         [[ "$val" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || die "deploy.conf: REPO inválido" ;;
			STAGING_SSH|PRODUCTION_SSH)   [[ "$val" =~ ^[A-Za-z0-9._@][A-Za-z0-9._@-]*$ ]] || die "deploy.conf: $key inválido" ;;
			STAGING_PATH|PRODUCTION_PATH)
				[[ "$val" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "deploy.conf: $key inválido (solo letras, números . _ - /)"
				case "$val" in *..*|*//*|*/./*|*/.) die "deploy.conf: $key no puede contener .., // ni /./" ;; esac ;;
			REPO_DIR)
				case "$val" in /*) ;; *) die "deploy.conf: REPO_DIR debe ser una ruta absoluta" ;; esac
				case "$val" in *[\"\'\`\$\\]*|*..*) die "deploy.conf: REPO_DIR con caracteres no permitidos" ;; esac ;;
			ALLOW_SHARED_STAGING|ALLOW_UNPROTECTED_MAIN) [ "$val" = yes ] || die "deploy.conf: $key solo admite \"yes\"" ;;
			*) die "deploy.conf: clave desconocida $key" ;;
		esac
		printf -v "$key" '%s' "$val"
	done < "$conf"
	[ -n "$REPO" ] && [ -n "$REPO_DIR" ] && [ -n "$STAGING_SSH" ] && [ -n "$STAGING_PATH" ] && [ -n "$PRODUCTION_SSH" ] && [ -n "$PRODUCTION_PATH" ] \
		|| die "deploy.conf: faltan claves (REPO, REPO_DIR, STAGING_SSH, STAGING_PATH, PRODUCTION_SSH, PRODUCTION_PATH)"
	return 0
}

load_conf() {
	local conf="$LOCAL_LOG_DIR/deploy.conf" mode origin top
	parse_conf "$conf"
	mode="$(file_mode "$conf")"
	[ "$mode" = 600 ] || die "$conf debe tener permisos 600 (chmod 600); tiene $mode"
	# El clon se toma de la configuración (fuera del repositorio), no de la ubicación del script.
	top="$(git -C "$REPO_DIR" rev-parse --show-toplevel 2>/dev/null)" || die "REPO_DIR ($REPO_DIR) no es un repositorio git"
	[ "$top" = "$(cd "$REPO_DIR" && pwd -P)" ] || die "REPO_DIR debe ser la raíz del repositorio"
	[ -d "$top/$PROJECT_REL/ops" ] || die "REPO_DIR no contiene $PROJECT_REL/ops"
	REPO_ROOT="$top"; PROJECT_DIR="$top/$PROJECT_REL"
	origin="$(git -C "$REPO_ROOT" remote get-url origin)"
	case "$origin" in *"github.com/$REPO"|*"github.com/$REPO.git"|*"github.com:$REPO"|*"github.com:$REPO.git") ;; *) die "REPO ($REPO) no coincide con el remoto origin ($origin)" ;; esac
	return 0
}

# Identidad real del destino SSH (usuario y host tras resolver ~/.ssh/config), para detectar alias que apuntan a la misma cuenta.
ssh_identity() { ssh -G "$1" 2>/dev/null | awk '$1=="user"||$1=="hostname"{printf "%s ", $2}'; }

# Condiciones de la primera salida a producción (decisiones de Sara; ver ops/compliance/accounts-2fa.md).
# Staging y producción no pueden compartir cuenta ni solaparse (el código de staging se despliega sin la revisión de producción).
check_destinations() {
	if [ "$STAGING_SSH" = "$PRODUCTION_SSH" ] || [ "$(ssh_identity "$STAGING_SSH")" = "$(ssh_identity "$PRODUCTION_SSH")" ]; then
		if [ "$STAGING_PATH" = "$PRODUCTION_PATH" ] || [[ "$STAGING_PATH/" == "$PRODUCTION_PATH/"* ]] || [[ "$PRODUCTION_PATH/" == "$STAGING_PATH/"* ]]; then
			die "STAGING_PATH y PRODUCTION_PATH son iguales o están anidados en la misma cuenta: staging podría pisar producción"
		fi
		[ "$ALLOW_SHARED_STAGING" = yes ] \
			|| die "Staging y producción usan la misma cuenta SSH: el código de staging (sin revisión) podría leer los secretos de producción. Separa las cuentas (ops/compliance/accounts-2fa.md) o, bajo tu responsabilidad, ALLOW_SHARED_STAGING=\"yes\" en deploy.conf"
		info "AVISO: ALLOW_SHARED_STAGING=yes (staging comparte cuenta con producción)"
	fi
	return 0
}

# Condiciones de la primera salida a producción (decisiones de Sara; ver ops/compliance/accounts-2fa.md).
check_production_prereqs() {
	local prot
	gh auth status >/dev/null 2>&1 || die "gh no tiene sesión (gh auth login)"
	prot="$(gh api "repos/$REPO/branches/main/protection" --jq '((.required_pull_request_reviews.required_approving_review_count >= 1) and (.enforce_admins.enabled == true) and (.allow_force_pushes.enabled == false))' 2>/dev/null || true)"
	if [ "$prot" != true ]; then
		[ "$ALLOW_UNPROTECTED_MAIN" = yes ] \
			|| die "La rama main no exige PR con revisión ni bloquea el force-push (GitHub Pro/Team). Actívalo o, bajo tu responsabilidad, ALLOW_UNPROTECTED_MAIN=\"yes\" en deploy.conf"
		info "AVISO: ALLOW_UNPROTECTED_MAIN=yes (main sin protección de rama efectiva)"
	fi
	return 0
}

# Solo se ejecuta el lanzador de confianza, fuera del repositorio y sin permisos de escritura para otros.
require_trusted_launcher() {
	local mode
	[ "$SCRIPT_DIR" = "$(cd "$TRUSTED_DIR" 2>/dev/null && pwd -P)" ] \
		|| die "Ejecuta el lanzador de confianza $TRUSTED_DIR/deploy.sh, no el script del árbol de trabajo (ops/runbooks/deploy.md, «Instalar el lanzador»)"
	mode="$(file_mode "$SELF")"
	[ $((8#$mode & 8#022)) -eq 0 ] || die "El lanzador no debe tener permisos de escritura para grupo u otros (chmod 700 $SELF)"
	[ ! -L "$SELF" ] && [ ! -L "$TRUSTED_DIR" ] || die "El lanzador y su directorio no pueden ser enlaces simbólicos"
	[ -O "$SELF" ] && [ -O "$TRUSTED_DIR" ] || die "El lanzador y su directorio deben pertenecer a tu usuario"
	mode="$(file_mode "$TRUSTED_DIR")"
	[ $((8#$mode & 8#022)) -eq 0 ] || die "$TRUSTED_DIR no debe tener permisos de escritura para grupo u otros (chmod 700)"
	return 0
}

# Tras un despliegue a producción verificado, el lanzador pasa a ser el deploy.sh de ese commit (que la puerta exigió revisado).
install_launcher() {
	local sha="$1" tmpf
	mkdir -p "$TRUSTED_DIR" && chmod 700 "$LOCAL_LOG_DIR" "$TRUSTED_DIR"
	tmpf="$(mktemp "$TRUSTED_DIR/.deploy.XXXXXX")"
	git -C "$REPO_ROOT" show "$sha:$PROJECT_REL/ops/deploy.sh" > "$tmpf" || { rm -f "$tmpf"; die "No se pudo leer deploy.sh de $sha"; }
	bash -n "$tmpf" || { rm -f "$tmpf"; die "El deploy.sh de $sha no es válido: el lanzador no se actualiza"; }
	chmod 700 "$tmpf" && mv -f "$tmpf" "$TRUSTED_DIR/deploy.sh"
	info "Lanzador de confianza actualizado al deploy.sh de ${sha:0:7} (sha256 $(sha256_of "$TRUSTED_DIR/deploy.sh"))"
}

deploy_log() { echo "$LOCAL_LOG_DIR/$1.log"; }

# Último SHA registrado para un destino (vacío si no hay registro o está vacío).
# Último SHA desplegado y VERIFICADO en un destino (los rollback no mueven el ancla de confianza).
last_deployed() {
	local f; f="$(deploy_log "$1")"
	[ -f "$f" ] || return 0
	awk '$4=="verified" { l=$2 } END { if (l != "") print l }' "$f"
}

# El SHA a desplegar debe estar en origin/main.
check_source() {
	local sha="$1"
	git -C "$REPO_ROOT" fetch --quiet origin main || die "No se pudo actualizar origin/main"
	git -C "$REPO_ROOT" merge-base --is-ancestor "$sha" origin/main || die "$sha no está en origin/main"
	return 0
}

# El código propio del paquete debe ser idéntico al del árbol de git en <sha>, sin extras. El CI genera el paquete
# y su SHA-256: el hash solo prueba integridad del transporte, no autenticidad.
verify_own_code() {
	local sha="$1" dir="$2" x="$2/x" rel name e n=0 gdir cdir f ok base stem
	local asset_re="^<\\?php return array\\('dependencies' => array\\(('[a-z0-9@/._-]+'(, )?)*\\), 'version' => '[a-z0-9]+'\\);[[:space:]]*\$"
	# Directorios de trabajo nuevos: si el artefacto los trajera prellenados, la comparación se podría falsear.
	[ -d "$x" ] || die "Falta $x"
	mkdir "$dir/git" "$dir/cmp" 2>/dev/null || die "Directorios de trabajo de la verificación ya existen: se rechaza el paquete"
	[ -z "$(find "$x" -type l)" ] || die "El paquete contiene enlaces simbólicos"
	# .gitkeep solo como archivo vacío (package_release los borra; no viajan al servidor).
	[ -z "$(find "$x" -name .gitkeep \( ! -type f -o -size +0c \))" ] || die ".gitkeep con contenido o que no es un archivo"
	find "$x" -type f -name .gitkeep -delete

	# 1. Lista blanca exacta de componentes y nombres seguros: solo código propio que exista en git; el resto lo instala Hostinger.
	for e in "$x"/wp-content/plugins/* "$x"/wp-content/themes/* "$x"/wp-content/mu-plugins/*; do
		[ -e "$e" ] || continue
		[[ "$(basename "$e")" =~ ^[A-Za-z0-9._-]+$ ]] || die "Nombre no permitido en el paquete: $(basename "$e")"
	done
	for e in "$x"/wp-content/plugins/*; do
		[ -e "$e" ] || continue; name="$(basename "$e")"; ok=no
		if [ "$name" = aralia-core ] && git -C "$REPO_ROOT" cat-file -e "$sha:$PROJECT_REL/wp-content/plugins/aralia-core" 2>/dev/null; then ok=yes; fi
		[ "$ok" = yes ] || die "Plugin no permitido en el paquete: $name"
	done
	for e in "$x"/wp-content/themes/*; do
		[ -e "$e" ] || continue; name="$(basename "$e")"
		[ "$name" = aralia ] && git -C "$REPO_ROOT" cat-file -e "$sha:$PROJECT_REL/wp-content/themes/aralia" 2>/dev/null && continue
		die "Tema no permitido en el paquete: $name"
	done
	for e in "$x"/wp-content/mu-plugins/*; do
		[ -e "$e" ] || continue; name="$(basename "$e")"
		git -C "$REPO_ROOT" cat-file -e "$sha:$PROJECT_REL/wp-content/mu-plugins/$name" 2>/dev/null \
			|| die "mu-plugin del paquete que no está en git: $name"
	done

	# 2. Comparación contra git (aralia-core y tema).
	for rel in wp-content/plugins/aralia-core wp-content/themes/aralia; do
		[ -d "$x/$rel" ] || continue
		name="$(basename "$rel")"; gdir="$dir/git/$PROJECT_REL/$rel"; cdir="$dir/cmp/$name"
		git -C "$REPO_ROOT" archive "$sha" "$PROJECT_REL/$rel" | tar -x -C "$dir/git"
		cp -R "$x/$rel" "$cdir"
		# build/ solo en la raíz del componente y solo de tipos no ejecutables; los *.asset.php deben ser EXACTAMENTE
		# una línea con la forma que genera @wordpress/scripts (sin llamadas a funciones).
		if [ -d "$cdir/build" ]; then
			while IFS= read -r f; do
				base="$(basename "$f")"
				# Ninguna extensión ejecutable en medio del nombre (x.php.js, x.phtml.map…) salvo la terminación exacta .asset.php.
				stem="$(printf '%s' "${base%.asset.php}" | tr 'A-Z' 'a-z')"
				if [[ "$stem" =~ \.(php[0-9]*|phtml|phar|pht|phps|inc|shtml|cgi|pl|py)([.]|$) ]] || [[ "$stem" =~ ^\.|^$ ]]; then die "build/ con nombre no permitido: $f"; fi
				case "$base" in
					*.asset.php)
						[[ "$base" =~ ^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*\.asset\.php$ ]] || die "build/ con nombre no permitido: $f"
						{ [ "$(grep -c '' "$f")" -eq 1 ] && grep -Eq "$asset_re" "$f"; } || die "build/ con PHP no permitido: $f" ;;
					*) [[ "$base" =~ ^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*\.(js|css|json|map|png|jpg|woff2)$ ]] || die "build/ con archivo no permitido: $f" ;;
				esac
			done < <(find "$cdir/build" -type f)
			rm -rf "$cdir/build"
		fi
		rm -rf "$gdir/build"
		# package_release excluye blocks/ y node_modules/ SOLO de aralia-core: si el paquete los trae, diff -r falla.
		if [ "$name" = aralia-core ]; then rm -rf "$gdir/blocks" "$gdir/node_modules"; fi
		# Marcadores .gitkeep: solo archivos (un directorio llamado .gitkeep no se excluye).
		find "$gdir" "$cdir" -type f -name .gitkeep -delete
		diff -r "$gdir" "$cdir" >/dev/null || die "El paquete difiere del árbol de git en $rel (¿workflow o build alterado?)"
		n=$((n+1))
	done

	# 3. mu-plugins: cada uno (incluido el cargador de secretos) idéntico a git.
	for e in "$x"/wp-content/mu-plugins/*; do
		[ -e "$e" ] || continue; name="$(basename "$e")"
		git -C "$REPO_ROOT" archive "$sha" "$PROJECT_REL/wp-content/mu-plugins/$name" | tar -x -C "$dir/git"
		find "$dir/git/$PROJECT_REL/wp-content/mu-plugins/$name" -type f -name .gitkeep -delete
		diff -r "$dir/git/$PROJECT_REL/wp-content/mu-plugins/$name" "$e" >/dev/null || die "El paquete difiere del árbol de git en mu-plugins/$name"
		n=$((n+1))
	done
	# 4. Completitud: todo componente propio que git tiene en <sha> debe viajar. Si no, remove_orphans lo borraría del servidor.
	git -C "$REPO_ROOT" cat-file -e "$sha:$PROJECT_REL/wp-content/plugins/aralia-core/aralia-core.php" 2>/dev/null \
		&& { [ -d "$x/wp-content/plugins/aralia-core" ] || die "Al paquete le falta aralia-core (existe en git)"; }
	git -C "$REPO_ROOT" cat-file -e "$sha:$PROJECT_REL/wp-content/themes/aralia/style.css" 2>/dev/null \
		&& { [ -d "$x/wp-content/themes/aralia" ] || die "Al paquete le falta el tema aralia (existe en git)"; }
	while IFS= read -r name; do
		[[ "$name" == aralia-* ]] || continue
		[ -e "$x/wp-content/mu-plugins/$name" ] || die "Al paquete le falta el mu-plugin $name (existe en git)"
	done < <(git -C "$REPO_ROOT" ls-tree --name-only "$sha:$PROJECT_REL/wp-content/mu-plugins/" 2>/dev/null || true)
	[ "$n" -gt 0 ] || die "No se pudo verificar ningún componente propio: el paquete no coincide con git"
	info "Código propio del paquete idéntico al de git ($n componentes, sin extras ni enlaces)"
}

fetch_release() {
	local sha="$1" dir="$2" run_id
	command -v gh >/dev/null || die "Falta gh (GitHub CLI): brew install gh && gh auth login"
	run_id="$(gh run list -R "$REPO" --workflow ecommerce-ci.yml --commit "$sha" --branch main --event push \
		--json databaseId,conclusion --jq 'map(select(.conclusion=="success"))[0].databaseId // empty')" || die "No se pudo consultar el CI (gh auth status)"
	[ -n "$run_id" ] || die "No hay un CI en verde para $sha en main"
	# El artefacto se descarga a un directorio aparte y solo se adoptan sus dos archivos esperados.
	mkdir "$dir/dl" || die "No se pudo crear el directorio de descarga"
	gh run download "$run_id" -R "$REPO" -n "release-$sha" -D "$dir/dl" >/dev/null || die "No se pudo descargar el paquete del CI"
	[ -f "$dir/dl/aralia-$sha.tar.gz" ] && [ -f "$dir/dl/aralia-$sha.tar.gz.sha256" ] || die "El artefacto del CI no trae el paquete y su SHA-256"
	[ ! -L "$dir/dl/aralia-$sha.tar.gz" ] && [ ! -L "$dir/dl/aralia-$sha.tar.gz.sha256" ] || die "El artefacto del CI trae enlaces"
	cp "$dir/dl/aralia-$sha.tar.gz" "$dir/dl/aralia-$sha.tar.gz.sha256" "$dir/"
	unpack_release "$sha" "$dir"
	verify_own_code "$sha" "$dir"
	info "CI $run_id en verde; paquete verificado"
}

# Verifica el SHA-256 y el MANIFEST de $dir/aralia-<sha>.tar.gz y lo extrae en $dir/x.
unpack_release() {
	local sha="$1" dir="$2" expected actual types names
	expected="$(cut -d' ' -f1 < "$dir/aralia-$sha.tar.gz.sha256")"
	actual="$(sha256_of "$dir/aralia-$sha.tar.gz")"
	[ "$expected" = "$actual" ] || die "SHA-256 del paquete no coincide"
	# Antes de extraer: solo archivos y directorios (nada de enlaces simbólicos/duros ni dispositivos) y sin rutas absolutas ni "..".
	# Se captura la salida completa antes de buscar: con pipefail, `grep -q` cortaría la tubería (SIGPIPE) y un tar grande
	# con una entrada maliciosa pasaría por "sin coincidencia".
	types="$(tar -tvzf "$dir/aralia-$sha.tar.gz" | cut -c1)" || die "No se pudo leer el paquete"
	names="$(tar -tzf "$dir/aralia-$sha.tar.gz")" || die "No se pudo leer el paquete"
	if grep -q '[^-d]' <<<"$types"; then die "El paquete contiene entradas que no son archivos ni directorios"; fi
	if grep -Eq '(^|/)\.\.(/|$)|^/' <<<"$names"; then die "El paquete contiene rutas absolutas o con .."; fi
	mkdir "$dir/x" 2>/dev/null || die "$dir/x ya existe"
	tar -xzf "$dir/aralia-$sha.tar.gz" -C "$dir/x"
	grep -qx "sha=$sha" "$dir/x/MANIFEST" || die "El MANIFEST no corresponde a $sha"
	return 0
}

# Lista de revisoras (logins en minúscula, una por línea, formato válido) tal como está en un commit.
reviewers_at() {
	git -C "$PROJECT_DIR" show "$1:$PROJECT_REL/ops/payment-reviewers.txt" 2>/dev/null \
		| sed 's/#.*//' | tr -d ' \t\r' | tr 'A-Z' 'a-z' | grep -E '^[a-z0-9-]{1,39}$' || true
}

# Puerta de revisión independiente (CM ítem 11, PCI 6.2.3).
check_payment_reviews() {
	local sha="$1" last base range trusted new reviewers c prs ok n author head r answer commits_raw owner prauthors reviews signed
	owner="$(echo "${REPO%%/*}" | tr 'A-Z' 'a-z')"
	last="$(last_deployed production)"
	if [ -n "$last" ]; then
		valid_sha "$last" || die "Registro de producción corrupto: '$last'"
		git -C "$REPO_ROOT" cat-file -e "$last^{commit}" 2>/dev/null || die "Falta el commit del último despliegue ($last) en este clon: git fetch origin"
		git -C "$REPO_ROOT" merge-base --is-ancestor "$last" "$sha" || die "$sha no desciende del último despliegue $last (para volver atrás usa: ops/deploy.sh rollback <sha>)"
		base="$last"
	else
		# Primer despliegue: se revisa todo lo posterior al génesis (ver REVIEW_GENESIS).
		valid_sha "$REVIEW_GENESIS" || die "REVIEW_GENESIS inválido"
		git -C "$REPO_ROOT" cat-file -e "$REVIEW_GENESIS^{commit}" 2>/dev/null || die "REVIEW_GENESIS no existe en este clon (git fetch)"
		git -C "$REPO_ROOT" merge-base --is-ancestor "$REVIEW_GENESIS" "$sha" || die "$sha no desciende de REVIEW_GENESIS"
		# El génesis debe ser el commit inicial del repositorio: un commit revisado no puede moverlo para excluir historia.
		git -C "$REPO_ROOT" rev-list --max-parents=0 "$sha" | grep -qx "$REVIEW_GENESIS" || die "REVIEW_GENESIS no es el commit inicial de $sha"
		base="$REVIEW_GENESIS"
	fi
	range="$base..$sha"

	commits_raw="$(git -C "$REPO_ROOT" log --format=%H "$range" -- . "${REVIEW_EXEMPT[@]}")"
	local -a commits=()
	while IFS= read -r c; do [ -n "$c" ] && commits+=("$c"); done <<<"$commits_raw"
	[ "${#commits[@]}" -eq 0 ] && { info "Sin cambios que exijan revisión desde el último despliegue"; return 0; }

	# Raíz de confianza: la lista del último despliegue, y solo mientras siga vigente en este commit (revocar surte efecto).
	# La dueña del repositorio no puede ser revisora independiente.
	new="$(reviewers_at "$sha" | grep -vx "$owner" || true)"
	if [ -n "$last" ]; then
		trusted="$(reviewers_at "$last" | grep -vx "$owner" || true)"
		[ -n "$trusted" ] || die "La lista de revisoras del último despliegue está vacía: producción bloqueada hasta contratar a la revisora (el alta inicial solo existe en el primer despliegue)"
		reviewers="$(grep -Fx -f <(printf '%s\n' "$trusted") <<<"$new" || true)"
		[ -n "$reviewers" ] || die "Ninguna revisora de confianza del último despliegue sigue en ops/payment-reviewers.txt de $sha"
	else
		[ -n "$new" ] || die "Hay ${#commits[@]} commit(s) por revisar y ops/payment-reviewers.txt está vacío (régimen transitorio): producción bloqueada hasta contratar a la revisora"
		info "Primer despliegue: no hay lista de revisoras previa. La lista propuesta en $sha es:"
		sed 's/^/    /' <<<"$new"
		read -r -p "Alta de revisora(s): escribe ALTA-REVISORA para aceptar ESTA lista como raíz de confianza: " answer || die "Sin entrada interactiva"
		[ "$answer" = "ALTA-REVISORA" ] || die "Cancelado"
		reviewers="$new"
	fi

	for c in "${commits[@]}"; do
		# El commit debe ser EL commit de fusión (squash) que creó GitHub para el PR aprobado: un merge local con código
		# añadido después de la aprobación no cumple ninguna de las dos condiciones.
		prs="$(gh api "repos/$REPO/commits/$c/pulls" --jq ".[] | select(.merged_at != null and .base.ref == \"main\" and .merge_commit_sha == \"$c\") | \"\\(.number) \\(.user.login)\"")" \
			|| die "No se pudo consultar los PR del commit $c (gh auth status)"
		[ -n "$prs" ] || die "Commit $c sin PR fusionado en main con ese commit como resultado de la fusión (push directo o merge local)"
		signed="$(gh api "repos/$REPO/commits/$c" --jq '((.committer.login // "") == "web-flow") and (.commit.verification.verified == true)')" \
			|| die "No se pudo consultar el commit $c"
		[ "$signed" = true ] || die "Commit $c no fue creado y firmado por GitHub (merge desde la web, squash): ¿merge local?"
		ok=false
		while read -r n author; do
			[ -n "$n" ] || continue
			head="$(gh api "repos/$REPO/pulls/$n" --jq .head.sha)" || die "No se pudo consultar el PR #$n"
			# Quien aprueba no puede haber escrito commits del PR (ni abrirlo).
			prauthors="$(gh api "repos/$REPO/pulls/$n/commits" --paginate --jq '.[] | ((.author.login // "NULL") , (.committer.login // "NULL"))' | tr 'A-Z' 'a-z')" \
				|| die "No se pudo consultar los commits del PR #$n"
			# Un commit cuyo correo no está vinculado a una cuenta de GitHub no se puede atribuir: no se acepta.
			if grep -qx 'null' <<<"$prauthors"; then die "El PR #$n tiene commits sin cuenta de GitHub asociada: no se puede verificar la independencia de la revisión"; fi
			prauthors="$(printf '%s\n%s\n' "$prauthors" "$(echo "$author" | tr 'A-Z' 'a-z')")"
			# Último estado de cada persona (las reseñas llegan en orden cronológico); solo cuenta APPROVED sobre el último commit.
			reviews="$(gh api "repos/$REPO/pulls/$n/reviews" --paginate \
				--jq '.[] | select(.state=="APPROVED" or .state=="CHANGES_REQUESTED" or .state=="DISMISSED") | "\(.user.login | ascii_downcase) \(.state) \(.commit_id)"')" \
				|| die "No se pudo consultar las reseñas del PR #$n"
			while read -r r; do
				[ -n "$r" ] || continue
				grep -Fxq "$r" <<<"$prauthors" && continue
				grep -Fxq "$r" <<<"$reviewers" && ok=true
			done < <(printf '%s\n' "$reviews" \
				| awk 'NF { s[$1]=$2" "$3 } END { for (u in s) print u" "s[u] }' \
				| awk -v h="$head" '$2=="APPROVED" && $3==h { print $1 }')
		done <<<"$prs"
		$ok || die "Commit $c: su PR no tiene aprobación vigente de una revisora de confianza (distinta de quienes escribieron el PR) sobre el último commit"
	done
	info "Revisión humana verificada en ${#commits[@]} commit(s)"
}

sha256_stdin() { if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi; }

# Lo instalado por Hostinger/wp-admin (núcleo, plugins, temas, mu-plugins, drop-ins) debe coincidir con ops/third-party.lock DEL
# COMMIT que se despliega: lista cerrada y versiones exactas ("any" = cualquier versión; núcleo "7.1.*" = cualquier 7.1.x).
# 1) Inventario por sistema de archivos (sin ejecutar PHP): nada instalado que no esté en el manifiesto.
# 2) WP-CLI: versiones, plugins activos y checksums. OJO: `--skip-plugins --skip-themes` NO omite wp-config.php, los
#    mu-plugins ni los drop-ins, así que estas salidas las podría falsear un mu-plugin hostil (RR-13): el inventario del
#    paso 1 es lo que no depende de ese código. Se hace ANTES de copiar y se repite tras copiar (apply_release).
# Deja en TP_HASH la huella de lo encontrado: producción exige la misma que staging tuvo para ese SHA.
TP_HASH=""
THIRD_PARTY_REQUIRED=(woocommerce wompi-portal-de-pagos wordfence)   # deben estar instalados y activos

# Entradas (archivos o carpetas) de un directorio del destino, sin PHP; un nombre por línea.
list_dir() { on_target "$1" "ls -A '$2' 2>/dev/null || true"; }

inventory_fs() {
	local host="$1" path="$2" manifest="$3" e base kind dir
	for kind in plugins themes mu-plugins; do
		dir="$path/wp-content/$kind"
		while IFS= read -r e; do
			[ -n "$e" ] || continue
			[[ "$e" =~ ^[A-Za-z0-9._-]+$ ]] || die "Nombre no permitido en $kind del servidor: $e"
			base="${e%.php}"
			[ "$e" = index.php ] && continue
			case "$kind" in
				plugins)    [ "$e" = aralia-core ] && continue
				            grep -Eq "^plugin $e " <<<"$manifest" || die "Plugin instalado no inventariado: $e (ops/third-party.lock)" ;;
				themes)     [ "$e" = aralia ] && continue
				            grep -qxF "theme $e" <<<"$manifest" || die "Tema instalado no inventariado: $e (ops/third-party.lock)" ;;
				mu-plugins) [[ "$e" == aralia-* ]] && continue
				            grep -qxF "mu $base" <<<"$manifest" || die "mu-plugin instalado no inventariado: $e (ops/third-party.lock)" ;;
			esac
		done < <(list_dir "$host" "$dir")
	done
	while IFS= read -r e; do
		[ -n "$e" ] || continue
		[[ "$e" =~ ^[A-Za-z0-9._-]+$ ]] || die "Nombre no permitido en wp-content del servidor: $e"
		[ "$e" = index.php ] && continue
		grep -qxF "dropin ${e%.php}" <<<"$manifest" || die "Drop-in instalado no inventariado: $e (ops/third-party.lock)"
	done < <(list_dir "$host" "$path/wp-content" | grep '\.php$' || true)
}

check_third_party() {
	local sha="$1" host="$2" path="$3" manifest out core_v list norm name status ver au k v ok req seen=" "
	manifest="$(git -C "$REPO_ROOT" show "$sha:$PROJECT_REL/ops/third-party.lock" 2>/dev/null | sed 's/#.*//' | awk 'NF')" || true
	[ -n "$manifest" ] || die "Falta ops/third-party.lock en $sha (lista cerrada de terceros y versiones)"
	inventory_fs "$host" "$path" "$manifest"
	out="$(on_target "$host" "cd '$path' && wp core verify-checksums --skip-plugins --skip-themes 2>&1")" || die "wp core verify-checksums falló: $out"
	if grep -qi 'warning' <<<"$out"; then die "wp core verify-checksums con avisos: $out"; fi
	core_v="$(on_target "$host" "cd '$path' && wp core version --skip-plugins --skip-themes")" || die "No se pudo leer la versión de WordPress"
	ok=no
	while read -r k v; do
		[ "$k" = core ] || continue
		[[ "$v" =~ ^[0-9]+\.[0-9]+(\.[0-9]+|\.\*)$ ]] || die "ops/third-party.lock: versión de núcleo no válida: $v"
		# shellcheck disable=SC2254  # $v es un patrón a propósito (validado arriba)
		case "$core_v" in $v) ok=yes ;; esac
	done <<<"$manifest"
	[ "$ok" = yes ] || die "WordPress $core_v no coincide con ops/third-party.lock de $sha"
	out="$(on_target "$host" "cd '$path' && wp plugin verify-checksums ${THIRD_PARTY_PLUGINS[*]} --skip-plugins --skip-themes 2>&1")" || die "wp plugin verify-checksums falló: $out"
	if grep -qi 'warning' <<<"$out"; then die "wp plugin verify-checksums con avisos: $out"; fi
	list="$(on_target "$host" "cd '$path' && wp plugin list --fields=name,status,version,auto_update --format=csv --skip-plugins --skip-themes")" || die "No se pudo listar los plugins"
	norm="core $core_v"$'\n'
	while IFS=, read -r name status ver au; do
		{ [ -n "$name" ] && [ "$name" != name ]; } || continue
		[[ "$name" =~ ^[A-Za-z0-9._-]+$ ]] || die "Nombre de plugin no permitido en el servidor: $name"
		name="${name%.php}"   # WP-CLI quita la extensión de mu-plugins y drop-ins de un solo archivo
		case "$status" in
			must-use)
				[[ "$name" == aralia-* ]] && continue
				grep -qxF "mu $name" <<<"$manifest" || die "mu-plugin no inventariado en el servidor: $name (añádelo a ops/third-party.lock con su justificación o retíralo)"
				norm="${norm}mu $name"$'\n' ;;
			dropin)
				grep -qxF "dropin $name" <<<"$manifest" || die "drop-in no inventariado en el servidor: $name"
				norm="${norm}dropin $name"$'\n' ;;
			*)
				[ "$name" = aralia-core ] && continue
				grep -qxF "plugin $name $ver" <<<"$manifest" || grep -qxF "plugin $name any" <<<"$manifest" \
					|| die "Plugin $name $ver no coincide con ops/third-party.lock de $sha (instalado: lista cerrada y versión exacta)"
				if [ "$name" = wompi-portal-de-pagos ] && [ "$au" != off ]; then
					die "Wompi tiene la actualización automática activada (auto_update=$au): desactívala en wp-admin y en hPanel"
				fi
				for req in "${THIRD_PARTY_REQUIRED[@]}"; do
					if [ "$name" = "$req" ]; then
						[ "$status" = active ] || die "$name está $status: debe estar activo"
						seen="${seen}${name} "
					fi
				done
				norm="${norm}plugin $name $ver"$'\n' ;;
		esac
	done <<<"$list"
	for req in "${THIRD_PARTY_REQUIRED[@]}"; do
		case "$seen" in *" $req "*) ;; *) die "Falta el plugin $req (debe estar instalado y activo)" ;; esac
	done
	TP_HASH="$(printf '%s' "$norm" | sort | sha256_stdin)"
	info "Terceros del servidor conformes con ops/third-party.lock (huella ${TP_HASH:0:12})"
}

# Producción solo recibe un SHA cuyos terceros fueron, en staging, EXACTAMENTE los que hay ahora en producción.
staging_tp_matches() {
	local sha="$1" f stg
	f="$(deploy_log staging)"
	stg="$(awk -v s="$sha" '$2==s && $4=="verified" { t=""; for (i=5;i<=NF;i++) if ($i ~ /^tp=/) t=substr($i,4) } END { print t }' "$f" 2>/dev/null || true)"
	[ -n "$stg" ] || die "El registro de staging de $sha no trae la huella de terceros (tp=): vuelve a desplegarlo en staging"
	[ "$stg" = "$TP_HASH" ] || die "Los terceros de producción difieren de los probados en staging para $sha (huella $TP_HASH, staging $stg)"
}

verify_target() {
	local target="$1" host="$2" path="$3" baseline
	if [ "$target" = production ]; then
		baseline="wp aralia baseline check"
	else
		baseline="if wp cli has-command 'aralia baseline'; then wp aralia baseline check; else echo 'aviso: baseline check aún no existe (fase 2)'; fi"
	fi
	on_target "$host" "cd '$path' && $baseline"
}

DEPLOY_TMP=""
cleanup() { [ -n "$DEPLOY_TMP" ] && rm -rf "$DEPLOY_TMP"; return 0; }

# Simulación, confirmación, copia, verificación y registro (compartido por deploy y rollback).
apply_release() {
	local target="$1" sha="$2" tmp="$3" host="$4" path="$5" kind="$6" confirm="$7" answer nchanges ndel flags="" lock_sha tp_before
	# Un rollback comprueba los terceros contra el lock del último despliegue verificado (lo que debe estar instalado AHORA),
	# no contra el del SHA de destino: si después se actualizó un tercero, el lock antiguo ya no describe el servidor.
	lock_sha="$sha"
	if [ "$kind" = rollback ]; then lock_sha="$(last_deployed production)"; [ -n "$lock_sha" ] || die "No hay un despliegue verificado previo contra el que comprobar los terceros"; fi
	check_third_party "$lock_sha" "$host" "$path"
	tp_before="$TP_HASH"
	if [ "$target" = production ] && [ "$kind" = verified ]; then staging_tp_matches "$sha"; fi
	if [ "$target" = production ]; then
		on_target "$host" "cd '$path' && wp cli has-command 'aralia baseline'" >/dev/null 2>&1 \
			|| die "En producción falta 'wp aralia baseline check' (Fase 2, T032): no se despliega hasta que exista, para no copiar y fallar la verificación"
	fi
	info "Simulación en $target ($host:$path):"
	sync_release "$tmp/x" "$host" "$path" -n > "$tmp/dry-run.txt" || die "La simulación falló (ruta, ssh o rsync); no se despliega nada"
	nchanges="$(grep -vc '^\.' "$tmp/dry-run.txt" || true)"
	ndel="$(grep -c '^\*deleting' "$tmp/dry-run.txt" || true)"
	grep -v '^\.' "$tmp/dry-run.txt" | head -n 40 || true
	info "$nchanges líneas de cambio, $ndel borrados. (Lista completa: $tmp/dry-run.txt; ábrela en otra terminal antes de confirmar)"

	read -r -p "Escribe $confirm para continuar: " answer || die "Sin entrada interactiva"
	[ "$answer" = "$confirm" ] || die "Cancelado"

	sync_release "$tmp/x" "$host" "$path" > /dev/null \
		|| die "El despliegue falló a medias en $target: estado parcial. Si es producción, activa el modo mantenimiento de pagos y vuelve a un SHA bueno con ops/deploy.sh rollback <sha> (ops/runbooks/deploy.md)"
	# Los terceros pudieron cambiar entre la comprobación y la copia (o durante ella): se repite antes de registrar.
	check_third_party "$lock_sha" "$host" "$path"
	[ "$TP_HASH" = "$tp_before" ] || die "Los terceros del servidor cambiaron durante el despliegue en $target (huella $tp_before → $TP_HASH). No queda registrado: revisa y repite"
	verify_target "$target" "$host" "$path" \
		|| die "Verificación fallida en $target. No queda registrado. Si es producción: activa el modo mantenimiento de pagos y vuelve a un SHA bueno con ops/deploy.sh rollback <sha> (ops/runbooks/deploy.md)"
	mkdir -p "$LOCAL_LOG_DIR/releases" && chmod 700 "$LOCAL_LOG_DIR"
	if [ "$target" = production ] && [ "$kind" = verified ]; then
		cp "$tmp/aralia-$sha.tar.gz" "$tmp/aralia-$sha.tar.gz.sha256" "$LOCAL_LOG_DIR/releases/"
	fi
	# Las aceptaciones de riesgo vigentes quedan escritas junto al despliegue.
	[ "$ALLOW_SHARED_STAGING" = yes ] && flags="$flags ALLOW_SHARED_STAGING"
	[ "$ALLOW_UNPROTECTED_MAIN" = yes ] && flags="$flags ALLOW_UNPROTECTED_MAIN"
	printf '%s %s %s %s launcher=%s allow=%s tp=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$sha" "$(git config user.email || whoami)" "$kind" \
		"$(sha256_of "$SELF")" "${flags# }" "$TP_HASH" >> "$(deploy_log "$target")"
	info "Desplegado y verificado en $target ($kind): $sha"
	if [ "$target" = production ] && [ "$kind" = verified ]; then install_launcher "$sha"; fi
	return 0
}

deploy() {
	local target="$1" sha="$2" host path tmp
	valid_sha "$sha" || die "Uso: ops/deploy.sh $target <sha de 40 caracteres>"
	require_trusted_launcher
	load_conf
	if [ "$target" = production ]; then host="$PRODUCTION_SSH"; path="$PRODUCTION_PATH"; else host="$STAGING_SSH"; path="$STAGING_PATH"; fi
	command -v gh >/dev/null || die "Falta gh (GitHub CLI): brew install gh && gh auth login"

	check_source "$sha"
	check_destinations
	if [ "$target" = production ]; then check_production_prereqs; fi
	DEPLOY_TMP="$(mktemp -d)"; tmp="$DEPLOY_TMP"; trap cleanup EXIT
	fetch_release "$sha" "$tmp"

	if [ "$target" = production ]; then
		grep -q " $sha " "$(deploy_log staging)" 2>/dev/null || die "$sha no se ha desplegado y verificado en staging (registro local)"
		check_payment_reviews "$sha"
		apply_release production "$sha" "$tmp" "$host" "$path" verified "PRODUCCION ${sha:0:7}"
	else
		apply_release staging "$sha" "$tmp" "$host" "$path" verified "si"
	fi
}

# Vuelta atrás: solo a un SHA que ya se desplegó y verificó en producción; usa el paquete guardado en local
# (los artefactos del CI caducan a los 90 días) y no exige ascendencia ni descarga. Queda registrada.
rollback() {
	local sha="$1" tmp rel
	valid_sha "$sha" || die "Uso: ops/deploy.sh rollback <sha de 40 caracteres>"
	require_trusted_launcher
	load_conf
	grep -q " $sha " "$(deploy_log production)" 2>/dev/null || die "$sha no figura en el registro de producción: solo se puede volver a un SHA ya desplegado"
	rel="$LOCAL_LOG_DIR/releases"
	[ -f "$rel/aralia-$sha.tar.gz" ] && [ -f "$rel/aralia-$sha.tar.gz.sha256" ] || die "No hay paquete guardado de $sha en $rel"
	git -C "$REPO_ROOT" cat-file -e "$sha^{commit}" 2>/dev/null || die "Falta el commit $sha en este clon: git fetch origin"
	DEPLOY_TMP="$(mktemp -d)"; tmp="$DEPLOY_TMP"; trap cleanup EXIT
	cp "$rel/aralia-$sha.tar.gz" "$rel/aralia-$sha.tar.gz.sha256" "$tmp/"
	unpack_release "$sha" "$tmp"
	verify_own_code "$sha" "$tmp"
	apply_release production "$sha" "$tmp" "$PRODUCTION_SSH" "$PRODUCTION_PATH" rollback "ROLLBACK ${sha:0:7}"
}

main() {
	case "${1:-}" in
		package)            package_release "${2:-}" ;;
		staging|production) deploy "$1" "${2:-}" ;;
		rollback)           rollback "${2:-}" ;;
		*) die "Uso: ops/deploy.sh package|staging|production|rollback <sha>" ;;
	esac
}

# Las pruebas (tests/ops/deploy_test.sh) cargan las funciones sin ejecutar main.
if [ "${ARALIA_DEPLOY_LIB:-}" != 1 ]; then main "$@"; fi
