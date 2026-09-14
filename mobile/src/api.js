import { db } from './db';

/** Error con el status HTTP, para distinguir "sin red" de "credenciales malas". */
export class ErrorApi extends Error {
    constructor(mensaje, status, datos = null) {
        super(mensaje);
        this.status = status;
        // El cuerpo de la respuesta, cuando dice algo más que el mensaje. El alta
        // de un cliente repetido responde 409 con la ficha que ya existía, y la
        // pantalla la necesita para ofrecer abrirla en vez de repetir el error.
        this.datos = datos;
    }
}

async function pedir(ruta, { method = 'GET', body = null, auth = true } = {}) {
    const servidor = await db.getServidor();
    if (!servidor) throw new ErrorApi('Falta configurar la dirección del servidor.', 0);

    const headers = { Accept: 'application/json' };
    if (body) headers['Content-Type'] = 'application/json';
    if (auth) {
        const t = await db.getToken();
        if (t) headers.Authorization = 'Bearer ' + t;
    }

    let res;
    try {
        res = await fetch(servidor + '/api' + ruta, {
            method,
            headers,
            body: body ? JSON.stringify(body) : null,
        });
    } catch {
        throw new ErrorApi('No se pudo llegar al servidor. Revisa la conexión.', 0);
    }

    let json = null;
    try {
        json = await res.json();
    } catch { /* respuesta sin JSON */ }

    if (!res.ok) {
        const msg =
            json?.message ||
            (json?.errors ? Object.values(json.errors)[0][0] : `Error ${res.status}`);
        throw new ErrorApi(msg, res.status, json);
    }
    return json;
}

/**
 * Descarga un PDF. Va aparte de `pedir` porque la respuesta no es JSON: son
 * bytes, y hay que leerlos como `ArrayBuffer` o se corrompen al pasar por
 * texto. La versión viaja en una cabecera para saber si lo guardado sigue
 * valiendo sin volver a bajar 60 KB.
 */
async function pedirPdf(ruta) {
    const servidor = await db.getServidor();
    if (!servidor) throw new ErrorApi('Falta configurar la dirección del servidor.', 0);

    const t = await db.getToken();

    let res;
    try {
        res = await fetch(servidor + '/api' + ruta, {
            headers: { Accept: 'application/pdf', ...(t ? { Authorization: 'Bearer ' + t } : {}) },
        });
    } catch {
        throw new ErrorApi('No se pudo llegar al servidor. Revisa la conexión.', 0);
    }

    if (!res.ok) {
        let json = null;
        try { json = await res.json(); } catch { /* sin cuerpo */ }
        throw new ErrorApi(json?.message || `Error ${res.status}`, res.status, json);
    }

    return {
        bytes: await res.arrayBuffer(),
        version: Number(res.headers.get('X-Documento-Version') || 1),
        hash: res.headers.get('X-Documento-Hash') || '',
    };
}

/**
 * Sube un archivo. No pasa por `pedir` porque el cuerpo es multipart: la
 * cabecera `Content-Type` la tiene que poner el navegador, con el `boundary`
 * que él elige, y fijarla a mano rompe la subida en silencio.
 */
async function subir(ruta, campo, archivo) {
    const servidor = await db.getServidor();
    if (!servidor) throw new ErrorApi('Falta configurar la dirección del servidor.', 0);

    const cuerpo = new FormData();
    cuerpo.append(campo, archivo);

    const t = await db.getToken();

    let res;
    try {
        res = await fetch(servidor + '/api' + ruta, {
            method: 'POST',
            headers: { Accept: 'application/json', ...(t ? { Authorization: 'Bearer ' + t } : {}) },
            body: cuerpo,
        });
    } catch {
        throw new ErrorApi('No se pudo llegar al servidor. Revisa la conexión.', 0);
    }

    let json = null;
    try { json = await res.json(); } catch { /* sin cuerpo */ }

    if (!res.ok) {
        throw new ErrorApi(
            json?.message || (json?.errors ? Object.values(json.errors)[0][0] : `Error ${res.status}`),
            res.status,
            json,
        );
    }
    return json;
}

