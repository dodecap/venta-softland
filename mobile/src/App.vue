<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { App as AppNativa } from '@capacitor/app';
import { cerrarCapaSuperior } from './nav';
import { conectado } from './red';
import { contarPendientes, enviarPendientes, porEnviar } from './pendientes';
import AppIcon from './components/AppIcon.vue';
import BarraInferior from './components/BarraInferior.vue';
import BotonCrear from './components/BotonCrear.vue';

const router = useRouter();
const route = useRoute();
const avisoSalida = ref(false);

/**
 * Pantallas sin nada detrás. Retroceder desde aquí no lleva a ningún lado,
 * así que el gesto se interpreta como salir de la app.
 *
 * Son las cuatro pestañas (`meta.tab`) y las dos de entrada. Las pestañas se
 * navegan con `replace`, así que entre ellas no hay historial que desandar:
 * desde cualquiera, «atrás» significa salir.
 */
const RAICES = ['/login', '/servidor'];

const enRaiz = () => RAICES.includes(route.path) || !!route.meta.tab;

let salidaArmada = null;
const oyentes = [];

/**
 * Botón y gesto «atrás» de Android.
 *
 * Sin esto el gesto cierra la app de golpe, incluso a media edición. El orden
 * importa: primero se cierra lo que esté encima, después se retrocede, y solo
 * en la raíz se sale — pidiendo confirmación, porque en terreno cerrar la app
 * sin querer cuesta volver a entrar.
 */
function atras() {
    if (cerrarCapaSuperior()) return;

    if (!enRaiz()) {
        router.back();
        return;
    }

    if (salidaArmada) {
        clearTimeout(salidaArmada);
        AppNativa.exitApp();
        return;
    }

    avisoSalida.value = true;
    salidaArmada = setTimeout(() => {
        salidaArmada = null;
        avisoSalida.value = false;
    }, 2000);
}

/*
 * Cuando vuelve la señal, lo que se guardó sin ella sale solo.
 *
 * Va aquí y no en una pantalla porque la red puede volver en cualquier momento
 * y con cualquier cosa a la vista. Si dependiera de que el vendedor pase por
 * Cuenta, el cliente que dio de alta en la mañana podría quedarse una semana
 * en el teléfono.
 */
watch(conectado, (hayRed) => {
    if (hayRed && porEnviar.value) enviarPendientes();
});

onMounted(async () => {
    contarPendientes();
    try {
        oyentes.push(await AppNativa.addListener('backButton', atras));
    } catch {
        // Fuera de Android no hay botón físico que escuchar.
    }
});

onUnmounted(() => {
    if (salidaArmada) clearTimeout(salidaArmada);
    oyentes.forEach((o) => o.remove?.());
});
</script>

<template>
    <div class="sin-red" v-if="!conectado">
        <AppIcon name="sinRed" :size="15" color="currentColor" />
        Sin conexión — trabajando en el teléfono
    </div>
    <router-view />
    <BarraInferior />
    <BotonCrear />
    <div class="brindis" v-if="avisoSalida">Pulsa otra vez para salir</div>
</template>
