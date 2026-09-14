<script setup>
import { ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';

/**
 * Campo de búsqueda de las listas grandes.
 *
 * Emite con retraso a propósito. Buscar en IndexedDB es rápido, pero dibujar
 * cincuenta fichas en cada letra no lo es, y quien escribe «rojas» genera cinco
 * búsquedas de las que solo importa la última.
 *
 * El `<input>` no lleva `v-model`: lee `value` y escribe a mano en `@input`.
 *
 * `v-model` en un elemento nativo ignora a propósito los eventos de tecla
 * mientras el navegador está «componiendo» — así evita capturar texto a
 * medias en un IME de chino o japonés (mira `target.composing` en el propio
 * runtime de Vue). El teclado predictivo de Android usa la misma composición
 * para el autocorrector **en cualquier idioma**: escribir «netdomain» de
 * corrido es una sola composición de principio a fin, y `v-model` no la
 * suelta hasta que termina —con un espacio, una puntuación, o el botón de
 * buscar del teclado, que es justo lo que parecía «haber que apretar»—. El
 * campo se veía escrito igual porque eso lo pinta el navegador, no Vue. La
 * búsqueda instantánea prefiere el texto a medio componer al texto exacto, así
 * que aquí conviene perder esa garantía: se lee `event.target.value` en cada
 * `input`, tal cual, sin mirar si está componiendo.
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
        <input :value="texto" @input="texto = $event.target.value"
               type="text" inputmode="search" enterkeyhint="search" :placeholder="placeholder"
               autocapitalize="off" autocomplete="off" spellcheck="false">
        <!-- La X aparece solo cuando hay algo que borrar: un botón que no hace
             nada es peor que no tener botón. -->
        <button v-if="texto" class="limpiar" type="button" aria-label="Limpiar" @click="limpiar">
            <AppIcon name="cerrar" :size="16" color="currentColor" />
        </button>
    </div>
</template>
