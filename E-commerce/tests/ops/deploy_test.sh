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

# --- Paquete de origen (lo que produce `package`: solo código propio) -------------------------------------
SRC="$T/src"
mkdir -p "$SRC/wp-content/plugins/aralia-core" "$SRC/wp-content/themes/aralia" "$SRC/wp-content/mu-plugins/aralia-wompi-guard"
echo new > "$SRC/wp-content/plugins/aralia-core/aralia-core.php"
echo new > "$SRC/wp-content/themes/aralia/style.css"
echo new > "$SRC/wp-content/mu-plugins/aralia-secrets-loader.php"
echo new > "$SRC/wp-content/mu-plugins/aralia-wompi-guard/guard.php"

# --- "Servidor" de Hostinger: WordPress, WooCommerce y demás los instala el panel; el script no debe tocarlos -----
DST="$T/public_html"
mkdir -p "$DST/wp-admin" "$DST/wp-includes" "$DST/.well-known/acme-challenge" \
	"$DST/wp-content/uploads/2026" "$DST/wp-content/wflogs" "$DST/wp-content/litespeed" \
	"$DST/wp-content/languages" "$DST/wp-content/updraft" "$DST/wp-content/plugins/updraftplus" \
	"$DST/wp-content/plugins/woocommerce" "$DST/wp-content/plugins/wompi-portal-de-pagos" "$DST/wp-content/plugins/aralia-core" \
	"$DST/wp-content/themes/twentytwentysix" "$DST/wp-content/mu-plugins/aralia-wompi-guard"
KEEP=(wp-config.php .htaccess .user.ini wordfence-waf.php robots.txt index.php wp-load.php wp-admin/admin.php wp-includes/version.php
	.well-known/acme-challenge/tok wp-content/uploads/2026/foto.jpg wp-content/wflogs/config.php wp-content/litespeed/x.css
	wp-content/languages/es_CO.mo wp-content/updraft/backup.zip wp-content/plugins/updraftplus/updraftplus.php
	wp-content/plugins/woocommerce/woocommerce.php wp-content/plugins/wompi-portal-de-pagos/wompi.php
	wp-content/themes/twentytwentysix/style.css wp-content/advanced-cache.php wp-content/object-cache.php
	wp-content/mu-plugins/hostinger-tools.php)
for f in "${KEEP[@]}"; do echo keep > "$DST/$f"; done
echo stale > "$DST/wp-content/plugins/aralia-core/old-class.php"
echo stale > "$DST/wp-content/mu-plugins/aralia-wompi-guard/old.php"

echo "sync_release: simulación (-n) no cambia nada"
sync_release "$SRC" "" "$DST" -n > /dev/null
expect_file "$DST/wp-content/plugins/aralia-core/old-class.php" "la simulación no borra"
expect_no_file "$DST/wp-content/themes/aralia/style.css" "la simulación no escribe"

echo "sync_release: despliegue real (solo código propio)"
sync_release "$SRC" "" "$DST" > /dev/null
expect_content "$DST/wp-content/plugins/aralia-core/aralia-core.php" new "actualiza aralia-core"
expect_content "$DST/wp-content/mu-plugins/aralia-wompi-guard/guard.php" new "actualiza el guard"
expect_content "$DST/wp-content/mu-plugins/aralia-secrets-loader.php" new "crea el cargador de secretos"
expect_content "$DST/wp-content/themes/aralia/style.css" new "crea el tema"
expect_no_file "$DST/wp-content/plugins/aralia-core/old-class.php" "limpia archivos viejos de aralia-core"
expect_no_file "$DST/wp-content/mu-plugins/aralia-wompi-guard/old.php" "limpia archivos viejos del guard"
for f in "${KEEP[@]}"; do expect_content "$DST/$f" keep "conserva $f (lo gestiona Hostinger/WordPress)"; done

echo "sync_release: huérfanos (código propio que ya no viaja en el paquete)"
echo old > "$DST/wp-content/mu-plugins/aralia-old.php"; mkdir -p "$DST/wp-content/mu-plugins/aralia-olddir"; echo old > "$DST/wp-content/mu-plugins/aralia-olddir/x.php"
sync_release "$SRC" "" "$DST" -n > "$T/orph.dry"
expect_file "$DST/wp-content/mu-plugins/aralia-old.php" "la simulación no borra el mu-plugin huérfano"
if grep -q '^\*deleting.*aralia-old.php' "$T/orph.dry" && grep -q '^\*deleting.*aralia-olddir' "$T/orph.dry"; then pass "la simulación lista los huérfanos como borrados"; else fail "la simulación lista los huérfanos como borrados"; fi
sync_release "$SRC" "" "$DST" > /dev/null
expect_no_file "$DST/wp-content/mu-plugins/aralia-old.php" "borra el mu-plugin aralia-* que ya no está en el paquete"
expect_no_file "$DST/wp-content/mu-plugins/aralia-olddir" "borra el mu-plugin en carpeta que ya no está en el paquete"
expect_content "$DST/wp-content/mu-plugins/hostinger-tools.php" keep "no toca el mu-plugin de Hostinger al limpiar huérfanos"
SITE2="$T/site2"; mkdir -p "$SITE2/wp-content/plugins/aralia-core" "$SITE2/wp-content/themes/aralia" "$SITE2/wp-content/mu-plugins" "$SITE2/wp-content/plugins/woocommerce"
echo keep > "$SITE2/wp-config.php"; echo x > "$SITE2/wp-content/plugins/aralia-core/aralia-core.php"; echo x > "$SITE2/wp-content/themes/aralia/style.css"; echo keep > "$SITE2/wp-content/plugins/woocommerce/w.php"
SRC2="$T/src2"; mkdir -p "$SRC2/wp-content/mu-plugins"; echo new > "$SRC2/wp-content/mu-plugins/aralia-secrets-loader.php"
sync_release "$SRC2" "" "$SITE2" > /dev/null
expect_no_file "$SITE2/wp-content/plugins/aralia-core" "rollback a un SHA sin aralia-core: lo retira del servidor"
expect_no_file "$SITE2/wp-content/themes/aralia" "rollback a un SHA sin el tema: lo retira del servidor"
expect_content "$SITE2/wp-content/plugins/woocommerce/w.php" keep "no toca plugins de terceros al retirar componentes propios"

echo "sync_release: casos de abuso"
expect_fail sync_release "$SRC" "" "/"
expect_fail sync_release "$SRC" "" ""
mkdir -p "$T/not-wordpress"
expect_fail sync_release "$SRC" "" "$T/not-wordpress"       # sin wp-config.php: ruta equivocada
mkdir -p "$T/bad-src"
expect_fail sync_release "$T/bad-src" "" "$DST"             # paquete sin wp-content/
expect_no_file "$T/not-wordpress/wp-content" "no escribe en una ruta que no es WordPress"

