/**
 * Cuánto queda por convertir de una cotización, calculado en el teléfono.
 *
 * ## Es una copia, y tiene que decir lo mismo
 *
 * La regla vive en el servidor (`app/Services/Softland/Saldo.php`) y aquí se
 * repite, igual que `documentos.js` repite la aritmética de `Totales.php`. Se
 * repite porque **esto se mira en terreno**: la ficha de la cotización dice
 * «quedan 1 de 8 líneas» y la lista la marca como parcial, y las dos se abren
 * sin señal. Un dato que sólo aparece con cobertura no sirve para el trabajo
 * que hace un vendedor.
 *
 * Si cambia una, cambia la otra. Las dos reglas son:
 *
 *   1. **Se calcula, no se guarda.** Es una resta sobre los documentos que
 *      existen ahora, así que anular o borrar la cambian solos.
 *   2. **Sólo cuentan los documentos vivos.** Una nota de venta anulada (`N`)
 *      no consume. Los estados `P`, `A` y `C` sí: una que espera la aprobación
 *      del jefe está viva, y si no consumiera, dos personas convertirían la
 *      misma cotización mientras él decide.
 *
 * ## Y puede no saberse
 *
 * Una cotización convertida antes de que existiera la app —o desde el Softland
 * de escritorio— tiene notas de venta vivas y ningún enlace de línea. Ahí el
 * saldo **no se sabe**, y decir «queda todo» la convertiría dos veces. Se
 * devuelve `conocible: false` y la pantalla lo dice.
 */

// Con extensión, a propósito: así el módulo se puede importar tal cual desde
// Node y `npm run pruebas` puede ejercitar la regla sin un navegador delante.
import { idb } from './idb.js';

/** La línea como clave: en Softland es `float` y vale 1.0, 2.0… */
export function clave(linea) {
    return Number(linea).toFixed(2);
}

/**
 * El saldo de una cotización, leyendo el almacén.
 *
 * @returns {Promise<{conocible: boolean, lineas: Array, pendientes: number,
 *                    totales: number, parcial: boolean}>}
 */
export async function saldoCotizacion(numero) {
    const [cot, lineas, notas, enlaces] = await Promise.all([
        idb.obtener('cotizaciones', numero),
        idb.porIndice('cotizacion_lineas', 'cotizacion', numero),
        idb.porIndice('notas_venta', 'cotizacion', numero),
        idb.porIndice('linea_origen', 'cotizacion', numero),
    ]);

    return calcularSaldo({ cotizacion: cot, lineas, notas, enlaces });
}

/**
 * La regla, sin almacén de por medio.
 *
 * Separada para poder probarla: es la copia de `Saldo.php` y las dos tienen que
 * dar lo mismo. Una regla que sólo se puede ejercitar con un IndexedDB delante
 * no se prueba, y una que no se prueba se separa de su gemela sin que nadie lo
 * note hasta que la ficha dice «quedan 7» y el servidor escribe 12.
 */
export function calcularSaldo({ cotizacion: cot, lineas, notas, enlaces }) {
    const vivas = (notas || []).filter((n) => (n.estado || '').trim().toUpperCase() !== 'N');
    const vivasPorNumero = new Map(vivas.map((n) => [n.numero, n]));

    // Un enlace vale si su nota de venta sigue viva **y es la misma**: el
    // correlativo de Softland es `MAX + 1`, así que un número borrado se vuelve
    // a repartir. Sin la marca, un enlace viejo apuntaría al documento de otro.
    const consumido = new Map();
    const conEnlace = new Set();

    for (const e of enlaces || []) {
        const nv = vivasPorNumero.get(e.nota_venta);

        if (! nv) continue;
        if (! mismaMarca(e.nota_venta_creada, nv.creado)) continue;
        if (! mismaMarca(e.cotizacion_creada, cot?.creado)) continue;

        const k = clave(e.cotizacion_linea);
        consumido.set(k, (consumido.get(k) || 0) + Number(e.cantidad || 0));
        conEnlace.add(e.nota_venta);
    }

    const conSaldo = (lineas || [])
        .slice()
        .sort((a, b) => a.linea - b.linea)
        .map((l) => {
            const convertida = consumido.get(clave(l.linea)) || 0;

            return {
                ...l,
                convertida,
                saldo: Number(l.cantidad || 0) - convertida,
            };
        });

    // Si hay notas de venta vivas de las que no hay enlace, el saldo no se
    // puede afirmar: algo se convirtió y no sabemos qué.
    const ciegas = vivas.length - conEnlace.size;
    const pendientes = conSaldo.filter((l) => l.saldo > 0.0001).length;

    return {
        conocible: ciegas <= 0,
        lineas: conSaldo,
        pendientes,
        totales: conSaldo.length,
        // Convertida a medias: tiene nota de venta y todavía le queda algo.
        parcial: ciegas <= 0 && vivas.length > 0 && pendientes > 0,
    };
}

