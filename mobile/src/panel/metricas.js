/*
 * El motor del panel. Calcula **en el teléfono**, sobre lo que ya bajó la
 * sincronización: 185 cotizaciones y 51 notas de venta de doce meses se
 * recorren en milisegundos, y así el panel se dibuja completo sin señal.
 *
 * Aquí no se lee nada: este archivo son las reglas y nada más, funciones puras
 * que reciben arreglos. Quien va a IndexedDB a buscarlos es `datos.js`. Esa
 * separación es la que permite probar las fórmulas sin navegador
 * (`npm run pruebas`).
 *
 * Las reglas, que están razonadas en `docs/panel-comercial.md` §14:
 *
 * - Lo anulado (`N`) no cuenta en ninguna parte. Lo perdido (`R`) sí cuenta
 *   como cotizado —se cotizó— y no cuenta como vendido.
 * - La conversión se mide sobre la **cohorte del período**: de las
 *   cotizaciones hechas este mes, cuántas llegaron a nota de venta. Contar las
 *   NV del mes contra las cotizaciones del mes deja que una NV de una
 *   cotización del año pasado infle el mes.
 * - El tiempo de cierre es **mediana**, no promedio: con extremos de 141 días
 *   el promedio no describe a nadie.
 * - Un estado que no está en el vocabulario es «otro», no un error y tampoco
 *   un pendiente. En INNOVAGES hay una cotización de 2022 en `A`.
 */

/** Nulo en los dos documentos. Es «nula», no «nueva». */
const ANULADO = 'N';

const est = (f) => (f.estado || '').trim().toUpperCase();
const dentro = (f, r) => {
    const d = (f.fecha || '').slice(0, 10);

    return d >= r.desde && d <= r.hasta;
};

/** Suma y cuenta en una sola pasada. */
function agregar(filas) {
    let monto = 0;

    for (const f of filas) monto += Number(f.total) || 0;

    return { monto, n: filas.length };
}

function mediana(valores) {
    if (! valores.length) return null;

    const v = [...valores].sort((a, b) => a - b);
    const medio = Math.floor(v.length / 2);

    return v.length % 2 ? v[medio] : (v[medio - 1] + v[medio]) / 2;
}

/** Días entre dos fechas de documento, contando por día calendario. */
function diasEntre(desde, hasta) {
    const a = new Date(`${(desde || '').slice(0, 10)}T00:00:00`);
    const b = new Date(`${(hasta || '').slice(0, 10)}T00:00:00`);

    if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) return null;

    return Math.round((b - a) / 86400000);
}

/**
 * Todo lo de un período.
 *
 * `vendedores` es `null` para el ámbito empresa (sin filtro) o la lista de
 * códigos del ámbito. Ojo: el teléfono sólo tiene bajados los documentos que
 * el servidor le dejó ver, así que el filtro de aquí acota dentro de eso, no
 * abre nada.
 */
export function calcular({ cotizaciones = [], notas = [], rango, vendedores = null }) {
    const suyo = (f) => ! vendedores || vendedores.includes((f.vendedor || '').trim());

    const cot = cotizaciones.filter((f) => suyo(f) && est(f) !== ANULADO);
    const nv = notas.filter((f) => suyo(f) && est(f) !== ANULADO);

    const cotPeriodo = cot.filter((f) => dentro(f, rango));
    const nvPeriodo = nv.filter((f) => dentro(f, rango));

    // Qué cotizaciones acabaron en nota de venta. Se mira contra **todas** las
    // notas bajadas, no sólo las del período: una cotización de fin de mes que
    // se convierte al mes siguiente sí convirtió.
    const convertidas = new Set(nv.map((f) => Number(f.cotizacion)).filter(Boolean));
    const conNv = cotPeriodo.filter((f) => convertidas.has(Number(f.numero)));

    const cotizado = agregar(cotPeriodo);
    const vendido = agregar(nvPeriodo);
    const perdidas = agregar(cotPeriodo.filter((f) => est(f) === 'R'));

    // El cierre necesita la cotización de origen, que puede no estar bajada si
    // es más vieja que la ventana de doce meses. Esas notas no entran.
    const porNumero = new Map(cot.map((f) => [Number(f.numero), f]));
    const cierres = [];

    for (const f of nvPeriodo) {
        const origen = porNumero.get(Number(f.cotizacion));
        if (! origen) continue;

        const d = diasEntre(origen.fecha, f.fecha);
        // Hay notas de venta fechadas antes que su cotización — el mínimo real
        // en INNOVAGES es −89 días. No se corrigen: se descartan.
        if (d !== null && d >= 0) cierres.push(d);
    }

    return {
        rango,
        cotizado,
        vendido,
        perdidas,
        conversion: cotizado.n ? { pct: (conNv.length * 100) / cotizado.n, n: conNv.length, base: cotizado.n } : null,
        cierre: mediana(cierres),
        cierre_n: cierres.length,
        ticket: vendido.n ? vendido.monto / vendido.n : null,
    };
}