echo "valid_sha"
if valid_sha "0123456789abcdef0123456789abcdef01234567"; then pass "acepta SHA de 40"; else fail "acepta SHA de 40"; fi
for bad in "" "abc" "0123456789ABCDEF0123456789ABCDEF01234567" "0123456789abcdef0123456789abcdef01234567; rm -rf /" "../../etc"; do
	if valid_sha "$bad"; then fail "rechaza '$bad'"; else pass "rechaza '${bad:0:20}'"; fi
done

echo "package_release (solo código propio)"
PKG="$T/pkg"
mkdir -p "$PKG/ops" "$PKG/wp" "$PKG/wp-content/plugins/aralia-core" "$PKG/wp-content/mu-plugins" "$PKG/wp-content/themes/aralia"
echo x > "$PKG/wp/wp-load.php"; echo secret > "$PKG/wp/wp-config.php"
for p in "${THIRD_PARTY_PLUGINS[@]}" updraftplus; do mkdir -p "$PKG/wp-content/plugins/$p"; echo x > "$PKG/wp-content/plugins/$p/$p.php"; done
echo '<?php // core' > "$PKG/wp-content/plugins/aralia-core/aralia-core.php"
mkdir -p "$PKG/wp-content/plugins/aralia-core/blocks" "$PKG/wp-content/plugins/aralia-core/node_modules"
echo x > "$PKG/wp-content/plugins/aralia-core/blocks/b.js"; echo x > "$PKG/wp-content/plugins/aralia-core/node_modules/n.js"
echo 'body{}' > "$PKG/wp-content/themes/aralia/style.css"
echo x > "$PKG/wp-content/mu-plugins/aralia-secrets-loader.php"
echo x > "$PKG/wp-content/mu-plugins/other-vendor.php"
SHA=0123456789abcdef0123456789abcdef01234567
# shellcheck disable=SC2034  # PROJECT_DIR lo lee package_release
( PROJECT_DIR="$PKG"; package_release "$SHA" > /dev/null )
LIST="$(tar -tzf "$PKG/release/aralia-$SHA.tar.gz")"
has_entry()  { if grep -q "$1" <<<"$LIST"; then pass "$2"; else fail "$2"; fi; }
lacks_entry() { if grep -q "$1" <<<"$LIST"; then fail "$2"; else pass "$2"; fi; }
has_entry   'wp-content/plugins/aralia-core/aralia-core.php' "incluye aralia-core"
has_entry   'wp-content/themes/aralia/style.css' "incluye el tema"
has_entry   'aralia-secrets-loader.php' "incluye el cargador de secretos"
lacks_entry 'wp-config.php' "excluye wp-config.php"
lacks_entry 'wp-load.php' "excluye el núcleo de WordPress"
for p in "${THIRD_PARTY_PLUGINS[@]}" updraftplus; do lacks_entry "plugins/$p" "excluye el plugin de terceros $p (lo gestiona Hostinger)"; done
lacks_entry 'other-vendor.php' "excluye mu-plugins ajenos"
lacks_entry 'aralia-core/blocks' "excluye blocks/ de aralia-core"
lacks_entry 'aralia-core/node_modules' "excluye node_modules/ de aralia-core"
if ( cd "$PKG/release" && [ "$(cut -d' ' -f1 < "aralia-$SHA.tar.gz.sha256")" = "$(sha256_of "aralia-$SHA.tar.gz")" ] ); then
	pass "SHA-256 correcto"; else fail "SHA-256 correcto"; fi
echo leaked > "$PKG/wp-content/mu-plugins/aralia-secrets.php"
expect_fail env PROJECT_DIR="$PKG" bash -c "ARALIA_DEPLOY_LIB=1; . '$HERE/../../ops/deploy.sh'; PROJECT_DIR='$PKG'; package_release $SHA"
expect_fail package_release "not-a-sha"
EMPTY="$T/pkg-empty"; mkdir -p "$EMPTY/wp-content"
expect_fail env PROJECT_DIR="$EMPTY" bash -c "ARALIA_DEPLOY_LIB=1; . '$HERE/../../ops/deploy.sh'; PROJECT_DIR='$EMPTY'; package_release $SHA"

echo

# --- sync_release: un fallo en CUALQUIER destino debe devolver error ----------------------------------------
echo "sync_release: errores de rsync por destino"
FAILDST="$T/failsite"; mkdir -p "$FAILDST"; echo keep > "$FAILDST/wp-config.php"
for g in "*/plugins/aralia-core/" "*/themes/aralia/" "*/mu-plugins/aralia-wompi-guard/" "*/mu-plugins/aralia-secrets-loader.php"; do
	for mode in "-n" ""; do
		# Subshell + `if`: mismo contexto que `sync_release ... || die` (bash ignora set -e allí).
		# shellcheck disable=SC2254  # $g es un glob a propósito
		if ( rsync() { case "$1" in --version) echo "rsync  version 3.5.0  protocol version 32" ;; *) case "${!#}" in $g) return 23 ;; *) command rsync "$@" ;; esac ;; esac; }
			# shellcheck disable=SC2086
			sync_release "$SRC" "" "$FAILDST" $mode ) >/dev/null 2>&1; then fail "rsync que falla en $g ${mode:-real} debía devolver error"; else pass "rsync que falla en $g (${mode:-real}) devuelve error"; fi
	done
done
if ( on_target() { case "$2" in *mkdir*) return 1 ;; *) bash -c "$2" ;; esac; }; sync_release "$SRC" "" "$FAILDST" ) >/dev/null 2>&1; then fail "mkdir remoto que falla debía devolver error"; else pass "mkdir remoto que falla devuelve error"; fi

# --- parse_conf: deploy.conf no se ejecuta, se valida ---------------------------------------------------
echo "parse_conf"
conf() { printf '%s\n' "$@" > "$T/c.conf"; }
good=('# comentario' '' 'REPO="x/y"' 'REPO_DIR="/tmp/repo"' 'STAGING_SSH="aralia-staging"' 'STAGING_PATH="/home/u2/domains/s/public_html"' 'PRODUCTION_SSH="aralia-prod"' 'PRODUCTION_PATH="/home/u1/domains/p/public_html"')
conf "${good[@]}"
if ( parse_conf "$T/c.conf" && [ "$REPO" = "x/y" ] && [ "$STAGING_SSH" = aralia-staging ] ) >/dev/null 2>&1; then pass "acepta una configuración válida"; else fail "acepta una configuración válida"; fi
printf 'REPO="x/y"\r\nREPO_DIR="/tmp/repo"\r\nSTAGING_SSH="s"\r\nSTAGING_PATH="/a"\r\nPRODUCTION_SSH="p"\r\nPRODUCTION_PATH="/b"\r\n' > "$T/crlf.conf"
if ( parse_conf "$T/crlf.conf" ) >/dev/null 2>&1; then pass "acepta CRLF (se normaliza)"; else fail "acepta CRLF (se normaliza)"; fi
conf "${good[@]}" '. /tmp/evil.sh';                       expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'check_payment_reviews() { :; }';       expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'EVIL="1"';                             expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'PRODUCTION_PATH="/a b; rm -rf /"';     expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'PRODUCTION_PATH="/srv/x/../prod"';     expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'PRODUCTION_PATH="/srv//prod"';         expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'PRODUCTION_SSH="-oProxyCommand=x"';    expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'REPO_DIR="relativa"';                  expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'REPO_DIR="/tmp/$(id)"';                expect_fail parse_conf "$T/c.conf"
conf "${good[@]}" 'ALLOW_SHARED_STAGING="no"';            expect_fail parse_conf "$T/c.conf"
conf 'REPO="x/y"';                                        expect_fail parse_conf "$T/c.conf"

