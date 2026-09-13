<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ErrorApi } from '../api';
import { db } from '../db';
import { idb } from '../idb';
import { monto, nombre as nombreDe } from '../catalogos';
import { calcularTotales, cuerpoDe, TIPOS } from '../documentos';
import { conectado } from '../red';
import { encolar, nuevoUuid } from '../pendientes';
import { useCapa } from '../nav';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Buscador from '../components/Buscador.vue';
import Selector from '../components/Selector.vue';
import Vacio from '../components/Vacio.vue';

/*
 * Escribir una cotización o una nota de venta. La misma pantalla para las dos:
 * son el mismo documento en dos momentos de su vida y se llenan igual.
 *
 * ## El precio se escribe en la moneda del documento
 *
 * El vendedor negocia en pesos, aunque el producto esté tarifado en UF. Aquí
 * escribe pesos y ve pesos. El servidor devuelve el precio a la moneda del
 * producto antes de guardarlo, porque es donde lo espera Softland — el factor
 * de conversión es asunto suyo, no del vendedor.
 *
 * ## Sin señal también se cotiza
 *
 * El total lo calcula el teléfono con la misma aritmética del servidor, así que
 * se ve mientras se teclea aunque no haya red. Al guardar sin señal, el
 * documento se va a la bandeja de salida con su `client_uuid` y sale solo
 * cuando vuelve la red. Ese uuid es lo que impide que un reenvío deje dos
 * cotizaciones iguales en Softland.
 *
 * Lo que **no** se puede hacer sin señal es corregir un documento que ya está
 * en Softland: la copia del teléfono podría no ser la última, y pisar el
 * documento con una versión vieja es peor que esperar a tener señal.
 */

const route = useRoute();
const router = useRouter();

const tipo = computed(() => route.meta.tipo);
const def = computed(() => TIPOS[tipo.value]);
const numero = computed(() => (route.params.numero ? Number(route.params.numero) : null));
const editando = computed(() => numero.value !== null);
const esNV = computed(() => tipo.value === 'nota_venta');

const form = ref(vacio());
const cliente = ref(null);
const contactos = ref([]);
const ivaPct = ref(19);
const uf = ref(null);
const guardando = ref(false);
const error = ref('');
const cargando = ref(true);

// Las dos hojas de elegir. `useCapa` hace que «atrás» de Android las cierre en
// vez de salirse de la pantalla y perder lo escrito.
const eligiendoCliente = ref(false);
const eligiendoProducto = ref(false);
useCapa(computed(() => eligiendoCliente.value || eligiendoProducto.value), () => {
    eligiendoCliente.value = false;
    eligiendoProducto.value = false;
});

function vacio() {
    return {
        client_uuid: nuevoUuid(),
        cliente: '',
        contacto: '',
        moneda: '01',
        lista: '',
        condicion: '',
        centro_costo: '',
        bodega: '',
        fecha_entrega: '',
        oc: '',
        observacion: '',
        descuento_pct: 0,
        lineas: [],
    };
}

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    try {
        const info = await db.getServidorInfo();
        ivaPct.value = Number(info?.iva_pct) || 19;
        uf.value = Number(info?.uf) || null;

        if (editando.value) {
            await cargarDocumento();
        } else {
            // Los valores por defecto del vendedor: su lista, su centro de costo
            // y su bodega. Son tres campos que casi nunca cambia y que, en
            // blanco, obligan a elegir en cada documento.
            const u = await db.getUsuario();
            form.value.lista = u?.cod_lista || '';
            form.value.centro_costo = u?.cod_cc || '';
            form.value.bodega = u?.cod_bode || '';
        }
    } finally {
        cargando.value = false;
    }
}

