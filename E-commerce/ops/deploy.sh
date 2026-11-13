#!/usr/bin/env bash
# Despliegue de Aralía (research R-15, enmienda de la constitución 1.1.0).
#
#   ops/deploy.sh package    <sha>   # lo usa el CI: arma release/aralia-<sha>.tar.gz (+ .sha256)
#   ops/deploy.sh staging    <sha>   # Sara, desde su equipo
#   ops/deploy.sh production <sha>   # Sara, desde su equipo
#   ops/deploy.sh rollback   <sha>   # vuelta atrás a un SHA ya desplegado antes en producción (paquete guardado local)
#
# Por qué local y no en GitHub Actions: con el plan Free en un repo privado no hay environments protegidos
# ni protección de rama, así que una llave SSH guardada en GitHub quedaría al alcance de cualquier rama.
# La llave vive solo en el equipo de Sara (ssh-agent) y la "aprobación manual" es que ella ejecute esto.
#
# Antes de copiar un solo archivo, el script exige:
#   1. que <sha> esté en origin/main y que este script sea idéntico al de <sha> (staging) o al del ÚLTIMO despliegue
#      a producción (producción: la puerta no puede ser editada por el commit que se revisa);
#   2. CI en verde para <sha> (push a main) y el paquete de ese run con su SHA-256 correcto;
#   3. producción: que <sha> ya se haya desplegado y verificado en staging;
#   4. producción: que TODO commit desde el último despliegue (salvo documentación exenta, ver REVIEW_EXEMPT)
#      venga de un PR fusionado en main y aprobado, sobre su último commit, por alguien de la lista de revisoras
#      DISTINTO de la autora. La lista se lee del último despliegue a producción (no del commit a desplegar);
#      el alta de la primera revisora exige una confirmación escrita de Sara (ALTA-REVISORA);
#   5. una simulación (rsync -n) que Sara revisa y confirma.
# Solo sincroniza carpetas propias o fijadas por Composer, cada una con --delete acotado a sí misma:
# nunca toca .htaccess, .user.ini, wordfence-waf.php, .well-known, wp-config.php, uploads, wflogs,
# caché de LiteSpeed, idiomas, updraft ni UpdraftPlus.
#
# Configuración (no secreta, ignorada por git): ops/deploy.conf, a partir de ops/deploy.conf.sample.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SELF="$SCRIPT_DIR/$(basename "${BASH_SOURCE[0]}")"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"     # E-commerce/
PROJECT_REL="E-commerce"
REPO_ROOT="$(cd "$PROJECT_DIR/.." && pwd)"
# Compatible con el bash 3.2 de macOS (sin mapfile ni arrays asociativos).

# Plugins de terceros que se despliegan (lista cerrada, R-15). UpdraftPlus Premium se instala a mano y no se toca.
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

die()  { printf '✖ %s\n' "$*" >&2; exit 1; }
info() { printf '• %s\n' "$*"; }

sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

valid_sha() { [[ "${1:-}" =~ ^[0-9a-f]{40}$ ]]; }

# El rsync de macOS (openrsync) no soporta --chmod: hace falta rsync 3 (brew install rsync).
require_rsync3() {
	local v
	v="$(rsync --version 2>/dev/null || true)"   # sin tubería: con pipefail, head cortaría rsync (SIGPIPE)
	[[ "${v%%$'\n'*}" =~ rsync\ +version\ +3\. ]] \
		|| die "Hace falta rsync 3.x (en macOS: brew install rsync y abrir una terminal nueva)"
}

# ---------------------------------------------------------------------------------------------- package

