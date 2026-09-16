<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { refrescarAvisos } from '../avisos';
import { fecha as fechaCorta, monto } from '../catalogos';
import { px } from '../densidad';
import { conectado } from '../red';
import { contarRegistros, corridas, progreso, sincronizando, sincronizar, cancelarSincronizacion } from '../sync';
import { enviarPendientes, contarPendientes, porEnviar } from '../pendientes';
import { dias, diferenciaDias, dinero, porcentaje, puntos, variacion, SIN_DATO } from '../dinero';
import { PERIODOS, POR_DEFECTO, anterior, dia, rango } from '../panel/periodo';
import { panel } from '../panel/datos';
import { estado as estadoDoc, estadoSii } from '../documentos';
import { idb } from '../idb';
import { useCapa } from '../nav';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Persiana from '../components/Persiana.vue';
import Vacio from '../components/Vacio.vue';

const router = useRouter();
const usuario = ref(null);
const sincronizado = ref(null);
const registros = ref(0);

// Qué medida de rendimiento tiene su explicación desplegada. Una a la vez:
// las tres compiten por el mismo pedazo de pantalla.
const medidaAbierta = ref(null);

/*
 * Cuántos días la empresa da por buena una cotización. Lo pone el
 * administrador en la identidad y es el mismo número que sale impreso en el
 * PDF; llega en el bootstrap porque no hay maestro donde ponerlo. Los 30 son
 * el mismo valor por defecto que usa el servidor.
 */
const vigencia = ref(30);

/*
 * La actividad reciente. Antes eran los últimos correos que había mandado el
 * servidor, que es un dato de administrador y no de vendedor: contaba lo que
 * hacía la máquina, no lo que pasaba con las ventas. Ahora son los últimos
 * cinco documentos de cada tipo, salidos de IndexedDB, que es lo que el
 * vendedor reconoce como «lo que hice». La bitácora de correos no se perdió:
 * vive en Cuenta, donde ya estaba su sitio.
 */
const nombresCliente = ref({});
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

/* Cambia lo que significa una factura sin enviar: avería o tarea pendiente. */
const envioAutomatico = ref(true);

