<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { idb } from '../idb';
import { monto, fecha, nombre as nombreDe, simbolo } from '../catalogos';
import { TIPOS, estado, lineasDe, avanceFacturacion } from '../documentos';
import { conectado } from '../red';
import { compartirPdf, olvidarPdf, pdfGuardado, verPdf } from '../pdf';
import { useCapa } from '../nav';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Selector from '../components/Selector.vue';
import Vacio from '../components/Vacio.vue';

/*
 * Un documento con su detalle. Todo de IndexedDB: en terreno esta pantalla es
 * la que se le muestra al cliente, y ahí no se puede depender de la señal.
 */

const route = useRoute();
const router = useRouter();

const tipo = computed(() => route.meta.tipo);
const def = computed(() => TIPOS[tipo.value]);
const numero = computed(() => Number(route.params.numero));

const doc = ref(null);
const lineas = ref([]);
const cliente = ref(null);
const cargando = ref(true);
const seguimientos = ref([]);
const aprobacion = ref(null);
const error = ref('');
const aviso = ref('');
const trabajando = ref(false);

// Si el PDF ya está en el teléfono, verlo y mandarlo funcionan sin señal.
const papelGuardado = ref(null);

// Las hojas de cerrar por pérdida, anotar un seguimiento y eliminar.
const perdiendo = ref(false);
const siguiendo = ref(false);
const borrando = ref(false);
const formPerdida = ref({ motivo: '', observacion: '' });
const formSeguimiento = ref({ descripcion: '', proximo_contacto: '' });

// Por qué el servidor no dejó eliminar. Sólo él lo sabe: en el teléfono no
// está si el documento se facturó, si generó picking o si ya salió al cliente.
const impedimentos = ref([]);

useCapa(computed(() => perdiendo.value || siguiendo.value || borrando.value), () => {
    perdiendo.value = false;
    siguiendo.value = false;
    borrando.value = false;
});

onMounted(cargar);
watch(numero, cargar);

async function cargar() {
    cargando.value = true;
    error.value = '';
    try {
        doc.value = await idb.obtener(def.value.almacen, numero.value);
        lineas.value = doc.value ? await lineasDe(tipo.value, numero.value) : [];
        cliente.value = doc.value ? await idb.obtener('clientes', doc.value.cliente) : null;
        papelGuardado.value = doc.value ? await pdfGuardado(tipo.value, numero.value) : null;
        await refrescarDelServidor();
    } finally {
        cargando.value = false;
    }
}

/**
 * Lo que solo está en el servidor: los seguimientos de una cotización y el
 * estado de la aprobación de una nota de venta. No se descargan al teléfono
 * porque no se consultan en terreno, se consultan cuando se está decidiendo.
 */
async function refrescarDelServidor() {
    if (! conectado.value || ! doc.value) return;
    try {
        if (esCotizacion.value) {
            seguimientos.value = (await api.cotizacion(numero.value)).seguimientos ?? [];
        } else {
            aprobacion.value = (await api.notaVenta(numero.value)).aprobacion ?? null;
        }
    } catch { /* sin conexión al servidor se muestra lo que hay en el teléfono */ }
}

const esCotizacion = computed(() => tipo.value === 'cotizacion');

/**
 * En qué estados el documento todavía admite cambios. Igual que en el servidor
 * (`CotizacionController::EDITABLES`).
 *
 * Sólo el pendiente. Ojo: `N` **no** entra, porque en Softland es «nula».
 */
const editable = computed(() => ['P', ''].includes((doc.value?.estado || '').trim()));

const puedeConvertir = computed(() => esCotizacion.value && editable.value);

/**
 * Anular deja el documento donde está, con su número, fuera de juego. Sólo
 * desde pendiente: lo que ya se convirtió, se perdió, se aprobó o se concluyó
 * tuvo un desenlace, y anularlo lo borraría de la historia en vez de cerrarlo.
 */
const anulable = computed(() => editable.value);

/**
 * Eliminar borra la fila de Softland. Aquí sólo se sabe lo que se ve — que el
 * documento esté pendiente o ya anulado — y con eso basta para no ofrecer el
 * botón donde seguro no va a funcionar. El resto de las condiciones las
 * contesta el servidor, que es el único que sabe si esto se facturó, si generó
 * picking o si el PDF ya salió al cliente.
 */