package_release() {
	local sha="$1" out="$PROJECT_DIR/release" stage
	valid_sha "$sha" || die "SHA inválido"
	[ -f "$PROJECT_DIR/wp/wp-load.php" ] || die "Falta wp/ (composer install)"

	stage="$(mktemp -d)"
	mkdir -p "$stage/wp-content/plugins" "$stage/wp-content/themes" "$stage/wp-content/mu-plugins" "$out"

	cp -R "$PROJECT_DIR/wp" "$stage/wp"
	rm -f "$stage/wp/wp-config.php" "$stage/wp/wp-config-sample.php"

	local p
	for p in "${THIRD_PARTY_PLUGINS[@]}"; do
		[ -d "$PROJECT_DIR/wp-content/plugins/$p" ] || die "Falta el plugin $p (composer install)"
		cp -R "$PROJECT_DIR/wp-content/plugins/$p" "$stage/wp-content/plugins/$p"
	done
	if [ -f "$PROJECT_DIR/wp-content/plugins/aralia-core/aralia-core.php" ]; then
		cp -R "$PROJECT_DIR/wp-content/plugins/aralia-core" "$stage/wp-content/plugins/aralia-core"
		rm -rf "$stage/wp-content/plugins/aralia-core/blocks" "$stage/wp-content/plugins/aralia-core/node_modules"
	fi
	if [ -f "$PROJECT_DIR/wp-content/themes/aralia/style.css" ]; then
		cp -R "$PROJECT_DIR/wp-content/themes/aralia" "$stage/wp-content/themes/aralia"
	fi
	local m
	for m in "$PROJECT_DIR"/wp-content/mu-plugins/aralia-*; do
		[ -e "$m" ] || continue
		case "$(basename "$m")" in
			aralia-secrets-loader.php) ;;
			aralia-secrets*) die "Archivo de secretos dentro de mu-plugins: $(basename "$m")" ;;
		esac
		cp -R "$m" "$stage/wp-content/mu-plugins/"
	done
	find "$stage" -name '.gitkeep' -delete

	{
		echo "sha=$sha"
		echo "built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
		echo "plugins=${THIRD_PARTY_PLUGINS[*]}"
	} > "$stage/MANIFEST"

	tar -czf "$out/aralia-$sha.tar.gz" -C "$stage" .
	( cd "$out" && printf '%s  %s\n' "$(sha256_of "aralia-$sha.tar.gz")" "aralia-$sha.tar.gz" > "aralia-$sha.tar.gz.sha256" )
	rm -rf "$stage"
	info "Paquete: release/aralia-$sha.tar.gz"
}

# ------------------------------------------------------------------------------------------------- sync

# Ejecuta un comando en el destino ("" = local, para las pruebas).
on_target() {
	local host="$1"; shift
	if [ -z "$host" ]; then bash -c "$*"; else ssh -o StrictHostKeyChecking=yes "$host" "$*"; fi
}

# Copia el paquete extraído ($1) a la raíz de WordPress del destino. $2 host ("" local), $3 ruta, $4 "-n" para simular.
sync_release() {
	local src="$1" host="$2" path="$3" dry="${4:-}" dest
	if [ -z "$path" ] || [ "$path" = "/" ]; then die "Ruta de destino vacía o /"; fi
	[ -f "$src/wp/wp-load.php" ] || die "Paquete sin wp/"
	if [ -z "$host" ]; then dest="$path"; else dest="$host:$path"; fi
	require_rsync3

	on_target "$host" "test -f '$path/wp-config.php'" || die "No hay wp-config.php en $path (¿ruta equivocada?)"
	on_target "$host" "mkdir -p '$path/wp-content/plugins' '$path/wp-content/themes' '$path/wp-content/mu-plugins'" || return 1

	# --checksum: compara contenido, no tamaño+hora (un archivo con igual tamaño y hora no se saltaría).
	local -a R=(rsync -rlptz --checksum --chmod='D755,F644' --itemize-changes)
	[ "$dry" = "-n" ] && R+=(-n)
	export RSYNC_RSH="ssh -o StrictHostKeyChecking=yes"

	# Núcleo: wp-admin y wp-includes son de WordPress (se limpian); los archivos sueltos de la raíz, sin --delete.
	# Cada orden termina en "|| return 1": dentro de una función llamada con "|| die", bash ignora set -e.
	"${R[@]}" --delete "$src/wp/wp-admin/"    "$dest/wp-admin/"    || return 1
	"${R[@]}" --delete "$src/wp/wp-includes/" "$dest/wp-includes/" || return 1
	"${R[@]}" --exclude='/wp-admin/' --exclude='/wp-includes/' --exclude='/wp-content/' \
		--exclude='/wp-config.php' --exclude='/.htaccess' --exclude='/.user.ini' \
		"$src/wp/" "$dest/" || return 1

	local d n
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
	return 0
}

# ---------------------------------------------------------------------------------------------- deploy

REPO=""; STAGING_SSH=""; STAGING_PATH=""; PRODUCTION_SSH=""; PRODUCTION_PATH=""
ALLOW_SHARED_STAGING=""; ALLOW_UNPROTECTED_MAIN=""

