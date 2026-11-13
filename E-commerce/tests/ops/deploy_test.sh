#!/usr/bin/env bash
# Pruebas de ops/deploy.sh contra un "servidor" local (sin SSH). Corre en CI (job node) y en macOS.
#   bash tests/ops/deploy_test.sh
# shellcheck disable=SC2034  # variables que leen las funciones de deploy.sh cargadas con source
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export ARALIA_DEPLOY_LIB=1
# shellcheck source=../../ops/deploy.sh
. "$HERE/../../ops/deploy.sh"

T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
fails=0
pass() { printf '  ok   %s\n' "$1"; }
fail() { printf '  FAIL %s\n' "$1"; fails=$((fails + 1)); }
expect_file()    { if [ -e "$1" ]; then pass "$2"; else fail "$2"; fi; }
expect_no_file() { if [ ! -e "$1" ]; then pass "$2"; else fail "$2"; fi; }
expect_content() { if [ "$(cat "$1" 2>/dev/null)" = "$2" ]; then pass "$3"; else fail "$3"; fi; }
expect_fail()    { if ( "$@" ) >/dev/null 2>&1; then fail "debe fallar: $*"; else pass "falla como debe: ${*:1:2}"; fi; }

# --- Paquete de origen (lo que produce `package`) -------------------------------------------------------
SRC="$T/src"
mkdir -p "$SRC/wp/wp-admin" "$SRC/wp/wp-includes" "$SRC/wp-content/plugins/woocommerce" \
	"$SRC/wp-content/plugins/aralia-core" "$SRC/wp-content/themes/aralia" \
	"$SRC/wp-content/mu-plugins/aralia-wompi-guard"
echo new > "$SRC/wp/wp-load.php"
echo new > "$SRC/wp/index.php"
echo new > "$SRC/wp/wp-admin/admin.php"
echo new > "$SRC/wp/wp-includes/version.php"
echo new > "$SRC/wp-content/plugins/woocommerce/woocommerce.php"
echo new > "$SRC/wp-content/plugins/aralia-core/aralia-core.php"
echo new > "$SRC/wp-content/themes/aralia/style.css"
echo new > "$SRC/wp-content/mu-plugins/aralia-secrets-loader.php"
echo new > "$SRC/wp-content/mu-plugins/aralia-wompi-guard/guard.php"

# --- "Servidor" con todo lo que NO es del repo ---------------------------------------------------------
DST="$T/public_html"
mkdir -p "$DST/wp-admin" "$DST/wp-includes" "$DST/.well-known/acme-challenge" \
	"$DST/wp-content/uploads/2026" "$DST/wp-content/wflogs" "$DST/wp-content/litespeed" \
	"$DST/wp-content/languages" "$DST/wp-content/updraft" "$DST/wp-content/plugins/updraftplus" \
	"$DST/wp-content/plugins/woocommerce" "$DST/wp-content/plugins/aralia-core" \
	"$DST/wp-content/mu-plugins/aralia-wompi-guard"
for f in wp-config.php .htaccess .user.ini wordfence-waf.php robots.txt .well-known/acme-challenge/tok \
	wp-content/uploads/2026/foto.jpg wp-content/wflogs/config.php wp-content/litespeed/x.css \
	wp-content/languages/es_CO.mo wp-content/updraft/backup.zip wp-content/plugins/updraftplus/updraftplus.php \
	wp-content/advanced-cache.php wp-content/object-cache.php wp-content/mu-plugins/hostinger-tools.php; do
	echo keep > "$DST/$f"
done
echo old > "$DST/wp-admin/admin.php"
echo stale > "$DST/wp-includes/removed-in-new-version.php"
echo stale > "$DST/wp-content/plugins/woocommerce/old-file.php"
echo stale > "$DST/wp-content/plugins/aralia-core/old-class.php"
echo stale > "$DST/wp-content/mu-plugins/aralia-wompi-guard/old.php"

echo "sync_release: simulación (-n) no cambia nada"
sync_release "$SRC" "" "$DST" -n > /dev/null
expect_content "$DST/wp-admin/admin.php" old "la simulación no escribe"
expect_file "$DST/wp-includes/removed-in-new-version.php" "la simulación no borra"

