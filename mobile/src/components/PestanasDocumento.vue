<script setup>
import { computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ultimaLista } from '../nav';

/**
 * Las tres listas del flujo de venta, como hermanas.
 *
 * ## Por qué existen
 *
 * Porque son **el mismo documento en tres momentos de su vida**, y hasta ahora
 * eran tres pantallas a las que sólo se llegaba desde el panel. Estando en las
 * notas de venta, mirar las cotizaciones costaba tres toques y un rodeo por las
 * acciones rápidas. Ahora cuesta uno.
 *
 * ## Y por qué no las pone el botón de crear
 *
 * Porque crear y listar son dos cosas distintas, y un botón que a veces crea y
 * a veces lista es un botón que se piensa antes de tocarlo. La pestaña **siempre
 * lista**; el «+» **siempre crea**. Ninguno de los dos cambia de significado
 * según dónde estés.
 *
 * Se cambia con `replace` y no con `push`: son hermanas, no una encima de otra.
 * Con `push`, «atrás» recorrería el zigzag entre listas en vez de salir.
 */

const route = useRoute();
const router = useRouter();

const LISTAS = [
    { ruta: '/cotizaciones', rotulo: 'Cotizaciones' },
    { ruta: '/notas-venta', rotulo: 'Notas de venta' },
    { ruta: '/facturas', rotulo: 'Facturas' },
];

const actual = computed(() => route.path);

/* La barra de abajo abre «Documentos» por la última que se miró. */
watch(actual, (r) => {
    if (LISTAS.some((l) => l.ruta === r)) ultimaLista.value = r;
}, { immediate: true });

function ir(ruta) {
    // Se cambia de lista limpia: el filtro que traía la anterior —«con algo por
    // facturar», «compromisos de hoy»— no significa lo mismo en la siguiente, y
    // arrastrarlo dejaría una lista filtrada por algo que nadie pidió.
    if (route.path !== ruta || Object.keys(route.query).length) router.replace(ruta);
}
</script>

<template>
    <div class="pestanas-doc" role="tablist">
        <button v-for="l in LISTAS" :key="l.ruta" role="tab"
                :class="{ activa: actual === l.ruta }"
                :aria-selected="actual === l.ruta"
                @click="ir(l.ruta)">{{ l.rotulo }}</button>
    </div>
</template>
