<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { definidos as atributosDefinidos } from '../atributos';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';

const router = useRouter();
const cargando = ref(true);
const error = ref('');
const aviso = ref('');
const guardando = ref('');

const conexion = ref({ host: '', port: '', database: '', sa_user: '', sa_password: '', softland_password: '', password_guardada: false });
const correo = ref({ host: '', port: '', encryption: 'tls', username: '', password: '', from_address: '', from_name: '', password_guardada: false, configurado: false });
const destinoPrueba = ref('');

/*
 * Cómo factura la empresa. Dos decisiones que no están en Softland y que
 * cambian lo que hace la app, no lo que dice el documento.
 */
const facturacion = ref({ receptor_editable: false, envio_automatico: true, envio_softland: null });

/*
 * La orden de compra al proveedor.
 *
 * Qué atributo va en cada hueco del papel no se puede adivinar: los atributos
 * los define cada empresa, y los huecos tienen nombre propio —«OBSERVACIÓN»,
 * «TIPO DE VENTA»— que no se llama como el atributo que los llena.
 */
const ordenCompra = ref({
    proveedor: '', proveedor_nombre: null, contacto: '', correo: '',
    atributo_observacion: '', atributo_tipo_venta: '', atributo_fecha: '',
});

const atributos = ref([]);

/** Los de fecha sólo pueden llenar el hueco de fecha; los demás, los otros dos. */
const atributosFecha = computed(() => atributos.value.filter((a) => a.control === 'fecha'));
const atributosTexto = computed(() => atributos.value.filter((a) => a.control !== 'fecha'));

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    try {
        const c = await api.configuracion();
        conexion.value = { ...c.conexion, sa_password: '', softland_password: '' };
        correo.value = { ...c.correo, password: '' };
        facturacion.value = { ...facturacion.value, ...(c.facturacion || {}) };
        ordenCompra.value = { ...ordenCompra.value, ...(c.orden_compra || {}) };
        atributos.value = await atributosDefinidos();
    } catch (e) {
        error.value = e.message;
    } finally {
        cargando.value = false;
    }
}

async function guardarFacturacion(campo) {
    limpiar();
    guardando.value = 'facturacion';
    try {
        const r = await api.guardarFacturacion({ [campo]: facturacion.value[campo] });
        facturacion.value = { ...facturacion.value, ...r };
        aviso.value = 'Guardado.';
    } catch (e) {
        error.value = e.message;
        await cargar();
    } finally {
        guardando.value = '';
    }
}

async function guardarOrdenCompra() {
    limpiar();
    guardando.value = 'orden_compra';
    try {
        const r = await api.guardarOrdenCompra(ordenCompra.value);
        ordenCompra.value = { ...ordenCompra.value, ...r };
        aviso.value = 'Guardado.';
    } catch (e) {
        error.value = e.message;
    } finally {
        guardando.value = '';
    }
}

/** Lo que hace el Softland de escritorio, en una frase. No decide nada. */
function queHaceSoftland() {
    const s = facturacion.value.envio_softland;

    if (! s) return '';

    return s.factura_linea
        ? 'El Softland de escritorio manda las facturas en línea, al emitirlas.'
        : 'El Softland de escritorio manda las facturas en lote, no al emitirlas.';
}

function limpiar() {
    error.value = '';
    aviso.value = '';
}

async function guardarConexion() {
    limpiar();
    if (!conexion.value.softland_password) {
        error.value = 'Para cambiar la conexión hay que confirmar con la contraseña del usuario «softland».';
        return;
    }
    guardando.value = 'conexion';
    try {
        const r = await api.guardarConexion(conexion.value);
        aviso.value = r.message;
        conexion.value.softland_password = '';
        conexion.value.sa_password = '';
        await cargar();
    } catch (e) {
        error.value = e.message;
    } finally {
        guardando.value = '';
    }
}

async function guardarCorreo() {
    limpiar();
    guardando.value = 'correo';
    try {
        const r = await api.guardarCorreo(correo.value);
        aviso.value = r.message;
        correo.value.password = '';
        await cargar();
    } catch (e) {
        error.value = e.message;
    } finally {
        guardando.value = '';
    }
}

