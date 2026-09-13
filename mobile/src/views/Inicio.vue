<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { refrescarAvisos } from '../avisos';
import { cargarCatalogos } from '../catalogos';
import { px } from '../densidad';
import { conectado } from '../red';
import { contarRegistros, progreso, sincronizando, sincronizar, cancelarSincronizacion } from '../sync';
import { enviarPendientes, contarPendientes, porEnviar } from '../pendientes';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Vacio from '../components/Vacio.vue';

const router = useRouter();
const usuario = ref(null);
const sincronizado = ref(null);
const registros = ref(0);
const actividad = ref([]);
const aviso = ref('');
const error = ref('');

const esAdmin = computed(() => !!usuario.value?.es_admin);

/*
 * Accesos del flujo de ventas. Los de fase 2 ya llevan a alguna parte; los que
 * todavía no existen se muestran apagados y con la fase a la vista, para que el
 * vendedor entienda hacia dónde va la herramienta y nadie prometa un botón que
 * no hace nada.
 *
 * Lo administrativo NO está aquí: vive en Cuenta. El panel es del vendedor.
 */
const FLUJO = [
    { icono: 'cotizacion', rotulo: 'Cotizaciones', ruta: '/cotizaciones' },
    { icono: 'notaVenta', rotulo: 'Notas de venta', ruta: '/notas-venta' },
    { icono: 'cliente', rotulo: 'Clientes', ruta: '/clientes' },
    { icono: 'producto', rotulo: 'Productos', ruta: '/productos' },
    { icono: 'factura', rotulo: 'Facturar', fase: 'Fase 4' },
    { icono: 'cobranza', rotulo: 'Cobranza', fase: 'Fase 5' },
];

onMounted(async () => {
    usuario.value = await db.getUsuario();
    sincronizado.value = await db.getSincronizado();
    registros.value = await contarRegistros();
    await contarPendientes();
    // El punto rojo de la barra inferior sale de aquí: si se pidiera recién al
    // abrir el buzón, nunca habría aviso de que hay algo que mirar.
    refrescarAvisos();
    if (esAdmin.value) cargarActividad();
});

/** Últimos correos que salieron. Es la actividad del administrador. */
async function cargarActividad() {
    try {
        const r = await api.bitacora(5);
        actividad.value = r.notificaciones;
    } catch {
        // Sin red el panel sigue sirviendo: la actividad es un extra, no falla la pantalla.
    }
}

/**
 * Lo que hay que hacer antes de salir a terreno: bajar los maestros y mandar lo
 * que quedó pendiente. Va en ese orden porque si hay algo por enviar conviene
 * que llegue antes de volver a descargar, o la descarga lo pisa con el dato viejo.
 */
async function sincronizarTodo() {
    error.value = '';
    aviso.value = '';
    try {
        const salida = await enviarPendientes();
        const b = await api.bootstrap();
        await db.setUsuario(b.usuario);
        await db.setServidorInfo(b.servidor);
        usuario.value = b.usuario;

        const r = await sincronizar();
        await cargarCatalogos();
        registros.value = await contarRegistros();
        sincronizado.value = await db.getSincronizado();

        aviso.value = r.errores.length
            ? `Se actualizó casi todo, pero ${r.errores[0]}`
            : `Listo: ${registros.value.toLocaleString('es-CL')} registros en el teléfono.`
                + (salida.enviados ? ` Se enviaron ${salida.enviados} cambios.` : '');

        refrescarAvisos();
        if (esAdmin.value) cargarActividad();
    } catch (e) {
        error.value = e.message;
    }
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
    return hoy ? `Hoy ${hora}` : d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit' }) + ` ${hora}`;
});

/** Sin maestros la app no sirve en terreno, y eso hay que decirlo, no insinuarlo. */
const sinDatos = computed(() => registros.value === 0 && !sincronizando.value);

const pct = computed(() => {
    const p = progreso.value;
    if (!p || !p.recursos) return 0;
    const porRecurso = 100 / p.recursos;
    const dentro = p.total ? Math.min(p.hechas / p.total, 1) : 1;
    return Math.round((p.indice - 1) * porRecurso + dentro * porRecurso);
});