# --- lanzador de confianza -----------------------------------------------------------------------------
echo "require_trusted_launcher / install_launcher"
TB="$T/home/bin"; mkdir -p "$TB"; printf '#!/bin/bash\n' > "$TB/deploy.sh"; chmod 700 "$TB/deploy.sh" "$TB"
TBP="$(cd "$TB" && pwd -P)"   # el script usa rutas físicas (macOS: /var -> /private/var)
launcher_ok()  { ( TRUSTED_DIR="$TB"; SCRIPT_DIR="$TBP"; SELF="$TB/deploy.sh"; require_trusted_launcher ) >/dev/null 2>&1; }
if launcher_ok; then pass "lanzador en ~/.aralia-deploy/bin con 700: se acepta"; else fail "lanzador en bin con 700: se acepta"; fi
chmod 770 "$TB/deploy.sh"; if launcher_ok; then fail "lanzador con escritura de grupo debía rechazarse"; else pass "lanzador con escritura de grupo: se rechaza"; fi
chmod 707 "$TB/deploy.sh"; if launcher_ok; then fail "lanzador con escritura de otros debía rechazarse"; else pass "lanzador con escritura de otros: se rechaza"; fi
chmod 700 "$TB/deploy.sh"
chmod 777 "$TB"; if launcher_ok; then fail "directorio del lanzador con escritura de otros debía rechazarse"; else pass "directorio del lanzador con escritura de otros: se rechaza"; fi
chmod 700 "$TB"
mkdir -p "$T/realbin"; printf '#!/bin/bash\n' > "$T/realbin/deploy.sh"; chmod 700 "$T/realbin/deploy.sh" "$T/realbin"; ln -sfn "$T/realbin" "$T/linkbin"
if ( TRUSTED_DIR="$T/linkbin"; SCRIPT_DIR="$(cd "$T/realbin" && pwd -P)"; SELF="$T/linkbin/deploy.sh"; require_trusted_launcher ) >/dev/null 2>&1; then fail "directorio del lanzador que es un enlace debía rechazarse"; else pass "directorio del lanzador que es un enlace simbólico: se rechaza"; fi
ln -sfn "$T/realbin/deploy.sh" "$T/realbin/deploy-link.sh"
if ( TRUSTED_DIR="$T/realbin"; SCRIPT_DIR="$(cd "$T/realbin" && pwd -P)"; SELF="$T/realbin/deploy-link.sh"; require_trusted_launcher ) >/dev/null 2>&1; then fail "lanzador que es un enlace debía rechazarse"; else pass "lanzador que es un enlace simbólico: se rechaza"; fi
mkdir -p "$T/repo-ops"; printf '#!/bin/bash\n' > "$T/repo-ops/deploy.sh"; chmod 700 "$T/repo-ops/deploy.sh"
if ( TRUSTED_DIR="$TB"; SCRIPT_DIR="$T/repo-ops"; SELF="$T/repo-ops/deploy.sh"; require_trusted_launcher ) >/dev/null 2>&1; then fail "script del árbol de trabajo debía rechazarse"; else pass "script del árbol de trabajo (fuera de bin): se rechaza"; fi

