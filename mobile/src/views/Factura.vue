<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { idb } from '../idb';
import { monto, fecha, nombre as nombreDe } from '../catalogos';
import { estadoSii as leerEstadoSii } from '../documentos';
import { facturasEmitidas } from '../saldo';
import { nuevoUuid } from '../pendientes';
import { compartirPdf, verPdf } from '../pdf';
import { conectado } from '../red';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Persiana from '../components/Persiana.vue';

/*
 * La ficha de un documento emitido.
 *
 * Lo normal es que aquí no haya nada que hacer: la factura se manda al SII en
 * el mismo acto en que se emite. «Enviar al SII» está para lo que no salió —el
 * SII caído, el documento escrito desde el Softland de escritorio— y para no
 * tener que esperar a la tarea que barre lo pendiente.
 *
 * Se lee **sin señal**: el documento y sus líneas están en el teléfono. Lo que
 * necesita red es actuar —enviar, preguntar, anular—, y cada botón lo dice.
 */

const route = useRoute();
const router = useRouter();

const tipo = computed(() => String(route.params.tipo || '').toUpperCase());
const numeroInterno = computed(() => Number(route.params.numeroInterno));

const doc = ref(null);
const lineas = ref([]);
const cliente = ref(null);
const usuario = ref(null);

const cargando = ref(true);
const error = ref('');
const aviso = ref('');
const trabajando = ref(false);
const estadoSii = ref(null);
const enviando = ref(false);
const anulando = ref(false);
const razonNc = ref('Anula Documento');

const TIPO = { F: 'Factura', B: 'Boleta', N: 'Nota de crédito' };

onMounted(async () => {
    usuario.value = await db.getUsuario();
    await cargar();
});

/**
 * Primero el teléfono, después el servidor.
 *
 * El almacén tiene el documento, sus líneas y en qué quedó con el SII, así que
 * la ficha abre sin red. Con señal se vuelve a preguntar, porque el estado ante
 * el SII cambia por su cuenta —el veredicto llega minutos después— y porque el
 * documento pudo tocarse desde el Softland de escritorio.
 */
async function cargar() {
    cargando.value = true;
    error.value = '';

    try {
        const guardado = (await facturasEmitidas())
            .find((d) => d.tipo === tipo.value && d.numero_interno === numeroInterno.value);

        if (guardado) {
            doc.value = guardado;
            lineas.value = await idb.porIndice(
                'factura_lineas', 'documento', [tipo.value, numeroInterno.value]
            );
            cliente.value = await idb.obtener('clientes', guardado.cliente);
        }

        if (conectado.value) {
            const r = await api.factura(tipo.value, numeroInterno.value);
            // Lo del servidor manda en los datos del documento, pero no en las
            // marcas que calcula el teléfono —anulada, aceptada, el folio que
            // la acredita—: ésas salen de cruzar tres almacenes y el servidor
            // no las manda en esta respuesta.
            doc.value = { ...(guardado || {}), ...r.documento };
            lineas.value = r.lineas;
            cliente.value = await idb.obtener('clientes', r.documento.cliente);
        } else if (! guardado) {
            error.value = 'Ese documento no está en el teléfono y no hay señal para traerlo.';
        }
    } catch (e) {
        if (! doc.value) error.value = e.message;
    } finally {
        cargando.value = false;
    }
}

const esNotaCredito = computed(() => tipo.value === 'N');
const anulada = computed(() => String(doc.value?.estado || '').trim().toUpperCase() === 'N');

// Quien puede emitir, puede mandar: son el mismo acto desde que la app manda
// sola al emitir. Separarlo sólo conseguía que la factura del vendedor se
// quedara esperando a que alguien de la oficina se acordara.
const puedeEnviarAlSii = computed(() => !! usuario.value);

/**
 * Mandar el documento al SII.
 *
 * Es lo más irreversible que hace la app: escrito en inventario un documento se
 * corrige, enviado ya existe para el fisco.
 */
async function enviarAlSii() {
    enviando.value = false;
    trabajando.value = true;
    error.value = '';

    try {
        const r = await api.enviarAlSii(tipo.value, numeroInterno.value);
        aviso.value = `Enviada al SII. TrackID ${r.track_id}. El veredicto tarda unos minutos.`;
        await cargar();
    } catch (e) {
        error.value = e.message;
    } finally {
        trabajando.value = false;
    }
}

/** En qué quedó el envío. Pregunta que se hace, no que se espera. */
async function consultarSii() {
    estadoSii.value = { cargando: true };

    try {
        estadoSii.value = await api.estadoSii(tipo.value, numeroInterno.value);
    } catch (e) {
        estadoSii.value = { message: e.message };
    }
}

