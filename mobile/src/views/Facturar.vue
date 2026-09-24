<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';
import { idb } from '../idb';
import { monto, nombre as nombreDe } from '../catalogos';
import { db } from '../db';
import { calcularTotales } from '../documentos';
import { propuestaLocal } from '../saldo';
import { encolar, nuevoUuid } from '../pendientes';
import { conectado } from '../red';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Buscador from '../components/Buscador.vue';
import Cantidad from '../components/Cantidad.vue';
import Vacio from '../components/Vacio.vue';

/*
 * Facturar una nota de venta.
 *
 * Es la única pantalla de la app que gasta algo que no se recupera. De ahí tres
 * cosas que no están en ninguna otra:
 *
 *  - **los folios se dicen antes**, no después de teclear el documento;
 *  - **el precio no se puede tocar**: lo pone la nota de venta, y aquí ni
 *    siquiera hay campo donde escribirlo, que es más claro que un campo
 *    desactivado;
 *  - **confirma nombrando el folio** que va a gastar.
 *
 * Y no funciona sin señal, a propósito. Un documento tributario no se guarda en
 * una bandeja de salida: el folio lo reparte Softland y el número tiene que ser
 * el mismo para siempre desde el instante en que se emite.
 *
 * ## Dos facturas distintas, y hay que decir cuál
 *
 * Con el permiso de Softland —`IW · Iw_FacLin · NVOtroAuxiliar`, cruzado con la
 * llave de la empresa— se le puede cambiar el receptor. Durante un tiempo esta
 * pantalla **dedujo** de ahí qué documento era: receptor distinto, comisión. Se
 * quedó corto, porque son dos cosas que se parecen y no son la misma:
 *
 *  - **Facturar la venta.** Los productos de la nota de venta, con su precio,
 *    su orden de compra y su observación. Las líneas llevan `nv_linea` y
 *    **consumen saldo**. El receptor puede ser otro —quien paga no siempre es
 *    quien recibe— y eso no cambia nada de lo anterior: lo único que cambia es
 *    a nombre de quién sale el documento.
 *  - **Facturar la comisión.** El ciclo de distribuidor: la venta es del cliente
 *    final y a quien se le cobra es al mandante, por un concepto que no está en
 *    la nota de venta. Las líneas se escriben a mano y **no llevan `nv_linea`**,
 *    porque una comisión no factura nada de lo que se vendió.
 *
 * Deducirlo del receptor hacía imposible la primera con otro RUT, que es un
 * caso real y corriente: el mismo pedido facturado a la matriz, a la
 * aseguradora o a quien financia. Así que se pregunta, y el receptor pasa a ser
 * consecuencia y no causa.
 *
 * **Las dos quedan atadas a la nota de venta.** Va en `iw_gsaen.nvnumero`, así
 * que se ven desde su ficha y desde la cotización.
 *
 * ## Lo que la factura hereda de la venta
 *
 * Todo lo que describe la venta viaja con ella, se le facture a quien se le
 * facture: condición de pago, bodega, centro de costo, la observación y la
 * orden de compra del cliente. Las dos últimas se pueden corregir aquí —la OC
 * llega muchas veces después de escribir la venta—, y la OC acaba en el DTE
 * como **referencia 801**, que es la que le sirve a quien recibe la factura
 * para cuadrarla contra lo que encargó.
 */

const route = useRoute();
const router = useRouter();

const numero = computed(() => Number(route.params.numero));

const propuesta = ref(null);
const lineas = ref([]);
const cliente = ref(null);
const cargando = ref(true);

/*
 * Decimales que admite la empresa en una cantidad (`iwparam.CantDecimales`).
 * Aquí no se cargan productos con el escáner —lo que se escribe a mano es el
 * concepto de una comisión, no una caja que se apunta con la cámara— pero la
 * cantidad se escribe igual, y el parámetro es de la empresa, no de la
 * pantalla.
 */
const decimalesCantidad = ref(3);
db.getServidorInfo().then((info) => {
    // `?? 3` y no `|| 3`: cero decimales es una respuesta, no un hueco.
    decimalesCantidad.value = Number(info?.cant_decimales ?? 3);
});
const error = ref('');
const trabajando = ref(false);
const confirmando = ref(false);
const emitida = ref(null);
const encolada = ref(false);
const sii = ref(null);

