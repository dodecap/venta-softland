/*
 * Almacenamiento local de los maestros — IndexedDB en crudo, sin librería.
 *
 * Hasta la fase 1 todo cabía en Preferences, que guarda un string por clave.
 * Ya no: son 3.373 clientes, 1.195 productos y 912 líneas de cotización. Con
 * Preferences habría que leer el bloque entero, parsearlo y recorrerlo en
 * memoria para buscar un cliente — en un teléfono de gama baja eso es un
 * segundo de pantalla congelada por cada letra que se escribe.
 *
 * No se usa una librería envoltorio a propósito: IndexedDB es fea pero es del
 * navegador, y una dependencia menos en un APK que tiene que durar años.
 *
 * ## Cómo se busca
 *
 * IndexedDB no sabe hacer «contiene». Lo que sí sabe hacer rápido es recorrer
 * un rango de un índice, así que cada ficha guarda `_t`: la lista de sus
 * palabras, normalizadas. El índice es `multiEntry`, o sea que indexa cada
 * palabra por separado, y buscar «rojas» es pedir el rango [rojas, rojas￿)
 * — todas las palabras que empiezan así, sin recorrer la tabla.
 *
 * Por eso la búsqueda es por principio de palabra y no por trozo suelto:
 * «mauricio» encuentra a MAURICIO ROJAS, y «rojas» también, pero «auricio» no.
 * Es como busca la gente, y es la diferencia entre responder al instante y
 * recorrer 3.373 fichas en cada tecla.
 */

const NOMBRE = 'venta-softland';
// La 2 agrega `motivos_perdida` y la 3 el almacén de PDF: la migración solo
// crea los almacenes que falten, así que subir el número es todo lo que hace
// falta. La 10 agrega `referencias_dte`, los tipos de documento que se pueden
// nombrar en una referencia del DTE.
const VERSION = 10;

/**
 * Los almacenes. `clave` es el keyPath; si es un arreglo, la clave es compuesta
 * (los contactos y los precios no tienen id propio en Softland).
 *
 * `busqueda` son los campos que alimentan el índice de palabras. Un almacén sin
 * `busqueda` no se puede buscar por texto, solo leer por clave.
 */
