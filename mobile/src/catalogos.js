import { ref } from 'vue';
import { idb } from './idb';

/*
 * Traductor de códigos a nombres.
 *
 * Softland guarda códigos en todas partes: una cotización dice `CodMon: "01"`,
 * `CveCod: "2"`, `CodiCC: "SOF-501"`. Al vendedor hay que mostrarle «Peso
 * Chileno», «30 días» y el nombre del centro de costo.
 *
 * Los maestros de traducir se cargan una vez a memoria y de ahí en adelante
 * traducir no toca IndexedDB. Son unas 4.000 filas de dos columnas entre todos
 * — código y nombre, nada más — y caben de sobra.
 *
 * Los grandes (clientes, productos, precios, documentos) no se cargan: esos se
 * consultan por clave cuando hacen falta, que traer 3.373 fichas a memoria
 * para mostrar un nombre es justo lo que esta fase vino a evitar.
 */

const CHICOS = [
    'vendedores', 'bodegas', 'listas_precio', 'condiciones_venta', 'monedas',
    'unidades', 'grupos', 'cargos', 'regiones', 'motivos_perdida',
    // Estos cuatro son más gordos (2.009 giros, 937 ciudades, 594 centros de
    // costo, 352 comunas) pero van igual: sin ellos la ficha del cliente
    // muestra «C28 · 08301 · LANGE», que no le dice nada a nadie.
    'giros', 'comunas', 'ciudades', 'centros_costo',
];

const mapas = {};
export const listo = ref(false);

export async function cargarCatalogos() {
    for (const nombre of CHICOS) {
        const filas = await idb.todos(nombre);
        mapas[nombre] = Object.fromEntries(filas.map((f) => [String(f.codigo), f]));
    }
    listo.value = true;
}

/**
 * Nombre de un código. Si no está, devuelve el código tal cual: es preferible
 * mostrar «SOF-501» que un hueco en blanco, porque el código al menos se puede
 * buscar en Softland.
 */
export function nombre(maestro, codigo) {
    const c = String(codigo ?? '').trim();
    if (! c) return '';
    return mapas[maestro]?.[c]?.nombre ?? c;
}

export function fila(maestro, codigo) {
    return mapas[maestro]?.[String(codigo ?? '').trim()] ?? null;
}

export function opciones(maestro) {
    return Object.values(mapas[maestro] ?? {});
}

/** Símbolo de la moneda, para escribir montos. Por defecto el peso. */
export function simbolo(codMon) {
    return fila('monedas', codMon)?.simbolo || '$';
}

/**
 * Monto con el formato chileno y los decimales de su moneda: el peso va sin
 * decimales y la UF con dos. Escribir «$ 463.369,00» es delatar que el formato
 * se copió de otra parte.
 */
export function monto(valor, codMon = '01') {
    const m = fila('monedas', codMon);
    const dec = m ? m.decimales : 0;
    return (m?.simbolo || '$') + ' ' + Number(valor || 0).toLocaleString('es-CL', {
        minimumFractionDigits: dec,
        maximumFractionDigits: dec,
    });
}

/** Fecha corta, la que cabe en una fila de lista. */
export function fecha(iso) {
    if (! iso) return '';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: '2-digit' });
}