const FLUJO = [
    { icono: 'cotizacion', rotulo: 'Cotizaciones', ruta: '/cotizaciones' },
    { icono: 'notaVenta', rotulo: 'Notas de venta', ruta: '/notas-venta' },
    { icono: 'cliente', rotulo: 'Clientes', ruta: '/clientes' },
    { icono: 'producto', rotulo: 'Productos', ruta: '/productos' },
    // Facturar cuelga de una nota de venta, así que lleva a las que todavía
    // tienen algo por facturar: es la cola de trabajo, no un catálogo.
    { icono: 'factura', rotulo: 'Facturar', ruta: '/notas-venta?facturar=1' },
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

const tituloRendimiento = computed(() => {
    if (ambito.value === 'yo') return 'Mi rendimiento';

    return esAdmin.value ? 'Rendimiento de la empresa' : 'Rendimiento del equipo';
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
    await cargarNombresRecientes();
}

watch([periodo, ambito], ([p, a]) => {
    calcularPanel();
    // Se guarda en el aparato, no como parte del cálculo: cambiar de período
    // no espera a que termine de escribirse para repintar el panel.
    db.setPanelPeriodo(p);
    db.setPanelAmbito(a);
});

const venta = computed(() => m.value?.actual.vendido ?? null);

const variacionVenta = computed(() => variacion(m.value?.actual.vendido.monto, m.value?.anterior?.vendido.monto));

const iconoTendencia = (v) => (v.direccion === 'sube' ? 'sube' : v.direccion === 'baja' ? 'baja' : 'sinCambio');

/**
 * Las tres etapas del embudo.
 *
 * La tercera sigue apagada, y ya no por falta de datos —las facturas se
 * sincronizan desde la 0.20.0—: **aquí se factura por suscripción**. Una nota de
 * venta genera varias facturas a lo largo de meses, así que «facturado» dentro
 * de un período no es la continuación de «vendido» en ese mismo período, y
 * ponerlos uno al lado del otro invitaría a restarlos. Cuando se mida, se mide
 * de otra forma.
 */
const embudo = computed(() => {
    if (! m.value) return [];

    return [
        { id: 'cotizado', rotulo: 'Cotizado', ...m.value.actual.cotizado },
        { id: 'vendido', rotulo: 'Vendido', ...m.value.actual.vendido },
        { id: 'facturado', rotulo: 'Facturado', sinFuente: 'Se factura por suscripción' },
    ];
});

/* ------------------------------------------------------- mi rendimiento
 *
 * Las tres medidas de *cómo* se vende, frente al período anterior. No son el
 * cuánto —eso es el KPI de arriba—: son las que dicen si el mes fue bueno por
 * trabajar mejor o sólo por trabajar más.
 *
 * Cada una se cae sola cuando no hay con qué calcularla, y lo dice en su sitio
 * en vez de mostrar un cero. Un cero es un dato; la ausencia de dato no lo es,
 * y confundirlos en un panel comercial es cómo se toman decisiones sobre humo.
 */
const rendimiento = computed(() => {
    if (! m.value) return [];

    const a = m.value.actual;
    const b = m.value.anterior;

    return [
        {
            id: 'conversion',
            icono: 'conversion',
            rotulo: 'Conversión',
            valor: a.conversion ? porcentaje(a.conversion.pct) : SIN_DATO,
            sub: a.conversion
                ? `${a.conversion.n} de ${a.conversion.base} ${a.conversion.base === 1 ? 'cotización' : 'cotizaciones'} ${a.conversion.n === 1 ? 'llegó' : 'llegaron'} a nota de venta`
                : 'Sin cotizaciones en el período: no hay conversión que medir',
            cambio: puntos(a.conversion?.pct, b?.conversion?.pct),
        },
        {
            id: 'cierre',
            icono: 'tiempoCierre',
            rotulo: 'Tiempo de cierre',
            valor: dias(a.cierre, true),
            sub: a.cierre === null
                ? 'Ninguna nota del período tiene su cotización en el teléfono'
                : `Mediana de ${a.cierre_n} ${a.cierre_n === 1 ? 'nota' : 'notas'}, desde que se cotizó`,
            cambio: diferenciaDias(a.cierre, b?.cierre),
            // Cerrar antes es mejor. La flecha sigue apuntando hacia donde se
            // movió el número; lo que cambia es de qué color se pinta.
            menosEsMejor: true,
        },
        {
            id: 'ticket',
            icono: 'ticket',
            rotulo: 'Ticket promedio',
            valor: dinero(a.ticket),
            sub: a.vendido.n
                ? `${a.vendido.n} ${a.vendido.n === 1 ? 'nota de venta aprobada' : 'notas de venta aprobadas'} en el período`
                : 'Sin notas de venta aprobadas en el período',
            cambio: variacion(a.ticket, b?.ticket),
        },
    ];
});

/* -------------------------------------------------- actividad reciente */

const recientes = computed(() => {
    const r = m.value?.recientes;

    if (! r) return [];

    return [
        { id: 'cotizacion', tipo: 'cotizacion', titulo: 'Cotizaciones', ruta: '/cotizaciones', icono: 'cotizacion', filas: r.cotizaciones },
        { id: 'nota_venta', tipo: 'nota_venta', titulo: 'Notas de venta', ruta: '/notas-venta', icono: 'notaVenta', filas: r.notas },
        // La factura se numera por folio y se abre por tipo + número interno:
        // el folio es único dentro de su tipo y nada más, así que no sirve de
        // dirección. Y su estado es **el del SII**, que es la pregunta que se
        // hace uno mirando una factura recién emitida.
        { id: 'factura', tipo: 'factura', titulo: 'Facturas', ruta: '/facturas', icono: 'factura', filas: r.facturas ?? [] },
    ];
});

/** Cómo se llama y cómo se abre cada fila, que no es igual en los tres grupos. */
function filaReciente(grupo, d) {
    if (grupo.tipo !== 'factura') {
        return {
            clave: `${grupo.id}-${d.numero}`,
            numero: d.numero,
            ruta: `${grupo.ruta}/${d.numero}`,
            ...estadoDoc(grupo.tipo, d.estado),
        };
    }

    return {
        clave: `${grupo.id}-${d.tipo}-${d.numero_interno}`,
        numero: d.folio,
        ruta: `${grupo.ruta}/${d.tipo}/${d.numero_interno}`,
        ...estadoSii(d, envioAutomatico.value),
    };
}

/**
 * El nombre del cliente, que en el documento sólo está su código.
 *
 * Son diez búsquedas por clave primaria contra un almacén de 3.824 clientes:
 * el maestro completo en memoria costaría más que esto y haría falta una vez
 * por apertura del panel.
 */
async function cargarNombresRecientes() {
    const codigos = new Set(recientes.value.flatMap((g) => g.filas.map((d) => d.cliente)).filter(Boolean));
    const mapa = {};

    for (const c of codigos) mapa[c] = (await idb.obtener('clientes', c))?.nombre || c;

    nombresCliente.value = mapa;
}

/**
 * El color de una comparación dice si la noticia es buena; la flecha, hacia
 * dónde se movió el número. Son dos cosas distintas y en el tiempo de cierre
 * se separan: pasar de 14 días a 11 es una flecha hacia abajo y una buena
 * noticia.
 */
function tono(cambio, menosEsMejor = false) {
    if (! cambio || cambio.direccion === 'igual') return 'igual';

    const bueno = menosEsMejor ? cambio.direccion === 'baja' : cambio.direccion === 'sube';

    return bueno ? 'sube' : 'baja';
}

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

    const info = await db.getServidorInfo();
    vigencia.value = info?.vigencia_cotizacion_dias || 30;
    envioAutomatico.value = info?.envio_automatico !== false;

    // El período y el ámbito quedan como el vendedor los dejó, no en «mes» y
    // «yo»: si uno se guarda inválido —el jefe ya no lo es, el período no
    // existe— se cae al de siempre en vez de dejar el panel sin nada elegido.
    const periodoGuardado = await db.getPanelPeriodo();
    if (periodoGuardado && PERIODOS.some((p) => p.id === periodoGuardado)) periodo.value = periodoGuardado;

    const ambitoGuardado = await db.getPanelAmbito();
    ambito.value = (ambitoGuardado && ambitos.value.some((a) => a.id === ambitoGuardado))
        ? ambitoGuardado
        : ambitos.value[0]?.id ?? 'todos';

    await releerAlmacen();
    // El punto rojo de la barra inferior sale de aquí: si se pidiera recién al
    // abrir el buzón, nunca habría aviso de que hay algo que mirar.
    refrescarAvisos();
    cargarAprobaciones();
});

