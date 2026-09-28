<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ErrorApi } from '../api';
import { idb } from '../idb';
import { monto, fecha as fechaTexto, nombre as nombreDe } from '../catalogos';
import { conectado } from '../red';
import { nuevoUuid } from '../pendientes';
import { refrescarGrupo } from '../sync';
import { diasVencido } from '../cartera';
import { aplicaciones, clave, exceso as excesoDe, impedimento as impedimentoDe, saldoDe, totalAbonado } from '../cobro';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Vacio from '../components/Vacio.vue';

/*
 * Cobrarle a un cliente: el comprobante de ingreso, escrito en contabilidad.
 *
 * ## Esta pantalla decide muy poco, y es a propósito
 *
 * Manda tres cosas: **a quién**, **con qué forma de pago** y **cuánto a cada
 * documento**. Nada más. La cuenta contable de cada medio sale de `iwparam`,
 * la fecha de emisión de cada documento se lee de la cuenta corriente y el
 * número del comprobante lo reparte el servidor dentro de su transacción. Es
 * la misma regla que «el teléfono no decide impuestos»: una cuenta contable
 * dentro de un APK que se descompila no pinta nada, y un saldo guardado en
 * IndexedDB hace media hora no es el saldo.
 *
 * Lo poco que sí decide —cuánto suma, qué se pasa del saldo y por qué el botón
 * está apagado— vive en `src/cobro.js` y no aquí: lo usan el botón y el cuerpo
 * que se manda, y dos copias de esa regla serían un botón encendido sobre un
 * cobro que el servidor va a rechazar.
 *
 * ## Por qué hace falta señal
 *
 * Porque el recibo es el número del comprobante, y ese número no existe hasta
 * que el servidor lo escribe. Una cotización sin señal se guarda y se manda
 * luego; un cobro sin señal sería un papel con un hueco donde va lo único que
 * el cliente va a mirar. Se apaga el botón y se dice por qué, que es más
 * honesto que encolar una promesa.
 *
 * ## Una forma de pago por cobro
 *
 * El servidor admite varias —el asiento lleva una fila al debe por
 * instrumento— pero la pantalla ofrece una. Quien paga en terreno paga con una
 * cosa: el efectivo, el cheque, la transferencia que acaba de hacer. Repartir
 * un cobro entre dos instrumentos es trabajo de escritorio, y ahí está el
 * Softland de siempre.
 *
 * ## La fecha del pago no es la de hoy necesariamente
 *
 * Va en `MovFv` y es **el día en que se movió la plata**. Quien transfirió el
 * día 1 y a quien le arman el comprobante el 20 tiene que quedar con el día 1,
 * así que el campo existe y se puede corregir. La fecha del comprobante, en
 * cambio, es la de hoy y no se pregunta.
 */

const route = useRoute();
const router = useRouter();

const codigo = route.params.cliente;

const cliente = ref(null);
const documentos = ref([]);
const medios = ref([]);
const permisos = ref({ cobrar: false, sobre_saldo: false, borrar: false });
const problemas = ref([]);

const cargando = ref(true);
/* Si la propuesta llegó. Sin ella no hay medios ni permisos que enseñar, y
   cualquier diagnóstico sería inventado: ver `cobro.js`. */
const consultado = ref(false);
const error = ref('');
const enviando = ref(false);
const hecho = ref(null);

/* Qué se abona a cada documento, por `tipo-numero`. Vacío = no se toca. */
const abonos = ref({});
const medio = ref('');
const referencia = ref('');
const fechaPago = ref(hoy());
const glosa = ref('');

/* El mismo `client_uuid` mientras este cobro siga siendo este cobro. Si el
   envío falla a medias y se reintenta, el servidor reconoce el de antes y no
   escribe un segundo asiento. Se estrena uno nuevo sólo al empezar otro. */
const uuid = ref(nuevoUuid());

onMounted(cargar);

function hoy() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

async function cargar() {
    cargando.value = true;
    error.value = '';

    cliente.value = await idb.obtener('clientes', codigo);

    try {
        const p = await api.propuestaCobro(codigo);

        documentos.value = p.documentos || [];
        medios.value = p.medios || [];
        permisos.value = p.permisos || permisos.value;
        problemas.value = p.problemas || [];
        consultado.value = true;

        if (! medio.value) medio.value = medios.value[0]?.codigo || '';
    } catch (e) {
        // Sin señal se enseña lo que hay en el almacén: sirve para mirar la
        // deuda, aunque no se pueda cobrar. Lo que no se puede es inventarse
        // las formas de pago ni los permisos, que son del servidor.
        error.value = e.message;
        consultado.value = false;
        documentos.value = (await idb.todos('cartera')).filter((d) => d.cliente === codigo);
    } finally {
        cargando.value = false;
    }
}

