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
const facturacion = ref({
    receptor_editable: false,
    envio_automatico: true,
    referencia_orden_compra: true,
    referencia_nota_venta: true,
    envio_softland: null,
});

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

/*
 * El certificado digital: quién firma los documentos de esta empresa ante el
 * SII. Lo que llega del servidor es la ficha del archivo que hay —nunca el
 * archivo ni su clave—, y `hay: false` significa que este servidor todavía no
 * puede emitir nada.
 */
const certificado = ref({ hay: false, subido: false });
const certArchivo = ref(null);
const certClave = ref('');

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
        certificado.value = c.certificado || { hay: false, subido: false };
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

function elegirCertificado(e) {
    certArchivo.value = e.target.files?.[0] ?? null;
}

async function subirCertificado() {
    limpiar();

    if (!certArchivo.value) {
        error.value = 'Elige el archivo del certificado (.pfx o .p12).';
        return;
    }
    if (!certClave.value) {
        error.value = 'Falta la clave con que se abre el certificado.';
        return;
    }

    guardando.value = 'certificado';
    try {
        const r = await api.subirCertificado(certArchivo.value, certClave.value);
        aviso.value = r.message;
        certificado.value = r.certificado;
        // La clave no se queda escrita en la pantalla ni un minuto de más.
        certClave.value = '';
        certArchivo.value = null;
    } catch (e) {
        error.value = e.message;
    } finally {
        guardando.value = '';
    }
}

async function quitarCertificado() {
    limpiar();
    guardando.value = 'certificado';
    try {
        const r = await api.borrarCertificado();
        aviso.value = r.message;
        certificado.value = r.certificado;
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
                        <p class="ayuda">
                            <b>Encenderlo no se lo da a todos.</b> Softland decide además usuario por
                            usuario, con el permiso <b>IW · Factura en Línea · NVOtroAuxiliar</b>, y
                            se respeta: esta llave puede quitarlo para toda la empresa, nunca darlo a
                            quien el ERP se lo negó. Se marca en los perfiles del Softland de
                            escritorio.
                        </p>
                    </div>
                </div>

                <!-- Qué papeles nombra la factura en el DTE. Son dos códigos
                     del SII y no significan lo mismo, así que van separados: el
                     801 es de la orden de compra del cliente y el 802 del
                     número de la nota de venta. -->
                <div class="tarjeta">
                    <div class="tarjeta-cabecera">Qué nombra la factura</div>
                    <div class="tarjeta-cuerpo">
                        <label class="interruptor">
                            <input type="checkbox" v-model="facturacion.referencia_orden_compra"
                                   :disabled="guardando === 'facturacion'"
                                   @change="guardarFacturacion('referencia_orden_compra')">
                            <span>La orden de compra del cliente</span>
                        </label>
                        <p class="ayuda">
                            Va como referencia <b>Orden de Compra</b> del DTE, y es la que le sirve
                            a quien recibe la factura para cuadrarla contra lo que encargó. El
                            renglón sólo sale cuando hay orden de compra que poner, así que
                            apagarlo sólo tiene sentido si la empresa no trabaja con ellas.
                        </p>

                        <label class="interruptor">
                            <input type="checkbox" v-model="facturacion.referencia_nota_venta"
                                   :disabled="guardando === 'facturacion'"
                                   @change="guardarFacturacion('referencia_nota_venta')">
                            <span>El número de la nota de venta</span>
                        </label>
                        <p class="ayuda">
                            Va como referencia <b>Nota de Pedido</b>. Es lo que hace hoy el Softland
                            de escritorio, y por eso nace encendido. Si la nota de venta es un papel
                            interno, publicar su número en un documento tributario que lee el
                            cliente no aporta nada.
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
                        Certificado digital
                        <span class="etiqueta" :class="certificado.hay && !certificado.vencido ? 'verde' : 'roja'"
                              style="float:right;">
                            {{ certificado.hay ? (certificado.vencido ? 'vencido' : 'vigente') : 'sin certificado' }}
                        </span>
                    </div>
                    <div class="tarjeta-cuerpo">
                        <!-- Sin certificado no se emite nada: ni factura, ni
                             boleta, ni nota de crédito. Es lo primero que hay
                             que decir, y antes de cualquier formulario. -->
                        <Aviso tipo="error" v-if="!certificado.hay">
                            Este servidor no puede emitir documentos tributarios hasta que
                            se suba el certificado.
                            <template v-if="certificado.problema"><br>{{ certificado.problema }}</template>
                        </Aviso>

                        <template v-else>
                            <dl class="datos">
                                <dt>Firma</dt><dd>{{ certificado.sujeto }}</dd>
                                <dt>RUT</dt><dd>{{ certificado.rut || '—' }}</dd>
                                <dt>Vence</dt>
                                <dd>{{ certificado.vence }}
                                    <span class="ayuda">({{ certificado.dias }} días)</span></dd>
                                <template v-if="certificado.subido_por">
                                    <dt>Lo subió</dt>
                                    <dd>{{ certificado.subido_por }}, el {{ certificado.subido_en }}</dd>
                                </template>
                            </dl>

                            <Aviso tipo="error" v-if="certificado.vencido">{{ certificado.aviso }}</Aviso>
                            <Aviso tipo="info" v-else-if="certificado.aviso">{{ certificado.aviso }}</Aviso>

                            <!-- Quien instaló este servidor antes de que esto
                                 existiera lo tiene puesto en el .env. Funciona
                                 igual, pero explica por qué no hay nada que
                                 quitar. -->
                            <p class="ayuda" v-if="!certificado.subido">
                                Está puesto a mano en el servidor, no subido desde aquí.
                                Al subir uno, manda el que se suba.
                            </p>
                        </template>

                        <label class="boton-archivo">
                            <AppIcon name="subir" :size="18" color="currentColor" />
                            {{ certArchivo ? certArchivo.name : (certificado.hay ? 'Elegir el certificado nuevo' : 'Elegir el certificado') }}
                            <input type="file" accept=".pfx,.p12"
                                   :disabled="guardando === 'certificado'" @change="elegirCertificado">
                        </label>

                        <label style="margin-top:12px;">Clave del certificado</label>
                        <input v-model="certClave" type="password" autocapitalize="off" spellcheck="false">
                        <p class="ayuda">
                            La misma con que lo abre el Softland de escritorio. Se comprueba
                            abriendo el archivo: si no es ésa, no se guarda nada y el que está
                            funcionando se queda como está. El archivo y la clave quedan
                            cifrados en el servidor y no vuelven a salir de ahí.
                        </p>

                        <button class="boton" :disabled="guardando === 'certificado'" @click="subirCertificado">
                            {{ guardando === 'certificado' ? 'Comprobando…' : 'Subir certificado' }}
                        </button>

                        <button class="boton-texto peligro" v-if="certificado.subido"
                                :disabled="guardando === 'certificado'" @click="quitarCertificado">
                            Quitar el certificado subido
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