/** Rearma el formulario desde lo que hay en el teléfono. */
async function cargarDocumento() {
    const doc = await idb.obtener(def.value.almacen, numero.value);
    if (! doc) {
        error.value = 'Ese documento no está en el teléfono. Sincroniza y vuelve a entrar.';
        return;
    }

    const filas = await idb.porIndice(def.value.lineas, def.value.indiceLineas, numero.value);
    filas.sort((a, b) => a.linea - b.linea);

    form.value = {
        client_uuid: null,           // ya tiene número: esto no es un alta
        cliente: doc.cliente || '',
        contacto: doc.contacto || '',
        moneda: doc.moneda || '01',
        lista: doc.lista || '',
        condicion: doc.condicion || '',
        centro_costo: doc.centro_costo || '',
        bodega: doc.bodega || '',
        fecha_entrega: (doc.fecha_entrega || '').slice(0, 10),
        oc: doc.oc && doc.oc !== '0' ? doc.oc : '',
        observacion: doc.observacion || '',
        descuento_pct: porcentajeDe(doc.descuento, filas),
        lineas: [],
    };

    for (const l of filas) {
        const p = await idb.obtener('productos', l.producto);
        form.value.lineas.push({
            producto: l.producto,
            nombre: p?.nombre || l.detalle || l.producto,
            detalle: l.detalle || '',
            unidad: l.unidad || p?.unidad || '',
            afecto: p ? !! p.afecto : true,
            cantidad: l.cantidad,
            // El maestro guarda el precio en la moneda del producto; aquí se
            // trabaja en la del documento, que es `precio × equiv`.
            precio: redondearPeso((l.precio || 0) * (l.equiv || 1)),
            descuento_pct: l.cantidad && l.precio
                ? redondear2((l.descuento || 0) * 100 / ((l.cantidad * l.precio * (l.equiv || 1)) || 1))
                : 0,
        });
    }

    await elegirCliente(form.value.cliente, false);
}

/**
 * El descuento de pie vuelve a porcentaje. Softland guarda el monto y el
 * porcentaje por separado, pero el porcentaje que guarda viene con quince
 * decimales de arrastre («9,9999955097268298 %»), así que se recalcula.
 */
function porcentajeDe(descuento, filas) {
    const bruto = filas.reduce((n, l) => n + (l.total || 0), 0);
    return bruto > 0 ? redondear2((descuento || 0) * 100 / bruto) : 0;
}

const redondear2 = (n) => Math.round(n * 100) / 100;
const redondearPeso = (n) => Math.round(n * 100) / 100;

// ---------------------------------------------------------------- el cliente

const busquedaCliente = ref('');
const clientesHallados = ref([]);

watch(busquedaCliente, async (q) => {
    clientesHallados.value = await idb.buscar('clientes', q, { limite: 30 });
});

async function abrirClientes() {
    eligiendoCliente.value = true;
    busquedaCliente.value = '';
    clientesHallados.value = await idb.buscar('clientes', '', { limite: 30 });
}

async function elegirCliente(codigo, cerrar = true) {
    form.value.cliente = codigo;
    cliente.value = await idb.obtener('clientes', codigo);
    contactos.value = await idb.porIndice('contactos', 'cliente', codigo);

    // Un solo contacto se elige solo: preguntarlo sería preguntar por preguntar.
    if (! form.value.contacto && contactos.value.length === 1) {
        form.value.contacto = contactos.value[0].nombre;
    }
    if (cerrar) eligiendoCliente.value = false;
}

// --------------------------------------------------------------- las líneas

const busquedaProducto = ref('');
const productosHallados = ref([]);

watch(busquedaProducto, async (q) => {
    productosHallados.value = await idb.buscar('productos', q, { limite: 30 });
});

async function abrirProductos() {
    eligiendoProducto.value = true;
    busquedaProducto.value = '';
    productosHallados.value = await idb.buscar('productos', '', { limite: 30 });
}

/**
 * Agrega una línea con el precio de referencia ya puesto.
 *
 * El precio sale de la lista de precios del documento y, si no hay, del maestro
 * del producto. Va convertido a la moneda del documento con el último valor de
 * la UF que bajó el teléfono. Es una propuesta: en las 2.350 cotizaciones
 * reales de INNOVAGES el vendedor terminó escribiendo su propio precio en casi
 * todas.
 */
