/*
 * Contacto con soporte por WhatsApp.
 *
 * El número vive aquí y en ningún otro lado. Es el de la empresa, no el de una
 * persona: si mañana lo atiende otro, se cambia esta línea y se recompila.
 *
 * El mensaje va escrito de antemano con quién escribe, desde qué servidor y con
 * qué versión. No es adorno: la primera pregunta de soporte siempre es «¿quién
 * eres y qué versión tienes?», y el vendedor que escribe desde un pasillo sin
 * señal no la va a saber responder.
 */

/** Número de soporte en formato internacional, solo dígitos. */
export const NUMERO = '56978559103';

/** Cómo se ve escrito, para mostrarlo en la pantalla. */
export const NUMERO_VISIBLE = '+56 9 7855 9103';

/**
 * Abre el chat de WhatsApp con soporte.
 *
 * `wa.me` y no el esquema `whatsapp://`: si el teléfono no tiene WhatsApp
 * instalado, el enlace web ofrece instalarlo en vez de fallar en silencio.
 *
 * Dentro del APK la WebView no navega a un dominio ajeno: Capacitor intercepta
 * y lo manda al sistema, que lo entrega a WhatsApp. `window.open` devuelve
 * null en ese caso, y también en un navegador con bloqueo de ventanas, así que
 * el respaldo es navegar.
 *
 * @param {object} ctx  usuario, servidor y versión, para no hacérselo contar.
 */
export function abrirSoporte(ctx = {}) {
    const url = `https://wa.me/${NUMERO}?text=${encodeURIComponent(mensaje(ctx))}`;
    const w = window.open(url, '_blank');
    if (! w) window.location.href = url;
}

function mensaje({ usuario, servidor, version } = {}) {
    const lineas = ['Hola, necesito ayuda con la app de ventas.', ''];

    if (usuario?.nombre) {
        const rol = usuario.rol ? ` (${usuario.rol}${usuario.ven_cod ? ` ${usuario.ven_cod}` : ''})` : '';
        lineas.push(`Soy ${usuario.nombre}${rol}.`);
    }
    if (servidor) lineas.push(`Servidor: ${servidor.replace(/^https?:\/\//, '')}`);
    if (version) lineas.push(`Versión: ${version}`);

    lineas.push('', 'Lo que me pasa: ');
    return lineas.join('\n');
}
