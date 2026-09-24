<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '../api';
import { idb } from '../idb';
import { conectado } from '../red';
import { monto, nombre as nombreDe } from '../catalogos';
import { abrir as abrirEscaner, cerrar as cerrarEscaner } from '../escaner';
import AppIcon from './AppIcon.vue';
import Aviso from './Aviso.vue';
import Buscador from './Buscador.vue';
import Cantidad from './Cantidad.vue';
import Vacio from './Vacio.vue';

/**
 * Cargar productos en un documento, de corrido.
 *
 * ## Qué problema resuelve
 *
 * Antes, agregar un producto era: abrir la hoja, buscar, tocar el producto, la
 * hoja se cerraba, bajar hasta la línea recién creada, tocar la cantidad,
 * teclear, y **volver a abrir la hoja** para el siguiente. Siete gestos por
 * producto y una hoja que se abre y se cierra una vez por cada uno. Un
 * documento de quince líneas se hacía en la oficina, no en terreno.
 *
 * Aquí el bucle no se corta: se busca, se toca el producto, se pone la
 * cantidad, y «Agregar y seguir» devuelve al campo de búsqueda vacío y
 * enfocado, sin que el teclado llegue a bajarse. Dos gestos por producto, y
 * «Terminar» siempre a la vista.
 *
 * ## Dos maneras de nombrar el producto, un solo bucle
 *
 * La lupa y la cámara son la misma pantalla con la primera mitad cambiada: una
 * busca por texto y la otra lee el código de barras. Lo que pasa después —la
 * cantidad, el «y seguir», la cuenta de lo que se lleva— es idéntico, y por eso
 * está escrito una sola vez. Se salta de una a otra sin salir ni perder lo
 * agregado.
 *
 * ## Suma, no duplica
 *
 * Escanear dos veces la misma caja son dos unidades de un producto, no dos
 * líneas iguales en el documento. Quien recibe el `agregar` es quien suma; aquí
 * sólo se enseña lo que ya lleva, para que se note que sumó.
 *
 * ## Lo que no hace
 *
 * No toca precios ni monedas. Emite el producto del maestro y una cantidad; el
 * precio de lista, la conversión de moneda y la línea del documento son del
 * formulario que lo abrió, porque no se arman igual en una cotización que en
 * una factura.
 *
 * ## Lo único que sí escribe: el código que nadie tenía
 *
 * El maestro viene casi vacío —134 códigos de 1.229 productos— así que un
 * escáner que sólo lea lo que ya está escrito no sirve el primer día. Cuando
 * lee uno que no conoce, ofrece decir de qué producto es, y eso queda en
 * `iw_tprod` para toda la empresa. El servidor pone las condiciones; aquí sólo
 * se pregunta y se enseña lo que conteste.
 */

const props = defineProps({
    /** Con cuál de las dos mitades se entra: 'buscar' o 'escanear'. */
    modo: { type: String, default: 'buscar' },
    /** Decimales que admite la empresa (`iwparam.CantDecimales`). */
    decimales: { type: Number, default: 3 },
    /** Cuánto lleva ya el documento de ese producto. Para poder decirlo. */
    yaEn: { type: Function, default: () => 0 },
});

const emit = defineEmits(['agregar', 'cerrar']);

/** buscar · escanear · cantidad */
const paso = ref(props.modo === 'escanear' ? 'escanear' : 'buscar');
const anterior = ref(paso.value);

const agregados = ref(0);
const error = ref('');

// ------------------------------------------------------------- buscar

const busqueda = ref('');
const hallados = ref([]);

// El mismo guardián de turno que el resto de la app: la carga inicial puede
// resolver después de la búsqueda tecleada y pisarla en silencio.
let turno = 0;

async function refrescar(q) {
    const mio = ++turno;
    const filas = await idb.buscar('productos', q, { limite: 30 });
    if (mio === turno) hallados.value = filas;
}

