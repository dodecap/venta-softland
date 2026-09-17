<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { App as AppNativa } from '@capacitor/app';
import { cerrarCapaSuperior } from './nav';
import { db } from './db';
import { conectado } from './red';
import { contarPendientes, enviarPendientes, porEnviar } from './pendientes';
import { sincronizar } from './sync';
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
 * Son las de la barra (`meta.tab`) y las dos de entrada. Ahí entran también
 * las tres listas de documentos, que son un destino de la barra con tres
 * caras. Todas se navegan con `replace`, así que entre ellas no hay historial
 * que desandar: desde cualquiera, «atrás» significa salir.
 *
 * Avisos y Cuenta se fueron de la barra a la cabecera, y por eso dejaron de
 * ser raíz: se entra a ellas apilándolas, y «atrás» devuelve a donde se
 * estaba. Cada una lleva su flecha, que hace lo mismo.
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

/*
 * Al volver del segundo plano, se pone al día sola.
 *
 * Es lo que pidió resolver esto: el vendedor deja el teléfono una hora, en el
 * escritorio se crean cotizaciones, y al volver la app seguía mostrando lo de
 * antes porque nada la avisaba. No hace falta abrir un canal push para
 * arreglarlo — basta con que, cada vez que Android trae la app de vuelta a
 * primer plano, se dispare la misma sincronización incremental de siempre.
 * El aviso lo pone `sync.js` (`corridas`): el panel se repinta solo en cuanto
 * termina, sin que el vendedor haga nada.
 *
 * El plazo evita machacar a cada rato a quien cambia de app cada diez
 * segundos — y de paso evita pisarse con la que ya arrancó el login.
 */
const REANUDAR_TRAS = 5 * 60 * 1000;

async function alReanudar() {
    if (! conectado.value) return;
    if (! (await db.getToken())) return;

    const ultima = await db.getSincronizado();
    if (ultima && Date.now() - new Date(ultima).getTime() < REANUDAR_TRAS) return;

    sincronizar().catch(() => { /* sin señal se reintenta la próxima vez */ });
}

onMounted(async () => {
    contarPendientes();
    try {
        oyentes.push(await AppNativa.addListener('backButton', atras));
        oyentes.push(await AppNativa.addListener('appStateChange', ({ isActive }) => {
            if (isActive) alReanudar();
        }));
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
