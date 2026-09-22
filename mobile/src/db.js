import { Preferences } from '@capacitor/preferences';
import { idb } from './idb';

/**
 * Preferencias del teléfono: cosas chicas, de una línea, que no se consultan.
 *
 * Aquí vive lo que identifica al aparato y a la sesión — servidor, token,
 * usuario, dónde dejó el botón flotante, qué tamaño de letra eligió. Los
 * **maestros no están aquí**: desde la fase 2 viven en IndexedDB (`idb.js`),
 * porque Preferences guarda un string por clave y buscar un cliente entre 3.373
 * obligaría a leer y parsear el bloque completo en cada tecla.
 */

async function leer(clave, porDefecto) {
    const { value } = await Preferences.get({ key: clave });
    if (!value) return porDefecto;
    try {
        return JSON.parse(value);
    } catch {
        return porDefecto;
    }
}

async function escribir(clave, valor) {
    await Preferences.set({ key: clave, value: JSON.stringify(valor) });
}

export const db = {
    async getServidor() {
        return (await Preferences.get({ key: 'servidor' })).value || '';
    },
    async setServidor(v) {
        await Preferences.set({ key: 'servidor', value: (v || '').replace(/\/+$/, '') });
    },

    async getToken() {
        return (await Preferences.get({ key: 'token' })).value || null;
    },
    async setToken(v) {
        await Preferences.set({ key: 'token', value: v || '' });
    },

    getUsuario: () => leer('usuario', null),
    setUsuario: (v) => escribir('usuario', v),

    /** Datos del servidor que devuelve el bootstrap: nombre, base y RUT emisor. */
    getServidorInfo: () => leer('servidor_info', null),
    setServidorInfo: (v) => escribir('servidor_info', v),

    /**
     * Lado donde el vendedor dejó el botón flotante: izq | centro | der.
     * Es del teléfono, no de la sesión: el aparato tiene un dueño y una mano.
     */
    async getPosBoton() {
        const v = (await Preferences.get({ key: 'pos_boton' })).value;
        return ['izq', 'centro', 'der'].includes(v) ? v : 'centro';
    },
    async setPosBoton(v) {
        await Preferences.set({ key: 'pos_boton', value: v });
    },

    getSincronizado: () => leer('sincronizado_at', null),
    setSincronizado: (v) => escribir('sincronizado_at', v),

    /**
     * Qué período y qué ámbito dejó elegidos el vendedor en el panel.
     * Cada uno prefiere quedarse en el suyo —hoy, trimestre, el equipo— y no
     * quiere volver a elegirlo cada vez que entra. Del aparato, como la
     * densidad: no se manda al servidor.
     */
    async getPanelPeriodo() {
        return (await Preferences.get({ key: 'panel_periodo' })).value || null;
    },
    async setPanelPeriodo(v) {
        await Preferences.set({ key: 'panel_periodo', value: v || '' });
    },
    async getPanelAmbito() {
        return (await Preferences.get({ key: 'panel_ambito' })).value || null;
    },
    async setPanelAmbito(v) {
        await Preferences.set({ key: 'panel_ambito', value: v || '' });
    },

    /**
     * Tamaño de la interfaz: compacta | normal | amplia.
     * Del aparato, no de la sesión — depende de la pantalla y de la vista de
     * quien lo usa, no de quién entró. Ver `densidad.js`.
     */
    async getDensidad() {
        const v = (await Preferences.get({ key: 'densidad' })).value;
        return ['compacta', 'normal', 'amplia'].includes(v) ? v : 'normal';
    },
    async setDensidad(v) {
        await Preferences.set({ key: 'densidad', value: v });
    },

    /**
     * Último aviso que el vendedor alcanzó a ver, para contar los no leídos.
     * Se guarda en el teléfono a propósito: así la cuenta funciona sin señal y
     * abrir el buzón no escribe en la base cada vez.
     */
    async getAvisoVisto() {
        return Number((await Preferences.get({ key: 'aviso_visto' })).value || 0);
    },
    async setAvisoVisto(id) {
        await Preferences.set({ key: 'aviso_visto', value: String(id || 0) });
    },

    /*
     * La última versión de la que ya se avisó en este teléfono.
     *
     * Va por versión y no por un id: el aviso de que hay una nueva no es una
     * fila del servidor —el servidor no sabe qué versión tiene cada aparato—,
     * así que lo que hay que recordar es de cuál se avisó, para que la
     * siguiente vuelva a encender el punto rojo y ésta no.
     */
    async getVersionAvisada() {
        return (await Preferences.get({ key: 'version_avisada' })).value || '';
    },
    async setVersionAvisada(v) {
        await Preferences.set({ key: 'version_avisada', value: String(v || '') });
    },

    /** Cierra sesión pero conserva la dirección del servidor: no se reconfigura cada vez. */
    async olvidarSesion() {
        for (const k of ['token', 'usuario', 'catalogos', 'servidor_info', 'sincronizado_at', 'aviso_visto']) {
            await Preferences.remove({ key: k });
        }
        // `catalogos` ya no se escribe: se borra por los teléfonos que vienen de
        // la fase 1 y todavía lo tienen guardado ocupando espacio.

        // Y se van los maestros. No es prolijidad: en el teléfono hay 3.373
        // clientes de la empresa con su RUT, su dirección y su correo. Un
        // aparato que cambia de manos no se los lleva puestos.
        await idb.vaciarTodo();
    },
};
