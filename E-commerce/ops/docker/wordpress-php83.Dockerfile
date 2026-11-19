# Imagen base parcheada para wp-env (T165).
# wp-env construye su imagen encima de `wordpress:php8.3` (Debian trixie) y `apt install git` falla porque falta el grupo `_ssh`.
# Se reconstruye esa misma etiqueta con el grupo creado; wp-env usa la imagen local sin descargar otra.
# La base está FIJADA por digest (reproducible); Dependabot (ecosistema docker) propone el cambio como PR, que pasa por revisión.
# Uso: bash ops/docker/build-base.sh
FROM wordpress:php8.3@sha256:074f9ef5429025dab76a4242f5b796f64ae76a49dd19b6708ad29e1893a8d383
RUN getent group _ssh >/dev/null || groupadd -r _ssh
