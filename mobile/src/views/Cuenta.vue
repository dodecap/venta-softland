<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { olvidarAvisos } from '../avisos';
import { cambiarDensidad, densidad, ETIQUETAS } from '../densidad';
import { conectado } from '../red';
import { abrirSoporte, NUMERO_VISIBLE } from '../soporte';
import { contarRegistros, corridas, inventarioLocal, progreso, sincronizando, sincronizar as sincronizarMaestros } from '../sync';
import { confirmarPendiente, contarPendientes, descartar, enviarPendientes, porEnviar } from '../pendientes';
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
const registros = ref(0);
const pendientes = ref([]);
const detalleDatos = ref(false);
const aviso = ref('');
const error = ref('');

const version = __VERSION__;

/*
 * La versión del servidor llega en el bootstrap. Se muestra al lado de la del
 * teléfono porque son dos programas distintos que se despliegan por separado:
 * un APK nuevo contra una API vieja es la causa de la mitad de los «a mí no me
 * funciona», y hasta que no se ve escrito nadie lo sospecha.
 */
const versionServidor = computed(() => info.value?.version || null);
const desfasado = computed(() => !! versionServidor.value && versionServidor.value !== version);

const esAdmin = computed(() => !!usuario.value?.es_admin);

const ADMIN = [
    { icono: 'usuario', rotulo: 'Usuarios', detalle: 'Altas, roles y topes', ruta: '/usuarios' },
    { icono: 'configuracion', rotulo: 'Configuración', detalle: 'Conexión a Softland y correo saliente', ruta: '/configuracion' },
    { icono: 'empresa', rotulo: 'Identidad', detalle: 'Logo y datos que salen en los documentos', ruta: '/identidad' },
    { icono: 'reglaAviso', rotulo: 'Reglas de aviso', detalle: 'Qué correo sale y a quién', ruta: '/reglas' },
    { icono: 'correoEnviado', rotulo: 'Correos enviados', detalle: 'Bitácora completa del servidor', ruta: '/bitacora' },
];

onMounted(async () => {
    usuario.value = await db.getUsuario();
    servidor.value = await db.getServidor();
    info.value = await db.getServidorInfo();
    await refrescar();
    await refrescarVersionServidor();
});

/**
 * La versión del servidor, preguntada ahora.
 *
 * Se guardaba **sólo al iniciar sesión**, así que esta pantalla enseñaba la foto
 * del día en que el vendedor entró: después de cualquier despliegue decía que la
 * app y el servidor no coincidían aunque coincidieran, y tapaba los desfases de
 * verdad. Un aviso que se equivoca es peor que no tenerlo, porque enseña a no
 * hacerle caso.
 *
 * Sin señal se queda con lo guardado, que es lo último que se supo.
 */
async function refrescarVersionServidor() {
    if (! conectado.value) return;

    try {
        const r = await api.ping();

        if (! r?.version) return;

        info.value = { ...(info.value || {}), version: r.version };
        await db.setServidorInfo(info.value);
    } catch {
        // El servidor no contesta: lo guardado sigue siendo lo último que se supo.
    }
}

async function refrescar() {
    registros.value = await contarRegistros();
    pendientes.value = await contarPendientes();
    sincronizado.value = await db.getSincronizado();
}

// La descarga puede haberla arrancado el login y estar todavía corriendo
// cuando se abre esta pantalla. Cuando termina, la cuenta de registros y el
// «última vez» se ponen al día solos, sin tener que salir y volver a entrar.
watch(corridas, refrescar);

const iniciales = computed(() => (usuario.value?.nombre || '')
    .split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || 'VS');

const cuando = computed(() => {
    if (!sincronizado.value) return 'Nunca';
    const d = new Date(sincronizado.value);
    const hoy = new Date().toDateString() === d.toDateString();
    const hora = d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });
    return hoy ? `Hoy ${hora}` : d.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit' }) + ` ${hora}`;
});

