import { ref, computed } from 'vue';
import { api } from './api';
import { cargarCatalogos } from './catalogos';
import { db } from './db';
import { idb } from './idb';

/*
 * Sincronización de maestros — el motor que deja al vendedor con todo encima
 * antes de salir a terreno.
 *
 * El servidor no recuerda qué bajó quién: solo sirve páginas. Todo el estado
 * vive aquí y en IndexedDB, una fila por maestro en el almacén `meta`. Eso
 * hace la sincronización **retomable**: si se corta la señal en la página 4 de
 * clientes, la próxima vez sigue en la 4, no vuelve a la 1.
 *
 * ## Las dos formas de bajar un maestro
 *
 * **Completa.** Se recorre entero. Cada fila se marca con el sello de esta
 * corrida y, al terminar, se borra lo que quedó con un sello viejo: eso son las
 * filas que Softland eliminó, que de otro modo quedarían para siempre en el
 * teléfono. Importante: **no se vacía antes de empezar**. Si se vaciara y la
 * señal se cortara a la mitad, el vendedor se quedaría con medio catálogo justo
 * cuando salió a la calle.
 *
 * **Incremental.** Solo lo que cambió desde la última vez, por `FechaUlMod`.
 * Es lo que hace que sincronizar de nuevo cueste segundos y no una descarga
 * completa. Tiene un punto ciego conocido: un borrado no deja rastro y no se
 * entera. Por eso, al terminar, se compara cuántas filas quedaron contra el
 * total que informa el servidor; si no calzan, se repite en completa.
 */

/** Progreso visible: lo lee la pantalla mientras baja. */
export const progreso = ref(null);
export const sincronizando = computed(() => progreso.value !== null);
export const ultimoError = ref('');

/** Cuántas filas hay hoy en el teléfono, por maestro. */
export const inventarioLocal = ref({});

/**
 * Sube uno cada vez que termina una corrida.
 *
 * Es el aviso de «ya hay datos nuevos en el almacén». Las pantallas que
 * muestran números contados de IndexedDB —el panel, sobre todo— lo miran para
 * volver a leerlos, porque la descarga no arranca donde se dibujan: empieza en
 * el login y termina medio minuto después, con el vendedor ya mirando el panel.
 */
export const corridas = ref(0);

let cancelado = false;

/** La corrida en curso, si la hay. Ver `sincronizar()`. */
let enCurso = null;

/**
 * Qué maestros hay detrás de cada pantalla.
 *
 * Existe para el refresco de una sola lista: el vendedor tira de la pantalla
 * de cotizaciones hacia abajo y baja **eso**, no los veintiún maestros. Una
 * cabecera sin su detalle no sirve de nada, así que van juntos.
 *
 * El mapa vive aquí y no en cada pantalla por la misma razón de siempre: dos
 * copias de la lista son el día en que alguien refresca cotizaciones y el
 * detalle se queda con los precios de la semana pasada.
 */
export const GRUPOS = {
    // `linea_origen` va con las dos: dice qué se llevó cada nota de venta de su
    // cotización, así que lo necesitan la ficha de una y la lista de la otra.
    cotizaciones: ['cotizaciones', 'cotizacion_lineas', 'linea_origen', 'notas_venta'],
    // Las facturas van con la nota de venta porque su avance —«facturado 5 de
    // 12»— se calcula desde ellas: las columnas que Softland tiene para eso
    // están muertas.
    notas_venta: ['notas_venta', 'nota_venta_lineas', 'linea_origen', 'facturas', 'factura_lineas', 'factura_referencias'],
    facturas: ['facturas', 'factura_lineas', 'factura_referencias'],
    clientes: ['clientes', 'contactos'],
    productos: ['productos', 'precios'],
};

/**
 * Refresca sólo lo que hay detrás de una pantalla.
 *
 * Es el tirón hacia abajo. Cuesta lo que cuesta ese grupo —las cotizaciones
 * con su detalle son 1.096 filas de las 14.184 del teléfono— y por eso se
 * puede hacer a cada rato, que es justo lo que hace falta: Softland no tiene
 * cómo avisar de que alguien cambió un documento desde el escritorio.
 */
export function refrescarGrupo(nombre) {
    const solo = GRUPOS[nombre];

    if (! solo) throw new Error(`No hay grupo de sincronización «${nombre}».`);

    return sincronizar({ solo });
}

/**
 * Baja todo lo que falte.
 *
 * @param {boolean} completa Fuerza descarga completa de todo, ignorando lo que
 *                           ya haya. Es el botón «volver a descargar» de Cuenta,
 *                           para cuando algo quedó raro y no se sabe por qué.
 * @param {string[]|null} solo Sólo estos maestros. El resto ni se cuenta en el
 *                           servidor. `null` es todo, que es lo normal.
 */
export function sincronizar({ completa = false, solo = null } = {}) {
    // Una sola corrida a la vez: dos se pisarían el estado por maestro. Pero el
    // que llega segundo **espera a la primera** en vez de irse con las manos
    // vacías, que es lo que hacía antes: el vendedor entra, la descarga arranca
    // sola desde el login, él aprieta sincronizar y se le respondía «listo» sin
    // haber bajado nada. Lo que recibe es el resumen de la corrida en curso,
    // aunque la haya pedido con otras opciones.
    if (enCurso) return enCurso;

    enCurso = correr({ completa, solo }).finally(() => { enCurso = null; });

    return enCurso;
}

