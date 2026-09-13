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

    getSincronizado: () => leer('sincronizado_at', null),
    setSincronizado: (v) => escribir('sincronizado_at', v),

    /** Cierra sesión pero conserva la dirección del servidor: no se reconfigura cada vez. */
    async olvidarSesion() {
        for (const k of ['token', 'usuario', 'catalogos', 'sincronizado_at']) {
            await Preferences.remove({ key: k });
        }
    },
};
