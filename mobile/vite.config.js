import { readFileSync } from 'node:fs';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// La versión sale del archivo VERSION de la raíz del repositorio: un solo
// número que mantener, el mismo que leen el APK y la API. `package.json` lo
// repite porque npm lo pide, y `bin/version.sh` lo mantiene sincronizado.
const version = readFileSync(new URL('../VERSION', import.meta.url), 'utf8').trim();

export default defineConfig({
    plugins: [vue()],
    base: './',
    define: { __VERSION__: JSON.stringify(version) },
});
