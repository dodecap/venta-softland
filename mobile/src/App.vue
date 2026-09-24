<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { App as AppNativa } from '@capacitor/app';
import { cerrarCapaSuperior } from './nav';
import { db } from './db';
import { conectado } from './red';
import { contarPendientes, enviarPendientes, porEnviar } from './pendientes';
import { sincronizar } from './sync';
import { refrescarAvisosSiToca } from './avisos';
import AppIcon from './components/AppIcon.vue';
import BarraInferior from './components/BarraInferior.vue';
import BotonCrear from './components/BotonCrear.vue';

const router = useRouter();
const route = useRoute();
const avisoSalida = ref(false);

/** El destino inicial de la barra: a donde vuelve «atrás» desde cualquier otra
 *  pestaña, y la única pantalla de la barra desde la que se sale de la app. */
const INICIO = '/inicio';

/**
 * Pantallas sin nada detrás. Retroceder desde aquí no lleva a ningún lado,
 * así que el gesto se interpreta como salir de la app.
 *
 * **El panel es el destino inicial**, y es el único de la barra que está aquí.
 * Las demás pestañas no: se llega a ellas con `replace` y detrás no queda
 * historial suyo que desandar, así que «atrás» desde una lista de documentos
 * cerraba la app de golpe. Vuelven al panel, que es de donde se viene.
 *
 * Avisos y Cuenta tampoco son raíz, y por otro motivo: se entra a ellas
 * apilándolas desde la cabecera, así que ahí sí hay historial y «atrás»
 * devuelve a donde se estaba. Cada una lleva su flecha, que hace lo mismo.
 */
const RAICES = ['/login', '/servidor', INICIO];

const enRaiz = () => RAICES.includes(route.path);

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
        // Una pestaña que no es el panel vuelve al panel, no al historial: se
        // llegó a ella con `replace`, así que detrás hay lo que hubiera antes
        // —a menudo nada—, y retroceder ahí cerraba la app. Es lo mismo que
        // hace la flecha de la cabecera de cada lista.
        if (route.meta.tab) router.replace(INICIO);
        else router.back();

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

    /*
     * El buzón va por su cuenta y con su propio plazo, que es más corto: traer
     * los avisos es una petición pequeña, y el globo rojo de la campana es
     * justo lo que se mira al volver a coger el teléfono. Colgarlo del plazo de
     * los maestros lo dejaría sin encender hasta cinco minutos después.
     */
    refrescarAvisosSiToca().catch(() => { /* el buzón es un extra */ });

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