/**
 * Todo lo que el panel cuenta del almacén, leído de nuevo.
 *
 * El panel no consulta al servidor: cuenta lo que hay en IndexedDB. Y la
 * primera vez lo cuenta cuando todavía no hay nada, porque la descarga arranca
 * en el login y tarda medio minuto: el vendedor se quedaba mirando «Todavía no
 * te has traído los datos» encima de un teléfono con catorce mil filas dentro,
 * y sincronizaba otra vez para que aparecieran.
 */
async function releerAlmacen() {
    registros.value = await contarRegistros();
    sincronizado.value = await db.getSincronizado();
    await calcularPanel();
    await contarPendientes();
}

// Da igual quién haya pedido la descarga —el login, el botón de aquí, la
// pantalla Cuenta—: cuando termina, el panel vuelve a contar.
watch(corridas, releerAlmacen);

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
        await releerAlmacen();

        aviso.value = r.errores.length
            ? `Se actualizó casi todo, pero ${r.errores[0]}`
            : `Listo: ${registros.value.toLocaleString('es-CL')} registros en el teléfono.`
                + (salida.enviados ? ` Se enviaron ${salida.enviados} cambios.` : '');

        refrescarAvisos();
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
                    <!-- «Neto» va escrito, no supuesto. El total del documento
                         lleva IVA y el del panel no, y sin decirlo el vendedor
                         suma su lista a mano y no le cuadra. -->
                    <div class="cuantos">
                        {{ venta.n }} {{ venta.n === 1 ? 'nota de venta aprobada' : 'notas de venta aprobadas' }} · neto
                    </div>
                    <!-- Lo escrito y sin autorizar no suma arriba, y por eso
                         mismo tiene que verse: si no, el vendedor cuenta seis
                         notas en su lista, el panel dice cuatro y la diferencia
                         parece un error de la app. -->
                    <button class="esperando" v-if="m.actual.esperando.n"
                            @click="router.push('/notas-venta?estado=P')">
                        <AppIcon name="esperando" :size="14" color="currentColor" />
                        <span>
                            {{ m.actual.esperando.n }}
                            {{ m.actual.esperando.n === 1 ? 'nota más espera' : 'notas más esperan' }}
                            aprobación · {{ dinero(m.actual.esperando.monto) }} que todavía no cuenta
                        </span>
                        <AppIcon name="avanzar" :size="14" color="currentColor" />
                    </button>
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

                <Persiana class="embudo-persiana">
                    <template #cabecera>
                        <span v-if="m.actual.conversion">
                            Conversión <b>{{ porcentaje(m.actual.conversion.pct) }}</b>
                        </span>
                        <span v-else>Conversión — sin datos</span>
                    </template>
                    <div class="embudo-pie" v-if="m.actual.conversion">
                        {{ m.actual.conversion.n }} de {{ m.actual.conversion.base }}
                        {{ m.actual.conversion.base === 1 ? 'cotización llegó' : 'cotizaciones llegaron' }}
                        a nota de venta.
                    </div>
                    <div class="embudo-pie" v-else>
                        Sin cotizaciones en el período: no hay conversión que medir.
                    </div>
                </Persiana>
                <Persiana class="embudo-persiana">
                    <template #cabecera>Cómo leer este panel</template>
                    <div class="embudo-pie">
                        Todos los montos del panel van <b>netos</b>, sin IVA. En la lista y en
                        la ficha de cada documento sale el total que paga el cliente.
                        Y venta es la nota de venta <b>aprobada o concluida</b>: la que
                        sigue pendiente aparece en la lista, pero no en esta cifra.
                    </div>
                </Persiana>

                <!-- Cómo se vende, no cuánto. Las tres van juntas porque se
                     leen juntas: un ticket que sube con una conversión que baja
                     es un vendedor que está yendo a menos puertas más grandes. -->
                <div class="seccion">
                    <h2>{{ tituloRendimiento }}</h2>
                    <span class="sub">vs. {{ rangoPrevio.etiqueta.toLowerCase() }}</span>
                </div>

                <div class="rendimiento">
                    <template v-for="r in rendimiento" :key="r.id">
                        <button type="button" class="medida"
                                :aria-expanded="medidaAbierta === r.id"
                                @click="medidaAbierta = medidaAbierta === r.id ? null : r.id">
                            <AppIcon :name="r.icono" :caja="px(38)" :size="px(19)" />
                            <div class="texto">
                                <div class="rotulo">{{ r.rotulo }}</div>
                            </div>
                            <div class="cifra">
                                <div class="valor">{{ r.valor }}</div>
                                <span class="tendencia" :class="tono(r.cambio, r.menosEsMejor)" v-if="r.cambio">
                                    <AppIcon :name="iconoTendencia(r.cambio)" :size="12" color="currentColor" />
                                    {{ r.cambio.texto }}
                                </span>
                            </div>
                            <AppIcon name="desplegar" :size="16" color="var(--texto-suave)"
                                     class="persiana-flecha" :class="{ abierta: medidaAbierta === r.id }" />
                        </button>
                        <p class="medida-sub" v-if="medidaAbierta === r.id">{{ r.sub }}</p>
                    </template>
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

            <template v-if="!sinDatos">
                <div class="seccion">
                    <h2>Actividad reciente</h2>
                    <span class="sub">Lo último, sin mirar el período</span>
                </div>

                <div class="grupo-reciente" v-for="g in recientes" :key="g.id">
                    <button class="cabeza" @click="router.push(g.ruta)">
                        <AppIcon :name="g.icono" :caja="px(30)" :size="px(16)" />
                        <span class="titulo">{{ g.titulo }}</span>
                        <span class="ver">Ver todas</span>
                        <AppIcon name="avanzar" :size="15" color="var(--texto-suave)" />
                    </button>

                    <Vacio v-if="!g.filas.length" :icono="g.icono" :titulo="`Sin ${g.titulo.toLowerCase()}`" />

                    <template v-for="d in g.filas" :key="filaReciente(g, d).clave">
                        <button class="fila-reciente" @click="router.push(filaReciente(g, d).ruta)">
                            <span class="franja" :class="filaReciente(g, d).color"></span>
                            <span class="texto">
                                <span class="linea">
                                    <b>Nº {{ filaReciente(g, d).numero }}</b>
                                    <small>{{ fechaCorta(d.fecha) }}</small>
                                </span>
                                <small class="quien">{{ nombresCliente[d.cliente] || d.cliente }}</small>
                            </span>
                            <span class="cifra">
                                <b>{{ monto(d.total, d.moneda) }}</b>
                                <small>{{ filaReciente(g, d).rotulo }}</small>
                            </span>
                        </button>
                    </template>
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
