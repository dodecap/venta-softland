<script setup>
import { computed } from 'vue';
import { nombre as nombreDe, opciones } from '../catalogos';
import AppIcon from './AppIcon.vue';
import Selector from './Selector.vue';

/**
 * Los otros papeles que este documento nombra.
 *
 * ## Por qué hace falta escribirlas a mano
 *
 * Hay clientes grandes —eléctricas, forestales, mineras— que **no pagan una
 * factura que no nombre su propio documento**: la HES que autorizó el servicio,
 * el contrato marco, la resolución. Ese papel no está en Softland y no se puede
 * deducir de nada: lo sabe quien factura, y hasta ahora no tenía dónde
 * escribirlo. La factura salía correcta para el SII y **impagable** para el
 * cliente.
 *
 * Van al DTE como `<Referencia>` y salen impresas en el papel, que es donde
 * administración las busca.
 *
 * ## Las automáticas no se tocan aquí
 *
 * La orden de compra y el número de la nota de venta las pone el servidor solo
 * —la 801 y la 802— y se enseñan arriba **sin poder editarlas**. No es un
 * candado: es que ya tienen su campo. La orden de compra se escribe en la
 * cabecera, y cambiarla ahí cambia la referencia; tenerla en dos sitios sería
 * tener dos verdades. Se enseñan porque si no, el papel sale con renglones que
 * nadie escribió y parece un error.
 *
 * ## El tipo sale del maestro del ERP
 *
 * `DTE_SiiTDocRef`, que es donde el SII declara sus códigos y de donde sale el
 * rótulo que se imprime. La app no interpreta esa lista: ofrece lo que declare.
 * Lo que sí hace el servidor es **no aceptar un código que no esté ahí**, porque
 * un código inventado es un folio gastado en un documento que el SII rechaza.
 */
const props = defineProps({
    /**
     * Las que pone el servidor solo, para enseñarlas. Cada una,
     * `{ tipo_sii, folio, fecha }`. No se editan desde aquí.
     */
    automaticas: { type: Array, default: () => [] },
    /** La fecha que se propone al agregar una: la del documento. */
    fechaPorOmision: { type: String, default: '' },
    tope: { type: Number, default: 20 },
});

const refs = defineModel({ type: Array, default: () => [] });

/** Si el maestro llegó al teléfono. Sin él no se puede elegir un tipo. */
const hayTipos = computed(() => opciones('referencias_dte').length > 0);

const lleno = computed(() => refs.value.length >= props.tope);

function agregar() {
    if (lleno.value) return;

    refs.value.push({
        // Ninguno elegido de partida: el tipo es lo que el SII lee para saber
        // de qué papel se habla, y proponer uno es proponer que no se mire.
        tipo_sii: '',
        folio: '',
        fecha: props.fechaPorOmision,
        glosa: '',
    });
}

/** Cómo se nombra un tipo: el rótulo del maestro, o el código si no está. */
function rotulo(codigo) {
    return nombreDe('referencias_dte', codigo);
}

/** Una referencia está a medias mientras le falte el tipo o el folio. */
function incompleta(r) {
    return ! String(r.tipo_sii || '').trim() || ! String(r.folio || '').trim();
}

const aMedias = computed(() => refs.value.some(incompleta));

defineExpose({ aMedias });
</script>

<template>
    <div class="seccion">
        <h2>Documentos de referencia</h2>
        <button class="ver-todo" :disabled="lleno || ! hayTipos" @click="agregar">
            Agregar <AppIcon name="crear" :size="15" color="currentColor" />
        </button>
    </div>

    <!-- Las que salen solas. Texto, no controles: su sitio es otro campo. -->
    <div class="ref-automaticas" v-if="automaticas.length">
        <div class="ref-automatica" v-for="(a, i) in automaticas" :key="i">
            <AppIcon name="referencia" :size="15" variant="neutro" />
            <span>{{ rotulo(a.tipo_sii) }} <b>{{ a.folio }}</b></span>
        </div>
        <p class="ayuda">
            Éstas salen solas y ya van en el documento. Se cambian en sus campos, no aquí.
        </p>
    </div>

    <p class="ayuda" v-if="! hayTipos">
        Los tipos de documento todavía no están en el teléfono. Tira la lista de facturas hacia
        abajo para descargarlos.
    </p>

    <p class="ayuda" v-else-if="! refs.length">
        Si el cliente exige que la factura nombre su HES, su contrato o su resolución, se agrega
        aquí y sale impresa.
    </p>

    <div class="linea-doc" v-for="(r, i) in refs" :key="i">
        <div class="linea-cabecera">
            <div>
                <div class="item-titulo">
                    {{ r.tipo_sii ? rotulo(r.tipo_sii) : 'Referencia sin tipo' }}
                </div>
                <div class="item-meta">
                    <span class="etiqueta gris" v-if="r.tipo_sii">{{ r.tipo_sii }}</span>
                    <span v-if="r.folio"> · {{ r.folio }}</span>
                </div>
            </div>
            <button class="icono-barra" title="Quitar" @click="refs.splice(i, 1)">
                <AppIcon name="borrar" :size="18" variant="peligro" />
            </button>
        </div>

        <label class="linea-detalle">
            <span>Tipo de documento</span>
            <Selector v-model="r.tipo_sii" maestro="referencias_dte"
                      vacio="— elegir tipo —" filtrar="Filtrar tipos" />
        </label>

        <div class="ref-campos">
            <label>
                <span>Folio o número</span>
                <!-- Texto, no número: hay folios como «272-OC00008216», y hay
                     HES con letras. Convertirlo a entero daba 272. -->
                <input v-model="r.folio" type="text" maxlength="18" placeholder="Su número">
            </label>
            <label>
                <span>Fecha del documento</span>
                <input v-model="r.fecha" type="date">
            </label>
        </div>

        <label class="linea-detalle">
            <span>Glosa, si hay que aclarar algo</span>
            <input v-model="r.glosa" type="text" maxlength="400"
                   :placeholder="r.tipo_sii ? rotulo(r.tipo_sii) : 'El rótulo del tipo'">
        </label>
        <p class="ayuda" v-if="incompleta(r)">
            Le falta el {{ ! String(r.tipo_sii || '').trim() ? 'tipo' : 'folio' }}: así no se puede
            emitir.
        </p>
    </div>
</template>
