<script setup>
import { computed, ref } from 'vue';
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
 *
 * ## Los decimales los dice la empresa, no este archivo
 *
 * `iwparam.CantDecimales` es un parámetro de Softland y viaja en el arranque.
 * Una empresa que vende licencias no quiere la coma en el teclado ni ver
 * «1,000»; una que vende cable por metro la necesita. Con `decimales: 0` el
 * teclado que abre Android es el de enteros y el paso es 1; con 2 o 3 aparece
 * el decimal. El tope es 3 porque es lo que admite `nw_detcot.CantidadPedida`,
 * y eso se acota en el servidor.
 */

const valor = defineModel({ type: Number, default: 0 });

const props = defineProps({
    /** Cuánto sube o baja cada toque. */
    paso: { type: Number, default: 1 },
    /** Decimales que admite la empresa. Lo dice `iwparam.CantDecimales`. */
    decimales: { type: Number, default: 3 },
    /** Piso. Nunca por debajo: una cantidad negativa no es un documento. */
    min: { type: Number, default: 0 },
    /** Techo, si lo hay. Facturar de más está permitido, así que aquí no va. */
    max: { type: Number, default: null },
    etiqueta: { type: String, default: 'Cantidad' },
});

/*
 * Se redondea porque sumar decimales en coma flotante da 2.9000000004, y eso
 * acabaría escrito en el documento.
 */
const factor = computed(() => 10 ** Math.max(0, Math.min(3, props.decimales)));
const limpiar = (n) => Math.round(n * factor.value) / factor.value;

/*
 * Con cero decimales no se ofrece la coma: `inputmode=numeric` abre el teclado
 * de dígitos pelados, que es más rápido y no deja escribir algo que el
 * documento va a redondear a espaldas de quien lo escribió.
 */
const tecladoDecimal = computed(() => props.decimales > 0);
const salto = computed(() => (props.decimales > 0 ? 'any' : '1'));

/*
 * Para el bucle de carga rápida: entrar en la cantidad con el número puesto y
 * **seleccionado**, para que teclear «40» reemplace el «1» en vez de dar «140».
 */
const campo = ref(null);

function enfocar() {
    campo.value?.focus();
    campo.value?.select();
}

defineExpose({ enfocar });

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
            <input ref="campo" v-model.number="valor" type="number"
                   :inputmode="tecladoDecimal ? 'decimal' : 'numeric'"
                   :min="min" :max="max ?? undefined" :step="salto">
            <button type="button" class="cantidad-boton" :disabled="enElTecho()"
                    :aria-label="`Agregar ${paso}`" @click.prevent="mover(1)">
                <AppIcon name="crear" :size="18" color="currentColor" />
            </button>
        </div>
    </label>
</template>