# Lee KEY="valor" sin ejecutar nada (deploy.conf no es código) y valida cada valor.
parse_conf() {
	local conf="$1" line key val
	[ -f "$conf" ] || die "Falta ops/deploy.conf (copiar de ops/deploy.conf.sample)"
	while IFS= read -r line || [ -n "$line" ]; do
		case "$line" in ''|'#'*) continue ;; esac
		[[ "$line" =~ ^([A-Z_]+)=\"([^\"]*)\"$ ]] || die "deploy.conf: línea no válida (solo CLAVE=\"valor\"): $line"
		key="${BASH_REMATCH[1]}"; val="${BASH_REMATCH[2]}"
		case "$key" in
			REPO)                         [[ "$val" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || die "deploy.conf: REPO inválido" ;;
			STAGING_SSH|PRODUCTION_SSH)   [[ "$val" =~ ^[A-Za-z0-9._@][A-Za-z0-9._@-]*$ ]] || die "deploy.conf: $key inválido" ;;
			STAGING_PATH|PRODUCTION_PATH) [[ "$val" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "deploy.conf: $key inválido (solo letras, números . _ - /)" ;;
			ALLOW_SHARED_STAGING|ALLOW_UNPROTECTED_MAIN) [ "$val" = yes ] || die "deploy.conf: $key solo admite \"yes\"" ;;
			*) die "deploy.conf: clave desconocida $key" ;;
		esac
		printf -v "$key" '%s' "$val"
	done < "$conf"
	[ -n "$REPO" ] && [ -n "$STAGING_SSH" ] && [ -n "$STAGING_PATH" ] && [ -n "$PRODUCTION_SSH" ] && [ -n "$PRODUCTION_PATH" ] \
		|| die "deploy.conf: faltan claves (REPO, STAGING_SSH, STAGING_PATH, PRODUCTION_SSH, PRODUCTION_PATH)"
	return 0
}

load_conf() {
	local conf="$SCRIPT_DIR/deploy.conf" mode origin
	parse_conf "$conf"
	mode="$(stat -f %Lp "$conf" 2>/dev/null || stat -c %a "$conf")"
	[ "$mode" = 600 ] || die "ops/deploy.conf debe tener permisos 600 (chmod 600 ops/deploy.conf); tiene $mode"
	origin="$(git -C "$REPO_ROOT" remote get-url origin)"
	case "$origin" in *"github.com/$REPO"|*"github.com/$REPO.git"|*"github.com:$REPO"|*"github.com:$REPO.git") ;; *) die "REPO ($REPO) no coincide con el remoto origin ($origin)" ;; esac
	return 0
}

# Identidad real del destino SSH (usuario y host tras resolver ~/.ssh/config), para detectar alias que apuntan a la misma cuenta.
ssh_identity() { ssh -G "$1" 2>/dev/null | awk '$1=="user"||$1=="hostname"{printf "%s ", $2}'; }

# Condiciones de la primera salida a producción (decisiones de Sara; ver ops/compliance/accounts-2fa.md).
check_production_prereqs() {
	if [ "$STAGING_SSH" = "$PRODUCTION_SSH" ] || [ "$(ssh_identity "$STAGING_SSH")" = "$(ssh_identity "$PRODUCTION_SSH")" ]; then
		if [ "$STAGING_PATH" = "$PRODUCTION_PATH" ] || [[ "$STAGING_PATH/" == "$PRODUCTION_PATH/"* ]] || [[ "$PRODUCTION_PATH/" == "$STAGING_PATH/"* ]]; then
			die "STAGING_PATH y PRODUCTION_PATH son iguales o están anidados: staging podría pisar producción"
		fi
		[ "$ALLOW_SHARED_STAGING" = yes ] \
			|| die "Staging y producción usan la misma cuenta SSH: el código de staging (sin revisión) podría leer los secretos de producción. Separa las cuentas (ops/compliance/accounts-2fa.md) o, bajo tu responsabilidad, ALLOW_SHARED_STAGING=\"yes\" en deploy.conf"
		info "AVISO: ALLOW_SHARED_STAGING=yes (staging comparte cuenta con producción)"
	fi
	if ! gh api "repos/$REPO/branches/main/protection" >/dev/null 2>&1; then
		[ "$ALLOW_UNPROTECTED_MAIN" = yes ] \
			|| die "La rama main no tiene protección (GitHub Pro/Team): sin ella cualquiera con Write puede empujar sin PR. Actívala o, bajo tu responsabilidad, ALLOW_UNPROTECTED_MAIN=\"yes\" en deploy.conf"
		info "AVISO: ALLOW_UNPROTECTED_MAIN=yes (main sin protección de rama)"
	fi
	return 0
}

