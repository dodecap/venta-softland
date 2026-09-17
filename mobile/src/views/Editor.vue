<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ErrorApi } from '../api';
import { db } from '../db';
import { idb, normalizar } from '../idb';
import { monto, nombre as nombreDe, opciones } from '../catalogos';
import { calcularTotales, cuerpoDe, TIPOS } from '../documentos';
import { definidos as atributosDefinidos, valoresDe, vacios } from '../atributos';
import { sumarDias } from '../seguimiento';
import { conectado } from '../red';
import { encolar, nuevoUuid } from '../pendientes';
import { olvidarPdf } from '../pdf';
import { useCapa } from '../nav';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Cantidad from '../components/Cantidad.vue';
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

/*
 * Duplicar. Se entra por `/cotizaciones/nuevo?desde=8550`: es la pantalla de
 * alta con el formulario ya lleno, no un modo aparte. Así lo que se guarda es
 * un documento nuevo, con su `client_uuid` nuevo, y todo lo demás —el número
 * que asigna el servidor, la idempotencia, el guardado sin señal— funciona sin
 * saber que viene de una copia.
 *
 * Sale del teléfono y no necesita señal, salvo que el original sea anterior a
 * los doce meses que se descargan: esos no están en el aparato y hay que
 * pedirlos a la API.
 */
const desde = computed(() => (route.query.desde ? Number(route.query.desde) : null));
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
        vendedor: '',
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
        atributos: {},
        // El compromiso con el que nace la cotización. Se pregunta aquí y no
        // después porque después no vuelve nadie: el vendedor sale de la visita
        // y no abre otra pantalla a anotar el seguimiento.
        compromiso: { tipo: '', fecha: '', hora: '' },
        lineas: [],
    };
}

/*
 * Los compromisos que ofrece la empresa y el que viene propuesto. Bajan con la
 * información del servidor; si no hay ninguno definido, la pregunta no aparece
 * y la cotización se guarda como siempre.
 */
const compromisos = ref([]);

const ATAJOS = [
    { rotulo: 'Mañana', dias: 1 },
    { rotulo: 'En 3 días', dias: 3 },
    { rotulo: 'La próxima semana', dias: 7 },
];

const hoyTexto = () => new Date().toISOString().slice(0, 10);

function elegirAtajo(dias) {
    form.value.compromiso.fecha = sumarDias(hoyTexto(), dias);
}

/*
 * Los campos que la empresa definió en el ERP.
 *
 * Son de la nota de venta: es el maestro para el que Softland los declara. En
 * una cotización no se piden porque allá no existen.
 *
 * Puede no haber ninguno —la lista queda vacía y la sección no se dibuja—, y
 * puede haber siete. Nada de esto está escrito en el código: se lee de lo que
 * bajó el teléfono.
 */
const atributos = ref([]);

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    try {
        const info = await db.getServidorInfo();
        ivaPct.value = Number(info?.iva_pct) || 19;
        uf.value = Number(info?.uf) || null;

        if (tipo.value === 'nota_venta') {
            atributos.value = await atributosDefinidos();
            form.value.atributos = vacios(atributos.value);
        }

        if (tipo.value === 'cotizacion') {
            compromisos.value = info?.compromisos ?? [];
            // El tipo viene propuesto; **la fecha no**. Es la única decisión
            // que nadie puede tomar por el vendedor, y un valor por omisión la
            // convertiría en un campo que nadie lee.
            form.value.compromiso.tipo = info?.compromiso_por_omision || '';
        }

        if (editando.value) {
            await cargarDocumento(numero.value);
        } else if (desde.value) {
            await cargarDocumento(desde.value, true);
        } else {
            // Los valores por defecto del vendedor: su lista, su centro de costo
            // y su bodega. Son tres campos que casi nunca cambia y que, en
            // blanco, obligan a elegir en cada documento.
            const u = await db.getUsuario();
            // El vendedor del documento es el del usuario. Se deja a la vista y
            // se puede cambiar: el jefe carga pedidos que entran por teléfono a
            // nombre de su gente, y un administrador no tiene vendedor propio.
            form.value.vendedor = u?.ven_cod || '';
            form.value.lista = u?.cod_lista || '';
            form.value.centro_costo = u?.cod_cc || '';
            form.value.bodega = u?.cod_bode || '';
        }
    } finally {
        cargando.value = false;
    }
}

/**
 * Rearma el formulario desde las filas del documento.
 *
 * Sirve para corregir y para duplicar: es el mismo trabajo —volver a armar el
 * documento desde sus filas— y sólo cambia si lo que sale es el mismo
 * documento o uno nuevo.
 */
