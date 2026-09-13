<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { olvidarAvisos } from '../avisos';
import { cambiarDensidad, densidad, ETIQUETAS } from '../densidad';
import { conectado } from '../red';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import FilaAjuste from '../components/FilaAjuste.vue';

/**
 * Cuenta: quién eres, qué tienes descargado, a qué servidor estás conectado y
 * cómo salir.
 *
 * Existe para sacarle peso al panel. Sincronizar y cerrar sesión vivían en el
 * encabezado del inicio, donde un vendedor podía cerrar la sesión de un dedazo
 * al buscar el botón de al lado. Aquí están donde se buscan y no donde estorban.
 */
const router = useRouter();
const usuario = ref(null);
const servidor = ref('');
const info = ref(null);
const sincronizado = ref(null);
const catalogos = ref({});
const sincronizando = ref(false);
const aviso = ref('');
const error = ref('');

const version = __VERSION__;

const esAdmin = computed(() => !!usuario.value?.es_admin);

const ADMIN = [
    { icono: 'usuario', rotulo: 'Usuarios', detalle: 'Altas, roles y topes', ruta: '/usuarios' },
    { icono: 'configuracion', rotulo: 'Configuración', detalle: 'Conexión a Softland y correo saliente', ruta: '/configuracion' },
    { icono: 'reglaAviso', rotulo: 'Reglas de aviso', detalle: 'Qué correo sale y a quién', ruta: '/reglas' },
    { icono: 'correoEnviado', rotulo: 'Correos enviados', detalle: 'Bitácora completa del servidor', ruta: '/bitacora' },
];

onMounted(async () => {
    usuario.value = await db.getUsuario();
    servidor.value = await db.getServidor();
    info.value = await db.getServidorInfo();
    sincronizado.value = await db.getSincronizado();
    catalogos.value = await db.getCatalogos();
});

const iniciales = computed(() => (usuario.value?.nombre || '')
    .split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || 'VS');

const registros = computed(() => Object.values(catalogos.value || {})
    .reduce((n, lista) => n + (Array.isArray(lista) ? lista.length : 0), 0));

const cuando = computed(() => {
    if (!sincronizado.value) return 'Nunca';
    const d = new Date(sincronizado.value);
    const hoy = new Date().toDateString() === d.toDateString();
    const hora = d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });
    return hoy ? `Hoy ${hora}` : d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit' }) + ` ${hora}`;
});

/** Deja sin el «http://» de adelante: en una fila angosta ocupa y no dice nada. */
const direccion = computed(() => (servidor.value || '').replace(/^https?:\/\//, ''));

async function sincronizar() {
    error.value = '';
    aviso.value = '';
    sincronizando.value = true;
    try {
        const b = await api.bootstrap();
        await db.setCatalogos(b.catalogos);
        await db.setUsuario(b.usuario);
        await db.setServidorInfo(b.servidor);
        await db.setSincronizado(b.sincronizado_at);
        usuario.value = b.usuario;
        info.value = b.servidor;
        sincronizado.value = b.sincronizado_at;
        catalogos.value = b.catalogos;
        aviso.value = 'Datos actualizados.';
    } catch (e) {
        error.value = e.message;
    } finally {
        sincronizando.value = false;
    }
}

async function salir() {
    if (!confirm('¿Cerrar sesión? Los datos descargados se borran del teléfono y hay que volver a entrar.')) return;
    try {
        await api.logout();
    } catch { /* sin red el token queda huérfano en el servidor y expira con el uso */ }
    await db.olvidarSesion();
    olvidarAvisos();
    router.replace('/login');
}
</script>

<template>
    <div class="pantalla">
        <div class="encabezado simple">
            <h1>Cuenta</h1>
        </div>

        <div class="contenido panel">
            <Aviso tipo="error" v-if="error" style="margin-top:14px;">{{ error }}</Aviso>
            <Aviso tipo="ok" v-if="aviso" style="margin-top:14px;">{{ aviso }}</Aviso>

            <div class="perfil">
                <div class="marca grande">{{ iniciales }}</div>
                <div class="datos">
                    <div class="nombre">{{ usuario?.nombre }}</div>
                    <div class="correo">{{ usuario?.email || 'sin correo' }}</div>
                    <div class="chapas">
                        <span class="etiqueta">{{ usuario?.rol }}</span>
                        <span class="etiqueta gris" v-if="usuario?.ven_cod">vendedor {{ usuario.ven_cod }}</span>
                    </div>
                </div>
            </div>

            <div class="seccion"><h2>Datos en el teléfono</h2></div>
            <div class="ajustes">
                <FilaAjuste icono="sincronizar" rotulo="Sincronizar ahora"
                            :detalle="`Última vez: ${cuando}`" @click="sincronizar">
                    <template #control>
                        <AppIcon name="sincronizar" :size="18" color="var(--indigo)"
                                 :class="{ girando: sincronizando }" />
                    </template>
                </FilaAjuste>
                <FilaAjuste icono="inventario" rotulo="Registros descargados"
                            :valor="registros.toLocaleString('es-CL')" />
                <FilaAjuste :icono="conectado ? 'alDia' : 'sinRed'" rotulo="Conexión"
                            :valor="conectado ? 'En línea' : 'Sin señal'" />
            </div>

            <div class="seccion">
                <h2>Interfaz</h2>
                <span class="sub">Se guarda en este teléfono</span>
            </div>
            <div class="ajustes">
                <FilaAjuste icono="tamanoTexto" rotulo="Tamaño de la interfaz"
                            detalle="Texto, iconos y separación de las tarjetas" apilada>
                    <template #control>
                        <div class="segmentado">
                            <button v-for="(rot, clave) in ETIQUETAS" :key="clave"
                                    :class="{ activa: densidad === clave }"
                                    @click.stop="cambiarDensidad(clave)">{{ rot }}</button>
                        </div>
                    </template>
                </FilaAjuste>
            </div>

            <template v-if="esAdmin">
                <div class="seccion"><h2>Administración</h2></div>
                <div class="ajustes">
                    <FilaAjuste v-for="a in ADMIN" :key="a.ruta" :icono="a.icono" :rotulo="a.rotulo"
                                :detalle="a.detalle" lleva @click="router.push(a.ruta)" />
                </div>
            </template>

            <div class="seccion"><h2>Acerca de</h2></div>
            <div class="ajustes">
                <FilaAjuste icono="servidor" rotulo="Servidor" :valor="direccion" />
                <FilaAjuste icono="empresa" rotulo="Base de datos" :valor="info?.base || '—'" />
                <FilaAjuste icono="permisos" rotulo="RUT emisor" :valor="info?.rut_emisor || '—'" />
                <FilaAjuste icono="dispositivo" rotulo="Versión de la app" :valor="version" />
                <FilaAjuste icono="configuracion" rotulo="Cambiar de servidor"
                            detalle="Vuelve a pedir la dirección y la sesión" lleva
                            @click="router.push('/servidor')" />
            </div>

            <button class="boton salir" @click="salir">
                <AppIcon name="salir" :size="18" color="currentColor" />
                Cerrar sesión
            </button>
        </div>
    </div>
</template>
