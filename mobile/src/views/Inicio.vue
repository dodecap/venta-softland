<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { refrescarAvisos } from '../avisos';
import { cargarCatalogos } from '../catalogos';
import { px } from '../densidad';
import { conectado } from '../red';
import { contarRegistros, progreso, sincronizando, sincronizar, cancelarSincronizacion } from '../sync';
import { enviarPendientes, contarPendientes, porEnviar } from '../pendientes';
import { dinero, porcentaje, variacion, SIN_DATO } from '../dinero';
import { PERIODOS, POR_DEFECTO, anterior, dia, rango } from '../panel/periodo';
import { panel } from '../panel/datos';
import { useCapa } from '../nav';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Vacio from '../components/Vacio.vue';

const router = useRouter();
const usuario = ref(null);
const sincronizado = ref(null);
const registros = ref(0);

/*
 * Cuántos días la empresa da por buena una cotización. Lo pone el
 * administrador en la identidad y es el mismo número que sale impreso en el
 * PDF; llega en el bootstrap porque no hay maestro donde ponerlo. Los 30 son
 * el mismo valor por defecto que usa el servidor.
 */
const vigencia = ref(30);
const actividad = ref([]);
const aviso = ref('');
const error = ref('');

const esAdmin = computed(() => !!usuario.value?.es_admin);

// Quien puede aprobar: el administrador y los supervisores. A un vendedor la
// consulta ni se le hace, que responde vacía y gasta una petición por arranque.
const esJefe = computed(() => ['admin', 'supervisor'].includes(usuario.value?.rol));

/*
 * Accesos del flujo de ventas. Los de fase 2 ya llevan a alguna parte; los que
 * todavía no existen se muestran apagados y con la fase a la vista, para que el
 * vendedor entienda hacia dónde va la herramienta y nadie prometa un botón que
 * no hace nada.
 *
 * Lo administrativo NO está aquí: vive en Cuenta. El panel es del vendedor.
 */
const porAprobar = ref(0);

const FLUJO = [
    { icono: 'cotizacion', rotulo: 'Cotizaciones', ruta: '/cotizaciones' },
    { icono: 'notaVenta', rotulo: 'Notas de venta', ruta: '/notas-venta' },
    { icono: 'cliente', rotulo: 'Clientes', ruta: '/clientes' },
    { icono: 'producto', rotulo: 'Productos', ruta: '/productos' },
    { icono: 'factura', rotulo: 'Facturar', fase: 'Fase 4' },
    { icono: 'cobranza', rotulo: 'Cobranza', fase: 'Fase 5' },
];

/* ------------------------------------------------------------------ ámbito
 *
 * Dos ámbitos como mucho, y sólo cuando hay diferencia entre ellos. Un
 * vendedor ve lo suyo y no hay nada que elegir; un jefe con código de vendedor
 * elige entre lo suyo y lo de todos; un administrador sin código de vendedor
 * sólo puede ver el total, porque «lo mío» para él está vacío.
 *
 * «Todos» es todo lo que el servidor dejó bajar a este teléfono, que ya viene
 * acotado por el alcance del usuario. El filtro de aquí acota dentro de eso;
 * no abre nada que el servidor no haya dado.
 */
const ambito = ref('yo');

const ambitos = computed(() => {
    const suyo = usuario.value?.ven_cod;
    const lista = [];

    if (suyo) lista.push({ id: 'yo', rotulo: 'Yo' });
    if (esJefe.value) lista.push({ id: 'todos', rotulo: esAdmin.value ? 'Empresa' : 'Equipo' });

    return lista;
});

const vendedores = computed(() => (ambito.value === 'yo' ? [String(usuario.value?.ven_cod || '')] : null));

const tituloVenta = computed(() => {
    if (ambito.value === 'yo') return 'Mi venta';

    return esAdmin.value ? 'Venta de la empresa' : 'Venta del equipo';
});