watch(busqueda, async (q) => {
    await refrescar(q);

    /*
     * Un código de barras tecleado entero se adelanta solo.
     *
     * No es por el pulgar: es por los lectores de mano, que escriben el código
     * en el campo como si fuera un teclado. Sin esto habría que tocar el único
     * resultado que sale.
     *
     * Sólo por `barra` y sólo desde ocho dígitos. Por código de producto no,
     * porque los códigos cortos son prefijo unos de otros —tecleando «1001» se
     * saltaría en «100», que existe y es otra cosa— y ocho es el largo del
     * EAN-8, el código de comercio más corto que hay.
     */
    const codigo = (q || '').trim();
    if (codigo.length >= 8 && /^\d+$/.test(codigo)) {
        const p = hallados.value.find((x) => (x.barra || '').trim() === codigo);
        if (p) elegir(p);
    }
});

// ------------------------------------------------------------ escanear

const mando = ref(null);
const linterna = ref(false);
const hayLinterna = ref(false);
/** Un código leído que ningún producto tiene. Se enseña, no se traga. */
const desconocido = ref('');

/**
 * El código que se está enseñando, mientras se elige de qué producto es.
 *
 * Vacío casi siempre. Mientras vale algo, la búsqueda deja de ser «cuál
 * agrego» y pasa a ser «a cuál le pertenece esto», y eso hay que decirlo en
 * pantalla: son dos preguntas distintas sobre la misma lista.
 */
const aprendiendo = ref('');
const aprendiendoError = ref('');

async function encender() {
    error.value = '';
    desconocido.value = '';

    try {
        mando.value = await abrirEscaner({ alLeer: leido });
        hayLinterna.value = await mando.value.linternaDisponible();
        linterna.value = false;
    } catch (e) {
        mando.value = null;
        error.value = e?.sinPermiso
            ? 'Sin permiso para usar la cámara. Se concede desde los ajustes de Android.'
            : (e?.message || 'No se pudo encender la cámara.');
        paso.value = 'buscar';
    }
}

async function apagar() {
    mando.value = null;
    hayLinterna.value = false;
    linterna.value = false;
    await cerrarEscaner();
}

async function alternarLinterna() {
    if (! mando.value) return;
    linterna.value = await mando.value.alternarLinterna();
}

/**
 * De un código leído al producto que lo lleva.
 *
 * Se prueba el código tal cual y, si no está, con y sin el cero de delante: un
 * UPC-A de doce dígitos y el EAN-13 del mismo producto se diferencian
 * exactamente en ese cero, y cuál de los dos quedó escrito en `iw_tprod`
 * depende de quién cargó el maestro.
 */
async function porCodigoDeBarras(codigo) {
    const pruebas = [codigo];
    if (codigo.length === 12) pruebas.push(`0${codigo}`);
    if (codigo.length === 13 && codigo.startsWith('0')) pruebas.push(codigo.slice(1));

    for (const c of pruebas) {
        const filas = await idb.buscar('productos', c, {
            limite: 5,
            filtro: (p) => (p.barra || '').trim() === c,
        });
        if (filas.length) return filas[0];
    }

    return null;
}

async function leido(codigo) {
    const p = await porCodigoDeBarras(codigo);

    if (! p) {
        desconocido.value = codigo;

        return;
    }

    desconocido.value = '';
    mando.value?.pausar();
    elegir(p);
}

/** Del código que nadie tiene a la búsqueda a mano, con el código ya puesto. */
function buscarloAMano() {
    busqueda.value = desconocido.value;
    desconocido.value = '';
    aprendiendo.value = '';
    irABuscar();
}

/**
 * Del código que nadie tiene al producto al que pertenece.
 *
 * Apaga la cámara para elegir —hace falta leer— y la vuelve a encender después
 * de guardar: el que enseña un código está escaneando una estantería, y
 * devolverlo a la lista de búsqueda le cortaría el trabajo.
 */
function asignarloAUnProducto() {
    aprendiendo.value = desconocido.value;
    aprendiendoError.value = '';
    desconocido.value = '';
    busqueda.value = '';
    irABuscar();
}

