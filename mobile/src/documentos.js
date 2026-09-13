import { idb } from './idb';

/*
 * Cotizaciones y notas de venta: lo que tienen en común.
 *
 * Son el mismo documento en dos momentos de su vida — la cotización se
 * convierte en nota de venta y la NV guarda de qué cotización vino — así que la
 * app las dibuja con la misma pantalla y solo cambia esta tabla.
 *
 * Los estados y su lectura están medidos contra las 2.350 cotizaciones y las
 * 800 notas de venta reales de INNOVAGES; están en `docs/flujo-ventas-softland.md`.
 */

export const TIPOS = {
    cotizacion: {
        titulo: 'Cotizaciones',
        singular: 'Cotización',
        icono: 'cotizacion',
        ruta: '/cotizaciones',
        almacen: 'cotizaciones',
        lineas: 'cotizacion_lineas',
        indiceLineas: 'cotizacion',
        estados: {
            N: { rotulo: 'En curso', color: 'cian' },
            V: { rotulo: 'Vendida', color: 'verde' },
            R: { rotulo: 'Perdida', color: 'rojo' },
            P: { rotulo: 'Pendiente', color: 'amarillo' },
            A: { rotulo: 'Anulada', color: 'gris' },
        },
    },
    nota_venta: {
        titulo: 'Notas de venta',
        singular: 'Nota de venta',
        icono: 'notaVenta',
        ruta: '/notas-venta',
        almacen: 'notas_venta',
        lineas: 'nota_venta_lineas',
        indiceLineas: 'nota_venta',
        estados: {
            A: { rotulo: 'Aprobada', color: 'verde' },
            N: { rotulo: 'Nueva', color: 'cian' },
            P: { rotulo: 'Pendiente', color: 'amarillo' },
            C: { rotulo: 'Cerrada', color: 'gris' },
        },
    },
};

/** Estado legible. Un código que no está en la tabla se muestra tal cual. */
export function estado(tipo, codigo) {
    const c = String(codigo || '').trim().toUpperCase();
    return TIPOS[tipo].estados[c] ?? { rotulo: c || 'Sin estado', color: 'gris' };
}

/**
 * Las líneas de un documento, en orden y con el precio ya convertido.
 *
 * Softland guarda el precio de la línea en la moneda **del producto** y el
 * total en la del documento; `equiv` es el factor entre las dos. Un tercio de
 * las líneas de INNOVAGES lo tiene distinto de 1 — el producto está en UF y la
 * cotización en pesos — así que mostrar `precio` a secas dice «1 UNIDAD × $ 5
 * = $ 214.735», que no cuadra ni con una calculadora en la mano.
 *
 * Cada línea sale de aquí con dos campos añadidos: `unitario`, que es lo que
 * hay que mostrar junto al total, y `moneda_origen`, para poder escribir «5,25
 * UF» debajo cuando el precio venía en otra moneda.
 */
export async function lineasDe(tipo, numero) {
    const def = TIPOS[tipo];
    const filas = await idb.porIndice(def.lineas, def.indiceLineas, Number(numero));
    filas.sort((a, b) => a.linea - b.linea);

    for (const l of filas) {
        const factor = l.equiv || 1;
        l.unitario = (l.precio || 0) * factor;
        // La moneda del precio original sale de la ficha del producto. Puede no
        // estar — líneas de texto suelto, productos dados de baja — y entonces
        // se muestra el número sin símbolo antes que mentir con un «$».
        l.moneda_origen = factor === 1 ? null : (await idb.obtener('productos', l.producto))?.moneda ?? '';
    }

    return filas;
}

/**
 * Cuánto falta por facturar de una nota de venta.
 *
 * Se mira línea por línea y no en el encabezado a propósito: los flags
 * `nvEstFact` y `nvEstDesp` están en 0 en las 800 notas de venta de INNOVAGES,
 * Softland no los usa. El avance real vive en `nvCantFact` de cada línea.
 */
export function avanceFacturacion(lineas) {
    const pedido = lineas.reduce((n, l) => n + (l.cantidad || 0), 0);
    const facturado = lineas.reduce((n, l) => n + (l.facturado || 0), 0);
    if (! pedido) return null;

    return {
        pedido,
        facturado,
        pct: Math.round((facturado / pedido) * 100),
        completo: facturado >= pedido,
    };
}
