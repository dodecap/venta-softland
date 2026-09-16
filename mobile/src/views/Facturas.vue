<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { idb } from '../idb';
import { monto, fecha } from '../catalogos';
import { estadoSii } from '../documentos';
import { facturasEmitidas } from '../saldo';
import { useAccionCrear } from '../crear';
import { conectado } from '../red';
import { refrescarGrupo } from '../sync';
import { useTirarParaRefrescar } from '../refresco';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Buscador from '../components/Buscador.vue';
import TirarRefrescar from '../components/TirarRefrescar.vue';
import Vacio from '../components/Vacio.vue';

/*
 * Lo emitido: facturas, boletas y notas de crédito.
 *
 * No es la cola de facturación —eso es «Facturar», que lleva a las notas de
 * venta con algo pendiente— sino el otro lado: lo que ya salió. Hasta ahora
 * cada factura sólo se veía desde la ficha de su nota de venta, y la que nace
 * sin nota de venta detrás no aparecía en ninguna parte.
 *
 * ## Dos estados que no son el mismo
 *
 * Un documento puede estar **escrito** en inventario y no haber viajado al SII,
 * y puede haber viajado y no tener todavía veredicto. Por eso la franja
 * derecha, que en las otras listas es la sincronización con el servidor, aquí
 * dice en qué quedó con el fisco: es lo único que no se arregla solo.
 */

const router = useRouter();

const documentos = ref([]);
const nombres = ref({});
const busqueda = ref('');
const filtro = ref('');
const cargando = ref(true);

const contenido = ref(null);
const refrescado = ref(null);
const errorRefresco = ref('');

const { distancia, refrescando, listo } = useTirarParaRefrescar(contenido, refrescar, conectado);

// La factura que no sale de ninguna nota de venta —el 88 % de las de
// NETDOMAIN— nace aquí, que es donde alguien está mirando lo emitido.
useAccionCrear('Factura sin nota de venta', () => router.push('/facturas/nueva'));

const TIPO = {
    F: { rotulo: 'Factura', color: 'verde' },
    B: { rotulo: 'Boleta', color: 'verde' },
    N: { rotulo: 'Nota de crédito', color: 'gris' },
};

/*
 * Los filtros son los tres estados por los que alguien viene a esta lista:
 * «¿qué falta mandar?», «¿qué anulé?» y el catálogo entero. No hay filtro por
 * tipo: para eso está buscar, y con dos tipos no compensa una pestaña.
 */
const FILTROS = {
    sin_enviar: (d) => ! d.anulada && ! d.track_id,
    anuladas: (d) => d.anulada || d.acreditada,
};

onMounted(async () => {
    await cargar();
    await leerRefrescado();
});

watch([busqueda, filtro], cargar);

async function cargar() {
    cargando.value = true;
    try {
        const q = busqueda.value.trim().toLowerCase();
        const todas = await facturasEmitidas();

        documentos.value = todas
            .filter((d) => (FILTROS[filtro.value] ? FILTROS[filtro.value](d) : true))
            .filter((d) => ! q
                || String(d.folio).includes(q)
                || (d.glosa || '').toLowerCase().includes(q)
                || (nombres.value[d.cliente] || d.cliente || '').toLowerCase().includes(q))
            .sort((a, b) => String(b.fecha).localeCompare(String(a.fecha)) || b.folio - a.folio);

        await cargarNombres();
    } finally {
        cargando.value = false;
    }
}

/** El código de cliente no le dice nada a nadie; el nombre sí. */
async function cargarNombres() {
    const mapa = { ...nombres.value };

    for (const c of new Set(documentos.value.map((d) => d.cliente).filter(Boolean))) {
        if (! mapa[c]) mapa[c] = (await idb.obtener('clientes', c))?.nombre || c;
    }

    nombres.value = mapa;
}

async function refrescar() {
    errorRefresco.value = '';
    try {
        await refrescarGrupo('facturas');
        await cargar();
        await leerRefrescado();
    } catch (e) {
        errorRefresco.value = e.message;
    }
}

async function leerRefrescado() {
    refrescado.value = (await idb.estado('facturas'))?.sync_at || null;
}

const cuando = computed(() => {
    if (! refrescado.value) return 'Sin descargar';

    const d = new Date(refrescado.value);
    const hoy = new Date().toDateString() === d.toDateString();
    const hora = d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });

    return hoy ? `Hoy ${hora}` : `${d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit' })} ${hora}`;
});

/** Cuántas quedaron escritas sin viajar al SII. Es la deuda de la lista. */
const sinEnviar = computed(() => documentos.value.filter(FILTROS.sin_enviar).length);

