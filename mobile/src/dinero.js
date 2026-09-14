/*
 * Cómo se escribe un peso en el panel.
 *
 * Un panel de 360 px no puede llevar «$ 127.412.880»: son doce caracteres que
 * empujan a la tarjeta de al lado y que además nadie lee de un vistazo — lo
 * que el vendedor necesita saber es «127 millones», no el peso exacto.
 *
 * La regla es que **el formato no toca el cálculo**. Aquí entra un número y
 * sale un texto. El valor exacto sigue viajando igual y se ve al entrar al
 * detalle, donde sí hay sitio y sí importa el peso.
 *
 * Chile: coma decimal, punto de miles.
 */

/** Cuando no hay dato. No es «$0»: cero es un dato y esto es su ausencia. */
export const SIN_DATO = '—';

const ES = 'es-CL';

function numero(v, decimales = 0) {
    return v.toLocaleString(ES, { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
}

/** ¿Es un número con el que se pueda hacer algo? `null`, `''` y `NaN` no lo son. */
export function hayDato(v) {
    return v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
}

/**
 * El peso como se muestra en una tarjeta del panel.
 *
 *   840            da  $840
 *   84.500         da  $84.500
 *   850.000        da  $850 mil
 *   1.500.000      da  $1,5 MM
 *   127.412.880    da  $127,4 MM
 *   1.250.000.000  da  $1.250 MM
 *
 * Por debajo de cien mil se escribe entero: «$84.500» cabe y es la cifra que
 * el vendedor reconoce. De ahí para arriba se abrevia, porque la precisión
 * deja de aportar antes de que deje de ocupar.
 */
export function dinero(v) {
    if (! hayDato(v)) return SIN_DATO;

    const n = Number(v);
    const signo = n < 0 ? '-' : '';
    const a = Math.abs(n);

    if (a < 100000) return `${signo}$${numero(Math.round(a))}`;

    // El corte se mira ya redondeado: 999.999 son mil miles, o sea un millón.
    // Sin esto sale «$1.000 mil», que es la abreviatura más larga que el número.
    if (Math.round(a / 1000) < 1000) return `${signo}$${numero(Math.round(a / 1000))} mil`;

    const mm = a / 1000000;

    // Con cuatro cifras de millones la decimal ya no se lee: «$1.250,4 MM» son
    // once caracteres para decir lo mismo que «$1.250 MM».
    if (mm >= 1000) return `${signo}$${numero(Math.round(mm))} MM`;

    // La decimal se decide sobre el valor ya redondeado: 0,999999 millones son
    // «$1 MM», no «$1,0 MM».
    const r = Math.round(mm * 10) / 10;

    return `${signo}$${numero(r, r % 1 === 0 ? 0 : 1)} MM`;
}

/** El peso completo, para el detalle: `$127.412.880`. */
export function dineroExacto(v) {
    if (! hayDato(v)) return SIN_DATO;

    const n = Math.round(Number(v));

    return `${n < 0 ? '-' : ''}$${numero(Math.abs(n))}`;
}

/** La nota al pie que explica la abreviatura, sólo si en la pantalla hay alguna. */
export function leyenda(valores) {
    const hay = valores.filter(hayDato).map((v) => Math.abs(Number(v)));

    if (hay.some((v) => v >= 1000000)) return 'MM = millones de pesos';
    if (hay.some((v) => v >= 100000)) return 'mil = miles de pesos';

    return '';
}

/**
 * Variación contra el período anterior.
 *
 * Devuelve `null` cuando no se puede comparar — sin período anterior, o con
 * cero de denominador. Un «sube infinito por ciento» no informa de nada y ocupa
 * el sitio de algo que sí; es preferible no dibujar el indicador.
 *
 * La flecha no viene en el texto: viene `direccion`, y la dibuja `<AppIcon>`.
 * Una flecha tipográfica no hereda el grosor de trazo del resto de la tarjeta
 * y se ve distinta en cada teléfono — la misma razón por la que aquí no hay
 * emojis.
 */
export function variacion(actual, anterior) {
    if (! hayDato(actual) || ! hayDato(anterior)) return null;

    const a = Number(actual);
    const b = Number(anterior);

    if (b === 0) return null;

    const pct = ((a - b) / Math.abs(b)) * 100;

    return { pct, direccion: direccionDe(pct), texto: `${numero(Math.abs(pct), 1)}%` };
}

/**
 * Variación de una tasa. En puntos porcentuales, no en porcentaje: de 61 % a
 * 65 % la conversión subió 4 pp, no 6,6 %. Confundirlos es el error clásico
 * de un panel comercial.
 */
export function puntos(actual, anterior) {
    if (! hayDato(actual) || ! hayDato(anterior)) return null;

    const d = Number(actual) - Number(anterior);

    return { pp: d, direccion: direccionDe(d), texto: `${numero(Math.abs(d), 1)} pp` };
}

/**
 * Hacia dónde se movió. El panel lo usa para elegir icono y color, y el color
 * nunca es la única señal: la flecha va siempre.
 */
function direccionDe(v) {
    return v > 0 ? 'sube' : v < 0 ? 'baja' : 'igual';
}

/** Un porcentaje de panel: una decimal, y sin decimal cuando es redondo. */
export function porcentaje(v, decimales = 1) {
    if (! hayDato(v)) return SIN_DATO;

    const n = Number(v);

    return `${numero(n, n % 1 === 0 ? 0 : decimales)}%`;
}

/** Días de ciclo: «3,7 d». */
export function dias(v) {
    if (! hayDato(v)) return SIN_DATO;

    const n = Number(v);

    return `${numero(n, n % 1 === 0 ? 0 : 1)} d`;
}
