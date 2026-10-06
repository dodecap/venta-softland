#!/usr/bin/env bash
# Arma el paquete de instalación de Venta Softland: un ZIP que deja la
# aplicación servida y `/setup` en pie, sin consola, sin Composer y sin internet
# en el servidor donde se instala.
#
#   bash mobile/build-apk.sh        # primero se compila la app
#   bin/empaquetar.sh              # y después se empaqueta
#   bin/empaquetar.sh --solo-zip   # igual, sin la nota del .exe que falta
#
# Sale en dist/:
#   venta-softland-<version>-instalador.zip
#
# ## Qué resuelve, y qué le queda por resolver
#
# El ZIP deja la aplicación servida y `/setup` en pie, y eso es la mitad. La
# otra mitad no se puede hacer desde un navegador y es siempre la misma: **XAMPP
# no trae `sqlsrv` ni `pdo_sqlsrv`**, así que recién descomprimido el paso 1 de
# `/setup` sale en rojo y hay que bajar unas DLL de Microsoft, acertar con la
# variante —la de hilos y 64 bits, que es la única que sirve en un XAMPP—,
# copiarlas, editar `php.ini` y reiniciar Apache. Eso es lo que hará el `.exe`,
# que es el paso siguiente de este mismo plan. Hasta que exista, el ZIP es el
# único camino y la lista de lo que falta la dice `/setup`, que para eso mira el
# servidor **antes** de dibujar el formulario.
#
# ## Por qué se descomprime y ya está
#
# Desde la 0.52.0 el `.htaccess` y el `index.php` de la raíz sirven la
# aplicación desde dentro de `htdocs` —el puente— y `deploy/arranque.php` crea
# el `.env`, la `APP_KEY` y las carpetas de escritura en la primera petición. Sin
# eso había que editar `httpd.conf` para poner un `Alias`, que es pedirle a quien
# instala que toque la configuración de un Apache que ya sirve otras cosas.
#
# ## Por qué el `vendor/` no se construye aquí
#
# Porque en esta máquina no hay PHP —es la de desarrollo, aquí se compilan la
# SPA y el APK— y las dependencias las resuelve Composer, que es PHP.
#
# Y no se construye otro: se **reusa el mismo** `vendor-<sha256 del
# composer.lock>.tgz` que publica `bin/publicar-version.sh`. El nombre lleva la
# huella de `composer.lock` dentro, así que no puede quedarse viejo sin que se
# note, y sobre todo son **los mismos bytes** que ese servidor va a recibir
# cuando se actualice. Construir uno aparte sería tener dos `vendor` para la
# misma versión, y el día que difirieran el error saldría en casa de un cliente.
#
# ## Qué no entra
#
# Ni `.env`, ni la conexión cifrada, ni el certificado del DTE, ni los
# registros, ni `.git`. Un paquete se manda por WhatsApp y se reenvía; con un
# `.env` dentro va la clave que descifra la conexión a SQL Server de otra
# empresa y la del certificado con el que esa empresa firma sus facturas. Al
# final hay una comprobación que aborta si algo de eso se coló.
#
# Tampoco entra `bin/` entero, aunque `bin/deploy.sh` sí lo mande a producción:
# esos guiones llevan dentro el alias `srv` y rutas de esta máquina. De `bin`
# viaja `instalar.cmd`, que es el único escrito para quien instala.
set -euo pipefail

SOLO_ZIP=0
if [[ "${1:-}" == '--solo-zip' ]]; then
    SOLO_ZIP=1
    shift
fi

R="$(cd "$(dirname "$0")/.." && pwd)"
V="$(cat "$R/VERSION")"
SRV="${1:-srv}"

APK="$R/venta-softland-$V.apk"
ZIP="$R/dist/venta-softland-$V-instalador.zip"
CARPETA='venta-softland'   # La que se crea al descomprimir. Es un nombre por
                           # omisión y nada más: el `.htaccess` del puente no
                           # lleva `RewriteBase`, así que quien instala puede
                           # renombrarla sin que nada deje de resolver.

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

morir() { echo "  ✕ $*" >&2; exit 1; }
paso() { echo "  · $*"; }

[[ -f "$APK" ]] || morir "no existe $APK
    compílalo antes:  bash mobile/build-apk.sh"

mkdir -p "$R/dist"
echo
echo "  Venta Softland $V — paquete de instalación"
echo

# ------------------------------------------------------------------- vendor --
#
# La misma huella y el mismo nombre que `bin/publicar-version.sh`, a propósito:
# es el mismo archivo y se comparte la caché.
LOCK="$(sha256sum "$R/composer.lock" | cut -c1-12)"
CACHE="$R/dist/vendor-$LOCK.tgz"

if [[ -f "$CACHE" ]]; then
    paso "vendor: $(du -h "$CACHE" | cut -f1) en caché ($LOCK)"
