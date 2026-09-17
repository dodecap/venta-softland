import { onBeforeUnmount, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { hayCapa, LISTAS_DOCUMENTO } from './nav';

/*
 * Deslizar la lista para cambiar de pestaña.
 *
 * ## Por qué
 *
 * Porque las tres listas ya son hermanas y se ven una al lado de la otra en la
 * tira de arriba, y en un teléfono lo que está al lado se alcanza con el dedo.
 * Tocar la pestaña sigue estando; esto es el atajo de quien va con el teléfono
 * en una mano.
 *
 * El sentido no es arbitrario: el orden lo pone `LISTAS_DOCUMENTO` y es el del
 * ciclo de venta —se cotiza, se vende, se factura—, así que deslizar hacia la
 * izquierda avanza, como pasar la página.
 *
 * ## Convivir con el otro gesto
 *
 * En la misma lista ya se tira hacia abajo para actualizar. Los dos miran el
 * mismo dedo, así que cada uno se retira en cuanto el otro eje manda: aquí no
 * se hace nada hasta que el recorrido horizontal le saca ventaja clara al
 * vertical, y `refresco.js` se cancela cuando pasa al revés. Sin ese acuerdo,
 * un deslizamiento con un poco de caída arrastraba la lista hacia abajo.
 *
 * ## Y qué se ve mientras
 *
 * La lista se corre con el dedo, a un tercio de su recorrido. Eso es lo que
 * dice que el gesto existe antes de soltarlo. En el borde —la primera lista
 * hacia atrás, la última hacia adelante— se corre mucho menos y vuelve sola:
 * es como se dice «no hay más» sin un cartel.
 */

/** Cuánto hay que recorrer para que cuente. Menos de esto es un roce. */
const UMBRAL = 64;

/** Desde dónde se decide que el gesto es horizontal y no un desplazamiento. */
const DECIDE = 12;

/** El dedo recorre el triple que la lista. De ahí la sensación de arrastre. */
const RESISTENCIA = 0.34;

/** Tope del recorrido. Más allá no dice nada nuevo. */
const TOPE = 96;

/** En el borde apenas cede: el gesto tiene que sentirse imposible, no lento. */
const RESISTENCIA_BORDE = 0.12;
const TOPE_BORDE = 34;

const sinAnimacion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/**
 * Engancha el gesto en el contenedor que se desplaza.
 *
 * @param {import('vue').Ref<HTMLElement|null>} contenedor  el `.contenido` de la lista
 * @param {import('vue').Ref<boolean>} [ocupado]  para apagarlo mientras se refresca
 */
export function useDeslizarPestanas(contenedor, ocupado = null) {
    const route = useRoute();
    const router = useRouter();

    let x0 = null;
    let y0 = null;
    let eje = null;      // null mientras no se decide; 'x' o 'y' después
    let corrido = 0;

    const indice = () => LISTAS_DOCUMENTO.findIndex((l) => l.ruta === route.path);

    function pintar(px, suave) {
        const el = contenedor.value;

        if (! el) return;

        el.style.transition = suave ? 'transform .16s ease-out' : '';
        el.style.transform = px ? `translateX(${px}px)` : '';
    }

    function empezar(e) {
        x0 = null;
        eje = null;
        corrido = 0;

        if (e.touches.length !== 1 || hayCapa.value || (ocupado && ocupado.value)) return;
        if (indice() < 0) return;

        // Una tira que ya se desplaza de lado —los filtros de la lista, la tira
        // de pestañas— se queda con el gesto: ahí el dedo está hojeando sus
        // propios botones, no cambiando de pestaña.
        if (e.target.closest?.('.pestanas, .pestanas-doc')) return;

        x0 = e.touches[0].clientX;
        y0 = e.touches[0].clientY;
    }

    function mover(e) {
        if (x0 === null) return;

        const dx = e.touches[0].clientX - x0;
        const dy = e.touches[0].clientY - y0;

        if (eje === null) {
            if (Math.abs(dx) < DECIDE && Math.abs(dy) < DECIDE) return;

            // Con ventaja clara, no por un píxel: en una lista larga el dedo
            // casi nunca baja recto, y sin margen el desplazamiento normal
            // acabaría cambiando de pestaña.
            eje = Math.abs(dx) > Math.abs(dy) * 1.4 ? 'x' : 'y';
        }

        if (eje !== 'x') return;

        const i = indice();
        const borde = (dx < 0 && i >= LISTAS_DOCUMENTO.length - 1) || (dx > 0 && i <= 0);

        corrido = borde
            ? Math.max(-TOPE_BORDE, Math.min(TOPE_BORDE, dx * RESISTENCIA_BORDE))
            : Math.max(-TOPE, Math.min(TOPE, dx * RESISTENCIA));

        // El navegador querría desplazar la página de lado o disparar su propio
        // «atrás» por gesto. Se le quita sólo cuando ya está claro que el
        // movimiento es nuestro.
        if (e.cancelable) e.preventDefault();

        pintar(corrido, false);
    }

    function soltar() {
        if (x0 === null) return;

        const movido = corrido;
        const horizontal = eje === 'x';

        x0 = null;
        eje = null;
        corrido = 0;

        if (! horizontal) return;

        const i = indice();
        const destino = movido < 0 ? i + 1 : i - 1;
        const cuenta = Math.abs(movido) >= UMBRAL * RESISTENCIA
            && destino >= 0 && destino < LISTAS_DOCUMENTO.length;

        if (! cuenta) {
            pintar(0, ! sinAnimacion());

            return;
        }

        // Se vuelve a cero de golpe y la lista nueva entra deslizándose desde
        // el lado del que vino: animar la salida retrasaría el cambio, y lo
        // que el vendedor quiere ver es la otra lista, no la que deja.
        pintar(0, false);
        anunciarEntrada(movido < 0 ? 'izquierda' : 'derecha');
        router.replace(LISTAS_DOCUMENTO[destino].ruta);
    }

    /*
     * La animación de entrada se marca en el elemento de la pantalla, no en el
     * contenedor: la lista de facturas es otro componente y el contenedor de
     * ésta ya no existe cuando entra la siguiente.
     */
    function anunciarEntrada(desde) {
        if (sinAnimacion()) return;

        const raiz = document.documentElement;

        raiz.dataset.entra = desde;
        setTimeout(() => { delete raiz.dataset.entra; }, 260);
    }

    function enganchar(el) {
        el.addEventListener('touchstart', empezar, { passive: true });
        el.addEventListener('touchmove', mover, { passive: false });
        el.addEventListener('touchend', soltar, { passive: true });
        el.addEventListener('touchcancel', soltar, { passive: true });
    }

    function soltarlo(el) {
        el.removeEventListener('touchstart', empezar);
        el.removeEventListener('touchmove', mover);
        el.removeEventListener('touchend', soltar);
        el.removeEventListener('touchcancel', soltar);
        el.style.transition = '';
        el.style.transform = '';
    }

    // El contenedor cambia cuando la pantalla se reusa entre rutas hermanas:
    // cotizaciones y notas de venta son el mismo componente.
    watch(contenedor, (el, anterior) => {
        if (anterior) soltarlo(anterior);
        if (el) enganchar(el);
    }, { immediate: true });

    onBeforeUnmount(() => {
        if (contenedor.value) soltarlo(contenedor.value);
    });
}
