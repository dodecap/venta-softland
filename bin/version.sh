#!/usr/bin/env bash
# Sube el número de versión del proyecto.
#
#   bin/version.sh menor "Panel comercial: rendimiento y actividad real"
#
# La versión vive en el archivo VERSION de la raíz y de ahí la leen la SPA, el
# APK y la API. Este script la sube, sincroniza package.json y abre la entrada
# en docs/versiones.md. No hace commit ni etiqueta: eso lo decide quien trabaja.
set -euo pipefail
R="$(cd "$(dirname "$0")/.." && pwd)"

tramo="${1:-}"
titulo="${2:-}"

if [[ ! "$tramo" =~ ^(mayor|menor|parche)$ || -z "$titulo" ]]; then
    echo "uso: bin/version.sh mayor|menor|parche \"Título de la versión\"" >&2
    echo "actual: $(cat "$R/VERSION")" >&2
    exit 1
fi

IFS=. read -r may men par < "$R/VERSION"

case "$tramo" in
    mayor) may=$((may + 1)); men=0; par=0 ;;
    menor) men=$((men + 1)); par=0 ;;
    parche) par=$((par + 1)) ;;
esac

nueva="$may.$men.$par"
codigo=$((may * 10000 + men * 100 + par))
hoy="$(date +%F)"

echo "$nueva" > "$R/VERSION"

# package.json: npm quiere la versión dentro, y si se queda atrás confunde.
# El versionName y el versionCode del APK los calcula Gradle leyendo VERSION.
node -e '
const fs = require("fs");
const p = process.argv[1];
const j = JSON.parse(fs.readFileSync(p, "utf8"));
j.version = process.argv[2];
fs.writeFileSync(p, JSON.stringify(j, null, 2) + "\n");
' "$R/mobile/package.json" "$nueva"

# La entrada nueva va arriba del historial, bajo la marca.
marca='<!-- nuevas entradas arriba -->'
python3 - "$R/docs/versiones.md" "$marca" "$nueva" "$titulo" "$hoy" <<'PY'
import sys
ruta, marca, nueva, titulo, hoy = sys.argv[1:6]
texto = open(ruta, encoding='utf-8').read()
entrada = f"{marca}\n\n### {nueva} — {titulo}\n*{hoy}*\n\n- \n"
open(ruta, 'w', encoding='utf-8').write(texto.replace(marca + '\n', entrada, 1))
PY

cat <<FIN
$nueva  (versionCode $codigo)

Escrito en VERSION, mobile/package.json y docs/versiones.md.
Falta anotar qué trae la versión en docs/versiones.md, y después:

    git commit -am "$titulo"
    git tag -a v$nueva -m "$titulo"
FIN
