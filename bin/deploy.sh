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
tar -C "$R" -cf "$TMP/deploy.tar" --exclude='bootstrap/cache' \
  app bootstrap config database resources routes public \
  artisan composer.json composer.lock phpunit.xml tests

scp -q "$TMP/deploy.tar" "$SRV:C:/Users/ddecap/deploy-ventas.tar"
ssh "$SRV" "cmd /c \"cd /d $DEST && C:\\Windows\\System32\\tar.exe -xf C:\\Users\\ddecap\\deploy-ventas.tar && C:\\xampp\\php\\php.exe artisan config:cache && C:\\xampp\\php\\php.exe artisan view:cache\"" | sed 's/\r$//'

echo "desplegado a $SRV:$DEST"
