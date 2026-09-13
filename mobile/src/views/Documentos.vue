<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { idb } from '../idb';
import { monto, fecha, nombre as nombreDe } from '../catalogos';
import { TIPOS, estado } from '../documentos';
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
const documentos = ref([]);
const cargando = ref(true);

onMounted(cargar);
watch([busqueda, filtroEstado, tipo], cargar);

async function cargar() {
    cargando.value = true;
    try {
        const filas = await idb.buscar(def.value.almacen, busqueda.value, {
            limite: 200,
            filtro: filtroEstado.value ? (d) => d.estado === filtroEstado.value : null,
        });
        // El más nuevo arriba: es el que se está mirando en la reunión.
        documentos.value = filas.sort((a, b) => b.numero - a.numero);
    } finally {
        cargando.value = false;
    }
}

/** Solo se ofrecen los estados que de verdad hay: un filtro vacío es ruido. */
const estadosPresentes = computed(() => {
    const vistos = new Set(documentos.value.map((d) => d.estado));
    return Object.entries(def.value.estados)
        .filter(([c]) => vistos.has(c) || filtroEstado.value === c)
        .map(([codigo, e]) => ({ codigo, ...e }));
});

const vacio = computed(() => ! cargando.value && ! documentos.value.length);
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>{{ def.titulo }}</h1>
        </div>

        <div class="contenido">
            <Buscador v-model="busqueda" placeholder="Número, cliente o contacto" />

            <div class="pestanas en-linea">
                <button :class="{ activa: filtroEstado === '' }" @click="filtroEstado = ''">Todo</button>
                <button v-for="e in estadosPresentes" :key="e.codigo"
                        :class="{ activa: filtroEstado === e.codigo }"
                        @click="filtroEstado = e.codigo">{{ e.rotulo }}</button>
            </div>

            <Vacio v-if="vacio && ! busqueda && ! filtroEstado" :icono="def.icono"
                   :titulo="`Sin ${def.titulo.toLowerCase()}`">
                Aquí aparecen las de los últimos 12 meses, una vez que sincronices.
            </Vacio>

            <Vacio v-else-if="vacio" icono="sinResultados" titulo="Nada con esos criterios" />

            <div class="item" v-for="d in documentos" :key="d.numero"
                 @click="router.push(`${def.ruta}/${d.numero}`)">
                <div class="item-estado" :class="estado(tipo, d.estado).color"></div>
                <div class="item-cuerpo">
                    <div class="item-titulo">Nº {{ d.numero }} · {{ monto(d.total, d.moneda) }}</div>
                    <div class="item-linea">{{ d.observacion || d.cliente }}</div>
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
