#!/usr/bin/env bash
# Despliegue de Aralía (research R-15, enmienda de la constitución 1.1.0).
#
#   ops/deploy.sh package    <sha>   # lo usa el CI: arma release/aralia-<sha>.tar.gz (+ .sha256)
#   ops/deploy.sh staging    <sha>   # Sara, desde su equipo
#   ops/deploy.sh production <sha>   # Sara, desde su equipo
#
# Por qué local y no en GitHub Actions: con el plan Free en un repo privado no hay environments protegidos
# ni protección de rama, así que una llave SSH guardada en GitHub quedaría al alcance de cualquier rama.
# La llave vive solo en el equipo de Sara (ssh-agent) y la "aprobación manual" es que ella ejecute esto.
#
# Antes de copiar un solo archivo, el script exige:
#   1. que <sha> esté en origin/main y que este script sea idéntico al de <sha>;
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
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"     # E-commerce/
PROJECT_REL="E-commerce"
REPO_ROOT="$(cd "$PROJECT_DIR/.." && pwd)"
# Compatible con el bash 3.2 de macOS (sin mapfile ni arrays asociativos).

# Plugins de terceros que se despliegan (lista cerrada, R-15). UpdraftPlus Premium se instala a mano y no se toca.
THIRD_PARTY_PLUGINS=(woocommerce wompi-portal-de-pagos wordfence litespeed-cache)

# Revisión obligatoria para TODO el repo salvo documentación que no se despliega ni controla el despliegue.
# (Criterio invertido tras la revisión T0 del 2026-11-13: una lista de "rutas de pago" se desalineaba de CODEOWNERS
# y en WordPress todo el PHP corre en el mismo proceso. Lo nuevo queda protegido por defecto.)
REVIEW_EXEMPT=(
	":(exclude)$PROJECT_REL/specs"
	":(exclude)$PROJECT_REL/ops/runbooks"
	":(exclude)$PROJECT_REL/ops/compliance"
	":(exclude)$PROJECT_REL/ops/inventory.md"
)

# Registro de despliegues: EN EL EQUIPO DE SARA, no en el servidor (código desplegado en staging o producción
# podría escribir en un registro del servidor y falsear "último despliegue").
LOCAL_LOG_DIR="${ARALIA_DEPLOY_HOME:-$HOME/.aralia-deploy}"

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
	on_target "$host" "mkdir -p '$path/wp-content/plugins' '$path/wp-content/themes' '$path/wp-content/mu-plugins'"

	# --checksum: compara contenido, no tamaño+hora (un archivo con igual tamaño y hora no se saltaría).
	local -a R=(rsync -rlptz --checksum --chmod='D755,F644' --itemize-changes)
	[ "$dry" = "-n" ] && R+=(-n)
	export RSYNC_RSH="ssh -o StrictHostKeyChecking=yes"

	# Núcleo: wp-admin y wp-includes son de WordPress (se limpian); los archivos sueltos de la raíz, sin --delete.
	"${R[@]}" --delete "$src/wp/wp-admin/"    "$dest/wp-admin/"
	"${R[@]}" --delete "$src/wp/wp-includes/" "$dest/wp-includes/"
	"${R[@]}" --exclude='/wp-admin/' --exclude='/wp-includes/' --exclude='/wp-content/' \
		--exclude='/wp-config.php' --exclude='/.htaccess' --exclude='/.user.ini' \
		"$src/wp/" "$dest/"

	local d n
	for d in "$src"/wp-content/plugins/*/ "$src"/wp-content/themes/*/; do
		[ -d "$d" ] || continue
		n="${d#"$src"/}"
		"${R[@]}" --delete "$d" "$dest/$n"
	done
	for d in "$src"/wp-content/mu-plugins/aralia-*; do
		[ -e "$d" ] || continue
		n="wp-content/mu-plugins/$(basename "$d")"
		if [ -d "$d" ]; then "${R[@]}" --delete "$d/" "$dest/$n/"; else "${R[@]}" "$d" "$dest/$n"; fi
	done
}

