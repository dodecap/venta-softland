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
    seguirCotizacion: (numero, s) => pedir(`/cotizaciones/${numero}/seguimientos`, { method: 'POST', body: s }),
    convertirCotizacion: (numero, nv) => pedir(`/cotizaciones/${numero}/nota-venta`, { method: 'POST', body: nv }),

    notaVenta: (numero) => pedir(`/notas-venta/${numero}`),
    crearNotaVenta: (nv) => pedir('/notas-venta', { method: 'POST', body: nv }),
    editarNotaVenta: (numero, nv) => pedir(`/notas-venta/${numero}`, { method: 'PUT', body: nv }),
    aprobaciones: () => pedir('/notas-venta/aprobaciones'),
    resolverAprobacion: (numero, r) => pedir(`/notas-venta/${numero}/aprobacion`, { method: 'POST', body: r }),

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
