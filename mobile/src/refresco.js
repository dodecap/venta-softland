import { computed, onBeforeUnmount, ref, watch } from 'vue';

/*
 * Tirar de la lista hacia abajo para actualizarla — el gesto que todo el mundo
 * ya conoce del navegador y del correo.
 *
 * ## Por qué existe
 *
 * Softland no tiene cómo avisar de que un documento cambió. Lo comprobamos
 * columna por columna: `nwcotiza` sólo guarda `FechaHoraCreacion`, que no se
 * mueve cuando la cotización pasa a vendida o a perdida, y el `FechaUlMod` de
 * `nw_nventa` está lleno en 15 de 800 filas —las que escribió esta app— porque
 * el Softland de escritorio no lo toca. O sea que no hay forma de preguntar
 * «¿qué cambió?»: hay que volver a bajar el maestro.
 *
 * Lo que sí se puede es bajar **poco**. Las cotizaciones con su detalle son
 * 1.096 filas de las 14.184 del teléfono, y las notas de venta 227. Refrescar
 * la lista que se está mirando cuesta menos de un décimo de una sincronización,
 * así que se puede hacer a cada rato sin pensarlo. Eso es lo que convierte «me
 * cambiaron el documento en la oficina» en un gesto y no en una descarga
 * completa antes de cada reunión.
 *
 * ## El gesto
 *
 * Sólo arranca con la lista arriba del todo: si el vendedor está a mitad de la
 * lista y arrastra hacia abajo, está desplazando, no refrescando. El recorrido
 * del dedo se divide a la mitad, que es lo que hace que el gesto se sienta
 * elástico y no un salto; pasado el umbral, el indicador dice que ya puede
 * soltar.
 */

/** Cuánto hay que tirar para que cuente. Menos de esto es hojear la lista. */
export const UMBRAL = 68;

/** Tope del recorrido: más abajo el indicador ya no dice nada nuevo. */
const TOPE = 104;

/** El dedo recorre el doble que el indicador. De ahí sale la sensación elástica. */
const RESISTENCIA = 0.5;

/**
 * @param {import('vue').Ref<HTMLElement|null>} contenedor  el que se desplaza
 * @param {Function} alRefrescar  lo que hay que hacer; puede ser asíncrono
 * @param {import('vue').Ref<boolean>} [activo]  para apagarlo (sin señal, por ejemplo)
 */
export function useTirarParaRefrescar(contenedor, alRefrescar, activo = null) {
    const distancia = ref(0);
    const refrescando = ref(false);
    const listo = computed(() => distancia.value >= UMBRAL);

    let desde = null;
    let desdeX = null;

    const habilitado = () => (activo ? activo.value : true);

    function empezar(e) {
        desde = null;

        if (! habilitado() || refrescando.value || e.touches.length !== 1) return;
        if ((contenedor.value?.scrollTop ?? 1) > 0) return;

        desde = e.touches[0].clientY;
        desdeX = e.touches[0].clientX;
    }

    function mover(e) {
        if (desde === null || refrescando.value) return;

        const avance = e.touches[0].clientY - desde;
        const lado = Math.abs(e.touches[0].clientX - desdeX);

        // Hacia arriba, si la lista ya se movió, o si el dedo va claramente de
        // lado —eso es deslizar entre pestañas, y lo atiende `deslizar.js`—,
        // esto no era tirar para refrescar y el gesto se cancela sin rastro.
        if (avance <= 0 || lado > Math.abs(avance) || (contenedor.value?.scrollTop ?? 0) > 0) {
            desde = null;
            distancia.value = 0;

            return;
        }

        distancia.value = Math.min(TOPE, avance * RESISTENCIA);

        // Se le quita el rebote nativo al navegador sólo cuando ya se está
        // tirando de verdad: cancelar antes haría que un desplazamiento normal
        // se sintiera pegajoso.
        if (distancia.value > 4 && e.cancelable) e.preventDefault();
    }

    async function soltar() {
        if (desde === null) return;

        desde = null;

        if (! listo.value) {
            distancia.value = 0;

            return;
        }

        // El indicador se queda a la altura del umbral mientras trabaja: si se
        // fuera a cero, el gesto parecería no haber hecho nada.
        distancia.value = UMBRAL;
        refrescando.value = true;

        try {
            await alRefrescar();
        } finally {
            refrescando.value = false;
            distancia.value = 0;
        }
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
    }

    // El contenedor llega después del montaje y puede cambiar si la pantalla se
    // reusa entre rutas hermanas — cotizaciones y notas de venta son la misma.
    watch(contenedor, (el, anterior) => {
        if (anterior) soltarlo(anterior);
        if (el) enganchar(el);
    }, { immediate: true });

    onBeforeUnmount(() => {
        if (contenedor.value) soltarlo(contenedor.value);
    });

    return { distancia, refrescando, listo, umbral: UMBRAL };
}
