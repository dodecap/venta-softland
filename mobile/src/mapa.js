import { Capacitor, registerPlugin } from '@capacitor/core';
import { nombre as nombreDe } from './catalogos.js';

/*
 * Cómo llegar al cliente.
 *
 * ## Por qué no hay un mapa dibujado dentro de la app
 *
 * Porque dibujarlo exige una llave de Google o de Mapbox **dentro del APK**, y
 * un APK se descompila: es el mismo argumento por el que la llave del SII vive
 * en el servidor. Exigiría además una cuenta de facturación por instalación
 * —y aquí la regla es un repositorio y N instalaciones—, pesaría megas, y el
 * mapa sin señal no se dibujaría igual.
 *
 * Lo que hacemos es pasarle el destino a la aplicación de mapas que el vendedor
 * ya usa —Google Maps, Waze, la que tenga—, que ya está instalada, ya tiene su
 * cuenta y ya sabe sus preferencias de tráfico. Cero permisos y cero llaves.
 *
 * ## Por qué hace falta un plugin de treinta líneas
 *
 * Por lo mismo que el calendario: esto es una **intención** de Android. Aquí sí
 * es `ACTION_VIEW`, que es lo que haría un `openUrl`, pero no tenemos ninguno
 * instalado y traer un paquete entero para una línea sería pagar de más —el
 * mismo criterio que dejó escrito `CalendarioPlugin`—. Y el plugin hace algo
 * que un `openUrl` pelado no haría: probar las dos intenciones en orden.
 *
 * ## Dos intenciones, y el orden importa
 *
 * `google.navigation:` arranca la guía paso a paso, que es lo que quiere decir
 * «cómo llegar» cuando uno está en la calle. Sólo la atienden algunas apps. Si
 * no la atiende ninguna se cae a `geo:`, que la atienden todas y deja el punto
 * en el mapa con el botón de ir al lado. Un paso más, pero nunca un callejón.
 *
 * ## Qué no hace
 *
 * No calcula nada sin señal: la intención se lanza igual, pero la ruta la traza
 * la app de mapas y eso necesita red (o un mapa descargado, que es cosa suya).
 * Y no optimiza el orden de las visitas del día: eso es otro problema —necesita
 * las coordenadas de cada cliente, y Softland no las guarda—.
 */

const Mapa = registerPlugin('Mapa');

const nativo = Capacitor.isNativePlatform();

/*
 * El país no es adorno. Sin él, «Serrano 123, Santiago» cae en cualquiera de
 * los Santiagos del continente, y hay comunas chilenas cuyo nombre se repite
 * en Argentina y en España. Es la única pieza del destino que no sale de la
 * ficha, y por eso está aquí y no en la llamada.
 */
const PAIS = 'Chile';

/**
 * Traduce un código de maestro a su nombre, o devuelve vacío.
 *
 * `catalogos.nombre()` devuelve el código cuando no sabe traducirlo, que es lo
 * correcto en una pantalla —«08301» al menos se puede buscar en Softland— y lo
 * contrario de lo que sirve aquí: un código metido en la dirección es ruido que
 * el buscador de mapas no entiende y que puede desplazar el acierto.
 */
function traducido(maestro, codigo) {
    const c = String(codigo ?? '').trim();
    if (! c) return '';

    const n = nombreDe(maestro, c);

    return n === c ? '' : n;
}

/**
 * La dirección como se la damos al mapa, a partir de nombres ya traducidos.
 *
 * @param {object} partes
 * @param {string} partes.direccion  la calle y el número
 * @param {string} partes.comuna     nombre, no código
 * @param {string} partes.ciudad     nombre, no código
 */
export function destino({ direccion = '', comuna = '', ciudad = '' }) {
    return [direccion, comuna, ciudad, PAIS]
        .map((t) => String(t ?? '').trim())
        .filter(Boolean)
        // En muchas fichas la comuna y la ciudad son la misma palabra —en
        // INNOVAGES, «Santiago · Santiago»—. Repetirla no ayuda a geocodificar
        // y queda feo en la pantalla del mapa.
        .filter((t, i, a) => a.findIndex((o) => o.toLowerCase() === t.toLowerCase()) === i)
        .join(', ');
}

/**
 * La dirección de un cliente tal como está en el teléfono.
 *
 * Devuelve vacío si no hay calle. Es deliberado: una comuna sola no es una
 * dirección, y mandar al vendedor al centro geométrico de Maipú es peor que
 * decirle que esa ficha no tiene dónde.
 */
export function destinoDe(cliente) {
    if (! String(cliente?.direccion ?? '').trim()) return '';

    return destino({
        direccion: cliente.direccion,
        comuna: traducido('comunas', cliente.comuna),
        ciudad: traducido('ciudades', cliente.ciudad),
    });
}

/** Si esta ficha da para llevar a alguien hasta la puerta. */
export function sabeDondeVive(cliente) {
    return Boolean(destinoDe(cliente));
}

/**
 * Abre la aplicación de mapas con la ruta hasta el cliente.
 *
 * @returns {Promise<{navegando: boolean}>} `navegando` dice si arrancó la guía
 *          paso a paso o si sólo se pudo dejar el punto en el mapa.
 */
export async function comoLlegar(cliente) {
    const a = destinoDe(cliente);

    if (! a) throw new Error('Este cliente no tiene dirección en Softland.');

    if (! nativo) {
        // El respaldo del navegador de desarrollo, que no tiene intenciones.
        // Sirve para comprobar qué dirección le estamos pasando al mapa, que es
        // justo la parte que se puede equivocar.
        const url = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(a)}`;
        window.open(url, '_blank', 'noopener');

        return { navegando: false };
    }

    return Mapa.comoLlegar({ destino: a });
}
