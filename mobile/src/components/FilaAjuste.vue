<script setup>
import AppIcon from './AppIcon.vue';

/**
 * Fila de una lista de ajustes: icono, texto, y a la derecha el valor o la
 * señal de que lleva a alguna parte.
 *
 * Es el patrón de la pantalla de perfil de cualquier app del teléfono. Se usa
 * en vez de un formulario cuando lo que hay son opciones sueltas: un
 * formulario pide llenar, una lista de ajustes pide mirar.
 */
defineProps({
    icono: { type: String, required: true },
    rotulo: { type: String, required: true },
    /** Texto a la derecha, para lo que solo se informa (versión, base de datos). */
    valor: { type: [String, Number], default: null },
    /** Segunda línea, para explicar sin alargar el rótulo. */
    detalle: { type: String, default: null },
    /** Muestra la punta de flecha: la fila lleva a otra pantalla. */
    lleva: { type: Boolean, default: false },
    peligro: { type: Boolean, default: false },
    /** El control va debajo, a lo ancho. Para los que no caben al costado. */
    apilada: { type: Boolean, default: false },
});
</script>

<template>
    <component :is="lleva || $attrs.onClick ? 'button' : 'div'"
               class="fila-ajuste" :class="{ peligro, apilada }">
        <AppIcon :name="icono" :caja="36" :size="18" :variant="peligro ? 'peligro' : null" />
        <span class="texto">
            <span class="rotulo">{{ rotulo }}</span>
            <span class="detalle" v-if="detalle">{{ detalle }}</span>
        </span>
        <slot name="control">
            <span class="valor" v-if="valor !== null">{{ valor }}</span>
            <AppIcon v-if="lleva" name="avanzar" :size="18" color="var(--texto-suave)" />
        </slot>
    </component>
</template>
