<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';
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
                    </template>

                    <label>Nombre o razón social</label>
                    <input v-model="form.nombre" type="text">

                    <label>Nombre de fantasía</label>
                    <input v-model="form.fantasia" type="text" placeholder="Como lo conoce la gente">

                    <label>Giro</label>
                    <Selector v-model="form.giro" maestro="giros"
                              vacio="— sin giro —" filtrar="Filtrar giros" />

                    <label>Dirección</label>
                    <input v-model="form.direccion" type="text">

                    <label>Comuna</label>
                    <Selector v-model="form.comuna" maestro="comunas"
                              vacio="— sin comuna —" filtrar="Filtrar comunas" />

                    <label>Ciudad</label>
                    <Selector v-model="form.ciudad" maestro="ciudades"
                              vacio="— sin ciudad —" filtrar="Filtrar ciudades" />
                    <p class="ayuda">
                        En Softland la ciudad es su propio maestro, no sale de la comuna.
                    </p>

                    <label>Teléfono</label>
                    <input v-model="form.fono" type="tel" inputmode="tel">

                    <label>Correo</label>
                    <input v-model="form.email" type="email" autocapitalize="off" spellcheck="false">
                    <p class="ayuda">Adonde llega la cotización.</p>

                    <label>Correo para documentos tributarios</label>
                    <input v-model="form.email_dte" type="email" autocapitalize="off" spellcheck="false">
                    <p class="ayuda">Adonde llega la factura o la boleta. Suele ser el de contabilidad.</p>

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