const vacio = computed(() => ! cargando.value && ! documentos.value.length);

function abrir(d) {
    router.push(`/facturas/${d.tipo}/${d.numero_interno}`);
}
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>Facturas</h1>
        </div>

        <div class="contenido" ref="contenido">
            <TirarRefrescar :distancia="distancia" :refrescando="refrescando" :listo="listo"
                            que="facturas" />

            <Buscador v-model="busqueda" placeholder="Folio, cliente o glosa" />

            <div class="cuando-lista">
                <span>Actualizada: {{ cuando }}</span>
                <button class="actualizar-lista" :disabled="refrescando || ! conectado" @click="refrescar">
                    <AppIcon name="sincronizar" :size="15" color="currentColor" :class="{ girando: refrescando }" />
                    {{ conectado ? 'Actualizar' : 'Sin señal' }}
                </button>
            </div>

            <Aviso tipo="error" v-if="errorRefresco">{{ errorRefresco }}</Aviso>

            <!-- Desde que emitir y enviar son un solo acto, esto es una avería,
                 no una tarea pendiente del día: o el SII no contestó, o el
                 documento salió del Softland de escritorio. Va arriba y en
                 rojo. -->
            <Aviso tipo="error" v-if="sinEnviar && filtro !== 'sin_enviar'">
                Hay <b>{{ sinEnviar }}</b> {{ sinEnviar === 1 ? 'documento escrito' : 'documentos escritos' }}
                que no {{ sinEnviar === 1 ? 'llegó' : 'llegaron' }} al SII. El servidor lo reintenta
                solo; desde la ficha se puede mandar ahora.
            </Aviso>

            <div class="pestanas en-linea">
                <button :class="{ activa: filtro === '' }" @click="filtro = ''">Todo</button>
                <button :class="{ activa: filtro === 'sin_enviar' }" @click="filtro = 'sin_enviar'">
                    Sin enviar
                </button>
                <button :class="{ activa: filtro === 'anuladas' }" @click="filtro = 'anuladas'">
                    Anuladas
                </button>
            </div>

            <Vacio v-if="vacio && filtro === 'sin_enviar'" icono="ok" titulo="Todo enviado al SII">
                No queda ningún documento escrito sin mandar al fisco.
            </Vacio>

            <Vacio v-else-if="vacio && busqueda" icono="sinResultados" titulo="Ningún documento con eso">
                Se busca por folio, por cliente y por la glosa.
            </Vacio>

            <Vacio v-else-if="vacio" icono="factura" titulo="Sin facturas">
                Aquí aparecen las de los últimos 12 meses, una vez que sincronices.
            </Vacio>

            <!-- Misma fila que en cotizaciones y notas de venta: título, cliente
                 y etiquetas. Franja izquierda el documento, franja derecha el
                 SII — son dos estados distintos y mezclarlos sería no poder ver
                 de lejos la factura que está impecable y no ha salido.

                 Va en un `div` y no en un `button`: el botón trae los estilos
                 del navegador —texto centrado, otra tipografía— y la fila se
                 veía como un recuadro de otra app. -->
            <div class="item" v-for="d in documentos" :key="`${d.tipo}-${d.numero_interno}`"
                 @click="abrir(d)">
                <div class="item-estado" :class="d.anulada || d.acreditada ? 'gris' : TIPO[d.tipo]?.color"></div>
                <div class="item-cuerpo">
                    <div class="item-titulo">
                        {{ TIPO[d.tipo]?.rotulo || d.tipo }} Nº {{ d.folio }}
                        · {{ monto(d.total, d.moneda) }}
                    </div>
                    <div class="item-linea">{{ nombres[d.cliente] || d.cliente }}</div>
                    <div class="item-meta">
                        <!-- El color nunca es la única señal: lo que dice la
                             franja lo dice también la etiqueta, con palabras. -->
                        <span class="etiqueta" :class="estadoSii(d).color">{{ estadoSii(d).rotulo }}</span>
                        <span v-if="d.anula_a" class="etiqueta gris">Anula la Nº {{ d.anula_a }}</span>
                        <span v-else-if="d.acreditada" class="etiqueta gris">
                            Anulada con la NC Nº {{ d.acreditada }}
                        </span>
                        <span v-else-if="d.anulada" class="etiqueta gris">Anulada</span>
                        <span> · {{ fecha(d.fecha) }}</span>
                        <span v-if="d.nota_venta"> · NV Nº {{ d.nota_venta }}</span>
                    </div>
                </div>
                <div class="item-sync" :class="estadoSii(d).color"></div>
            </div>
        </div>
    </div>
</template>