/*
 * La cotización no tiene columna de vencimiento en Softland: se calcula.
 */
export const AVISO_VENCIMIENTO_DIAS = 7;

/**
 * En qué situación está una cotización abierta. Devuelve `null` si no es una
 * cotización abierta, o si no se puede saber.
 *
 * Es **la misma función** que usa el panel para contar y la lista para
 * filtrar. Si cada una tuviera su copia de la regla, el panel diría «3 por
 * vencer» y al tocarlo saldrían cuatro.
 *
 * El corte no es un número inventado: es la **vigencia que la empresa le pone
 * a su cotización** (`identidad.vigencia_cotizacion_dias`, 30 por defecto, y
 * es el mismo número que sale impreso en el PDF). Pasada esa fecha la
 * cotización dejó de estar en pie; lo que el vendedor hace con ella —insistir,
 * renovarla o cerrarla como perdida— es decisión suya, pero seguir contándola
 * como oportunidad abierta sería contarse un cuento.
 */
export function situacion(f, { hoy, vigencia = 30, avisoDias = AVISO_VENCIMIENTO_DIAS }) {
    if (est(f) !== 'P') return null;

    const edad = diasEntre(f.fecha, hoy);
    if (edad === null) return null;

    const faltan = vigencia - edad;

    if (faltan < 0) return 'vencida';
    if (faltan <= avisoDias) return 'por_vencer';

    return 'abierta';
}

/**
 * Lo pendiente, que no depende del período: una cotización abierta de hace
 * ocho meses sigue abierta hoy, mire uno el mes que mire.
 */
export function pendientes({ cotizaciones = [], vendedores = null, hoy, vigencia = 30, avisoDias = AVISO_VENCIMIENTO_DIAS }) {
    const suyo = (f) => ! vendedores || vendedores.includes((f.vendedor || '').trim());
    const abiertas = cotizaciones.filter((f) => suyo(f) && est(f) === 'P');

    const tramos = { reciente: [], mes: [], trimestre: [], viejas: [] };
    const grupos = { por_vencer: [], vencida: [], abierta: [] };

    for (const f of abiertas) {
        const edad = diasEntre(f.fecha, hoy);
        if (edad === null) continue;

        if (edad <= 7) tramos.reciente.push(f);
        else if (edad <= 30) tramos.mes.push(f);
        else if (edad <= 90) tramos.trimestre.push(f);
        else tramos.viejas.push(f);

        grupos[situacion(f, { hoy, vigencia, avisoDias })].push(f);
    }

    return {
        total: agregar(abiertas),
        reciente: agregar(tramos.reciente),
        mes: agregar(tramos.mes),
        trimestre: agregar(tramos.trimestre),
        viejas: agregar(tramos.viejas),
        por_vencer: agregar(grupos.por_vencer),
        vencidas: agregar(grupos.vencida),
        abiertas: agregar(grupos.abierta),
    };
}
