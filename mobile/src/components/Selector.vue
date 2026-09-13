<script setup>
import { computed, ref } from 'vue';
import { opciones } from '../catalogos';
import Buscador from './Buscador.vue';

/**
 * Elegir un código de un maestro largo.
 *
 * Un `<select>` con las 2.009 opciones de giro no sirve: en Android abrirlo
 * tarda y desplazarlo hasta «CALZADOS Y CURTIEMBRES» es imposible. Aquí el
 * desplegable muestra solo las primeras 40 y encima va un campo para acotar.
 *
 * La opción ya elegida se cuela siempre en la lista aunque no calce con el
 * filtro: si desapareciera al escribir, el `select` se quedaría sin valor y el
 * vendedor perdería el dato que ya tenía sin haberlo tocado.
 *
 * Con maestros cortos (bodegas, monedas) el campo de filtrar no aparece: hay
 * menos opciones que el tope y estorbaría.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    /** Nombre del maestro en `catalogos.js`: giros, comunas, ciudades, cargos… */
    maestro: { type: String, required: true },
    /** Texto de la opción en blanco. Si es null, el campo es obligatorio. */
    vacio: { type: String, default: '— sin elegir —' },
    filtrar: { type: String, default: 'Filtrar' },
    tope: { type: Number, default: 40 },
});

const emit = defineEmits(['update:modelValue']);

const busqueda = ref('');

const todas = computed(() => opciones(props.maestro));
const conFiltro = computed(() => todas.value.length > props.tope);

const calzan = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    return q ? todas.value.filter((o) => (o.nombre || '').toLowerCase().includes(q)) : todas.value;
});

const lista = computed(() => {
    const vistas = calzan.value.slice(0, props.tope);
    const elegido = String(props.modelValue || '');
    if (elegido && ! vistas.some((o) => String(o.codigo) === elegido)) {
        const actual = todas.value.find((o) => String(o.codigo) === elegido);
        if (actual) vistas.unshift(actual);
    }
    return vistas;
});

const sobran = computed(() => calzan.value.length - props.tope);
</script>

<template>
    <Buscador v-if="conFiltro" v-model="busqueda" :placeholder="filtrar" :espera="0" />
    <select :value="modelValue" @change="emit('update:modelValue', $event.target.value)">
        <option v-if="vacio !== null" value="">{{ vacio }}</option>
        <option v-for="o in lista" :key="o.codigo" :value="o.codigo">{{ o.nombre }}</option>
    </select>
    <p class="ayuda" v-if="sobran > 0">
        Hay {{ sobran.toLocaleString('es-CL') }} más. Escribe arriba para acotar.
    </p>
</template>
