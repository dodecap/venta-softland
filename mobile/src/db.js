import { Preferences } from '@capacitor/preferences';

/**
 * Estado local del teléfono.
 *
 * Fase 1 guarda poco (servidor, token, usuario, maestros chicos) y le alcanza
 * con Preferences. Cuando entren los catálogos grandes — 1.200 productos, 3.800
 * clientes, 2.200 precios — esto pasa a IndexedDB, porque Preferences guarda
 * todo como un solo string por clave y no se puede consultar ni filtrar.
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

    getCatalogos: () => leer('catalogos', {}),
    setCatalogos: (v) => escribir('catalogos', v),

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

    /** Cierra sesión pero conserva la dirección del servidor: no se reconfigura cada vez. */
    async olvidarSesion() {
        for (const k of ['token', 'usuario', 'catalogos', 'servidor_info', 'sincronizado_at', 'aviso_visto']) {
            await Preferences.remove({ key: k });
        }
    },
};