else
    paso "vendor: no está en caché, se trae de $SRV"

    DEST_WIN='C:\xampp\htdocs\venta-softland'
    ssh "$SRV" "cmd /c \"cd /d $DEST_WIN && C:\\Windows\\System32\\tar.exe -czf %TEMP%\\vendor-$LOCK.tgz vendor\"" >/dev/null \
        || morir "no se pudo empaquetar el vendor en $SRV"
    scp -q "$SRV:%TEMP%/vendor-$LOCK.tgz" "$CACHE" 2>/dev/null \
        || scp -q "$SRV:C:/Users/ddecap/AppData/Local/Temp/vendor-$LOCK.tgz" "$CACHE" \
        || morir "no se pudo traer el vendor de $SRV"
    ssh "$SRV" "cmd /c \"del %TEMP%\\vendor-$LOCK.tgz\"" >/dev/null 2>&1 || true

    paso "vendor: $(du -h "$CACHE" | cut -f1) traído y guardado ($LOCK)"
fi

# -------------------------------------------------------------------- árbol --
DEST="$TMP/paquete/$CARPETA"
mkdir -p "$DEST"

# `bootstrap/cache` no viaja: lo que hay dentro son rutas absolutas de la
# máquina que lo generó. Se rehace en la primera petición.
#
# `tests` y `phpunit.xml` tampoco: no hacen falta para que el servidor corra, y
# en el servidor de un cliente no se corre ninguna prueba.
tar -C "$R" -cf - --exclude='bootstrap/cache' \
    app bootstrap config database lang resources routes public deploy \
    artisan composer.json composer.lock .env.example VERSION .htaccess index.php \
    | tar -C "$DEST" -xf -

tar -C "$DEST" -xzf "$CACHE"

mkdir -p "$DEST/bootstrap/cache" \
         "$DEST/storage/app/private/apk" \
         "$DEST/storage/framework/cache/data" \
         "$DEST/storage/framework/sessions" \
         "$DEST/storage/framework/views" \
         "$DEST/storage/logs" \
         "$DEST/bin" "$DEST/docs"

# El APK sembrado dentro. Sin esto `/app` sale vacío el primer día: la otra
# forma de llenarlo es `ventas:actualizar --apk`, que necesita internet justo
# donde puede no haberlo, y el código QR de `/app` es lo primero que se usa
# después de instalar.
cp "$APK" "$DEST/storage/app/private/apk/"

cp "$R/bin/instalar.cmd" "$DEST/bin/"
cp "$R/docs/instalacion.md" "$DEST/docs/"

paso "app: venta-softland-$V.apk ($(du -h "$APK" | cut -f1))"

