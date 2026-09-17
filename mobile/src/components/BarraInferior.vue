<script setup>
import { computed, onUnmounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { hayCapa, ultimaLista } from '../nav';
import { tecladoAbierto } from '../teclado';
import AppIcon from './AppIcon.vue';

/**
 * Navegación principal, al pie y flotando.
 *
 * Cuatro destinos, y aquí se cierra la lista. Cuatro es el techo: con la activa
 * desplegada, en 360 px quedan 328 px de barra y las tres inactivas ocupan 132.
 * Una quinta no cabría sin achicar el área pulsable por debajo de los 44 px.
 *
 * ## Por qué son éstos cuatro
 *
 * La barra es **dónde estoy**, no quién soy ni qué hago. Avisos y Cuenta se
 * fueron arriba —a la campana y a las iniciales— porque se visitan una vez al
 * día y ocupaban la mitad del sitio más valioso de la pantalla. Crear tampoco
 * está aquí: es una acción y vive en el botón flotante.
 *
 * Y «Documentos» entró porque resolvía un rodeo diario: estando en las notas de
 * venta, ver las cotizaciones obligaba a volver al panel y buscar en las
 * acciones rápidas. Las tres listas son el mismo documento en tres momentos de
 * su vida, así que son hermanas y se cambian con las pestañas de arriba.
 *
 * Solo la activa muestra su nombre. Un icono suelto se adivina; con la etiqueta
 * al lado se lee. Mostrarlas todas obligaría a achicar la letra a un tamaño
 * que no se lee a pleno sol.
 */
const router = useRouter();
const route = useRoute();

const PESTANAS = [
    { ruta: '/inicio', icono: 'panel', rotulo: 'Panel' },
    { ruta: '/cotizaciones', icono: 'cotizacion', rotulo: 'Documentos', familia: true },
    { ruta: '/clientes', icono: 'cliente', rotulo: 'Clientes' },
    { ruta: '/cobranza', icono: 'cobranza', rotulo: 'Cobranza' },
];

/*
 * «Documentos» son tres rutas, no una: la pestaña queda encendida en las tres.
 * Sin esto, pasar de cotizaciones a facturas apagaría la pestaña y el vendedor
 * dejaría de saber dónde está parado.
 */
const FAMILIA = ['/cotizaciones', '/notas-venta', '/facturas'];

const activa = (p) => (p.familia ? FAMILIA.includes(route.path) : route.path === p.ruta);

const enPestana = computed(() => PESTANAS.some((p) => activa(p)));

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
    if (activa(p)) return;

    // Documentos abre por la lista que se estaba mirando la última vez: volver
    // siempre a cotizaciones obligaría a dos toques a quien vive en facturas.
    if (p.familia) {
        router.replace(ultimaLista.value || p.ruta);

        return;
    }

    // replace y no push: las pestañas son hermanas, no una encima de otra.
    // Con push, «atrás» recorrería el historial de saltos entre pestañas en vez
    // de salir de la app, que es lo que espera la mano.
    router.replace(p.ruta);
}
</script>

<template>
    <nav class="barra-inferior" :class="{ oculta: !visible }" aria-label="Navegación principal">
        <button v-for="p in PESTANAS" :key="p.ruta" class="pestana"
                :class="{ activa: activa(p) }"
                :aria-current="activa(p) ? 'page' : undefined"
                @click="ir(p)">
            <span class="glifo">
                <AppIcon :name="p.icono" :size="21" color="currentColor"
                         :stroke-width="activa(p) ? 2.25 : 1.75" />
            </span>
            <span class="rotulo">{{ p.rotulo }}</span>
        </button>
    </nav>
</template>
