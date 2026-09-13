<script setup>
import AppIcon from './AppIcon.vue';

/**
 * Estado vacío. Nunca texto pelado: un icono grande y apagado deja claro de un
 * vistazo que no hay nada, en vez de parecer que la pantalla no cargó.
 */
defineProps({
    icono: { type: String, default: 'buzon' },
    titulo: { type: String, required: true },
});
</script>

<template>
    <div class="vacio">
        <AppIcon :name="icono" :size="40" :stroke-width="1.4" color="var(--texto-suave)" />
        <div class="titulo">{{ titulo }}</div>
        <p class="detalle" v-if="$slots.default"><slot /></p>
    </div>
</template>