deploy_log() { echo "$LOCAL_LOG_DIR/$1.log"; }

# Último SHA registrado para un destino (vacío si no hay registro o está vacío).
last_deployed() {
	local f; f="$(deploy_log "$1")"
	[ -f "$f" ] || return 0
	grep -v '^[[:space:]]*$' "$f" | tail -n 1 | cut -d' ' -f2 || true
}

check_source() {
	local sha="$1" target="$2" ref="$1" last
	git -C "$PROJECT_DIR" fetch --quiet origin main
	git -C "$PROJECT_DIR" merge-base --is-ancestor "$sha" origin/main || die "$sha no está en origin/main"
	# Producción ejecuta la puerta del ÚLTIMO despliegue: un commit no puede reescribir la comprobación que lo revisa.
	if [ "$target" = production ]; then
		last="$(last_deployed production)"
		if [ -n "$last" ]; then valid_sha "$last" || die "Registro de producción corrupto: '$last'"; ref="$last"; fi
	fi
	git -C "$PROJECT_DIR" show "$ref:$PROJECT_REL/ops/deploy.sh" | cmp -s - "$SELF" \
		|| die "Este deploy.sh difiere del de $ref. Para ese despliegue usa el script correspondiente: git show $ref:$PROJECT_REL/ops/deploy.sh > ops/deploy.last.sh && bash ops/deploy.last.sh $target $sha  (un cambio de deploy.sh entra en vigor en el despliegue siguiente)"
	return 0
}