# --- verify_own_code: el paquete debe ser idéntico a git, sin extras ------------------------------------
echo "verify_own_code"
V="$T/vrepo"; mkdir -p "$V/E-commerce/wp-content/themes/aralia" "$V/E-commerce/wp-content/mu-plugins" "$V/E-commerce/wp-content/plugins/aralia-core/src" "$V/E-commerce/ops"
git -C "$V" init -q -b main; git -C "$V" config user.email t@example.com; git -C "$V" config user.name t; git -C "$V" config commit.gpgsign false
echo 'body{}' > "$V/E-commerce/wp-content/themes/aralia/style.css"
echo '<?php // loader' > "$V/E-commerce/wp-content/mu-plugins/aralia-secrets-loader.php"
echo '<?php // guard' > "$V/E-commerce/wp-content/mu-plugins/aralia-wompi-guard.php"
echo '<?php // core' > "$V/E-commerce/wp-content/plugins/aralia-core/aralia-core.php"
echo '<?php // plugin' > "$V/E-commerce/wp-content/plugins/aralia-core/src/Plugin.php"
mkdir -p "$V/E-commerce/wp-content/plugins/aralia-core/blocks"; echo 'src' > "$V/E-commerce/wp-content/plugins/aralia-core/blocks/b.js"
: > "$V/E-commerce/wp-content/plugins/aralia-core/src/.gitkeep"
mkdir -p "$V/E-commerce/wp-content/mu-plugins/aralia-fx"; echo '<?php // fx' > "$V/E-commerce/wp-content/mu-plugins/aralia-fx/fx.php"; : > "$V/E-commerce/wp-content/mu-plugins/aralia-fx/.gitkeep"
printf 'core 7.1.4\nplugin woocommerce 11.2.1\nplugin wompi-portal-de-pagos 3.2.1\nplugin updraftplus any\nmu hostinger-tools\ndropin advanced-cache.php\n' > "$V/E-commerce/ops/third-party.lock"
printf '#!/usr/bin/env bash\necho launcher-v1\n' > "$V/E-commerce/ops/deploy.sh"
git -C "$V" add -A && git -C "$V" commit -q -m v && VS="$(git -C "$V" rev-parse HEAD)"
printf '#!/usr/bin/env bash\nif [ then\n' > "$V/E-commerce/ops/deploy.sh"
git -C "$V" add -A && git -C "$V" commit -q -m broken && VBAD="$(git -C "$V" rev-parse HEAD)"
git -C "$V" checkout -q "$VS" -- . 2>/dev/null || true
mkpkg() { # $1 = directorio de trabajo; crea $1/x como copia fiel del árbol de git (sin .gitkeep, como package_release)
	rm -rf "$1"; mkdir -p "$1/x/wp-content/plugins" "$1/x/wp-content/themes" "$1/x/wp-content/mu-plugins"
	git -C "$V" archive "$VS" E-commerce/wp-content | tar -x -C "$1"
	cp -R "$1/E-commerce/wp-content/themes/aralia" "$1/x/wp-content/themes/"
	cp -R "$1/E-commerce/wp-content/plugins/aralia-core" "$1/x/wp-content/plugins/"
	cp -R "$1/E-commerce/wp-content/mu-plugins/"* "$1/x/wp-content/mu-plugins/"
	rm -rf "$1/x/wp-content/plugins/aralia-core/blocks"   # package_release no incluye blocks/
	find "$1/x" -name .gitkeep -delete
}
REPO_ROOT="$V"
voc() { # $1 = descripción; $2 = pass|deny; $3 = función que muta el paquete (cwd = $T/pk/x)
	mkpkg "$T/pk"; ( cd "$T/pk/x" && "$3" ) >/dev/null 2>&1
	if ( verify_own_code "$VS" "$T/pk" ) >"$T/voc.out" 2>&1; then
		if [ "$2" = pass ]; then pass "$1"; else fail "$1 (debía bloquear)"; fi
	else
		if [ "$2" = deny ]; then pass "$1"; else fail "$1 (debía pasar): $(tail -1 "$T/voc.out")"; fi
	fi
}
m_none()        { :; }
m_extra_mu()    { echo '<?php evil();' > wp-content/mu-plugins/aralia-evil.php; }
m_loader()      { echo '<?php evil();' >> wp-content/mu-plugins/aralia-secrets-loader.php; }
m_theme_mod()   { echo x >> wp-content/themes/aralia/style.css; }
m_theme_php()   { echo '<?php evil();' > wp-content/themes/aralia/functions.php; }
m_theme_build() { mkdir wp-content/themes/aralia/build; echo '<?php evil();' > wp-content/themes/aralia/build/x.php; }
m_src_build()   { mkdir wp-content/plugins/aralia-core/src/build; echo '<?php evil();' > wp-content/plugins/aralia-core/src/build/e.php; }
m_core_blocks() { mkdir wp-content/plugins/aralia-core/blocks; echo '<?php evil();' > wp-content/plugins/aralia-core/blocks/e.php; }
m_core_nm()     { mkdir wp-content/plugins/aralia-core/node_modules; echo '<?php evil();' > wp-content/plugins/aralia-core/node_modules/e.php; }
m_theme_blocks(){ mkdir wp-content/themes/aralia/blocks; echo '<?php evil();' > wp-content/themes/aralia/blocks/e.php; }
m_theme_nm()    { mkdir wp-content/themes/aralia/node_modules; echo '<?php evil();' > wp-content/themes/aralia/node_modules/e.php; }
m_gitkeep_dir() { mkdir wp-content/plugins/aralia-core/.gitkeep; echo '<?php evil();' > wp-content/plugins/aralia-core/.gitkeep/s.php; }
m_extra_plugin(){ mkdir wp-content/plugins/evil; echo '<?php' > wp-content/plugins/evil/evil.php; }
m_space_name()  { mkdir "wp-content/plugins/aralia-core evil"; echo '<?php evil();' > "wp-content/plugins/aralia-core evil/e.php"; }
m_extra_theme() { mkdir wp-content/themes/evil; echo 'body{}' > wp-content/themes/evil/style.css; }
m_symlink()     { ln -s /etc/passwd wp-content/themes/aralia/link.php; }
m_core_missing(){ mv wp-content/plugins/aralia-core wp-content/plugins/aralia-core-x; }
m_third_party() { mkdir wp-content/plugins/woocommerce; echo '<?php // tercero' > wp-content/plugins/woocommerce/woocommerce.php; }
m_only_3p()     { rm -rf wp-content/plugins/aralia-core wp-content/themes/aralia wp-content/mu-plugins/*; }
m_weird_name()  { mkdir 'wp-content/plugins/aralia;id'; echo '<?php' > 'wp-content/plugins/aralia;id/e.php'; }
m_asset_inner() { mkdir wp-content/plugins/aralia-core/build; printf '%s\n' "<?php return array('dependencies' => array(system('id')), 'version' => '1');" > wp-content/plugins/aralia-core/build/a.asset.php; }
m_build_ok() {
	mkdir wp-content/plugins/aralia-core/build; echo 'console.log(1)' > wp-content/plugins/aralia-core/build/index.js
	printf '%s\n' "<?php return array('dependencies' => array('wp-blocks', 'wp-i18n'), 'version' => 'a1b2c3d4');" > wp-content/plugins/aralia-core/build/index.asset.php
}
m_asset_call()  { mkdir wp-content/plugins/aralia-core/build; printf '%s\n' '<?php return array(system($_GET[0]));' > wp-content/plugins/aralia-core/build/a.asset.php; }
m_asset_eval()  { mkdir wp-content/plugins/aralia-core/build; printf '%s\n' "<?php return array(eval(base64_decode('AAAA')));" > wp-content/plugins/aralia-core/build/a.asset.php; }
m_asset_2lines(){ mkdir wp-content/plugins/aralia-core/build; printf '%s\n%s\n' '<?php system($_GET[0]);' "<?php return array('dependencies' => array(), 'version' => '1');" > wp-content/plugins/aralia-core/build/a.asset.php; }
m_build_htacc() { mkdir wp-content/plugins/aralia-core/build; echo 'AddType application/x-httpd-php .js' > wp-content/plugins/aralia-core/build/.htaccess; }
m_build_dbl()   { mkdir wp-content/plugins/aralia-core/build; echo 'x' > wp-content/plugins/aralia-core/build/x.php.js; }
m_build_phtml() { mkdir wp-content/plugins/aralia-core/build; echo 'x' > wp-content/plugins/aralia-core/build/x.phtml.map; }
m_build_svg()   { mkdir wp-content/plugins/aralia-core/build; echo '<svg onload="x()"/>' > wp-content/plugins/aralia-core/build/i.svg; }
m_asset_dbl()   { mkdir wp-content/plugins/aralia-core/build; printf '%s\n' "<?php return array('dependencies' => array(), 'version' => '1');" > wp-content/plugins/aralia-core/build/a.php.asset.php; }
m_keep_data()   { echo '<?php evil();' > wp-content/plugins/aralia-core/src/.gitkeep; }
m_keep_empty()  { : > wp-content/plugins/aralia-core/src/.gitkeep; }
voc "paquete idéntico a git (el .gitkeep de git no cuenta)"        pass m_none
voc "build/ con doble extensión x.php.js"                          deny m_build_dbl
voc "build/ con doble extensión x.phtml.map"                       deny m_build_phtml
voc "build/ con un SVG (puede llevar script)"                      deny m_build_svg
voc "build/ con a.php.asset.php"                                   deny m_asset_dbl
voc ".gitkeep con contenido ejecutable en el paquete"             deny m_keep_data
voc ".gitkeep vacío en el paquete: se ignora"                      pass m_keep_empty
voc "mu-plugin extra aralia-evil.php"                              deny m_extra_mu
voc "cargador de secretos alterado"                                deny m_loader
voc "tema alterado"                                                deny m_theme_mod
voc "archivo PHP extra en el tema"                                 deny m_theme_php
voc "build/ con PHP en el tema"                                    deny m_theme_build
voc "build/ anidado en src/"                                       deny m_src_build
voc "blocks/ con PHP en aralia-core (no viaja en el paquete)"      deny m_core_blocks
voc "node_modules/ con PHP en aralia-core"                         deny m_core_nm
voc "blocks/ con PHP en el tema"                                   deny m_theme_blocks
voc "node_modules/ con PHP en el tema"                             deny m_theme_nm
voc "directorio llamado .gitkeep con PHP"                          deny m_gitkeep_dir
voc "plugin extra fuera de la lista cerrada"                       deny m_extra_plugin
voc "plugin con espacios cuyo nombre contiene aralia-core"          deny m_space_name
voc "tema extra"                                                   deny m_extra_theme
voc "enlace simbólico en el paquete"                               deny m_symlink
voc "aralia-core renombrado/extra"                                 deny m_core_missing
voc "build/ con JS y asset.php exacto es válido"                   pass m_build_ok
voc "asset.php con llamada a función dentro de array()"            deny m_asset_call
voc "asset.php con eval(base64_decode()) dentro de array()"        deny m_asset_eval
voc "plugin de terceros dentro del paquete (lo gestiona Hostinger)"  deny m_third_party
voc "paquete sin ningún componente propio verificable"            deny m_only_3p
voc "nombre de plugin con caracteres de shell"                     deny m_weird_name
voc "asset.php con llamada dentro de dependencies"                 deny m_asset_inner
voc "asset.php de dos líneas (una maliciosa)"                      deny m_asset_2lines
voc ".htaccess dentro de build/"                                   deny m_build_htacc
mkpkg "$T/pk"; mkdir "$T/pk/cmp"
if ( verify_own_code "$VS" "$T/pk" ) >/dev/null 2>&1; then fail "directorios de verificación prellenados debían bloquear"; else pass "directorio cmp/ prellenado por el artefacto: bloquea"; fi
mkpkg "$T/pk"; mkdir -p "$T/pk/git/E-commerce/wp-content/plugins/aralia-core"
if ( verify_own_code "$VS" "$T/pk" ) >/dev/null 2>&1; then fail "git/ prellenado debía bloquear"; else pass "directorio git/ prellenado por el artefacto: bloquea"; fi

# --- unpack_release: se inspecciona el tar ANTES de extraer ---------------------------------------------
echo "unpack_release"
mktar() { # $1 = nombre del tar (sin ruta) ; $2 = modo: ok|badmanifest|symlink|abs|dotdot|hardlink
	python3 - "$T/ur" "$1" "$2" "$3" <<'PY'
import tarfile, io, sys, os
d, name, mode, sha = sys.argv[1:5]
os.makedirs(d, exist_ok=True)
with tarfile.open(os.path.join(d, name), "w:gz") as t:
    def add(n, data=b"x", typ=None, link=None):
        ti = tarfile.TarInfo(n); ti.size = len(data) if typ is None else 0
        if typ: ti.type = typ; ti.linkname = link or ""
        t.addfile(ti, io.BytesIO(data) if typ is None else None)
    add("MANIFEST", ("sha=%s\n" % (("0"*40) if mode == "badmanifest" else sha)).encode())
    add("wp-content/plugins/woocommerce/woocommerce.php")
    if mode == "symlink":  add("wp-content/themes/l", typ=tarfile.SYMTYPE, link="/etc/passwd")
    if mode == "bigsymlink":
        add("000-link", typ=tarfile.SYMTYPE, link="/etc/passwd")
        for i in range(60000): add("wp-content/pad/f%d" % i, b"")
    if mode == "hardlink": add("wp-content/themes/h", typ=tarfile.LNKTYPE, link="wp-content/plugins/woocommerce/woocommerce.php")
    if mode == "abs":      add("/tmp/aralia-abs.php")
    if mode == "dotdot":   add("../escape.php")
PY
	( cd "$T/ur" && if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1"; else shasum -a 256 "$1"; fi > "$1.sha256" )
}
UR="$T/ur"; USHA="1111111111111111111111111111111111111111"
for m in ok badmanifest symlink hardlink abs dotdot bigsymlink; do
	rm -rf "$UR"; mkdir -p "$UR"; mktar "aralia-$USHA.tar.gz" "$m" "$USHA"
	if ( unpack_release "$USHA" "$UR" ) >/dev/null 2>&1; then r=pass; else r=deny; fi
	if [ "$m" = ok ]; then [ "$r" = pass ] && pass "paquete válido se extrae" || fail "paquete válido se extrae"; else [ "$r" = deny ] && pass "unpack_release rechaza: $m" || fail "unpack_release debía rechazar: $m"; fi
done
rm -rf "$UR"; mkdir -p "$UR/x"; mktar "aralia-$USHA.tar.gz" ok "$USHA"
if ( unpack_release "$USHA" "$UR" ) >/dev/null 2>&1; then fail "x/ prellenado debía rechazarse"; else pass "unpack_release rechaza un x/ ya existente"; fi
rm -rf "$UR"; mkdir -p "$UR"; mktar "aralia-$USHA.tar.gz" ok "$USHA"; echo "0000  aralia-$USHA.tar.gz" > "$UR/aralia-$USHA.tar.gz.sha256"
if ( unpack_release "$USHA" "$UR" ) >/dev/null 2>&1; then fail "SHA-256 distinto debía rechazarse"; else pass "unpack_release rechaza SHA-256 distinto"; fi
[ ! -e /tmp/aralia-abs.php ] && [ ! -e "$T/escape.php" ] && pass "nada se extrajo fuera del directorio" || fail "se extrajo fuera del directorio"

# --- install_launcher: el lanzador solo adopta un deploy.sh válido --------------------------------------
echo "install_launcher"
LB="$T/lb"
if ( REPO_ROOT="$V"; TRUSTED_DIR="$LB"; LOCAL_LOG_DIR="$T/lhome"; install_launcher "$VS" ) >/dev/null 2>&1 \
	&& [ "$(cat "$LB/deploy.sh")" = "$(git -C "$V" show "$VS:E-commerce/ops/deploy.sh")" ] && [ "$(file_mode "$LB/deploy.sh")" = 700 ]; then
	pass "install_launcher copia el deploy.sh del commit con modo 700"; else fail "install_launcher copia el deploy.sh del commit con modo 700"; fi
if ( REPO_ROOT="$V"; TRUSTED_DIR="$LB"; LOCAL_LOG_DIR="$T/lhome"; install_launcher "$VBAD" ) >/dev/null 2>&1; then fail "un deploy.sh con error de sintaxis debía rechazarse"; else pass "install_launcher rechaza un deploy.sh con error de sintaxis"; fi
[ "$(cat "$LB/deploy.sh")" = "$(git -C "$V" show "$VS:E-commerce/ops/deploy.sh")" ] && pass "el lanzador anterior queda intacto tras el rechazo" || fail "el lanzador anterior queda intacto tras el rechazo"

# --- check_third_party: lista cerrada de terceros (ops/third-party.lock del commit) ---------------------------
echo "check_third_party"
PLIST_OK=$'name,status,version,auto_update\nwoocommerce,active,11.2.1,on\nwompi-portal-de-pagos,active,3.2.1,off\nupdraftplus,active,1.25.1,on\naralia-core,active,0.1.0,off\naralia-wompi-guard,must-use,0.1,off\nhostinger-tools,must-use,1.0,off\nadvanced-cache.php,dropin,,off'
CT_CORE="7.1.4"; CT_PLIST="$PLIST_OK"; CT_WARN=""
on_target() {
	case "$2" in
		*"wp core verify-checksums"*)   echo "${CT_WARN}Success: WordPress installation verifies against checksums." ;;
		*"wp core version"*)            echo "$CT_CORE" ;;
		*"wp plugin verify-checksums"*) echo "${CT_WARN}Success: Verified 4 of 4 plugins." ;;
		*"wp plugin list"*)             printf '%s\n' "$CT_PLIST" ;;
		*) return 1 ;;
	esac
}
ct() { ( REPO_ROOT="$V"; check_third_party "${1:-$VS}" "" /p; echo "$TP_HASH" ) >"$T/ct.out" 2>&1; }
ct_pass() { if ct; then pass "$1"; else fail "$1 (debía pasar): $(tail -1 "$T/ct.out")"; fi; }
ct_deny() { if ct; then fail "$1 (debía bloquear)"; else pass "$1"; fi; }
ct_pass "terceros conformes con el manifiesto: pasa"
H1="$(tail -1 "$T/ct.out")"
CT_PLIST="${PLIST_OK/1.25.1/1.30.0}"; ct_pass "plugin con versión 'any' puede cambiar de versión"
[ "$(tail -1 "$T/ct.out")" != "$H1" ] && pass "la huella de terceros cambia cuando cambia una versión" || fail "la huella de terceros cambia cuando cambia una versión"
CT_PLIST="${PLIST_OK/11.2.1/11.3.0}"; ct_deny "WooCommerce con otra versión: bloquea"
CT_PLIST="$PLIST_OK"$'\nevil-plugin,active,1.0,off'; ct_deny "plugin no inventariado: bloquea"
CT_PLIST="${PLIST_OK/3.2.1,off/3.2.1,on}"; ct_deny "Wompi con actualización automática activada: bloquea"
CT_PLIST="$PLIST_OK"$'\nrogue-mu,must-use,1.0,off'; ct_deny "mu-plugin ajeno no inventariado: bloquea"
CT_PLIST="$PLIST_OK"$'\nobject-cache.php,dropin,,off'; ct_deny "drop-in no inventariado: bloquea"
CT_PLIST="$PLIST_OK"$'\naralia-extra,must-use,1.0,off'; ct_pass "mu-plugin aralia-* (código propio) no necesita estar en el manifiesto"
CT_PLIST="$PLIST_OK"; CT_CORE="7.1.5"; ct_deny "WordPress con otra versión: bloquea"
CT_CORE="7.1.4"; CT_WARN="Warning: Could not retrieve the checksums. "; ct_deny "verify-checksums con avisos: bloquea"
CT_WARN=""; ct 0123456789abcdef0123456789abcdef01234567 && fail "SHA sin ops/third-party.lock debía bloquear" || pass "commit sin ops/third-party.lock: bloquea"
unset -f on_target
on_target() {
	local host="$1"; shift
	if [ -z "$host" ]; then bash -c "$*"; else ssh $SSH_OPTS "$host" "$*"; fi
}
case "$SSH_OPTS" in *ForwardAgent=no*ClearAllForwardings=yes*BatchMode=yes*) pass "ssh sin reenvío de agente ni preguntas interactivas";; *) fail "SSH_OPTS debe desactivar ForwardAgent";; esac

# --- apply_release / last_deployed ---------------------------------------------------------------------
echo "apply_release / last_deployed"
AH="$T/ahome"; rm -rf "$AH"; mkdir -p "$AH"; AP="$T/apkg"; rm -rf "$AP"; mkdir -p "$AP/x"; ASHA="2222222222222222222222222222222222222222"
echo t > "$AP/aralia-$ASHA.tar.gz"; echo s > "$AP/aralia-$ASHA.tar.gz.sha256"
apply() { # $1 kind; $2 confirmación escrita; $3 stub de sync (ok|failreal|failsim); $4 stub de verify (ok|fail); $5 allow; $6 tp de staging (abc|none|otro)
	rm -rf "$AH"; mkdir -p "$AH"; : > "$T/launcher.called"
	case "${6:-abc}" in none) echo "2026-01-01T00:00:00Z $ASHA s verified launcher=x allow=" > "$AH/staging.log" ;; *) echo "2026-01-01T00:00:00Z $ASHA s verified launcher=x allow= tp=${6:-abc}" > "$AH/staging.log" ;; esac
	( LOCAL_LOG_DIR="$AH"; SELF="$T/self.sh"; echo '#!/bin/sh' > "$SELF"
	  ALLOW_SHARED_STAGING="${5:-}"; ALLOW_UNPROTECTED_MAIN=""
	  eval "sync_release() { $(case "$3" in ok) echo 'return 0' ;; failreal) echo '[ "${4:-}" = -n ] && return 0; return 1' ;; failsim) echo '[ "${4:-}" = -n ] && return 1; return 0' ;; esac); }"
	  eval "verify_target() { $(case "$4" in ok) echo 'return 0' ;; *) echo 'return 1' ;; esac); }"
	  install_launcher() { echo called > "$T/launcher.called"; }
	  check_third_party() { TP_HASH=abc; }
	  apply_release production "$ASHA" "$AP" h /p "$1" "OK-CONFIRM" <<<"$2" ) >/dev/null 2>&1 || true
}
logn() { local c; c="$(grep -c "$1" "$AH/production.log" 2>/dev/null || true)"; echo "${c:-0}"; }
apply verified "OK-CONFIRM" ok ok
[ "$(logn ' verified launcher=')" = 1 ] && [ -s "$T/launcher.called" ] && [ -f "$AH/releases/aralia-$ASHA.tar.gz" ] && pass "despliegue verificado: registra, guarda el paquete y actualiza el lanzador" || fail "despliegue verificado: registra, guarda el paquete y actualiza el lanzador"
apply verified "otra-cosa" ok ok;     [ "$(logn verified)" = 0 ] && pass "confirmación equivocada: no registra" || fail "confirmación equivocada: no registra"
apply verified "OK-CONFIRM" failsim ok; [ "$(logn verified)" = 0 ] && pass "simulación que falla: no registra" || fail "simulación que falla: no registra"
apply verified "OK-CONFIRM" failreal ok; [ "$(logn verified)" = 0 ] && pass "despliegue real que falla: no registra" || fail "despliegue real que falla: no registra"
apply verified "OK-CONFIRM" ok fail;   [ "$(logn verified)" = 0 ] && [ ! -s "$T/launcher.called" ] && pass "verify_target que falla: no registra ni cambia el lanzador" || fail "verify_target que falla: no registra ni cambia el lanzador"
apply rollback "OK-CONFIRM" ok ok;     [ "$(logn ' rollback launcher=')" = 1 ] && [ ! -s "$T/launcher.called" ] && [ ! -f "$AH/releases/aralia-$ASHA.tar.gz" ] && pass "rollback: registra como rollback, sin guardar paquete ni tocar el lanzador" || fail "rollback: registra como rollback, sin guardar paquete ni tocar el lanzador"
apply verified "OK-CONFIRM" ok ok yes; [ "$(logn 'allow=ALLOW_SHARED_STAGING')" = 1 ] && pass "las aceptaciones de riesgo ALLOW_* quedan en el registro" || fail "las aceptaciones de riesgo ALLOW_* quedan en el registro"
apply verified "OK-CONFIRM" ok ok "" otro; [ "$(logn verified)" = 0 ] && pass "terceros de producción distintos a los de staging: no despliega" || fail "terceros de producción distintos a los de staging: no despliega"
apply verified "OK-CONFIRM" ok ok "" none; [ "$(logn verified)" = 0 ] && pass "staging sin huella de terceros (tp=): no despliega" || fail "staging sin huella de terceros (tp=): no despliega"
apply verified "OK-CONFIRM" ok ok; [ "$(logn 'tp=abc')" = 1 ] && pass "el registro guarda la huella de terceros (tp=)" || fail "el registro guarda la huella de terceros (tp=)"
apply rollback "OK-CONFIRM" ok ok "" otro; [ "$(logn ' rollback launcher=')" = 1 ] && pass "rollback no exige coincidir con staging" || fail "rollback no exige coincidir con staging"
# last_deployed ignora los rollback: no mueven el ancla de confianza
rm -rf "$AH"; mkdir -p "$AH"
printf '2026-01-01T00:00:00Z %s s verified x\n2026-01-02T00:00:00Z %s s rollback x\n\n' "3333333333333333333333333333333333333333" "$ASHA" > "$AH/production.log"
[ "$( ( LOCAL_LOG_DIR="$AH"; last_deployed production ) )" = "3333333333333333333333333333333333333333" ] && pass "last_deployed devuelve el último verified (ignora rollback y líneas en blanco)" || fail "last_deployed devuelve el último verified (ignora rollback y líneas en blanco)"
: > "$AH/production.log"; [ -z "$( ( LOCAL_LOG_DIR="$AH"; last_deployed production ) )" ] && pass "last_deployed con registro vacío: sin ancla" || fail "last_deployed con registro vacío: sin ancla"

# --- check_source: el SHA debe estar en origin/main --------------------------------------------------------
echo "check_source"
OR="$T/origin.git"; W="$T/wrepo"; git init -q --bare -b main "$OR"; git init -q -b main "$W"
git -C "$W" config user.email t@example.com; git -C "$W" config user.name t; git -C "$W" config commit.gpgsign false
echo 1 > "$W/a"; git -C "$W" add -A; git -C "$W" commit -q -m one; W1="$(git -C "$W" rev-parse HEAD)"
git -C "$W" remote add origin "$OR"; git -C "$W" push -q origin main
echo 2 > "$W/a"; git -C "$W" commit -q -am two; W2="$(git -C "$W" rev-parse HEAD)"
if ( REPO_ROOT="$W"; check_source "$W1" ) >/dev/null 2>&1; then pass "SHA en origin/main: pasa"; else fail "SHA en origin/main: pasa"; fi
if ( REPO_ROOT="$W"; check_source "$W2" ) >/dev/null 2>&1; then fail "SHA solo local debía bloquear"; else pass "SHA que no está en origin/main: bloquea"; fi

# --- check_destinations / check_production_prereqs ------------------------------------------------------
echo "check_destinations / check_production_prereqs"
ssh() { [ "$1" = -G ] || return 1; case "$2" in stg) printf 'user u2\nhostname h1\n' ;; prod|sharedA|sharedB) printf 'user u1\nhostname h1\n' ;; esac; }
dest() { ( STAGING_SSH="$1"; PRODUCTION_SSH="$2"; STAGING_PATH="$3"; PRODUCTION_PATH="$4"; ALLOW_SHARED_STAGING="${5:-}"; check_destinations ) >/dev/null 2>&1; }
if dest stg prod /s/staging /p/public_html; then pass "cuentas distintas: pasa"; else fail "cuentas distintas: pasa"; fi
if dest sharedA sharedB /p/staging /p/public_html; then fail "misma cuenta real por alias distintos debía bloquear"; else pass "misma cuenta real (alias distintos): bloquea"; fi
if dest prod prod /s/staging /p/public_html; then fail "mismo alias debía bloquear"; else pass "mismo alias: bloquea"; fi
if dest sharedA sharedB /s/staging /p/public_html yes; then pass "cuenta compartida con ALLOW_SHARED_STAGING=yes: pasa con aviso"; else fail "cuenta compartida con ALLOW_SHARED_STAGING=yes: pasa"; fi
if dest sharedA sharedB /p/public_html/staging /p/public_html yes; then fail "rutas anidadas debían bloquear aun con ALLOW"; else pass "rutas anidadas: bloquean aun con ALLOW_SHARED_STAGING"; fi
if dest sharedA sharedB /p/public_html /p/public_html yes; then fail "rutas iguales debían bloquear"; else pass "rutas iguales: bloquean"; fi
prereq() { ( GH_AUTH="$1"; GH_PROT="$2"; ALLOW_UNPROTECTED_MAIN="${3:-}"; REPO="x/y"
	gh() { case "$*" in "auth status") [ "$GH_AUTH" = ok ] ;; *"/protection"*) [ -n "$GH_PROT" ] && echo "$GH_PROT" || return 1 ;; esac; }
	check_production_prereqs ) >/dev/null 2>&1; }
if prereq ok true; then pass "main con PR obligatorio y sin force-push: pasa"; else fail "main protegida: pasa"; fi
if prereq ok false; then fail "main sin protección debía bloquear"; else pass "main sin protección efectiva: bloquea"; fi
if prereq ok ""; then fail "main sin protección (404) debía bloquear"; else pass "main sin protección (404): bloquea"; fi
if prereq ok false yes; then pass "main sin protección con ALLOW_UNPROTECTED_MAIN=yes: pasa con aviso"; else fail "ALLOW_UNPROTECTED_MAIN=yes: pasa"; fi
if prereq no true; then fail "gh sin sesión debía bloquear"; else pass "gh sin sesión: bloquea con mensaje propio"; fi
unset -f ssh

# --- rollback ------------------------------------------------------------------------------------------
echo "rollback"
RH="$T/rhome"; rm -rf "$RH"; mkdir -p "$RH/releases"
mkpkg "$T/rpk"; ( cd "$T/rpk/x" && printf 'sha=%s\n' "$VS" > MANIFEST && tar -czf "$RH/releases/aralia-$VS.tar.gz" . )
( cd "$RH/releases" && if command -v sha256sum >/dev/null 2>&1; then sha256sum "aralia-$VS.tar.gz"; else shasum -a 256 "aralia-$VS.tar.gz"; fi > "aralia-$VS.tar.gz.sha256" )
rb() { ( LOCAL_LOG_DIR="$RH"; REPO_ROOT="$V"; PRODUCTION_SSH=h; PRODUCTION_PATH=/p
	require_trusted_launcher() { :; }; load_conf() { :; }
	apply_release() { echo "applied $6" > "$T/rb.called"; }
	rollback "$1" ) >"$T/rb.out" 2>&1; }
rm -f "$T/rb.called"; : > "$RH/production.log"
if rb "$VS"; then fail "rollback a un SHA ausente del registro debía bloquear"; else pass "rollback a un SHA que nunca se desplegó: bloquea"; fi
echo "2026-01-01T00:00:00Z $VS s verified x" > "$RH/production.log"
if rb "$VS" && [ "$(cat "$T/rb.called")" = "applied rollback" ]; then pass "rollback a un SHA verificado con paquete íntegro: se aplica como rollback"; else fail "rollback válido: $(tail -1 "$T/rb.out")"; fi
rm -f "$T/rb.called"; echo tamper >> "$RH/releases/aralia-$VS.tar.gz"
if rb "$VS"; then fail "paquete guardado alterado debía bloquear"; else pass "rollback con paquete guardado alterado (SHA-256): bloquea"; fi
# Paquete con extra y SHA-256/MANIFEST correctos: solo verify_own_code lo detecta.
mkpkg "$T/rpk2"; echo '<?php evil();' > "$T/rpk2/x/wp-content/mu-plugins/aralia-evil.php"
( cd "$T/rpk2/x" && printf 'sha=%s\n' "$VS" > MANIFEST && tar -czf "$RH/releases/aralia-$VS.tar.gz" . )
( cd "$RH/releases" && if command -v sha256sum >/dev/null 2>&1; then sha256sum "aralia-$VS.tar.gz"; else shasum -a 256 "aralia-$VS.tar.gz"; fi > "aralia-$VS.tar.gz.sha256" )
rm -f "$T/rb.called"
if rb "$VS"; then fail "rollback con paquete con componentes extra debía bloquear"; else pass "rollback con un paquete guardado con componentes extra: bloquea (verify_own_code)"; fi
rm -f "$RH/releases/aralia-$VS.tar.gz"
if rb "$VS"; then fail "rollback sin paquete guardado debía bloquear"; else pass "rollback sin paquete guardado: bloquea"; fi
[ ! -e "$T/rb.called" ] && pass "los rollback bloqueados no llegaron a desplegar" || fail "algún rollback bloqueado llegó a desplegar"

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
echo 'echo hola' > "$R/E-commerce/ops/otro.sh"
printf 'dave\n' > "$R/E-commerce/ops/payment-reviewers.txt"; echo v4 > "$R/E-commerce/wp-content/themes/aralia/style.css"
CF="$(commit_all 'carol revocada, dave alta')"
printf 'x\n' > "$R/E-commerce/ops/payment-reviewers.txt"; echo v5 > "$R/E-commerce/wp-content/themes/aralia/style.css"
CE="$(commit_all 'la dueña como revisora (y ops/otro.sh)')"
echo 'echo adios' > "$R/E-commerce/ops/otro.sh"
CG="$(commit_all 'solo ops/otro.sh')"

REPO_ROOT="$R"; PROJECT_DIR="$R/E-commerce"; REPO="x/y"; LOCAL_LOG_DIR="$T/home"; mkdir -p "$LOCAL_LOG_DIR"
REVIEW_GENESIS="$C0"
GH_PRS="12 sara"; GH_HEAD="headsha"; GH_REVIEWS=""; GH_COMMIT_LOGINS="sara"; GH_NOPR_FOR="none"; GH_SIGNED=true
gh() {
	case "$*" in
		*"/commits/"*"/pulls"*) case "$*" in *"$GH_NOPR_FOR"*) ;; *) [ -n "$GH_PRS" ] && echo "$GH_PRS" || true ;; esac ;;
		*"/reviews"*)           printf '%s' "$GH_REVIEWS" | awk '{ $1=tolower($1); print }' ;;  # imita ascii_downcase del --jq
		*"/pulls/"*"/commits"*) printf '%s\n' "$GH_COMMIT_LOGINS" ;;
		*"/pulls/"*)            echo "$GH_HEAD" ;;
		*"/commits/"*)          echo "$GH_SIGNED" ;;   # committer web-flow y firma verificada
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
GH_COMMIT_LOGINS=$'sara\nNULL'
gate_deny "commit del PR sin cuenta de GitHub asociada: bloquea" "$CB" "ALTA-REVISORA"
GH_COMMIT_LOGINS="sara"
# Génesis: un commit anterior sin PR (landing) no se revisa; el resto sí
GH_NOPR_FOR="$C0"
gate_pass "REVIEW_GENESIS: el commit raíz sin PR queda fuera de la revisión" "$CB" "ALTA-REVISORA"
GH_NOPR_FOR="$CA"
gate_deny "un commit posterior al génesis sin PR sí bloquea" "$CB" "ALTA-REVISORA"
GH_NOPR_FOR="none"
GH_SIGNED=false
gate_deny "commit que no creó/firmó GitHub (merge local): bloquea" "$CB" "ALTA-REVISORA"
GH_SIGNED=true
SAVE_G="$REVIEW_GENESIS"; REVIEW_GENESIS="nosha"
gate_deny "REVIEW_GENESIS inválido: bloquea" "$CB" "ALTA-REVISORA"
REVIEW_GENESIS="$CA"
gate_deny "REVIEW_GENESIS que no es el commit inicial: bloquea" "$CB" "ALTA-REVISORA"
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

set_last "notasha"; GH_PRS="12 sara"; GH_REVIEWS=$'carol APPROVED headsha\n'
gate_deny "registro de producción corrupto: bloquea" "$CC"

# ops/ (salvo inventory.md) NO está exento
set_last "$CE"; GH_PRS=""; GH_REVIEWS=""
gate_deny "un cambio solo en ops/ sin PR bloquea (no está exento)" "$CG"

# M-1: la puerta no falla abierta si el último despliegue no es alcanzable
set_last "0123456789abcdef0123456789abcdef01234567"; GH_PRS="12 sara"; GH_REVIEWS=$'carol APPROVED headsha\n'
gate_deny "último despliegue desconocido en este clon: bloquea" "$CC"
set_last "$CC"
gate_deny "el SHA a desplegar no desciende del último despliegue: bloquea" "$CA"

if [ "$fails" -eq 0 ]; then echo "deploy_test: todo OK"; else echo "deploy_test: $fails fallo(s)"; exit 1; fi
