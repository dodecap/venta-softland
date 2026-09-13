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