# -------------------------------------------------------------------- LEEME --
#
# Con marca de orden de bytes: es un .txt que se abre con el Notepad de Windows
# y sin ella las tildes salen como otra cosa.
{
printf '\xEF\xBB\xBF'
cat <<LEEME
Venta Softland $V — instalación
================================

Flujo de ventas de Softland de punta a punta —cotización, nota de venta,
factura o boleta electrónica, cobranza— con app Android para el vendedor en
terreno, que trabaja sin señal y sincroniza al volver la red.


QUÉ HAY QUE HACER
-----------------

  1. Descomprime este ZIP dentro de C:\\xampp\\htdocs

     Queda C:\\xampp\\htdocs\\$CARPETA. La carpeta puede renombrarse —la
     aplicación no lleva su nombre escrito dentro—, pero conviene que sea
     corta: vendor/ tiene rutas largas y Windows no pasa de 260 caracteres.

  2. Abre en el navegador:

         http://localhost/$CARPETA/

     No hay que editar httpd.conf ni reiniciar Apache. Si el Apache de este
     servidor no escucha en el 80, el puerto va en la dirección:

         http://localhost:8080/$CARPETA/

  3. La página comprueba el servidor y dice qué falta antes de pedir nada. Lo
     que casi siempre falta son las dos extensiones de SQL Server: mira más
     abajo.

  4. Después pide la conexión: la instancia de SQL Server, la base de datos de
     la empresa, un usuario de SQL con su contraseña, y la contraseña del
     usuario «softland» de la propia Softland, que es quien autoriza instalar.

     Antes de guardar comprueba que la base tenga lo que la aplicación lee, y
     dice con qué se va a quedar corta. Una base sin las tablas del documento
     tributario sirve para vender: lo que no va a poder es facturar.

  5. Al terminar sale el código QR con el que se instala la app en los
     teléfonos. Ese código está también en /$CARPETA/app, y esa dirección no
     caduca: es la que se imprime y se reparte.


QUÉ HACE FALTA EN EL SERVIDOR
-----------------------------

  · XAMPP con PHP 8.3 o superior.

  · Las extensiones sqlsrv y pdo_sqlsrv de PHP, que NO vienen con XAMPP. Se
    bajan de learn.microsoft.com/sql/connect/php/ para la versión de PHP
    instalada —hace falta la variante con hilos (ts) y de 64 bits, que es la
    que usa XAMPP—, se copian los .dll en C:\\xampp\\php\\ext y se añaden a
    C:\\xampp\\php\\php.ini:

        extension=php_sqlsrv.dll
        extension=php_pdo_sqlsrv.dll

    Y se reinicia Apache.

  · El driver ODBC de Microsoft para SQL Server (busca «msodbcsql»). Es otra
    cosa distinta de las extensiones de arriba, y hacen falta las dos. Sirve
    el 17 o el 18: si ya hay uno de los dos instalado, no hay nada que hacer.

  · Estas otras extensiones, que XAMPP sí trae y normalmente ya están
    encendidas en php.ini: openssl, mbstring, fileinfo, gd, dom y curl.

  · La base de datos Softland de la empresa, alcanzable desde este servidor.
    Es lo único de esta lista que no se arregla instalando algo.

  · Un usuario de SQL Server que pueda crear un esquema y sus tablas en ella.
    La aplicación crea un esquema propio, «ventas», y no modifica ninguna
    tabla de Softland. Lo que haya tocado se puede ver después en cualquier
    momento con:

        php artisan ventas:huella

  No hace falta Composer, ni Node, ni internet: todo viaja dentro del paquete,
  incluido el instalable de la app Android.

  Para que los teléfonos lleguen hacen falta dos cosas más: el puerto de Apache
  abierto en el Firewall de Windows, y que los teléfonos alcancen a este
  servidor —en la misma red, o por un proxy publicado hacia afuera.


DESPUÉS
-------

  No hay más páginas web que ésta y /$CARPETA/app. Usuarios, configuración,
  avisos y actualizaciones se administran desde la propia app, entrando con un
  usuario con rol admin.

  El certificado digital del DTE no se copia a ninguna carpeta ni se escribe en
  ningún archivo: se sube desde la app, y su clave queda cifrada.

  El detalle está en docs\\instalacion.md
LEEME
} > "$DEST/LEEME.txt"

# ------------------------------------------------ que no se cuele un secreto --
#
# `vendor/` se mira sólo para lo que nunca es de Composer: lo demás de ahí
# dentro es suyo y da falsos positivos.
#
# `.env.example` es la excepción y tiene que estar: es la plantilla con la que
# `deploy/arranque.php` escribe el `.env` de la instalación. Lo que no puede
# estar es cualquier **otro** `.env.algo`, que es como se llaman los de verdad
# —y como se llama el respaldo que quedó en `srv` el día del depurador.
FUERA="$(find "$DEST" \( -name '.env' -o -name 'softland.json' -o -name '.git' \
    -o -name 'actualizacion.json' \) -print)"
FUERA="$FUERA$(find "$DEST" -path "$DEST/vendor" -prune -o \
    \( -name '*.log' -o -name '*.pem' -o -name '*.key' -o -name '*.pfx' \
       -o -name '*.p12' -o -name '*.crt' \
       -o \( -name '.env.*' ! -name '.env.example' \) \) -print)"

if [[ -n "${FUERA//[[:space:]]/}" ]]; then
    echo "$FUERA" | sed 's/^/      /' >&2
    morir "eso no puede viajar en el paquete"
fi

# Y que esté lo que tiene que estar: un paquete al que le falte el puente se
# descomprime igual y no sirve de nada, y eso no se ve hasta abrir el navegador
# en casa del cliente.
for IMPRESCINDIBLE in .htaccess index.php deploy/arranque.php .env.example \
                      vendor/autoload.php public/index.php LEEME.txt \
                      "storage/app/private/apk/venta-softland-$V.apk"; do
    [[ -e "$DEST/$IMPRESCINDIBLE" ]] || morir "falta $IMPRESCINDIBLE en el paquete"
done

# --------------------------------------------------------------------- ZIP --
rm -f "$ZIP"
(cd "$TMP/paquete" && zip -q -r -X "$ZIP" "$CARPETA")

echo
echo "  $ZIP"
echo "    $(du -h "$ZIP" | cut -f1) · $(find "$DEST" -type f | wc -l) archivos dentro"
echo "    sha256 $(sha256sum "$ZIP" | cut -c1-16)…"
echo
echo "  Se descomprime en C:\\xampp\\htdocs y se abre http://localhost/$CARPETA/"

if [[ "$SOLO_ZIP" == 0 ]]; then
    echo
    echo "  El .exe todavía no existe: es el paso siguiente del plan, y es el que"
    echo "  pone las DLL de SQL Server que XAMPP no trae. Hasta entonces eso queda"
    echo "  a mano, y /setup lo reclama."
fi
echo