/*
 * El receptor. Arranca en el de la nota de venta siempre: aunque se pueda
 * cambiar, lo normal es no cambiarlo, y un campo que nace distinto de lo
 * esperado es un documento mal emitido esperando a que alguien no mire.
 */
const receptor = ref('');

/** El cliente de la nota de venta, para poder nombrarlo al ofrecer volver. */
const clienteNv = ref(null);
const eligiendoCliente = ref(false);
const busquedaCliente = ref('');
const clientesHallados = ref([]);

const eligiendoProducto = ref(false);
const busquedaProducto = ref('');
const productosHallados = ref([]);

/** Las líneas escritas a mano, que son las que valen cuando el receptor cambió. */
const propias = ref([]);

/**
 * Qué factura es ésta: la de la venta o la de la comisión.
 *
 * Se declara, no se deduce. Ver arriba el porqué: deducirlo del receptor
 * confundía «le facturo a otro» con «le cobro una comisión», que son dos
 * documentos con dos efectos distintos sobre el saldo de la venta.
 */
const modo = ref('venta');
const esComision = computed(() => modo.value === 'comision');

/** Si el documento sale a nombre de alguien que no es el cliente de la venta. */
const aOtro = computed(
    () => !! propuesta.value && receptor.value !== '' && receptor.value !== propuesta.value.cliente
);

/*
 * Lo que la factura hereda de la nota de venta y aquí se puede corregir.
 *
 * La orden de compra, porque llega muchas veces después de escribir la venta; y
 * la observación, porque en `iw_gsaen` cabe en 255 caracteres y en la nota de
 * venta en 4.000. Lo que no cabe se enseña recortado antes de emitir, nunca se
 * corta en silencio.
 */
const oc = ref('');
const observacion = ref('');

const LARGO_GLOSA = 255;

/** La observación que de verdad se va a escribir, con su recorte a la vista. */
const observacionRecortada = computed(
    () => observacion.value.trim().length > LARGO_GLOSA
);

/** Lo que propone cada modo como observación, para saber si nadie la tocó. */
function observacionPropuesta(cual) {
    if (cual === 'comision') {
        const quien = clienteNv.value?.nombre || propuesta.value?.cliente || '';

        return quien ? `COMISION ${quien}`.slice(0, LARGO_GLOSA) : '';
    }

    return propuesta.value?.observacion || '';
}

/*
 * Al cambiar de modo cambia el documento, así que cambia lo que propone. Pero
 * sólo se pisa lo que nadie escribió: si el texto sigue siendo el que propuso
 * el otro modo, se cambia; si lo tocaron, se respeta.
 */
watch(modo, (nuevo, viejo) => {
    if (observacion.value.trim() === observacionPropuesta(viejo).trim()) {
        observacion.value = observacionPropuesta(nuevo);
    }
});

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    error.value = '';
    try {
        // Con señal manda el servidor, que ve lo que el ERP facturó hace un
        // minuto. Sin señal se calcula aquí, que es lo mismo mientras el
        // almacén esté al día — y es lo que permite dejar la factura escrita
        // en terreno en vez de confiar en que alguien se acuerde después.
        // Sin señal, el permiso sale de lo que dijo el servidor al arrancar: es
        // de este usuario y no cambia de un día para otro. Presumirlo apagado
        // dejaría al distribuidor sin poder trabajar en terreno, que es justo
        // para lo que está la app, y presumirlo no abre nada — el servidor lo
        // vuelve a comprobar al emitir.
        const r = conectado.value
            ? await api.propuestaFactura(numero.value)
            : await propuestaLocal(
                numero.value,
                !! (await db.getServidorInfo())?.receptor_editable
            );

        if (! r) {
            error.value = 'Esa nota de venta no está en el teléfono y no hay señal para traerla.';

            return;
        }

        propuesta.value = r;

        // Sólo lo que queda. Lo ya facturado no se vuelve a ofrecer, aunque se
        // pueda facturar de más: para eso está el campo de cantidad.
        lineas.value = r.lineas
            .filter((l) => l.saldo > 0.0001)
            .map((l) => ({ ...l, cantidad: l.saldo, nombre: '' }));

        await ponerNombres();
        receptor.value = r.cliente;
        clienteNv.value = await idb.obtener('clientes', r.cliente);
        cliente.value = clienteNv.value;

        // Lo que se hereda de la venta. Se pone después del cliente porque la
        // observación que propone el modo comisión lo nombra.
        oc.value = r.oc || '';
        observacion.value = observacionPropuesta(modo.value);
    } catch (e) {
        error.value = e.message;
    } finally {
        cargando.value = false;
    }
}

