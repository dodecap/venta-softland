<script setup>
import { computed } from 'vue';
import { icono } from '../iconos';

/**
 * Único componente que dibuja iconos en la app.
 *
 * Recibe un **concepto** («cliente», «factura»), no un icono: el mapa de
 * `iconos.js` decide cuál es y de qué familia funcional. Ninguna pantalla
 * importa de `lucide-vue-next` por su cuenta.
 *
 *   <AppIcon name="cliente" caja />            icono en su recuadro suave
 *   <AppIcon name="atras" :size="22" />        icono suelto, hereda el color
 */
const props = defineProps({
    /** Concepto del mapa (`src/iconos.js`). */
    name: { type: String, required: true },
    /** Alto del trazo en px. 22-24 en listas y acciones, 18-20 en línea de texto. */
    size: { type: [Number, String], default: 22 },
    /** Familia funcional; por defecto la que le toca al concepto. */
    variant: { type: String, default: null },
    /** Color explícito del trazo. Manda sobre la variante. */
    color: { type: String, default: null },
    /**
     * Recuadro redondeado de fondo suave, al modo de las apps de banco.
     * `true` usa 48px; un número fija el lado.
     */
    caja: { type: [Boolean, Number], default: false },
    /** Fondo del recuadro. Por defecto, el suave de la variante. */
    background: { type: String, default: null },
    /** Grosor del trazo. 1.75 es la línea media de la app. */
    strokeWidth: { type: [Number, String], default: 1.75 },
});

const def = computed(() => icono(props.name));
const variante = computed(() => props.variant || def.value.variante || 'neutro');
const lado = computed(() => (props.caja === true ? 48 : Number(props.caja) || 0));

const estiloCaja = computed(() => ({
    width: `${lado.value}px`,
    height: `${lado.value}px`,
    borderRadius: `${Math.round(lado.value * 0.3)}px`,
    background: props.background || `var(--ic-${variante.value}-bg)`,
}));

const estiloGlifo = computed(() => ({
    width: `${props.size}px`,
    height: `${props.size}px`,
    color: props.color || (props.caja ? `var(--ic-${variante.value}-fg)` : undefined),
}));
</script>

<template>
    <span v-if="caja" class="ic-caja" :style="estiloCaja">
        <component :is="def.glifo" :size="Number(size)" :stroke-width="Number(strokeWidth)"
                   :style="estiloGlifo" aria-hidden="true" />
    </span>
    <component v-else :is="def.glifo" class="ic" :size="Number(size)"
               :stroke-width="Number(strokeWidth)" :style="estiloGlifo" aria-hidden="true" />
</template>