async function correr({ completa, solo }) {
    cancelado = false;
    ultimoError.value = '';
    progreso.value = { titulo: 'Preparando', hechas: 0, total: 0, recurso: null, indice: 0, recursos: 0 };

    const resumen = { recursos: 0, filas: 0, errores: [] };

    try {
        const { recursos } = await api.catalogo(solo);
        progreso.value.recursos = recursos.length;

        for (const [i, def] of recursos.entries()) {
            if (cancelado) break;

            progreso.value = {
                titulo: def.titulo,
                recurso: def.recurso,
                hechas: 0,
                total: def.total,
                indice: i + 1,
                recursos: recursos.length,
            };

            try {
                resumen.filas += await bajarRecurso(def, completa);
                resumen.recursos++;
            } catch (e) {
                // Un maestro que falla no bota la sincronización entera: los
                // demás sirven igual. Se anota y se sigue.
                resumen.errores.push(`${def.titulo}: ${e.message}`);
            }
        }

        // La marca de «sincronizado» es de la sincronización entera. Un
        // refresco de una lista no la mueve: si la moviera, Cuenta diría que
        // el teléfono está al día con todo por haber bajado dos maestros.
        if (! solo) await db.setSincronizado(new Date().toISOString());
        await refrescarInventarioLocal();

        // Los traductores de código a nombre viven en memoria y se cargaron
        // cuando el almacén estaba vacío: sin volver a leerlos, el panel de la
        // primera sesión dice «vendedor 2» donde tiene que decir el nombre.
        if (! solo) await cargarCatalogos();

        if (resumen.errores.length) ultimoError.value = resumen.errores[0];

        // Lo último, y sólo si se llegó hasta aquí: quien escucha cuenta filas,
        // y contarlas a mitad de descarga es enseñar un número que va a cambiar.
        corridas.value++;

        return resumen;
    } catch (e) {
        ultimoError.value = e.message;
        throw e;
    } finally {
        progreso.value = null;
    }
}

/** Corta la descarga en curso. Lo bajado se queda; la próxima vez retoma. */
export function cancelarSincronizacion() {
    cancelado = true;
}

/**
 * Un maestro, de principio a fin.
 *
 * @returns {number} filas escritas
 */
async function bajarRecurso(def, forzarCompleta) {
    const { recurso, total: totalServidor } = def;
    let estado = await idb.estado(recurso);

    // Una corrida a medias se retoma tal cual estaba: su `desde` y su sello son
    // los de entonces, no los de ahora. Mezclarlos dejaría un hueco de filas.
    const retomando = !forzarCompleta && estado.cursor != null;

    const incremental = retomando
        ? estado.incremental
        : !forzarCompleta && def.incremental && !!estado.sync_at;

    let desde = retomando ? estado.desde : (incremental ? estado.sync_at : null);
    let sello = retomando ? estado.sello : null;
    let cursor = retomando ? estado.cursor : null;
    let escritas = retomando ? estado.escritas || 0 : 0;

    for (;;) {
        if (cancelado) return escritas;

        const pagina = await api.catalogoPagina(recurso, { desde, cursor });

        // El reloj es siempre el del servidor. Si el teléfono usara el suyo para
        // pedir «lo cambiado desde», bastaría con que fuera un minuto adelantado
        // para saltarse filas y no enterarse nunca.
        sello ??= pagina.servidor_at;

        if (pagina.filas.length) {
            // El sello va en toda fila que se escribe, venga de donde venga. Solo
            // lo usa el barrido de la descarga completa, pero si unas filas lo
            // llevaran y otras no, el barrido borraría las que no.
            await idb.guardar(recurso, pagina.filas.map((f) => ({ ...f, _s: sello })));
            escritas += pagina.filas.length;
        }

        cursor = pagina.cursor;

        progreso.value = { ...progreso.value, hechas: escritas };

        // Se guarda el avance en cada página: si se corta aquí, se retoma aquí.
        estado = { recurso, ...estado, cursor, desde, sello, escritas, incremental };
        await idb.guardarEstado(estado);

        if (!cursor) break;
    }

    if (cancelado) return escritas;

    if (!incremental) {
        await idb.barrer(recurso, sello);
    }

    await idb.guardarEstado({
        recurso,
        sync_at: sello,
        total: totalServidor,
        cursor: null,
        desde: null,
        sello: null,
        escritas: 0,
        incremental: false,
    });

    // Un borrado en Softland no deja rastro que se pueda consultar, así que la
    // descarga incremental no se entera. Contar es la forma barata de notarlo:
    // si sobran o faltan filas, se baja el maestro completo y se acabó la duda.
    if (incremental && (await idb.contar(recurso)) !== totalServidor) {
        return escritas + (await bajarRecurso(def, true));
    }

    return escritas;
}

export async function refrescarInventarioLocal() {
    const out = {};
    for (const e of await idb.estados()) {
        out[e.recurso] = { ...e, local: await idb.contar(e.recurso) };
    }
    inventarioLocal.value = out;
    return out;
}

/** Total de registros guardados en el teléfono. Es el KPI del panel. */
export async function contarRegistros() {
    const inv = await refrescarInventarioLocal();
    return Object.values(inv).reduce((n, x) => n + x.local, 0);
}
