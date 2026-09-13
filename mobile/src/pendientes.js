import { ref } from 'vue';
import { api, ErrorApi } from './api';
import { idb } from './idb';
import { conectado } from './red';

/*
 * Bandeja de salida: lo que se hizo sin señal y todavía no llegó a Softland.
 *
 * El vendedor da de alta a un cliente en la puerta de la empresa, donde no hay
 * datos. La ficha queda en el teléfono, se ve en la lista con la franja ámbar
 * de «sin enviar», y se manda sola cuando vuelve la red.
 *
 * ## Por qué esto no duplica clientes
 *
 * La clave del cliente en Softland es el RUT (`CodAux` es el cuerpo del RUT),
 * no un correlativo. Si el teléfono manda el alta y se corta antes de recibir
 * la respuesta, el reintento choca contra la clave primaria y el servidor
 * responde 409 con la ficha que ya existe. Eso **es** el resultado correcto:
 * el cliente está creado. Por eso un 409 al reintentar un alta se trata como
 * éxito y no como error.
 *
 * ## Qué NO hace
 *
 * No resuelve conflictos. Si dos vendedores editan al mismo cliente sin señal,
 * gana el último que llegue. Con tres personas en terreno y una cartera de
 * 3.373 clientes eso es teórico; el día que deje de serlo, aquí es donde hay
 * que mirar.
 */

export const porEnviar = ref(0);
export const enviando = ref(false);

/** Cuenta lo que falta por mandar y lo publica para la barra y para Cuenta. */
export async function contarPendientes() {
    const filas = await idb.todos('pendientes');
    porEnviar.value = filas.length;
    return filas;
}

