import { computed, ref } from 'vue';
import { api } from './api';
import { db } from './db';
import { avisaDeVersion, esMasNueva } from './version';

/**
 * Buzón de avisos, compartido entre la barra inferior (que muestra la cuenta)
 * y la pantalla (que muestra la lista). Si cada una pidiera lo suyo, el número
 * del punto rojo y lo que se ve al entrar no coincidirían.
 *
 * Lo leído es del aparato: el teléfono guarda hasta qué id llegó. Sin señal la
 * cuenta sigue siendo correcta, y abrir la pestaña no escribe en la base.
 */
export const avisos = ref([]);
export const familias = ref({});
export const noLeidos = ref(0);
export const cargando = ref(false);

/**
 * La versión publicada, cuando es **posterior** a la que corre este teléfono.
 *
 * No es una fila del servidor y no podría serlo: el servidor no sabe qué
 * versión tiene cada aparato, así que «hay una nueva» es una comparación que
 * sólo puede hacer el teléfono. Por eso entra en el buzón como un aviso
 * sintético y no como una notificación más.
 *
 * Distinta no es más nueva: un APK nuevo contra una API vieja también desfasa,
 * y ahí lo que falta es desplegar, no descargar. Esa distinción la hace
 * `esMasNueva` y la explica Cuenta.
 */
export const versionNueva = ref(null);

/** De cuál se avisó ya en este teléfono. Se lee de Preferences al refrescar. */
const versionAvisada = ref('');

/** ¿Hay una nueva de la que este teléfono todavía no avisó? */
export const versionSinAvisar = computed(
    () => avisaDeVersion(versionNueva.value, __VERSION__, versionAvisada.value)
);

let visto = 0;

function recontar() {
    noLeidos.value = avisos.value.filter((a) => a.id > visto).length
        + (versionSinAvisar.value ? 1 : 0);
}

/**
 * Trae el buzón. Sin red no rompe nada: se queda con lo último que tenía.
 *
 * De paso mira si hay versión nueva, que es la misma pregunta que contesta
 * Cuenta pero hecha aquí: quien no entra a Cuenta no se entera nunca, y el
 * sitio donde se mira si hay algo pendiente es la campana.
 */
export async function refrescarAvisos() {
    cargando.value = true;
    try {
        visto = await db.getAvisoVisto();
        versionAvisada.value = await db.getVersionAvisada();
        await mirarVersion();
        const r = await api.avisos();
        avisos.value = r.avisos;
        familias.value = r.familias;
    } catch {
        // El buzón es un extra: que falle no puede tumbar la pantalla que lo pide.
    } finally {
        recontar();
        cargando.value = false;
    }
}

/**
 * Qué versión reparte el servidor.
 *
 * Sin señal no se sabe, y no saber no es «no hay»: se deja en pie lo último
 * que se supo en vez de apagar el aviso a la primera pérdida de cobertura.
 */
async function mirarVersion() {
    try {
        const r = await api.ping();
        const publicada = r?.apk?.version || null;
        versionNueva.value = esMasNueva(publicada, __VERSION__) ? publicada : null;
    } catch {
        // Se queda como estaba.
    }
}

/** Se llama al mirar el buzón: lo que está a la vista deja de estar sin leer. */
export async function marcarVistos() {
    const tope = avisos.value.reduce((m, a) => Math.max(m, a.id), 0);

    if (tope > visto) {
        visto = tope;
        await db.setAvisoVisto(tope);
    }

    // La versión se apunta aparte: el punto rojo de la próxima tiene que
    // volver a encenderse, y el de ésta no.
    if (versionSinAvisar.value) {
        versionAvisada.value = versionNueva.value;
        await db.setVersionAvisada(versionNueva.value);
    }

    recontar();
}

/** Al cerrar sesión el buzón del anterior no puede quedar en pantalla. */
export function olvidarAvisos() {
    avisos.value = [];
    noLeidos.value = 0;
    visto = 0;
}
