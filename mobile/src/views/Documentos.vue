<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { db } from '../db';
import { idb } from '../idb';
import { monto, fecha, nombre as nombreDe } from '../catalogos';
import { dia } from '../panel/periodo';
import { situacion } from '../panel/metricas';
import { TIPOS, estado } from '../documentos';
import { parciales, porFacturar } from '../saldo';
import { useAccionCrear } from '../crear';
import { contarPendientes, porEnviar } from '../pendientes';
import { conectado } from '../red';
import { refrescarGrupo } from '../sync';
import { useTirarParaRefrescar } from '../refresco';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Buscador from '../components/Buscador.vue';
import TirarRefrescar from '../components/TirarRefrescar.vue';
import Vacio from '../components/Vacio.vue';

/*
 * Lista de cotizaciones o de notas de venta — la misma pantalla para las dos.
 *
 * Son el mismo documento en dos momentos de su vida y se leen igual; lo que
 * cambia (almacén, estados, título) está en `documentos.js`. El tipo lo dice la
 * ruta, no una condición desperdigada por el template.
 */

const route = useRoute();
const router = useRouter();

const tipo = computed(() => route.meta.tipo);
const def = computed(() => TIPOS[tipo.value]);

/*
 * Las cotizaciones convertidas a medias. Softland no distingue este caso: su
 * estado `V` dice «tiene nota de venta» y nada más, así que sin esta marca hay
 * que entrar una por una para saber cuál dejó algo fuera.
 */
const aMedias = ref(new Set());

/*
 * «Facturar» del panel llega aquí con el filtro puesto: las notas de venta que
 * todavía tienen algo por facturar. Es la cola de trabajo de quien factura, no
 * un catálogo de documentos.
 */
const soloPorFacturar = computed(() => route.query.facturar === '1' && tipo.value === 'nota_venta');

const busqueda = ref('');
const filtroEstado = ref('');
const vigencia = ref(30);

/*
 * De dónde viene el vendedor. El panel manda `?atencion=por_vencer` y aquí se
 * abre la lista ya filtrada: tocar «3 cotizaciones por vencer» y aterrizar en
 * las 185 de siempre sería obligar a buscarlas a mano.
 *
 * La regla que decide cuál es cuál no se repite: es `situacion()`, la misma
 * que usó el panel para contarlas. Si cada pantalla tuviera su copia, el panel
 * diría tres y la lista mostraría cuatro.
 */
const ATENCION = {
    por_vencer: 'Por vencer',
    vencida: 'Vencidas',
};

const atencion = computed(() => (ATENCION[route.query.atencion] ? route.query.atencion : ''));
const documentos = ref([]);
const sinEnviar = ref([]);
const nombres = ref({});
const cargando = ref(true);

/* ------------------------------------------------- tirar para actualizar
 *
 * Un documento que cambia en el Softland de escritorio no llega solo al
 * teléfono, y Softland no tiene columna que permita preguntar qué cambió. Lo
 * que sí se puede es volver a bajar **esta** lista y nada más: cotizaciones
 * con su detalle son 1.096 filas de las 14.184 del teléfono.
 *
 * De paso se entera de lo borrado, que es el punto ciego de la sincronización
 * incremental: la descarga completa de un maestro barre lo que quedó con sello
 * viejo.
 */
const contenido = ref(null);
const refrescado = ref(null);
const errorRefresco = ref('');

const { distancia, refrescando, listo } = useTirarParaRefrescar(contenido, refrescar, conectado);

async function refrescar() {
    errorRefresco.value = '';
    try {
        await refrescarGrupo(def.value.grupo);
        await cargar();
        await leerRefrescado();
    } catch (e) {
        errorRefresco.value = e.message;
    }
}

/**
 * Cuándo se bajó esta lista por última vez — no la app entera, que es lo que
 * dice Cuenta. Son dos números distintos desde que se puede refrescar una sola
 * pantalla, y mezclarlos sería decirle al vendedor que está al día con todo
 * por haber tirado de las cotizaciones.
 */
async function leerRefrescado() {
    refrescado.value = (await idb.estado(def.value.almacen))?.sync_at || null;
}

const cuando = computed(() => {
    if (! refrescado.value) return 'Sin descargar';

    const d = new Date(refrescado.value);
    const hoy = new Date().toDateString() === d.toDateString();
    const hora = d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });

    return hoy ? `Hoy ${hora}` : `${d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit' })} ${hora}`;
});

onMounted(async () => {
    vigencia.value = (await db.getServidorInfo())?.vigencia_cotizacion_dias || 30;
    await cargar();
    await leerRefrescado();
});

/*
 * El panel puede llegar con el filtro puesto — `?estado=P` desde «2 notas más
 * esperan aprobación». Va en un `watch` y no en el montaje porque esta
 * pantalla se reusa: cotizaciones y notas de venta son el mismo componente, y
 * volver a ella con otra consulta no la vuelve a montar.
 *
 * A partir de ahí manda la pestaña: el vendedor ya está en la lista y la
 * cambia con el dedo.
 */
