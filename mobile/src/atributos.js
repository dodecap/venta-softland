import { idb } from './idb.js';

/*
 * Los campos que cada empresa define por su cuenta en el ERP.
 *
 * Softland tiene un mecanismo general para esto: cada maestro puede llevar
 * campos declarados **en la base**, no en el programa. La nota de venta es uno
 * de ellos, y de ahí salen dos de las líneas de la orden de compra al
 * distribuidor: «TIPO DE VENTA» y «OBSERVACIÓN».
 *
 * ## Lo que hay que tener presente antes de tocar esto
 *
 * **No hay ningún atributo garantizado.** INNOVAGES declara cuatro, NETDOMAIN
 * uno, y la empresa siguiente puede no declarar ninguno. Así que en este
 * archivo —y en la pantalla que lo usa— no se nombra ni un solo atributo: se
 * dibuja lo que haya bajado, y si no bajó nada no aparece la sección.
 *
 * ## Los cuatro tipos
 *
 * El `tipo` de la definición dice cómo se pide el valor y dónde lo guarda
 * Softland. Está comprobado mirando dónde acaban los valores de los nueve
 * atributos definidos entre las dos empresas:
 *
 *   1 es número, 2 es sí/no, 3 es fecha y 4 es una lista de opciones.
 */

/** Cómo se pide cada tipo. Lo que no esté aquí se pide como texto. */
export const CONTROL = { 1: 'numero', 2: 'si_no', 3: 'fecha', 4: 'lista' };

/**
 * Los atributos definidos, cada uno con sus opciones.
 *
 * Devuelve lista vacía cuando la empresa no define ninguno, que es un caso
 * normal y no un error.
 */
export async function definidos() {
    const [defs, opciones] = await Promise.all([
        idb.todos('nv_atributos'),
        idb.todos('nv_atributo_opciones'),
    ]);

    const porAtributo = new Map();

    for (const o of opciones || []) {
        if (! porAtributo.has(o.atributo)) porAtributo.set(o.atributo, []);
        porAtributo.get(o.atributo).push(o);
    }

    return (defs || [])
        .slice()
        .sort((a, b) => a.codigo - b.codigo)
        .map((d) => ({
            ...d,
            control: CONTROL[d.tipo] || 'texto',
            opciones: (porAtributo.get(d.codigo) || [])
                .slice()
                .sort((a, b) => String(a.nombre || '').localeCompare(String(b.nombre || ''), 'es')),
        }));
}

/**
 * Lo que vale cada atributo en un documento, como lo quiere el formulario:
 * `{ 2: 27, 1: '2026-03-31' }`.
 *
 * La opción `0` es «ninguna»: el maestro la trae así porque el servidor
 * convierte el nulo a entero al servir la página.
 */
export async function valoresDe(notaVenta) {
    const filas = await idb.porIndice('nv_atributo_valores', 'nota_venta', Number(notaVenta));
    const valores = {};

    for (const f of filas || []) {
        valores[f.atributo] = f.opcion
            ? f.opcion
            : (f.fecha ? String(f.fecha).slice(0, 10) : (f.texto || ''));
    }

    return valores;
}

/**
 * Un formulario vacío con todos los atributos declarados.
 *
 * Todos presentes y en blanco, no sólo los que tengan valor: el servidor
 * distingue «no viene» —no toca nada— de «viene vacío» —borra lo que hubiera—,
 * y el editor tiene que poder borrar un atributo dejándolo en blanco.
 */
export function vacios(defs) {
    return Object.fromEntries(defs.map((d) => [d.codigo, '']));
}

/** El valor en palabras, para enseñarlo en una ficha. */
export function comoTexto(def, valor) {
    if (valor === '' || valor === null || valor === undefined) return '';

    if (def.control === 'lista') {
        return def.opciones.find((o) => o.codigo === Number(valor))?.nombre || String(valor);
    }

    if (def.control === 'fecha') {
        const [a, m, d] = String(valor).slice(0, 10).split('-');
        return d ? `${d}-${m}-${a}` : String(valor);
    }

    return String(valor);
}