const borrable = computed(() => ['P', 'N', ''].includes((doc.value?.estado || '').trim()));

async function perder() {
    if (! formPerdida.value.motivo) return;
    await conServidor(async () => {
        const r = await api.perderCotizacion(numero.value, formPerdida.value);
        await idb.guardar(def.value.almacen, [JSON.parse(JSON.stringify(r.cotizacion))]);
        perdiendo.value = false;
        aviso.value = 'Cotización cerrada como perdida.';
        await cargar();
    });
}

/**
 * Manda el documento al correo del cliente, con el PDF adjunto.
 *
 * Va aparte de guardar a propósito: un documento se corrige tres veces antes de
 * mandarlo, y un correo por cada guardado sería una plaga para el cliente.
 */
async function enviarPorCorreo() {
    if (! confirm(`¿Enviar ${esCotizacion.value ? 'esta cotización' : 'esta nota de venta'} al correo del cliente?`)) return;
    await conServidor(async () => {
        const r = esCotizacion.value
            ? await api.enviarCotizacion(numero.value)
            : await api.enviarNotaVenta(numero.value);
        aviso.value = r.message;
        papelGuardado.value = await pdfGuardado(tipo.value, numero.value);
    });
}

/** Abre el PDF con el visor del teléfono. Con copia guardada, sin señal también. */
async function verPapel() {
    error.value = '';
    trabajando.value = true;
    try {
        await verPdf(tipo.value, numero.value);
        papelGuardado.value = await pdfGuardado(tipo.value, numero.value);
    } catch (e) {
        error.value = e.message;
    } finally {
        trabajando.value = false;
    }
}

/**
 * Manda el documento por WhatsApp.
 *
 * Son dos pasos y es culpa de WhatsApp, no de la app: un enlace `wa.me` sólo
 * lleva texto, así que el archivo se entrega por la hoja de compartir de
 * Android y ahí el vendedor elige el chat. El mensaje ya va escrito.
 */
async function compartirPapel() {
    error.value = '';
    trabajando.value = true;
    try {
        const r = await compartirPdf(tipo.value, numero.value, {
            cliente: cliente.value?.nombre,
            total: doc.value?.total,
            moneda: simbolo(doc.value?.moneda),
        });
        papelGuardado.value = await pdfGuardado(tipo.value, numero.value);

        // El servidor no ve salir esto: el acuse es lo que deja la emisión
        // marcada como entregada, y con eso una corrección posterior genera una
        // versión nueva en vez de pisar la que tiene el cliente.
        if (r.compartido && conectado.value) {
            try {
                await api.marcarCompartido(tipo.value, numero.value, 'whatsapp');
            } catch { /* el acuse no puede tumbar un envío que ya salió */ }
        }
        aviso.value = r.compartido ? 'Documento entregado a la app que elegiste.' : 'Documento descargado.';
    } catch (e) {
        error.value = e.message;
    } finally {
        trabajando.value = false;
    }
}

async function anular() {
    const que = esCotizacion.value ? 'esta cotización' : 'esta nota de venta';
    if (! confirm(`¿Anular ${que}? Se queda en Softland con su número, pero deja de contar.`)) return;

    await conServidor(async () => {
        const r = esCotizacion.value
            ? await api.anularCotizacion(numero.value)
            : await api.anularNotaVenta(numero.value);
        await idb.guardar(def.value.almacen, [JSON.parse(JSON.stringify(r.cotizacion ?? r.nota_venta))]);
        aviso.value = 'Anulada en Softland.';
        await cargar();
    });
}

/**
 * Eliminar de verdad. Es irreversible y **el número vuelve al pozo**: el
 * correlativo de Softland es el máximo más uno, así que el siguiente documento
 * que se cree puede quedarse con él.
 *
 * Si el servidor dice que no, devuelve las razones en vez de un mensaje suelto,
 * y se muestran todas: al vendedor le sirve saber de una vez qué estorba.
 */