watch(() => route.query.estado, (v) => {
    const pedido = String(v || '').toUpperCase();

    if (def.value.estados[pedido]) filtroEstado.value = pedido;
}, { immediate: true });
watch([busqueda, filtroEstado, tipo, atencion, soloPorFacturar], cargar);
// La bandeja se vacía sola al volver la red, estando en otra pantalla: sin esto
// el documento seguiría apareciendo como «sin enviar» después de haber salido.
watch(porEnviar, cargar);

// El botón flotante es uno solo para toda la app; esta pantalla solo dice qué
// hace el suyo. Así hereda la posición que el vendedor eligió y el resto.
useAccionCrear(`Nueva ${def.value.singular.toLowerCase()}`, () => router.push(`${def.value.ruta}/nuevo`));

async function cargar() {
    cargando.value = true;
    try {
        const regla = { hoy: dia(new Date()), vigencia: vigencia.value };
        const filtros = [];

        if (filtroEstado.value) filtros.push((d) => d.estado === filtroEstado.value);
        if (atencion.value) filtros.push((d) => situacion(d, regla) === atencion.value);

        const filas = await idb.buscar(def.value.almacen, busqueda.value, {
            limite: 200,
            filtro: filtros.length ? (d) => filtros.every((f) => f(d)) : null,
        });
        // El más nuevo arriba: es el que se está mirando en la reunión.
        documentos.value = filas.sort((a, b) => b.numero - a.numero);

        // Los que todavía no salieron del teléfono no tienen número, así que no
        // están en el almacén. Van arriba y con la franja ámbar: el vendedor
        // acaba de escribirlos y tiene que verlos, no suponer que se perdieron.
        sinEnviar.value = (await contarPendientes())
            .filter((p) => p.accion === `${tipo.value}.crear`);

        aMedias.value = tipo.value === 'cotizacion'
            ? await parciales(documentos.value.map((d) => d.numero))
            : new Set();

        if (soloPorFacturar.value) {
            const conSaldo = await porFacturar(documentos.value.map((d) => d.numero));
            documentos.value = documentos.value.filter((d) => conSaldo.has(d.numero));
        }

        await cargarNombres();
    } finally {
        cargando.value = false;
    }
}

/**
 * El nombre del cliente de cada documento.
 *
 * Softland guarda el código (`CodAux`) y nada más. Una lista que dice «99999999»
 * obliga a abrir el documento para saber de quién es, que es justo lo que la
 * lista tenía que ahorrar.
 */
async function cargarNombres() {
    const codigos = new Set([
        ...documentos.value.map((d) => d.cliente),
        ...sinEnviar.value.map((p) => p.datos.cliente),
    ].filter(Boolean));

    const mapa = {};
    for (const c of codigos) {
        mapa[c] = (await idb.obtener('clientes', c))?.nombre || c;
    }
    nombres.value = mapa;
}

/* ------------------------------------------- buscar una más vieja en Softland
 *
 * El teléfono se lleva doce meses de documentos. Más atrás hay 2.351
 * cotizaciones y once mil líneas: no caben, no se usan y además ensuciarían el
 * panel, donde entrarían a contarse como vencidas de hace dos años.
 *
 * Pero preguntar por la 8000 tiene que funcionar. El vendedor sabe su número —
 * se lo dijo el cliente por teléfono— y es suya. Así que si lo que se escribió
 * es un número, no hay nada con él en el aparato y hay señal, se ofrece ir a
 * buscarla: la ficha la pide a la API, la muestra y no la guarda.
 */
const numeroBuscado = computed(() => {
    const t = busqueda.value.trim();

    return /^\d{1,9}$/.test(t) ? Number(t) : null;
});

const buscarEnServidor = computed(() => ! cargando.value
    && numeroBuscado.value !== null
    && conectado.value
    && ! documentos.value.some((d) => Number(d.numero) === numeroBuscado.value));

/** Solo se ofrecen los estados que de verdad hay: un filtro vacío es ruido. */
const estadosPresentes = computed(() => {
    const vistos = new Set(documentos.value.map((d) => d.estado));
    return Object.entries(def.value.estados)
        .filter(([c]) => vistos.has(c) || filtroEstado.value === c)
        .map(([codigo, e]) => ({ codigo, ...e }));
});

const vacio = computed(() =>
    ! cargando.value && ! documentos.value.length && ! sinEnviar.value.length);

