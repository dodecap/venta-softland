<script setup>
import { ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';

/**
 * Campo de búsqueda de las listas grandes.
 *
 * Emite con retraso a propósito. Buscar en IndexedDB es rápido, pero dibujar
 * cincuenta fichas en cada letra no lo es, y quien escribe «rojas» genera cinco
 * búsquedas de las que solo importa la última.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Buscar' },
    /** Milisegundos de espera antes de avisar. 0 para avisar en cada tecla. */
    espera: { type: Number, default: 160 },
});

const emit = defineEmits(['update:modelValue']);

const texto = ref(props.modelValue);
let reloj = null;

watch(() => props.modelValue, (v) => { if (v !== texto.value) texto.value = v; });

watch(texto, (v) => {
    clearTimeout(reloj);
    reloj = setTimeout(() => emit('update:modelValue', v), props.espera);
});

function limpiar() {
    clearTimeout(reloj);
    texto.value = '';
    emit('update:modelValue', '');
}
</script>

<template>
    <div class="campo-buscar">
        <AppIcon name="buscar" :size="18" />
        <input v-model="texto" type="search" :placeholder="placeholder"
               autocapitalize="off" autocomplete="off" spellcheck="false">
        <!-- La X aparece solo cuando hay algo que borrar: un botón que no hace
             nada es peor que no tener botón. -->
        <button v-if="texto" class="limpiar" type="button" aria-label="Limpiar" @click="limpiar">
            <AppIcon name="cerrar" :size="16" color="currentColor" />
        </button>
    </div>
</template>