function dejarDeAprender() {
    aprendiendo.value = '';
    aprendiendoError.value = '';
}

// -------------------------------------------------------------- cantidad

const elegido = ref(null);
const cantidad = ref(1);
const campoCantidad = ref(null);

function elegir(p) {
    anterior.value = paso.value === 'cantidad' ? anterior.value : paso.value;
    elegido.value = p;
    cantidad.value = 1;
    paso.value = 'cantidad';

    // Enfocado **y seleccionado**: así teclear «40» reemplaza el 1 en vez de
    // dejar 140, que es el error que se descubre al firmar el documento.
    nextTick(() => campoCantidad.value?.enfocar());
}

const llevaba = computed(() => (elegido.value ? Number(props.yaEn(elegido.value.codigo)) || 0 : 0));

const puedeAgregar = computed(() => !! elegido.value && Number(cantidad.value) > 0);

/**
 * Agrega y vuelve a lo anterior.
 *
 * El `enfocar()` va **antes** del emit y sin `await` por delante: Android sólo
 * abre el teclado si el foco cuelga del toque que lo pidió, y basta una
 * promesa en medio para perder ese permiso y dejar el campo enfocado con el
 * teclado abajo.
 */
const campoBusqueda = ref(null);

const guardandoCodigo = ref(false);

async function agregarYSeguir() {
    if (! puedeAgregar.value || guardandoCodigo.value) return;

    const p = elegido.value;
    const c = Number(cantidad.value);

    /*
     * Si se está enseñando un código, se escribe **antes** de agregar la línea.
     *
     * Y si el servidor dice que no —el producto ya tenía otro, o ese código es
     * de otro producto—, la línea se agrega igual: el producto que eligió el
     * vendedor es el correcto, lo que no se pudo guardar es el atajo para la
     * próxima vez. Perder también la línea sería castigar dos veces.
     */
    if (aprendiendo.value) {
        guardandoCodigo.value = true;
        aprendiendoError.value = '';
        try {
            const barra = aprendiendo.value;
            await api.aprenderCodigoBarras(p.codigo, barra);
            // Y que valga ya, sin esperar a la próxima descarga: se conserva
            // el sello de la fila para que el barrido no se la lleve.
            await idb.guardar('productos', [{ ...p, barra }]);
            p.barra = barra;
        } catch (e) {
            /*
             * No se reintenta solo. El motivo del rechazo es del estado de la
             * base —ese producto ya tiene otro código, o ese código ya es de
             * otro— y no va a cambiar por volver a mandarlo: reintentar sería
             * ofrecer un botón que no puede funcionar. Se suelta lo de
             * aprender y el siguiente toque agrega la línea, que es lo que de
             * verdad se estaba haciendo.
             */
            aprendiendoError.value = e.message;
            aprendiendo.value = '';
            guardandoCodigo.value = false;

            return;
        }
        guardandoCodigo.value = false;

        // Se volvió a la lista sólo para elegir el producto; el trabajo estaba
        // en la estantería, y ahí es donde hay que devolverlo.
        anterior.value = 'escanear';
        aprendiendo.value = '';
    }

    paso.value = anterior.value;
    elegido.value = null;
    agregados.value += 1;

    if (paso.value === 'buscar') {
        busqueda.value = '';
        nextTick(() => campoBusqueda.value?.enfocar());
    } else if (mando.value) {
        mando.value.reanudar();
    } else {
        // Se venía de enseñar un código: la cámara estaba apagada para poder
        // leer la lista y hay que volver a encenderla.
        encender();
    }

    emit('agregar', p, c);
}

function volver() {
    elegido.value = null;
    aprendiendoError.value = '';
    paso.value = anterior.value;
    if (paso.value === 'escanear') mando.value?.reanudar();
    else nextTick(() => campoBusqueda.value?.enfocar());
}

// ----------------------------------------------------------- las dos mitades

