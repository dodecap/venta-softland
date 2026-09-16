<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { idb } from '../idb';
import { monto, nombre as nombreDe } from '../catalogos';
import { calcularTotales } from '../documentos';
import { conectado } from '../red';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Buscador from '../components/Buscador.vue';
import Selector from '../components/Selector.vue';
import Vacio from '../components/Vacio.vue';

/*
 * Una factura sin nota de venta detrás.
 *
 * No es una versión degradada de la otra: es el mismo documento sin un origen
 * que le dicte los datos. Y es lo más común — en NETDOMAIN, **el 88 % de las
 * facturas y el 100 % de las boletas** nacieron así, y en INNOVAGES hay cinco,
 * dos de ellas a NETDOMAIN por el mismo concepto que se factura aquí.
 *
 * Lo que cambia respecto de facturar una nota de venta: aquí el precio **sí** lo
 * escribe quien factura, porque no hay documento anterior que lo mande, y las
 * líneas se eligen a mano. Lo que no cambia: gasta un folio, no se deshace, y
 * necesita señal.
 */

const router = useRouter();

const form = ref({
    cliente: '', vendedor: '', moneda: '01', centro_costo: null, condicion: null, glosa: '', lineas: [],
});
const cliente = ref(null);
const folios = ref(null);
const usuario = ref(null);
const uf = ref(null);

const cargando = ref(true);
const error = ref('');
const trabajando = ref(false);
const confirmando = ref(false);
const emitida = ref(null);

const eligiendoCliente = ref(false);
const busquedaCliente = ref('');
const clientesHallados = ref([]);

const eligiendoProducto = ref(false);
const busquedaProducto = ref('');
const productosHallados = ref([]);

onMounted(async () => {
    try {
        usuario.value = await db.getUsuario();
        form.value.centro_costo = usuario.value?.cod_cc || null;
        // Sin nota de venta detrás no hay de quién heredar el vendedor, así
        // que se elige. Se propone el propio, que es lo normal; un
        // administrador no tiene y tiene que decirlo.
        form.value.vendedor = usuario.value?.ven_cod || '';
        uf.value = Number((await db.getServidorInfo())?.uf) || null;
        folios.value = (await api.foliosFactura()).folios;
    } catch (e) {
        error.value = e.message;
    } finally {
        cargando.value = false;
    }
});

/*
 * Mismo guardián de turno que el editor: la carga inicial y lo que se escribe
 * compiten por la misma respuesta, y sin turno gana la que termina última, no
 * la que se pidió última.
 */
let turnoCliente = 0;

watch(busquedaCliente, async (q) => {
    const turno = ++turnoCliente;
    const filas = await idb.buscar('clientes', q, { limite: 30 });
    if (turno === turnoCliente) clientesHallados.value = filas;
});

async function abrirClientes() {
    eligiendoCliente.value = true;
    busquedaCliente.value = '';
    const turno = ++turnoCliente;
    const filas = await idb.buscar('clientes', '', { limite: 30 });
    if (turno === turnoCliente) clientesHallados.value = filas;
}

async function elegirCliente(codigo) {
    form.value.cliente = codigo;
    cliente.value = await idb.obtener('clientes', codigo);
    form.value.condicion = form.value.condicion || cliente.value?.condicion || null;
    eligiendoCliente.value = false;
}

let turnoProducto = 0;

watch(busquedaProducto, async (q) => {
    const turno = ++turnoProducto;
    const filas = await idb.buscar('productos', q, { limite: 30 });
    if (turno === turnoProducto) productosHallados.value = filas;
});

async function abrirProductos() {
    eligiendoProducto.value = true;
    busquedaProducto.value = '';
    const turno = ++turnoProducto;
    const filas = await idb.buscar('productos', '', { limite: 30 });
    if (turno === turnoProducto) productosHallados.value = filas;
}

function agregarProducto(p) {
    form.value.lineas.push({
        producto: p.codigo,
        nombre: p.nombre,
        glosa: p.nombre || '',
        unidad: p.unidad || '',
        afecto: !! p.afecto,
        cantidad: 1,
        precio: aPesos(p.precio, p.moneda),
        descuento_pct: 0,
    });

    eligiendoProducto.value = false;
}

/**
 * De la moneda del producto a la del documento.
 *
 * Sin forma de convertir se deja en cero: proponer un número inventado en un
 * documento tributario es peor que dejar el campo esperando.
 */
function aPesos(valor, monedaProducto) {
    const mp = (monedaProducto || '01').trim();

    if (mp === form.value.moneda) return Math.round(valor || 0);
    if (mp === '02' && uf.value) return Math.round((valor || 0) * uf.value);

    return 0;
}