/* ----------------------------------------------------------------- período */
const periodo = ref(POR_DEFECTO);
const eligiendoPeriodo = ref(false);
useCapa(eligiendoPeriodo, () => { eligiendoPeriodo.value = false; });

const rangoActual = computed(() => rango(periodo.value));
const rangoPrevio = computed(() => anterior(rangoActual.value));

/* ------------------------------------------------------------- los números */
const m = ref(null);

/**
 * Se calcula en el teléfono, con lo que ya bajó la sincronización. Por eso no
 * hay estado «cargando» que tape el panel: son dos lecturas de IndexedDB sobre
 * un par de cientos de filas.
 */
async function calcularPanel() {
    m.value = await panel({
        rango: rangoActual.value,
        comparar: rangoPrevio.value,
        vendedores: vendedores.value,
        hoy: dia(new Date()),
        vigencia: vigencia.value,
    });
}

watch([periodo, ambito], calcularPanel);

const venta = computed(() => m.value?.actual.vendido ?? null);

const variacionVenta = computed(() => variacion(m.value?.actual.vendido.monto, m.value?.anterior?.vendido.monto));

const iconoTendencia = (v) => (v.direccion === 'sube' ? 'sube' : v.direccion === 'baja' ? 'baja' : 'sinCambio');

/**
 * Las tres etapas del embudo. La tercera está declarada y apagada a propósito:
 * las facturas todavía no se sincronizan al teléfono, y un embudo que termina
 * en «vendido» sin decir nada haría creer que ahí se acaba el negocio.
 */
const embudo = computed(() => {
    if (! m.value) return [];

    return [
        { id: 'cotizado', rotulo: 'Cotizado', ...m.value.actual.cotizado },
        { id: 'vendido', rotulo: 'Vendido', ...m.value.actual.vendido },
        { id: 'facturado', rotulo: 'Facturado', sinFuente: 'No sincronizado' },
    ];
});

/**
 * Requiere tu atención — lo que convierte el panel en una lista de trabajo.
 *
 * El orden no es por monto, es por lo que pasa si nadie lo toca:
 *
 *   1. Una venta detenida esperando la firma de una persona.
 *   2. Algo escrito en el teléfono que todavía no está en Softland.
 *   3. Una cotización que vence en días y todavía se puede salvar.
 *   4. Una que ya venció: es la de más plata y la más fría.
 *
 * Sólo aparece lo que existe. Una lista con cuatro ceros no es un panel de
 * trabajo, es un formulario en blanco.
 */
const atencion = computed(() => {
    const p = m.value?.pendientes;
    const lista = [];

    if (porAprobar.value) {
        lista.push({
            id: 'aprobar',
            icono: 'alerta',
            nivel: 'urgente',
            titulo: `${porAprobar.value} ${porAprobar.value === 1 ? 'nota de venta espera' : 'notas de venta esperan'} tu visto bueno`,
            sub: 'Pasaron el tope de descuento o de monto de su vendedor',
            ir: () => router.push('/aprobaciones'),
        });
    }

    if (porEnviar.value) {
        lista.push({
            id: 'enviar',
            icono: 'subir',
            nivel: 'urgente',
            titulo: `${porEnviar.value} ${porEnviar.value === 1 ? 'cambio' : 'cambios'} sin enviar`,
            sub: conectado.value
                ? (porEnviar.value === 1
                    ? 'Está en el teléfono y todavía no en Softland'
                    : 'Están en el teléfono y todavía no en Softland')
                : (porEnviar.value === 1
                    ? 'Sale solo cuando vuelva la señal'
                    : 'Salen solos cuando vuelva la señal'),
            ir: conectado.value ? sincronizarTodo : null,
        });
    }

    if (p?.por_vencer.n) {
        lista.push({
            id: 'por-vencer',
            icono: 'cotizacion',
            nivel: 'aviso',
            titulo: `${p.por_vencer.n} ${p.por_vencer.n === 1 ? 'cotización' : 'cotizaciones'} por vencer`,
            sub: `${dinero(p.por_vencer.monto)} · todavía se pueden cerrar`,
            ir: () => router.push('/cotizaciones?atencion=por_vencer'),
        });
    }

    if (p?.vencidas.n) {
        lista.push({
            id: 'vencidas',
            icono: 'cotizacion',
            nivel: 'frio',
            titulo: `${p.vencidas.n} ${p.vencidas.n === 1 ? 'cotización vencida' : 'cotizaciones vencidas'}`,
            sub: `${dinero(p.vencidas.monto)} · pasaron los ${vigencia.value} días de vigencia`,
            ir: () => router.push('/cotizaciones?atencion=vencida'),
        });
    }

    return lista;
});

