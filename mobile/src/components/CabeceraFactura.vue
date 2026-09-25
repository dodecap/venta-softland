<script setup>
import { computed, ref, watch } from 'vue';
import { idb } from '../idb';
import { nombre as nombreDe } from '../catalogos';
import Selector from './Selector.vue';

/**
 * Lo que describe una factura, aparte de sus líneas.
 *
 * ## Por qué es un componente y no dos formularios
 *
 * Hay tres sitios donde se escribe una factura y **la cabecera es la misma en
 * los tres**: la factura suelta, la que nace de una nota de venta y la de
 * comisión. Lo que cambia entre ellos no es qué campos hay, es de dónde salen
 * los valores.
 *
 * Estaba escrito sólo en uno y a medias. La factura suelta preguntaba el cliente
 * y la glosa, y mandaba el centro de costo y la condición **sin enseñarlos**:
 * salían del usuario o del cliente y nadie los veía, así que corregirlos era
 * imposible. La que nace de una nota de venta los enseñaba como texto, sin poder
 * tocarlos, con el argumento de que son datos de la venta — y con el receptor
 * cambiado ese argumento se cae: la factura de comisión va a otro RUT, con otra
 * condición de pago y otro contacto, y heredar los del cliente final es escribir
 * un documento tributario con los datos de quien no lo recibe.
 *
 * De ahí la regla: **heredar es una propuesta, no un candado.** Lo heredado se
 * dice —«viene de la nota de venta»— y se puede cambiar.
 *
 * ## Lo que no se pregunta, y es a propósito
 *
 * **La fecha.** Un DTE lleva la del día en que se emite, y en esta app emitir y
 * mandar al SII son el mismo acto justamente para que no se separen: una factura
 * con fecha de ayer es un mes tributario distinto. Ofrecer el campo sería
 * ofrecer equivocarse en lo único que no se deshace.
 *
 * **El vendedor.** Lo hereda de la nota de venta y sólo se pregunta cuando no
 * hay ninguna detrás; vive en la pantalla, no aquí, porque es de quién es la
 * venta y no una descripción del documento.
 */
const props = defineProps({
    /** El cliente al que sale el documento: de él salen los contactos. */
    receptor: { type: String, default: '' },
    /**
     * Lo que propone el documento de origen, para poder decir de dónde viene
     * cada campo. `null` en la factura suelta, donde no viene de ningún sitio.
     */
    heredado: { type: Object, default: null },
    /** Lo que mide `iw_gsaen.Glosa`. Se avisa del recorte, nunca se corta callado. */
    largoGlosa: { type: Number, default: 255 },
    /**
     * Si el documento se le factura a alguien que no es el cliente del origen.
     * Cuando eso pasa, lo heredado deja de ser una buena propuesta y hay que
     * decirlo: la condición de pago y el contacto son del cliente, no del pedido.
     */
    aOtro: { type: Boolean, default: false },
});

const contacto = defineModel('contacto', { type: String, default: '' });
const condicion = defineModel('condicion', { type: String, default: '' });
const centroCosto = defineModel('centroCosto', { type: String, default: '' });
const bodega = defineModel('bodega', { type: String, default: '' });
const oc = defineModel('oc', { type: String, default: '' });
const glosa = defineModel('glosa', { type: String, default: '' });

/** Los contactos del cliente que recibe el documento. */
const contactos = ref([]);

/*
 * Al cambiar el receptor cambia la lista de contactos, y nada más.
 *
 * **Vaciar el campo al cambiar de cliente no se hace aquí**, aunque sea lo que
 * hay que hacer: un contacto de otra empresa impreso en un documento tributario
 * es un error que nadie nota hasta que alguien llama. Lo hace quien cambia el
 * receptor, que es la pantalla, porque aquí no se puede distinguir «el vendedor
 * eligió otro cliente» de «la nota de venta acabó de cargar». Los dos se ven
 * igual desde este `watch` —el receptor pasa de vacío a un código— y borrar en el
 * segundo caso se llevaba por delante el contacto heredado de la venta, a veces:
 * dependía de cuál de las dos lecturas de IndexedDB terminara antes.
 */