async function eliminar() {
    trabajando.value = true;
    error.value = '';
    impedimentos.value = [];

    try {
        if (esCotizacion.value) {
            await api.eliminarCotizacion(numero.value);
        } else {
            await api.eliminarNotaVenta(numero.value);
        }

        // Del teléfono también: la ficha, sus líneas y el PDF guardado. Si no,
        // el documento sigue apareciendo en la lista hasta la próxima descarga
        // completa, y el papel se abriría sin nada detrás.
        await olvidarPdf(tipo.value, numero.value);
        for (const l of lineas.value) {
            await idb.borrar(def.value.lineas, [numero.value, l.linea]);
        }
        await idb.borrar(def.value.almacen, numero.value);

        borrando.value = false;
        router.replace(def.value.ruta);
    } catch (e) {
        impedimentos.value = e?.datos?.razones ?? [];
        if (! impedimentos.value.length) {
            error.value = e.message;
            borrando.value = false;
        }
    } finally {
        trabajando.value = false;
    }
}

async function anotarSeguimiento() {
    if (! formSeguimiento.value.descripcion.trim()) return;
    await conServidor(async () => {
        const r = await api.seguirCotizacion(numero.value, formSeguimiento.value);
        seguimientos.value = r.seguimientos ?? [];
        formSeguimiento.value = { descripcion: '', proximo_contacto: '' };
        siguiendo.value = false;
        aviso.value = 'Seguimiento anotado.';
    });
}

/**
 * Convertir en nota de venta.
 *
 * Se manda el mismo detalle que tiene la cotización: el vendedor puede
 * corregirlo después en la nota de venta, pero convertir no debe ser una
 * ocasión de volver a teclear diez líneas.
 *
 * Lo que se arrastra de la cotización es todo lo que el cliente ya aceptó: el
 * vendedor a cuyo nombre está, la fecha de entrega pactada y el texto de cada
 * línea. Volver a deducirlos del usuario que aprieta el botón cambiaría el
 * documento sin que nadie lo haya pedido.
 */
async function convertir() {
    await conServidor(async () => {
        const u = await db.getUsuario();
        const r = await api.convertirCotizacion(numero.value, {
            client_uuid: crypto.randomUUID?.() ?? `nv-${numero.value}-${Date.now()}`,
            cliente: doc.value.cliente,
            vendedor: doc.value.vendedor || u?.ven_cod || null,
            contacto: doc.value.contacto || null,
            moneda: doc.value.moneda,
            lista: doc.value.lista || null,
            condicion: doc.value.condicion || null,
            centro_costo: doc.value.centro_costo || u?.cod_cc || null,
            bodega: u?.cod_bode || null,
            fecha_entrega: (doc.value.fecha_entrega || '').slice(0, 10) || null,
            oc: doc.value.oc && doc.value.oc !== '0' ? doc.value.oc : null,
            observacion: doc.value.observacion || null,
            lineas: lineas.value.map((l) => ({
                producto: l.producto,
                detalle: l.detalle || null,
                unidad: l.unidad || null,
                cantidad: l.cantidad,
                precio: l.unitario,
                descuento_pct: l.cantidad && l.precio
                    ? Math.round((l.descuento || 0) * 10000 / (l.cantidad * l.precio * (l.equiv || 1))) / 100
                    : 0,
            })),
        });
        const plano = JSON.parse(JSON.stringify(r));
        await idb.guardar('notas_venta', [plano.nota_venta]);
        await idb.guardar('nota_venta_lineas', plano.lineas || []);
        router.push(`/notas-venta/${r.nota_venta.numero}`);
    });
}

async function conServidor(fn) {
    trabajando.value = true;
    error.value = '';
    aviso.value = '';
    try {
        await fn();
    } catch (e) {
        // Un 409 con el número de la NV es «ya estaba convertida»: se lleva ahí.
        if (e.status === 409 && e.datos?.nota_venta) {
            router.push(`/notas-venta/${e.datos.nota_venta}`);
            return;
        }
        error.value = e.message;
    } finally {
        trabajando.value = false;
    }
}

const avance = computed(() => (tipo.value === 'nota_venta' ? avanceFacturacion(lineas.value) : null));

