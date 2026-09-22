/*
 * De dónde saca el panel sus números: de IndexedDB, no del servidor.
 *
 * Tres lecturas completas por apertura. Son 185 cotizaciones y 51 notas de
 * venta de doce meses; leerlas enteras y filtrar en memoria es más rápido que
 * abrir cuatro cursores por índice, y sobre todo es lo que funciona sin señal.
 *
 * `metricas.js` no sabe que esto existe: recibe arreglos.
 */

import { idb } from '../idb';
import { facturasEmitidas } from '../saldo';
import { compromisosVivos, resumen as resumenCompromisos } from '../seguimiento';
import { calcular, pendientes, ultimos } from './metricas';

export async function panel({ rango, comparar = null, vendedores = null, hoy, vigencia = 30, recientes = 5 }) {
    const [cotizaciones, notas, facturas] = await Promise.all([
        idb.todos('cotizaciones'),
        idb.todos('notas_venta'),
        // Marcadas, no en crudo: el estado que importa de una factura es el
        // del SII, y ése vive en otro almacén. `enviado_sii` de la fila no
        // sirve — se escribe al timbrar, antes de que el documento viaje.
        facturasEmitidas(),
    ]);

    // El mapa de compromisos vivos se lee una vez y se cuenta dos: por el
    // ámbito elegido y por todo el almacén. Lo segundo es lo que deja decirle
    // al jefe que su equipo tiene trabajo aunque él no tenga ninguno.
    const vivos = await compromisosVivos();

    return {
        actual: calcular({ cotizaciones, notas, facturas, rango, vendedores }),
        anterior: comparar ? calcular({ cotizaciones, notas, facturas, rango: comparar, vendedores }) : null,
        pendientes: pendientes({ cotizaciones, vendedores, hoy, vigencia }),
        // Los compromisos con el cliente: lo que hay que hacer hoy y lo que se
        // quedó sin hacer. Va aparte de `pendientes` porque responde a otra
        // pregunta — aquélla mira la vigencia del documento, ésta la promesa.
        compromisos: await resumenCompromisos({ cotizaciones, vendedores, hoy, vivos }),
        // Los del almacén entero. Sólo tiene sentido cuando se está mirando un
        // trozo: sin `vendedores` sería el mismo número dos veces.
        compromisos_todos: vendedores
            ? await resumenCompromisos({ cotizaciones, vendedores: null, hoy, vivos })
            : null,
        // La actividad reciente sale de la misma lectura: pedirla aparte sería
        // volver a recorrer IndexedDB por lo que ya está en memoria.
        recientes: {
            cotizaciones: ultimos(cotizaciones, { vendedores, cuantos: recientes }),
            notas: ultimos(notas, { vendedores, cuantos: recientes }),
            // Las notas de crédito no entran: son el desenlace de una factura,
            // y en una lista de «lo último que pasó» harían aparecer dos veces
            // la misma operación.
            facturas: ultimos(
                (facturas || []).filter((f) => f.tipo !== 'N'),
                { vendedores, cuantos: recientes },
            ),
        },
    };
}
