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

/**
 * Cuánto se ha facturado de una nota de venta, línea por línea.
 *
 * **No sale de `nvCantFact`.** Esa columna y sus seis hermanas están en cero en
 * las 3.824 líneas de cada empresa: Softland no las mantiene, ni siquiera con
 * 941 facturas de NETDOMAIN nacidas de una nota de venta. Leerlas daba
 * «facturado 0 de 12» para siempre.
 *
 * Sale de sumar las líneas de factura **vigentes** que apuntan a cada línea de
 * la nota de venta (`nvCorrela`), y de restar lo que devolvieron las notas de
 * crédito. Es la misma resta del servidor.
 *
 * @returns {Promise<Map<string, number>>} la clave de cada línea con su cantidad facturada
 */
export async function facturadoDe(notaVenta) {
    const [documentos, lineas] = await Promise.all([
        idb.porIndice('facturas', 'nota_venta', Number(notaVenta)),
        idb.todos('factura_lineas'),
    ]);

    const vigentes = new Map(
        (documentos || [])
            .filter((d) => (d.estado || '').trim().toUpperCase() !== 'N')
            .map((d) => [`${d.tipo}-${d.numero_interno}`, d])
    );

    // Las notas de crédito no cuelgan de la nota de venta sino de la factura,
    // así que se recogen aparte: son las que devuelven.
    const porDocumento = new Map();

    for (const l of lineas || []) {
        const k = `${l.tipo}-${l.numero_interno}`;
        if (! porDocumento.has(k)) porDocumento.set(k, []);
        porDocumento.get(k).push(l);
    }

    const facturado = new Map();

    for (const [k, doc] of vigentes) {
        for (const l of porDocumento.get(k) || []) {
            if (! (l.nota_venta_linea > 0)) continue;

            const k2 = clave(l.nota_venta_linea);
            // La cantidad de una nota de crédito viene en negativo: sumarla tal
            // cual ya devuelve lo suyo.
            const suma = doc.tipo === 'N'
                ? -Math.abs(Number(l.cantidad || 0))
                : Number(l.cantidad || 0);

            facturado.set(k2, (facturado.get(k2) || 0) + suma);
        }
    }

    return facturado;
}

/**
 * Qué facturar de una nota de venta, calculado **en el teléfono**.
 *
 * Es el gemelo sin señal de lo que devuelve el servidor en
 * `/notas-venta/:n/facturar`. Cuando hay red manda el servidor, que ve lo que
 * facturó el ERP hace un minuto; sin red, esto es lo que hay, y es lo mismo
 * mientras el almacén esté al día.
 *
 * Lo único que no se puede saber sin red son **los folios**: los reparte
 * Softland y no hay copia en el teléfono. Va en `null`, que quiere decir «no se
 * sabe», no «no quedan».
 */
export async function propuestaLocal(numero) {
    const nv = await idb.obtener('notas_venta', Number(numero));

    if (! nv) return null;

    const facturado = await facturadoDe(numero);
    const lineas = await idb.porIndice('nota_venta_lineas', 'nota_venta', Number(numero));

    return {
        nota_venta: Number(numero),
        cliente: nv.cliente,
        moneda: nv.moneda,
        centro_costo: nv.centro_costo || null,
        condicion: nv.condicion || null,
        vendedor: nv.vendedor || '',
        estado: nv.estado,
        receptor_editable: false,
        conocible: true,
        motivo: null,
        folios: null,
        lineas: (lineas || []).map((l) => {
            const pedida = Number(l.cantidad || 0);
            const hecha = facturado.get(clave(l.linea)) || 0;

            return {
                linea: l.linea,
                producto: l.producto,
                pedida,
                facturada: hecha,
                saldo: pedida - hecha,
            };
        }),
    };
}

/**
 * Las facturas de una nota de venta, con lo que hay que saber de cada una.
 *
 * `acreditada` es el folio de la nota de crédito que la anuló, si la hay. Se
 * busca por la referencia del DTE —tipo del SII y folio—, que es donde de
 * verdad se dice qué se acredita; `AuxDocNum` parece servir y no sirve.
 *
 * Las notas de crédito no se listan aparte: son el desenlace de una factura, no
 * un documento suelto, y enseñarlas sueltas haría contar dos veces la misma
 * operación.
 */
export async function facturasDe(notaVenta) {
    const documentos = await idb.porIndice('facturas', 'nota_venta', Number(notaVenta));

    return (await marcar(documentos)).filter((d) => d.tipo !== 'N');
}

/**
 * Todo lo emitido: facturas **y** notas de crédito.
 *
 * Aquí sí van las notas de crédito, y no es una contradicción con lo de arriba.
 * Colgando de una nota de venta la nota de crédito es el desenlace de una
 * factura y listarla suelta contaría dos veces la misma operación; en la lista
 * de documentos emitidos es un documento con su folio, y quien lo busca lo
 * busca por ese folio.
 */
export async function facturasEmitidas() {
    return marcar(await idb.todos('facturas'));
}