# ---------------------------------------------------------------------------------------------- deploy

load_conf() {
	local conf="$SCRIPT_DIR/deploy.conf"
	[ -f "$conf" ] || die "Falta ops/deploy.conf (copiar de ops/deploy.conf.sample)"
	# shellcheck source=/dev/null
	. "$conf"
	: "${REPO:?}" "${STAGING_SSH:?}" "${STAGING_PATH:?}" "${PRODUCTION_SSH:?}" "${PRODUCTION_PATH:?}"
}

deploy_log() { echo "$LOCAL_LOG_DIR/$1.log"; }

# Último SHA registrado para un destino (vacío si no hay). Valida que sea alcanzable desde el SHA a desplegar.
last_deployed() {
	local target="$1" f; f="$(deploy_log "$target")"
	[ -f "$f" ] && tail -n 1 "$f" | cut -d' ' -f2 || true
}

check_source() {
	local sha="$1"
	git -C "$PROJECT_DIR" fetch --quiet origin main
	git -C "$PROJECT_DIR" merge-base --is-ancestor "$sha" origin/main || die "$sha no está en origin/main"
	git -C "$PROJECT_DIR" show "$sha:$PROJECT_REL/ops/deploy.sh" | cmp -s - "$SCRIPT_DIR/deploy.sh" \
		|| die "ops/deploy.sh local difiere del de $sha: ejecuta el script de ese commit"
}

# El código propio del paquete debe ser idéntico al del árbol de git en <sha> (el CI genera el paquete y su
# SHA-256, así que el hash solo prueba integridad del transporte, no autenticidad).
verify_own_code() {
	local sha="$1" dir="$2" rel d name n=0
	mkdir -p "$dir/git"
	for rel in wp-content/plugins/aralia-core wp-content/themes/aralia; do
		git -C "$REPO_ROOT" cat-file -e "$sha:$PROJECT_REL/$rel" 2>/dev/null || continue
		git -C "$REPO_ROOT" archive "$sha" "$PROJECT_REL/$rel" | tar -x -C "$dir/git"
		diff -r -x blocks -x build -x node_modules -x .gitkeep "$dir/git/$PROJECT_REL/$rel" "$dir/x/$rel" >/dev/null \
			|| die "El paquete difiere del árbol de git en $rel (¿workflow o build alterado?)"
		n=$((n+1))
	done
	for name in $(git -C "$REPO_ROOT" ls-tree --name-only "$sha" "$PROJECT_REL/wp-content/mu-plugins/" 2>/dev/null | xargs -n1 basename 2>/dev/null | grep '^aralia-' || true); do
		case "$name" in aralia-secrets*) continue ;; esac
		git -C "$REPO_ROOT" archive "$sha" "$PROJECT_REL/wp-content/mu-plugins/$name" | tar -x -C "$dir/git"
		diff -r "$dir/git/$PROJECT_REL/wp-content/mu-plugins/$name" "$dir/x/wp-content/mu-plugins/$name" >/dev/null \
			|| die "El paquete difiere del árbol de git en mu-plugins/$name"
		n=$((n+1))
	done
	info "Código propio del paquete idéntico al de git ($n componentes comparados)"
}

fetch_release() {
	local sha="$1" dir="$2" run_id
	command -v gh >/dev/null || die "Falta gh (GitHub CLI): brew install gh && gh auth login"
	run_id="$(gh run list -R "$REPO" --workflow ecommerce-ci.yml --commit "$sha" --branch main --event push \
		--json databaseId,conclusion --jq 'map(select(.conclusion=="success"))[0].databaseId // empty')"
	[ -n "$run_id" ] || die "No hay un CI en verde para $sha en main"
	gh run download "$run_id" -R "$REPO" -n "release-$sha" -D "$dir" >/dev/null
	local expected actual
	expected="$(cut -d' ' -f1 < "$dir/aralia-$sha.tar.gz.sha256")"
	actual="$(sha256_of "$dir/aralia-$sha.tar.gz")"
	[ "$expected" = "$actual" ] || die "SHA-256 del paquete no coincide"
	mkdir -p "$dir/x" && tar -xzf "$dir/aralia-$sha.tar.gz" -C "$dir/x"
	grep -qx "sha=$sha" "$dir/x/MANIFEST" || die "El MANIFEST no corresponde a $sha"
	verify_own_code "$sha" "$dir"
	info "CI $run_id en verde; paquete verificado"
}