export const ALMACENES = {
    vendedores: { clave: 'codigo', busqueda: ['codigo', 'nombre'] },
    bodegas: { clave: 'codigo' },
    listas_precio: { clave: 'codigo' },
    condiciones_venta: { clave: 'codigo' },
    monedas: { clave: 'codigo' },
    unidades: { clave: 'codigo' },
    grupos: { clave: 'codigo' },
    cargos: { clave: 'codigo', busqueda: ['nombre'] },
    regiones: { clave: 'codigo' },
    motivos_perdida: { clave: 'codigo' },
    referencias_dte: { clave: 'codigo' },
    centros_costo: { clave: 'codigo', busqueda: ['codigo', 'nombre'] },
    giros: { clave: 'codigo', busqueda: ['nombre'] },
    comunas: { clave: 'codigo', busqueda: ['nombre'] },
    ciudades: { clave: 'codigo', busqueda: ['nombre'] },

    clientes: {
        clave: 'codigo',
        busqueda: ['codigo', 'nombre', 'fantasia', 'rut'],
        indices: { comuna: 'comuna' },
    },
    contactos: {
        clave: ['cliente', 'nombre'],
        busqueda: ['nombre', 'email'],
        indices: { cliente: 'cliente' },
    },
    productos: {
        clave: 'codigo',
        busqueda: ['codigo', 'nombre', 'nombre2', 'barra'],
        indices: { grupo: 'grupo' },
    },
    precios: { clave: ['lista', 'producto'], indices: { producto: 'producto' } },

    /*
     * Los PDF ya emitidos, para poder abrirlos y mandarlos sin señal.
     *
     * No es un maestro y no entra en la sincronización: bajar 2.350 documentos
     * a un teléfono no tiene sentido. Cae aquí el que se abrió o se mandó, y
     * `pdf.js` se queda con los últimos 50 por uso.
     */
    pdfs: { clave: 'clave', indices: { tipo: 'tipo' } },

    cotizaciones: {
        clave: 'numero',
        busqueda: ['numero', 'cliente', 'contacto', 'observacion'],
        indices: { cliente: 'cliente', fecha: 'fecha', estado: 'estado' },
    },
    cotizacion_lineas: { clave: ['cotizacion', 'linea'], indices: { cotizacion: 'cotizacion' } },
    notas_venta: {
        clave: 'numero',
        busqueda: ['numero', 'cliente', 'contacto', 'observacion', 'oc'],
        indices: { cliente: 'cliente', fecha: 'fecha', cotizacion: 'cotizacion' },
    },
    nota_venta_lineas: { clave: ['nota_venta', 'linea'], indices: { nota_venta: 'nota_venta' } },

    /*
     * Los campos que cada empresa define por su cuenta en el ERP.
     *
     * Tres almacenes porque son tres cosas: qué atributos hay, qué opciones
     * tiene cada uno, y qué eligió cada nota de venta. **Puede no haber
     * ninguno**: INNOVAGES declara cuatro, NETDOMAIN uno, y otra empresa
     * ninguno. Lo que se dibuje sale de aquí, no de una lista escrita en el
     * código.
     */
    /*
     * El seguimiento de las cotizaciones: qué se quedó de hacer con cada
     * cliente y cuándo. Bajan al teléfono porque el panel cuenta compromisos y
     * el panel se calcula sin señal.
     */
    compromisos: { clave: 'codigo' },
    seguimientos: {
        clave: ['cotizacion', 'numero'],
        indices: { cotizacion: 'cotizacion' },
    },
    /* La historia del avance, no sólo el último: con ella se puede decir
       «lleva seis semanas en 70 %», que es la pregunta que importa. */
    cotizacion_avance: {
        clave: 'id',
        indices: { cotizacion: 'cotizacion' },
    },

    nv_atributos: { clave: 'codigo' },
    nv_atributo_opciones: { clave: ['atributo', 'codigo'], indices: { atributo: 'atributo' } },
    nv_atributo_valores: {
        clave: ['nota_venta', 'atributo'],
        indices: { nota_venta: 'nota_venta' },
    },

    /**
     * De qué línea de cotización salió cada línea de nota de venta.
     *
     * Es lo que permite saber **sin señal** qué queda por convertir de una
     * cotización repartida entre dos notas de venta. Softland no lo guarda: su
     * estado `V` no distingue entre convertida entera y convertida a medias.
     */
    linea_origen: {
        clave: ['nota_venta', 'linea'],
        indices: { cotizacion: 'cotizacion', nota_venta: 'nota_venta' },
    },

    /**
     * Facturas, boletas y notas de crédito.
     *
     * La clave es `tipo` + `numero_interno`, que es la de Softland. El **folio**
     * es lo que ve la gente, pero no sirve de clave: no es único entre tipos.
     */
    facturas: {
        clave: ['tipo', 'numero_interno'],
        busqueda: ['folio', 'cliente', 'glosa'],
        indices: { cliente: 'cliente', fecha: 'fecha', nota_venta: 'nota_venta' },
    },
    factura_lineas: {
        clave: ['tipo', 'numero_interno', 'linea'],
        indices: { documento: ['tipo', 'numero_interno'] },
    },
    /** En qué quedó cada documento con el SII: enviado, aceptado, su TrackID. */
    dte_estado: {
        clave: ['tipo_sii', 'folio'],
        indices: { documento: ['tipo', 'numero_interno'] },
    },
    /** Qué documento acredita cada nota de crédito. */
    factura_referencias: {
        clave: ['tipo', 'numero_interno', 'linea'],
        indices: { referido: 'folio_referido' },
    },

    /** Estado de la sincronización: una fila por maestro. No viene del servidor. */
    meta: { clave: 'recurso' },

    /**
     * Lo que el vendedor hizo sin señal y todavía no llega a Softland.
     *
     * Es la bandeja de salida. Mientras haya algo aquí, la ficha correspondiente
     * se dibuja con la franja de sincronización en ámbar: el vendedor ve que su
     * cambio está en el teléfono y aún no en el ERP, en vez de creer que se
     * guardó y descubrir tres días después que no.
     */
    pendientes: { clave: 'uuid', indices: { clave: 'clave', estado: 'estado' } },
};

let abriendo = null;