onMounted(async () => {
    usuario.value = await db.getUsuario();
    sincronizado.value = await db.getSincronizado();
    vigencia.value = (await db.getServidorInfo())?.vigencia_cotizacion_dias || 30;
    registros.value = await contarRegistros();
    ambito.value = ambitos.value[0]?.id ?? 'todos';
    await calcularPanel();
    await contarPendientes();
    // El punto rojo de la barra inferior sale de aquí: si se pidiera recién al
    // abrir el buzón, nunca habría aviso de que hay algo que mirar.
    refrescarAvisos();
    if (esAdmin.value) cargarActividad();
    cargarAprobaciones();
});

/**
 * Lo que este usuario tiene que aprobar.
 *
 * No es una acción rápida más: las seis de abajo están calibradas para caber en
 * 360×640 sin desplazar, y una séptima rompería eso. Además esto no es un sitio
 * al que se va, es algo que hay que resolver, y por eso va arriba y solo cuando
 * hay algo que resolver.
 */
async function cargarAprobaciones() {
    if (! esJefe.value) return;
    try {
        porAprobar.value = (await api.aprobaciones()).aprobaciones.length;
    } catch { /* sin red el panel sigue sirviendo */ }
}

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
        vigencia.value = b.servidor?.vigencia_cotizacion_dias || 30;

        const r = await sincronizar();
        await cargarCatalogos();
        registros.value = await contarRegistros();
        sincronizado.value = await db.getSincronizado();
        await calcularPanel();

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

            <!-- Ámbito y período: lo primero, porque cambia todo lo de abajo. -->
            <div class="mandos">
                <div class="ambitos" v-if="ambitos.length > 1">
                    <button v-for="a in ambitos" :key="a.id"
                            :class="{ activo: ambito === a.id }" @click="ambito = a.id">{{ a.rotulo }}</button>
                </div>
                <!-- Con un solo ámbito no hay nada que elegir y tampoco nada
                     que rotular: el KPI de abajo ya dice de quién es la venta. -->

                <button class="elige-periodo" @click="eligiendoPeriodo = true">
                    <AppIcon name="periodo" :size="15" color="currentColor" />
                    {{ rangoActual.etiqueta }}
                    <AppIcon name="desplegar" :size="15" color="currentColor" />
                </button>
            </div>

            <Aviso tipo="info" v-if="sinDatos">
                Todavía no te has traído los datos. Toca el botón de sincronizar,
                arriba a la derecha, antes de salir a terreno.
            </Aviso>

            <template v-else-if="m">
                <!-- El KPI protagonista. Un solo número grande: lo que lleva
                     vendido en el período que está mirando. -->
                <div class="protagonista">
                    <div class="rotulo">{{ tituloVenta }}</div>
                    <div class="monto">{{ dinero(venta.monto) }}</div>
                    <div class="pie">
                        <span class="tendencia" :class="variacionVenta.direccion" v-if="variacionVenta">
                            <AppIcon :name="iconoTendencia(variacionVenta)" :size="14" color="currentColor" />
                            {{ variacionVenta.texto }}
                        </span>
                        <span class="contra" v-if="variacionVenta">vs. {{ rangoPrevio.etiqueta.toLowerCase() }}</span>
                        <span class="contra" v-else>sin período anterior con que comparar</span>
                    </div>
                    <div class="cuantos">
                        {{ venta.n }} {{ venta.n === 1 ? 'nota de venta' : 'notas de venta' }}
                    </div>
                </div>

                <div class="seccion">
                    <h2>Embudo comercial</h2>
                    <span class="sub">{{ rangoActual.etiqueta }}</span>
                </div>

                <div class="embudo">
                    <template v-for="(e, i) in embudo" :key="e.id">
                        <AppIcon v-if="i" name="avanzar" :size="14" color="var(--borde)" class="flecha" />
                        <div class="etapa" :class="{ apagada: e.sinFuente }">
                            <div class="rotulo">{{ e.rotulo }}</div>
                            <div class="monto">{{ e.sinFuente ? SIN_DATO : dinero(e.monto) }}</div>
                            <div class="cuantos">{{ e.sinFuente || e.n }}</div>
                        </div>
                    </template>
                </div>

                <div class="embudo-pie" v-if="m.actual.conversion">
                    Conversión <b>{{ porcentaje(m.actual.conversion.pct) }}</b> —
                    {{ m.actual.conversion.n }} de {{ m.actual.conversion.base }}
                    {{ m.actual.conversion.base === 1 ? 'cotización llegó' : 'cotizaciones llegaron' }}
                    a nota de venta.
                </div>
                <div class="embudo-pie" v-else>
                    Sin cotizaciones en el período: no hay conversión que medir.
                </div>
            </template>

            <!-- Va antes que las acciones porque pide una decisión, no una
                 consulta: cada línea es una venta esperando a esta persona. -->
            <template v-if="!sinDatos">
                <div class="seccion">
                    <h2>Requiere tu atención</h2>
                </div>

                <div class="atencion" v-if="atencion.length">
                    <button v-for="a in atencion" :key="a.id" class="fila-atencion" :class="a.nivel"
                            :disabled="!a.ir" @click="a.ir && a.ir()">
                        <AppIcon :name="a.icono" :caja="px(40)" :size="px(20)" />
                        <span class="texto">
                            <b>{{ a.titulo }}</b>
                            <small>{{ a.sub }}</small>
                        </span>
                        <AppIcon v-if="a.ir" name="avanzar" :size="18" color="var(--texto-suave)" />
                    </button>
                </div>

                <!-- Decir «no hay nada» también es informar: el vendedor cierra
                     el panel sabiendo que está al día, no dudando. -->
                <Vacio v-else icono="alDia" titulo="Nada pendiente">
                    Ninguna cotización por vencer y nada esperando salir del teléfono.
                </Vacio>
            </template>

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

            <!-- Lo técnico, que antes ocupaba tres KPI, reducido a una línea.
                 El detalle completo vive en Cuenta. -->
            <div class="pie-estado">
                <AppIcon :name="conectado ? 'alDia' : 'sinRed'" :size="13" color="currentColor" />
                Actualizado {{ cuando.toLowerCase() }}
                <template v-if="porEnviar"> · {{ porEnviar }} por enviar</template>
            </div>
        </div>

        <!-- Período -->
        <div class="velo" v-if="eligiendoPeriodo" @click.self="eligiendoPeriodo = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Período</h2>
                    <button class="icono-barra" @click="eligiendoPeriodo = false">
                        <AppIcon name="cerrar" :size="21" />
                    </button>
                </div>
                <div class="hoja-cuerpo">
                    <button class="opcion-periodo" v-for="p in PERIODOS" :key="p.id"
                            :class="{ activo: periodo === p.id }"
                            @click="periodo = p.id; eligiendoPeriodo = false">
                        <span>{{ p.rotulo }}</span>
                        <small>{{ rango(p.id).etiqueta }}</small>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