# Lista de revisoras (logins en minúscula, una por línea) tal como está en un commit.
reviewers_at() {
	git -C "$PROJECT_DIR" show "$1:$PROJECT_REL/ops/payment-reviewers.txt" 2>/dev/null \
		| sed 's/#.*//' | tr -d ' \t\r' | grep -v '^$' | tr 'A-Z' 'a-z' || true
}

# Puerta de revisión independiente (CM ítem 11, PCI 6.2.3).
check_payment_reviews() {
	local sha="$1" last range reviewers new c prs ok n author head r answer commits_raw
	last="$(last_deployed production)"
	if [ -n "$last" ]; then
		valid_sha "$last" || die "Registro de producción corrupto: '$last'"
		git -C "$REPO_ROOT" cat-file -e "$last^{commit}" 2>/dev/null || die "El último despliegue $last no existe en este clon (git fetch)"
		git -C "$REPO_ROOT" merge-base --is-ancestor "$last" "$sha" || die "$sha no desciende del último despliegue $last"
		range="$last..$sha"
	else
		range="$sha"
	fi

	commits_raw="$(git -C "$REPO_ROOT" log --format=%H "$range" -- . "${REVIEW_EXEMPT[@]}")"
	local -a commits=()
	while IFS= read -r c; do [ -n "$c" ] && commits+=("$c"); done <<<"$commits_raw"
	[ "${#commits[@]}" -eq 0 ] && { info "Sin cambios que exijan revisión desde el último despliegue"; return 0; }

	# La lista de confianza es la del último despliegue; nunca la del commit que se está revisando.
	if [ -n "$last" ]; then reviewers="$(reviewers_at "$last")"; else reviewers=""; fi
	new="$(reviewers_at "$sha")"
	if [ -z "$reviewers" ]; then
		[ -n "$new" ] || die "Hay ${#commits[@]} commit(s) por revisar y ops/payment-reviewers.txt está vacío (régimen transitorio): producción bloqueada hasta contratar a la revisora"
		info "No hay lista de revisoras de confianza previa. La lista propuesta en $sha es:"
		sed 's/^/    /' <<<"$new"
		read -r -p "Alta de revisora(s): escribe ALTA-REVISORA para aceptar ESTA lista como raíz de confianza: " answer
		[ "$answer" = "ALTA-REVISORA" ] || die "Cancelado"
		reviewers="$new"
	fi

	for c in "${commits[@]}"; do
		prs="$(gh api "repos/$REPO/commits/$c/pulls" --jq '.[] | select(.merged_at != null and .base.ref == "main") | "\(.number) \(.user.login)"')"
		[ -n "$prs" ] || die "Commit $c sin PR fusionado en main (push directo)"
		ok=false
		while read -r n author; do
			[ -n "$n" ] || continue
			head="$(gh api "repos/$REPO/pulls/$n" --jq .head.sha)"
			# Último estado de cada persona (las reseñas llegan en orden cronológico); solo cuenta APPROVED sobre el último commit.
			while read -r r; do
				[ -n "$r" ] || continue
				[ "$r" != "$(echo "$author" | tr 'A-Z' 'a-z')" ] && grep -qx "$r" <<<"$reviewers" && ok=true
			done < <(gh api "repos/$REPO/pulls/$n/reviews" --paginate \
					--jq '.[] | select(.state=="APPROVED" or .state=="CHANGES_REQUESTED" or .state=="DISMISSED") | "\(.user.login | ascii_downcase) \(.state) \(.commit_id)"' \
				| awk '{ s[$1]=$2" "$3 } END { for (u in s) print u" "s[u] }' \
				| awk -v h="$head" '$2=="APPROVED" && $3==h { print $1 }')
		done <<<"$prs"
		$ok || die "Commit $c: su PR no tiene aprobación vigente de una revisora de confianza sobre el último commit"
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

deploy() {
	local target="$1" sha="$2" host path tmp answer nchanges ndel
	valid_sha "$sha" || die "Uso: ops/deploy.sh $target <sha de 40 caracteres>"
	load_conf
	if [ "$target" = production ]; then host="$PRODUCTION_SSH"; path="$PRODUCTION_PATH"; else host="$STAGING_SSH"; path="$STAGING_PATH"; fi
	if [ "$STAGING_SSH" = "$PRODUCTION_SSH" ]; then
		info "AVISO: staging y producción usan la misma cuenta SSH ($STAGING_SSH). El código de staging corre con el mismo usuario que producción y puede leer sus secretos: usa una cuenta separada (ops/baseline-config.md H5)."
	fi

	check_source "$sha"
	DEPLOY_TMP="$(mktemp -d)"; tmp="$DEPLOY_TMP"; trap cleanup EXIT
	fetch_release "$sha" "$tmp"

	if [ "$target" = production ]; then
		grep -q " $sha " "$(deploy_log staging)" 2>/dev/null || die "$sha no se ha desplegado y verificado en staging (registro local)"
		command -v gh >/dev/null || die "Falta gh"
		check_payment_reviews "$sha"
	fi

	info "Simulación en $target ($host:$path):"
	sync_release "$tmp/x" "$host" "$path" -n > "$tmp/dry-run.txt" || die "La simulación falló (ruta, ssh o rsync); no se despliega nada"
	nchanges="$(grep -vc '^\.' "$tmp/dry-run.txt" || true)"
	ndel="$(grep -c '^\*deleting' "$tmp/dry-run.txt" || true)"
	grep -v '^\.' "$tmp/dry-run.txt" | head -n 40 || true
	info "$nchanges líneas de cambio, $ndel borrados. (Lista completa: $tmp/dry-run.txt; ábrela en otra terminal antes de confirmar)"

	if [ "$target" = production ]; then
		read -r -p "Escribe PRODUCCION ${sha:0:7} para continuar: " answer
		[ "$answer" = "PRODUCCION ${sha:0:7}" ] || die "Cancelado"
	else
		read -r -p "¿Desplegar en staging? (si/no): " answer
		[ "$answer" = "si" ] || die "Cancelado"
	fi

	sync_release "$tmp/x" "$host" "$path" > /dev/null \
		|| die "El despliegue falló a medias en $target: estado parcial. Si es producción, activa el modo mantenimiento de pagos y vuelve a desplegar el último SHA bueno (ops/runbooks/deploy.md)"
	if ! verify_target "$target" "$host" "$path"; then
		die "Verificación fallida en $target. No queda registrado. Si es producción: activar el modo mantenimiento de pagos y volver a desplegar el último SHA bueno (ops/runbooks/deploy.md)"
	fi
	mkdir -p "$LOCAL_LOG_DIR/releases" && chmod 700 "$LOCAL_LOG_DIR"
	if [ "$target" = production ]; then cp "$tmp/aralia-$sha.tar.gz" "$tmp/aralia-$sha.tar.gz.sha256" "$LOCAL_LOG_DIR/releases/"; fi
	printf '%s %s %s verified\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$sha" "$(git config user.email || whoami)" >> "$(deploy_log "$target")"
	info "Desplegado y verificado en $target: $sha"
}

main() {
	case "${1:-}" in
		package)            package_release "${2:-}" ;;
		staging|production) deploy "$1" "${2:-}" ;;
		*) die "Uso: ops/deploy.sh package|staging|production <sha>" ;;
	esac
}

# Las pruebas (tests/ops/deploy_test.sh) cargan las funciones sin ejecutar main.
if [ "${ARALIA_DEPLOY_LIB:-}" != 1 ]; then main "$@"; fi