echo "sync_release: despliegue real"
sync_release "$SRC" "" "$DST" > /dev/null
expect_content "$DST/wp-admin/admin.php" new "actualiza wp-admin"
expect_content "$DST/wp-content/plugins/aralia-core/aralia-core.php" new "actualiza aralia-core"
expect_content "$DST/wp-content/mu-plugins/aralia-wompi-guard/guard.php" new "actualiza el guard"
expect_content "$DST/wp-content/themes/aralia/style.css" new "crea el tema"
expect_no_file "$DST/wp-includes/removed-in-new-version.php" "limpia archivos viejos del núcleo"
expect_no_file "$DST/wp-content/plugins/woocommerce/old-file.php" "limpia archivos viejos de un plugin fijado"
expect_no_file "$DST/wp-content/plugins/aralia-core/old-class.php" "limpia archivos viejos de aralia-core"
expect_no_file "$DST/wp-content/mu-plugins/aralia-wompi-guard/old.php" "limpia archivos viejos del guard"
for f in wp-config.php .htaccess .user.ini wordfence-waf.php robots.txt .well-known/acme-challenge/tok \
	wp-content/uploads/2026/foto.jpg wp-content/wflogs/config.php wp-content/litespeed/x.css \
	wp-content/languages/es_CO.mo wp-content/updraft/backup.zip wp-content/plugins/updraftplus/updraftplus.php \
	wp-content/advanced-cache.php wp-content/object-cache.php wp-content/mu-plugins/hostinger-tools.php; do
	expect_content "$DST/$f" keep "conserva $f"
done

echo "sync_release: casos de abuso"
expect_fail sync_release "$SRC" "" "/"
expect_fail sync_release "$SRC" "" ""
mkdir -p "$T/not-wordpress"
expect_fail sync_release "$SRC" "" "$T/not-wordpress"       # sin wp-config.php: ruta equivocada
mkdir -p "$T/bad-src"
expect_fail sync_release "$T/bad-src" "" "$DST"             # paquete sin wp/
expect_no_file "$T/not-wordpress/wp-admin" "no escribe en una ruta que no es WordPress"

echo "valid_sha"
if valid_sha "0123456789abcdef0123456789abcdef01234567"; then pass "acepta SHA de 40"; else fail "acepta SHA de 40"; fi
for bad in "" "abc" "0123456789ABCDEF0123456789ABCDEF01234567" "0123456789abcdef0123456789abcdef01234567; rm -rf /" "../../etc"; do
	if valid_sha "$bad"; then fail "rechaza '$bad'"; else pass "rechaza '${bad:0:20}'"; fi
done

echo "package_release"
PKG="$T/pkg"
mkdir -p "$PKG/ops" "$PKG/wp" "$PKG/wp-content/plugins" "$PKG/wp-content/mu-plugins" "$PKG/wp-content/themes"
echo x > "$PKG/wp/wp-load.php"; echo secret > "$PKG/wp/wp-config.php"
for p in "${THIRD_PARTY_PLUGINS[@]}"; do mkdir -p "$PKG/wp-content/plugins/$p"; echo x > "$PKG/wp-content/plugins/$p/$p.php"; done
mkdir -p "$PKG/wp-content/plugins/updraftplus"; echo x > "$PKG/wp-content/plugins/updraftplus/u.php"
echo x > "$PKG/wp-content/mu-plugins/aralia-secrets-loader.php"
echo x > "$PKG/wp-content/mu-plugins/other-vendor.php"
SHA=0123456789abcdef0123456789abcdef01234567
# shellcheck disable=SC2034  # PROJECT_DIR lo lee package_release
( PROJECT_DIR="$PKG"; package_release "$SHA" > /dev/null )
LIST="$(tar -tzf "$PKG/release/aralia-$SHA.tar.gz")"
has_entry()  { if grep -q "$1" <<<"$LIST"; then pass "$2"; else fail "$2"; fi; }
lacks_entry() { if grep -q "$1" <<<"$LIST"; then fail "$2"; else pass "$2"; fi; }
has_entry   'wp-content/plugins/woocommerce/woocommerce.php' "incluye plugins fijados"
has_entry   'aralia-secrets-loader.php' "incluye el cargador de secretos"
lacks_entry 'wp-config.php' "excluye wp-config.php"
lacks_entry 'updraftplus' "excluye plugins fuera de la lista"
lacks_entry 'other-vendor.php' "excluye mu-plugins ajenos"
if ( cd "$PKG/release" && [ "$(cut -d' ' -f1 < "aralia-$SHA.tar.gz.sha256")" = "$(sha256_of "aralia-$SHA.tar.gz")" ] ); then
	pass "SHA-256 correcto"; else fail "SHA-256 correcto"; fi