function abrir() {
    if (abriendo) return abriendo;

    abriendo = new Promise((resolve, reject) => {
        const req = indexedDB.open(NOMBRE, VERSION);

        req.onupgradeneeded = () => {
            const bd = req.result;
            for (const [nombre, def] of Object.entries(ALMACENES)) {
                if (bd.objectStoreNames.contains(nombre)) continue;
                const almacen = bd.createObjectStore(nombre, { keyPath: def.clave });
                if (def.busqueda) almacen.createIndex('_t', '_t', { multiEntry: true });
                for (const [idx, campo] of Object.entries(def.indices || {})) {
                    almacen.createIndex(idx, campo);
                }
            }
        };

        req.onsuccess = () => {
            // Otra pestaña (o el navegador de desarrollo) pidió subir de versión:
            // hay que soltar la conexión o la migración se queda bloqueada.
            req.result.onversionchange = () => {
                req.result.close();
                abriendo = null;
            };
            resolve(req.result);
        };
        req.onerror = () => {
            abriendo = null;
            reject(req.error);
        };
    });

    return abriendo;
}

function esperar(req) {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

function transaccion(bd, almacenes, modo) {
    const tx = bd.transaction(almacenes, modo);
    const fin = new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
    return { tx, fin };
}

// ------------------------------------------------------------------ búsqueda

/**
 * Palabra buscable: sin tildes, sin mayúsculas, sin puntuación.
 *
 * Lo de los acentos no es cosmético: en INNOVAGES conviven «COMPAÑIA» y
 * «COMPAÑÍA», y quien escribe en el teclado del teléfono casi nunca pone la
 * tilde. Sin normalizar, media lista no aparece.
 */
export function normalizar(texto) {
    return String(texto ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')  // marcas de acento
        .toLowerCase();
}

/** Palabras de un texto, ya normalizadas y sin las de una sola letra. */
export function palabras(texto) {
    return normalizar(texto)
        .split(/[^a-z0-9]+/)
        .filter((p) => p.length > 1);
}

/** Índice de palabras de una ficha, según los campos que declara el almacén. */
function indexar(fila, campos) {
    const vistas = new Set();
    for (const campo of campos) {
        for (const p of palabras(fila[campo])) vistas.add(p);
    }
    return [...vistas];
}

// -------------------------------------------------------------------- lectura

export const idb = {
    /** Cuántas filas hay guardadas de un maestro. */
    async contar(almacen) {
        const bd = await abrir();
        const { tx } = transaccion(bd, [almacen], 'readonly');
        return esperar(tx.objectStore(almacen).count());
    },

    async obtener(almacen, clave) {
        const bd = await abrir();
        const { tx } = transaccion(bd, [almacen], 'readonly');
        return (await esperar(tx.objectStore(almacen).get(clave))) ?? null;
    },

    /** Todas las filas de un maestro. Solo para los chicos: los grandes se buscan. */
    async todos(almacen, limite = Infinity) {
        const bd = await abrir();
        const { tx } = transaccion(bd, [almacen], 'readonly');
        const req = tx.objectStore(almacen).getAll(undefined, limite === Infinity ? undefined : limite);
        return esperar(req);
    },

    /** Filas que calzan con un índice: `porIndice('contactos', 'cliente', '77234300')`. */
    async porIndice(almacen, indice, valor, limite = 500) {
        const bd = await abrir();
        const { tx } = transaccion(bd, [almacen], 'readonly');
        return esperar(tx.objectStore(almacen).index(indice).getAll(valor, limite));
    },

    /**
     * Búsqueda por texto. Devuelve como mucho `limite` filas.
     *
     * La primera palabra se resuelve con el índice — es la que acota — y las
     * demás se comprueban sobre lo que salió de ahí. Buscar «juan perez» no
     * recorre dos índices: recorre el de «juan» y descarta los que no tengan
     * además una palabra que empiece con «perez».
     */
    async buscar(almacen, texto, { limite = 50, filtro = null } = {}) {
        const terminos = palabras(texto);
        if (! terminos.length) {
            const filas = await this.todos(almacen, filtro ? 2000 : limite);
            return (filtro ? filas.filter(filtro) : filas).slice(0, limite);
        }

        const [primero, ...resto] = terminos.sort((a, b) => b.length - a.length);
        const bd = await abrir();
        const { tx } = transaccion(bd, [almacen], 'readonly');
        const indice = tx.objectStore(almacen).index('_t');
        const rango = IDBKeyRange.bound(primero, primero + '\uffff', false, false);

        const salida = [];
        const vistos = new Set();

        await new Promise((resolve, reject) => {
            const req = indice.openCursor(rango);
            req.onerror = () => reject(req.error);
            req.onsuccess = () => {
                const cur = req.result;
                if (! cur || salida.length >= limite) return resolve();

                const fila = cur.value;
                // Una ficha con dos palabras que empiezan igual aparece dos veces
                // en un índice multiEntry: «PEDRO PEREZ» sale en «pe» dos veces.
                const id = JSON.stringify(cur.primaryKey);
                if (! vistos.has(id)) {
                    vistos.add(id);
                    const calza = resto.every((t) => (fila._t || []).some((p) => p.startsWith(t)));
                    if (calza && (! filtro || filtro(fila))) salida.push(fila);
                }
                cur.continue();
            };
        });

        return salida;
    },

    // ----------------------------------------------------------- escritura

    /**
     * Guarda un lote de filas. Es un `put`, no un `add`: la sincronización
     * incremental vuelve a traer filas que ya estaban y tiene que pisarlas.
     */
    async guardar(almacen, filas) {
        if (! filas.length) return 0;

        const def = ALMACENES[almacen];
        const bd = await abrir();
        const { tx, fin } = transaccion(bd, [almacen], 'readwrite');
        const store = tx.objectStore(almacen);

        for (const fila of filas) {
            store.put(def.busqueda ? { ...fila, _t: indexar(fila, def.busqueda) } : fila);
        }

        await fin;
        return filas.length;
    },

    async borrar(almacen, clave) {
        const bd = await abrir();
        const { tx, fin } = transaccion(bd, [almacen], 'readwrite');
        tx.objectStore(almacen).delete(clave);
        await fin;
    },

    /**
     * Borra las filas que no llevan el sello de la última descarga completa.
     *
     * Son las que Softland eliminó: ninguna consulta las devuelve ya, así que la
     * única forma de saber que se fueron es notar que esta vez no llegaron.
     *
     * Va con cursor y `delete()` sobre el propio cursor, no juntando claves en
     * un arreglo: en clientes serían 3.373 objetos en memoria para descartar
     * tres, y en un teléfono de gama baja eso se nota.
     */
    /**
     * Sello de lo que escribió el teléfono y el servidor todavía no confirmó.
     * Sobrevive al barrido: una ficha creada sin señal no puede desaparecer
     * porque el vendedor apretó «volver a descargar todo» antes de que saliera
     * de la bandeja. Cuando Softland la devuelve, se sobrescribe con el sello
     * de la corrida y vuelve a la normalidad.
     */
    SELLO_LOCAL: 'local',

    async barrer(almacen, sello) {
        const bd = await abrir();
        const { tx, fin } = transaccion(bd, [almacen], 'readwrite');
        const req = tx.objectStore(almacen).openCursor();
        let borradas = 0;

        req.onsuccess = () => {
            const cur = req.result;
            if (! cur) return;
            if (cur.value._s !== sello && cur.value._s !== idb.SELLO_LOCAL) {
                cur.delete();
                borradas++;
            }
            cur.continue();
        };

        await fin;
        return borradas;
    },

    async vaciar(almacen) {
        const bd = await abrir();
        const { tx, fin } = transaccion(bd, [almacen], 'readwrite');
        tx.objectStore(almacen).clear();
        await fin;
    },

    /** Todo fuera menos `meta`, que se limpia aparte para no perder el estado. */
    async vaciarTodo() {
        for (const nombre of Object.keys(ALMACENES)) {
            await this.vaciar(nombre);
        }
    },

    // --------------------------------------------------------------- meta

    async estado(recurso) {
        return (await this.obtener('meta', recurso)) ?? { recurso, sync_at: null, total: 0 };
    },

    async guardarEstado(estado) {
        await this.guardar('meta', [estado]);
    },

    async estados() {
        return this.todos('meta');
    },
};
