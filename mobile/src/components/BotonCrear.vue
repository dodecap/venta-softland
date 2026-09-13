<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { accionCrear } from '../crear';
import { hayCapa } from '../nav';
import { tecladoAbierto } from '../teclado';
import { db } from '../db';
import AppIcon from './AppIcon.vue';

/**
 * Botón flotante de crear.
 *
 * Va abajo y al centro, no arriba a la derecha: el pulgar llega solo, sea la
 * mano que sea. Y si el vendedor prefiere tenerlo de un lado, lo deja
 * presionado, lo arrastra y ahí se queda — el teléfono se lo recuerda.
 */

const TAMANO = 56;
const MARGEN = 20;      // separación del borde lateral
const MOVER_MS = 450;   // presión larga antes de poder arrastrar
const TOLERANCIA = 10;  // px que se perdonan antes de dar el toque por perdido

const ANCLAS = ['izq', 'centro', 'der'];

const ancla = ref('centro');
const ancho = ref(anchoActual());
const arrastreX = ref(null); // px mientras se mueve; null = quieto en su ancla
const moviendo = ref(false);

const visible = computed(() => !!accionCrear.value && !hayCapa.value && !tecladoAbierto.value);
const etiqueta = computed(() => accionCrear.value?.etiqueta || 'Crear');

/* En el primer render la ventana puede no estar medida todavía (ancho 0) y el
   botón saldría pegado al borde izquierdo. Se vuelve a medir al montar. */
function anchoActual() {
    return document.documentElement.clientWidth || window.innerWidth || 360;
}

function xDe(a) {
    if (a === 'izq') return MARGEN;
    if (a === 'der') return ancho.value - MARGEN - TAMANO;
    return (ancho.value - TAMANO) / 2;
}

const x = computed(() => arrastreX.value ?? xDe(ancla.value));

function vibrar() {
    // Sin el golpecito no se nota que entró en modo mover y se suelta sin más.
    import('@capacitor/haptics')
        .then(({ Haptics, ImpactStyle }) => Haptics.impact({ style: ImpactStyle.Light }))
        .catch(() => {});
}

let presion = null;      // { px, x, temporizador }
let bloquearClic = false;

function abajo(e) {
    bloquearClic = false;
    // Sin captura, sacar el dedo del botón corta el arrastre a medio camino.
    try { e.currentTarget.setPointerCapture(e.pointerId); } catch { /* da igual */ }
    presion = { px: e.clientX, x: xDe(ancla.value) };
    presion.temporizador = setTimeout(() => {
        moviendo.value = true;
        bloquearClic = true;      // ya no es un toque: no se crea nada al soltar
        arrastreX.value = presion.x;
        vibrar();
    }, MOVER_MS);
}

function mover(e) {
    if (!presion) return;
    const d = e.clientX - presion.px;

    if (moviendo.value) {
        const tope = ancho.value - MARGEN - TAMANO;
        arrastreX.value = Math.min(Math.max(presion.x + d, MARGEN), tope);
        return;
    }

    // Se corrió el dedo antes de cumplirse la presión larga: ni toque ni
    // arrastre. Pasa al desplazar la lista rozando el botón.
    if (Math.abs(d) > TOLERANCIA) {
        bloquearClic = true;
        clearTimeout(presion.temporizador);
        presion = null;
    }
}

/** Se queda en el ancla más cercana; nunca a medio camino. */
function soltar() {
    const centro = arrastreX.value + TAMANO / 2;
    const distancia = (a) => Math.abs(xDe(a) + TAMANO / 2 - centro);
    const elegida = ANCLAS.reduce((mejor, a) => (distancia(a) < distancia(mejor) ? a : mejor), ANCLAS[0]);

    ancla.value = elegida;
    arrastreX.value = null;
    moviendo.value = false;
    vibrar();
    db.setPosBoton(elegida);
}

function arriba() {
    if (!presion) return;
    clearTimeout(presion.temporizador);
    presion = null;
    if (moviendo.value) soltar();
}

function clic() {
    if (bloquearClic) {
        bloquearClic = false;
        return;
    }
    accionCrear.value?.ejecutar();
}

const medir = () => { ancho.value = anchoActual(); };

onMounted(async () => {
    medir();
    ancla.value = await db.getPosBoton();
    window.addEventListener('resize', medir);
});

onUnmounted(() => window.removeEventListener('resize', medir));
</script>

<template>
    <!-- Mientras se mueve, se marca dónde se puede dejar. -->
    <div class="fab-anclas" v-if="moviendo">
        <span v-for="a in ANCLAS" :key="a" class="fab-ancla"
              :style="{ transform: `translateX(${xDe(a)}px)` }"></span>
    </div>

    <div class="fab-carro" :class="{ quieto: !moviendo }" :style="{ transform: `translateX(${x}px)` }">
        <button class="fab" :class="{ oculto: !visible, moviendo }"
                :aria-label="etiqueta" :title="etiqueta"
                @pointerdown="abajo" @pointermove="mover"
                @pointerup="arriba" @pointercancel="arriba"
                @contextmenu.prevent @click="clic">
            <AppIcon name="crear" :size="26" :stroke-width="2.25" color="currentColor" />
        </button>
    </div>
</template>
