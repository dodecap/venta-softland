<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { idb } from '../idb';
import { monto, nombre as nombreDe } from '../catalogos';
import { calcularTotales } from '../documentos';
import { encolar, nuevoUuid } from '../pendientes';
import { conectado } from '../red';
import { useCapa } from '../nav';
import { disponible as escanerDisponible } from '../escaner';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Cantidad from '../components/Cantidad.vue';
import Buscador from '../components/Buscador.vue';
import CabeceraFactura from '../components/CabeceraFactura.vue';
import CargaProductos from '../components/CargaProductos.vue';
import ReferenciasDte from '../components/ReferenciasDte.vue';
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
 *
 * ## La cabecera es la misma que la de cualquier factura
 *
 * Y tiene que estarlo. Durante un tiempo esta pantalla preguntaba el cliente, el
 * vendedor y la glosa, y **mandaba el centro de costo y la condición de venta
 * sin enseñarlos**: salían del usuario y de la ficha del cliente, nadie los veía
 * y no había forma de corregirlos. En un documento tributario eso no es un campo
 * que falta, es un dato escrito a ciegas. Los campos viven en
 * `CabeceraFactura.vue`, que es el mismo que usa la factura de una nota de venta.
 *
 * ## Y aquí las referencias importan más que en ninguna parte
 *
 * Una factura suelta no tiene nota de venta que la explique. Si el cliente exige
 * que se nombre su HES, su contrato o su resolución —y las eléctricas y las
 * forestales lo exigen—, este es el único sitio donde se puede decir.
 */

const router = useRouter();

