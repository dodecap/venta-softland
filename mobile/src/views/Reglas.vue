<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';

const router = useRouter();
const eventos = ref([]);
const cargando = ref(true);
const error = ref('');
const aviso = ref('');
const abierto = ref(null);
const guardando = ref('');

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    try {
        eventos.value = (await api.notificaciones()).eventos;
    } catch (e) {
        error.value = e.message;
    } finally {
        cargando.value = false;
    }
}

async function guardar(ev) {
    error.value = '';
    aviso.value = '';
    guardando.value = ev.evento;
    try {
        await api.guardarNotificacion(ev.evento, {
            activa: ev.activa,
            avisar_dueno: ev.avisar_dueno,
            avisar_jefe: ev.avisar_jefe,
            avisar_cliente: ev.avisar_cliente,
            roles: ev.roles || null,
            copia_a: ev.copia_a || null,
        });
        aviso.value = `«${ev.label}» guardado.`;
    } catch (e) {
        error.value = e.message;
    } finally {
        guardando.value = '';
    }
}
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>Reglas de aviso</h1>
        </div>

        <div class="contenido">
            <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
            <Aviso tipo="ok" v-if="aviso">{{ aviso }}</Aviso>
            <Aviso tipo="info">
                Manda qué correo sale y a quién. Están listados todos los avisos
                del flujo completo. Los de fases que
                todavía no existen se pueden dejar configurados desde ya: empezarán a
                salir solos cuando esa parte entre en funcionamiento.
            </Aviso>

            <div class="cargando" v-if="cargando">Cargando…</div>

            <div class="tarjeta" v-for="ev in eventos" :key="ev.evento">
                <div class="tarjeta-cabecera"
                     style="display:flex;align-items:center;gap:8px;cursor:pointer;"
                     @click="abierto = abierto === ev.evento ? null : ev.evento">
                    <span style="flex:1;">{{ ev.label }}</span>
                    <span class="etiqueta gris" v-if="ev.fase > 1">fase {{ ev.fase }}</span>
                    <span class="etiqueta" :class="ev.activa ? 'verde' : 'roja'">
                        {{ ev.activa ? 'activo' : 'apagado' }}
                    </span>
                    <AppIcon :name="abierto === ev.evento ? 'desplegar' : 'avanzar'" :size="18"
                             color="var(--texto-suave)" />
                </div>

                <div class="tarjeta-cuerpo" v-if="abierto === ev.evento">
                    <p class="ayuda" style="margin-bottom:10px;">{{ ev.descripcion }}</p>

                    <div class="interruptor">
                        <span>Activo</span>
                        <input type="checkbox" v-model="ev.activa">
                    </div>
                    <div class="interruptor">
                        <span>Avisar al vendedor del documento</span>
                        <input type="checkbox" v-model="ev.avisar_dueno">
                    </div>
                    <div class="interruptor">
                        <span>Avisar a su jefe</span>
                        <input type="checkbox" v-model="ev.avisar_jefe">
                    </div>
                    <div class="interruptor">
                        <span>Avisar al cliente</span>
                        <input type="checkbox" v-model="ev.avisar_cliente">
                    </div>

                    <label>Avisar además a estos roles</label>
                    <input v-model="ev.roles" type="text" autocapitalize="off"
                           placeholder="facturacion, admin">
                    <p class="ayuda">Separados por coma. Va a todos los usuarios activos con ese rol.</p>

                    <label>Copia fija a</label>
                    <input v-model="ev.copia_a" type="text" autocapitalize="off" spellcheck="false"
                           placeholder="gerencia@empresa.cl, contabilidad@empresa.cl">

                    <button class="boton" :disabled="guardando === ev.evento" @click="guardar(ev)">
                        {{ guardando === ev.evento ? 'Guardando…' : 'Guardar' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
