<script setup>
import { computed } from 'vue';
import AppIcon from './AppIcon.vue';

/**
 * Franja de aviso — error, confirmación o explicación.
 *
 * Lleva icono porque el color solo no basta: en la calle, con sol y el brillo
 * al mínimo, un fondo rosado y uno verdoso se parecen bastante, y uno de cada
 * doce hombres no distingue bien esos dos. La forma del icono se lee igual
 * aunque el color no llegue.
 *
 *   <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
 */
const props = defineProps({
    /** error | ok | info */
    tipo: { type: String, default: 'info' },
});

// El nombre del tipo es también el del concepto en el mapa de iconos.
const nombre = computed(() => (['error', 'ok', 'info'].includes(props.tipo) ? props.tipo : 'info'));
</script>

<template>
    <div class="aviso" :class="nombre">
        <AppIcon :name="nombre" :size="17" color="currentColor" :stroke-width="2" />
        <div class="texto"><slot /></div>
    </div>
</template>
