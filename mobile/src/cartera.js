/*
 * Lo que el cliente debe: los tramos de antigüedad y las cuentas de la lista.
 *
 * Vive aparte de la pantalla por la razón de siempre: la lista filtra por
 * tramo y el resumen de arriba cuenta por tramo, y dos copias de la misma
 * regla son una cabecera que dice «3 vencidas» sobre una lista que enseña 4.
 * Cuando el panel tenga su indicador de cobranza, leerá de aquí.
 *
 * **La antigüedad se mide contra el vencimiento, no contra la emisión.** Que
 * en INNOVAGES den lo mismo —`MovFe` y `MovFv` coinciden en 223 de los 225
 * cargos, porque esta empresa no usa vencimientos— no convierte una cosa en la
 * otra: son campos distintos y otra empresa sí los va a usar. Cuando el
 * vencimiento falta, se cae a la emisión, que es lo único que queda.
 */

/**
 * Los tramos, del más urgente al menos.
 *
 * El orden importa: `tramoDe()` devuelve el primero que calza, así que van de
 * más días a menos y lo que no vence todavía queda al final.
 *
 * El color dice cuán vieja es la deuda, y **nunca va solo**: cada tramo lleva
 * su rótulo en palabras, que es lo que se lee en la etiqueta.
 */
export const TRAMOS = [
    { id: 'mas90', rotulo: 'Más de 90 días', desde: 91, color: 'rojo' },
    { id: 'd90', rotulo: '61 a 90 días', desde: 61, color: 'rojo' },
    { id: 'd60', rotulo: '31 a 60 días', desde: 31, color: 'amarillo' },
    { id: 'd30', rotulo: 'Hasta 30 días', desde: 1, color: 'amarillo' },
    { id: 'hoy', rotulo: 'Vence hoy', desde: 0, color: 'cian' },
    { id: 'por_vencer', rotulo: 'Por vencer', desde: -Infinity, color: 'verde' },
];

/**
 * Días transcurridos desde el vencimiento. Positivo = atrasado.
 *
 * Las fechas se cortan a `YYYY-MM-DD` y se rearman con `T00:00:00` antes de
 * construir el `Date`, que es como lo hace el resto de la app
 * (`panel/metricas.js`). No es manía: `new Date('2026-09-28')` es medianoche
 * **UTC** y `new Date('2026-09-28T00:00:00')` es medianoche local, y en Chile
 * eso son días distintos. Un documento que vence hoy salía con un día de
 * atraso.
 */
export function diasVencido(doc, hoy = new Date()) {
    const a = medianoche(doc?.vencimiento || doc?.emision);
    const b = medianoche(hoy);

    if (a === null || b === null) return null;

    return Math.round((b - a) / 86400000);
}

/** Medianoche local del día de una fecha, venga como texto o como `Date`. */
function medianoche(valor) {
    if (! valor) return null;

    const texto = valor instanceof Date
        ? `${valor.getFullYear()}-${String(valor.getMonth() + 1).padStart(2, '0')}-${String(valor.getDate()).padStart(2, '0')}`
        : String(valor).slice(0, 10);

    const t = new Date(`${texto}T00:00:00`).getTime();

    return Number.isNaN(t) ? null : t;
}

/** En qué tramo cae un documento. Sin fecha no hay tramo, y se dice. */
export function tramoDe(doc, hoy = new Date()) {
    const d = diasVencido(doc, hoy);
    if (d === null) return null;

    return TRAMOS.find((t) => d >= t.desde) || null;
}

/** ¿Está atrasado? Es el mismo umbral que usa el tramo: un día es atraso. */
export function vencido(doc, hoy = new Date()) {
    const d = diasVencido(doc, hoy);

    return d !== null && d >= 1;
}

/**
 * El resumen de una cartera: cuánto, cuántos y cómo se reparte.
 *
 * Devuelve los tramos **en su orden**, con los vacíos fuera: un resumen que
 * enseña «0 documentos entre 31 y 60 días» gasta una fila en decir que no pasa
 * nada.
 */
export function resumen(docs, hoy = new Date()) {
    const filas = TRAMOS.map((t) => ({ ...t, n: 0, monto: 0 }));
    const porId = Object.fromEntries(filas.map((f) => [f.id, f]));
    let total = 0;
    let atrasado = 0;
    let sinFecha = 0;

    for (const d of docs || []) {
        const saldo = Number(d.saldo) || 0;
        total += saldo;

        const t = tramoDe(d, hoy);
        if (! t) { sinFecha++; continue; }

        porId[t.id].n++;
        porId[t.id].monto += saldo;
        if (diasVencido(d, hoy) >= 1) atrasado += saldo;
    }

    return {
        documentos: (docs || []).length,
        total,
        atrasado,
        sinFecha,
        tramos: filas.filter((f) => f.n > 0),
    };
}

/**
 * Agrupa por cliente, que es como se cobra.
 *
 * A nadie se le cobra una factura suelta: se le llama a una persona y se le
 * habla de todo lo que debe. Por eso la lista es de clientes y los documentos
 * cuelgan de cada uno.
 *
 * Ordena por lo más atrasado primero y, a igualdad, por monto: el criterio es
 * a quién hay que llamar hoy.
 */
export function porCliente(docs, hoy = new Date()) {
    const mapa = new Map();

    for (const d of docs || []) {
        if (! mapa.has(d.cliente)) {
            mapa.set(d.cliente, { cliente: d.cliente, documentos: [], total: 0, dias: null });
        }
        const g = mapa.get(d.cliente);
        g.documentos.push(d);
        g.total += Number(d.saldo) || 0;

        const dv = diasVencido(d, hoy);
        if (dv !== null && (g.dias === null || dv > g.dias)) g.dias = dv;
    }

    return [...mapa.values()].sort(
        (a, b) => (b.dias ?? -Infinity) - (a.dias ?? -Infinity) || b.total - a.total,
    );
}