function elegido(d) {
    return abonos.value[clave(d)] !== undefined;
}

/** Tocar la fila abona el saldo entero; volver a tocarla lo quita. */
function alternar(d) {
    const k = clave(d);
    const copia = { ...abonos.value };

    if (copia[k] === undefined) copia[k] = saldoDe(d);
    else delete copia[k];

    abonos.value = copia;
}

function escribirAbono(d, valor) {
    const n = Number(String(valor).replace(/[^\d]/g, ''));
    abonos.value = { ...abonos.value, [clave(d)]: Number.isFinite(n) ? n : 0 };
}

function exceso(d) {
    return excesoDe(d, abonos.value);
}

const deuda = computed(() => documentos.value.reduce((s, d) => s + saldoDe(d), 0));

const total = computed(() => totalAbonado(abonos.value));

const medioElegido = computed(() =>
    medios.value.find((m) => m.codigo === medio.value) || null);

const excede = computed(() => documentos.value.some((d) => exceso(d) > 0));

const impedimento = computed(() => impedimentoDe({
    conectado: conectado.value,
    consultado: consultado.value,
    problemas: problemas.value,
    permisos: permisos.value,
    medios: medios.value,
    medio: medio.value,
    total: total.value,
    excede: excede.value,
    fecha: fechaPago.value,
}));

async function cobrar() {
    if (impedimento.value || enviando.value) return;

    enviando.value = true;
    error.value = '';

    try {
        hecho.value = await api.cobrar({
            client_uuid: uuid.value,
            cliente: codigo,
            glosa: glosa.value.trim() || null,
            pagos: [{
                medio: medio.value,
                numero: Number(String(referencia.value).replace(/[^\d]/g, '')) || 0,
                fecha: fechaPago.value,
                aplicaciones: aplicaciones(documentos.value, abonos.value),
            }],
        });

        // La cartera acaba de cambiar y es de las que se bajan enteras: se
        // vuelve a pedir para que la lista de atrás no siga diciendo lo de
        // antes. Si falla, el cobro está escrito igual — el tirón lo arregla.
        try {
            await refrescarGrupo('cobranza');
        } catch { /* la cartera se pondrá al día en el próximo tirón */ }
    } catch (e) {
        error.value = e instanceof ErrorApi ? e.message : 'No se pudo escribir el cobro.';
    } finally {
        enviando.value = false;
    }
}

function otro() {
    hecho.value = null;
    abonos.value = {};
    referencia.value = '';
    glosa.value = '';
    fechaPago.value = hoy();
    uuid.value = nuevoUuid();
    cargar();
}

function tipoDe(d) {
    return nombreDe('tipos_documento', d.tipo);
}

