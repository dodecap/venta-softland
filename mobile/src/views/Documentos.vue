<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { db } from '../db';
import { idb } from '../idb';
import { monto, fecha, nombre as nombreDe } from '../catalogos';
import { dia } from '../panel/periodo';
import { situacion } from '../panel/metricas';
import { TIPOS, estado } from '../documentos';
import { useAccionCrear } from '../crear';
import { contarPendientes, porEnviar } from '../pendientes';
import AppIcon from '../components/AppIcon.vue';
import Buscador from '../components/Buscador.vue';
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

onMounted(async () => {
    vigencia.value = (await db.getServidorInfo())?.vigencia_cotizacion_dias || 30;
    await cargar();
});
watch([busqueda, filtroEstado, tipo, atencion], cargar);
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

        <div class="contenido">
            <Buscador v-model="busqueda" placeholder="Número, cliente o contacto" />

            <!-- El filtro que trajo el vendedor desde el panel, a la vista y
                 con su salida: un filtro que no se ve es una lista incompleta
                 sin explicación. -->
            <button class="filtro-traido" v-if="atencion" @click="quitarAtencion">
                <AppIcon name="cotizacion" :size="16" color="currentColor" />
                {{ ATENCION[atencion] }}
                <AppIcon name="cerrar" :size="16" color="currentColor" />
            </button>

            <div class="pestanas en-linea">
                <button :class="{ activa: filtroEstado === '' }" @click="filtroEstado = ''">Todo</button>
                <button v-for="e in estadosPresentes" :key="e.codigo"
                        :class="{ activa: filtroEstado === e.codigo }"
                        @click="filtroEstado = e.codigo">{{ e.rotulo }}</button>
            </div>

            <Vacio v-if="vacio && ! busqueda && ! filtroEstado && ! atencion" :icono="def.icono"
                   :titulo="`Sin ${def.titulo.toLowerCase()}`">
                Aquí aparecen las de los últimos 12 meses, una vez que sincronices.
            </Vacio>

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
                        <span> · {{ fecha(d.fecha) }}</span>
                        <span v-if="d.contacto"> · {{ d.contacto }}</span>
                        <span v-if="d.vendedor"> · {{ nombreDe('vendedores', d.vendedor) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
