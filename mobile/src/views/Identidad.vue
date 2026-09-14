<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';

/*
 * La identidad corporativa: quién emite los documentos y cómo se ven.
 *
 * ## Softland propone, esta pantalla dispone
 *
 * Casi toda la ficha ya está en el ERP y el servidor la hereda. Aquí cada campo
 * muestra en gris lo que dice Softland, y lo que se escriba encima manda. Un
 * campo en blanco no es un campo vacío: es «usa el del ERP».
 *
 * Eso importa porque las dos direcciones son verdad. Softland guarda el
 * domicilio tributario; el documento que ve el cliente muestra la oficina
 * comercial. Mezclarlas en un solo valor obligaría a elegir una y equivocarse
 * en la otra.
 */

const router = useRouter();
const cargando = ref(true);
const guardando = ref('');
const error = ref('');
const aviso = ref('');

const propio = ref({});
const heredado = ref({});
const efectivo = ref({});
const logoUrl = ref('');
const archivo = ref(null);

const CAMPOS_TEXTO = [
    ['razon_social', 'Razón social', 'La que va en el pie, junto al RUT'],
    ['nombre_comercial', 'Nombre comercial', 'El de la marca, si difiere de la razón social'],
    ['rut', 'RUT', ''],
    ['giro', 'Giro', ''],
    ['direccion', 'Dirección', 'La comercial, si no es la tributaria'],
    ['comuna', 'Comuna', ''],
    ['ciudad', 'Ciudad', ''],
    ['fono', 'Teléfono', ''],
    ['email', 'Correo', ''],
    ['web', 'Sitio web', ''],
];

const tieneLogo = computed(() => !! efectivo.value.tiene_logo);

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    error.value = '';
    try {
        const r = await api.identidad();
        propio.value = { ...r.propio };
        heredado.value = r.heredado;
        efectivo.value = r.efectivo;
        await refrescarLogo();
    } catch (e) {
        error.value = e.message;
    } finally {
        cargando.value = false;
    }
}

/**
 * El logo se pide con token, así que no se puede poner la URL en un `<img>` a
 * secas: se baja y se muestra como objeto local. El `revoke` del anterior evita
 * ir dejando copias en memoria cada vez que se cambia.
 */
async function refrescarLogo() {
    if (logoUrl.value) URL.revokeObjectURL(logoUrl.value);
    logoUrl.value = '';

    if (! efectivo.value.tiene_logo) return;

    try {
        const servidor = await db.getServidor();
        const token = await db.getToken();
        const res = await fetch(`${servidor}/api/identidad/logo`, {
            headers: { Authorization: 'Bearer ' + token },
        });
        if (res.ok) logoUrl.value = URL.createObjectURL(await res.blob());
    } catch { /* sin logo se muestra el hueco, no un error */ }
}

async function guardar() {
    error.value = '';
    aviso.value = '';
    guardando.value = 'datos';
    try {
        const r = await api.guardarIdentidad(propio.value);
        propio.value = { ...r.propio };
        heredado.value = r.heredado;
        efectivo.value = r.efectivo;
        aviso.value = r.message;
    } catch (e) {
        error.value = e.message;
    } finally {
        guardando.value = '';
    }
}

function elegirArchivo(e) {
    archivo.value = e.target.files?.[0] ?? null;
    if (archivo.value) subirLogo();
}

async function subirLogo() {
    error.value = '';
    aviso.value = '';
    guardando.value = 'logo';
    try {
        const r = await api.subirLogo(archivo.value);
        aviso.value = r.message;
        efectivo.value = { ...efectivo.value, tiene_logo: true };
        await refrescarLogo();
    } catch (e) {
        error.value = e.message;
    } finally {
        guardando.value = '';
        archivo.value = null;
    }
}