/**
 * Anular: emitir la nota de crédito que devuelve la factura entera.
 *
 * Las líneas no se mandan, las arma el servidor desde la factura. Y gasta un
 * folio de nota de crédito, así que la hoja lo dice antes.
 */
async function anular() {
    anulando.value = false;
    trabajando.value = true;
    error.value = '';

    try {
        const r = await api.emitirNotaCredito(tipo.value, numeroInterno.value, razonNc.value, nuevoUuid());
        await idb.guardar('facturas', [r.documento]);
        await idb.guardar('factura_lineas', r.lineas || []);
        aviso.value = `Nota de crédito Nº ${r.documento.folio} emitida: esta factura queda anulada.`
            + (r.sii?.enviado ? ` Enviada al SII, TrackID ${r.sii.track_id}.` : '');
        await cargar();
    } catch (e) {
        error.value = e.message;
    } finally {
        trabajando.value = false;
    }
}

/*
 * El papel.
 *
 * No es el documento —el documento es el XML que aceptó el SII— sino lo que se
 * le entrega al cliente para que lo lea. Lo dibuja el servidor y el teléfono
 * guarda los bytes, así que la segunda vez se abre sin señal.
 *
 * El tipo que entiende `pdf.js` no es la letra de Softland: son nombres, y la
 * etiqueta es el folio, que es lo que el cliente busca en su correo.
 */
const TIPO_PAPEL = { F: 'factura', B: 'boleta', N: 'nota_credito' };

async function abrirPdf() {
    trabajando.value = true;
    error.value = '';

    try {
        await verPdf(TIPO_PAPEL[tipo.value], numeroInterno.value, { etiqueta: doc.value.folio });
    } catch (e) {
        error.value = e.message;
    } finally {
        trabajando.value = false;
    }
}

async function compartir() {
    trabajando.value = true;
    error.value = '';

    try {
        await compartirPdf(TIPO_PAPEL[tipo.value], numeroInterno.value, {
            etiqueta: doc.value.folio,
            cliente: cliente.value?.nombre,
            total: Math.abs(Number(doc.value.total || 0)),
            moneda: doc.value.moneda,
        });
    } catch (e) {
        error.value = e.message;
    } finally {
        trabajando.value = false;
    }
}

