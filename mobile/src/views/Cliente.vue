<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { idb } from '../idb';
import { nombre as nombreDe } from '../catalogos';
import { encolar, enviarPendientes, pendienteDe, descartar, porEnviar } from '../pendientes';
import { conectado } from '../red';
import { useCapa } from '../nav';
import { Rut } from '../rut';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Vacio from '../components/Vacio.vue';
import Selector from '../components/Selector.vue';

/*
 * Ficha del cliente: ver, editar y dar de alta.
 *
 * Lee de IndexedDB, así que abrir un cliente en terreno es instantáneo y no
 * depende de la señal. Escribir sí necesita llegar a Softland, pero no ahí
 * mismo: lo que se guarda sin red queda en la bandeja de salida y sale solo
 * cuando vuelve. Ver `pendientes.js`.
 */

const route = useRoute();
const router = useRouter();

const esNuevo = computed(() => route.params.codigo === 'nuevo');
const codigo = computed(() => String(route.params.codigo || ''));

const cliente = ref(null);
const contactos = ref([]);
const pendiente = ref(null);
const cargando = ref(true);
const error = ref('');
const aviso = ref('');
const guardando = ref(false);

const editando = ref(false);
const form = ref(vacio());

/*
 * Lo que el SII publica de esta empresa.
 *
 * Es una **propuesta**: llena el formulario y se queda guardada para poder
 * decir, campo por campo, de dónde salió lo que hay escrito. En cuanto el
 * vendedor cambia un campo, el sello de ese campo desaparece solo —`deSii()`
 * compara, no hay que vigilar nada.
 */
const sii = ref(null);
const siiDisponible = ref(false);
const buscandoSii = ref(false);
const avisoSii = ref('');

useCapa(editando, () => { editando.value = false; });

function vacio() {
    return {
        rut: '', nombre: '', fantasia: '', giro: '', direccion: '',
        comuna: '', ciudad: '', fono: '', email: '', email_dte: '',
        dias_plazo: null, contactos: [],
    };
}

onMounted(cargar);

/*
 * Si este servidor sabe consultar el padrón. Es configuración del servidor, no
 * permiso del usuario: cuando no está configurado el botón **no se dibuja**,
 * en vez de dibujarse y fallar al apretarlo.
 */
onMounted(async () => {
    siiDisponible.value = !! (await db.getServidorInfo())?.sii_disponible;
});

/*
 * Saltar de una ficha a otra no desmonta el componente: es la misma ruta con
 * otro parámetro, y `onMounted` no vuelve a correr. Pasa de verdad — al crear
 * un cliente cuyo RUT ya existía, la app ofrece abrir esa ficha y salta desde
 * «nuevo» al código real. Sin esto la pantalla se quedaría mostrando lo
 * anterior con la URL del cliente nuevo.
 */
/*
 * La bandeja se vacía sola cuando vuelve la señal, y eso pasa fuera de esta
 * pantalla (`App.vue`). Sin esto la ficha se quedaría con la franja de «no ha
 * llegado a Softland» encima de un cambio que ya llegó.
 */
watch(porEnviar, cargar);

watch(codigo, () => {
    editando.value = false;
    error.value = '';
    aviso.value = '';
    cargar();
});

