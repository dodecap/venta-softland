#!/usr/bin/env bash
# Compila el APK de debug. Requiere JDK 21 en ~/jdk21 y el Android SDK en ~/android-sdk.
set -euo pipefail
cd "$(dirname "$0")"

export JAVA_HOME="$HOME/jdk21"
export ANDROID_HOME="$HOME/android-sdk"
export ANDROID_SDK_ROOT="$HOME/android-sdk"
export PATH="$JAVA_HOME/bin:$ANDROID_HOME/platform-tools:$PATH"

npm install --no-audit --no-fund
npm run build
npx cap sync android

cd android
[ -f local.properties ] || echo "sdk.dir=$HOME/android-sdk" > local.properties
./gradlew assembleDebug --no-daemon

# El nombre lleva la versión. Es la primera pregunta de cualquier soporte —«¿qué
# versión tienes?»— y con todos los APK llamándose igual, el que está en el
# teléfono y el que está en la carpeta de descargas son indistinguibles. Sale de
# VERSION, como todo lo demás.
cd ../..
VERSION="$(tr -d ' \t\r\n' < VERSION)"
APK="venta-softland-${VERSION}.apk"

cp mobile/android/app/build/outputs/apk/debug/app-debug.apk "$APK"

# Y se borran los de versiones anteriores: son 5 MB cada uno y el único que
# sirve es el último. Se queda el que se acaba de escribir.
find . -maxdepth 1 -name 'venta-softland*.apk' ! -name "$APK" -delete

echo
echo "APK: $APK (en la raíz del repo)"