# El código propio del paquete debe ser idéntico al del árbol de git en <sha>, sin extras. El CI genera el paquete
# y su SHA-256: el hash solo prueba integridad del transporte, no autenticidad.
verify_own_code() {
	local sha="$1" dir="$2" x="$2/x" rel name e n=0 gdir cdir f
	mkdir -p "$dir/git" "$dir/cmp"
	[ -z "$(find "$x" -type l)" ] || die "El paquete contiene enlaces simbólicos"

	# 1. Lista blanca exacta de componentes: lo que no esté en la lista cerrada o en git, no se despliega.
	for e in "$x"/wp-content/plugins/*; do
		[ -e "$e" ] || continue; name="$(basename "$e")"
		case " ${THIRD_PARTY_PLUGINS[*]} " in *" $name "*) continue ;; esac
		[ "$name" = aralia-core ] && git -C "$REPO_ROOT" cat-file -e "$sha:$PROJECT_REL/wp-content/plugins/aralia-core" 2>/dev/null && continue
		die "Plugin no permitido en el paquete: $name"
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
		# Artefactos de build permitidos solo en la raíz del componente y solo de tipos no ejecutables
		# (más *.asset.php de una sola línea, "<?php return array(...);", que genera @wordpress/scripts).
		if [ -d "$cdir/build" ]; then
			while IFS= read -r f; do
				case "$f" in
					*.js|*.css|*.json|*.map|*.svg|*.png|*.jpg|*.woff2) ;;
					*.asset.php) [ "$(wc -l < "$f")" -le 1 ] && grep -Eq '^<\?php return array\([^;]*\);[[:space:]]*$' "$f" || die "build/ con PHP no permitido: $f" ;;
					*) die "build/ con archivo no permitido: $f" ;;
				esac
			done < <(find "$cdir/build" -type f)
			rm -rf "$cdir/build"
		fi
		# Igual que package_release: blocks/ y node_modules/ de la raíz no viajan.
		rm -rf "$cdir/blocks" "$cdir/node_modules" "$gdir/blocks" "$gdir/node_modules" "$gdir/build"
		diff -r -x .gitkeep "$gdir" "$cdir" >/dev/null || die "El paquete difiere del árbol de git en $rel (¿workflow o build alterado?)"
		n=$((n+1))
	done

	# 3. mu-plugins: cada uno (incluido el cargador de secretos) idéntico a git.
	for e in "$x"/wp-content/mu-plugins/*; do
		[ -e "$e" ] || continue; name="$(basename "$e")"
		git -C "$REPO_ROOT" archive "$sha" "$PROJECT_REL/wp-content/mu-plugins/$name" | tar -x -C "$dir/git"
		diff -r "$dir/git/$PROJECT_REL/wp-content/mu-plugins/$name" "$e" >/dev/null || die "El paquete difiere del árbol de git en mu-plugins/$name"
		n=$((n+1))
	done
	[ "$n" -gt 0 ] || die "No se pudo verificar ningún componente propio: el paquete no coincide con git"
	info "Código propio del paquete idéntico al de git ($n componentes, sin extras ni enlaces)"
}

fetch_release() {
	local sha="$1" dir="$2" run_id
	command -v gh >/dev/null || die "Falta gh (GitHub CLI): brew install gh && gh auth login"
	run_id="$(gh run list -R "$REPO" --workflow ecommerce-ci.yml --commit "$sha" --branch main --event push \
		--json databaseId,conclusion --jq 'map(select(.conclusion=="success"))[0].databaseId // empty')" || die "No se pudo consultar el CI (gh auth status)"
	[ -n "$run_id" ] || die "No hay un CI en verde para $sha en main"
	gh run download "$run_id" -R "$REPO" -n "release-$sha" -D "$dir" >/dev/null || die "No se pudo descargar el paquete del CI"
	unpack_release "$sha" "$dir"
	verify_own_code "$sha" "$dir"
	info "CI $run_id en verde; paquete verificado"
}

# Verifica el SHA-256 y el MANIFEST de $dir/aralia-<sha>.tar.gz y lo extrae en $dir/x.
unpack_release() {
	local sha="$1" dir="$2" expected actual
	expected="$(cut -d' ' -f1 < "$dir/aralia-$sha.tar.gz.sha256")"
	actual="$(sha256_of "$dir/aralia-$sha.tar.gz")"
	[ "$expected" = "$actual" ] || die "SHA-256 del paquete no coincide"
	mkdir -p "$dir/x" && tar -xzf "$dir/aralia-$sha.tar.gz" -C "$dir/x"
	grep -qx "sha=$sha" "$dir/x/MANIFEST" || die "El MANIFEST no corresponde a $sha"
}

# Lista de revisoras (logins en minúscula, una por línea, formato válido) tal como está en un commit.
reviewers_at() {
	git -C "$PROJECT_DIR" show "$1:$PROJECT_REL/ops/payment-reviewers.txt" 2>/dev/null \
		| sed 's/#.*//' | tr -d ' \t\r' | tr 'A-Z' 'a-z' | grep -E '^[a-z0-9-]{1,39}$' || true
}

