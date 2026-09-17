<script setup>
import { useRouter } from 'vue-router';
import AppIcon from './AppIcon.vue';

/**
 * Los documentos que cuelgan de éste, como enlaces de verdad.
 *
 * Hasta ahora las fichas **contaban** sus relaciones —«viene de la nota de
 * venta 812», «anulada con la NC 45»— y había que volver a la lista y buscar el
 * número a mano. Un documento nombrado y no abrible es un callejón.
 *
 * Quién cuelga de quién lo decide `relaciones.js`, no esta tarjeta: aquí sólo
 * se dibuja lo que llega. Así la cotización, la nota de venta y la factura
 * enseñan lo mismo de la misma forma, y mañana un documento nuevo entra sin
 * tocar ninguna pantalla.
 */

defineProps({
    /** Filas de `relaciones.js`: `{ clave, icono, rotulo, detalle, ruta, color }`. */
    filas: { type: Array, default: () => [] },
    titulo: { type: String, default: 'Relacionados' },
});

const router = useRouter();
</script>

<template>
    <template v-if="filas.length">
        <div class="seccion"><h2>{{ titulo }}</h2></div>
        <div class="item" v-for="f in filas" :key="f.clave" @click="router.push(f.ruta)">
            <div class="item-estado" :class="f.color"></div>
            <div class="item-cuerpo">
                <div class="item-titulo rel-titulo">
                    <AppIcon :name="f.icono" :size="16" color="currentColor" />
                    <span>{{ f.rotulo }}</span>
                </div>
                <div class="item-meta" v-if="f.detalle">{{ f.detalle }}</div>
            </div>
            <div class="rel-flecha"><AppIcon name="avanzar" :size="18" /></div>
        </div>
    </template>
</template>
