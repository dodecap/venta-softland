import { idb } from './idb.js';

/*
 * Los compromisos con el cliente: qué se quedó de hacer y cuándo.
 *
 * ## Un compromiso es una promesa, no una etapa
 *
 * Un verbo y una fecha: llamar el martes, visitar el jueves. **No dice nada de
 * cuán cerca está el cierre**, y por eso no hay que confundirlo con el avance:
 * se puede estar al 90 % y que el próximo paso sea una llamada. Mezclarlos
 * —que es lo que pasa cuando el maestro de compromisos se llena con
 * porcentajes— hace que una venta «retroceda» al acordar una llamada.
 *
 * ## Por qué el estado se calcula por fecha y nada más
 *
 * Porque `nwtsegui` **no tiene columna de cumplido**. No hay forma de marcar un
 * compromiso como hecho, así que sólo se puede definir de una manera: el
 * compromiso vivo de una cotización es el de su **última** anotación, y anotar
 * la siguiente es lo que cierra la anterior. Una anotación sin próxima fecha
 * deja la cotización sin compromiso — es el «ya está hecho, no quedó nada».
 *
 * Es un modelo simple y tiene la virtud de no obligar a inventar columnas en el
 * ERP.
 *
 * ## La regla se escribe aquí y en ningún otro sitio
 *
 * La usan el panel para contar y la lista para filtrar. Dos copias de la misma
 * regla son un panel que dice «4» y una lista que muestra 5.
 */

/** Los cuatro estados, con el color que les toca. El orden es el de urgencia. */
export const ESTADOS = {
    atrasado: { rotulo: 'Atrasado', color: 'rojo' },
    hoy: { rotulo: 'Hoy', color: 'amarillo' },
    proximo: { rotulo: 'Próximo', color: 'cian' },
    // Ni prometido ni cerrado. Es el que más plata recupera: una cotización
    // abierta que nadie prometió volver a tocar se está enfriando en silencio.
    sin_compromiso: { rotulo: 'Sin próximo paso', color: 'gris' },
};

/** Cuántos días adelante cuentan como «lo que viene». */
export const VENTANA_DIAS = 7;

/** El día de una fecha, sin hora, para comparar sin sorpresas de zona horaria. */
function dia(valor) {
    return String(valor || '').slice(0, 10);
}

/**
 * El compromiso vivo de cada cotización: el de su última anotación.
 *
 * @returns {Promise<Map<number, {compromiso: string, cuando: string, descripcion: string}>>}
 */
export async function compromisosVivos() {
    const filas = await idb.todos('seguimientos');
    const ultimo = new Map();

    for (const s of filas || []) {
        const cot = Number(s.cotizacion);
        const previo = ultimo.get(cot);

        if (! previo || Number(s.numero) > Number(previo.numero)) ultimo.set(cot, s);
    }

    const vivos = new Map();

    for (const [cot, s] of ultimo) {
        // Sin próxima fecha no hay compromiso: esa anotación cerró el anterior.
        if (! s.proximo_contacto) continue;

        vivos.set(cot, {
            numero: s.numero,
            compromiso: s.compromiso || '',
            cuando: s.proximo_contacto,
            contacto: s.contacto || '',
            descripcion: s.descripcion || '',
        });
    }

    return vivos;
}

/**
 * En qué situación está el seguimiento de una cotización.
 *
 * `null` cuando la cotización no está abierta: una vendida o perdida no espera
 * ninguna llamada, y contarla sería inflar la lista con trabajo que no existe.
 */
export function estado(cotizacion, vivo, hoy) {
    const abierta = ['P', ''].includes(String(cotizacion?.estado || '').trim().toUpperCase());

    if (! abierta) return null;
    if (! vivo) return 'sin_compromiso';

    const cuando = dia(vivo.cuando);

    if (cuando < hoy) return 'atrasado';
    if (cuando === hoy) return 'hoy';

    return cuando <= sumarDias(hoy, VENTANA_DIAS) ? 'proximo' : null;
}

/** Los cuatro contadores del panel, de una sola pasada. */
export async function resumen({ cotizaciones, vendedores = null, hoy }) {
    const vivos = await compromisosVivos();
    const cuenta = { atrasado: 0, hoy: 0, proximo: 0, sin_compromiso: 0 };
    const suyo = (c) => ! vendedores || vendedores.includes((c.vendedor || '').trim());

    for (const c of cotizaciones || []) {
        if (! suyo(c)) continue;

        const e = estado(c, vivos.get(Number(c.numero)), hoy);
        if (e) cuenta[e]++;
    }

    return cuenta;
}

/**
 * Los compromisos de una tanda de cotizaciones, para pintar la lista.
 *
 * Igual que `parciales()`: una sola lectura para doscientas filas en vez de una
 * consulta por documento.
 */
export async function porCotizacion(numeros, hoy) {
    const vivos = await compromisosVivos();
    const mapa = new Map();

    for (const n of numeros) {
        const vivo = vivos.get(Number(n));
        if (vivo) mapa.set(Number(n), vivo);
    }

    return mapa;
}

/** El avance de hoy de cada cotización: la última fila de su historia. */
export async function avances() {
    const filas = await idb.todos('cotizacion_avance');
    const ultimo = new Map();

    for (const a of filas || []) {
        const cot = Number(a.cotizacion);
        const previo = ultimo.get(cot);

        if (! previo || Number(a.id) > Number(previo.id)) ultimo.set(cot, a);
    }

    return new Map([...ultimo].map(([cot, a]) => [cot, Number(a.pct)]));
}

export function sumarDias(fecha, dias) {
    const d = new Date(`${dia(fecha)}T12:00:00`);
    d.setDate(d.getDate() + dias);

    return d.toISOString().slice(0, 10);
}

/**
 * Cómo se lee una fecha de compromiso: «hoy», «mañana», «hace 3 días».
 *
 * En una lista de compromisos la fecha absoluta obliga a hacer la resta
 * mentalmente cada vez, y el vendedor la hace mal cuando va con prisa.
 */
export function cuando(valor, hoy) {
    const f = dia(valor);

    if (! f) return '';
    if (f === hoy) return 'hoy';
    if (f === sumarDias(hoy, 1)) return 'mañana';
    if (f === sumarDias(hoy, -1)) return 'ayer';

    const dias = Math.round((new Date(`${f}T12:00:00`) - new Date(`${hoy}T12:00:00`)) / 86400000);

    return dias < 0 ? `hace ${-dias} días` : `en ${dias} días`;
}

/** La hora del compromiso, si se fijó una. Las 00:00 son «sin hora». */
export function hora(valor) {
    const h = String(valor || '').slice(11, 16);

    return h && h !== '00:00' ? h : '';
}
