import { readFileSync } from 'node:fs';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// La versión que muestra la pantalla de Cuenta sale de package.json: un solo
// número que mantener, y no uno escrito a mano que se queda atrás.
const pkg = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8'));

export default defineConfig({
    plugins: [vue()],
    base: './',
    define: { __VERSION__: JSON.stringify(pkg.version) },
});