function cantidad(n) {
    return Number(n || 0).toLocaleString('es-CL', { maximumFractionDigits: 2 });
}
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>{{ TIPO[tipo] || 'Documento' }}<span v-if="doc"> Nº {{ doc.folio }}</span></h1>
        </div>

        <div class="contenido">
            <div class="cargando" v-if="cargando && ! doc">Cargando…</div>

            <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
            <Aviso tipo="ok" v-if="aviso">{{ aviso }}</Aviso>

            <template v-if="doc">
                <!-- El total y las etiquetas, como en la cotización y en la
                     nota de venta: es lo que se mira primero y es lo que se le
                     dice al cliente por teléfono. -->
                <div class="ficha">
                    <h2>{{ monto(Math.abs(doc.total), doc.moneda) }}</h2>
                    <div class="etiquetas">
                        <span class="etiqueta" :class="leerEstadoSii(doc).color">
                            {{ leerEstadoSii(doc).rotulo }}
                        </span>
                        <span class="etiqueta gris">{{ fecha(doc.fecha) }}</span>
                        <span class="etiqueta gris" v-if="anulada || doc.acreditada">Anulada</span>
                    </div>
                </div>

                <!-- Cerrada enseña lo que se busca —qué documento es y de quién
                     es—, y el resto se despliega. Es la misma persiana del
                     cliente en la cotización: los datos están en el teléfono, y
                     ocupar la pantalla con ellos empuja el detalle fuera de la
                     vista. -->
                <Persiana class="cliente-persiana">
                    <template #cabecera>
                        <span class="cliente-nombre">
                            {{ TIPO[tipo] }} Nº {{ doc.folio }} — {{ cliente?.nombre || doc.cliente }}
                        </span>
                    </template>
                    <div class="tarjeta-cuerpo datos">
                        <div v-if="cliente?.rut"><span>RUT</span><b>{{ cliente.rut }}</b></div>
                        <div v-if="cliente?.direccion"><span>Dirección</span><b>{{ cliente.direccion }}</b></div>
                        <div><span>Fecha</span><b>{{ fecha(doc.fecha) }}</b></div>
                        <div><span>Ante el SII</span><b>{{ leerEstadoSii(doc).rotulo }}</b></div>
                        <div v-if="doc.track_id"><span>TrackID</span><b>{{ doc.track_id }}</b></div>
                        <div v-if="doc.vendedor">
                            <span>Vendedor</span><b>{{ nombreDe('vendedores', doc.vendedor) }}</b>
                        </div>
                        <div v-if="doc.centro_costo">
                            <span>Centro de costo</span><b>{{ doc.centro_costo }}</b>
                        </div>
                        <div v-if="doc.condicion">
                            <span>Condición</span><b>{{ nombreDe('condiciones', doc.condicion) }}</b>
                        </div>
                        <div v-if="doc.glosa"><span>Glosa</span><b>{{ doc.glosa }}</b></div>
                        <div v-if="doc.anula_a">
                            <span>Anula</span><b>la factura Nº {{ doc.anula_a }}</b>
                        </div>
                    </div>
                    <button class="enlace cliente-ficha" v-if="cliente"
                            @click="router.push(`/clientes/${cliente.codigo}`)">
                        Ver ficha del cliente
                    </button>
                </Persiana>

                <!-- Lo primero, en qué quedó con el fisco. Un documento escrito
                     y sin enviar no existe para el SII, y eso pesa más que
                     cualquier dato del encabezado. -->
                <Aviso tipo="ok" v-if="doc.aceptada">
                    Aceptada por el SII.
                </Aviso>
                <Aviso tipo="info" v-else-if="doc.track_id && ! doc.motivo_sii">
                    Enviada al SII, todavía sin veredicto. TrackID <b>{{ doc.track_id }}</b>.
                </Aviso>
                <Aviso tipo="error" v-else-if="doc.motivo_sii">
                    <b>El SII la rechazó:</b> {{ doc.motivo_sii }}
                </Aviso>
                <!-- Desde que emitir y mandar son un solo acto, esto es una
                     avería y no un paso pendiente: o el SII no contestó, o el
                     documento salió del Softland de escritorio. -->
                <Aviso tipo="error" v-else-if="! anulada">
                    <b>Escrita, no llegó al SII.</b> Está en inventario y facturación con su folio,
                    pero para el fisco todavía no existe. El servidor lo reintenta solo.
                </Aviso>

                <Aviso tipo="info" v-if="doc.acreditada">
                    Anulada con la nota de crédito Nº <b>{{ doc.acreditada }}</b>.
                </Aviso>
                <Aviso tipo="info" v-else-if="anulada">
                    Este documento está anulado en el ERP.
                </Aviso>

                <!-- Todo lo que se puede hacer con el documento, arriba y en
                     una sola fila que se desplaza. Abajo obligaba a recorrer el
                     detalle entero para llegar a lo que se venía a hacer. -->
                <div class="acciones-doc">
                    <button class="chip-accion" :disabled="trabajando" @click="abrirPdf">
                        <AppIcon name="pdf" :size="17" color="currentColor" /> Ver el papel
                    </button>
                    <button class="chip-accion" :disabled="trabajando" @click="compartir">
                        <AppIcon name="compartir" :size="17" color="currentColor" /> Enviar al cliente
                    </button>
                    <button class="chip-accion fuerte" v-if="! doc.track_id && ! anulada && puedeEnviarAlSii"
                            :disabled="! conectado || trabajando" @click="enviando = true">
                        <AppIcon name="compartir" :size="17" color="currentColor" /> Enviar al SII
                    </button>
                    <button class="chip-accion" v-if="doc.track_id"
                            :disabled="! conectado" @click="consultarSii">
                        <AppIcon name="buzon" :size="17" color="currentColor" /> Ver qué dijo el SII
                    </button>
                    <button class="chip-accion peligro"
                            v-if="! esNotaCredito && ! anulada && ! doc.acreditada"
                            :disabled="! conectado || trabajando"
                            @click="anulando = true; razonNc = 'Anula Documento'">
                        <AppIcon name="anular" :size="17" color="currentColor" /> Anular
                    </button>
                </div>

                <p class="ayuda" v-if="! conectado">
                    Sin señal se puede leer, no actuar: enviar al SII y anular hablan con el
                    servidor.
                </p>

                <Aviso :tipo="estadoSii?.resuelto ? 'ok' : 'info'" v-if="estadoSii">
                    <template v-if="estadoSii.cargando">Preguntándole al SII…</template>
                    <template v-else-if="estadoSii.estado">
                        <b>{{ estadoSii.estado }}</b>
                        <span v-if="estadoSii.glosa"> — {{ estadoSii.glosa }}</span>
                        <span v-if="estadoSii.aceptados !== null && estadoSii.aceptados !== undefined">
                            · aceptados {{ estadoSii.aceptados }}, rechazados {{ estadoSii.rechazados }},
                            con reparos {{ estadoSii.reparos }}
                        </span>
                    </template>
                    <template v-else>{{ estadoSii.message }}</template>
                </Aviso>

                <!-- Totales y procedencia, en persiana y **encima** del
                     detalle: es donde los tienen la cotización y la nota de
                     venta. La tarjeta grande que decía «Nota de venta Nº 2065»
                     ocupaba media pantalla para decir un número; aquí es un
                     renglón, igual que el «Viene de» de la nota de venta. -->
                <div class="tarjeta datos-persiana">
                    <Persiana>
                        <template #cabecera>
                            Total <b>{{ monto(Math.abs(doc.total), doc.moneda) }}</b>
                        </template>
                        <div class="tarjeta-cuerpo datos">
                            <div><span>Neto afecto</span><b>{{ monto(Math.abs(doc.neto), doc.moneda) }}</b></div>
                            <div v-if="doc.exento">
                                <span>Exento</span><b>{{ monto(Math.abs(doc.exento), doc.moneda) }}</b>
                            </div>
                            <div><span>IVA</span><b>{{ monto(Math.abs(doc.iva), doc.moneda) }}</b></div>
                            <div class="fuerte">
                                <span>Total</span><b>{{ monto(Math.abs(doc.total), doc.moneda) }}</b>
                            </div>
                            <div v-if="doc.nota_venta"><span>Viene de</span>
                                <b><button class="enlace" @click="router.push(`/notas-venta/${doc.nota_venta}`)">
                                    Nota de venta {{ doc.nota_venta }}</button></b>
                            </div>
                        </div>
                    </Persiana>
                </div>

                <div class="seccion"><h2>Detalle</h2></div>

                <div class="linea-doc" v-for="l in lineas" :key="l.linea">
                    <div class="linea-cabecera">
                        <div>
                            <div class="item-titulo">{{ l.detalle || l.producto }}</div>
                            <div class="item-meta">
                                <span class="etiqueta gris">{{ l.producto }}</span>
                                <span> · {{ cantidad(l.cantidad) }} {{ nombreDe('unidades', l.unidad) }}
                                    × {{ monto(l.precio, doc.moneda) }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="linea-total">{{ monto(l.total, doc.moneda) }}</div>
                </div>

            </template>
        </div>

        <!-- Enviar al SII: lo único de la app que no se deshace de ninguna
             manera. Un documento escrito se corrige; uno enviado ya existe para
             el fisco y sólo se arregla con otro documento. -->
        <div class="velo" v-if="enviando" @click.self="enviando = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Enviar al SII</h2>
                    <button class="icono-barra" @click="enviando = false">
                        <AppIcon name="cerrar" :size="21" />
                    </button>
                </div>
                <div class="hoja-cuerpo">
                    <p>
                        Se manda al SII {{ esNotaCredito ? 'la nota de crédito' : 'la factura' }}
                        <b>Nº {{ doc?.folio }}</b> por <b>{{ monto(doc?.total, doc?.moneda) }}</b>.
                    </p>
                    <p class="ayuda">
                        Esto no se deshace. Y enviado no es aceptado: el veredicto del SII tarda
                        unos minutos y se consulta aparte.
                    </p>
                    <button class="boton" :disabled="trabajando" @click="enviarAlSii">
                        {{ trabajando ? 'Enviando…' : 'Enviar' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Anular: emite la nota de crédito que devuelve la factura entera y
             gasta un folio de nota de crédito. -->
        <div class="velo" v-if="anulando" @click.self="anulando = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Anular la factura</h2>
                    <button class="icono-barra" @click="anulando = false">
                        <AppIcon name="cerrar" :size="21" />
                    </button>
                </div>
                <div class="hoja-cuerpo">
                    <p>
                        Se emite una <b>nota de crédito</b> que devuelve entera la factura
                        Nº {{ doc?.folio }}. Gasta un folio de nota de crédito.
                    </p>
                    <label>Razón</label>
                    <input v-model="razonNc" maxlength="90">
                    <p class="ayuda">
                        Va en la referencia del DTE, que es donde el SII lee qué se anula.
                    </p>
                    <button class="boton peligro" :disabled="trabajando" @click="anular">
                        {{ trabajando ? 'Emitiendo…' : 'Emitir la nota de crédito' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
