import { db } from './db';

/** Error con el status HTTP, para distinguir "sin red" de "credenciales malas". */
export class ErrorApi extends Error {
    constructor(mensaje, status) {
        super(mensaje);
        this.status = status;
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
        throw new ErrorApi(msg, res.status);
    }
    return json;
}

export const api = {
    ping: () => pedir('/ping', { auth: false }),
    login: (usuario, password, dispositivo) =>
        pedir('/login', { method: 'POST', auth: false, body: { usuario, password, dispositivo } }),
    bootstrap: () => pedir('/bootstrap'),
    logout: () => pedir('/logout', { method: 'POST' }),

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