async function agregarProducto(p) {
    const deLista = form.value.lista
        ? (await idb.obtener('precios', [form.value.lista, p.codigo]))?.valor
        : null;

    form.value.lineas.push({
        producto: p.codigo,
        nombre: p.nombre,
        detalle: '',
        unidad: p.unidad || '',
        afecto: !! p.afecto,
        cantidad: 1,
        precio: aMonedaDocumento(deLista ?? p.precio, p.moneda),
        descuento_pct: 0,
    });

    eligiendoProducto.value = false;
}

/** De la moneda del producto a la del documento. Hoy el único cambio es la UF. */
function aMonedaDocumento(valor, monedaProducto) {
    const mp = (monedaProducto || '01').trim();
    if (mp === form.value.moneda) return redondearPeso(valor || 0);
    if (mp === '02' && form.value.moneda === '01' && uf.value) {
        return Math.round((valor || 0) * uf.value);
    }
    // Sin forma de convertir se deja en cero: proponer un número inventado en
    // un documento de venta es peor que dejar el campo esperando.
    return 0;
}

const totales = computed(() => calcularTotales(
    form.value.lineas, form.value.descuento_pct, ivaPct.value
));

const puedeGuardar = computed(() =>
    !! form.value.cliente
    && form.value.lineas.length > 0
    && form.value.lineas.every((l) => Number(l.cantidad) > 0)
    && (! esNV.value || !! form.value.centro_costo)
);

// ------------------------------------------------------------------ guardar

async function guardar() {
    if (! puedeGuardar.value || guardando.value) return;

    guardando.value = true;
    error.value = '';

    try {
        const cuerpo = cuerpoDe(form.value);

        if (editando.value) {
            // Corregir exige señal: ver el porqué arriba.
            if (! conectado.value) {
                error.value = 'Para corregir un documento que ya está en Softland hace falta señal.';
                return;
            }
            const r = esNV.value
                ? await api.editarNotaVenta(numero.value, cuerpo)
                : await api.editarCotizacion(numero.value, cuerpo);
            await guardarLocal(r);
            router.replace(`${def.value.ruta}/${numero.value}`);
            return;
        }

        cuerpo.client_uuid = form.value.client_uuid;

        if (! conectado.value) {
            await encolar(`${tipo.value}.crear`, cuerpo.client_uuid, cuerpo);
            router.replace(def.value.ruta);
            return;
        }

        const r = esNV.value ? await api.crearNotaVenta(cuerpo) : await api.crearCotizacion(cuerpo);
        const n = await guardarLocal(r);
        router.replace(`${def.value.ruta}/${n}`);
    } catch (e) {
        if (e instanceof ErrorApi && e.status === 0 && ! editando.value) {
            // Se cayó la red justo al mandar. No se pierde: a la bandeja.
            await encolar(`${tipo.value}.crear`, form.value.client_uuid, cuerpoDe(form.value));
            router.replace(def.value.ruta);
            return;
        }
        error.value = e.message;
    } finally {
        guardando.value = false;
    }
}

/** La respuesta del servidor manda: se guarda tal cual llegó. */
async function guardarLocal(r) {
    const doc = r.nota_venta ?? r.cotizacion;
    const plano = JSON.parse(JSON.stringify(r));

    await idb.guardar(def.value.almacen, [plano.nota_venta ?? plano.cotizacion]);

    // Las líneas se reemplazan enteras: al corregir puede haber menos que antes,
    // y las que sobran quedarían colgando con su número de línea viejo.
    const viejas = await idb.porIndice(def.value.lineas, def.value.indiceLineas, doc.numero);
    for (const l of viejas) await idb.borrar(def.value.lineas, [doc.numero, l.linea]);
    await idb.guardar(def.value.lineas, plano.lineas || []);

    return doc.numero;
}

