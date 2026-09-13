<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { idb } from '../idb';
import { monto, fecha, nombre as nombreDe, simbolo } from '../catalogos';
import { TIPOS, estado, lineasDe, avanceFacturacion } from '../documentos';
import AppIcon from '../components/AppIcon.vue';
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

onMounted(cargar);
watch(numero, cargar);

async function cargar() {
    cargando.value = true;
    try {
        doc.value = await idb.obtener(def.value.almacen, numero.value);
        lineas.value = doc.value ? await lineasDe(tipo.value, numero.value) : [];
        cliente.value = doc.value ? await idb.obtener('clientes', doc.value.cliente) : null;
    } finally {
        cargando.value = false;
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
                        <span v-if="doc.oc" class="etiqueta gris">OC {{ doc.oc }}</span>
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
                        <div class="item-titulo">{{ l.detalle || l.producto }}</div>
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
    </div>
</template>
