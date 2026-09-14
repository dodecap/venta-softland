<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { idb } from '../idb';
import { nombre as nombreDe } from '../catalogos';
import { useAccionCrear } from '../crear';
import { px } from '../densidad';
import AppIcon from '../components/AppIcon.vue';
import { conectado } from '../red';
import { refrescarGrupo } from '../sync';
import { useTirarParaRefrescar } from '../refresco';
import Aviso from '../components/Aviso.vue';
import Buscador from '../components/Buscador.vue';
import TirarRefrescar from '../components/TirarRefrescar.vue';
import Vacio from '../components/Vacio.vue';

/*
 * Los clientes de la empresa, buscables sin señal.
 *
 * Nada de esto habla con el servidor: sale de IndexedDB, que es donde los dejó
 * la sincronización. En terreno esta pantalla funciona igual que en la oficina,
 * que es de lo que se trataba la fase.
 */

const router = useRouter();
const TOPE = 60;

const busqueda = ref('');
const resultados = ref([]);
const total = ref(0);
const cargando = ref(true);
const soloConCorreo = ref(false);

useAccionCrear('Nuevo cliente', () => router.push('/clientes/nuevo'));

onMounted(async () => {
    total.value = await idb.contar('clientes');
    await buscar();
});

watch([busqueda, soloConCorreo], buscar);

async function buscar() {
    cargando.value = true;
    try {
        resultados.value = await idb.buscar('clientes', busqueda.value, {
            limite: TOPE,
            filtro: soloConCorreo.value ? (c) => !! (c.email || c.email_dte) : null,
        });
    } finally {
        cargando.value = false;
    }
}

/** Hay más de los que caben: se avisa en vez de mentir con una lista cortada. */
const hayMas = computed(() => resultados.value.length >= TOPE);

const sinDescargar = computed(() => ! cargando.value && total.value === 0);

function ubicacion(c) {
    return [nombreDe('comunas', c.comuna), nombreDe('ciudades', c.ciudad)]
        .filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join(' · ');
}
/* ------------------------------------------------- tirar para actualizar
 *
 * El mismo gesto que en las listas de documentos, y por la misma razón: lo que
 * cambia en Softland no llega solo al teléfono. Aquí baja sólo los clientes; el resto
 * de los maestros no se toca.
 */
const contenido = ref(null);
const refrescado = ref(null);
const errorRefresco = ref('');

const { distancia, refrescando, listo } = useTirarParaRefrescar(contenido, refrescar, conectado);

async function refrescar() {
    errorRefresco.value = '';
    try {
        await refrescarGrupo('clientes');
        total.value = await idb.contar('clientes');
        await buscar();
        await leerRefrescado();
    } catch (e) {
        errorRefresco.value = e.message;
    }
}

/** Cuándo se bajó esta lista, no la app entera: son dos cosas distintas. */
async function leerRefrescado() {
    refrescado.value = (await idb.estado('clientes'))?.sync_at || null;
}

const cuando = computed(() => {
    if (! refrescado.value) return 'Sin descargar';

    const d = new Date(refrescado.value);
    const hoy = new Date().toDateString() === d.toDateString();
    const hora = d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });

    return hoy ? `Hoy ${hora}` : `${d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit' })} ${hora}`;
});
</script>

<template>
    <div class="pantalla">
        <div class="encabezado simple">
            <AppIcon name="cliente" :caja="px(40)" :size="px(20)" />
            <div class="saludo">
                <h1>Clientes</h1>
                <div class="quien">{{ total.toLocaleString('es-CL') }} en el teléfono</div>
            </div>
        </div>

        <div class="contenido" ref="contenido">
            <TirarRefrescar :distancia="distancia" :refrescando="refrescando" :listo="listo"
                            que="los clientes" />

            <Buscador v-model="busqueda" placeholder="Nombre, RUT o código" />

            <div class="cuando-lista">
                <span>Actualizada: {{ cuando }}</span>
                <button class="actualizar-lista" :disabled="refrescando || ! conectado" @click="refrescar">
                    <AppIcon name="sincronizar" :size="15" color="currentColor" :class="{ girando: refrescando }" />
                    {{ conectado ? 'Actualizar' : 'Sin señal' }}
                </button>
            </div>

            <Aviso tipo="error" v-if="errorRefresco">{{ errorRefresco }}</Aviso>

            <label class="interruptor filtro">
                <span>Solo los que tienen correo</span>
                <input type="checkbox" v-model="soloConCorreo">
            </label>

            <Vacio v-if="sinDescargar" icono="sinRed" titulo="Todavía no hay clientes">
                Sincroniza desde el panel para traerte la cartera al teléfono.
            </Vacio>

            <Vacio v-else-if="! cargando && ! resultados.length" icono="sinResultados"
                   titulo="Nadie con ese nombre">
                Prueba con el RUT sin puntos, o con una sola palabra del nombre.
            </Vacio>

            <div class="item" v-for="c in resultados" :key="c.codigo" @click="router.push(`/clientes/${c.codigo}`)">
                <div class="item-estado" :class="c.bloqueado ? 'rojo' : 'cian'"></div>
                <div class="item-cuerpo">
                    <div class="item-titulo">{{ c.nombre }}</div>
                    <div class="item-linea">{{ c.rut }}<span v-if="ubicacion(c)"> · {{ ubicacion(c) }}</span></div>
                    <div class="item-meta">
                        <span class="etiqueta gris">{{ c.codigo }}</span>
                        <span v-if="c.bloqueado" class="etiqueta roja">bloqueado</span>
                        <span v-if="c.email"> · {{ c.email }}</span>
                    </div>
                </div>
            </div>

            <p class="ayuda centrado" v-if="hayMas">
                Se muestran los primeros {{ TOPE }}. Escribe un poco más para afinar.
            </p>
        </div>
    </div>
</template>