/** Cantidades: Softland las guarda como float y «2» no se escribe «2,00». */
function cantidad(n) {
    return Number(n || 0).toLocaleString('es-CL', { maximumFractionDigits: 2 });
}
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>{{ def.singular }} Nº {{ numero }}</h1>
        </div>

        <div class="contenido">
            <div class="cargando" v-if="cargando">Cargando…</div>

            <Vacio v-else-if="! doc" icono="sinResultados" :titulo="`No tenemos la ${def.singular.toLowerCase()} ${numero}`">
                Puede ser de otro vendedor, o de antes de los últimos 12 meses.
            </Vacio>

            <template v-else>
                <div class="ficha">
                    <h2>{{ monto(doc.total, doc.moneda) }}</h2>
                    <div class="sub">
                        <button class="enlace" v-if="cliente" @click="router.push(`/clientes/${cliente.codigo}`)">
                            {{ cliente.nombre }}
                        </button>
                        <span v-else>Cliente {{ doc.cliente }}</span>
                    </div>
                    <div class="etiquetas">
                        <span class="etiqueta" :class="estado(tipo, doc.estado).color === 'rojo' ? 'roja'
                              : estado(tipo, doc.estado).color === 'verde' ? 'verde' : ''">
                            {{ estado(tipo, doc.estado).rotulo }}
                        </span>
                        <span class="etiqueta gris">{{ fecha(doc.fecha) }}</span>
                        <!-- `numOC` es NOT NULL en Softland con cero por defecto:
                             un «OC 0» no es una orden de compra, es el hueco. -->
                        <span v-if="doc.oc && doc.oc !== '0'" class="etiqueta gris">OC {{ doc.oc }}</span>
                    </div>
                </div>

                <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
                <Aviso tipo="ok" v-if="aviso">{{ aviso }}</Aviso>

                <!-- Lo que se puede hacer con este documento, y solo lo que se
                     puede: un botón que va a responder «ya no se puede» es peor
                     que no tener el botón. -->
                <!-- El papel. Va en su propia fila y siempre visible: ver o
                     mandar el documento no depende de que todavía se pueda
                     corregir, y con el PDF ya guardado tampoco de la señal. -->
                <div class="acciones-doc">
                    <button class="chip-accion" :disabled="trabajando || (! conectado && ! papelGuardado)"
                            @click="verPapel">
                        <AppIcon name="pdf" :size="17" color="currentColor" /> Ver el documento
                    </button>
                    <button class="chip-accion" :disabled="trabajando || (! conectado && ! papelGuardado)"
                            @click="compartirPapel">
                        <AppIcon name="compartir" :size="17" color="currentColor" /> Enviar por WhatsApp
                    </button>
                    <button class="chip-accion" :disabled="! conectado || trabajando"
                            @click="enviarPorCorreo">
                        <AppIcon name="correo" :size="17" color="currentColor" /> Enviar por correo
                    </button>
                </div>
                <p class="ayuda" v-if="! conectado && ! papelGuardado">
                    El documento se dibuja en el servidor. Ábrelo una vez con señal y después queda en el teléfono.
                </p>

                <div class="acciones-doc" v-if="editable || puedeConvertir">
                    <button class="chip-accion" v-if="editable"
                            @click="router.push(`${def.ruta}/${numero}/editar`)">
                        <AppIcon name="configuracion" :size="17" color="currentColor" /> Corregir
                    </button>
                    <button class="chip-accion" v-if="esCotizacion" :disabled="! conectado"
                            @click="siguiendo = true">
                        <AppIcon name="buzon" :size="17" color="currentColor" /> Seguimiento
                    </button>
                    <button class="chip-accion" v-if="esCotizacion && editable" :disabled="! conectado"
                            @click="perdiendo = true">
                        <AppIcon name="error" :size="17" color="currentColor" /> Perdida
                    </button>
                    <button class="chip-accion fuerte" v-if="puedeConvertir"
                            :disabled="! conectado || trabajando" @click="convertir">
                        <AppIcon name="notaVenta" :size="17" color="currentColor" /> Pasar a nota de venta
                    </button>
                </div>
                <p class="ayuda" v-if="! conectado && (editable || puedeConvertir)">
                    Sin señal solo se puede mirar: cambiar un documento que ya está en Softland necesita red.
                </p>

                <!-- Anular y eliminar. Aparte del resto a propósito: son las
                     dos acciones que no se deshacen. -->
                <div class="acciones-doc riesgo" v-if="anulable || borrable">
                    <button class="chip-accion peligro" v-if="anulable" :disabled="! conectado || trabajando"
                            @click="anular">
                        <AppIcon name="anular" :size="17" color="currentColor" /> Anular
                    </button>
                    <button class="chip-accion peligro" v-if="borrable" :disabled="! conectado || trabajando"
                            @click="impedimentos = []; borrando = true">
                        <AppIcon name="borrar" :size="17" color="currentColor" /> Eliminar
                    </button>
                </div>

                <!-- La aprobación del jefe no existe en Softland: la pone la app
                     cuando la venta pasa el tope del vendedor. -->
                <div class="tarjeta" v-if="aprobacion">
                    <div class="tarjeta-cabecera">Aprobación</div>
                    <div class="tarjeta-cuerpo datos">
                        <div><span>Estado</span><b>{{ aprobacion.estado }}</b></div>
                        <div><span>Motivo</span><b>{{ aprobacion.motivo }}</b></div>
                        <div v-if="aprobacion.comentario"><span>Comentario</span><b>{{ aprobacion.comentario }}</b></div>
                    </div>
                </div>

                <!-- El avance real de una NV se lee línea por línea: los flags del
                     encabezado están en 0 en las 800 notas de venta de INNOVAGES. -->
                <div class="tarjeta" v-if="avance">
                    <div class="tarjeta-cabecera">Facturación</div>
                    <div class="tarjeta-cuerpo">
                        <div class="progreso"><div class="relleno" :style="{ width: avance.pct + '%' }"></div></div>
                        <p class="ayuda">
                            {{ avance.completo ? 'Facturada por completo.'
                               : `Facturado ${cantidad(avance.facturado)} de ${cantidad(avance.pedido)} (${avance.pct} %).` }}
                        </p>
                    </div>
                </div>

                <div class="tarjeta">
                    <div class="tarjeta-cabecera">Datos</div>
                    <div class="tarjeta-cuerpo datos">
                        <div v-if="doc.contacto"><span>Contacto</span><b>{{ doc.contacto }}</b></div>
                        <div v-if="doc.vendedor"><span>Vendedor</span><b>{{ nombreDe('vendedores', doc.vendedor) }}</b></div>
                        <div v-if="doc.condicion"><span>Condición</span><b>{{ nombreDe('condiciones_venta', doc.condicion) }}</b></div>
                        <div v-if="doc.centro_costo"><span>Centro de costo</span><b>{{ nombreDe('centros_costo', doc.centro_costo) }}</b></div>
                        <div v-if="doc.bodega"><span>Bodega</span><b>{{ nombreDe('bodegas', doc.bodega) }}</b></div>
                        <div v-if="doc.fecha_entrega"><span>Entrega</span><b>{{ fecha(doc.fecha_entrega) }}</b></div>
                        <div v-if="doc.cotizacion"><span>Viene de</span>
                            <b><button class="enlace" @click="router.push(`/cotizaciones/${doc.cotizacion}`)">
                                Cotización {{ doc.cotizacion }}</button></b>
                        </div>
                        <div v-if="doc.observacion"><span>Observación</span><b>{{ doc.observacion }}</b></div>
                    </div>
                </div>

                <div class="seccion">
                    <h2>Detalle</h2>
                    <span class="sub">{{ lineas.length }} {{ lineas.length === 1 ? 'línea' : 'líneas' }}</span>
                </div>

                <div class="item" v-for="l in lineas" :key="l.linea">
                    <div class="item-estado cian"></div>
                    <div class="item-cuerpo">
                        <div class="item-titulo">{{ l.nombre }}</div>
                        <div class="item-linea">
                            {{ cantidad(l.cantidad) }} {{ nombreDe('unidades', l.unidad) }}
                            × {{ monto(l.unitario, doc.moneda) }} = {{ monto(l.total, doc.moneda) }}
                        </div>
                        <div class="item-meta">
                            <span class="etiqueta gris">{{ l.producto }}</span>
                            <span v-if="l.moneda_origen !== null">
                                · {{ cantidad(l.precio) }} <template v-if="l.moneda_origen">{{ simbolo(l.moneda_origen) }}</template>
                                a {{ monto(l.equiv, doc.moneda) }}
                            </span>
                            <span v-if="l.descuento"> · desc. {{ monto(l.descuento, doc.moneda) }}</span>
                            <span v-if="l.facturado"> · facturado {{ cantidad(l.facturado) }}</span>
                        </div>
                    </div>
                </div>

                <template v-if="esCotizacion && seguimientos.length">
                    <div class="seccion">
                        <h2>Seguimiento</h2>
                        <span class="sub">{{ seguimientos.length }}</span>
                    </div>
                    <div class="item" v-for="s in seguimientos" :key="s.numero">
                        <div class="item-estado amarillo"></div>
                        <div class="item-cuerpo">
                            <div class="item-titulo">{{ s.descripcion }}</div>
                            <div class="item-meta">
                                <span class="etiqueta gris">{{ fecha(s.fecha) }}</span>
                                <span v-if="s.contacto"> · {{ s.contacto }}</span>
                                <span v-if="s.proximo_contacto"> · vuelve el {{ fecha(s.proximo_contacto) }}</span>
                            </div>
                        </div>
                    </div>
                </template>

                <div class="tarjeta">
                    <div class="tarjeta-cabecera">Totales</div>
                    <div class="tarjeta-cuerpo datos">
                        <div><span>Neto afecto</span><b>{{ monto(doc.neto, doc.moneda) }}</b></div>
                        <div v-if="doc.exento"><span>Exento</span><b>{{ monto(doc.exento, doc.moneda) }}</b></div>
                        <div v-if="doc.descuento"><span>Descuentos</span><b>{{ monto(doc.descuento, doc.moneda) }}</b></div>
                        <div v-if="doc.flete"><span>Flete</span><b>{{ monto(doc.flete, doc.moneda) }}</b></div>
                        <div v-if="doc.embalaje"><span>Embalaje</span><b>{{ monto(doc.embalaje, doc.moneda) }}</b></div>
                        <div class="fuerte"><span>Total</span><b>{{ monto(doc.total, doc.moneda) }}</b></div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Cerrar por pérdida -->
        <div class="velo" v-if="perdiendo" @click.self="perdiendo = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Cerrar como perdida</h2>
                    <button class="icono-barra" @click="perdiendo = false"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <p class="ayuda">
                        Queda cerrada en Softland con su motivo. Después no se puede corregir ni convertir.
                    </p>

                    <label>Motivo</label>
                    <Selector v-model="formPerdida.motivo" maestro="motivos_perdida" :vacio="null" />

                    <label>Qué pasó</label>
                    <textarea v-model="formPerdida.observacion" rows="3"
                              placeholder="Lo que sirva para la próxima"></textarea>

                    <button class="boton peligro" :disabled="! formPerdida.motivo || trabajando" @click="perder">
                        {{ trabajando ? 'Cerrando…' : 'Cerrar como perdida' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Eliminar de Softland -->
        <div class="velo" v-if="borrando" @click.self="borrando = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Eliminar de Softland</h2>
                    <button class="icono-barra" @click="borrando = false"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <Aviso v-if="impedimentos.length" tipo="error">
                        <ul class="motivos">
                            <li v-for="(m, i) in impedimentos" :key="i">{{ m }}</li>
                        </ul>
                    </Aviso>

                    <template v-else>
                        <p class="ayuda">
                            {{ esCotizacion ? 'La cotización' : 'La nota de venta' }} N° {{ numero }} desaparece de
                            Softland con todo su detalle. No se puede deshacer, y el número queda libre: el
                            siguiente documento que se cree puede quedarse con él.
                        </p>
                        <p class="ayuda">
                            Si ya salió al cliente, anúlala en vez de eliminarla: así conserva su número.
                        </p>

                        <button class="boton peligro" :disabled="trabajando" @click="eliminar">
                            {{ trabajando ? 'Eliminando…' : 'Eliminar definitivamente' }}
                        </button>
                    </template>

                    <button class="boton-texto peligro" v-if="anulable" @click="borrando = false; anular()">
                        Anular en vez de eliminar
                    </button>
                </div>
            </div>
        </div>

        <!-- Anotar un seguimiento -->
        <div class="velo" v-if="siguiendo" @click.self="siguiendo = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Seguimiento</h2>
                    <button class="icono-barra" @click="siguiendo = false"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <label>Qué se hizo</label>
                    <textarea v-model="formSeguimiento.descripcion" rows="3"
                              placeholder="Llamada, visita, correo…"></textarea>

                    <label>Próximo contacto</label>
                    <input v-model="formSeguimiento.proximo_contacto" type="date" class="angosto">

                    <button class="boton" :disabled="! formSeguimiento.descripcion.trim() || trabajando"
                            @click="anotarSeguimiento">
                        {{ trabajando ? 'Guardando…' : 'Anotar' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
