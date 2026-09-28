<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { idb } from '../idb';
import { monto, fecha, nombre as nombreDe } from '../catalogos';
import { conectado } from '../red';
import { refrescarGrupo } from '../sync';
import { useTirarParaRefrescar } from '../refresco';
import { TRAMOS, diasVencido, tramoDe, resumen, porCliente } from '../cartera';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Buscador from '../components/Buscador.vue';
import Persiana from '../components/Persiana.vue';
import TirarRefrescar from '../components/TirarRefrescar.vue';
import Vacio from '../components/Vacio.vue';

/*
 * La cartera: lo que cada cliente debe hoy.
 *
 * ## De dónde sale
 *
 * De `ventas.cartera`, que es la resta sobre la cuenta corriente **contable**
 * —`cwmovim` con su comprobante vigente— y no sobre el módulo de clientes: las
 * tablas `xw*` están vacías en cualquier instalación que facture desde
 * inventario. El teléfono no hace esa cuenta: baja el resultado como un
 * maestro más y lo guarda en IndexedDB, así que la cartera se mira sin señal.
 *
 * ## Por qué se agrupa por cliente
 *
 * Porque así se cobra. A nadie se le llama por una factura suelta: se le llama
 * y se le habla de todo lo que debe. Por eso la lista es de clientes, el
 * documento cuelga de cada uno, y el orden es por antigüedad y no por monto
 * — la pregunta de la mañana es a quién hay que llamar, no quién debe más.
 *
 * ## Esto no cobra todavía
 *
 * Es sólo lectura. Escribir el comprobante de ingreso es el paso siguiente, y
 * lo hace el servidor: el teléfono manda la forma de pago y no elige ninguna
 * cuenta contable, igual que no decide impuestos.
 */

const router = useRouter();

const documentos = ref([]);
/* La foto de la cartera entera: se calcula sobre el almacén y no sobre lo
   filtrado. Una cabecera que cambia con el filtro no es una foto, y sus
   tramos son además los botones del filtro. */
const total = ref({ documentos: 0, total: 0, atrasado: 0, sinFecha: 0, tramos: [] });
const nombres = ref({});
const busqueda = ref('');
const tramo = ref('');
const cargando = ref(true);
/* Cuántos hay en el almacén, sin filtro ninguno. Es lo que distingue «no hay
   ninguno» de «no hay ninguno con esto puesto», que no son lo mismo. */
const enAlmacen = ref(0);

const contenido = ref(null);
const refrescado = ref(null);
const errorRefresco = ref('');

const { distancia, refrescando, listo } = useTirarParaRefrescar(contenido, refrescar, conectado);

onMounted(async () => {
    await cargar();
    await leerRefrescado();
});

watch([busqueda, tramo], cargar);

async function cargar() {
    cargando.value = true;
    try {
        const todos = await idb.todos('cartera');
        enAlmacen.value = todos.length;
        total.value = resumen(todos);

        await cargarNombres(todos);

        const q = busqueda.value.trim().toLowerCase();

        documentos.value = todos
            .filter((d) => ! tramo.value || tramoDe(d)?.id === tramo.value)
            .filter((d) => ! q
                || String(d.numero).includes(q)
                || (d.glosa || '').toLowerCase().includes(q)
                || (d.cliente || '').toLowerCase().includes(q)
                || (nombres.value[d.cliente] || '').toLowerCase().includes(q));
    } finally {
        cargando.value = false;
    }
}

/** El código de auxiliar no le dice nada a nadie; el nombre sí. */
async function cargarNombres(docs) {
    const mapa = { ...nombres.value };

    for (const c of new Set(docs.map((d) => d.cliente).filter(Boolean))) {
        if (! mapa[c]) mapa[c] = (await idb.obtener('clientes', c))?.nombre || c;
    }

    nombres.value = mapa;
}

async function refrescar() {
    errorRefresco.value = '';
    try {
        await refrescarGrupo('cobranza');
        await cargar();
        await leerRefrescado();
    } catch (e) {
        errorRefresco.value = e.message;
    }
}

async function leerRefrescado() {
    refrescado.value = (await idb.estado('cartera'))?.sync_at || null;
}

const cuando = computed(() => {
    if (! refrescado.value) return 'Sin descargar';

    const d = new Date(refrescado.value);
    const hoy = new Date().toDateString() === d.toDateString();
    const hora = d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });

    return hoy ? `Hoy ${hora}` : `${d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit' })} ${hora}`;
});

const grupos = computed(() => porCliente(documentos.value));

const vacio = computed(() => ! cargando.value && ! documentos.value.length);

/** Cuán vieja es la deuda de un cliente, para la franja y la etiqueta. */
function tramoGrupo(g) {
    if (g.dias === null) return null;

    return TRAMOS.find((t) => g.dias >= t.desde) || null;
}

function edad(d) {
    const n = diasVencido(d);

    if (n === null) return 'Sin fecha';
    if (n > 0) return `${n} ${n === 1 ? 'día' : 'días'} de atraso`;
    if (n === 0) return 'Vence hoy';

    return `Vence en ${-n} ${n === -1 ? 'día' : 'días'}`;
}