function edad(d) {
    const n = diasVencido(d);

    if (n === null) return 'Sin fecha';
    if (n > 0) return `${n} ${n === 1 ? 'día' : 'días'} de atraso`;
    if (n === 0) return 'Vence hoy';

    return `Vence en ${-n} ${n === -1 ? 'día' : 'días'}`;
}
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()" title="Volver">
                <AppIcon name="atras" :size="24" />
            </button>
            <h1>Cobrar</h1>
        </div>

        <div class="contenido">
            <!-- Después de cobrar no queda formulario: queda el comprobante.
                 Dejarlo con los campos puestos invita a pulsar otra vez. -->
            <template v-if="hecho">
                <div class="tarjeta">
                    <div class="tarjeta-cabecera">Comprobante {{ hecho.numero }}</div>
                    <div class="tarjeta-cuerpo comprobante-hecho">
                        <AppIcon name="comprobante" :size="26" :recuadro="52" />
                        <div class="cifra">{{ monto(hecho.total) }}</div>
                        <p class="ayuda">
                            Abonados a {{ cliente?.nombre || codigo }}, año {{ hecho.ano }}.
                        </p>
                        <p class="ayuda">
                            Quedó escrito en la contabilidad de Softland, vigente, y la cartera
                            ya lo descontó.
                        </p>
                    </div>
                </div>

                <button class="boton principal" @click="otro">Cobrar otra cosa</button>
                <button class="boton" @click="router.back()">Volver a la cartera</button>
            </template>

            <template v-else>
                <div class="tarjeta">
                    <div class="tarjeta-cabecera">{{ cliente?.nombre || codigo }}</div>
                    <div class="tarjeta-cuerpo">
                        <p class="ayuda">
                            {{ codigo }} · debe <b>{{ monto(deuda) }}</b> en
                            {{ documentos.length }}
                            {{ documentos.length === 1 ? 'documento' : 'documentos' }}
                        </p>
                    </div>
                </div>

                <Aviso tipo="error" v-if="error">{{ error }}</Aviso>

                <!-- Lo que falta configurar se dice arriba y entero: es cosa
                     del administrador, no de quien está intentando cobrar. -->
                <Aviso tipo="error" v-for="p in problemas" :key="p">{{ p }}</Aviso>

                <Vacio v-if="! cargando && ! documentos.length" icono="alDia"
                       titulo="No debe nada">
                    Este cliente no tiene documentos abiertos en su cuenta corriente.
                </Vacio>

                <template v-else>
                    <div class="seccion"><h2>Qué se paga</h2></div>

                    <!-- Tocar la fila abona el saldo entero, que es lo que pasa
                         nueve de cada diez veces. El importe se corrige sólo si
                         hace falta, y entonces el campo ya está a la vista. -->
                    <div class="doc-cobro" v-for="d in documentos" :key="clave(d)"
                         :class="{ elegido: elegido(d) }">
                        <button class="marca" @click="alternar(d)"
                                :title="elegido(d) ? 'Quitar' : 'Abonar entero'">
                            <AppIcon :name="elegido(d) ? 'ok' : 'crear'" :size="20"
                                     color="currentColor" />
                        </button>

                        <div class="texto" @click="alternar(d)">
                            <b>{{ tipoDe(d) }} Nº {{ d.numero }}</b>
                            <small>{{ fechaTexto(d.vencimiento || d.emision) }} · {{ edad(d) }}</small>
                        </div>

                        <div class="importe">
                            <div class="saldo">{{ monto(d.saldo) }}</div>
                            <input v-if="elegido(d)" inputmode="numeric"
                                   :value="abonos[clave(d)]"
                                   @input="escribirAbono(d, $event.target.value)" />
                            <small class="exceso" v-if="exceso(d) > 0">
                                {{ monto(exceso(d)) }} sobre el saldo
                            </small>
                        </div>
                    </div>

                    <div class="seccion"><h2>Con qué se paga</h2></div>

                    <div class="pestanas en-linea" v-if="medios.length">
                        <button v-for="m in medios" :key="m.codigo"
                                :class="{ activa: medio === m.codigo }"
                                @click="medio = m.codigo">
                            {{ m.rotulo }}
                        </button>
                    </div>

                    <Aviso tipo="info" v-else-if="consultado && ! cargando">
                        La empresa no tiene ninguna forma de pago con cuenta contable.
                        Se configuran en Softland, en los parámetros de facturación.
                    </Aviso>

                    <div class="tarjeta">
                        <div class="tarjeta-cuerpo">
                            <label>Número del {{ medioElegido?.rotulo?.toLowerCase() || 'pago' }}</label>
                            <input v-model="referencia" inputmode="numeric"
                                   placeholder="El del cheque, el de la transferencia…">
                            <p class="ayuda">
                                Opcional. Queda escrito junto al abono, para reconocerlo después.
                            </p>

                            <label>Fecha del pago</label>
                            <input type="date" v-model="fechaPago">
                            <p class="ayuda">
                                El día en que se movió la plata, que no tiene por qué ser hoy.
                            </p>

                            <label>Glosa</label>
                            <input v-model="glosa" maxlength="60"
                                   placeholder="Lo que el comprobante dirá">
                        </div>
                    </div>

                    <div class="resumen-cobro" v-if="total > 0">
                        <span>Se abona</span>
                        <b>{{ monto(total) }}</b>
                    </div>

                    <!-- Si ya hay un error arriba, no se repite el motivo con
                         otras palabras: el botón apagado y el error bastan. -->
                    <Aviso tipo="info" v-if="impedimento && ! cargando && ! error">{{ impedimento }}</Aviso>

                    <button class="boton principal" :disabled="!! impedimento || enviando"
                            @click="cobrar">
                        <AppIcon name="cobrar" :size="20" color="currentColor" />
                        {{ enviando ? 'Escribiendo el comprobante…'
                            : (total > 0 ? `Cobrar ${monto(total)}` : 'Cobrar') }}
                    </button>

                    <p class="ayuda centrado">
                        El comprobante se escribe vigente en la contabilidad, con el número que
                        le toque. Se puede borrar después, pero no anular: en contabilidad no
                        existe el estado nulo.
                    </p>
                </template>
            </template>
        </div>
    </div>
</template>
