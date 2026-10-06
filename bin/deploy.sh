#!/usr/bin/env bash
# Despliega el servidor a srv (C:\xampp\htdocs\venta-softland) por tar+scp.
# No toca vendor/ ni .env: viven en el servidor.
#   bin/deploy.sh
set -euo pipefail
R="$(cd "$(dirname "$0")/.." && pwd)"
SRV=srv
DEST='C:\xampp\htdocs\venta-softland'
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# bootstrap/cache se genera en el servidor: contiene el manifiesto de paquetes
# de Composer, que solo existe donde corre Composer.
#
# El `.htaccess` y el `index.php` de la raíz son el puente que deja servir la
# aplicación sin tocar `httpd.conf`, y `deploy/` lleva `arranque.php`. Van en la
# lista aunque esta instalación esté publicada con el `Alias` de Apache y no use
# el puente, por dos razones: una instalación hecha con el paquete se quedaría
# sin ellos en el primer despliegue, y `public/index.php` **requiere**
# `deploy/arranque.php` cuando no hay `.env` — desplegar sin esa carpeta deja un
# servidor que no arranca justo el día que se instala en otro sitio.
tar -C "$R" -cf "$TMP/deploy.tar" --exclude='bootstrap/cache' \
  app bootstrap config database lang resources routes public bin deploy \
  artisan composer.json composer.lock phpunit.xml tests VERSION .env.example \
  .htaccess index.php

scp -q "$TMP/deploy.tar" "$SRV:C:/Users/ddecap/deploy-ventas.tar"
ssh "$SRV" "cmd /c \"cd /d $DEST && C:\\Windows\\System32\\tar.exe -xf C:\\Users\\ddecap\\deploy-ventas.tar && C:\\xampp\\php\\php.exe artisan config:cache && C:\\xampp\\php\\php.exe artisan view:cache\"" | sed 's/\r$//'

echo "desplegado a $SRV:$DEST"
