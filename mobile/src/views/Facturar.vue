<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';
import { idb } from '../idb';
import { monto, nombre as nombreDe } from '../catalogos';
import { calcularTotales } from '../documentos';
import { propuestaLocal } from '../saldo';
import { encolar, nuevoUuid } from '../pendientes';
import { conectado } from '../red';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
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
 */

const route = useRoute();
const router = useRouter();

const numero = computed(() => Number(route.params.numero));

const propuesta = ref(null);
const lineas = ref([]);
const cliente = ref(null);
const cargando = ref(true);
const error = ref('');
const trabajando = ref(false);
const confirmando = ref(false);
const emitida = ref(null);
const encolada = ref(false);
const sii = ref(null);

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    error.value = '';
    try {
        // Con señal manda el servidor, que ve lo que el ERP facturó hace un
        // minuto. Sin señal se calcula aquí, que es lo mismo mientras el
        // almacén esté al día — y es lo que permite dejar la factura escrita
        // en terreno en vez de confiar en que alguien se acuerde después.
        const r = conectado.value
            ? await api.propuestaFactura(numero.value)
            : await propuestaLocal(numero.value);

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
        cliente.value = await idb.obtener('clientes', r.cliente);
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

const totales = computed(() => calcularTotales(lineas.value.filter((l) => l.cantidad > 0)));

// Sin folios no se emite. Ojo con el caso sin señal: ahí `folios` es `null`,
// que quiere decir «no se sabe» y no «no quedan» — bloquear ahí sería impedir
// justo lo que la bandeja vino a permitir.
const sinFolios = computed(() => !! propuesta.value?.folios && propuesta.value.folios.libres <= 0);
const hayQueFacturar = computed(() => lineas.value.some((l) => l.cantidad > 0));

/** Facturar de más está permitido, pero tiene que verse. */
const deMas = computed(() => lineas.value.filter((l) => l.cantidad > l.saldo + 0.0001));

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
        nota_venta: numero.value,
        receptor: propuesta.value.cliente,
        centro_costo: propuesta.value.centro_costo,
        condicion: propuesta.value.condicion,
        lineas: lineas.value
            .filter((l) => l.cantidad > 0)
            .map((l) => ({ producto: l.producto, cantidad: l.cantidad, nv_linea: l.linea })),
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
                        <div><span>Se le factura a</span><b>{{ cliente?.nombre || propuesta.cliente }}</b></div>
                        <!-- La venta es de quien la hizo, no de quien la
                             factura. Se enseña porque el documento queda a su
                             nombre en el ERP y de ahí salen las comisiones. -->
                        <div v-if="propuesta.vendedor">
                            <span>Vendedor</span><b>{{ nombreDe('vendedores', propuesta.vendedor) }}</b>
                        </div>
                        <div v-if="propuesta.centro_costo">
                            <span>Centro de costo</span><b>{{ propuesta.centro_costo }}</b>
                        </div>
                    </div>
                </div>
                <!-- Por usuario, no por empresa: lo dice el permiso que Softland
                     le tenga concedido a éste, cruzado con la llave de la
                     configuración. Quien no lo tenga no ve esta línea. -->
                <p class="ayuda" v-if="propuesta.receptor_editable">
                    Tu usuario puede facturarle a otro cliente. Eso se hace desde Softland;
                    aquí se factura al de la nota de venta.
                </p>

                <Vacio v-if="! lineas.length" icono="factura" titulo="No queda nada por facturar">
                    Todas las líneas de esta nota de venta ya se facturaron.
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
                            <label>
                                <span>Cantidad</span>
                                <input v-model.number="l.cantidad" type="number" inputmode="decimal"
                                       min="0" step="any">
                            </label>
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

                    <div class="tarjeta">
                        <div class="tarjeta-cabecera">Totales</div>
                        <div class="tarjeta-cuerpo datos">
                            <div><span>Neto</span><b>{{ monto(totales.afecto, propuesta.moneda) }}</b></div>
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
                </template>
                </template>
            </template>
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
