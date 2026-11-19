#!/usr/bin/env bash
# Construye la imagen base parcheada para wp-env (T165): la base de wordpress-php83.Dockerfile (fijada por digest) más el grupo `_ssh`,
# etiquetada `wordpress:php8.3` para que wp-env la use. Se ejecuta antes de `wp-env start`, en local y en el CI.
# Ojo: en una máquina de desarrollo esto sobrescribe la etiqueta local `wordpress:php8.3` (solo local; no se sube a ningún registro).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
docker build -t wordpress:php8.3 -f wordpress-php83.Dockerfile .
echo "Imagen wordpress:php8.3 construida sobre la base fijada en wordpress-php83.Dockerfile"
