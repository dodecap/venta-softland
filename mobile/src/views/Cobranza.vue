<script setup>
import { useRouter } from 'vue-router';
import BarraSuperior from '../components/BarraSuperior.vue';
import Vacio from '../components/Vacio.vue';

/**
 * Cobranza — todavía no construida.
 *
 * La pantalla existe a propósito en vez de dejar el botón apagado. Un botón de
 * la barra que no responde se lee como una app rota; uno que explica qué va a
 * vivir ahí se lee como una app que sabe a dónde va. Y de paso guarda el sitio:
 * cuando llegue la fase 5 no hay que rehacer la navegación ni que la gente
 * reaprenda dónde está.
 */
const router = useRouter();
</script>

<template>
    <div class="pantalla">
        <BarraSuperior titulo="Cobranza" />

        <div class="contenido">
            <Vacio icono="cobranza" titulo="Cobranza llega en la fase 5">
                Aquí van a vivir los documentos por cobrar, sus vencimientos y el estado de
                cuenta de cada cliente — lo que hoy se mira en el Softland de escritorio.
            </Vacio>

            <p class="ayuda centrado">
                Mientras tanto, lo emitido y su estado ante el SII está en Facturas.
            </p>

            <button class="boton" @click="router.replace('/facturas')">Ver las facturas</button>
        </div>
    </div>
</template>
