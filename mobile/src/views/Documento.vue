<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { idb } from '../idb';
import { monto, fecha, nombre as nombreDe, simbolo } from '../catalogos';
import { TIPOS, estado, lineasDe, avanceFacturacion } from '../documentos';
import { conectado } from '../red';
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

// Las hojas de cerrar por pérdida y de anotar un seguimiento.
const perdiendo = ref(false);
const siguiendo = ref(false);
const formPerdida = ref({ motivo: '', observacion: '' });
const formSeguimiento = ref({ descripcion: '', proximo_contacto: '' });

useCapa(computed(() => perdiendo.value || siguiendo.value), () => {
    perdiendo.value = false;
    siguiendo.value = false;
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

/** En qué estados el documento todavía admite cambios. Igual que en el servidor. */
const editable = computed(() => ['N', 'P', ''].includes((doc.value?.estado || '').trim()));

const puedeConvertir = computed(() => esCotizacion.value && editable.value);

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
 * Manda la cotización al cliente con el PDF adjunto.
 *
 * Va aparte de guardar a propósito: una cotización se corrige tres veces antes
 * de mandarla, y un correo por cada guardado sería una plaga para el cliente.
 */
async function enviar() {
    if (! confirm('¿Enviar esta cotización al correo del cliente?')) return;
    await conServidor(async () => {
        const r = await api.enviarCotizacion(numero.value);
        aviso.value = r.message;
    });
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
 */
async function convertir() {
    await conServidor(async () => {
        const u = await db.getUsuario();
        const r = await api.convertirCotizacion(numero.value, {
            client_uuid: crypto.randomUUID?.() ?? `nv-${numero.value}-${Date.now()}`,
            cliente: doc.value.cliente,
            contacto: doc.value.contacto || null,
            moneda: doc.value.moneda,
            lista: doc.value.lista || null,
            condicion: doc.value.condicion || null,
            centro_costo: doc.value.centro_costo || u?.cod_cc || null,
            bodega: u?.cod_bode || null,
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
                <div class="acciones-doc" v-if="editable || puedeConvertir">
                    <button class="chip-accion" v-if="editable"
                            @click="router.push(`${def.ruta}/${numero}/editar`)">
                        <AppIcon name="configuracion" :size="17" color="currentColor" /> Corregir
                    </button>
                    <button class="chip-accion" v-if="esCotizacion" :disabled="! conectado || trabajando"
                            @click="enviar">
                        <AppIcon name="correo" :size="17" color="currentColor" /> Enviar al cliente
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
