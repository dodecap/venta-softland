#!/usr/bin/env bash
# Publica el APK compilado en el servidor, para repartirlo por /app.
#
#   bash mobile/build-apk.sh        # primero se compila
#   bin/publicar-apk.sh             # y después se sube
#
# Va a storage/app/private/apk, que está fuera de public/ y fuera del tar de
# deploy.sh: desplegar el servidor no se lleva por delante lo publicado. No se
# borra el anterior — ocupan 5 MB y tenerlo a mano es lo que permite volver
# atrás cuando una versión sale mala.
set -euo pipefail
R="$(cd "$(dirname "$0")/.." && pwd)"
SRV=srv
V="$(cat "$R/VERSION")"
APK="$R/venta-softland-$V.apk"

if [[ ! -f "$APK" ]]; then
    echo "no existe $APK" >&2
    echo "compílalo antes:  bash mobile/build-apk.sh" >&2
    exit 1
fi

DEST_WIN='C:\xampp\htdocs\venta-softland\storage\app\private\apk'
DEST_SCP='C:/xampp/htdocs/venta-softland/storage/app/private/apk'

ssh "$SRV" "cmd /c \"if not exist $DEST_WIN mkdir $DEST_WIN\"" >/dev/null
scp -q "$APK" "$SRV:$DEST_SCP/venta-softland-$V.apk"

echo "publicado $V ($(du -h "$APK" | cut -f1))"
echo "se reparte desde  https://venta.netdomain.cl/app"
