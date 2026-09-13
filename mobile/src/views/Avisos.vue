<script setup>
import { computed, onMounted, ref } from 'vue';
import { avisos, cargando, familias, marcarVistos, refrescarAvisos } from '../avisos';
import AppIcon from '../components/AppIcon.vue';
import Vacio from '../components/Vacio.vue';

/**
 * Buzón del vendedor: lo que le llegó a él, no todo lo que salió del servidor
 * (eso es la bitácora, y es de administración).
 *
 * Las pestañas de arriba las manda el servidor (`Eventos::familias()`): si
 * mañana se agrega un evento, cae solo en la pestaña que le toca sin cambiar
 * nada aquí.
 */
const filtro = ref('todo');

onMounted(async () => {
    await refrescarAvisos();
    // Se marcan al verlos, no al abrir cada uno: el buzón se mira entero de
    // una pasada y volver a ver el punto rojo al día siguiente es ruido.
    await marcarVistos();
});

const pestanas = computed(() => [
    { clave: 'todo', rotulo: 'Todo' },
    ...Object.entries(familias.value)
        // Solo las familias que tienen algo: cuatro pestañas vacías no informan.
        .filter(([k]) => avisos.value.some((a) => a.familia === k))
        .map(([clave, rotulo]) => ({ clave, rotulo })),
]);

const lista = computed(() => (filtro.value === 'todo'
    ? avisos.value
    : avisos.value.filter((a) => a.familia === filtro.value)));

function icono(a) {
    if (a.estado === 'error') return 'correoFallido';
    return a.enviada_at ? 'correoEnviado' : 'correo';
}

function cuando(a) {
    const v = a.enviada_at || a.created_at;
    if (!v) return '';
    const d = new Date(String(v).replace(' ', 'T'));
    const hoy = new Date().toDateString() === d.toDateString();
    const hora = d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });
    return hoy ? hora : d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit' }) + ' ' + hora;
}
</script>

<template>
    <div class="pantalla">
        <div class="encabezado simple">
            <h1>Avisos</h1>
            <button class="icono-barra" :disabled="cargando" title="Actualizar" @click="refrescarAvisos">
                <AppIcon name="sincronizar" :size="20" :class="{ girando: cargando }" />
            </button>
        </div>

        <div class="pestanas" v-if="pestanas.length > 1">
            <button v-for="p in pestanas" :key="p.clave"
                    :class="{ activa: filtro === p.clave }" @click="filtro = p.clave">
                {{ p.rotulo }}
            </button>
        </div>

        <div class="contenido">
            <div class="cargando" v-if="cargando && !avisos.length">Cargando…</div>

            <Vacio v-else-if="!lista.length" icono="sinNotificaciones" titulo="Sin avisos">
                Aquí van a aparecer los correos del flujo de ventas en los que
                figures: cotizaciones, notas de venta y documentos.
            </Vacio>

            <div class="actividad" v-else>
                <div class="fila" v-for="a in lista" :key="a.id">
                    <AppIcon :name="icono(a)" :caja="36" :size="18" />
                    <div class="texto">
                        <div class="titulo">{{ a.asunto }}</div>
                        <div class="sub">{{ a.label }}<span v-if="a.referencia"> · {{ a.referencia }}</span></div>
                        <div class="sub error" v-if="a.estado === 'error'">{{ a.error || 'No se pudo enviar.' }}</div>
                    </div>
                    <div class="cuando">{{ cuando(a) }}</div>
                </div>
            </div>
        </div>
    </div>
</template>