export const api = {
    ping: () => pedir('/ping', { auth: false }),
    login: (usuario, password, dispositivo) =>
        pedir('/login', { method: 'POST', auth: false, body: { usuario, password, dispositivo } }),
    bootstrap: () => pedir('/bootstrap'),
    logout: () => pedir('/logout', { method: 'POST' }),

    /** Buzón personal del usuario. No es la bitácora: son solo sus avisos. */
    avisos: (limite = 40) => pedir(`/avisos?limite=${limite}`),

    // ---- Maestros para trabajar sin señal (el orquestador está en sync.js) ----

    /** Qué maestros hay y cuántas filas tiene cada uno hoy en Softland. */
    catalogo: () => pedir('/catalogo'),

    /**
     * Una página de un maestro. `desde` trae solo lo cambiado desde esa hora y
     * `cursor` retoma donde quedó la página anterior.
     */
    catalogoPagina: (recurso, { desde = null, cursor = null, limite = 500 } = {}) => {
        const q = new URLSearchParams({ limite: String(limite) });
        if (desde) q.set('desde', desde);
        if (cursor) q.set('cursor', cursor);
        return pedir(`/catalogo/${recurso}?${q}`);
    },

    // ---- Clientes: lo único de Softland que la app escribe en esta fase ----
    cliente: (codigo) => pedir(`/clientes/${encodeURIComponent(codigo)}`),
    crearCliente: (c) => pedir('/clientes', { method: 'POST', body: c }),
    editarCliente: (codigo, c) =>
        pedir(`/clientes/${encodeURIComponent(codigo)}`, { method: 'PUT', body: c }),

    // ---- Flujo de ventas: cotización y nota de venta ----
    //
    // `client_uuid` lo pone el teléfono al guardar, antes de que haya red, y es
    // lo que hace idempotente el reenvío: si la primera respuesta se perdió, el
    // reintento devuelve el mismo número en vez de crear otro documento.

    cotizacion: (numero) => pedir(`/cotizaciones/${numero}`),
    crearCotizacion: (c) => pedir('/cotizaciones', { method: 'POST', body: c }),
    editarCotizacion: (numero, c) => pedir(`/cotizaciones/${numero}`, { method: 'PUT', body: c }),
    enviarCotizacion: (numero) => pedir(`/cotizaciones/${numero}/enviar`, { method: 'POST' }),
    perderCotizacion: (numero, m) => pedir(`/cotizaciones/${numero}/perder`, { method: 'POST', body: m }),
    anularCotizacion: (numero) => pedir(`/cotizaciones/${numero}/anular`, { method: 'POST' }),
    eliminarCotizacion: (numero) => pedir(`/cotizaciones/${numero}`, { method: 'DELETE' }),
    seguirCotizacion: (numero, s) => pedir(`/cotizaciones/${numero}/seguimientos`, { method: 'POST', body: s }),
    convertirCotizacion: (numero, nv) => pedir(`/cotizaciones/${numero}/nota-venta`, { method: 'POST', body: nv }),

    // El papel. `compartido` es el acuse de que el documento salió por un
    // camino que el servidor no ve — WhatsApp, la impresora, el visor —, y
    // sirve para que la emisión guardada quede marcada como entregada.
    pdfCotizacion: (numero) => pedirPdf(`/cotizaciones/${numero}/pdf`),
    pdfNotaVenta: (numero) => pedirPdf(`/notas-venta/${numero}/pdf`),
    marcarCompartido: (tipo, numero, canal) => pedir(
        `/${tipo === 'cotizacion' ? 'cotizaciones' : 'notas-venta'}/${numero}/compartido`,
        { method: 'POST', body: { canal } },
    ),

    // Identidad corporativa: la lee cualquiera, la cambia el admin.
    identidad: () => pedir('/identidad'),
    guardarIdentidad: (i) => pedir('/admin/identidad', { method: 'PUT', body: i }),
    borrarLogo: () => pedir('/admin/identidad/logo', { method: 'DELETE' }),
    subirLogo: (archivo) => subir('/admin/identidad/logo', 'logo', archivo),

    notaVenta: (numero) => pedir(`/notas-venta/${numero}`),
    enviarNotaVenta: (numero) => pedir(`/notas-venta/${numero}/enviar`, { method: 'POST' }),
    crearNotaVenta: (nv) => pedir('/notas-venta', { method: 'POST', body: nv }),
    editarNotaVenta: (numero, nv) => pedir(`/notas-venta/${numero}`, { method: 'PUT', body: nv }),
    aprobaciones: () => pedir('/notas-venta/aprobaciones'),
    resolverAprobacion: (numero, r) => pedir(`/notas-venta/${numero}/aprobacion`, { method: 'POST', body: r }),
    anularNotaVenta: (numero) => pedir(`/notas-venta/${numero}/anular`, { method: 'POST' }),
    eliminarNotaVenta: (numero) => pedir(`/notas-venta/${numero}`, { method: 'DELETE' }),

    // Administración (rol admin)
    usuarios: () => pedir('/admin/usuarios'),
    usuariosOpciones: () => pedir('/admin/usuarios/opciones'),
    crearUsuario: (u) => pedir('/admin/usuarios', { method: 'POST', body: u }),
    editarUsuario: (id, u) => pedir(`/admin/usuarios/${id}`, { method: 'PUT', body: u }),
    desactivarUsuario: (id) => pedir(`/admin/usuarios/${id}`, { method: 'DELETE' }),
    sesiones: (id) => pedir(`/admin/usuarios/${id}/sesiones`),
    revocarSesiones: (id) => pedir(`/admin/usuarios/${id}/sesiones`, { method: 'DELETE' }),

    configuracion: () => pedir('/admin/configuracion'),
    guardarConexion: (c) => pedir('/admin/configuracion/conexion', { method: 'PUT', body: c }),
    guardarCorreo: (c) => pedir('/admin/configuracion/correo', { method: 'PUT', body: c }),
    probarCorreo: (c) => pedir('/admin/configuracion/correo/probar', { method: 'POST', body: c }),

    notificaciones: () => pedir('/admin/notificaciones'),
    guardarNotificacion: (evento, r) =>
        pedir(`/admin/notificaciones/${evento}`, { method: 'PUT', body: r }),
    bitacora: (limite = 50) => pedir(`/admin/notificaciones/bitacora?limite=${limite}`),
};
