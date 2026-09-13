<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';

const router = useRouter();
const cargando = ref(true);
const error = ref('');
const aviso = ref('');
const guardando = ref('');

const conexion = ref({ host: '', port: '', database: '', sa_user: '', sa_password: '', softland_password: '', password_guardada: false });
const correo = ref({ host: '', port: '', encryption: 'tls', username: '', password: '', from_address: '', from_name: '', password_guardada: false, configurado: false });
const destinoPrueba = ref('');

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    try {
        const c = await api.configuracion();
        conexion.value = { ...c.conexion, sa_password: '', softland_password: '' };
        correo.value = { ...c.correo, password: '' };
    } catch (e) {
        error.value = e.message;
    } finally {
        cargando.value = false;
    }
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
            <button class="icono-barra" @click="router.back()">‹</button>
            <h1>Configuración</h1>
        </div>

        <div class="contenido">
            <div class="aviso error" v-if="error">{{ error }}</div>
            <div class="aviso ok" v-if="aviso">{{ aviso }}</div>
            <div class="cargando" v-if="cargando">Cargando…</div>

            <template v-else>
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
                        <div class="aviso info" v-if="!correo.configurado">
                            Mientras no haya SMTP configurado, los avisos no salen: quedan
                            registrados en el log del servidor.
                        </div>

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
