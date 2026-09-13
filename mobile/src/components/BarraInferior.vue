<script setup>
import { computed, onUnmounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { hayCapa } from '../nav';
import { tecladoAbierto } from '../teclado';
import { noLeidos } from '../avisos';
import AppIcon from './AppIcon.vue';

/**
 * Navegación principal, al pie y flotando.
 *
 * Tres destinos y no más: el pulgar alcanza tres blancos sin mirar, y cada
 * pestaña que se agrega le quita ancho a las otras. Clientes entra como cuarta
 * con la fase 2; cotizaciones y productos NO son pestañas, son acciones del
 * panel — una pestaña es un lugar donde se vuelve, no una acción que se hace.
 *
 * Solo la activa muestra su nombre. Un icono suelto se adivina; con la etiqueta
 * al lado se lee. Mostrarlas las tres obligaría a achicar la letra a un tamaño
 * que no se lee a pleno sol.
 */
const router = useRouter();
const route = useRoute();

const PESTANAS = [
    { ruta: '/inicio', icono: 'panel', rotulo: 'Panel' },
    { ruta: '/avisos', icono: 'notificacion', rotulo: 'Avisos', contador: true },
    { ruta: '/cuenta', icono: 'cuenta', rotulo: 'Cuenta' },
];

const enPestana = computed(() => PESTANAS.some((p) => p.ruta === route.path));

// Con el teclado abierto la barra queda montada sobre las teclas, y con una
// hoja encima compite con el formulario. En los dos casos estorba.
const visible = computed(() => enPestana.value && !hayCapa.value && !tecladoAbierto.value);

/*
 * La barra le quita su sitio al pie de la pantalla. En vez de que cada cosa
 * que flota sepa de su existencia, se levanta `--pie-flotante` para todos: el
 * botón de crear y el aviso pasajero ya cuelgan de esa variable y suben solos.
 */
watch(visible, (v) => {
    document.documentElement.classList.toggle('con-barra', v);
}, { immediate: true });

onUnmounted(() => document.documentElement.classList.remove('con-barra'));

function ir(p) {
    if (route.path === p.ruta) return;
    // replace y no push: las pestañas son hermanas, no una encima de otra.
    // Con push, «atrás» recorrería el historial de saltos entre pestañas en vez
    // de salir de la app, que es lo que espera la mano.
    router.replace(p.ruta);
}
</script>

<template>
    <nav class="barra-inferior" :class="{ oculta: !visible }" aria-label="Navegación principal">
        <button v-for="p in PESTANAS" :key="p.ruta" class="pestana"
                :class="{ activa: route.path === p.ruta }"
                :aria-current="route.path === p.ruta ? 'page' : undefined"
                @click="ir(p)">
            <span class="glifo">
                <AppIcon :name="p.icono" :size="21" color="currentColor"
                         :stroke-width="route.path === p.ruta ? 2.25 : 1.75" />
                <span class="punto" v-if="p.contador && noLeidos">{{ noLeidos > 9 ? '9+' : noLeidos }}</span>
            </span>
            <span class="rotulo">{{ p.rotulo }}</span>
        </button>
    </nav>
</template>
