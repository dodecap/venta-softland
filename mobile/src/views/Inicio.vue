<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { conectado } from '../red';
import AppIcon from '../components/AppIcon.vue';

const router = useRouter();
const usuario = ref(null);
const sincronizado = ref(null);
const sincronizando = ref(false);
const catalogos = ref({});
const actividad = ref([]);
const aviso = ref('');
const error = ref('');

const esAdmin = computed(() => !!usuario.value?.es_admin);

/*
 * Accesos del flujo de ventas. Se muestran desde ya, apagados y con la fase a
 * la vista: el vendedor entiende hacia dónde va la herramienta y nadie
 * promete un botón que todavía no hace nada.
 */
const FLUJO = [
    { icono: 'cotizacion', rotulo: 'Cotizaciones', fase: 'Fase 3' },
    { icono: 'notaVenta', rotulo: 'Notas de venta', fase: 'Fase 3' },
    { icono: 'cliente', rotulo: 'Clientes', fase: 'Fase 2' },
    { icono: 'producto', rotulo: 'Productos', fase: 'Fase 2' },
    { icono: 'factura', rotulo: 'Facturar', fase: 'Fase 4' },
    { icono: 'cobranza', rotulo: 'Cobranza', fase: 'Fase 5' },
];

const ADMIN = [
    { icono: 'usuario', rotulo: 'Usuarios', ruta: '/usuarios' },
    { icono: 'configuracion', rotulo: 'Configuración', ruta: '/configuracion' },
    { icono: 'notificacion', rotulo: 'Notificaciones', ruta: '/notificaciones' },
    { icono: 'correoEnviado', rotulo: 'Correos', ruta: '/bitacora' },
];

onMounted(async () => {
    usuario.value = await db.getUsuario();
    sincronizado.value = await db.getSincronizado();
    catalogos.value = await db.getCatalogos();
    if (esAdmin.value) cargarActividad();
});

/** Últimos correos que salieron. Es la única actividad real que hay en fase 1. */
async function cargarActividad() {
    try {
        const r = await api.bitacora(6);
        actividad.value = r.notificaciones;
    } catch {
        // Sin red el panel sigue sirviendo: la actividad es un extra, no falla la pantalla.
    }
}

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
        catalogos.value = b.catalogos;
        aviso.value = 'Datos actualizados.';
        if (esAdmin.value) cargarActividad();
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

/** Iniciales del vendedor para la placa del encabezado. */
const iniciales = computed(() => (usuario.value?.nombre || '')
    .split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || 'VS');

const cuando = computed(() => {
    if (!sincronizado.value) return 'Nunca';
    const d = new Date(sincronizado.value);
    const hoy = new Date().toDateString() === d.toDateString();
    // 24 horas: «12:18 p. m.» ocupa el doble y no dice nada más.
    const hora = d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });
    return hoy ? `Hoy ${hora}` : d.toLocaleString('es-CL', { day: '2-digit', month: '2-digit' }) + ` ${hora}`;
});

/** Registros de maestros guardados en el teléfono. Es lo que se lleva a terreno. */
const registros = computed(() => Object.values(catalogos.value || {})
    .reduce((n, lista) => n + (Array.isArray(lista) ? lista.length : 0), 0));

function fecha(n) {
    const v = n.enviada_at || n.created_at;
    if (!v) return '';
    return new Date(v.replace(' ', 'T'))
        .toLocaleString('es-CL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <div class="pantalla">
        <div class="encabezado">
            <div class="marca">{{ iniciales }}</div>
            <div class="saludo">
                <div class="hola">Hola, {{ (usuario?.nombre || '').split(' ')[0] }}</div>
                <div class="quien">
                    {{ usuario?.rol }}<span v-if="usuario?.ven_cod"> · vendedor {{ usuario.ven_cod }}</span>
                </div>
            </div>
            <button class="icono-barra" :disabled="sincronizando" title="Sincronizar" @click="sincronizar">
                <AppIcon name="sincronizar" :size="21" :class="{ girando: sincronizando }" />
            </button>
            <button class="icono-barra" title="Cerrar sesión" @click="salir">
                <AppIcon name="salir" :size="21" />
            </button>
        </div>

        <div class="contenido" style="padding:0 16px 16px;">
            <div class="aviso error" v-if="error" style="margin-top:14px;">{{ error }}</div>
            <div class="aviso ok" v-if="aviso" style="margin-top:14px;">{{ aviso }}</div>

            <div class="seccion">
                <h2>Panel de control</h2>
                <span class="sub">Estado de tus datos</span>
            </div>

            <div class="kpis">
                <div class="kpi" :class="conectado ? 'dinero' : 'aviso'">
                    <AppIcon :name="conectado ? 'alDia' : 'sinRed'" :caja="38" :size="19" />
                    <div class="dato corto">{{ conectado ? 'En línea' : 'Sin señal' }}</div>
                    <div class="rotulo">{{ conectado ? 'Todo se envía al momento' : 'Se guarda en el teléfono' }}</div>
                </div>
                <div class="kpi venta">
                    <AppIcon name="sincronizar" :caja="38" :size="19" variant="venta" />
                    <div class="dato corto">{{ cuando }}</div>
                    <div class="rotulo">Última sincronización</div>
                </div>
                <div class="kpi catalogo">
                    <AppIcon name="inventario" :caja="38" :size="19" />
                    <div class="dato">{{ registros.toLocaleString('es-CL') }}</div>
                    <div class="rotulo">Registros en el teléfono</div>
                </div>
            </div>

            <div class="seccion">
                <h2>Acciones rápidas</h2>
            </div>

            <div class="rejilla">
                <button class="accion" v-for="a in FLUJO" :key="a.rotulo" disabled>
                    <AppIcon :name="a.icono" :caja="52" :size="24" />
                    <span class="rotulo">{{ a.rotulo }}</span>
                    <span class="fase">{{ a.fase }}</span>
                </button>
            </div>

            <template v-if="esAdmin">
                <div class="seccion">
                    <h2>Administración</h2>
                </div>

                <div class="rejilla compacta">
                    <button class="accion" v-for="a in ADMIN" :key="a.ruta" @click="router.push(a.ruta)">
                        <AppIcon :name="a.icono" :caja="48" :size="22" />
                        <span class="rotulo">{{ a.rotulo }}</span>
                    </button>
                </div>

                <div class="seccion">
                    <h2>Actividad reciente</h2>
                    <span class="sub">Correos enviados</span>
                </div>

                <div class="actividad">
                    <div class="vacia" v-if="!actividad.length">Todavía no ha salido ningún correo.</div>
                    <div class="fila" v-for="n in actividad" :key="n.id">
                        <AppIcon :name="n.enviada_at ? 'correoEnviado' : 'alerta'" :caja="38" :size="19" />
                        <div class="texto">
                            <div class="titulo">{{ n.asunto }}</div>
                            <div class="sub">{{ fecha(n) }} · {{ n.evento }}</div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