function quitarAtencion() {
    router.replace({ path: route.path });
}
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>{{ def.titulo }}</h1>
        </div>

        <div class="contenido" ref="contenido">
            <TirarRefrescar :distancia="distancia" :refrescando="refrescando" :listo="listo"
                            :que="def.titulo.toLowerCase()" />

            <Buscador v-model="busqueda" placeholder="Número, cliente o contacto" />

            <!-- El gesto no se ve, así que el botón también está. Y la hora es
                 la de esta lista, no la de la sincronización completa. -->
            <div class="cuando-lista">
                <span>Actualizada: {{ cuando }}</span>
                <button class="actualizar-lista" :disabled="refrescando || ! conectado" @click="refrescar">
                    <AppIcon name="sincronizar" :size="15" color="currentColor" :class="{ girando: refrescando }" />
                    {{ conectado ? 'Actualizar' : 'Sin señal' }}
                </button>
            </div>

            <Aviso tipo="error" v-if="errorRefresco">{{ errorRefresco }}</Aviso>

            <!-- El filtro que trajo el vendedor desde el panel, a la vista y
                 con su salida: un filtro que no se ve es una lista incompleta
                 sin explicación. -->
            <button class="filtro-traido" v-if="atencion" @click="quitarAtencion">
                <AppIcon name="cotizacion" :size="16" color="currentColor" />
                {{ ATENCION[atencion] }}
                <AppIcon name="cerrar" :size="16" color="currentColor" />
            </button>
            <button class="filtro-traido" v-if="soloPorFacturar" @click="quitarAtencion">
                <AppIcon name="factura" :size="16" color="currentColor" />
                Con algo por facturar
                <AppIcon name="cerrar" :size="16" color="currentColor" />
            </button>

            <div class="pestanas en-linea">
                <button :class="{ activa: filtroEstado === '' }" @click="filtroEstado = ''">Todo</button>
                <button v-for="e in estadosPresentes" :key="e.codigo"
                        :class="{ activa: filtroEstado === e.codigo }"
                        @click="filtroEstado = e.codigo">{{ e.rotulo }}</button>
            </div>

            <Vacio v-if="vacio && soloPorFacturar" icono="factura" titulo="No queda nada por facturar">
                Todas las notas de venta aprobadas están facturadas del todo.
            </Vacio>

            <Vacio v-else-if="vacio && ! busqueda && ! filtroEstado && ! atencion" :icono="def.icono"
                   :titulo="`Sin ${def.titulo.toLowerCase()}`">
                Aquí aparecen las de los últimos 12 meses, una vez que sincronices.
            </Vacio>

            <!-- No está en el teléfono y es un número: se puede ir a buscarla.
                 Va antes del estado vacío porque es la salida, no el consuelo. -->
            <button class="item buscar-servidor" v-if="buscarEnServidor"
                    @click="router.push(`${def.ruta}/${numeroBuscado}`)">
                <div class="item-estado cian"></div>
                <div class="item-cuerpo">
                    <div class="item-titulo">Buscar la Nº {{ numeroBuscado }} en Softland</div>
                    <div class="item-meta">
                        No está en el teléfono. Si es tuya, se trae del servidor aunque sea
                        de hace años.
                    </div>
                </div>
                <AppIcon name="avanzar" :size="18" color="var(--texto-suave)" />
            </button>

            <Vacio v-else-if="vacio" icono="sinResultados" titulo="Nada con esos criterios" />

            <div class="item" v-for="p in sinEnviar" :key="p.uuid" @click="router.push('/cuenta')">
                <div class="item-estado cian"></div>
                <div class="item-cuerpo">
                    <div class="item-titulo">Sin enviar · {{ nombres[p.datos.cliente] || p.datos.cliente }}</div>
                    <div class="item-linea">
                        {{ p.datos.lineas.length }}
                        {{ p.datos.lineas.length === 1 ? 'línea' : 'líneas' }}
                    </div>
                    <div class="item-meta">
                        <span class="etiqueta gris">{{ p.estado === 'rechazado' ? 'Rechazada' : 'Esperando señal' }}</span>
                        <span v-if="p.estado === 'rechazado'"> · {{ p.mensaje }}</span>
                    </div>
                </div>
                <div class="item-sync" :class="p.estado === 'rechazado' ? 'error' : 'pendiente'"></div>
            </div>

            <div class="item" v-for="d in documentos" :key="d.numero"
                 @click="router.push(`${def.ruta}/${d.numero}`)">
                <div class="item-estado" :class="estado(tipo, d.estado).color"></div>
                <div class="item-cuerpo">
                    <div class="item-titulo">Nº {{ d.numero }} · {{ monto(d.total, d.moneda) }}</div>
                    <div class="item-linea">{{ nombres[d.cliente] || d.cliente }}</div>
                    <div class="item-meta">
                        <span class="etiqueta gris">{{ estado(tipo, d.estado).rotulo }}</span>
                        <span class="etiqueta cian" v-if="aMedias.has(d.numero)">A medias</span>
                        <span> · {{ fecha(d.fecha) }}</span>
                        <span v-if="d.contacto"> · {{ d.contacto }}</span>
                        <span v-if="d.vendedor"> · {{ nombreDe('vendedores', d.vendedor) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