echo leaked > "$PKG/wp-content/mu-plugins/aralia-secrets.php"
expect_fail env PROJECT_DIR="$PKG" bash -c "ARALIA_DEPLOY_LIB=1; . '$HERE/../../ops/deploy.sh'; PROJECT_DIR='$PKG'; package_release $SHA"
expect_fail package_release "not-a-sha"

echo

# --- sync_release: un fallo de rsync a mitad NO debe pasar desapercibido -------------------------------
echo "sync_release: errores de rsync"
FAILDST="$T/failsite"; mkdir -p "$FAILDST/wp-admin" "$FAILDST/wp-includes"; echo keep > "$FAILDST/wp-config.php"
rsync() {
	case "$1" in
		--version) echo "rsync  version 3.5.0  protocol version 32" ;;
		*) case "${!#}" in */wp-includes/) return 23 ;; *) command rsync "$@" ;; esac ;;   # falla solo la copia de wp-includes/
	esac
}
# En contexto `if` (igual que `sync_release ... || die` en deploy) bash ignora set -e: cada rsync debe devolver || return 1.
if sync_release "$SRC" "" "$FAILDST" -n >/dev/null 2>&1; then fail "rsync que falla a mitad debía devolver error"; else pass "rsync que falla a mitad devuelve error (simulación)"; fi
if sync_release "$SRC" "" "$FAILDST" >/dev/null 2>&1; then fail "rsync que falla a mitad debía devolver error (real)"; else pass "rsync que falla a mitad devuelve error (despliegue real)"; fi
unset -f rsync

# --- parse_conf: deploy.conf no se ejecuta, se valida ---------------------------------------------------
echo "parse_conf"
conf() { printf '%s\n' "$@" > "$T/c.conf"; }
good=('# comentario' '' 'REPO="x/y"' 'STAGING_SSH="aralia-staging"' 'STAGING_PATH="/home/u2/domains/s/public_html"' 'PRODUCTION_SSH="aralia-prod"' 'PRODUCTION_PATH="/home/u1/domains/p/public_html"')
conf "${good[@]}"
if ( parse_conf "$T/c.conf" && [ "$REPO" = "x/y" ] && [ "$STAGING_SSH" = aralia-staging ] ) >/dev/null 2>&1; then pass "acepta una configuración válida"; else fail "acepta una configuración válida"; fi
conf "${good[@]}" '. /tmp/evil.sh';                       expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'check_payment_reviews() { :; }';       expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'EVIL="1"';                             expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'PRODUCTION_PATH="/a b; rm -rf /"';     expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'PRODUCTION_SSH="-oProxyCommand=x"';    expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'ALLOW_SHARED_STAGING="no"';            expect_fail parse_conf "$T/c.conf"
conf 'REPO="x/y"';                                        expect_fail parse_conf "$T/c.conf"

