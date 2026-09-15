<script setup>
import { ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';

/**
 * Fila que se despliega para ver más, sin cambiar de pantalla ni ocupar el
 * espacio de siempre con algo que no siempre hace falta leer.
 *
 *   <Persiana>
 *       <template #cabecera>Conversión <b>31,4 %</b></template>
 *       El detalle, que no hace falta ver siempre.
 *   </Persiana>
 */
const props = defineProps({
    abierta: { type: Boolean, default: false },
});

const abierta = ref(props.abierta);
watch(() => props.abierta, (v) => { abierta.value = v; });
</script>

<template>
    <div class="persiana">
        <button type="button" class="persiana-cabecera" @click="abierta = ! abierta" :aria-expanded="abierta">
            <div class="persiana-titulo"><slot name="cabecera" /></div>
            <AppIcon name="desplegar" :size="18" color="var(--texto-suave)"
                     class="persiana-flecha" :class="{ abierta }" />
        </button>
        <div class="persiana-cuerpo" v-if="abierta"><slot /></div>
    </div>
</template>