async function cargar() {
    cargando.value = true;
    error.value = '';
    try {
        if (esNuevo.value) {
            form.value = vacio();
            editando.value = true;
            return;
        }

        cliente.value = await idb.obtener('clientes', codigo.value);
        contactos.value = await idb.porIndice('contactos', 'cliente', codigo.value);
        pendiente.value = await pendienteDe(codigo.value);

        // Puede no estar en el teléfono: un cliente creado hoy por otra persona,
        // o una sincronización que quedó a medias. Si hay señal se pide.
        if (! cliente.value && conectado.value) {
            const r = await api.cliente(codigo.value);
            cliente.value = r.cliente;
            contactos.value = r.contactos;
            await idb.guardar('clientes', [r.cliente]);
            await idb.guardar('contactos', r.contactos);
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        cargando.value = false;
    }
}

function editar() {
    form.value = {
        nombre: cliente.value.nombre,
        fantasia: cliente.value.fantasia,
        giro: cliente.value.giro,
        direccion: cliente.value.direccion,
        comuna: cliente.value.comuna,
        ciudad: cliente.value.ciudad,
        fono: cliente.value.fono,
        email: cliente.value.email,
        email_dte: cliente.value.email_dte,
        dias_plazo: cliente.value.dias_plazo || null,
        contactos: contactos.value.map((c) => ({ ...c })),
    };
    editando.value = true;
}

const rutValido = computed(() => ! esNuevo.value || Rut.esValido(form.value.rut));

/*
 * Traer del SII lo que publica de esta empresa.
 *
 * El botón sólo existe si el servidor sabe consultar, y sólo se puede apretar
 * con señal y con un RUT válido. **Nunca es requisito**: sin red se apaga y el
 * formulario se llena a mano, que es como se ha hecho siempre.
 */
async function buscarEnSii() {
    avisoSii.value = '';
    error.value = '';
    buscandoSii.value = true;
    try {
        const r = await api.clienteSii(form.value.rut);

        // Ya es cliente. No es un error: es que el trabajo ya estaba hecho.
        if (r.ya_existe) {
            // Se guardan también los contactos: la ficha a la que se salta los
            // lee de IndexedDB, y sin esto aparecería vacía de contactos hasta
            // la siguiente descarga.
            await idb.guardar('clientes', [r.cliente]);
            await idb.guardar('contactos', r.contactos || []);
            if (confirm(`${r.cliente.nombre} ya es cliente.\n\n¿Quieres abrir su ficha?`)) {
                editando.value = false;
                router.replace(`/clientes/${r.cliente.codigo}`);
            } else {
                avisoSii.value = `Ese RUT ya es el cliente ${r.cliente.codigo}.`;
            }
            return;
        }

        if (! r.encontrado) {
            avisoSii.value = 'El SII no publica ese RUT. El padrón es de empresas, '
                + 'así que una persona natural no sale. Llena el formulario a mano.';
            return;
        }

        sii.value = r;
        for (const [campo, dato] of Object.entries(r.campos)) {
            // Lo que el SII no trae no borra lo que ya estaba escrito.
            if (dato.valor === null || dato.valor === '') continue;
            form.value[campo] = dato.valor;
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        buscandoSii.value = false;
    }
}

/*
 * Cambiar el RUT invalida la propuesta entera: dejar los sellos puestos sería
 * decir que los datos de otra empresa vienen del SII.
 */
watch(() => form.value.rut, () => {
    sii.value = null;
    avisoSii.value = '';
});

/** ¿Este campo sigue teniendo, tal cual, lo que propuso el SII? */
function deSii(campo) {
    const d = sii.value?.campos?.[campo];

    return !! d && d.valor !== null && d.valor !== '' && form.value[campo] === d.valor;
}

/**
 * El texto entero cuando no cupo en el campo de Softland.
 *
 * Se enseña a propósito. El SII guarda razones sociales de hasta 80 caracteres
 * y `NomAux` tiene 60: recortar en silencio deja al vendedor creyendo que el
 * nombre que ve es el que hay. Que lo mire y decida él.
 */
function recorteDeSii(campo) {
    const d = sii.value?.campos?.[campo];

    return d?.recortado && deSii(campo) ? d.texto : '';
}

/** Los giros que el SII le conoce, cuando es más de uno y hay que elegir. */
const girosSii = computed(() => (sii.value?.giros || []).filter((g) => g.valor));

async function guardar() {
    error.value = '';

    if (! form.value.nombre.trim()) {
        error.value = 'El nombre es obligatorio.';
        return;
    }
    if (esNuevo.value && ! rutValido.value) {
        error.value = 'El RUT no es válido: revisa el dígito verificador.';
        return;
    }

    guardando.value = true;
    try {
        // Aplanado de una vez: lo que sale de aquí viaja por la red y, sin
        // señal, se guarda en IndexedDB. Los dos exigen un objeto de verdad, no
        // el proxy reactivo del formulario.
        const datos = JSON.parse(JSON.stringify({
            ...form.value,
            contactos: form.value.contactos.filter((c) => c.nombre?.trim()),
        }));
        const clave = esNuevo.value ? Rut.cuerpo(datos.rut) : codigo.value;

        if (conectado.value) {
            const r = esNuevo.value ? await api.crearCliente(datos) : await api.editarCliente(clave, datos);
            await guardarLocal(clave, r);   // confirmado por Softland: sin sello local
            aviso.value = esNuevo.value ? 'Cliente creado en Softland.' : 'Cambios guardados en Softland.';
        } else {
            // Sin señal se guarda igual: la ficha queda en el teléfono con la
            // franja ámbar y la operación en la bandeja de salida.
            await encolar(esNuevo.value ? 'cliente.crear' : 'cliente.editar', clave, datos);

            // La ficha local se arma con lo que ya había más lo editado. Los
            // contactos se sacan: viven en su propio almacén, y dejarlos
            // colgando del cliente los duplicaría.
            const { contactos: contactosNuevos, ...ficha } = datos;
            await guardarLocal(clave, {
                cliente: {
                    // Un cliente recién dado de alta está activo y no está
                    // bloqueado; sin esto la ficha se dibuja con la chapa roja
                    // de «inactivo» hasta que Softland la confirme.
                    activo: true,
                    bloqueado: false,
                    ...(cliente.value || {}),
                    ...ficha,
                    codigo: clave,
                    rut: Rut.formatear(datos.rut || cliente.value?.rut),
                },
                contactos: contactosNuevos.map((c) => ({ ...c, cliente: clave })),
            }, true);
            aviso.value = 'Guardado en el teléfono. Se enviará a Softland cuando vuelva la señal.';
        }

        editando.value = false;
        if (esNuevo.value) router.replace(`/clientes/${clave}`);
    } catch (e) {
        // El RUT ya existía: no es un error del vendedor, es un cliente que ya
        // está. Se ofrece abrirlo en vez de dejarlo peleando con el formulario.
        if (e.status === 409 && e.datos?.cliente) {
            error.value = e.message;
            const ya = e.datos.cliente;
            await idb.guardar('clientes', [ya]);
            aviso.value = '';
            if (confirm(`${e.message}\n\n¿Quieres abrir esa ficha?`)) {
                editando.value = false;
                router.replace(`/clientes/${ya.codigo}`);
            }
        } else {
            error.value = e.message;
        }
    } finally {
        guardando.value = false;
    }
}

/**
 * Deja en el teléfono lo que quedó guardado, venga del servidor o de la
 * bandeja de salida. Se aplana antes de escribir: la ficha que se arma sin
 * señal arrastra trozos reactivos de la pantalla, y IndexedDB no los clona.
 *
 * @param {boolean} enBandeja  La ficha todavía no llegó a Softland. Va con el
 *   sello local, que la salva del barrido de una descarga completa. Lo que el
 *   servidor ya confirmó va sin sello a propósito: así, si resulta que allá no
 *   existe, el próximo barrido se la lleva en vez de dejarla para siempre.
 */
async function guardarLocal(clave, respuesta, enBandeja = false) {
    const r = JSON.parse(JSON.stringify(respuesta));
    const sello = enBandeja ? { _s: idb.SELLO_LOCAL } : {};
    await idb.guardar('clientes', [{ ...r.cliente, ...sello }]);
    const viejos = await idb.porIndice('contactos', 'cliente', clave);
    for (const c of viejos) await idb.borrar('contactos', [c.cliente, c.nombre]);
    await idb.guardar('contactos', (r.contactos || [])
        .map((c) => ({ ...c, cliente: clave, ...sello })));

    cliente.value = r.cliente;
    contactos.value = await idb.porIndice('contactos', 'cliente', clave);
    pendiente.value = await pendienteDe(clave);
}

async function reintentar() {
    aviso.value = '';
    error.value = '';
    const r = await enviarPendientes();
    if (r.sinRed) error.value = 'Todavía no hay señal.';
    await cargar();
}

async function descartarPendiente() {
    if (! confirm('¿Descartar el cambio sin enviar? Se pierde lo que escribiste.')) return;
    await descartar(pendiente.value.uuid);
    await cargar();
}

function agregarContacto() {
    form.value.contactos.push({ nombre: '', cargo: '', fono: '', email: '' });
}

const ubicacion = computed(() => [
    nombreDe('comunas', cliente.value?.comuna),
    nombreDe('ciudades', cliente.value?.ciudad),
].filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join(' · '));
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>{{ esNuevo ? 'Nuevo cliente' : 'Cliente' }}</h1>
            <button v-if="cliente && ! editando" class="icono-barra" title="Editar" @click="editar">
                <AppIcon name="configuracion" :size="21" />
            </button>
        </div>

        <div class="contenido">
            <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
            <Aviso tipo="ok" v-if="aviso">{{ aviso }}</Aviso>

            <div class="cargando" v-if="cargando">Cargando…</div>

            <template v-else-if="cliente">
                <Aviso tipo="info" v-if="pendiente && pendiente.estado !== 'rechazado'">
                    Este cambio todavía no llega a Softland.
                    <button class="enlace" @click="reintentar">Reintentar ahora</button> ·
                    <button class="enlace" @click="descartarPendiente">Descartar</button>
                </Aviso>
                <Aviso tipo="error" v-else-if="pendiente">
                    Softland rechazó el cambio: {{ pendiente.mensaje }}
                    <button class="enlace" @click="descartarPendiente">Descartar</button>
                </Aviso>

                <div class="ficha">
                    <h2>{{ cliente.nombre }}</h2>
                    <div class="sub">{{ cliente.rut }} · código {{ cliente.codigo }}</div>
                    <div class="etiquetas">
                        <span v-if="cliente.bloqueado" class="etiqueta roja">bloqueado</span>
                        <span v-if="! cliente.activo" class="etiqueta roja">inactivo</span>
                        <span v-if="cliente.dias_plazo" class="etiqueta">{{ cliente.dias_plazo }} días de plazo</span>
                    </div>
                </div>

                <div class="tarjeta">
                    <div class="tarjeta-cabecera">Datos</div>
                    <div class="tarjeta-cuerpo datos">
                        <div v-if="cliente.fantasia && cliente.fantasia !== cliente.nombre">
                            <span>Nombre de fantasía</span><b>{{ cliente.fantasia }}</b>
                        </div>
                        <div v-if="cliente.giro"><span>Giro</span><b>{{ nombreDe('giros', cliente.giro) }}</b></div>
                        <div v-if="cliente.direccion"><span>Dirección</span><b>{{ cliente.direccion }}</b></div>
                        <div v-if="ubicacion"><span>Ubicación</span><b>{{ ubicacion }}</b></div>
                        <div v-if="cliente.fono"><span>Teléfono</span><b>{{ cliente.fono }}</b></div>
                        <div v-if="cliente.email"><span>Correo</span><b>{{ cliente.email }}</b></div>
                        <div v-if="cliente.email_dte"><span>Correo para DTE</span><b>{{ cliente.email_dte }}</b></div>
                    </div>
                </div>

                <div class="seccion">
                    <h2>Contactos</h2>
                    <span class="sub">{{ contactos.length }}</span>
                </div>

                <Vacio v-if="! contactos.length" icono="cliente" titulo="Sin contactos">
                    Agrega a quién llamar cuando haya que cerrar la venta.
                </Vacio>

                <div class="item" v-for="c in contactos" :key="c.nombre">
                    <div class="item-estado cian"></div>
                    <div class="item-cuerpo">
                        <div class="item-titulo">{{ c.nombre }}</div>
                        <div class="item-linea" v-if="c.cargo">{{ nombreDe('cargos', c.cargo) }}</div>
                        <div class="item-meta">
                            <span v-if="c.fono">{{ c.fono }}</span>
                            <span v-if="c.fono && c.email"> · </span>
                            <span v-if="c.email">{{ c.email }}</span>
                        </div>
                    </div>
                </div>
            </template>

            <Vacio v-else-if="! esNuevo" icono="sinResultados" titulo="No encontramos ese cliente">
                Puede que no esté descargado en el teléfono. Sincroniza y vuelve a intentar.
            </Vacio>
        </div>

        <!-- Hoja de edición -->
        <div class="velo" v-if="editando" @click.self="editando = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>{{ esNuevo ? 'Nuevo cliente' : 'Editar cliente' }}</h2>
                    <button class="icono-barra" @click="editando = false"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
                    <Aviso tipo="info" v-if="! conectado">
                        Sin señal. Se guarda en el teléfono y se envía a Softland cuando vuelva.
                    </Aviso>

                    <template v-if="esNuevo">
                        <label>RUT</label>
                        <input v-model="form.rut" type="text" inputmode="text" placeholder="76.123.456-7"
                               autocapitalize="characters" spellcheck="false">
                        <p class="ayuda" :class="{ malo: form.rut && ! rutValido }">
                            <template v-if="form.rut && ! rutValido">El dígito verificador no corresponde.</template>
                            <template v-else>
                                Es la clave del cliente en Softland y no se puede cambiar después.
                            </template>
                        </p>

                        <button v-if="siiDisponible" class="boton secundario con-icono"
                                :disabled="! rutValido || ! conectado || buscandoSii"
                                @click="buscarEnSii">
                            <AppIcon name="buscar" :size="18" color="currentColor" />
                            {{ buscandoSii ? 'Consultando al SII…' : 'Buscar en el SII' }}
                        </button>
                        <p class="ayuda" v-if="siiDisponible && ! conectado">
                            Sin señal no se puede consultar. El alta no lo necesita: llena el
                            formulario a mano y saldrá cuando vuelva la señal.
                        </p>

                        <Aviso tipo="info" v-if="avisoSii">{{ avisoSii }}</Aviso>

                        <template v-if="sii">
                            <Aviso tipo="info" v-if="sii.fuente === 'cache-vieja'">
                                El SII no contestó. Esto es lo que teníamos guardado del
                                {{ sii.consultado_en.slice(0, 10) }}: míralo con cuidado.
                            </Aviso>
                            <p class="sii-origen">
                                <AppIcon name="descargar" :size="14" color="currentColor" />
                                Padrón del SII al {{ sii.padron }}<template v-if="sii.region">
                                 · {{ sii.region }}</template>
                            </p>
                        </template>
                    </template>

                    <label>Nombre o razón social <span class="etiqueta cian" v-if="deSii('nombre')">del SII</span></label>
                    <input v-model="form.nombre" type="text">

                    <p class="sii-recorte" v-if="recorteDeSii('nombre')">
                        <AppIcon name="alerta" :size="15" />
                        <span>No cabe entero en Softland, que guarda 60 caracteres.
                        El SII lo tiene así: <b>{{ recorteDeSii('nombre') }}</b></span>
                    </p>

                    <label>Nombre de fantasía</label>
                    <input v-model="form.fantasia" type="text" placeholder="Como lo conoce la gente">

                    <label>Giro <span class="etiqueta cian" v-if="deSii('giro')">del SII</span></label>
                    <Selector v-model="form.giro" maestro="giros"
                              vacio="— sin giro —" filtrar="Filtrar giros" />
                    <template v-if="girosSii.length > 1">
                        <p class="ayuda">
                            El SII le conoce {{ girosSii.length }} giros. Va puesto el primero
                            que declaró; elige el que corresponda a lo que le vendes.
                        </p>
                        <div class="sii-giros">
                            <button v-for="g in girosSii" :key="g.acteco" type="button"
                                    class="sii-giro" :class="{ puesto: form.giro === g.valor }"
                                    @click="form.giro = g.valor">
                                <AppIcon :name="form.giro === g.valor ? 'ok' : 'crear'" :size="16"
                                         color="currentColor" />
                                <span>{{ g.descripcion }}</span>
                            </button>
                        </div>
                    </template>

                    <label>Dirección <span class="etiqueta cian" v-if="deSii('direccion')">del SII</span></label>
                    <input v-model="form.direccion" type="text">

                    <label>Comuna <span class="etiqueta cian" v-if="deSii('comuna')">del SII</span></label>
                    <Selector v-model="form.comuna" maestro="comunas"
                              vacio="— sin comuna —" filtrar="Filtrar comunas" />

                    <label>Ciudad <span class="etiqueta cian" v-if="deSii('ciudad')">del SII</span></label>
                    <Selector v-model="form.ciudad" maestro="ciudades"
                              vacio="— sin ciudad —" filtrar="Filtrar ciudades" />
                    <p class="ayuda">
                        <template v-if="sii?.campos?.ciudad?.deducido && deSii('ciudad')">
                            El SII no trae ciudad para este domicilio, así que va la de la
                            comuna. Cámbiala si no es esa.
                        </template>
                        <template v-else>
                            En Softland la ciudad es su propio maestro, no sale de la comuna.
                        </template>
                    </p>

                    <label>Teléfono</label>
                    <input v-model="form.fono" type="tel" inputmode="tel">

                    <label>Correo</label>
                    <input v-model="form.email" type="email" autocapitalize="off" spellcheck="false">
                    <p class="ayuda">Adonde llega la cotización.</p>

                    <label>Correo para documentos tributarios <span class="etiqueta cian" v-if="deSii('email_dte')">del SII</span></label>
                    <input v-model="form.email_dte" type="email" autocapitalize="off" spellcheck="false">
                    <p class="ayuda">
                        <template v-if="deSii('email_dte') && form.email_dte === 'FacturacionMIPYME@sii.cl'">
                            Factura por el portal gratuito del SII, y ése es su correo de
                            intercambio de verdad. Le pasa a cuatro de cada cinco empresas
                            del país: no lo borres.
                        </template>
                        <template v-else>
                            Adonde llega la factura o la boleta. Suele ser el de contabilidad.
                        </template>
                    </p>

                    <label>Días de plazo</label>
                    <input v-model.number="form.dias_plazo" type="number" min="0" max="99" class="angosto">

                    <div class="seccion">
                        <h2>Contactos</h2>
                        <button class="ver-todo" @click="agregarContacto">
                            Agregar <AppIcon name="crear" :size="15" color="currentColor" />
                        </button>
                    </div>

                    <div class="contacto" v-for="(c, i) in form.contactos" :key="i">
                        <div class="fila">
                            <input v-model="c.nombre" type="text" placeholder="Nombre">
                            <button class="icono-barra" title="Quitar" @click="form.contactos.splice(i, 1)">
                                <AppIcon name="borrar" :size="19" />
                            </button>
                        </div>
                        <div class="fila">
                            <input v-model="c.fono" type="tel" placeholder="Teléfono">
                            <input v-model="c.email" type="email" placeholder="Correo"
                                   autocapitalize="off" spellcheck="false">
                        </div>
                        <Selector v-model="c.cargo" maestro="cargos"
                                  vacio="— sin cargo —" filtrar="Filtrar cargos" />
                    </div>
                    <p class="ayuda" v-if="! form.contactos.length">
                        Sin contactos. El nombre del contacto es lo que queda estampado en la cotización.
                    </p>

                    <button class="boton" :disabled="guardando" @click="guardar">
                        {{ guardando ? 'Guardando…' : (esNuevo ? 'Crear cliente' : 'Guardar cambios') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
