<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { idb } from '../idb';
import { monto, nombre as nombreDe, opciones } from '../catalogos';
import { useCapa } from '../nav';
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
 * Catálogo de productos, sin señal.
 *
 * El precio que se muestra es el del maestro (`iw_tprod.PrecioVta`) y, cuando
 * existe, el de la lista de precios.
 *
 * En INNOVAGES la lista de precios no aparece nunca, y no es un error de la
 * app: `iw_tlprprod` tiene 838 filas cuyos 835 códigos de producto **no existen
 * en `iw_tprod`**. Es una tabla que quedó de una carga vieja y que nadie
 * mantiene. Se sigue descargando porque otra empresa Softland sí puede tenerla
 * al día, y aquí simplemente no se dibuja ninguna fila.
 *
 * En las 2.350 cotizaciones reales el vendedor terminó escribiendo un precio
 * propio en casi todas. Por eso esto es **referencia**, no una promesa: quien
 * cotiza decide.
 */

const router = useRouter();
const TOPE = 60;

const busqueda = ref('');
const grupo = ref('');
const resultados = ref([]);
const total = ref(0);
const cargando = ref(true);
const abierto = ref(null);
const preciosLista = ref([]);

useCapa(computed(() => abierto.value !== null), () => { abierto.value = null; });

onMounted(async () => {
    total.value = await idb.contar('productos');
    await buscar();
});

watch([busqueda, grupo], buscar);

async function buscar() {
    cargando.value = true;
    try {
        resultados.value = await idb.buscar('productos', busqueda.value, {
            limite: TOPE,
            filtro: grupo.value ? (p) => p.grupo === grupo.value : null,
        });
    } finally {
        cargando.value = false;
    }
}

const grupos = computed(() => opciones('grupos').slice().sort((a, b) => a.nombre.localeCompare(b.nombre)));
const hayMas = computed(() => resultados.value.length >= TOPE);
const sinDescargar = computed(() => ! cargando.value && total.value === 0);

async function abrir(p) {
    abierto.value = p;
    preciosLista.value = await idb.porIndice('precios', 'producto', p.codigo);
}
/* ------------------------------------------------- tirar para actualizar
 *
 * El mismo gesto que en las listas de documentos, y por la misma razón: lo que
 * cambia en Softland no llega solo al teléfono. Aquí baja sólo los productos; el resto
 * de los maestros no se toca.
 */
const contenido = ref(null);
const refrescado = ref(null);
const errorRefresco = ref('');

const { distancia, refrescando, listo } = useTirarParaRefrescar(contenido, refrescar, conectado);

async function refrescar() {
    errorRefresco.value = '';
    try {
        await refrescarGrupo('productos');
        await buscar();
        await leerRefrescado();
    } catch (e) {
        errorRefresco.value = e.message;
    }
}

/** Cuándo se bajó esta lista, no la app entera: son dos cosas distintas. */
async function leerRefrescado() {
    refrescado.value = (await idb.estado('productos'))?.sync_at || null;
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
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>Productos</h1>
        </div>

        <div class="contenido" ref="contenido">
            <TirarRefrescar :distancia="distancia" :refrescando="refrescando" :listo="listo"
                            que="los productos" />

            <Buscador v-model="busqueda" placeholder="Nombre, código o código de barras" />

            <div class="cuando-lista">
                <span>Actualizada: {{ cuando }}</span>
                <button class="actualizar-lista" :disabled="refrescando || ! conectado" @click="refrescar">
                    <AppIcon name="sincronizar" :size="15" color="currentColor" :class="{ girando: refrescando }" />
                    {{ conectado ? 'Actualizar' : 'Sin señal' }}
                </button>
            </div>

            <Aviso tipo="error" v-if="errorRefresco">{{ errorRefresco }}</Aviso>

            <select v-model="grupo" class="filtro-grupo">
                <option value="">Todos los grupos ({{ total.toLocaleString('es-CL') }} productos)</option>
                <option v-for="g in grupos" :key="g.codigo" :value="g.codigo">{{ g.nombre }}</option>
            </select>

            <Vacio v-if="sinDescargar" icono="sinRed" titulo="Todavía no hay productos">
                Sincroniza desde el panel para traerte el catálogo al teléfono.
            </Vacio>

            <Vacio v-else-if="! cargando && ! resultados.length" icono="sinResultados"
                   titulo="Ningún producto con eso">
                Prueba con una palabra del nombre o con el código.
            </Vacio>

            <div class="item" v-for="p in resultados" :key="p.codigo" @click="abrir(p)">
                <div class="item-estado" :class="p.afecto ? 'cian' : 'amarillo'"></div>
                <div class="item-cuerpo">
                    <div class="item-titulo">{{ p.nombre }}</div>
                    <div class="item-linea">{{ monto(p.precio, p.moneda) }} / {{ nombreDe('unidades', p.unidad) }}</div>
                    <div class="item-meta">
                        <span class="etiqueta gris">{{ p.codigo }}</span>
                        <span v-if="p.grupo"> · {{ nombreDe('grupos', p.grupo) }}</span>
                        <span v-if="! p.afecto"> · exento</span>
                    </div>
                </div>
            </div>

            <p class="ayuda centrado" v-if="hayMas">
                Se muestran los primeros {{ TOPE }}. Escribe un poco más para afinar.
            </p>
        </div>

        <!-- Ficha del producto -->
        <div class="velo" v-if="abierto" @click.self="abierto = null">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Producto</h2>
                    <button class="icono-barra" @click="abierto = null"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <div class="ficha">
                        <h2>{{ abierto.nombre }}</h2>
                        <div class="sub">{{ abierto.codigo }}</div>
                        <div class="etiquetas">
                            <span class="etiqueta" :class="abierto.afecto ? '' : 'gris'">
                                {{ abierto.afecto ? 'Afecto a IVA' : 'Exento' }}
                            </span>
                            <span v-if="abierto.grupo" class="etiqueta gris">{{ nombreDe('grupos', abierto.grupo) }}</span>
                        </div>
                    </div>

                    <div class="tarjeta">
                        <div class="tarjeta-cabecera">Precios de referencia</div>
                        <div class="tarjeta-cuerpo datos">
                            <div><span>Venta</span><b>{{ monto(abierto.precio, abierto.moneda) }}</b></div>
                            <div v-if="abierto.precio_boleta">
                                <span>Boleta (con IVA)</span><b>{{ monto(abierto.precio_boleta, abierto.moneda) }}</b>
                            </div>
                            <div v-for="p in preciosLista" :key="p.lista">
                                <span>Lista {{ nombreDe('listas_precio', p.lista) }}</span>
                                <b>{{ monto(p.valor, abierto.moneda) }}</b>
                            </div>
                            <div><span>Unidad</span><b>{{ nombreDe('unidades', abierto.unidad) }}</b></div>
                            <div v-if="abierto.barra"><span>Código de barras</span><b>{{ abierto.barra }}</b></div>
                        </div>
                    </div>

                    <p class="ayuda">
                        Son precios de referencia: el precio de la cotización lo fija quien vende.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
