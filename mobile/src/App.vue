<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { App as AppNativa } from '@capacitor/app';
import { cerrarCapaSuperior } from './nav';
import { conectado } from './red';
import AppIcon from './components/AppIcon.vue';
import BotonCrear from './components/BotonCrear.vue';

const router = useRouter();
const route = useRoute();
const avisoSalida = ref(false);

/**
 * Pantallas sin nada detrás. Retroceder desde aquí no lleva a ningún lado,
 * así que el gesto se interpreta como salir de la app.
 */
const RAICES = ['/inicio', '/login', '/servidor'];

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

    if (!RAICES.includes(route.path)) {
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

onMounted(async () => {
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
    <BotonCrear />
    <div class="brindis" v-if="avisoSalida">Pulsa otra vez para salir</div>
</template>