/** El nombre del producto sale del almacén: el servidor manda el código. */
async function ponerNombres() {
    for (const l of lineas.value) {
        const p = await idb.obtener('productos', l.producto);
        l.nombre = p?.nombre || l.producto;
        l.unidad = p?.unidad || 'UN';
        // El precio viene de la nota de venta y no se toca aquí; se trae para
        // poder enseñar el total mientras se cambian las cantidades.
        const nv = await idb.obtener('nota_venta_lineas', [numero.value, l.linea]);
        l.precio = Number(nv?.precio || 0) * Number(nv?.equiv || 1);
        l.descuento_pct = nv?.cantidad && nv?.precio
            ? Math.round((nv.descuento || 0) * 10000 / (nv.cantidad * nv.precio * (nv.equiv || 1))) / 100
            : 0;
        l.afecto = true;
    }
}

/** Lo que se va a facturar: las de la nota de venta, o las escritas a mano. */
const enJuego = computed(() => (esComision.value ? propias.value : lineas.value));

const totales = computed(() => calcularTotales(enJuego.value.filter((l) => l.cantidad > 0)));

/*
 * Mismo guardián de turno que en el editor y en la factura suelta: la carga
 * inicial y lo que se teclea compiten por la misma respuesta, y sin turno gana
 * la que termina última, no la que se pidió última.
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
    receptor.value = codigo;
    cliente.value = await idb.obtener('clientes', codigo);
    eligiendoCliente.value = false;
}

/** Volver al de la nota de venta, que es deshacer el cambio, no otra cosa. */
function volverAlDeLaNotaVenta() {
    receptor.value = propuesta.value.cliente;
    cliente.value = clienteNv.value;
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

/*
 * El precio nace en cero, no en el del catálogo.
 *
 * La comisión no se calcula: la escribe quien factura. Proponer el precio de
 * lista del producto sería sugerir un número que no tiene nada que ver, y el
 * riesgo de que se emita sin mirarlo es real.
 */
function agregarProducto(p) {
    propias.value.push({
        producto: p.codigo,
        nombre: p.nombre,
        glosa: p.nombre || '',
        unidad: p.unidad || '',
        afecto: !! p.afecto,
        cantidad: 1,
        precio: 0,
        descuento_pct: 0,
    });
    eligiendoProducto.value = false;
}

// Sin folios no se emite. Ojo con el caso sin señal: ahí `folios` es `null`,
// que quiere decir «no se sabe» y no «no quedan» — bloquear ahí sería impedir
// justo lo que la bandeja vino a permitir.
const sinFolios = computed(() => !! propuesta.value?.folios && propuesta.value.folios.libres <= 0);
const hayQueFacturar = computed(
    () => enJuego.value.some((l) => l.cantidad > 0) && (! esComision.value || totales.value.total > 0)
);

/** Facturar de más está permitido, pero tiene que verse. */
const deMas = computed(
    () => (esComision.value ? [] : lineas.value.filter((l) => l.cantidad > l.saldo + 0.0001))
);

/**
 * Emitir, que es escribir el documento **y mandarlo al SII**, en un solo acto.
 *
 * Sin señal no se puede ninguna de las dos cosas: el folio lo reparte Softland
 * y el timbre se firma con una llave que vive en el servidor. Así que lo que
 * queda en la bandeja **no es una factura todavía** — es lo que se va a emitir
 * en cuanto haya red, sin número y sin timbre. La pantalla lo dice con esas
 * palabras, porque el vendedor no puede darle un número al cliente.
 */
async function emitir() {
    confirmando.value = false;
    trabajando.value = true;
    error.value = '';

    const doc = {
        // El mismo `client_uuid` viaje lo que viaje: emitir dos veces la misma
        // factura son dos folios, y un folio no se devuelve.
        client_uuid: nuevoUuid(),

        // Siempre, cambie o no el receptor: la factura de comisión también sale
        // de esta nota de venta y tiene que poder verse desde su ficha.
        nota_venta: numero.value,
        receptor: receptor.value,

        // Lo que describe la venta viaja con ella, cambie o no el receptor:
        // quién paga no cambia qué se vendió, con qué condición ni desde qué
        // bodega. La OC acaba en el DTE como referencia 801 y la observación en
        // la glosa del documento.
        centro_costo: propuesta.value.centro_costo,
        condicion: propuesta.value.condicion,
        bodega: propuesta.value.bodega,
        oc: oc.value.trim() || null,
        glosa: observacion.value.trim().slice(0, LARGO_GLOSA) || null,

        // La comisión va con las líneas escritas a mano y **sin `nv_linea`**:
        // no factura nada de lo vendido, así que no puede consumir saldo. La
        // venta va con las suyas, enlazadas — y siguen enlazadas aunque el
        // documento salga a nombre de otro, porque lo vendido se entregó igual.
        lineas: esComision.value
            ? propias.value
                .filter((l) => l.cantidad > 0)
                .map((l) => ({
                    producto: l.producto,
                    cantidad: l.cantidad,
                    precio: l.precio,
                    glosa: l.glosa || null,
                }))
            : lineas.value
                .filter((l) => l.cantidad > 0)
                .map((l) => ({ producto: l.producto, cantidad: l.cantidad, nv_linea: l.linea })),
    };

    // Ojo con lo que **no** va: el vendedor. La factura lo hereda de su nota de
    // venta, también la de comisión, y escribir aquí el de quien opera le
    // quitaría la venta a quien la hizo.

    try {
        if (! conectado.value) {
            await encolar('factura.crear', doc.client_uuid, doc);
            encolada.value = true;

            return;
        }

        const r = await api.emitirFactura(doc);

        emitida.value = r.documento;
        sii.value = r.sii || null;
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
            <h1>Facturar</h1>
        </div>

        <div class="contenido">
            <div class="cargando" v-if="cargando">Cargando…</div>

            <!-- Emitida. Se queda aquí, con el folio a la vista: es el dato que
                 el vendedor le dice al cliente y el que sirve para buscarla. -->
            <!-- Guardada sin señal. Se dice lo que es —y lo que no es— sin
                 rodeos: sin folio no hay documento, y el vendedor no tiene
                 ningún número que darle al cliente todavía. -->
            <template v-else-if="encolada">
                <Aviso tipo="info">
                    Guardada en la bandeja de salida. <b>Todavía no es una factura</b>: no tiene
                    número ni timbre, y no se le puede entregar al cliente.
                </Aviso>
                <p class="ayuda">
                    Se emite y se manda al SII sola, en cuanto el teléfono vea red. Llevará la
                    fecha de ese día, no la de hoy.
                </p>
                <button class="boton" @click="router.replace(`/notas-venta/${numero}`)">
                    Volver a la nota de venta
                </button>
            </template>

            <template v-else-if="emitida">
                <Aviso tipo="ok">
                    Factura <b>Nº {{ emitida.folio }}</b> emitida por
                    <b>{{ monto(emitida.total, emitida.moneda) }}</b>.
                </Aviso>
                <!-- Emitir y mandar son un solo acto, así que aquí se dice en
                     qué quedó el envío. Que falle no deshace la factura: el
                     folio ya se gastó y el servidor lo reintenta solo. -->
                <Aviso tipo="ok" v-if="sii?.enviado">
                    Enviada al SII. TrackID <b>{{ sii.track_id }}</b>. El veredicto tarda unos minutos.
                </Aviso>
                <Aviso tipo="error" v-else-if="sii">
                    <b>La factura quedó escrita, pero no llegó al SII:</b> {{ sii.error }}
                    El servidor lo reintenta solo; también se puede mandar a mano desde la ficha.
                </Aviso>
                <p class="ayuda">
                    Queda escrita en inventario y facturación.
                </p>
                <button class="boton" @click="router.replace(`/notas-venta/${numero}`)">
                    Volver a la nota de venta
                </button>
            </template>

            <template v-else>
                <!-- El error va **dentro** del documento, no en lugar de él. Lo
                     que falla aquí falla al emitir, con el documento ya
                     tecleado; sacarlo de la pantalla para poner el aviso obliga
                     a escribirlo entero otra vez, y encima deja sin ver qué se
                     iba a emitir. -->
                <Aviso tipo="error" v-if="error">{{ error }}</Aviso>

                <template v-if="propuesta">
                <!-- Los folios, antes que nada: enterarse de que no hay después
                     de teclear el documento es la peor forma de enterarse. -->
                <Aviso tipo="info" v-if="! conectado">
                    Sin señal. Lo que escribas queda en la bandeja y se emite solo al volver la
                    red: no se puede dar un número al cliente hasta entonces.
                </Aviso>

                <Aviso tipo="error" v-if="sinFolios">
                    <b>No quedan folios de factura.</b> Hay que pedirle un CAF nuevo al SII y
                    cargarlo en Softland. Hasta entonces no se puede emitir, ni desde aquí ni
                    desde el ERP.
                </Aviso>
                <Aviso tipo="info" v-else-if="propuesta.folios && propuesta.folios.libres <= 3">
                    Quedan <b>{{ propuesta.folios.libres }}</b>
                    {{ propuesta.folios.libres === 1 ? 'folio' : 'folios' }} de factura.
                    Ésta se llevaría el <b>Nº {{ propuesta.folios.siguiente }}</b>.
                </Aviso>

                <Aviso tipo="info" v-if="! propuesta.conocible">{{ propuesta.motivo }}</Aviso>

                <div class="tarjeta">
                    <div class="tarjeta-cabecera">Nota de venta Nº {{ propuesta.nota_venta }}</div>
                    <div class="tarjeta-cuerpo datos">
                        <!-- Se puede tocar sólo con el permiso. Sin él es texto,
                             no un botón desactivado: un control que no responde
                             invita a pelearse con él. -->
                        <div v-if="! propuesta.receptor_editable">
                            <span>Se le factura a</span><b>{{ cliente?.nombre || propuesta.cliente }}</b>
                        </div>
                        <button v-else class="fila-elegible" @click="abrirClientes">
                            <span>Se le factura a</span>
                            <b>
                                {{ cliente?.nombre || receptor }}
                                <AppIcon name="avanzar" :size="15" color="currentColor" />
                            </b>
                        </button>
                        <!-- La venta es de quien la hizo, no de quien la
                             factura. Se enseña porque el documento queda a su
                             nombre en el ERP y de ahí salen las comisiones. -->
                        <div v-if="propuesta.vendedor">
                            <span>Vendedor</span><b>{{ nombreDe('vendedores', propuesta.vendedor) }}</b>
                        </div>
                        <!-- Lo que la factura hereda de la venta, a la vista.
                             No se edita aquí: son datos de la venta, y
                             corregirlos en la factura dejaría dos verdades. -->
                        <div v-if="propuesta.condicion">
                            <span>Condición de pago</span>
                            <b>{{ nombreDe('condiciones_venta', propuesta.condicion) }}</b>
                        </div>
                        <div v-if="propuesta.centro_costo">
                            <span>Centro de costo</span><b>{{ propuesta.centro_costo }}</b>
                        </div>
                        <div v-if="propuesta.bodega">
                            <span>Bodega</span><b>{{ nombreDe('bodegas', propuesta.bodega) }}</b>
                        </div>
                    </div>
                </div>

                <!-- Qué documento es. Se pregunta, no se deduce del receptor:
                     facturarle la venta a otro RUT y cobrarle una comisión son
                     dos cosas distintas, y la diferencia es si lo emitido
                     descuenta o no el saldo de la venta. -->
                <template v-if="propuesta.receptor_editable">
                    <div class="seccion"><h2>Qué factura es</h2></div>
                    <div class="eleccion">
                        <label class="eleccion-fila" :class="{ activa: modo === 'venta' }">
                            <input type="radio" value="venta" v-model="modo">
                            <span>
                                <b>La venta</b>
                                <small>
                                    Los productos de la nota de venta, con su precio. Descuenta
                                    saldo. Se le puede facturar a otro RUT sin que eso cambie.
                                </small>
                            </span>
                        </label>
                        <label class="eleccion-fila" :class="{ activa: modo === 'comision' }">
                            <input type="radio" value="comision" v-model="modo">
                            <span>
                                <b>Una comisión</b>
                                <small>
                                    Un concepto que no está en la nota de venta, escrito a mano.
                                    Queda enlazada a ella y <b>no descuenta saldo</b>.
                                </small>
                            </span>
                        </label>
                    </div>
                </template>

                <!-- El aviso es del cambio de receptor, no del modo: el que
                     importa decir es que la venta se le va a cobrar a alguien
                     que no la hizo, y eso pasa en los dos casos. -->
                <Aviso tipo="info" v-if="aOtro">
                    <template v-if="esComision">
                        <b>Se le cobra a {{ cliente?.nombre || receptor }}</b>, no al cliente de la
                        nota de venta. Queda enlazada a la Nº {{ propuesta.nota_venta }} —se ve
                        desde su ficha—, pero <b>no le descuenta saldo</b>.
                    </template>
                    <template v-else>
                        <b>Esta factura sale a nombre de {{ cliente?.nombre || receptor }}</b>, que
                        no es el cliente de la nota de venta. Lleva los productos vendidos y
                        <b>sí le descuenta saldo</b>: lo que quede por facturar baja igual.
                    </template>
                </Aviso>
                <button class="chip-accion" v-if="aOtro" @click="volverAlDeLaNotaVenta">
                    <AppIcon name="atras" :size="16" color="currentColor" />
                    Volver a facturarle a {{ clienteNv?.nombre || propuesta.cliente }}
                </button>

                <!-- Lo que viaja de la venta a la factura y sí se puede
                     corregir: la OC llega muchas veces después de escribir la
                     venta, y la observación no siempre es la que va en el
                     documento tributario. -->
                <div class="seccion"><h2>Lo que va en la factura</h2></div>

                <label>Orden de compra del cliente</label>
                <input v-model="oc" type="text" placeholder="Sin orden de compra" maxlength="18">
                <p class="ayuda">
                    Va al DTE como referencia <b>Orden de Compra</b>. Es lo que le sirve a quien
                    recibe la factura para cuadrarla contra lo que encargó.
                </p>

                <label>Observación</label>
                <textarea v-model="observacion" rows="2"
                          placeholder="Lo que tiene que leer el cliente"></textarea>
                <p class="ayuda" v-if="observacionRecortada">
                    <b>No cabe entera.</b> En la factura caben {{ LARGO_GLOSA }} caracteres y
                    llevas {{ observacion.trim().length }}: se escribirá cortada ahí.
                </p>

                <!-- La comisión: líneas escritas a mano, sin enlace a las de la
                     nota de venta y sin consumir su saldo. -->
                <template v-if="esComision">
                    <div class="seccion">
                        <h2>Qué se le cobra</h2>
                        <button class="ver-todo" @click="abrirProductos">
                            Agregar línea <AppIcon name="crear" :size="15" color="currentColor" />
                        </button>
                    </div>

                    <Vacio v-if="! propias.length" icono="producto" titulo="Todavía no hay ninguna línea">
                        Agrega el concepto que se le cobra y escribe el monto. No se calcula solo:
                        la comisión la pone quien factura.
                    </Vacio>

                    <div class="linea-doc" v-for="(l, i) in propias" :key="i">
                        <div class="linea-cabecera">
                            <div>
                                <div class="item-titulo">{{ l.nombre }}</div>
                                <div class="item-meta">
                                    <span class="etiqueta gris">{{ l.producto }}</span>
                                    <span v-if="! l.afecto"> · exento</span>
                                </div>
                            </div>
                            <button class="icono-barra" title="Quitar" @click="propias.splice(i, 1)">
                                <AppIcon name="borrar" :size="18" variant="peligro" />
                            </button>
                        </div>
                        <label class="linea-detalle">
                            <span>Detalle que ve el cliente</span>
                            <textarea v-model="l.glosa" rows="2" :placeholder="l.nombre"></textarea>
                        </label>
                        <div class="linea-campos">
                            <Cantidad v-model.number="l.cantidad" :decimales="decimalesCantidad" />
                            <label>
                                <span>Precio</span>
                                <input v-model.number="l.precio" type="number" inputmode="decimal"
                                       min="0" step="any">
                            </label>
                        </div>
                        <div class="linea-total">
                            {{ cantidad(l.cantidad) }} {{ nombreDe('unidades', l.unidad) }}
                            · {{ monto(totales.lineas[i]?.total ?? 0, propuesta.moneda) }}
                        </div>
                    </div>
                </template>

                <Vacio v-else-if="! lineas.length" icono="factura" titulo="No queda nada por facturar">
                    Todas las líneas de esta nota de venta ya se facturaron.
                    <template v-if="propuesta.receptor_editable">
                        Si lo que vas a emitir es la comisión, elígelo arriba.
                    </template>
                </Vacio>

                <template v-else>
                    <div class="seccion"><h2>Qué se factura</h2></div>

                    <div class="linea-doc" v-for="l in lineas" :key="l.linea">
                        <div class="linea-cabecera">
                            <div>
                                <div class="item-titulo">{{ l.nombre }}</div>
                                <div class="item-meta">
                                    <span class="etiqueta gris">{{ l.producto }}</span>
                                    <span> · pendiente {{ cantidad(l.saldo) }} de {{ cantidad(l.pedida) }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="linea-campos">
                            <!-- Sin techo: facturar de más está permitido, y el
                                 aviso de abajo lo dice cuando pasa. -->
                            <Cantidad v-model.number="l.cantidad" :decimales="decimalesCantidad" />
                        </div>
                        <!-- El precio se enseña, no se edita: lo pone la nota de
                             venta. Un campo desactivado invita a pelearse con
                             él; no tenerlo dice mejor que no es una decisión de
                             quien factura. -->
                        <div class="linea-total">
                            {{ monto(l.precio, propuesta.moneda) }} {{ nombreDe('unidades', l.unidad) }}
                            · {{ monto((l.cantidad || 0) * l.precio, propuesta.moneda) }}
                        </div>
                    </div>

                    <Aviso tipo="info" v-if="deMas.length">
                        Vas a facturar más de lo que queda pendiente en
                        {{ deMas.length === 1 ? 'una línea' : `${deMas.length} líneas` }}.
                        Se puede; sólo conviene que sea a propósito.
                    </Aviso>
                </template>

                <!-- Los totales y el botón van fuera de las dos ramas: el
                     documento es uno solo, se facture al cliente de la nota de
                     venta o al mandante, y duplicarlos era el día en que uno de
                     los dos se quedaba sin un arreglo. -->
                <template v-if="enJuego.length">
                    <div class="tarjeta">
                        <div class="tarjeta-cabecera">Totales</div>
                        <div class="tarjeta-cuerpo datos">
                            <div><span>Neto</span><b>{{ monto(totales.afecto, propuesta.moneda) }}</b></div>
                            <div v-if="totales.exento">
                                <span>Exento</span><b>{{ monto(totales.exento, propuesta.moneda) }}</b>
                            </div>
                            <div><span>IVA</span><b>{{ monto(totales.iva, propuesta.moneda) }}</b></div>
                            <div class="fuerte"><span>Total</span><b>{{ monto(totales.total, propuesta.moneda) }}</b></div>
                        </div>
                    </div>

                    <button class="boton" :disabled="sinFolios || ! hayQueFacturar || trabajando"
                            @click="confirmando = true">
                        <template v-if="trabajando">Emitiendo…</template>
                        <template v-else-if="! conectado">Dejar en la bandeja</template>
                        <template v-else>Emitir la factura</template>
                    </button>
                    <p class="ayuda centrado" v-if="sinFolios">
                        Sin folios no hay documento que emitir.
                    </p>
                    <p class="ayuda centrado" v-else-if="esComision && ! hayQueFacturar">
                        Falta el monto: una línea en cero no cobra nada.
                    </p>
                </template>
                </template>
            </template>
        </div>

        <!-- Elegir a quién se le factura. Sólo llega aquí quien tiene el
             permiso: el botón que la abre no existe sin él. -->
        <div class="velo" v-if="eligiendoCliente" @click.self="eligiendoCliente = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Se le factura a</h2>
                    <button class="icono-barra" @click="eligiendoCliente = false">
                        <AppIcon name="cerrar" :size="21" />
                    </button>
                </div>
                <div class="hoja-cuerpo">
                    <Buscador v-model="busquedaCliente" placeholder="Nombre o RUT" />
                    <div class="item" v-for="c in clientesHallados" :key="c.codigo"
                         @click="elegirCliente(c.codigo)">
                        <div class="item-estado" :class="c.codigo === propuesta?.cliente ? 'verde' : 'cian'"></div>
                        <div class="item-cuerpo">
                            <div class="item-titulo">{{ c.nombre }}</div>
                            <div class="item-meta">
                                <span class="etiqueta gris">{{ c.rut || c.codigo }}</span>
                                <span v-if="c.codigo === propuesta?.cliente"> · el de la nota de venta</span>
                            </div>
                        </div>
                    </div>
                    <Vacio v-if="! clientesHallados.length" icono="sinResultados" titulo="Ningún cliente con eso">
                        Prueba con una palabra del nombre o con el RUT.
                    </Vacio>
                </div>
            </div>
        </div>

        <!-- Elegir el concepto que se le cobra al mandante. -->
        <div class="velo" v-if="eligiendoProducto" @click.self="eligiendoProducto = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Qué se le cobra</h2>
                    <button class="icono-barra" @click="eligiendoProducto = false">
                        <AppIcon name="cerrar" :size="21" />
                    </button>
                </div>
                <div class="hoja-cuerpo">
                    <Buscador v-model="busquedaProducto" placeholder="Nombre, código o código de barras" />
                    <div class="item" v-for="p in productosHallados" :key="p.codigo"
                         @click="agregarProducto(p)">
                        <div class="item-estado" :class="p.afecto ? 'cian' : 'amarillo'"></div>
                        <div class="item-cuerpo">
                            <div class="item-titulo item-titulo-producto">{{ p.nombre }}</div>
                            <div class="item-meta"><span class="etiqueta gris">{{ p.codigo }}</span></div>
                        </div>
                    </div>
                    <Vacio v-if="! productosHallados.length" icono="sinResultados" titulo="Ningún producto con eso">
                        Prueba con una palabra del nombre o con el código.
                    </Vacio>
                </div>
            </div>
        </div>

        <!-- La confirmación nombra el folio que va a gastar. Es lo último que
             se lee antes de consumir un número que no vuelve. -->
        <div class="velo" v-if="confirmando" @click.self="confirmando = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>{{ conectado ? 'Emitir la factura' : 'Guardar para emitir' }}</h2>
                    <button class="icono-barra" @click="confirmando = false">
                        <AppIcon name="cerrar" :size="21" />
                    </button>
                </div>
                <div class="hoja-cuerpo">
                    <template v-if="conectado">
                        <p>
                            Se emite la factura <b>Nº {{ propuesta?.folios?.siguiente }}</b> por
                            <b>{{ monto(totales.total, propuesta?.moneda) }}</b>
                            a {{ cliente?.nombre || propuesta?.cliente }},
                            <b>y se manda al SII</b>.
                        </p>
                        <!-- Lo último que se lee antes de gastar un folio
                             tiene que decir qué documento es, no sólo a quién
                             va: el efecto sobre el saldo de la venta es lo que
                             separa los dos, y es lo que no se puede deshacer
                             sin una nota de crédito. -->
                        <p class="ayuda" v-if="esComision">
                            Es la factura de <b>comisión</b>. Queda enlazada a la
                            Nº {{ propuesta?.nota_venta }} y <b>no le descuenta saldo</b>.
                        </p>
                        <p class="ayuda" v-else-if="aOtro">
                            Son los productos de la nota de venta Nº {{ propuesta?.nota_venta }}
                            facturados a <b>otro RUT</b>. <b>Le descuenta saldo.</b>
                        </p>
                        <p class="ayuda">
                            Un folio emitido no se devuelve. Lo que salga mal se corrige con una
                            nota de crédito, no borrando.
                        </p>
                    </template>
                    <template v-else>
                        <p>
                            Queda guardada una factura por <b>{{ monto(totales.total, propuesta?.moneda) }}</b>
                            a {{ cliente?.nombre || propuesta?.cliente }}, que se emitirá al volver
                            la señal.
                        </p>
                        <p class="ayuda">
                            Todavía no tiene número ni timbre: el folio lo reparte Softland. Llevará
                            la fecha del día en que se emita.
                        </p>
                    </template>
                    <button class="boton" :disabled="trabajando" @click="emitir">
                        <template v-if="trabajando">Emitiendo…</template>
                        <template v-else-if="conectado">Emitir</template>
                        <template v-else>Guardar en la bandeja</template>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
