<script setup>
import AppIcon from './AppIcon.vue';

/**
 * Un número que se sube y se baja con el pulgar.
 *
 * ## Por qué
 *
 * Porque la cantidad es el campo que más se toca de la app —en la cotización,
 * en la nota de venta y al facturar— y hasta ahora era un `<input type=number>`
 * pelado. En Android eso significa abrir el teclado numérico, borrar lo que
 * había y teclear, para pasar de 2 a 3. Las flechitas nativas del navegador no
 * salen en el teléfono, así que no había nada.
 *
 * ## Menos a la izquierda, más a la derecha, y no arriba y abajo
 *
 * Arriba y abajo obligaría a dos áreas pulsables de la mitad de alto, y los 44
 * px de área pulsable no se negocian: quedarían de 22. En una fila caben los
 * dos botones a tamaño completo con el número en medio, y además es el gesto
 * que ya se conoce de cualquier carrito de compras.
 *
 * ## Lo que no se toca
 *
 * El campo sigue siendo un `<input>` de verdad, tecleable: para pasar de 1 a 40
 * nadie va a dar 39 toques. Y mantiene sus 16 px, que es el piso por debajo del
 * cual Android hace zoom al enfocar.
 */

const valor = defineModel({ type: Number, default: 0 });

const props = defineProps({
    /** Cuánto sube o baja cada toque. */
    paso: { type: Number, default: 1 },
    /** Piso. Nunca por debajo: una cantidad negativa no es un documento. */
    min: { type: Number, default: 0 },
    /** Techo, si lo hay. Facturar de más está permitido, así que aquí no va. */
    max: { type: Number, default: null },
    etiqueta: { type: String, default: 'Cantidad' },
});

/*
 * Se redondea al paso porque sumar decimales en coma flotante da 2.9000000004,
 * y eso acabaría escrito en el documento. Tres decimales es lo que admite
 * `nw_detcot.CantidadPedida`.
 */
const limpiar = (n) => Math.round(n * 1000) / 1000;

function mover(signo) {
    const siguiente = limpiar((Number(valor.value) || 0) + signo * props.paso);

    if (siguiente < props.min) {
        valor.value = props.min;

        return;
    }

    valor.value = props.max !== null && siguiente > props.max ? props.max : siguiente;
}

const enElPiso = () => (Number(valor.value) || 0) <= props.min;
const enElTecho = () => props.max !== null && (Number(valor.value) || 0) >= props.max;
</script>

<template>
    <label class="cantidad">
        <span>{{ etiqueta }}</span>
        <div class="cantidad-fila">
            <button type="button" class="cantidad-boton" :disabled="enElPiso()"
                    :aria-label="`Quitar ${paso}`" @click.prevent="mover(-1)">
                <AppIcon name="quitar" :size="18" color="currentColor" />
            </button>
            <input v-model.number="valor" type="number" inputmode="decimal"
                   :min="min" :max="max ?? undefined" step="any">
            <button type="button" class="cantidad-boton" :disabled="enElTecho()"
                    :aria-label="`Agregar ${paso}`" @click.prevent="mover(1)">
                <AppIcon name="crear" :size="18" color="currentColor" />
            </button>
        </div>
    </label>
</template>