async function irAEscanear() {
    if (paso.value === 'escanear') return;
    paso.value = 'escanear';
    await encender();
}

async function irABuscar() {
    paso.value = 'buscar';
    await apagar();
    await refrescar(busqueda.value);
    nextTick(() => campoBusqueda.value?.enfocar());
}

function terminar() {
    emit('cerrar');
}

onMounted(async () => {
    if (paso.value === 'escanear') await encender();
    else {
        await refrescar('');
        nextTick(() => campoBusqueda.value?.enfocar());
    }
});

// Pase lo que pase —«atrás» de Android, un cambio de ruta, un error—, la cámara
// se apaga aquí. Es lo único que esta pantalla puede dejar encendido.
onBeforeUnmount(() => { cerrarEscaner(); });
</script>

<template>
    <!-- La mitad de la cámara va fuera de `#app` a propósito: el vídeo se
         dibuja **por detrás** del navegador, así que la página entera tiene que
         volverse transparente y sólo puede quedar visible lo que esté fuera de
         ella. Ver `body.escaneando` en `style.css`. -->
    <Teleport to="body">
        <!-- `sobre-camara` mientras la cámara siga encendida, que no es lo mismo
             que estar escaneando: al pedir la cantidad el vídeo sigue puesto
             —volver tiene que ser instantáneo— y la página sigue transparente,
             así que esta capa tiene que seguir siendo la única visible.
             `en-camara` es el paso, y es lo que quita el velo. -->
        <div class="carga-productos"
             :class="{ 'sobre-camara': !! mando, 'en-camara': paso === 'escanear' }">

            <!-- ---------------- cantidad ---------------- -->
            <div class="hoja carga-hoja" v-if="paso === 'cantidad'">
                <div class="hoja-cabecera">
                    <button class="icono-barra" aria-label="Volver" @click="volver">
                        <AppIcon name="atras" :size="21" />
                    </button>
                    <h2>{{ aprendiendo ? 'Producto y cantidad' : 'Cantidad' }}</h2>
                    <button class="boton-terminar" @click="terminar">Terminar</button>
                </div>
                <div class="hoja-cuerpo">
                    <div class="carga-elegido">
                        <div class="item-titulo">{{ elegido?.nombre }}</div>
                        <div class="item-linea">
                            {{ monto(elegido?.precio, elegido?.moneda) }}
                            / {{ nombreDe('unidades', elegido?.unidad) }}
                        </div>
                        <div class="item-meta">
                            <span class="etiqueta gris">{{ elegido?.codigo }}</span>
                            <span v-if="elegido?.barra" class="etiqueta gris">{{ elegido.barra }}</span>
                            <span v-if="! elegido?.afecto"> · exento</span>
                        </div>
                    </div>

                    <Aviso tipo="info" v-if="aprendiendo">
                        Además se le va a guardar el código <b>{{ aprendiendo }}</b>, y desde
                        entonces lo reconoce toda la empresa.
                    </Aviso>
                    <Aviso tipo="error" v-if="aprendiendoError">
                        <b>El código no se pudo guardar.</b> {{ aprendiendoError }}
                        El producto sí se puede agregar.
                    </Aviso>

                    <Cantidad ref="campoCantidad" v-model.number="cantidad"
                              :decimales="decimales" :min="0" etiqueta="Cuántos" />

                    <p class="ayuda" v-if="llevaba > 0">
                        El documento ya lleva {{ llevaba }} de este producto. Se suman.
                    </p>

                    <button class="boton" :disabled="! puedeAgregar || guardandoCodigo"
                            @click="agregarYSeguir">
                        <template v-if="guardandoCodigo">Guardando el código…</template>
                        <template v-else-if="aprendiendo">Guardar el código y agregar</template>
                        <template v-else-if="aprendiendoError">Agregar de todos modos</template>
                        <template v-else>Agregar y seguir</template>
                    </button>
                </div>
                <div class="carga-pie" v-if="agregados">
                    {{ agregados }} {{ agregados === 1 ? 'producto agregado' : 'productos agregados' }}
                </div>
            </div>

            <!-- ---------------- buscar ---------------- -->
            <div class="hoja carga-hoja" v-else-if="paso === 'buscar'">
                <div class="hoja-cabecera">
                    <h2>Agregar productos</h2>
                    <button class="icono-barra" aria-label="Escanear" @click="irAEscanear">
                        <AppIcon name="codigoBarras" :size="21" />
                    </button>
                    <button class="boton-terminar" @click="terminar">Terminar</button>
                </div>
                <div class="hoja-cuerpo">
                    <Aviso v-if="error" tipo="error">{{ error }}</Aviso>

                    <Aviso tipo="info" v-if="aprendiendo">
                        <b>¿De qué producto es el código {{ aprendiendo }}?</b>
                        Elígelo abajo y queda guardado en Softland.
                        <button class="enlace" @click="dejarDeAprender">Dejarlo sin asignar</button>
                    </Aviso>

                    <Buscador ref="campoBusqueda" v-model="busqueda"
                              :placeholder="aprendiendo ? 'Nombre o código del producto' : 'Nombre, código o código de barras'" />

                    <div class="item" v-for="p in hallados" :key="p.codigo" @click="elegir(p)">
                        <div class="item-estado" :class="p.afecto ? 'cian' : 'amarillo'"></div>
                        <div class="item-cuerpo">
                            <div class="item-titulo item-titulo-producto">{{ p.nombre }}</div>
                            <div class="item-linea">
                                {{ monto(p.precio, p.moneda) }} / {{ nombreDe('unidades', p.unidad) }}
                            </div>
                            <div class="item-meta">
                                <span class="etiqueta gris">{{ p.codigo }}</span>
                                <span v-if="p.barra" class="etiqueta gris">{{ p.barra }}</span>
                            </div>
                        </div>
                        <div class="carga-lleva" v-if="yaEn(p.codigo) > 0">{{ yaEn(p.codigo) }}</div>
                    </div>

                    <Vacio v-if="! hallados.length" icono="sinResultados" titulo="Ningún producto con eso">
                        Prueba con una palabra del nombre o con el código.
                    </Vacio>
                </div>
                <div class="carga-pie" v-if="agregados">
                    {{ agregados }} {{ agregados === 1 ? 'producto agregado' : 'productos agregados' }}
                </div>
            </div>

            <!-- ---------------- escanear ---------------- -->
            <div class="escaner" v-else>
                <div class="escaner-arriba">
                    <button class="icono-camara" aria-label="Buscar a mano" @click="irABuscar">
                        <AppIcon name="buscar" :size="22" color="currentColor" />
                    </button>
                    <div class="escaner-cuenta">{{ agregados || '' }}</div>
                    <button class="icono-camara" v-if="hayLinterna"
                            :aria-label="linterna ? 'Apagar la linterna' : 'Encender la linterna'"
                            @click="alternarLinterna">
                        <AppIcon :name="linterna ? 'linterna' : 'linternaApagada'" :size="22" color="currentColor" />
                    </button>
                    <button class="boton-terminar sobre-video" @click="terminar">Terminar</button>
                </div>

                <div class="escaner-mira"><span></span></div>

                <div class="escaner-abajo">
                    <div class="escaner-desconocido" v-if="desconocido">
                        <div>
                            <b>{{ desconocido }}</b>
                            <span v-if="conectado">Ningún producto tiene ese código.</span>
                            <span v-else>
                                Ningún producto tiene ese código, y sin señal no se le puede
                                enseñar a Softland.
                            </span>
                        </div>
                        <div class="escaner-salidas">
                            <button v-if="conectado" @click="asignarloAUnProducto">Es de…</button>
                            <button class="secundario" @click="buscarloAMano">Buscar a mano</button>
                        </div>
                    </div>
                    <p v-else>Apunta al código de barras del producto.</p>
                </div>
            </div>
        </div>
    </Teleport>
</template>
