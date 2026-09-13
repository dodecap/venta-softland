<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { idb } from '../idb';
import { nombre as nombreDe } from '../catalogos';
import { useAccionCrear } from '../crear';
import { px } from '../densidad';
import AppIcon from '../components/AppIcon.vue';
import Buscador from '../components/Buscador.vue';
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

        <div class="contenido">
            <Buscador v-model="busqueda" placeholder="Nombre, RUT o código" />

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
