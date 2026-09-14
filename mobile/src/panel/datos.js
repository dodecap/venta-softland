/*
 * De dónde saca el panel sus números: de IndexedDB, no del servidor.
 *
 * Dos lecturas completas por apertura. Son 185 cotizaciones y 51 notas de
 * venta de doce meses; leerlas enteras y filtrar en memoria es más rápido que
 * abrir cuatro cursores por índice, y sobre todo es lo que funciona sin señal.
 *
 * `metricas.js` no sabe que esto existe: recibe arreglos.
 */

import { idb } from '../idb';
import { calcular, pendientes, ultimos } from './metricas';

export async function panel({ rango, comparar = null, vendedores = null, hoy, vigencia = 30, recientes = 5 }) {
    const [cotizaciones, notas] = await Promise.all([
        idb.todos('cotizaciones'),
        idb.todos('notas_venta'),
    ]);

    return {
        actual: calcular({ cotizaciones, notas, rango, vendedores }),
        anterior: comparar ? calcular({ cotizaciones, notas, rango: comparar, vendedores }) : null,
        pendientes: pendientes({ cotizaciones, vendedores, hoy, vigencia }),
        // La actividad reciente sale de la misma lectura: pedirla aparte sería
        // volver a recorrer IndexedDB por lo que ya está en memoria.
        recientes: {
            cotizaciones: ultimos(cotizaciones, { vendedores, cuantos: recientes }),
            notas: ultimos(notas, { vendedores, cuantos: recientes }),
        },
    };
}