/**
 * Las dos marcas de creación, comparadas hasta el segundo.
 *
 * Un enlace sin marca es de antes de que la columna existiera: de ésos sólo se
 * puede comprobar que el documento siga estando, que es lo que hace el
 * servidor.
 */
function mismaMarca(deLaFila, delDocumento) {
    if (! deLaFila || ! delDocumento) return true;

    return String(deLaFila).slice(0, 19) === String(delDocumento).slice(0, 19);
}

/**
 * Cuáles de estas cotizaciones están convertidas a medias.
 *
 * Para la lista, donde hay doscientas a la vez. Se carga todo de una —los tres
 * almacenes juntos son poco más de mil filas— en vez de preguntar cuatro cosas
 * por cotización: doscientas fichas son ochocientas consultas, y eso se nota al
 * desplazar.
 *
 * @returns {Promise<Set<number>>} los números que están a medias
 */
export async function parciales(numeros) {
    const buscados = new Set(numeros);

    if (buscados.size === 0) return new Set();

    const [cabeceras, lineas, notas, enlaces] = await Promise.all([
        idb.todos('cotizaciones'),
        idb.todos('cotizacion_lineas'),
        idb.todos('notas_venta'),
        idb.todos('linea_origen'),
    ]);

    const creada = new Map(cabeceras.map((c) => [c.numero, c.creado]));
    const nvViva = new Map(
        notas
            .filter((n) => (n.estado || '').trim().toUpperCase() !== 'N')
            .map((n) => [n.numero, n])
    );

    // Lo pedido y lo consumido, por cotización.
    const pedido = new Map();
    const consumido = new Map();
    const conEnlace = new Map();

    for (const l of lineas) {
        if (! buscados.has(l.cotizacion)) continue;
        pedido.set(l.cotizacion, (pedido.get(l.cotizacion) || 0) + Number(l.cantidad || 0));
    }

    for (const e of enlaces) {
        if (! buscados.has(e.cotizacion)) continue;

        const nv = nvViva.get(e.nota_venta);

        if (! nv) continue;
        if (! mismaMarca(e.nota_venta_creada, nv.creado)) continue;
        if (! mismaMarca(e.cotizacion_creada, creada.get(e.cotizacion))) continue;

        consumido.set(e.cotizacion, (consumido.get(e.cotizacion) || 0) + Number(e.cantidad || 0));
        (conEnlace.get(e.cotizacion) || conEnlace.set(e.cotizacion, new Set()).get(e.cotizacion))
            .add(e.nota_venta);
    }

    const vivasPorCotizacion = new Map();

    for (const n of nvViva.values()) {
        if (! buscados.has(n.cotizacion)) continue;
        vivasPorCotizacion.set(n.cotizacion, (vivasPorCotizacion.get(n.cotizacion) || 0) + 1);
    }

    const aMedias = new Set();

    for (const numero of buscados) {
        const vivas = vivasPorCotizacion.get(numero) || 0;

        // Sin nota de venta viva no está convertida; con notas de venta de las
        // que no hay enlace, el saldo no se sabe y no se afirma nada.
        if (vivas === 0 || vivas !== (conEnlace.get(numero)?.size || 0)) continue;

        if ((pedido.get(numero) || 0) - (consumido.get(numero) || 0) > 0.0001) {
            aMedias.add(numero);
        }
    }

    return aMedias;
}