export function nuevoUuid() {
    // `crypto.randomUUID` existe en el WebView de Android desde la 92; el respaldo
    // es para el navegador de desarrollo servido por http, donde no está.
    return crypto.randomUUID?.() ?? `u-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

/**
 * Deja una operación en la bandeja.
 *
 * Lo que llega es el formulario de una pantalla Vue, o sea un proxy reactivo, y
 * IndexedDB no sabe clonar proxies: guardarlo tal cual revienta con
 * «could not be cloned» justo en el caso que esto vino a resolver — el alta sin
 * señal. Se aplana con un viaje por JSON, que además garantiza que lo guardado
 * es exactamente lo que después se puede mandar por la red.
 *
 * @param {string} accion  `cliente.crear` | `cliente.editar`
 * @param {string} clave   Código del cliente: identifica a qué ficha afecta.
 */
export async function encolar(accion, clave, datos) {
    const item = {
        uuid: nuevoUuid(),
        accion,
        clave,
        datos: JSON.parse(JSON.stringify(datos)),
        estado: 'pendiente',
        mensaje: '',
        creado: new Date().toISOString(),
    };
    await idb.guardar('pendientes', [item]);
    await contarPendientes();
    return item;
}

/** ¿Esta ficha tiene algo sin enviar? Es lo que pinta la franja ámbar. */
export async function pendienteDe(clave) {
    const filas = await idb.porIndice('pendientes', 'clave', clave);
    return filas[0] ?? null;
}

/**
 * Tira a la basura una operación sin enviarla, y deshace lo que había dejado
 * en el teléfono.
 *
 * Un alta descartada se borra entera: ese cliente nunca existió en Softland y
 * dejarlo en la lista sería mentirle al vendedor — además, la ficha lleva el
 * sello local, que la haría sobrevivir a todas las descargas completas.
 *
 * Una edición descartada solo pierde el sello: la ficha sí existe allá, y la
 * próxima sincronización la traerá como esté en Softland.
 */
export async function descartar(uuid) {
    const item = (await idb.todos('pendientes')).find((p) => p.uuid === uuid);

    if (item?.accion === 'cliente.crear') {
        await idb.borrar('clientes', item.clave);
        for (const c of await idb.porIndice('contactos', 'cliente', item.clave)) {
            await idb.borrar('contactos', [c.cliente, c.nombre]);
        }
    } else if (item?.accion === 'cliente.editar') {
        // La ficha de verdad está en Softland: si hay señal se vuelve a pedir,
        // que en el teléfono quedó la versión editada y ya no la quiere nadie.
        let repuesta = false;
        if (conectado.value) {
            try {
                const r = await api.cliente(item.clave);
                await aplicarRespuesta(item, r);
                repuesta = true;
            } catch { /* se arregla en la próxima sincronización */ }
        }

        // Sin señal se deja como está, pero sin el sello local: así una
        // descarga completa se la lleva y la trae como esté en Softland.
        if (! repuesta) {
            const ficha = await idb.obtener('clientes', item.clave);
            if (ficha) {
                delete ficha._s;
                await idb.guardar('clientes', [ficha]);
            }
        }
    }

    await idb.borrar('pendientes', uuid);
    await contarPendientes();
}

/**
 * Manda lo que haya, en orden de llegada.
 *
 * Se para en el primer fallo de red: si no hay señal para uno, no la hay para
 * los siguientes, y seguir intentando solo gasta batería. Un rechazo del
 * servidor (4xx) es distinto: ese ítem no va a mejorar solo, se marca con el
 * motivo y se sigue con el resto.
 */
export async function enviarPendientes() {
    if (enviando.value || ! conectado.value) return { enviados: 0, fallidos: 0 };

    enviando.value = true;
    const resumen = { enviados: 0, fallidos: 0, sinRed: false };

    try {
        const filas = (await contarPendientes()).sort((a, b) => a.creado.localeCompare(b.creado));

        for (const item of filas) {
            if (item.estado === 'rechazado') {
                resumen.fallidos++;
                continue;
            }

            try {
                const r = await enviarUno(item);
                await aplicarRespuesta(item, r);
                await idb.borrar('pendientes', item.uuid);
                resumen.enviados++;
            } catch (e) {
                // Sin red: se deja todo como está y se reintenta más tarde.
                if (e instanceof ErrorApi && e.status === 0) {
                    resumen.sinRed = true;
                    break;
                }

                // El servidor lo rechazó por lo que trae, no por el camino.
                await idb.guardar('pendientes', [{
                    ...item,
                    estado: 'rechazado',
                    mensaje: e.message,
                }]);
                resumen.fallidos++;
            }
        }

        await contarPendientes();
        return resumen;
    } finally {
        enviando.value = false;
    }
}

async function enviarUno(item) {
    if (item.accion === 'cliente.crear') {
        try {
            return await api.crearCliente(item.datos);
        } catch (e) {
            // 409 = el RUT ya está. Si venimos de un reintento, esto es el éxito
            // de la primera vez, que se perdió en el camino: la ficha existe y
            // es la que queríamos. Se toma como buena.
            if (e instanceof ErrorApi && e.status === 409 && e.datos?.cliente) {
                return { cliente: e.datos.cliente, contactos: e.datos.contactos ?? [] };
            }
            throw e;
        }
    }

    if (item.accion === 'cliente.editar') {
        return api.editarCliente(item.clave, item.datos);
    }

    throw new Error(`Operación desconocida: ${item.accion}`);
}

/** La respuesta del servidor manda: pisa la copia optimista del teléfono. */
async function aplicarRespuesta(item, r) {
    if (! r?.cliente) return;

    await idb.guardar('clientes', [r.cliente]);

    // Los contactos se reemplazan enteros porque así los guarda el servidor: en
    // Softland no tienen id, la clave es cliente + nombre, y un renombre deja el
    // viejo colgando si no se barre.
    const viejos = await idb.porIndice('contactos', 'cliente', item.clave);
    for (const c of viejos) {
        await idb.borrar('contactos', [c.cliente, c.nombre]);
    }
    await idb.guardar('contactos', r.contactos ?? []);
}