async function cargarDocumento(num, copia = false) {
    let doc = await idb.obtener(def.value.almacen, num);
    let filas = doc ? await idb.porIndice(def.value.lineas, def.value.indiceLineas, num) : [];

    // Los anteriores a la ventana de doce meses no están en el teléfono, y
    // copiar uno de esos es de los casos buenos: volver a cotizarle a un
    // cliente lo mismo que en 2024 es escribir doce líneas a mano o traerse
    // las que ya existen. Hace falta señal, y si no la hay se dice.
    let atributosTraidos = null;

    if (! doc && conectado.value) {
        const traido = await traerDelServidor(num);
        doc = traido?.doc ?? null;
        filas = traido?.lineas ?? [];
        atributosTraidos = traido?.atributos ?? null;
    }

    if (! doc) {
        error.value = copia
            ? `La ${def.value.singular.toLowerCase()} Nº ${num} no está en el teléfono`
                + (conectado.value ? ' ni en Softland, o no es tuya.' : '. Búscala con señal.')
            : 'Ese documento no está en el teléfono. Sincroniza y vuelve a entrar.';
        return;
    }

    filas = [...filas];
    filas.sort((a, b) => a.linea - b.linea);

    form.value = {
        // Con número es una corrección y no lleva uuid; la copia es un alta y
        // estrena el suyo, que es lo que impide que un reenvío la duplique.
        client_uuid: copia ? nuevoUuid() : null,
        cliente: doc.cliente || '',
        vendedor: doc.vendedor || '',
        contacto: doc.contacto || '',
        moneda: doc.moneda || '01',
        lista: doc.lista || '',
        condicion: doc.condicion || '',
        centro_costo: doc.centro_costo || '',
        bodega: doc.bodega || '',
        fecha_entrega: fechaEntregaDe(doc, copia),
        oc: doc.oc && doc.oc !== '0' ? doc.oc : '',
        observacion: doc.observacion || '',
        descuento_pct: porcentajeDe(doc.descuento, filas),
        // Los atributos se llevan también al duplicar: son el tipo de venta y
        // el de contrato, y quien copia un documento copia esa venta.
        atributos: {
            ...vacios(atributos.value),
            ...(tipo.value === 'nota_venta' ? (atributosTraidos ?? await valoresDe(num)) : {}),
        },
        lineas: [],
    };

    for (const l of filas) {
        const p = await idb.obtener('productos', l.producto);
        form.value.lineas.push({
            producto: l.producto,
            nombre: p?.nombre || l.detalle || l.producto,
            detalle: l.detalle || p?.nombre || '',
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

/** El documento y su detalle desde la API, para lo que no está descargado. */
async function traerDelServidor(num) {
    try {
        const r = esNV.value ? await api.notaVenta(num) : await api.cotizacion(num);

        // Los atributos vienen en la respuesta porque un documento traído del
        // servidor **no pasa por IndexedDB**: es de hace años y no se guarda,
        // así que leerlos del almacén no devolvería nada.
        return {
            doc: esNV.value ? r.nota_venta : r.cotizacion,
            lineas: r.lineas ?? [],
            atributos: r.atributos ?? null,
        };
    } catch {
        return null;
    }
}

/**
 * La fecha de entrega de una copia no se arrastra si ya pasó.
 *
 * Copiar una cotización de hace dos meses y guardarla con su fecha de entrega
 * vencida escribe en Softland un compromiso imposible, y nadie lo mira: el
 * campo viene lleno y parece revisado. En blanco, se ve que falta.
 */
function fechaEntregaDe(doc, copia) {
    const f = (doc.fecha_entrega || '').slice(0, 10);

    if (! f) return '';
    if (! copia) return f;

    const hoy = new Date();
    const local = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-${String(hoy.getDate()).padStart(2, '0')}`;

    return f >= local ? f : '';
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

// La carga inicial y lo que se escribe compiten por la misma respuesta: si la
// primera tarda más que la búsqueda que el vendedor ya hizo, llega después y
// la pisa, dejando la lista congelada en el cliente equivocado sin ningún
// aviso. El turno se queda con la última pedida, gane quien gane la carrera.
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

// ----------------------------------------------------------- centro de costo
//
// 594 centros de costo: un `<select>` nativo con esa cantidad no se navega en
// Android. Es un maestro chico —vive entero en memoria, lo carga
// `cargarCatalogos()`— así que filtrar es sincrónico, sin `idb.buscar` ni
// condición de carrera posible.

const eligiendoCentroCosto = ref(false);
useCapa(eligiendoCentroCosto, () => { eligiendoCentroCosto.value = false; });
const busquedaCC = ref('');

const centrosCostoQueCalzan = computed(() => {
    const q = normalizar(busquedaCC.value).trim();
    const todos = opciones('centros_costo');
    if (! q) return todos;
    return todos.filter((c) => normalizar(c.nombre).includes(q) || normalizar(String(c.codigo)).includes(q));
});
const centrosCostoHallados = computed(() => centrosCostoQueCalzan.value.slice(0, 30));
const sobranCentrosCosto = computed(() => centrosCostoQueCalzan.value.length - 30);

function abrirCentroCosto() {
    eligiendoCentroCosto.value = true;
    busquedaCC.value = '';
}

function elegirCentroCosto(codigo) {
    form.value.centro_costo = codigo;
    eligiendoCentroCosto.value = false;
}

// --------------------------------------------------------------- las líneas

const busquedaProducto = ref('');
const productosHallados = ref([]);

// Mismo guardián que el cliente: sin él, la carga inicial puede pisar la
// búsqueda que ya se hizo.
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
        // Lo que va a leer el cliente. Se propone la descripción del maestro y
        // el vendedor la corrige si hace falta: en INNOVAGES hay líneas donde
        // dice «ADV» y el maestro dice «Business».
        detalle: p.nombre || '',
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

/*
 * El compromiso es obligatorio en una cotización nueva.
 *
 * No por burocracia: una cotización sin próximo paso se enfría en silencio y
 * nadie vuelve a abrirla para anotarlo. Aquí el vendedor tiene el contexto —
 * acaba de hablar con el cliente— y son dos toques.
 *
 * Al **corregir** no se pide: el seguimiento se anota desde la ficha, que es
 * donde corresponde. Al duplicar sí, porque es una cotización nueva.
 */
const pideCompromiso = computed(() =>
    tipo.value === 'cotizacion' && ! editando.value && compromisos.value.length > 0
);

const puedeGuardar = computed(() =>
    !! form.value.cliente
    && !! form.value.vendedor
    && form.value.lineas.length > 0
    && form.value.lineas.every((l) => Number(l.cantidad) > 0)
    && (! esNV.value || !! form.value.centro_costo)
    && (! pideCompromiso.value || !! form.value.compromiso.fecha)
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
            // El PDF que hubiera en el teléfono ya no es este documento. Se
            // borra para que la próxima vez se baje el corregido: enseñarle al
            // cliente el papel viejo es peor que no tener papel.
            await olvidarPdf(tipo.value, numero.value);
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
                <Aviso tipo="info" v-if="desde && ! error">
                    Copia de la {{ def.singular.toLowerCase() }} Nº {{ desde }}.
                    Todavía no existe en Softland: se crea con un número nuevo al guardar.
                    Revisa precios y fechas antes.
                </Aviso>
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

                <label>Vendedor</label>
                <Selector v-model="form.vendedor" maestro="vendedores"
                          vacio="— elegir vendedor —" filtrar="Filtrar vendedores" />
                <p class="ayuda">
                    Queda a su nombre en Softland. Un documento sin vendedor no
                    aparece en las búsquedas del ERP, así que va la opción en
                    blanco a la vista: sin ella el desplegable mostraría al
                    primero de la lista sin que nadie lo haya elegido.
                </p>

                <label>Condición de venta</label>
                <Selector v-model="form.condicion" maestro="condiciones_venta" vacio="— sin elegir —" />

                <label>Centro de costo</label>
                <button type="button" class="campo-boton" @click="abrirCentroCosto">
                    <span v-if="form.centro_costo">
                        <b>{{ nombreDe('centros_costo', form.centro_costo) }}</b>
                        <small>{{ form.centro_costo }}</small>
                    </span>
                    <span v-else class="hueco">Elegir centro de costo</span>
                    <AppIcon name="buscar" :size="18" color="var(--texto-suave)" />
                </button>
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

                <!-- Los campos que la empresa definió en el ERP.
                     No hay ninguno escrito aquí: se dibuja lo que declare la
                     base, con el control que pida su tipo. Si la empresa no
                     define ninguno, esta sección no existe. -->
                <template v-if="atributos.length">
                    <div class="seccion"><h2>Datos de la venta</h2></div>

                    <template v-for="a in atributos" :key="a.codigo">
                        <label>{{ a.nombre }}</label>

                        <select v-if="a.control === 'lista'" v-model="form.atributos[a.codigo]">
                            <option value="">— sin elegir —</option>
                            <option v-for="o in a.opciones" :key="o.codigo" :value="o.codigo">
                                {{ o.nombre }}
                            </option>
                        </select>

                        <input v-else-if="a.control === 'fecha'" type="date" class="angosto"
                               v-model="form.atributos[a.codigo]">

                        <select v-else-if="a.control === 'si_no'" v-model="form.atributos[a.codigo]">
                            <option value="">— sin elegir —</option>
                            <option value="Si">Sí</option>
                            <option value="No">No</option>
                        </select>

                        <input v-else-if="a.control === 'numero'" type="number" inputmode="decimal"
                               class="angosto" v-model="form.atributos[a.codigo]">

                        <input v-else v-model="form.atributos[a.codigo]" maxlength="50">

                        <p class="ayuda" v-if="a.descripcion">{{ a.descripcion }}</p>
                    </template>
                </template>

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
                        <textarea v-model="l.detalle" rows="2"
                                  :placeholder="l.nombre"></textarea>
                    </label>
                    <div class="linea-campos">
                        <Cantidad v-model.number="l.cantidad" />
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

                <!-- La única pregunta del cierre, y es la que hace que el
                     seguimiento exista: cuándo se vuelve a tocar al cliente.
                     Ninguna fecha viene marcada — un valor por omisión aquí
                     convierte esto en un campo que nadie lee. -->
                <template v-if="pideCompromiso">
                    <div class="seccion"><h2>¿Cuándo vuelves a tocarlo?</h2></div>

                    <select v-model="form.compromiso.tipo">
                        <option v-for="c in compromisos" :key="c.codigo" :value="c.codigo">
                            {{ c.nombre }}
                        </option>
                    </select>

                    <div class="acciones-doc">
                        <button class="chip-accion" v-for="a in ATAJOS" :key="a.dias"
                                :class="{ fuerte: form.compromiso.fecha === sumarDias(hoyTexto(), a.dias) }"
                                @click="elegirAtajo(a.dias)">{{ a.rotulo }}</button>
                    </div>

                    <div class="fila">
                        <div>
                            <label>Fecha</label>
                            <input v-model="form.compromiso.fecha" type="date">
                        </div>
                        <div class="angosto">
                            <label>Hora</label>
                            <input v-model="form.compromiso.hora" type="time">
                        </div>
                    </div>
                </template>

                <button class="boton" :disabled="! puedeGuardar || guardando" @click="guardar">
                    {{ guardando ? 'Guardando…' : (editando ? 'Guardar cambios' : `Crear ${def.singular.toLowerCase()}`) }}
                </button>
                <p class="ayuda centrado" v-if="! puedeGuardar">
                    <template v-if="! form.cliente">Falta elegir el cliente.</template>
                    <template v-else-if="! form.vendedor">Falta elegir el vendedor.</template>
                    <template v-else-if="! form.lineas.length">Falta agregar al menos un producto.</template>
                    <template v-else-if="esNV && ! form.centro_costo">Falta el centro de costo.</template>
                    <template v-else-if="pideCompromiso && ! form.compromiso.fecha">
                        Falta decir cuándo vuelves a contactar al cliente.
                    </template>
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

        <!-- Elegir centro de costo -->
        <div class="velo" v-if="eligiendoCentroCosto" @click.self="eligiendoCentroCosto = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Centro de costo</h2>
                    <button class="icono-barra" @click="eligiendoCentroCosto = false"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <Buscador v-model="busquedaCC" placeholder="Nombre o código" />
                    <div class="item" v-if="! esNV" @click="elegirCentroCosto('')">
                        <div class="item-estado gris"></div>
                        <div class="item-cuerpo"><div class="item-titulo">— sin elegir —</div></div>
                    </div>
                    <div class="item" v-for="c in centrosCostoHallados" :key="c.codigo" @click="elegirCentroCosto(c.codigo)">
                        <div class="item-estado cian"></div>
                        <div class="item-cuerpo">
                            <div class="item-titulo">{{ c.nombre }}</div>
                            <div class="item-meta"><span class="etiqueta gris">{{ c.codigo }}</span></div>
                        </div>
                    </div>
                    <p class="ayuda" v-if="sobranCentrosCosto > 0">
                        Hay {{ sobranCentrosCosto.toLocaleString('es-CL') }} más. Escribe arriba para acotar.
                    </p>
                    <Vacio v-if="! centrosCostoHallados.length && esNV" icono="sinResultados" titulo="Ningún centro de costo con eso">
                        Prueba con una palabra del nombre o con el código.
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
    </div>
</template>
