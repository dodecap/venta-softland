import { Capacitor, registerPlugin } from '@capacitor/core';
import { Share } from '@capacitor/share';
import { Directory, Filesystem } from '@capacitor/filesystem';

/*
 * El compromiso, en el calendario del teléfono.
 *
 * ## Por qué no escribimos en el calendario
 *
 * Porque escribir exige permiso de lectura **y** escritura del calendario — de
 * los que hacen que la gente desinstale una app— y porque escribir en silencio
 * es peor que no escribir: el vendedor no sabría en qué calendario quedó ni con
 * qué alarma.
 *
 * Lo que hacemos es pedirle al sistema que abra **su** pantalla de nuevo evento
 * con todo relleno. La atiende el calendario que el vendedor ya usa —Google,
 * Outlook, el de Samsung—, él confirma, y puede cambiar la hora o ponerle un
 * recordatorio antes de guardar. Cero permisos, y funciona sin señal, que es
 * donde se anota un compromiso de verdad.
 *
 * ## Por qué hace falta un plugin de tres líneas
 *
 * Porque esto es una **intención** de Android (`ACTION_INSERT`), no una
 * dirección web. Ni `openUrl` ni una navegación del WebView saben lanzarla: las
 * dos acaban en `ACTION_VIEW`, que no es lo mismo. El plugin sólo traduce.
 *
 * En el navegador de desarrollo no hay calendario, así que se descarga un
 * `.ics` — que es lo más cercano y sirve para probar el texto del evento sin el
 * teléfono delante.
 */

const Calendario = registerPlugin('Calendario');

const nativo = Capacitor.isNativePlatform();

export const calendarioDisponible = nativo;

/**
 * Abre el calendario con el evento listo para guardar.
 *
 * @param {object} evento
 * @param {string} evento.titulo
 * @param {string} evento.descripcion
 * @param {string} evento.lugar
 * @param {string} evento.fecha     `YYYY-MM-DD`
 * @param {string} evento.hora      `HH:MM`, o vacío para todo el día
 * @param {number} evento.minutos   cuánto dura
 */
export async function agendar({ titulo, descripcion = '', lugar = '', fecha, hora = '', minutos = 30 }) {
    if (! fecha) throw new Error('Un compromiso sin fecha no se puede agendar.');

    const inicio = new Date(`${fecha}T${hora || '09:00'}:00`);
    const fin = new Date(inicio.getTime() + minutos * 60000);

    if (! nativo) {
        return descargarIcs({ titulo, descripcion, lugar, inicio, fin, todoElDia: ! hora });
    }

    return Calendario.agendar({
        titulo,
        descripcion,
        lugar,
        // En milisegundos, que es lo que entiende el proveedor de calendario de
        // Android.
        inicio: inicio.getTime(),
        fin: fin.getTime(),
        todoElDia: ! hora,
    });
}

/**
 * El respaldo del navegador: un archivo `.ics`.
 *
 * Google Calendar y Outlook lo importan, así que sirve de verdad; simplemente
 * es un paso más y por eso no es el camino principal.
 */
async function descargarIcs({ titulo, descripcion, lugar, inicio, fin, todoElDia }) {
    const ics = construirIcs({ titulo, descripcion, lugar, inicio, fin, todoElDia });
    const nombre = 'compromiso.ics';

    if (typeof document !== 'undefined' && ! Capacitor.isNativePlatform()) {
        const url = URL.createObjectURL(new Blob([ics], { type: 'text/calendar' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = nombre;
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 60_000);

        return { compartido: false };
    }

    const { uri } = await Filesystem.writeFile({
        path: nombre,
        data: btoa(unescape(encodeURIComponent(ics))),
        directory: Directory.Cache,
    });

    await Share.share({ title: titulo, files: [uri] });

    return { compartido: true };
}

/**
 * El evento en formato iCalendar.
 *
 * Las líneas van con `\r\n` porque el formato lo exige — un `.ics` con saltos
 * de Unix lo rechazan varios clientes, y el fallo aparece sólo en el de algunos.
 */
function construirIcs({ titulo, descripcion, lugar, inicio, fin, todoElDia }) {
    const z = (d) => d.toISOString().replace(/[-:]|\.\d{3}/g, '');
    const dia = (d) => d.toISOString().slice(0, 10).replace(/-/g, '');
    const escapar = (t) => String(t || '').replace(/([,;\\])/g, '\\$1').replace(/\n/g, '\\n');

    const cuando = todoElDia
        ? [`DTSTART;VALUE=DATE:${dia(inicio)}`, `DTEND;VALUE=DATE:${dia(fin)}`]
        : [`DTSTART:${z(inicio)}`, `DTEND:${z(fin)}`];

    return [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Venta Softland//ES',
        'BEGIN:VEVENT',
        `UID:${Date.now()}@venta-softland`,
        `DTSTAMP:${z(new Date())}`,
        ...cuando,
        `SUMMARY:${escapar(titulo)}`,
        `DESCRIPTION:${escapar(descripcion)}`,
        `LOCATION:${escapar(lugar)}`,
        'END:VEVENT',
        'END:VCALENDAR',
    ].join('\r\n');
}