const totales = computed(() => calcularTotales(form.value.lineas));
const sinFolios = computed(() => (folios.value?.libres ?? 0) <= 0);
const puedeEmitir = computed(
    () => !! form.value.cliente && !! form.value.vendedor && form.value.lineas.length > 0
        && form.value.lineas.every((l) => l.cantidad > 0)
        && ! sinFolios.value
);

async function emitir() {
    confirmando.value = false;
    trabajando.value = true;
    error.value = '';
    try {
        const r = await api.emitirFactura({
            receptor: form.value.cliente,
            vendedor: form.value.vendedor,
            centro_costo: form.value.centro_costo,
            condicion: form.value.condicion,
            glosa: form.value.glosa || null,
            lineas: form.value.lineas.map((l) => ({
                producto: l.producto,
                cantidad: l.cantidad,
                precio: l.precio,
                glosa: l.glosa || null,
            })),
        });

        emitida.value = r.documento;
        await idb.guardar('facturas', [r.documento]);
        await idb.guardar('factura_lineas', r.lineas || []);
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
            <h1>Nueva factura</h1>
        </div>

        <div class="contenido">
            <Aviso tipo="error" v-if="! conectado">
                Facturar necesita señal: el folio lo reparte Softland y el número tiene que ser el
                mismo para siempre desde que se emite.
            </Aviso>

            <div class="cargando" v-else-if="cargando">Cargando…</div>

            <!-- Emitida. El folio a la vista: es el dato que se le dice al
                 cliente y el que sirve para buscarla. -->
            <template v-else-if="emitida">
                <Aviso tipo="ok">
                    Factura <b>Nº {{ emitida.folio }}</b> emitida por
                    <b>{{ monto(emitida.total, emitida.moneda) }}</b>.
                </Aviso>
                <p class="ayuda">
                    Queda escrita en inventario y facturación. Enviarla al SII es un paso aparte, y
                    se hace desde la ficha del documento.
                </p>
                <button class="boton" @click="router.replace('/inicio')">Volver al panel</button>
            </template>

            <template v-else>
                <Aviso tipo="error" v-if="sinFolios">
                    <b>No quedan folios de factura.</b> Hay que pedirle un CAF nuevo al SII y
                    cargarlo en Softland.
                </Aviso>
                <Aviso tipo="info" v-else-if="folios && folios.libres <= 3">
                    Quedan <b>{{ folios.libres }}</b> {{ folios.libres === 1 ? 'folio' : 'folios' }}.
                    Ésta se llevaría el <b>Nº {{ folios.siguiente }}</b>.
                </Aviso>

                <Aviso tipo="error" v-if="error">{{ error }}</Aviso>

                <label>Cliente</label>
                <button class="campo-boton" @click="abrirClientes">
                    <span v-if="cliente">
                        {{ cliente.nombre }}
                        <small>{{ cliente.rut || cliente.codigo }}</small>
                    </span>
                    <span v-else class="hueco">Elegir cliente</span>
                </button>

                <label>Vendedor</label>
                <Selector v-model="form.vendedor" maestro="vendedores"
                          vacio="— elegir vendedor —" filtrar="Filtrar vendedores" />
                <p class="ayuda">
                    De quién es la venta. Al facturar una nota de venta esto no se pregunta —lo
                    hereda de ella—, pero aquí no hay documento anterior de dónde sacarlo, y una
                    factura sin vendedor no aparece en las búsquedas del ERP.
                </p>

                <label>Glosa</label>
                <input v-model="form.glosa" maxlength="200" placeholder="Lo que explica la factura">

                <div class="seccion">
                    <h2>Detalle</h2>
                    <button class="ver-todo" @click="abrirProductos">
                        Agregar producto <AppIcon name="crear" :size="15" color="currentColor" />
                    </button>
                </div>

                <Vacio v-if="! form.lineas.length" icono="producto" titulo="Todavía no hay productos">
                    Agrega el primero y el total se va armando solo.
                </Vacio>

                <div class="linea-doc" v-for="(l, i) in form.lineas" :key="i">
                    <div class="linea-cabecera">
                        <div>
                            <div class="item-titulo">{{ l.nombre }}</div>
                            <div class="item-meta">
                                <span class="etiqueta gris">{{ l.producto }}</span>
                                <span v-if="! l.afecto"> · exento</span>
                            </div>
                        </div>
                        <button class="icono-barra" title="Quitar" @click="form.lineas.splice(i, 1)">
                            <AppIcon name="borrar" :size="18" variant="peligro" />
                        </button>
                    </div>
                    <label class="linea-detalle">
                        <span>Detalle que ve el cliente</span>
                        <textarea v-model="l.glosa" rows="2" :placeholder="l.nombre"></textarea>
                    </label>
                    <div class="linea-campos">
                        <label>
                            <span>Cantidad</span>
                            <input v-model.number="l.cantidad" type="number" inputmode="decimal" min="0" step="any">
                        </label>
                        <label>
                            <span>Precio</span>
                            <input v-model.number="l.precio" type="number" inputmode="decimal" min="0" step="any">
                        </label>
                        <label>
                            <span>Desc. %</span>
                            <input v-model.number="l.descuento_pct" type="number" inputmode="decimal"
                                   min="0" max="100" step="any">
                        </label>
                    </div>
                    <div class="linea-total">
                        {{ cantidad(l.cantidad) }} {{ nombreDe('unidades', l.unidad) }}
                        · {{ monto(totales.lineas[i]?.total ?? 0, form.moneda) }}
                    </div>
                </div>

                <template v-if="form.lineas.length">
                    <div class="tarjeta">
                        <div class="tarjeta-cabecera">Totales</div>
                        <div class="tarjeta-cuerpo datos">
                            <div><span>Neto afecto</span><b>{{ monto(totales.afecto, form.moneda) }}</b></div>
                            <div v-if="totales.exento"><span>Exento</span><b>{{ monto(totales.exento, form.moneda) }}</b></div>
                            <div><span>IVA</span><b>{{ monto(totales.iva, form.moneda) }}</b></div>
                            <div class="fuerte"><span>Total</span><b>{{ monto(totales.total, form.moneda) }}</b></div>
                        </div>
                    </div>
                </template>

                <button class="boton" :disabled="! puedeEmitir || trabajando" @click="confirmando = true">
                    {{ trabajando ? 'Emitiendo…' : 'Emitir la factura' }}
                </button>
                <p class="ayuda centrado" v-if="! puedeEmitir && ! sinFolios">
                    <template v-if="! form.cliente">Falta elegir el cliente.</template>
                    <template v-else-if="! form.vendedor">Falta elegir el vendedor.</template>
                    <template v-else-if="! form.lineas.length">Falta agregar al menos un producto.</template>
                    <template v-else>Alguna línea va con cantidad cero.</template>
                </p>
            </template>
        </div>

        <!-- Elegir cliente -->
        <div class="velo" v-if="eligiendoCliente" @click.self="eligiendoCliente = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Cliente</h2>
                    <button class="icono-barra" @click="eligiendoCliente = false"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <Buscador v-model="busquedaCliente" placeholder="Nombre o RUT" />
                    <div class="item" v-for="c in clientesHallados" :key="c.codigo" @click="elegirCliente(c.codigo)">
                        <div class="item-estado cian"></div>
                        <div class="item-cuerpo">
                            <div class="item-titulo">{{ c.nombre }}</div>
                            <div class="item-meta"><span class="etiqueta gris">{{ c.rut || c.codigo }}</span></div>
                        </div>
                    </div>
                    <Vacio v-if="! clientesHallados.length" icono="sinResultados" titulo="Ningún cliente con eso">
                        Prueba con una palabra del nombre o con el RUT.
                    </Vacio>
                </div>
            </div>
        </div>

        <!-- Elegir producto -->
        <div class="velo" v-if="eligiendoProducto" @click.self="eligiendoProducto = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Producto</h2>
                    <button class="icono-barra" @click="eligiendoProducto = false"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <Buscador v-model="busquedaProducto" placeholder="Nombre, código o código de barras" />
                    <div class="item" v-for="p in productosHallados" :key="p.codigo" @click="agregarProducto(p)">
                        <div class="item-estado" :class="p.afecto ? 'cian' : 'amarillo'"></div>
                        <div class="item-cuerpo">
                            <div class="item-titulo item-titulo-producto">{{ p.nombre }}</div>
                            <div class="item-linea">{{ monto(p.precio, p.moneda) }} / {{ nombreDe('unidades', p.unidad) }}</div>
                            <div class="item-meta"><span class="etiqueta gris">{{ p.codigo }}</span></div>
                        </div>
                    </div>
                    <Vacio v-if="! productosHallados.length" icono="sinResultados" titulo="Ningún producto con eso">
                        Prueba con una palabra del nombre o con el código.
                    </Vacio>
                </div>
            </div>
        </div>

        <!-- La confirmación nombra el folio que va a gastar. -->
        <div class="velo" v-if="confirmando" @click.self="confirmando = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Emitir la factura</h2>
                    <button class="icono-barra" @click="confirmando = false"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <p>
                        Se emite la factura <b>Nº {{ folios?.siguiente }}</b> por
                        <b>{{ monto(totales.total, form.moneda) }}</b> a {{ cliente?.nombre }}.
                    </p>
                    <p class="ayuda">
                        Un folio emitido no se devuelve. Lo que salga mal se corrige con una nota de
                        crédito, no borrando.
                    </p>
                    <button class="boton" :disabled="trabajando" @click="emitir">
                        {{ trabajando ? 'Emitiendo…' : 'Emitir' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