function tipoDe(d) {
    return nombreDe('tipos_documento', d.tipo);
}
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <!-- Al panel, no «atrás»: se llega aquí desde las acciones rápidas
                 y volver al sitio de donde se vino es volver al panel. -->
            <button class="icono-barra" @click="router.replace('/inicio')" title="Volver al panel">
                <AppIcon name="atras" :size="24" />
            </button>
            <h1>Cobranza</h1>
        </div>

        <div class="contenido" ref="contenido">
            <TirarRefrescar :distancia="distancia" :refrescando="refrescando" :listo="listo"
                            que="la cartera" />

            <!-- Dos cifras y no seis: cuánto hay por cobrar y cuánto de eso ya
                 se pasó de fecha. El reparto por antigüedad está justo debajo,
                 y de paso es el filtro. -->
            <div class="kpis" v-if="total.documentos">
                <div class="kpi dinero">
                    <div class="dato">{{ monto(total.total) }}</div>
                    <div class="rotulo">
                        Por cobrar · {{ total.documentos }}
                        {{ total.documentos === 1 ? 'documento' : 'documentos' }}
                    </div>
                </div>
                <div class="kpi peligro">
                    <div class="dato">{{ monto(total.atrasado) }}</div>
                    <div class="rotulo">Vencido</div>
                </div>
            </div>

            <Buscador v-model="busqueda" placeholder="Cliente, número o glosa" />

            <div class="cuando-lista">
                <span>Actualizada: {{ cuando }}</span>
                <button class="actualizar-lista" :disabled="refrescando || ! conectado" @click="refrescar">
                    <AppIcon name="sincronizar" :size="15" color="currentColor" :class="{ girando: refrescando }" />
                    {{ conectado ? 'Actualizar' : 'Sin señal' }}
                </button>
            </div>

            <Aviso tipo="error" v-if="errorRefresco">{{ errorRefresco }}</Aviso>

            <!-- Un documento sin fecha no se puede clasificar, y eso se dice en
                 vez de meterlo callado en un tramo que no le toca. -->
            <Aviso tipo="info" v-if="total.sinFecha">
                Hay <b>{{ total.sinFecha }}</b>
                {{ total.sinFecha === 1 ? 'documento sin fecha' : 'documentos sin fecha' }}
                en la cuenta corriente: salen en la lista, pero sin antigüedad.
            </Aviso>

            <!-- Los tramos son el filtro, y sólo se dibujan los que tienen algo:
                 un botón que lleva a una lista vacía es un botón que estorba. -->
            <div class="pestanas en-linea" v-if="total.tramos.length > 1">
                <button :class="{ activa: tramo === '' }" @click="tramo = ''">Todo</button>
                <button v-for="t in total.tramos" :key="t.id"
                        :class="{ activa: tramo === t.id }" @click="tramo = t.id">
                    {{ t.rotulo }} ({{ t.n }})
                </button>
            </div>

            <Vacio v-if="vacio && ! enAlmacen" icono="cobranza" titulo="Nadie debe nada">
                Aquí aparece lo que los clientes tienen pendiente en su cuenta corriente,
                una vez que sincronices.
            </Vacio>

            <Vacio v-else-if="vacio && busqueda" icono="sinResultados" titulo="Ningún documento con eso">
                Se busca por cliente, por número de documento y por la glosa.
            </Vacio>

            <Vacio v-else-if="vacio" icono="alDia" titulo="Nada en ese tramo">
                Con el filtro puesto no queda ningún documento. Quítalo para ver la cartera entera.
            </Vacio>

            <!-- Una fila por cliente, que es como se cobra. La franja izquierda
                 dice cuán vieja es su deuda más antigua; la etiqueta lo repite
                 con palabras, porque el color nunca va solo. -->
            <div class="item" v-for="g in grupos" :key="g.cliente">
                <div class="item-estado" :class="tramoGrupo(g)?.color || 'gris'"></div>
                <div class="item-cuerpo">
                    <Persiana>
                        <template #cabecera>
                            <div class="item-titulo">
                                {{ nombres[g.cliente] || g.cliente }} · {{ monto(g.total) }}
                            </div>
                            <div class="item-meta">
                                <span class="etiqueta" :class="tramoGrupo(g)?.color || 'gris'">
                                    {{ tramoGrupo(g)?.rotulo || 'Sin fecha' }}
                                </span>
                                <span> · {{ g.documentos.length }}
                                    {{ g.documentos.length === 1 ? 'documento' : 'documentos' }}</span>
                            </div>
                        </template>

                        <div class="doc-cartera" v-for="d in g.documentos"
                             :key="`${d.tipo}-${d.numero}`">
                            <div class="texto">
                                <b>{{ tipoDe(d) }} Nº {{ d.numero }}</b>
                                <small>{{ fecha(d.vencimiento || d.emision) }} · {{ edad(d) }}</small>
                            </div>
                            <div class="cifra">{{ monto(d.saldo, d.moneda) }}</div>
                        </div>
                    </Persiana>
                </div>
            </div>

            <p class="ayuda centrado" v-if="grupos.length">
                Cobrar —escribir el comprobante de pago en contabilidad— llega en el paso siguiente.
            </p>
        </div>
    </div>
</template>
