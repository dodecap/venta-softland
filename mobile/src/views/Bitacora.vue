<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import AppIcon from '../components/AppIcon.vue';

const router = useRouter();
const notificaciones = ref([]);
const cargando = ref(true);
const error = ref('');

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    error.value = '';
    try {
        notificaciones.value = (await api.bitacora(100)).notificaciones;
    } catch (e) {
        error.value = e.message;
    } finally {
        cargando.value = false;
    }
}

function color(n) {
    return { enviada: 'verde', error: 'rojo', pendiente: 'amarillo' }[n.estado] || '';
}

function fecha(n) {
    const v = n.enviada_at || n.created_at;
    if (!v) return '';
    return new Date(v.replace(' ', 'T')).toLocaleString('es-CL', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>Correos enviados</h1>
            <button class="icono-barra" title="Actualizar" @click="cargar"><AppIcon name="sincronizar" :size="20" /></button>
        </div>

        <div class="contenido">
            <div class="aviso error" v-if="error">{{ error }}</div>
            <div class="cargando" v-if="cargando">Cargando…</div>
            <div class="vacio" v-else-if="!notificaciones.length">
                Todavía no ha salido ningún correo.
            </div>

            <div class="item" v-for="n in notificaciones" :key="n.id">
                <div class="item-estado" :class="color(n)"></div>
                <div class="item-cuerpo">
                    <div class="item-titulo">{{ n.asunto }}</div>
                    <div class="item-linea">{{ n.destinatarios }}</div>
                    <div class="item-meta">
                        {{ fecha(n) }} · {{ n.evento }}
                        <span v-if="n.referencia"> · {{ n.referencia }}</span>
                    </div>
                    <div class="aviso error" v-if="n.error" style="margin:8px 0 0;font-size:12px;">
                        {{ n.error }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