async function borrarLogo() {
    if (! confirm('¿Quitar el logo? Los documentos saldrán con el nombre de la empresa.')) return;
    error.value = '';
    guardando.value = 'logo';
    try {
        const r = await api.borrarLogo();
        aviso.value = r.message;
        efectivo.value = { ...efectivo.value, tiene_logo: false };
        await refrescarLogo();
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
            <h1>Identidad corporativa</h1>
        </div>

        <div class="contenido">
            <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
            <Aviso tipo="ok" v-if="aviso">{{ aviso }}</Aviso>
            <div class="cargando" v-if="cargando">Cargando…</div>

            <template v-else>
                <Aviso tipo="info">
                    Lo que dejes en blanco lo toma de Softland — en gris ves qué diría.
                    Esto es lo que sale impreso en cotizaciones y notas de venta.
                </Aviso>

                <!-- ─────────────────────────────────────────────── logo -->
                <div class="tarjeta">
                    <div class="tarjeta-cabecera">Logo</div>
                    <div class="tarjeta-cuerpo">
                        <div class="logo-vista">
                            <img v-if="logoUrl" :src="logoUrl" alt="Logo de la empresa">
                            <div class="logo-vacio" v-else>
                                <AppIcon name="logo" :size="28" />
                                <span>Sin logo: los documentos salen con el nombre de la empresa</span>
                            </div>
                        </div>

                        <label class="boton-archivo">
                            <AppIcon name="subir" :size="18" color="currentColor" />
                            {{ guardando === 'logo' ? 'Subiendo…' : (tieneLogo ? 'Cambiar logo' : 'Subir logo') }}
                            <input type="file" accept="image/png,image/jpeg"
                                   :disabled="guardando === 'logo'" @change="elegirArchivo">
                        </label>

                        <button class="boton-texto peligro" v-if="tieneLogo"
                                :disabled="guardando === 'logo'" @click="borrarLogo">
                            Quitar el logo
                        </button>

                        <p class="ayuda">
                            PNG o JPG, hasta 2 MB. Se reduce a 600 px de ancho, que es lo que
                            cabe en una hoja A4: más resolución sólo engorda el correo.
                            La transparencia del PNG se conserva.
                        </p>
                    </div>
                </div>

                <!-- ───────────────────────────────────────────── la ficha -->
                <div class="tarjeta">
                    <div class="tarjeta-cabecera">Datos de la empresa</div>
                    <div class="tarjeta-cuerpo">
                        <template v-for="[campo, rotulo, ayuda] in CAMPOS_TEXTO" :key="campo">
                            <label>{{ rotulo }}</label>
                            <input v-model="propio[campo]" type="text"
                                   :placeholder="heredado[campo] || '—'"
                                   autocapitalize="off" spellcheck="false">
                            <p class="ayuda" v-if="ayuda">{{ ayuda }}</p>
                        </template>
                    </div>
                </div>

                <!-- ─────────────────────────────────────── el documento -->
                <div class="tarjeta">
                    <div class="tarjeta-cabecera">El documento</div>
                    <div class="tarjeta-cuerpo">
                        <div class="fila">
                            <div>
                                <label>Color corporativo</label>
                                <input v-model="propio.color" type="text" placeholder="#1d1060"
                                       autocapitalize="off" spellcheck="false">
                            </div>
                            <div class="angosto">
                                <label>Vigencia</label>
                                <input v-model="propio.vigencia_cotizacion_dias" type="text"
                                       inputmode="numeric" :placeholder="heredado.vigencia_cotizacion_dias">
                            </div>
                        </div>
                        <p class="ayuda">
                            El color se usa con moderación: el filete del pie, los títulos de
                            las columnas y el total. La vigencia son días, y calcula el
                            «válida hasta» de la cotización.
                        </p>

                        <label>Qué vende la empresa</label>
                        <select v-model="propio.modelo_negocio">
                            <option value="">Como está configurado ({{ heredado.modelo_negocio }})</option>
                            <option value="PRODUCTOS">Productos</option>
                            <option value="SERVICIOS">Servicios</option>
                            <option value="MIXTO">Los dos</option>
                        </select>
                        <p class="ayuda">
                            Decide las columnas del detalle. En servicios la descripción se
                            lleva el ancho y el código se va: un servicio se cotiza por lo
                            que vale, no por lo que cuesta la unidad.
                        </p>

                        <label>Condiciones comerciales</label>
                        <textarea v-model="propio.condiciones_comerciales" rows="4"
                                  placeholder="Una condición por línea"></textarea>

                        <label>Datos bancarios</label>
                        <textarea v-model="propio.datos_bancarios" rows="3"
                                  placeholder="Banco, cuenta, RUT — una línea cada uno"></textarea>

                        <label>Pie del documento</label>
                        <input v-model="propio.pie_documento" type="text"
                               placeholder="Una línea, bajo la dirección">
                    </div>
                </div>

                <button class="boton" :disabled="guardando === 'datos'" @click="guardar">
                    {{ guardando === 'datos' ? 'Guardando…' : 'Guardar identidad' }}
                </button>
            </template>
        </div>
    </div>
</template>
