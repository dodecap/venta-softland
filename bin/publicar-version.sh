#!/usr/bin/env bash
# Publica la versión actual en GitHub Releases, que es de donde se actualizan
# los servidores de los clientes.
#
#   bin/version.sh menor "Título"    # 1. subir el número
#   ...trabajar, commit, tag...
#   bin/deploy.sh                    # 2. dejar este servidor al día
#   bash mobile/build-apk.sh         # 3. compilar la app
#   bin/publicar-version.sh          # 4. y publicarla para todos
#
# Sube tres piezas, y las tres tienen que poder bajarse por separado:
#
#   venta-softland-<v>-servidor.tar.gz   el código (1,4 MB)
#   vendor-<sha256 del composer.lock>.tgz  las dependencias (17 MB)
#   venta-softland-<v>.apk               la app (5 MB)
#
# El nombre del paquete de dependencias lleva dentro el sha256 del
# `composer.lock`: así el servidor que se actualiza sabe **sin preguntar nada**
# si tiene que bajárselo o ya tiene ese mismo vendor puesto. Se publica en
# todas las versiones aunque no haya cambiado —cuesta subirlo, no bajarlo— para
# que la regla del cliente no tenga excepciones: mirar la última publicación
# siempre basta.
#
# Los paquetes se construyen **sin la entrada «.»**: `PharData`, que es con lo
# que descomprime el cliente, se niega a extraerla («Cannot extract "."»). De
# ahí que se nombren las carpetas una a una en vez de empaquetar el directorio.
set -euo pipefail
R="$(cd "$(dirname "$0")/.." && pwd)"
SRV=srv
DEST_WIN='C:\xampp\htdocs\venta-softland'

V="$(cat "$R/VERSION")"
TAG="v$V"
DIST="$R/dist"

# --- Lo que tiene que estar antes de publicar -------------------------------
if ! command -v gh >/dev/null 2>&1; then
    echo "falta gh (el cliente de GitHub): https://cli.github.com" >&2
    exit 1
fi

if ! gh auth status >/dev/null 2>&1; then
    echo "gh no ha iniciado sesión. Primero:  gh auth login" >&2
    exit 1
fi

if [[ -n "$(git -C "$R" status --porcelain)" ]]; then
    echo "hay cambios sin guardar: una publicación tiene que poder reconstruirse" >&2
    git -C "$R" status --short >&2
    exit 1
fi

if ! git -C "$R" rev-parse "$TAG" >/dev/null 2>&1; then
    echo "no existe la etiqueta $TAG" >&2
    echo "créala antes:  git tag -a $TAG -m \"...\" && git push --tags" >&2
    exit 1
fi

if gh release view "$TAG" >/dev/null 2>&1; then
    echo "la $TAG ya está publicada. Para rehacerla:  gh release delete $TAG" >&2
    exit 1
fi

APK="$R/venta-softland-$V.apk"
if [[ ! -f "$APK" ]]; then
    echo "no existe $APK — compílalo antes:  bash mobile/build-apk.sh" >&2
    exit 1
fi

rm -rf "$DIST"
mkdir -p "$DIST"

# --- 1) El código -----------------------------------------------------------
# La misma lista que bin/deploy.sh, y por el mismo motivo: es el código, y
# nada más. Ni .env ni storage/, donde viven la conexión cifrada, el
# certificado del DTE y los APK repartidos. Actualizar no puede llevarse por
# delante lo que identifica a esa instalación.
tar -C "$R" -czf "$DIST/venta-softland-$V-servidor.tar.gz" --exclude='bootstrap/cache' \
    app bootstrap config database lang resources routes public bin \
    artisan composer.json composer.lock phpunit.xml tests VERSION .env.example
echo "  código        $(du -h "$DIST/venta-softland-$V-servidor.tar.gz" | cut -f1)"

# --- 2) Las dependencias ----------------------------------------------------
# vendor/ sólo existe donde corre Composer, que es el servidor: aquí no hay
# PHP. Se empaqueta allá y se trae.
LOCK="$(sha256sum "$R/composer.lock" | cut -c1-12)"
VENDOR="vendor-$LOCK.tgz"

ssh "$SRV" "cmd /c \"cd /d $DEST_WIN && C:\\Windows\\System32\\tar.exe -czf %TEMP%\\$VENDOR vendor\"" >/dev/null
scp -q "$SRV:%TEMP%/$VENDOR" "$DIST/$VENDOR" 2>/dev/null \
    || scp -q "$SRV:C:/Users/ddecap/AppData/Local/Temp/$VENDOR" "$DIST/$VENDOR"
ssh "$SRV" "cmd /c \"del %TEMP%\\$VENDOR\"" >/dev/null 2>&1 || true
echo "  dependencias  $(du -h "$DIST/$VENDOR" | cut -f1)  ($LOCK)"

# --- 3) La app --------------------------------------------------------------
cp "$APK" "$DIST/venta-softland-$V.apk"
echo "  app           $(du -h "$DIST/venta-softland-$V.apk" | cut -f1)"

# --- 4) Las notas, que salen del historial de versiones ---------------------
python3 - "$R/docs/versiones.md" "$V" > "$DIST/notas.md" <<'PY'
import re, sys
ruta, v = sys.argv[1:3]
texto = open(ruta, encoding='utf-8').read()
m = re.search(r'^### ' + re.escape(v) + r' — (.+?)$\n(.*?)(?=^### |\Z)',
              texto, re.S | re.M)
if not m:
    sys.exit(f'no encuentro la entrada de la {v} en docs/versiones.md')
print(m.group(1).strip())
print()
print(m.group(2).strip())
PY
TITULO="$(head -1 "$DIST/notas.md")"

# --- 5) Publicar ------------------------------------------------------------
echo
gh release create "$TAG" \
    --title "$V — $TITULO" \
    --notes-file "$DIST/notas.md" \
    "$DIST/venta-softland-$V-servidor.tar.gz" \
    "$DIST/$VENDOR" \
    "$DIST/venta-softland-$V.apk"

echo
echo "publicada la $V"
echo "los servidores la ven en Configuración → Versión del servidor,"
echo "y desde la consola con:  php artisan ventas:actualizar --comprobar"
