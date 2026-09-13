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

cp app/build/outputs/apk/debug/app-debug.apk ../../venta-softland.apk
echo
echo "APK: venta-softland.apk (en la raíz del repo)"
