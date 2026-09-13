<script setup>
import { onMounted, ref } from 'vue';
import { Network } from '@capacitor/network';

// La franja de "sin conexión" es global: en terreno es la primera pregunta
// que se hace el vendedor cuando algo no sube.
const conectado = ref(true);

onMounted(async () => {
    try {
        conectado.value = (await Network.getStatus()).connected;
        Network.addListener('networkStatusChange', (s) => {
            conectado.value = s.connected;
        });
    } catch {
        // En el navegador (npm run dev) el plugin no existe: se asume con red.
        conectado.value = true;
    }
});
</script>

<template>
    <div class="sin-red" v-if="!conectado">Sin conexión — trabajando en el teléfono</div>
    <router-view />
</template>