/** Deja sin el «http://» de adelante: en una fila angosta ocupa y no dice nada. */
const direccion = computed(() => (servidor.value || '').replace(/^https?:\/\//, ''));

/**
 * @param {boolean} completa  Vuelve a bajarlo todo desde cero, en vez de solo lo
 *                            que cambió. Es el botón para cuando algo quedó raro:
 *                            no repara nada en particular, pero deja el teléfono
 *                            exactamente como está Softland.
 */
async function sincronizar(completa = false) {
    error.value = '';
    aviso.value = '';
    try {
        const salida = await enviarPendientes();
        const b = await api.bootstrap();
        await db.setUsuario(b.usuario);
        await db.setServidorInfo(b.servidor);
        usuario.value = b.usuario;
        info.value = b.servidor;

        const r = await sincronizarMaestros({ completa });
        await refrescar();

        aviso.value = r.errores.length
            ? `Casi todo actualizado, pero ${r.errores[0]}`
            : `Listo: ${registros.value.toLocaleString('es-CL')} registros.`
                + (salida.enviados ? ` Se enviaron ${salida.enviados} cambios.` : '');
    } catch (e) {
        error.value = e.message;
    }
}

async function reintentarPendientes() {
    error.value = '';
    aviso.value = '';
    const r = await enviarPendientes();
    await refrescar();
    if (r.sinRed) error.value = 'Sin señal: se reintentará al volver la red.';
    else if (r.enviados) aviso.value = `Se enviaron ${r.enviados} cambios.`;
    else if (r.fallidos) error.value = 'Softland rechazó los cambios; míralos en la ficha del cliente.';
}

/** Qué dice la fila de la bandeja. Cada operación se nombra por lo que es. */
function rotuloPendiente(p) {
    if (p.accion === 'cotizacion.crear') return `Nueva cotización de ${p.datos.cliente}`;
    if (p.accion === 'nota_venta.crear') return `Nueva nota de venta de ${p.datos.cliente}`;
    if (p.accion === 'factura.crear') return `Factura por emitir a ${p.datos.receptor}`;
    if (p.accion === 'cliente.crear') return `Nuevo cliente ${p.datos.nombre}`;
    return `Cambios en ${p.datos.nombre || p.clave}`;
}

/*
 * «Emitir igual» sale sólo cuando el servidor preguntó, no cuando rechazó.
 *
 * La diferencia importa: un rechazo por datos no mejora insistiendo, y ofrecer
 * un botón que vuelve a fallar es peor que no ofrecerlo. El servidor marca con
 * `confirmable` el único caso que sí mejora — la factura que esperaba y llegó
 * cuando su nota de venta ya estaba facturada entera.
 */
function sePuedeConfirmar(p) {
    return p.estado === 'rechazado' && p.confirmable;
}

async function confirmarEmision(p) {
    if (! confirm('Se emite la factura igualmente y se manda al SII. Gasta un folio y no se deshace.')) return;
    await confirmarPendiente(p.uuid);
    await refrescar();
}

async function descartarPendiente(p) {
    if (! confirm('¿Descartar este cambio sin enviar? Se pierde lo que se escribió.')) return;
    await descartar(p.uuid);
    await refrescar();
}

async function volverADescargar() {
    if (! confirm('¿Volver a descargar todo? Puede tardar un par de minutos con datos móviles.')) return;
    await sincronizar(true);
}

/** Los maestros con nombre y cuenta, para el detalle desplegable. */
const maestros = computed(() => Object.values(inventarioLocal.value)
    .filter((m) => m.local > 0)
    .sort((a, b) => b.local - a.local));

/** Chat de WhatsApp con soporte, con quién escribe ya puesto en el mensaje. */
function contactarSoporte() {
    abrirSoporte({ usuario: usuario.value, servidor: servidor.value, version });
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

            <!-- Bandeja de salida. Va antes que nada porque es lo único de esta
                 pantalla que pide una decisión: hay trabajo hecho que Softland
                 todavía no tiene. -->
            <template v-if="porEnviar">
                <div class="seccion">
                    <h2>Cambios por enviar</h2>
                    <span class="sub">{{ porEnviar }}</span>
                    <button class="ver-todo" @click="reintentarPendientes">
                        Enviar ahora <AppIcon name="subir" :size="15" color="currentColor" />
                    </button>
                </div>
                <div class="ajustes">
                    <FilaAjuste v-for="p in pendientes" :key="p.uuid"
                                :icono="p.estado === 'rechazado' ? 'error' : 'subir'"
                                :rotulo="rotuloPendiente(p)"
                                :detalle="p.estado === 'rechazado' ? p.mensaje : 'Esperando señal'"
                                :peligro="p.estado === 'rechazado'">
                        <template #control>
                            <button class="enlace" v-if="sePuedeConfirmar(p)"
                                    @click.stop="confirmarEmision(p)">Emitir igual</button>
                            <button class="enlace" @click.stop="descartarPendiente(p)">Descartar</button>
                        </template>
                    </FilaAjuste>
                </div>
            </template>

            <div class="seccion">
                <h2>Datos en el teléfono</h2>
                <button class="ver-todo" @click="detalleDatos = !detalleDatos">
                    {{ detalleDatos ? 'Ocultar' : 'Ver detalle' }}
                    <AppIcon :name="detalleDatos ? 'desplegar' : 'avanzar'" :size="15" color="currentColor" />
                </button>
            </div>

            <!-- Mientras baja se muestra qué maestro va: una descarga completa
                 son unas 12.000 filas y sin esto la pantalla parece colgada. -->
            <div class="descarga" v-if="sincronizando">
                <div class="titulo"><span>{{ progreso.titulo }}</span></div>
                <div class="detalle">
                    {{ progreso.hechas.toLocaleString('es-CL') }}
                    <template v-if="progreso.total">de {{ progreso.total.toLocaleString('es-CL') }}</template>
                    · maestro {{ progreso.indice }} de {{ progreso.recursos }}
                </div>
            </div>

            <div class="ajustes">
                <FilaAjuste icono="sincronizar" rotulo="Sincronizar ahora"
                            :detalle="`Última vez: ${cuando}`" @click="sincronizar(false)">
                    <template #control>
                        <AppIcon name="sincronizar" :size="18" color="var(--indigo)"
                                 :class="{ girando: sincronizando }" />
                    </template>
                </FilaAjuste>
                <FilaAjuste icono="inventario" rotulo="Registros descargados"
                            :valor="registros.toLocaleString('es-CL')" />
                <FilaAjuste :icono="conectado ? 'alDia' : 'sinRed'" rotulo="Conexión"
                            :valor="conectado ? 'En línea' : 'Sin señal'" />
                <FilaAjuste icono="descargar" rotulo="Volver a descargar todo"
                            detalle="Deja el teléfono igual a Softland. Tarda más." lleva
                            @click="volverADescargar" />
            </div>

            <div class="ajustes" v-if="detalleDatos">
                <FilaAjuste v-for="m in maestros" :key="m.recurso" icono="datos"
                            :rotulo="m.recurso.replace(/_/g, ' ')"
                            :valor="m.local.toLocaleString('es-CL')" />
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

            <div class="seccion"><h2>Ayuda</h2></div>
            <div class="ajustes">
                <FilaAjuste icono="soporte" rotulo="Escribir a soporte"
                            :detalle="`WhatsApp ${NUMERO_VISIBLE}`" lleva
                            @click="contactarSoporte" />
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
                <FilaAjuste icono="servidor" rotulo="Versión del servidor"
                            :valor="versionServidor || '—'"
                            :detalle="desfasado ? 'No coincide con la del teléfono' : null" />
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
