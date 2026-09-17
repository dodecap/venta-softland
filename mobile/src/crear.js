import { onUnmounted, ref, shallowRef } from 'vue';

/**
 * Acción de «crear» de la pantalla que está a la vista.
 *
 * El botón flotante es uno solo y vive en `App.vue`; las pantallas no lo
 * dibujan, solo dicen qué hace. Así el botón de cotizaciones, notas de venta
 * y facturación sale gratis: cada vista registra su acción y hereda el mismo
 * botón, la misma posición elegida por el vendedor y el mismo comportamiento.
 *
 * Si nadie registró nada, el botón no aparece.
 */
export const accionCrear = shallowRef(null);

/**
 * Los tres documentos, que se pueden crear desde cualquier pantalla.
 *
 * ## El problema que resuelven
 *
 * El botón flotante era **contextual**: en la lista de notas de venta creaba
 * una nota de venta, y punto. Para cotizar había que volver al panel, buscar en
 * las acciones rápidas, entrar a la lista y recién ahí tocar el botón. Cuatro
 * toques y un rodeo, todos los días.
 *
 * No era un problema de dónde estaba el botón: era que **sólo sabía hacer una
 * cosa**. Ahora el botón abre una hoja con las tres puertas, y la acción propia
 * de la pantalla —«Nuevo cliente» en Clientes— va arriba, que es donde la
 * espera quien entró ahí a eso.
 *
 * Van **directo al formulario**, no a la lista: quien toca «Cotizar» quiere
 * cotizar, no mirar cotizaciones. Para mirar están las pestañas.
 */
export const DOCUMENTOS = [
    { id: 'cotizacion', rotulo: 'Cotización', icono: 'cotizacion', ruta: '/cotizaciones/nuevo' },
    { id: 'nota_venta', rotulo: 'Nota de venta', icono: 'notaVenta', ruta: '/notas-venta/nuevo' },
    { id: 'factura', rotulo: 'Factura', icono: 'factura', ruta: '/facturas/nueva' },
];

/**
 * Declara la acción de crear de esta pantalla.
 *
 * @param {string} etiqueta   para lectores de pantalla («Nuevo usuario»)
 * @param {() => void} ejecutar
 */
export function useAccionCrear(etiqueta, ejecutar) {
    const mia = { etiqueta, ejecutar };
    accionCrear.value = mia;

    onUnmounted(() => {
        // Al navegar, Vue crea la vista nueva antes de destruir la vieja: si se
        // borrara a ciegas, la pantalla entrante se quedaría sin su botón.
        if (accionCrear.value === mia) accionCrear.value = null;
    });
}
