/*
 * Guardia de iconografía. Corre antes de cada `npm run build`.
 *
 * Existe porque la regla «nada de emojis» se olvida sola: alguien pega un
 * glifo en una plantilla, se ve pasable en su teléfono, y al mes la app tiene
 * seis familias de iconos. Aquí falla la compilación y se acabó la discusión.
 *
 * Vigila dos cosas:
 *   1. Glifos usados como icono — emoji, dingbats, flechas tipográficas.
 *   2. Importar Lucide fuera del sistema: los iconos se eligen por concepto en
 *      `src/iconos.js`, no uno a uno en cada pantalla.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const RAIZ = new URL('../src', import.meta.url).pathname;
const PERMITEN_LUCIDE = ['iconos.js'];

const GLIFOS = new RegExp(
    '[\\u{1F000}-\\u{1FAFF}'   // emoji y pictogramas
    + '\\u{2190}-\\u{21FF}'    // flechas
    + '\\u{2300}-\\u{23FF}'    // símbolos técnicos
    + '\\u{2600}-\\u{27BF}'    // símbolos varios y dingbats
    + '\\u{27F0}-\\u{27FF}'    // flechas suplementarias
    + '\\u{2B00}-\\u{2BFF}'    // flechas y figuras
    + '\\u{FE0F}'              // selector de variación (emoji)
    + '\\u{FF0B}'              // signo más de ancho completo
    + '\\u{2039}\\u{203A}]',   // comillas angulares simples, usadas como «volver»
    'u',
);

const faltas = [];

function recorrer(dir) {
    for (const nombre of readdirSync(dir)) {
        const ruta = join(dir, nombre);
        if (statSync(ruta).isDirectory()) {
            recorrer(ruta);
            continue;
        }
        if (!/\.(vue|js|css)$/.test(nombre)) continue;

        const relativa = ruta.slice(RAIZ.length + 1);
        readFileSync(ruta, 'utf8').split('\n').forEach((linea, i) => {
            if (GLIFOS.test(linea)) {
                faltas.push(`${relativa}:${i + 1}  glifo usado como icono → usa <AppIcon name="…">`);
            }
            if (/from ['"]lucide-vue-next['"]/.test(linea) && !PERMITEN_LUCIDE.includes(relativa)) {
                faltas.push(`${relativa}:${i + 1}  importa Lucide directo → agrégalo a src/iconos.js y úsalo por concepto`);
            }
        });
    }
}

recorrer(RAIZ);

if (faltas.length) {
    console.error('\n  Iconografía fuera del sistema:\n');
    faltas.forEach((f) => console.error('   ' + f));
    console.error('\n  Ver src/iconos.js y src/components/AppIcon.vue.\n');
    process.exit(1);
}

console.log('Iconografía: sin emojis, una sola familia.');
