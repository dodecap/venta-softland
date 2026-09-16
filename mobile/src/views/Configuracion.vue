<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
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

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    try {
        const c = await api.configuracion();
        conexion.value = { ...c.conexion, sa_password: '', softland_password: '' };
        correo.value = { ...c.correo, password: '' };
        facturacion.value = { ...facturacion.value, ...(c.facturacion || {}) };
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
