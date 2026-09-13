import { ref } from 'vue';
import { db } from './db';

/**
 * Tamaño de la interfaz.
 *
 * No es un capricho de diseño: el mismo panel se ve apretado en un teléfono
 * de 360 px y sobrado en uno de 412, y un vendedor de sesenta años no lee lo
 * mismo que uno de veinticinco. En vez de discutir el número correcto, la
 * escala es un multiplicador y la elige quien usa el aparato.
 *
 * Todo lo que crece o se achica cuelga de la variable CSS `--d`. Los tamaños
 * base están calibrados para `--d: 1` sobre un ancho de 360 px, que es el más
 * común en Android. Los pisos que NO se cruzan, pase lo que pase con la
 * escala: 44 px de área pulsable y 16 px en los campos de formulario (bajo eso
 * Android hace zoom al enfocar).
 */
const ESCALAS = { compacta: 0.92, normal: 1, amplia: 1.1 };

export const ETIQUETAS = {
    compacta: 'Compacta',
    normal: 'Normal',
    amplia: 'Amplia',
};

export const densidad = ref('normal');

/** Multiplicador vigente. Para los tamaños que se fijan en JS (iconos). */
export const factor = ref(1);

function aplicar(nombre) {
    const f = ESCALAS[nombre] ?? 1;
    densidad.value = nombre;
    factor.value = f;
    document.documentElement.style.setProperty('--d', String(f));
}

/** Escala un tamaño base en px. Redondeado: medio píxel de trazo se ve sucio. */
export function px(base) {
    return Math.round(base * factor.value);
}

export async function cargarDensidad() {
    aplicar(await db.getDensidad());
}

export async function cambiarDensidad(nombre) {
    if (!ESCALAS[nombre]) return;
    aplicar(nombre);
    await db.setDensidad(nombre);
}
