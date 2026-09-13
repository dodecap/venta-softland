<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { monto } from '../catalogos';
import { conectado } from '../red';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Vacio from '../components/Vacio.vue';

/*
 * Lo que el jefe tiene que resolver.
 *
 * Esta aprobación **no existe en Softland**: `nwparam.CheckApruebaNv = N`, el
 * ERP no la pide. La aporta la app, con los topes de descuento y de monto que
 * el administrador le puso a cada vendedor. Una nota de venta que se pasa nace
 * en estado «pendiente» y espera aquí.
 *
 * La pantalla pide señal a propósito y no se guarda para después: aprobar una
 * venta es una decisión con plata detrás y el jefe tiene que estar viendo el
 * número de verdad, no la copia que el teléfono bajó hace tres días.
 */
const router = useRouter();

const lista = ref([]);
const cargando = ref(true);
const resolviendo = ref(null);
const error = ref('');
const aviso = ref('');

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    error.value = '';
    try {
        lista.value = (await api.aprobaciones()).aprobaciones;
    } catch (e) {
        error.value = e.message;
    } finally {
        cargando.value = false;
    }
}

async function resolver(a, aprobar) {
    const comentario = aprobar
        ? null
        : prompt('¿Por qué se rechaza? El vendedor lo va a leer.');

    // Rechazar sin decir por qué no se puede: el vendedor se queda sin saber
    // qué corregir y vuelve a mandar lo mismo.
    if (! aprobar && ! (comentario || '').trim()) return;

    resolviendo.value = a.nota_venta;
    error.value = '';
    aviso.value = '';

    try {
        await api.resolverAprobacion(a.nota_venta, { aprobar, comentario });
        aviso.value = aprobar
            ? `Nota de venta ${a.nota_venta} aprobada.`
            : `Nota de venta ${a.nota_venta} rechazada.`;
        await cargar();
    } catch (e) {
        error.value = e.message;
    } finally {
        resolviendo.value = null;
    }
}

const vacia = computed(() => ! cargando.value && ! lista.value.length);
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>Aprobaciones</h1>
        </div>

        <div class="contenido">
            <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
            <Aviso tipo="ok" v-if="aviso">{{ aviso }}</Aviso>
            <Aviso tipo="info" v-if="! conectado">
                Sin señal. Aprobar una venta necesita conexión: el número tiene que ser el de ahora.
            </Aviso>

            <div class="cargando" v-if="cargando">Cargando…</div>

            <Vacio v-else-if="vacia" icono="alDia" titulo="No hay nada esperando">
                Cuando un vendedor pase su tope de descuento o de monto, la nota de venta aparece aquí.
            </Vacio>

            <div class="tarjeta" v-for="a in lista" :key="a.id">
                <div class="tarjeta-cabecera">Nota de venta {{ a.nota_venta }}</div>
                <div class="tarjeta-cuerpo">
                    <div class="datos">
                        <div><span>Vendedor</span><b>{{ a.vendedor }}</b></div>
                        <div><span>Motivo</span><b>{{ a.motivo }}</b></div>
                        <div class="fuerte"><span>Monto</span><b>{{ monto(a.monto) }}</b></div>
                    </div>

                    <button class="enlace" @click="router.push(`/notas-venta/${a.nota_venta}`)">
                        Ver el detalle
                    </button>

                    <div class="acciones">
                        <button class="boton" :disabled="! conectado || resolviendo === a.nota_venta"
                                @click="resolver(a, true)">
                            <AppIcon name="ok" :size="18" color="currentColor" /> Aprobar
                        </button>
                        <button class="boton peligro" :disabled="! conectado || resolviendo === a.nota_venta"
                                @click="resolver(a, false)">
                            <AppIcon name="error" :size="18" color="currentColor" /> Rechazar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
