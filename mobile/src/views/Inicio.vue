<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';

const router = useRouter();
const usuario = ref(null);
const sincronizado = ref(null);
const sincronizando = ref(false);
const aviso = ref('');
const error = ref('');

const esAdmin = computed(() => !!usuario.value?.es_admin);

onMounted(async () => {
    usuario.value = await db.getUsuario();
    sincronizado.value = await db.getSincronizado();
});

/** Refresca usuario y maestros. Es lo que hay que hacer antes de salir a terreno. */
async function sincronizar() {
    error.value = '';
    aviso.value = '';
    sincronizando.value = true;
    try {
        const b = await api.bootstrap();
        await db.setCatalogos(b.catalogos);
        await db.setUsuario(b.usuario);
        await db.setSincronizado(b.sincronizado_at);
        usuario.value = b.usuario;
        sincronizado.value = b.sincronizado_at;
        aviso.value = 'Datos actualizados.';
    } catch (e) {
        error.value = e.message;
    } finally {
        sincronizando.value = false;
    }
}

async function salir() {
    try {
        await api.logout();
    } catch { /* si no hay red, el token queda huérfano en el servidor y expira con el uso */ }
    await db.olvidarSesion();
    router.replace('/login');
}

const cuando = computed(() => {
    if (!sincronizado.value) return 'nunca';
    const d = new Date(sincronizado.value);
    return d.toLocaleString('es-CL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
});
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <h1>Venta Softland</h1>
            <button class="icono-barra" title="Salir" @click="salir">⏻</button>
        </div>

        <div class="contenido">
            <div class="aviso error" v-if="error">{{ error }}</div>
            <div class="aviso ok" v-if="aviso">{{ aviso }}</div>

            <div class="tarjeta">
                <div class="tarjeta-cuerpo" style="display:flex;align-items:center;gap:12px;">
                    <div style="flex:1;min-width:0;">
                        <div class="item-titulo">{{ usuario?.nombre }}</div>
                        <div class="item-meta">
                            <span class="etiqueta">{{ usuario?.rol }}</span>
                            <span v-if="usuario?.ven_cod"> · vendedor {{ usuario.ven_cod }}</span>
                        </div>
                        <div class="item-meta">Última actualización: {{ cuando }}</div>
                    </div>
                    <button class="icono-barra" style="color:var(--indigo);font-size:24px;"
                            :disabled="sincronizando" title="Actualizar datos" @click="sincronizar">
                        {{ sincronizando ? '…' : '⟳' }}
                    </button>
                </div>
            </div>

            <div class="mosaico">
                <button class="teja" disabled>
                    <span class="glifo">📄</span>
                    <span class="rotulo">Cotizaciones</span>
                    <span class="item-meta">Fase 2</span>
                </button>
                <button class="teja" disabled>
                    <span class="glifo">🧾</span>
                    <span class="rotulo">Notas de venta</span>
                    <span class="item-meta">Fase 3</span>
                </button>
                <button class="teja" disabled>
                    <span class="glifo">👥</span>
                    <span class="rotulo">Clientes</span>
                    <span class="item-meta">Fase 2</span>
                </button>
                <button class="teja" disabled>
                    <span class="glifo">📦</span>
                    <span class="rotulo">Productos</span>
                    <span class="item-meta">Fase 2</span>
                </button>
                <button class="teja ancha" disabled>
                    <span class="glifo">🧮</span>
                    <span class="rotulo">Facturar / Boletear</span>
                    <span class="item-meta">Fase 4</span>
                </button>
            </div>

            <template v-if="esAdmin">
                <div class="tarjeta-cabecera" style="background:none;padding:18px 2px 8px;">
                    Administración
                </div>
                <div class="mosaico">
                    <button class="teja" @click="router.push('/usuarios')">
                        <span class="glifo">🧑‍💼</span>
                        <span class="rotulo">Usuarios</span>
                    </button>
                    <button class="teja" @click="router.push('/configuracion')">
                        <span class="glifo">⚙️</span>
                        <span class="rotulo">Configuración</span>
                    </button>
                    <button class="teja" @click="router.push('/notificaciones')">
                        <span class="glifo">🔔</span>
                        <span class="rotulo">Notificaciones</span>
                    </button>
                    <button class="teja" @click="router.push('/bitacora')">
                        <span class="glifo">📬</span>
                        <span class="rotulo">Correos enviados</span>
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>