# --- verify_own_code: el paquete debe ser idéntico a git, sin extras ------------------------------------
echo "verify_own_code"
V="$T/vrepo"; mkdir -p "$V/E-commerce/wp-content/themes/aralia" "$V/E-commerce/wp-content/mu-plugins" "$V/E-commerce/wp-content/plugins/aralia-core/src"
git -C "$V" init -q -b main; git -C "$V" config user.email t@example.com; git -C "$V" config user.name t; git -C "$V" config commit.gpgsign false
echo 'body{}' > "$V/E-commerce/wp-content/themes/aralia/style.css"
echo '<?php // loader' > "$V/E-commerce/wp-content/mu-plugins/aralia-secrets-loader.php"
echo '<?php // guard' > "$V/E-commerce/wp-content/mu-plugins/aralia-wompi-guard.php"
echo '<?php // core' > "$V/E-commerce/wp-content/plugins/aralia-core/aralia-core.php"
echo '<?php // plugin' > "$V/E-commerce/wp-content/plugins/aralia-core/src/Plugin.php"
git -C "$V" add -A && git -C "$V" commit -q -m v && VS="$(git -C "$V" rev-parse HEAD)"
mkpkg() { # $1 = directorio de trabajo; crea $1/x como copia fiel del árbol de git
	rm -rf "$1"; mkdir -p "$1/x/wp-content/plugins" "$1/x/wp-content/themes" "$1/x/wp-content/mu-plugins" "$1/x/wp-content/plugins/woocommerce"
	cp -R "$V/E-commerce/wp-content/themes/aralia" "$1/x/wp-content/themes/"
	cp -R "$V/E-commerce/wp-content/plugins/aralia-core" "$1/x/wp-content/plugins/"
	cp "$V/E-commerce/wp-content/mu-plugins/"*.php "$1/x/wp-content/mu-plugins/"
	echo '<?php // tercero' > "$1/x/wp-content/plugins/woocommerce/woocommerce.php"
}
REPO_ROOT="$V"
voc_pass() { mkpkg "$T/pk"; [ -z "${2:-}" ] || ( eval "$2" ); if ( verify_own_code "$VS" "$T/pk" ) >"$T/voc.out" 2>&1; then pass "$1"; else fail "$1 (debía pasar): $(tail -1 "$T/voc.out")"; fi; }
voc_deny() { mkpkg "$T/pk"; ( cd "$T/pk/x" && eval "$2" ); if ( verify_own_code "$VS" "$T/pk" ) >"$T/voc.out" 2>&1; then fail "$1 (debía bloquear)"; else pass "$1"; fi; }
voc_pass "paquete idéntico a git"
voc_deny "mu-plugin extra aralia-evil.php"                  'echo "<?php evil();" > wp-content/mu-plugins/aralia-evil.php'
voc_deny "cargador de secretos alterado"                    'echo "<?php evil();" >> wp-content/mu-plugins/aralia-secrets-loader.php'
voc_deny "tema alterado"                                    'echo "x" >> wp-content/themes/aralia/style.css'
voc_deny "archivo PHP extra en el tema"                     'echo "<?php evil();" > wp-content/themes/aralia/functions.php'
voc_deny "build/ con PHP en el tema"                        'mkdir wp-content/themes/aralia/build && echo "<?php evil();" > wp-content/themes/aralia/build/x.php'
voc_deny "build/ anidado en src/ (antes se excluía a cualquier profundidad)" 'mkdir wp-content/plugins/aralia-core/src/build && echo "<?php evil();" > wp-content/plugins/aralia-core/src/build/e.php'
voc_deny "plugin extra fuera de la lista cerrada"           'mkdir wp-content/plugins/evil && echo "<?php" > wp-content/plugins/evil/evil.php'
voc_deny "tema extra"                                       'mkdir wp-content/themes/evil && echo "body{}" > wp-content/themes/evil/style.css'
voc_deny "enlace simbólico en el paquete"                   'ln -s /etc/passwd wp-content/themes/aralia/link.php'
voc_deny "aralia-core ausente en git pero presente en el paquete" 'mkdir -p wp-content/plugins/aralia-fake && true; mv wp-content/plugins/aralia-fake wp-content/plugins/aralia-core-x'
voc_pass "build/ con JS y asset.php de una línea es válido"  'mkdir "$T/pk/x/wp-content/plugins/aralia-core/build" && echo "console.log(1)" > "$T/pk/x/wp-content/plugins/aralia-core/build/index.js" && printf "%s\n" "<?php return array(\"dependencies\" => array(), \"version\" => \"1\");" > "$T/pk/x/wp-content/plugins/aralia-core/build/index.asset.php"'