# Puerta de revisión independiente (CM ítem 11, PCI 6.2.3).
check_payment_reviews() {
	local sha="$1" last base range trusted new reviewers c prs ok n author head r answer commits_raw owner prauthors reviews
	owner="$(echo "${REPO%%/*}" | tr 'A-Z' 'a-z')"
	last="$(last_deployed production)"
	if [ -n "$last" ]; then
		valid_sha "$last" || die "Registro de producción corrupto: '$last'"
		git -C "$REPO_ROOT" cat-file -e "$last^{commit}" 2>/dev/null || die "El último despliegue $last no existe en este clon (git fetch)"
		git -C "$REPO_ROOT" merge-base --is-ancestor "$last" "$sha" || die "$sha no desciende del último despliegue $last (para volver atrás usa: ops/deploy.sh rollback <sha>)"
		base="$last"
	else
		# Primer despliegue: se revisa todo lo posterior al génesis (ver REVIEW_GENESIS).
		valid_sha "$REVIEW_GENESIS" || die "REVIEW_GENESIS inválido"
		git -C "$REPO_ROOT" cat-file -e "$REVIEW_GENESIS^{commit}" 2>/dev/null || die "REVIEW_GENESIS no existe en este clon (git fetch)"
		git -C "$REPO_ROOT" merge-base --is-ancestor "$REVIEW_GENESIS" "$sha" || die "$sha no desciende de REVIEW_GENESIS"
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
		prs="$(gh api "repos/$REPO/commits/$c/pulls" --jq '.[] | select(.merged_at != null and .base.ref == "main") | "\(.number) \(.user.login)"')" \
			|| die "No se pudo consultar los PR del commit $c (gh auth status)"
		[ -n "$prs" ] || die "Commit $c sin PR fusionado en main (push directo)"
		ok=false
		while read -r n author; do
			[ -n "$n" ] || continue
			head="$(gh api "repos/$REPO/pulls/$n" --jq .head.sha)" || die "No se pudo consultar el PR #$n"
			# Quien aprueba no puede haber escrito commits del PR (ni abrirlo).
			prauthors="$(gh api "repos/$REPO/pulls/$n/commits" --paginate --jq '.[] | ((.author.login // "") , (.committer.login // ""))' | tr 'A-Z' 'a-z')" \
				|| die "No se pudo consultar los commits del PR #$n"
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

verify_target() {
	local target="$1" host="$2" path="$3" baseline
	if [ "$target" = production ]; then
		baseline="wp aralia baseline check"
	else
		baseline="if wp cli has-command 'aralia baseline'; then wp aralia baseline check; else echo 'aviso: baseline check aún no existe (fase 2)'; fi"
	fi
	on_target "$host" "cd '$path' && wp core verify-checksums && wp plugin verify-checksums ${THIRD_PARTY_PLUGINS[*]} && $baseline"
}

DEPLOY_TMP=""
cleanup() { [ -n "$DEPLOY_TMP" ] && rm -rf "$DEPLOY_TMP"; return 0; }

# Simulación, confirmación, copia, verificación y registro (compartido por deploy y rollback).
apply_release() {
	local target="$1" sha="$2" tmp="$3" host="$4" path="$5" kind="$6" confirm="$7" answer nchanges ndel
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
	verify_target "$target" "$host" "$path" \
		|| die "Verificación fallida en $target. No queda registrado. Si es producción: activa el modo mantenimiento de pagos y vuelve a un SHA bueno con ops/deploy.sh rollback <sha> (ops/runbooks/deploy.md)"
	mkdir -p "$LOCAL_LOG_DIR/releases" && chmod 700 "$LOCAL_LOG_DIR"
	if [ "$target" = production ] && [ "$kind" = verified ]; then
		cp "$tmp/aralia-$sha.tar.gz" "$tmp/aralia-$sha.tar.gz.sha256" "$LOCAL_LOG_DIR/releases/"
	fi
	printf '%s %s %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$sha" "$(git config user.email || whoami)" "$kind" >> "$(deploy_log "$target")"
	info "Desplegado y verificado en $target ($kind): $sha"
}

deploy() {
	local target="$1" sha="$2" host path tmp
	valid_sha "$sha" || die "Uso: ops/deploy.sh $target <sha de 40 caracteres>"
	load_conf
	if [ "$target" = production ]; then host="$PRODUCTION_SSH"; path="$PRODUCTION_PATH"; else host="$STAGING_SSH"; path="$STAGING_PATH"; fi
	command -v gh >/dev/null || die "Falta gh (GitHub CLI): brew install gh && gh auth login"

	check_source "$sha" "$target"
	if [ "$target" = production ]; then check_production_prereqs
	elif [ "$STAGING_SSH" = "$PRODUCTION_SSH" ] || [ "$(ssh_identity "$STAGING_SSH")" = "$(ssh_identity "$PRODUCTION_SSH")" ]; then
		info "AVISO: staging y producción usan la misma cuenta SSH: el código de staging puede leer los secretos de producción (ops/compliance/accounts-2fa.md)."
	fi
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
	load_conf
	grep -q " $sha " "$(deploy_log production)" 2>/dev/null || die "$sha no figura en el registro de producción: solo se puede volver a un SHA ya desplegado"
	rel="$LOCAL_LOG_DIR/releases"
	[ -f "$rel/aralia-$sha.tar.gz" ] && [ -f "$rel/aralia-$sha.tar.gz.sha256" ] || die "No hay paquete guardado de $sha en $rel"
	DEPLOY_TMP="$(mktemp -d)"; tmp="$DEPLOY_TMP"; trap cleanup EXIT
	cp "$rel/aralia-$sha.tar.gz" "$rel/aralia-$sha.tar.gz.sha256" "$tmp/"
	unpack_release "$sha" "$tmp"
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
