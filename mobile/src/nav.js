import { onUnmounted, ref, watch } from 'vue';

/**
 * Pila de capas abiertas — hojas de edición, diálogos, cualquier cosa que se
 * monte encima de la pantalla.
 *
 * El botón «atrás» de Android tiene que cerrar lo de encima antes de navegar.
 * Si una hoja de edición está abierta y retroceder cambia de pantalla, el
 * vendedor pierde lo que estaba escribiendo sin entender por qué: el gesto que
 * en todas las demás apps significa «cierra esto» aquí significaría «bótalo
 * todo». Por eso las vistas registran su capa y el retroceso las consume de a
 * una, de la más reciente a la más antigua.
 */
const pila = [];

/** Si hay algo abierto encima. Lo que flota al pie se esconde mientras tanto. */
export const hayCapa = ref(false);

/**
 * El orden de las tres listas de documentos, en un solo sitio.
 *
 * Lo leen las pestañas de arriba y el gesto de deslizar. Es el orden del ciclo
 * de venta —se cotiza, se vende, se factura— y por eso deslizar hacia la
 * izquierda avanza: el dedo va en el sentido del negocio.
 *
 * Está aquí y no en el componente de las pestañas porque el mismo orden escrito
 * en dos sitios es un gesto que un día lleva a otra pestaña que la que se ve
 * encendida.
 */
export const LISTAS_DOCUMENTO = [
    { ruta: '/cotizaciones', rotulo: 'Cotizaciones' },
    { ruta: '/notas-venta', rotulo: 'Notas de venta' },
    { ruta: '/facturas', rotulo: 'Facturas' },
];

/**
 * La última lista de documentos que se miró.
 *
 * La pestaña «Documentos» de la barra abre por ahí. Volver siempre a
 * cotizaciones le costaría dos toques a quien vive en facturas, y el vendedor
 * de terreno y el de facturación no viven en la misma lista.
 */
export const ultimaLista = ref('/cotizaciones');

function sincronizarBandera() {
    hayCapa.value = pila.length > 0;
}

/**
 * Registra una capa mientras `abierta` sea verdadera.
 *
 * @param {import('vue').Ref<boolean>} abierta  estado de apertura de la capa
 * @param {() => void} cerrar                   cómo cerrarla
 */
export function useCapa(abierta, cerrar) {
    let mia = null;

    const sincronizar = (v) => {
        if (v && !mia) {
            mia = cerrar;
            pila.push(mia);
        } else if (!v && mia) {
            const i = pila.indexOf(mia);
            // Puede no estar: si el retroceso ya la sacó de la pila, el cierre
            // llega de vuelta por el watch y no hay nada que quitar.
            if (i >= 0) pila.splice(i, 1);
            mia = null;
        }
        sincronizarBandera();
    };

    watch(abierta, sincronizar, { immediate: true });
    onUnmounted(() => sincronizar(false));
}

/** Cierra la capa de más arriba. Devuelve si había alguna. */
export function cerrarCapaSuperior() {
    const cerrar = pila.pop();
    sincronizarBandera();
    if (!cerrar) return false;

    cerrar();
    return true;
}