/**
 * Lo que hay que saber de cada documento emitido, que no está en su fila.
 *
 * Dos cosas viven en otras tablas porque son otra cosa: si alguien lo anuló con
 * una nota de crédito —eso está en las referencias del DTE— y en qué quedó con
 * el SII, que es una pregunta aparte de estar escrito en inventario.
 */
async function marcar(documentos) {
    const [referencias, dte] = await Promise.all([
        idb.todos('factura_referencias'),
        idb.todos('dte_estado'),
    ]);

    // El estado ante el SII vive en otra tabla porque es otra cosa: un documento
    // puede estar escrito en inventario y no haber viajado todavía.
    const ante = new Map(
        (dte || []).map((d) => [`${d.tipo}-${d.numero_interno}`, d])
    );

    // Tipo del SII de cada tipo de Softland, que es como se referencian.
    const SII = { F: '33', B: '39', N: '61' };

    const anula = new Map();

    // Y al revés: a qué folio devuelve cada nota de crédito. Es lo primero que
    // se pregunta de una nota de crédito suelta en una lista.
    const devuelveA = new Map(
        (referencias || [])
            .filter((r) => r.tipo === 'N' && Number(r.folio_referido) > 0)
            .map((r) => [`N-${r.numero_interno}`, Number(r.folio_referido)])
    );

    // Las notas de crédito que anulan algo pueden no estar en la lista que se
    // está marcando —la de una nota de venta trae sólo las suyas—, así que se
    // buscan en el almacén entero.
    const todas = await idb.todos('facturas');

    for (const r of referencias || []) {
        if (r.tipo !== 'N') continue;

        const nc = (todas || []).find(
            (d) => d.tipo === 'N' && d.numero_interno === r.numero_interno
        );

        if (! nc || (nc.estado || '').trim().toUpperCase() === 'N') continue;

        anula.set(`${r.tipo_sii_referido}-${Number(r.folio_referido)}`, nc.folio);
    }

    return (documentos || [])
        .sort((a, b) => b.folio - a.folio)
        .map((d) => {
            const sii = ante.get(`${d.tipo}-${d.numero_interno}`);
            const track = String(sii?.track_id || '').trim();

            return {
                ...d,
                anulada: (d.estado || '').trim().toUpperCase() === 'N',
                acreditada: anula.get(`${SII[d.tipo]}-${d.folio}`) || null,
                // Enviado quiere decir que viajó, no que lo hayan aceptado: el
                // veredicto tarda y es una pregunta aparte.
                track_id: track && track !== '0' ? track : null,
                aceptada: Number(sii?.aceptado || 0) === 1,
                anula_a: devuelveA.get(`${d.tipo}-${d.numero_interno}`) || null,
                motivo_sii: sii?.motivo || null,
            };
        });
}

/**
 * Cuáles de estas notas de venta tienen algo por facturar.
 *
 * Para la lista, donde hay doscientas. Se carga todo de una en vez de preguntar
 * tres cosas por documento, igual que en `parciales()`.
 *
 * Una nota de venta anulada o pendiente de aprobación no cuenta: la primera no
 * existe y la segunda espera el visto bueno del jefe, y facturarla se lo
 * saltaría.
 *
 * @returns {Promise<Set<number>>} los números que todavía tienen saldo
 */
export async function porFacturar(numeros) {
    const buscados = new Set(numeros);

    if (buscados.size === 0) return new Set();

    const [cabeceras, lineas, documentos, lineasDoc] = await Promise.all([
        idb.todos('notas_venta'),
        idb.todos('nota_venta_lineas'),
        idb.todos('facturas'),
        idb.todos('factura_lineas'),
    ]);

    const facturables = new Set(
        cabeceras
            .filter((n) => buscados.has(n.numero) && ['A', 'C'].includes((n.estado || '').trim().toUpperCase()))
            .map((n) => n.numero)
    );

    const pedido = new Map();

    for (const l of lineas) {
        if (! facturables.has(l.nota_venta)) continue;
        pedido.set(l.nota_venta, (pedido.get(l.nota_venta) || 0) + Number(l.cantidad || 0));
    }

    // Sólo los documentos vigentes consumen, y los de la nota de crédito vienen
    // en negativo, así que sumarlos ya devuelve lo suyo.
    const deDocumento = new Map();

    for (const d of documentos) {
        if (! facturables.has(d.nota_venta)) continue;
        if ((d.estado || '').trim().toUpperCase() === 'N') continue;
        deDocumento.set(`${d.tipo}-${d.numero_interno}`, d.nota_venta);
    }

    const facturado = new Map();

    for (const l of lineasDoc) {
        if (! (l.nota_venta_linea > 0)) continue;

        const nv = deDocumento.get(`${l.tipo}-${l.numero_interno}`);

        if (nv === undefined) continue;

        const suma = l.tipo === 'N'
            ? -Math.abs(Number(l.cantidad || 0))
            : Number(l.cantidad || 0);

        facturado.set(nv, (facturado.get(nv) || 0) + suma);
    }

    const conSaldo = new Set();

    for (const numero of facturables) {
        if ((pedido.get(numero) || 0) - (facturado.get(numero) || 0) > 0.0001) {
            conSaldo.add(numero);
        }
    }

    return conSaldo;
}