async function probarCorreo() {
    limpiar();
    if (!destinoPrueba.value) {
        error.value = 'Escribe a qué correo mandar la prueba.';
        return;
    }
    guardando.value = 'prueba';
    try {
        const r = await api.probarCorreo({ ...correo.value, to: destinoPrueba.value });
        aviso.value = r.message;
    } catch (e) {
        error.value = e.message;
    } finally {
        guardando.value = '';
    }
}
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>Configuración</h1>
        </div>

        <div class="contenido">
            <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
            <Aviso tipo="ok" v-if="aviso">{{ aviso }}</Aviso>
            <div class="cargando" v-if="cargando">Cargando…</div>

            <template v-else>
                <div class="tarjeta">
                    <div class="tarjeta-cabecera">Facturación</div>
                    <div class="tarjeta-cuerpo">
                        <label class="interruptor">
                            <input type="checkbox" v-model="facturacion.envio_automatico"
                                   :disabled="guardando === 'facturacion'"
                                   @change="guardarFacturacion('envio_automatico')">
                            <span>Mandar al SII al emitir</span>
                        </label>
                        <p class="ayuda">
                            Encendido, emitir una factura la manda al SII en el mismo acto, y si el
                            SII no contesta el servidor lo reintenta solo. Apagado, el documento
                            queda escrito esperando a que alguien lo mande desde su ficha — y
                            entonces depende de que alguien se acuerde.
                        </p>
                        <!-- Lo que hace el ERP se enseña, no se obedece: son dos
                             programas distintos emitiendo el mismo documento, y
                             que la oficina revise su lote a fin de día no dice
                             nada de lo que hace el teléfono en terreno. -->
                        <p class="ayuda" v-if="queHaceSoftland()"><b>{{ queHaceSoftland() }}</b></p>

                        <label class="interruptor">
                            <input type="checkbox" v-model="facturacion.receptor_editable"
                                   :disabled="guardando === 'facturacion'"
                                   @change="guardarFacturacion('receptor_editable')">
                            <span>Permitir facturar a otro cliente</span>
                        </label>
                        <p class="ayuda">
                            Apagado, la factura se le emite al cliente de la nota de venta y no hay
                            forma de equivocarse. Encendido, quien factura puede cambiar el
                            receptor: es lo que habilita el ciclo de distribuidor, donde la nota de
                            venta registra la venta al cliente final y la factura le cobra la
                            comisión a otra empresa.
                        </p>
                    </div>
                </div>

                <!-- Sólo tiene sentido con atributos definidos: sin ellos el
                     papel no tiene con qué llenar sus dos líneas y la tarjeta
                     sobra. -->
                <div class="tarjeta">
                    <div class="tarjeta-cabecera">Orden de compra al proveedor</div>
                    <div class="tarjeta-cuerpo">
                        <p class="ayuda">
                            La nota de venta sale también como orden de compra: los mismos datos
                            dirigidos a quien tiene que despachar. Aquí se dice a quién se le pide y
                            qué dato va en cada línea del papel.
                        </p>

                        <label>Código del proveedor en Softland</label>
                        <input v-model="ordenCompra.proveedor" autocapitalize="off" spellcheck="false"
                               placeholder="89889200">
                        <p class="ayuda" v-if="ordenCompra.proveedor_nombre">
                            <b>{{ ordenCompra.proveedor_nombre }}</b> — su dirección, RUT y giro se leen
                            de Softland cada vez, así que no hay que copiarlos aquí.
                        </p>

                        <div class="fila">
                            <div>
                                <label>Contacto</label>
                                <input v-model="ordenCompra.contacto">
                            </div>
                            <div>
                                <label>Correo</label>
                                <input v-model="ordenCompra.correo" type="email" autocapitalize="off">
                            </div>
                        </div>

                        <template v-if="atributos.length">
                            <label>Qué va en «OBSERVACIÓN»</label>
                            <select v-model="ordenCompra.atributo_observacion">
                                <option value="">— nada —</option>
                                <option v-for="a in atributosTexto" :key="a.codigo" :value="a.codigo">
                                    {{ a.nombre }}
                                </option>
                            </select>

                            <label>Qué va en «TIPO DE VENTA»</label>
                            <select v-model="ordenCompra.atributo_tipo_venta">
                                <option value="">— nada —</option>
                                <option v-for="a in atributosTexto" :key="a.codigo" :value="a.codigo">
                                    {{ a.nombre }}
                                </option>
                            </select>

                            <label>Qué fecha lleva la orden</label>
                            <select v-model="ordenCompra.atributo_fecha">
                                <option value="">— la del documento —</option>
                                <option v-for="a in atributosFecha" :key="a.codigo" :value="a.codigo">
                                    {{ a.nombre }}
                                </option>
                            </select>
                        </template>
                        <p class="ayuda" v-else>
                            Esta empresa no tiene atributos definidos en Softland, así que la orden
                            sale sin las líneas de observación y tipo de venta.
                        </p>

                        <button class="boton" :disabled="guardando === 'orden_compra'"
                                @click="guardarOrdenCompra">
                            {{ guardando === 'orden_compra' ? 'Guardando…' : 'Guardar' }}
                        </button>
                    </div>
                </div>

                <div class="tarjeta">
                    <div class="tarjeta-cabecera">Conexión a Softland</div>
                    <div class="tarjeta-cuerpo">
                        <label>Servidor SQL</label>
                        <input v-model="conexion.host" type="text" autocapitalize="off" spellcheck="false">

                        <div class="fila">
                            <div>
                                <label>Base de datos</label>
                                <input v-model="conexion.database" type="text" autocapitalize="off">
                            </div>
                            <div class="angosto">
                                <label>Puerto</label>
                                <input v-model="conexion.port" type="text" inputmode="numeric" placeholder="—">
                            </div>
                        </div>

                        <div class="fila">
                            <div>
                                <label>Usuario SQL</label>
                                <input v-model="conexion.sa_user" type="text" autocapitalize="off">
                            </div>
                            <div>
                                <label>Contraseña SQL</label>
                                <input v-model="conexion.sa_password" type="password"
                                       :placeholder="conexion.password_guardada ? 'guardada' : ''">
                            </div>
                        </div>

                        <label>Contraseña de «softland» (autoriza el cambio)</label>
                        <input v-model="conexion.softland_password" type="password">
                        <p class="ayuda">
                            Antes de guardar se prueba la conexión. Si falla, no se toca nada
                            de lo que ya está funcionando.
                        </p>

                        <button class="boton" :disabled="guardando === 'conexion'" @click="guardarConexion">
                            {{ guardando === 'conexion' ? 'Probando y guardando…' : 'Guardar conexión' }}
                        </button>
                    </div>
                </div>

                <div class="tarjeta">
                    <div class="tarjeta-cabecera">
                        Correo saliente
                        <span class="etiqueta" :class="correo.configurado ? 'verde' : 'roja'"
                              style="float:right;">
                            {{ correo.configurado ? 'configurado' : 'sin configurar' }}
                        </span>
                    </div>
                    <div class="tarjeta-cuerpo">
                        <Aviso tipo="info" v-if="!correo.configurado">
                            Mientras no haya SMTP configurado, los avisos no salen: quedan
                            registrados en el log del servidor.
                        </Aviso>

                        <div class="fila">
                            <div>
                                <label>Servidor SMTP</label>
                                <input v-model="correo.host" type="text" autocapitalize="off" spellcheck="false"
                                       placeholder="mail.empresa.cl">
                            </div>
                            <div class="angosto">
                                <label>Puerto</label>
                                <input v-model="correo.port" type="text" inputmode="numeric" placeholder="587">
                            </div>
                        </div>

                        <label>Cifrado</label>
                        <select v-model="correo.encryption">
                            <option value="tls">TLS (587)</option>
                            <option value="ssl">SSL (465)</option>
                            <option value="none">Ninguno</option>
                        </select>

                        <label>Usuario</label>
                        <input v-model="correo.username" type="text" autocapitalize="off" spellcheck="false">

                        <label>Contraseña</label>
                        <input v-model="correo.password" type="password"
                               :placeholder="correo.password_guardada ? 'guardada — en blanco = no cambiar' : ''">

                        <label>Remitente</label>
                        <input v-model="correo.from_address" type="email" autocapitalize="off" spellcheck="false"
                               placeholder="ventas@empresa.cl">

                        <label>Nombre del remitente</label>
                        <input v-model="correo.from_name" type="text" placeholder="Ventas">

                        <button class="boton" :disabled="guardando === 'correo'" @click="guardarCorreo">
                            {{ guardando === 'correo' ? 'Guardando…' : 'Guardar correo' }}
                        </button>

                        <label style="margin-top:22px;">Mandar una prueba a</label>
                        <input v-model="destinoPrueba" type="email" autocapitalize="off" spellcheck="false"
                               placeholder="tu@correo.cl">
                        <p class="ayuda">Usa los datos del formulario, sin guardarlos.</p>
                        <button class="boton secundario" :disabled="guardando === 'prueba'" @click="probarCorreo">
                            {{ guardando === 'prueba' ? 'Enviando…' : 'Enviar prueba' }}
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