function fecha(n) {
    const v = n.enviada_at || n.created_at;
    if (!v) return '';
    return new Date(String(v).replace(' ', 'T'))
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
            <!-- Cerrar sesión ya no está aquí: se fue a Cuenta. Al lado de
                 «sincronizar» era un dedazo de distancia perder la sesión. -->
            <button class="icono-barra" :disabled="sincronizando" title="Sincronizar" @click="sincronizarTodo">
                <AppIcon name="sincronizar" :size="20" :class="{ girando: sincronizando }" />
            </button>
        </div>

        <div class="contenido panel">
            <Aviso tipo="error" v-if="error" style="margin-top:14px;">{{ error }}</Aviso>
            <Aviso tipo="ok" v-if="aviso" style="margin-top:14px;">{{ aviso }}</Aviso>

            <!-- Mientras baja, la barra dice qué maestro va y cuánto lleva: una
                 descarga completa son 12.000 filas y sin esto parece colgada. -->
            <div class="descarga" v-if="sincronizando">
                <div class="titulo">
                    <span>{{ progreso.titulo }}</span>
                    <button class="enlace" @click="cancelarSincronizacion">Cancelar</button>
                </div>
                <div class="progreso"><div class="relleno" :style="{ width: pct + '%' }"></div></div>
                <div class="detalle">
                    {{ progreso.hechas.toLocaleString('es-CL') }}
                    <template v-if="progreso.total">de {{ progreso.total.toLocaleString('es-CL') }}</template>
                    · maestro {{ progreso.indice }} de {{ progreso.recursos }}
                </div>
            </div>

            <div class="seccion">
                <h2>Panel de control</h2>
                <span class="sub">Estado de tus datos</span>
            </div>

            <div class="kpis">
                <div class="kpi" :class="conectado ? 'dinero' : 'aviso'">
                    <AppIcon :name="conectado ? 'alDia' : 'sinRed'" :caja="px(36)" :size="px(18)" />
                    <div class="dato corto">{{ conectado ? 'En línea' : 'Sin señal' }}</div>
                    <div class="rotulo">{{ conectado ? 'Todo se envía al momento' : 'Se guarda en el teléfono' }}</div>
                </div>
                <div class="kpi venta">
                    <AppIcon name="sincronizar" :caja="px(36)" :size="px(18)" variant="venta" />
                    <div class="dato corto">{{ cuando }}</div>
                    <div class="rotulo">Última sincronización</div>
                </div>
                <div class="kpi catalogo">
                    <AppIcon name="inventario" :caja="px(36)" :size="px(18)" />
                    <div class="dato">{{ registros.toLocaleString('es-CL') }}</div>
                    <div class="rotulo">Registros en el teléfono</div>
                </div>
                <div class="kpi aviso" v-if="porEnviar">
                    <AppIcon name="subir" :caja="px(36)" :size="px(18)" variant="aviso" />
                    <div class="dato">{{ porEnviar }}</div>
                    <div class="rotulo">Cambios por enviar</div>
                </div>
            </div>

            <Aviso tipo="info" v-if="sinDatos">
                Todavía no te has traído los datos. Toca el botón de sincronizar,
                arriba a la derecha, antes de salir a terreno.
            </Aviso>

            <div class="seccion">
                <h2>Acciones rápidas</h2>
            </div>

            <div class="rejilla">
                <button class="accion" v-for="a in FLUJO" :key="a.rotulo"
                        :disabled="!a.ruta" @click="a.ruta && router.push(a.ruta)">
                    <AppIcon :name="a.icono" :caja="px(48)" :size="px(22)" />
                    <span class="rotulo">{{ a.rotulo }}</span>
                    <span class="fase" v-if="a.fase">{{ a.fase }}</span>
                </button>
            </div>

            <template v-if="esAdmin">
                <div class="seccion">
                    <h2>Actividad reciente</h2>
                    <span class="sub">Correos enviados</span>
                    <button class="ver-todo" @click="router.push('/bitacora')">
                        Ver todo
                        <AppIcon name="avanzar" :size="15" color="currentColor" />
                    </button>
                </div>

                <div class="actividad">
                    <Vacio v-if="!actividad.length" icono="correo" titulo="Sin movimiento" />
                    <div class="fila" v-for="n in actividad" :key="n.id">
                        <AppIcon :name="n.estado === 'error' ? 'correoFallido' : (n.enviada_at ? 'correoEnviado' : 'correo')"
                                 :caja="px(36)" :size="px(18)" />
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