# --- check_payment_reviews: puerta de revisión humana (con `gh` simulado y un repo git temporal) -----------
echo "check_payment_reviews"
R="$T/repo"; mkdir -p "$R/E-commerce/ops" "$R/E-commerce/specs" "$R/E-commerce/wp-content/themes/aralia"
git -C "$R" init -q -b main
git -C "$R" config user.email t@example.com; git -C "$R" config user.name t; git -C "$R" config commit.gpgsign false
commit_all() { git -C "$R" add -A && git -C "$R" commit -q -m "$1" && git -C "$R" rev-parse HEAD; }
printf '# vacía\n' > "$R/E-commerce/ops/payment-reviewers.txt"; echo a > "$R/E-commerce/specs/a.md"
C0="$(commit_all base)"
echo v1 > "$R/E-commerce/wp-content/themes/aralia/style.css"
CA="$(commit_all 'tema v1 (lista vacía)')"
printf 'Carol\n' > "$R/E-commerce/ops/payment-reviewers.txt"; echo v2 > "$R/E-commerce/wp-content/themes/aralia/style.css"
CB="$(commit_all 'tema v2 + alta de carol')"
printf 'carol\nmallory\n' > "$R/E-commerce/ops/payment-reviewers.txt"; echo v3 > "$R/E-commerce/wp-content/themes/aralia/style.css"
CC="$(commit_all 'alta de mallory + tema v3')"
echo b > "$R/E-commerce/specs/a.md"
CD="$(commit_all 'solo docs')"
printf 'dave\n' > "$R/E-commerce/ops/payment-reviewers.txt"; echo v4 > "$R/E-commerce/wp-content/themes/aralia/style.css"
CF="$(commit_all 'carol revocada, dave alta')"
printf 'x\n' > "$R/E-commerce/ops/payment-reviewers.txt"; echo v5 > "$R/E-commerce/wp-content/themes/aralia/style.css"
CE="$(commit_all 'la dueña como revisora')"

REPO_ROOT="$R"; PROJECT_DIR="$R/E-commerce"; REPO="x/y"; LOCAL_LOG_DIR="$T/home"; mkdir -p "$LOCAL_LOG_DIR"
REVIEW_GENESIS="$C0"
GH_PRS="12 sara"; GH_HEAD="headsha"; GH_REVIEWS=""; GH_COMMIT_LOGINS="sara"; GH_NOPR_FOR="none"
gh() {
	case "$*" in
		*"/commits/"*"/pulls"*) case "$*" in *"$GH_NOPR_FOR"*) ;; *) [ -n "$GH_PRS" ] && echo "$GH_PRS" || true ;; esac ;;
		*"/reviews"*)           printf '%s' "$GH_REVIEWS" | awk '{ $1=tolower($1); print }' ;;  # imita ascii_downcase del --jq
		*"/pulls/"*"/commits"*) printf '%s\n' "$GH_COMMIT_LOGINS" ;;
		*"/pulls/"*)            echo "$GH_HEAD" ;;
	esac
}
set_last() { rm -f "$LOCAL_LOG_DIR/production.log"; [ -z "${1:-}" ] || echo "2026-01-01T00:00:00Z $1 sara verified" > "$LOCAL_LOG_DIR/production.log"; }
gate()      { ( check_payment_reviews "$1" <<<"${2:-}" ) >"$T/gate.out" 2>&1; }
gate_pass() { if gate "$2" "${3:-}"; then pass "$1"; else fail "$1 (debía pasar): $(tail -1 "$T/gate.out")"; fi; }
gate_deny() { if gate "$2" "${3:-}"; then fail "$1 (debía bloquear)"; else pass "$1"; fi; }