watch(() => props.receptor, async (codigo) => {
    contactos.value = codigo ? await idb.porIndice('contactos', 'cliente', codigo) : [];

    // Un solo contacto se elige solo: preguntarlo sería preguntar por preguntar.
    // Sólo si el campo está vacío, que es lo que lo hace inofensivo aquí.
    if (! contacto.value && contactos.value.length === 1) {
        contacto.value = contactos.value[0].nombre;
    }
}, { immediate: true });

const seRecorta = computed(() => glosa.value.trim().length > props.largoGlosa);

/**
 * Si este campo sigue siendo el que propuso el origen.
 *
 * Sirve para decir «viene de la nota de venta» y para callarse cuando alguien ya
 * lo cambió: una etiqueta que dice de dónde viene un valor que ya no viene de
 * ahí es peor que ninguna etiqueta.
 */
function heredadoAun(campo, valor) {
    const propuesto = props.heredado?.[campo];

    return !! propuesto && String(propuesto) === String(valor || '');
}
</script>

<template>
    <!--
        El contacto. Con lista si el cliente tiene contactos cargados, y a mano
        si no: `NomContacto` es texto libre en el ERP, así que un cliente sin
        ficha de contactos no puede quedarse sin poder nombrar a nadie.
    -->
    <label>Contacto</label>
    <select v-if="contactos.length" v-model="contacto">
        <option value="">— sin contacto —</option>
        <option v-for="c in contactos" :key="c.nombre" :value="c.nombre">{{ c.nombre }}</option>
    </select>
    <input v-else v-model="contacto" type="text" maxlength="30"
           placeholder="A nombre de quién va, si hace falta">
    <p class="ayuda" v-if="heredado && heredadoAun('contacto', contacto)">
        Viene de la nota de venta.
    </p>
    <p class="ayuda" v-else-if="aOtro && ! contacto">
        Este documento va a otro cliente: el contacto de la nota de venta no sirve aquí.
    </p>

    <label>Condición de venta</label>
    <Selector v-model="condicion" maestro="condiciones_venta"
              vacio="— la del cliente —" filtrar="Filtrar condiciones" />
    <p class="ayuda" v-if="heredado && heredadoAun('condicion', condicion)">
        Viene de la nota de venta: {{ nombreDe('condiciones_venta', condicion) }}.
    </p>
    <p class="ayuda" v-else-if="! condicion">
        Sin elegir, la factura sale con la condición que tenga la ficha del cliente.
    </p>

    <label>Centro de costo</label>
    <Selector v-model="centroCosto" maestro="centros_costo"
              vacio="— sin centro de costo —" filtrar="Filtrar centros de costo" />
    <p class="ayuda" v-if="heredado && heredadoAun('centro_costo', centroCosto)">
        Viene de la nota de venta.
    </p>

    <label>Bodega</label>
    <Selector v-model="bodega" maestro="bodegas" vacio="— la general —" filtrar="Filtrar bodegas" />
    <p class="ayuda" v-if="heredado && heredadoAun('bodega', bodega)">
        Viene de la nota de venta: de aquí sale la mercadería.
    </p>

    <label>Orden de compra del cliente</label>
    <input v-model="oc" type="text" maxlength="18" placeholder="Sin orden de compra">
    <p class="ayuda">
        Va al DTE como referencia <b>Orden de Compra</b>. Es lo que le sirve a quien recibe la
        factura para cuadrarla contra lo que encargó.
    </p>

    <label>Observación</label>
    <textarea v-model="glosa" rows="2" placeholder="Lo que tiene que leer el cliente"></textarea>
    <p class="ayuda" v-if="seRecorta">
        <b>No cabe entera.</b> En la factura caben {{ largoGlosa }} caracteres y llevas
        {{ glosa.trim().length }}: se escribirá cortada ahí.
    </p>
</template>
