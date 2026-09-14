/*
 * El período del panel: hoy · semana · mes · trimestre · año.
 *
 * Todo sale en `AAAA-MM-DD` y se compara contra los primeros diez caracteres
 * de la fecha del documento, que llega del servidor como `2026-09-14T00:00:00`.
 * Comparar textos evita el problema de siempre: un `new Date('2026-09-01')` se
 * interpreta en UTC y en Chile cae el 31 de agosto, así que el primer día del
 * mes se quedaría fuera de su propio mes.
 *
 * El calendario es el del teléfono. Las fechas de los documentos son del
 * servidor —ésas no se discuten—, pero «este mes» es el mes del vendedor, que
 * es quien mira la pantalla. Si el aparato tiene mal la fecha, el panel lo
 * refleja; es el mismo aparato que fecha las cotizaciones creadas sin señal.
 */

export const PERIODOS = [
    { id: 'hoy', rotulo: 'Hoy' },
    { id: 'semana', rotulo: 'Semana' },
    { id: 'mes', rotulo: 'Mes' },
    { id: 'trimestre', rotulo: 'Trimestre' },
    { id: 'ano', rotulo: 'Año' },
];

export const POR_DEFECTO = 'mes';

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
    'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** `AAAA-MM-DD` en hora local, que es lo que `toISOString()` no da. */
export function dia(f) {
    const m = `${f.getMonth() + 1}`.padStart(2, '0');
    const d = `${f.getDate()}`.padStart(2, '0');

    return `${f.getFullYear()}-${m}-${d}`;
}

const capitalizar = (t) => t.charAt(0).toUpperCase() + t.slice(1);

/**
 * El rango de un período: `{ id, desde, hasta, etiqueta }`.
 *
 * La semana empieza el lunes. `getDay()` devuelve 0 para el domingo, y tomarlo
 * como primer día dejaría el lunes en la semana anterior.
 */
export function rango(id, ref = new Date()) {
    const a = ref.getFullYear();
    const m = ref.getMonth();
    const d = ref.getDate();

    switch (id) {
        case 'hoy': {
            const hoy = dia(ref);

            return { id, desde: hoy, hasta: hoy, etiqueta: 'Hoy' };
        }
        case 'semana': {
            const desplazamiento = (ref.getDay() + 6) % 7;
            const lunes = new Date(a, m, d - desplazamiento);
            const domingo = new Date(a, m, d - desplazamiento + 6);

            return { id, desde: dia(lunes), hasta: dia(domingo), etiqueta: 'Esta semana' };
        }
        case 'trimestre': {
            const primero = Math.floor(m / 3) * 3;

            return {
                id,
                desde: dia(new Date(a, primero, 1)),
                hasta: dia(new Date(a, primero + 3, 0)),
                etiqueta: `Trim. ${primero / 3 + 1} · ${a}`,
            };
        }
        case 'ano':
            return {
                id,
                desde: dia(new Date(a, 0, 1)),
                hasta: dia(new Date(a, 11, 31)),
                etiqueta: `${a}`,
            };
        default:
            return {
                id: 'mes',
                desde: dia(new Date(a, m, 1)),
                hasta: dia(new Date(a, m + 1, 0)),
                // El año sólo se escribe si no es el corriente: «Septiembre»
                // ocupa menos que «Septiembre 2026» y dice lo mismo.
                etiqueta: capitalizar(MESES[m]) + (a === new Date().getFullYear() ? '' : ` ${a}`),
            };
    }
}

/**
 * El período anterior, del mismo largo. Mes contra mes, trimestre contra
 * trimestre: comparar septiembre con los treinta días anteriores mezclaría dos
 * meses y ningún vendedor lee su mes así.
 */
export function anterior(r, ref = new Date()) {
    const a = ref.getFullYear();
    const m = ref.getMonth();
    const d = ref.getDate();

    switch (r.id) {
        case 'hoy':
            return rango('hoy', new Date(a, m, d - 1));
        case 'semana':
            return rango('semana', new Date(a, m, d - 7));
        case 'trimestre':
            return rango('trimestre', new Date(a, m - 3, 1));
        case 'ano':
            return rango('ano', new Date(a - 1, 0, 1));
        default:
            return rango('mes', new Date(a, m - 1, 1));
    }
}

/** Cuántos días caen dentro del rango. Sirve para el ritmo diario de la meta. */
export function largoEnDias(r) {
    const [ad, md, dd] = r.desde.split('-').map(Number);
    const [ah, mh, dh] = r.hasta.split('-').map(Number);

    return Math.round((new Date(ah, mh - 1, dh) - new Date(ad, md - 1, dd)) / 86400000) + 1;
}