set_last ""
GH_REVIEWS=""
gate_deny "lista vacía y cambios sin revisar: producción bloqueada" "$CA"
GH_PRS=""; GH_REVIEWS=$'carol APPROVED headsha\n'
gate_deny "push directo (sin PR fusionado en main): bloquea" "$CB" "ALTA-REVISORA"
GH_PRS="12 sara"
gate_pass "primer despliegue: ALTA-REVISORA y aprobación vigente" "$CB" "ALTA-REVISORA"
gate_deny "primer despliegue sin confirmación escrita: bloquea" "$CB" "no"
GH_REVIEWS=$'sara APPROVED headsha\n'
gate_deny "la autora no puede aprobar su propio PR" "$CB" "ALTA-REVISORA"
GH_REVIEWS=$'carol APPROVED oldsha\n'
gate_deny "aprobación sobre un commit anterior: bloquea" "$CB" "ALTA-REVISORA"
GH_REVIEWS=$'carol APPROVED headsha\ncarol CHANGES_REQUESTED headsha\n'
gate_deny "aprobación revocada por CHANGES_REQUESTED posterior: bloquea" "$CB" "ALTA-REVISORA"
GH_REVIEWS=$'carol CHANGES_REQUESTED headsha\ncarol APPROVED headsha\n'
gate_pass "CHANGES_REQUESTED seguido de APPROVED: pasa" "$CB" "ALTA-REVISORA"
GH_REVIEWS=$'carol APPROVED headsha\n'; GH_COMMIT_LOGINS=$'sara\ncarol'
gate_deny "la revisora escribió commits del PR: no cuenta como revisión independiente" "$CB" "ALTA-REVISORA"
GH_COMMIT_LOGINS="sara"
# Génesis: un commit anterior sin PR (landing) no se revisa; el resto sí
GH_NOPR_FOR="$C0"
gate_pass "REVIEW_GENESIS: el commit raíz sin PR queda fuera de la revisión" "$CB" "ALTA-REVISORA"
GH_NOPR_FOR="$CA"
gate_deny "un commit posterior al génesis sin PR sí bloquea" "$CB" "ALTA-REVISORA"
GH_NOPR_FOR="none"
SAVE_G="$REVIEW_GENESIS"; REVIEW_GENESIS="nosha"
gate_deny "REVIEW_GENESIS inválido: bloquea" "$CB" "ALTA-REVISORA"
REVIEW_GENESIS="$SAVE_G"

# H-2: una cuenta añadida en el mismo PR no puede aprobarse a sí misma
set_last "$CB"
GH_REVIEWS=$'mallory APPROVED headsha\n'
gate_deny "H-2: mallory (alta en el mismo PR) no es revisora de confianza" "$CC"
GH_REVIEWS=$'carol APPROVED headsha\n'
gate_pass "carol (de la lista del último despliegue) aprueba sin preguntas" "$CC"
GH_REVIEWS=$'CAROL APPROVED headsha\n'
gate_pass "los logins se comparan sin distinguir mayúsculas" "$CC"

# N3: revocar a una revisora surte efecto en el mismo despliegue
GH_REVIEWS=$'carol APPROVED headsha\n'
gate_deny "N3: carol revocada en el commit a desplegar ya no aprueba" "$CF"
# N4: el alta inicial solo existe en el primer despliegue
set_last "$CA"
GH_REVIEWS=$'carol APPROVED headsha\n'
gate_deny "N4: con último despliegue y lista vacía no se reabre ALTA-REVISORA" "$CB" "ALTA-REVISORA"
# N8: la dueña del repositorio no puede ser la revisora independiente
set_last ""
GH_REVIEWS=$'x APPROVED headsha\n'
gate_deny "N8: la dueña (x) en la lista no cuenta como revisora" "$CE" "ALTA-REVISORA"

# Solo documentación exenta: no exige PR
set_last "$CC"; GH_PRS=""; GH_REVIEWS=""
gate_pass "cambio solo de specs/: exento de revisión" "$CD"

# M-1: la puerta no falla abierta si el último despliegue no es alcanzable
set_last "0123456789abcdef0123456789abcdef01234567"; GH_PRS="12 sara"; GH_REVIEWS=$'carol APPROVED headsha\n'
gate_deny "último despliegue desconocido en este clon: bloquea" "$CC"
set_last "$CC"
gate_deny "el SHA a desplegar no desciende del último despliegue: bloquea" "$CA"

if [ "$fails" -eq 0 ]; then echo "deploy_test: todo OK"; else echo "deploy_test: $fails fallo(s)"; exit 1; fi