const form = ref({
    cliente: '',
    vendedor: '',
    moneda: '01',
    // Vacíos y no `null`: son el `v-model` de un `<select>`, y ahí el hueco es
    // la cadena vacía. Lo que se manda al servidor se traduce a `null` al emitir.
    contacto: '',
    condicion: '',
    centro_costo: '',
    bodega: '',
    oc: '',
    glosa: '',
    // Los papeles que esta factura nombra además de los suyos.
    referencias: [],
    lineas: [],
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
const encolada = ref(false);
const sii = ref(null);

const eligiendoCliente = ref(false);
const busquedaCliente = ref('');
const clientesHallados = ref([]);

/** Si la orden de compra sale nombrada en el DTE. Lo dice la empresa. */
const refOrdenCompra = ref(true);

/*
 * El mismo bucle de carga que el editor: se busca o se escanea, se pone la
 * cantidad y se sigue, sin cerrar la hoja entre producto y producto. Aquí pesa
 * más todavía, porque una factura sin nota de venta detrás se escribe entera a
 * mano.
 */
const agregandoProductos = ref(false);
const modoCarga = ref('buscar');
const decimalesCantidad = ref(3);
const hayCamara = ref(false);
escanerDisponible().then((si) => { hayCamara.value = si; });

// Pase lo que pase, «atrás» de Android cierra la hoja. Importa más que en las
// otras capas: con el escáner encendido la página está transparente, y quedarse
// sin forma de salir es quedarse mirando la cámara.
useCapa(agregandoProductos, () => { agregandoProductos.value = false; });

const encabezadoListo = computed(() => !! (form.value.cliente && form.value.vendedor));

function abrirCarga(modo) {
    modoCarga.value = modo;
    agregandoProductos.value = true;
}

function yaEnDocumento(codigo) {
    return form.value.lineas
        .filter((l) => l.producto === codigo)
        .reduce((t, l) => t + (Number(l.cantidad) || 0), 0);
}

onMounted(async () => {
    try {
        usuario.value = await db.getUsuario();
        form.value.centro_costo = usuario.value?.cod_cc || '';
        // Sin nota de venta detrás no hay de quién heredar el vendedor, así
        // que se elige. Se propone el propio, que es lo normal; un
        // administrador no tiene y tiene que decirlo.
        form.value.vendedor = usuario.value?.ven_cod || '';
        const info = await db.getServidorInfo();
        uf.value = Number(info?.uf) || null;
        // `?? 3` y no `|| 3`: cero decimales es una respuesta, no un hueco.
        decimalesCantidad.value = Number(info?.cant_decimales ?? 3);
        // Si la orden de compra sale nombrada en el DTE lo decide la empresa. El
        // teléfono lo sabe del arranque para no anunciar un renglón que no va a
        // salir; quien manda sigue siendo el servidor al emitir.
        refOrdenCompra.value = info?.referencia_orden_compra !== false;
        // Sin señal no hay forma de saber cuántos folios quedan: los reparte
        // Softland y no hay copia en el teléfono. Queda en `null`, que es «no
        // se sabe» y no «no quedan».
        folios.value = conectado.value ? (await api.foliosFactura()).folios : null;
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
    const anterior = form.value.cliente;

    form.value.cliente = codigo;
    cliente.value = await idb.obtener('clientes', codigo);
    eligiendoCliente.value = false;

    // Cambiar de cliente se lleva a su contacto: es una persona de esa empresa.
    if (codigo !== anterior) form.value.contacto = '';

    // Se propone, no se impone: el campo está a la vista y se puede cambiar.
    form.value.condicion = form.value.condicion || cliente.value?.condicion || '';
}

function agregarProducto(p, cuantos = 1) {
    // Dos veces el mismo producto son dos unidades, no dos líneas iguales: se
    // suma sobre la que ya está, sin tocarle el precio, que es el que se puso.
    const ya = form.value.lineas.find((l) => l.producto === p.codigo);
    if (ya) {
        ya.cantidad = (Number(ya.cantidad) || 0) + (Number(cuantos) || 0);

        return;
    }

    form.value.lineas.push({
        producto: p.codigo,
        nombre: p.nombre,
        glosa: p.nombre || '',
        unidad: p.unidad || '',
        afecto: !! p.afecto,
        cantidad: Number(cuantos) || 1,
        precio: aPesos(p.precio, p.moneda),
        descuento_pct: 0,
    });
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
const sinFolios = computed(() => !! folios.value && folios.value.libres <= 0);

/*
 * Las referencias que van a salir solas, para poder enseñarlas.
 *
 * Aquí es una sola —la orden de compra— porque no hay nota de venta detrás. Se
 * calcula con la misma llave que usa el servidor, que viaja en el arranque: sin
 * ella la pantalla anunciaría un renglón que la empresa tiene apagado.
 */
const refsAutomaticas = computed(() => (
    refOrdenCompra.value && form.value.oc.trim()
        ? [{ tipo_sii: '801', folio: form.value.oc.trim() }]
        : []
));

/**
 * Una referencia a medias no se emite.
 *
 * Sin tipo o sin folio el DTE sale con un `<Referencia>` incompleto y el SII lo
 * rechaza — con el folio ya gastado. Se para antes, no después.
 */
const refsAMedias = computed(() => form.value.referencias.some(
    (r) => ! String(r.tipo_sii || '').trim() || ! String(r.folio || '').trim()
));

const puedeEmitir = computed(
    () => !! form.value.cliente && !! form.value.vendedor && form.value.lineas.length > 0
        && form.value.lineas.every((l) => l.cantidad > 0)
        && ! refsAMedias.value
        && ! sinFolios.value
);

/**
 * Emitir, que es escribir el documento **y mandarlo al SII** de una vez.
 *
 * Sin señal queda en la bandeja y sale sola al volver la red. Lo guardado no es
 * una factura: no tiene folio ni timbre, porque los dos los pone el servidor.
 */
async function emitir() {
    confirmando.value = false;
    trabajando.value = true;
    error.value = '';

    const doc = {
        client_uuid: nuevoUuid(),
        receptor: form.value.cliente,
        vendedor: form.value.vendedor,
        // Lo que describe el documento. Vacío es `null`: el hueco de un
        // `<select>` es la cadena vacía, y en la base es una columna sin valor.
        contacto: form.value.contacto || null,
        centro_costo: form.value.centro_costo || null,
        condicion: form.value.condicion || null,
        bodega: form.value.bodega || null,
        oc: form.value.oc.trim() || null,
        glosa: form.value.glosa.trim().slice(0, 255) || null,
        // Sólo las completas. Las que están a medias ya impiden emitir, y esto
        // es el último filtro por si algo quedó a medio teclear.
        referencias: form.value.referencias
            .filter((r) => String(r.tipo_sii || '').trim() && String(r.folio || '').trim())
            .map((r) => ({
                tipo_sii: String(r.tipo_sii).trim(),
                folio: String(r.folio).trim(),
                fecha: r.fecha || null,
                glosa: (r.glosa || '').trim() || null,
            })),
        lineas: form.value.lineas.map((l) => ({
            producto: l.producto,
            cantidad: l.cantidad,
            precio: l.precio,
            glosa: l.glosa || null,
        })),
    };

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
            <h1>Nueva factura</h1>
        </div>

        <div class="contenido"
             :class="{ 'con-barra-cargar': encabezadoListo && ! cargando && ! emitida && ! encolada }">
            <div class="cargando" v-if="cargando">Cargando…</div>

            <!-- Emitida. El folio a la vista: es el dato que se le dice al
                 cliente y el que sirve para buscarla. -->
            <template v-else-if="encolada">
                <Aviso tipo="info">
                    Guardada en la bandeja de salida. <b>Todavía no es una factura</b>: no tiene
                    número ni timbre, y no se le puede entregar al cliente.
                </Aviso>
                <p class="ayuda">
                    Se emite y se manda al SII sola en cuanto el teléfono vea red, con la fecha de
                    ese día.
                </p>
                <button class="boton" @click="router.replace('/facturas')">Ver las facturas</button>
            </template>

            <template v-else-if="emitida">
                <Aviso tipo="ok">
                    Factura <b>Nº {{ emitida.folio }}</b> emitida por
                    <b>{{ monto(emitida.total, emitida.moneda) }}</b>.
                </Aviso>
                <Aviso tipo="ok" v-if="sii?.enviado">
                    Enviada al SII. TrackID <b>{{ sii.track_id }}</b>. El veredicto tarda unos minutos.
                </Aviso>
                <Aviso tipo="error" v-else-if="sii">
                    <b>La factura quedó escrita, pero no llegó al SII:</b> {{ sii.error }}
                    El servidor lo reintenta solo.
                </Aviso>
                <p class="ayuda">
                    Queda escrita en inventario y facturación.
                </p>
                <button class="boton" @click="router.replace('/facturas')">Ver las facturas</button>
            </template>

            <template v-else>
                <Aviso tipo="info" v-if="! conectado">
                    Sin señal. Lo que escribas queda en la bandeja y se emite solo al volver la
                    red: no se puede dar un número al cliente hasta entonces.
                </Aviso>

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

                <CabeceraFactura v-model:contacto="form.contacto"
                                 v-model:condicion="form.condicion"
                                 v-model:centro-costo="form.centro_costo"
                                 v-model:bodega="form.bodega"
                                 v-model:oc="form.oc"
                                 v-model:glosa="form.glosa"
                                 :receptor="form.cliente" />

                <ReferenciasDte v-model="form.referencias" :automaticas="refsAutomaticas" />

                <div class="seccion">
                    <h2>Detalle</h2>
                    <button class="ver-todo" :disabled="! encabezadoListo" @click="abrirCarga('buscar')">
                        Agregar producto <AppIcon name="crear" :size="15" color="currentColor" />
                    </button>
                </div>

                <Vacio v-if="! form.lineas.length && ! encabezadoListo" icono="cliente"
                       titulo="Primero, para quién">
                    Elige el cliente y el vendedor. Después aparece abajo la barra para ir
                    cargando productos.
                </Vacio>
                <Vacio v-else-if="! form.lineas.length" icono="producto" titulo="Todavía no hay productos">
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
                        <Cantidad v-model.number="l.cantidad" :decimales="decimalesCantidad" />
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
                    <template v-if="trabajando">Emitiendo…</template>
                    <template v-else-if="! conectado">Dejar en la bandeja</template>
                    <template v-else>Emitir la factura</template>
                </button>
                <p class="ayuda centrado" v-if="! puedeEmitir && ! sinFolios">
                    <template v-if="! form.cliente">Falta elegir el cliente.</template>
                    <template v-else-if="! form.vendedor">Falta elegir el vendedor.</template>
                    <template v-else-if="! form.lineas.length">Falta agregar al menos un producto.</template>
                    <template v-else-if="refsAMedias">
                        Hay una referencia sin tipo o sin folio: el SII rechazaría el documento.
                    </template>
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

        <!-- Cargar productos, de corrido: buscar o escanear, cantidad, y seguir. -->
        <CargaProductos v-if="agregandoProductos" :modo="modoCarga"
                        :decimales="decimalesCantidad" :ya-en="yaEnDocumento"
                        @agregar="agregarProducto" @cerrar="agregandoProductos = false" />

        <!--
            La barra de cargar productos. Pegada al fondo, no flotante: el botón
            flotante es de las pestañas y ésta es una pantalla de adentro.
        -->
        <div class="barra-cargar"
             v-if="encabezadoListo && ! agregandoProductos && ! cargando && ! emitida && ! encolada">
            <div class="resumen">
                <b v-if="form.lineas.length">{{ monto(totales.total, form.moneda) }}</b>
                <b v-else>Agregar productos</b>
                <span v-if="form.lineas.length">
                    {{ form.lineas.length }} {{ form.lineas.length === 1 ? 'línea' : 'líneas' }}
                </span>
                <span v-else>Buscando por nombre o leyendo el código</span>
            </div>
            <button class="accion" aria-label="Buscar productos" @click="abrirCarga('buscar')">
                <AppIcon name="buscar" :size="22" color="currentColor" />
            </button>
            <button class="accion principal" v-if="hayCamara" aria-label="Escanear código de barras"
                    @click="abrirCarga('escanear')">
                <AppIcon name="codigoBarras" :size="22" color="currentColor" />
            </button>
        </div>

        <!-- La confirmación nombra el folio que va a gastar. -->
        <div class="velo" v-if="confirmando" @click.self="confirmando = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>{{ conectado ? 'Emitir la factura' : 'Guardar para emitir' }}</h2>
                    <button class="icono-barra" @click="confirmando = false"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <template v-if="conectado">
                        <p>
                            Se emite la factura <b>Nº {{ folios?.siguiente }}</b> por
                            <b>{{ monto(totales.total, form.moneda) }}</b> a {{ cliente?.nombre }},
                            <b>y se manda al SII</b>.
                        </p>
                        <p class="ayuda">
                            Un folio emitido no se devuelve. Lo que salga mal se corrige con una
                            nota de crédito, no borrando.
                        </p>
                    </template>
                    <template v-else>
                        <p>
                            Queda guardada una factura por <b>{{ monto(totales.total, form.moneda) }}</b>
                            a {{ cliente?.nombre }}, que se emitirá al volver la señal.
                        </p>
                        <p class="ayuda">
                            Todavía no tiene número ni timbre. Llevará la fecha del día en que se
                            emita, no la de hoy.
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