function cantidad(n) {
    return Number(n || 0).toLocaleString('es-CL', { maximumFractionDigits: 2 });
}
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>{{ editando ? `${def.singular} Nº ${numero}` : `Nueva ${def.singular.toLowerCase()}` }}</h1>
        </div>

        <div class="contenido">
            <div class="cargando" v-if="cargando">Cargando…</div>

            <template v-else>
                <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
                <Aviso tipo="info" v-if="! conectado && ! editando">
                    Sin señal. Se guarda en el teléfono y sale a Softland cuando vuelva.
                </Aviso>

                <label>Cliente</label>
                <button class="campo-boton" @click="abrirClientes">
                    <span v-if="cliente">
                        <b>{{ cliente.nombre }}</b>
                        <small>{{ cliente.rut || cliente.codigo }}</small>
                    </span>
                    <span v-else class="hueco">Elegir cliente</span>
                    <AppIcon name="buscar" :size="18" color="var(--texto-suave)" />
                </button>

                <template v-if="contactos.length">
                    <label>Contacto</label>
                    <select v-model="form.contacto">
                        <option value="">— sin contacto —</option>
                        <option v-for="c in contactos" :key="c.nombre" :value="c.nombre">{{ c.nombre }}</option>
                    </select>
                </template>

                <label>Condición de venta</label>
                <Selector v-model="form.condicion" maestro="condiciones_venta" vacio="— sin elegir —" />

                <label>Centro de costo</label>
                <Selector v-model="form.centro_costo" maestro="centros_costo"
                          :vacio="esNV ? null : '— sin elegir —'" filtrar="Filtrar centros de costo" />
                <p class="ayuda" v-if="esNV">
                    Obligatorio en la nota de venta: así está configurado Softland.
                </p>

                <template v-if="esNV">
                    <label>Bodega</label>
                    <Selector v-model="form.bodega" maestro="bodegas" vacio="— sin elegir —" />

                    <label>Orden de compra del cliente</label>
                    <input v-model="form.oc" type="text" placeholder="Número de OC">
                </template>

                <label>Lista de precios</label>
                <Selector v-model="form.lista" maestro="listas_precio" vacio="— precio del maestro —" />

                <label>Fecha de entrega</label>
                <input v-model="form.fecha_entrega" type="date" class="angosto">

                <label>Observación</label>
                <textarea v-model="form.observacion" rows="2" placeholder="Lo que tiene que leer el cliente"></textarea>

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
                            <input v-model.number="l.descuento_pct" type="number" inputmode="decimal" min="0" max="100" step="any">
                        </label>
                    </div>
                    <div class="linea-total">
                        {{ cantidad(l.cantidad) }} {{ nombreDe('unidades', l.unidad) }}
                        · {{ monto(totales.lineas[i]?.total ?? 0, form.moneda) }}
                    </div>
                </div>

                <template v-if="form.lineas.length">
                    <label>Descuento sobre el total (%)</label>
                    <input v-model.number="form.descuento_pct" type="number" inputmode="decimal"
                           min="0" max="100" step="any" class="angosto">

                    <div class="tarjeta">
                        <div class="tarjeta-cabecera">Totales</div>
                        <div class="tarjeta-cuerpo datos">
                            <div><span>Neto afecto</span><b>{{ monto(totales.afecto, form.moneda) }}</b></div>
                            <div v-if="totales.exento"><span>Exento</span><b>{{ monto(totales.exento, form.moneda) }}</b></div>
                            <div v-if="totales.descuento"><span>Descuento</span><b>− {{ monto(totales.descuento, form.moneda) }}</b></div>
                            <div><span>IVA {{ ivaPct }} %</span><b>{{ monto(totales.iva, form.moneda) }}</b></div>
                            <div class="fuerte"><span>Total</span><b>{{ monto(totales.total, form.moneda) }}</b></div>
                        </div>
                    </div>
                </template>

                <button class="boton" :disabled="! puedeGuardar || guardando" @click="guardar">
                    {{ guardando ? 'Guardando…' : (editando ? 'Guardar cambios' : `Crear ${def.singular.toLowerCase()}`) }}
                </button>
                <p class="ayuda centrado" v-if="! puedeGuardar">
                    <template v-if="! form.cliente">Falta elegir el cliente.</template>
                    <template v-else-if="! form.lineas.length">Falta agregar al menos un producto.</template>
                    <template v-else-if="esNV && ! form.centro_costo">Falta el centro de costo.</template>
                    <template v-else>Hay una línea sin cantidad.</template>
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
                    <Buscador v-model="busquedaCliente" placeholder="Nombre, RUT o código" />
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
                            <div class="item-titulo">{{ p.nombre }}</div>
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
    </div>
</template>
