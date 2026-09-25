<script setup>
import { computed, ref } from 'vue';
import { opciones } from '../catalogos';
import AppIcon from './AppIcon.vue';

/**
 * Elegir un código de un maestro: se escribe y se filtra, en el propio campo.
 *
 * ## Por qué no es un `<select>`
 *
 * Un `<select>` con las 2.009 opciones de giro no sirve: en Android abrirlo
 * tarda y desplazarlo hasta «CALZADOS Y CURTIEMBRES» es imposible. Eso ya
 * estaba resuelto con un campo de filtrar **encima** del `<select>`, y seguía
 * costando demasiado: filtrar y elegir eran dos controles distintos, así que
 * poner un centro de costo de los 594 era tocar el filtro, escribir, tocar el
 * `<select>`, esperar la ventana nativa de Android y tocar la opción. Cuatro
 * gestos y dos capas para un dato de un formulario que tiene seis campos más.
 *
 * Aquí es **un solo control**: se toca el campo, se escribe y se toca lo que
 * salió. Dos gestos y ninguna ventana nativa. El filtro mira el nombre **y el
 * código**, porque quien conoce su centro de costo lo conoce por el número.
 *
 * ## Lo que hay que saber si se toca
 *
 * - **La lista va en el flujo, no flotando.** Una capa absoluta la recorta
 *   cualquier ancestro con `overflow` —y estos campos viven dentro de
 *   tarjetas—, así que empuja lo de abajo mientras está abierta. Con el teclado
 *   ocupando media pantalla, lo de abajo no se estaba mirando.
 * - **El nombre puesto se lee en el campo cuando está cerrado, y de marcador
 *   cuando está abierto.** Al abrir, el filtro nace vacío: así se ve la lista
 *   entera sin tener que borrar nada, y lo que había puesto sigue a la vista en
 *   gris. Dejar el nombre dentro obligaría a borrarlo para poder buscar.
 * - **Elegir no puede reabrir la lista.** Estos campos van dentro de un
 *   `<label>` en algunas pantallas, y un `<label>` reenvía el toque al control
 *   que envuelve: sin `.stop` en la fila, elegir volvía a enfocar el campo y la
 *   lista se abría otra vez. Por eso el toque de la fila no sube, y además hay
 *   un plazo corto que ignora el foco recién después de elegir.
 * - **Un código que el maestro no trae se enseña tal cual**, en vez de dejar el
 *   campo en blanco sobre un dato que sí está guardado: puede no haberse bajado,
 *   o haberlo escrito el Softland de escritorio.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    /** Nombre del maestro en `catalogos.js`: giros, comunas, ciudades, cargos… */
    maestro: { type: String, required: true },
    /** Texto de la opción en blanco. Si es null, el campo es obligatorio. */
    vacio: { type: String, default: '— sin elegir —' },
    filtrar: { type: String, default: 'Filtrar' },
    /** Cuántas se dibujan a la vez. El resto se acota escribiendo. */
    tope: { type: Number, default: 40 },
});

const emit = defineEmits(['update:modelValue']);

const campo = ref(null);
const abierto = ref(false);
const filtro = ref('');

/** Plazo corto tras elegir, para que el foco de vuelta no reabra la lista. */
let acabaDeElegir = 0;
/** Cierre con retraso al salir del campo, para no adelantarse al toque. */
let reloj = null;

const todas = computed(() => opciones(props.maestro));

/**
 * Lo que hay puesto, como opción. Si el maestro no trae el código —no se bajó,
 * o lo escribió Softland y aquí no existe— se enseña el código pelado: es más
 * honesto que un campo en blanco sobre un dato que sí está guardado.
 */
const puesto = computed(() => {
    const c = String(props.modelValue || '').trim();
    if (! c) return null;

    return todas.value.find((o) => String(o.codigo) === c) ?? { codigo: c, nombre: c };
});

const calzan = computed(() => {
    const q = filtro.value.trim().toLowerCase();
    if (! q) return todas.value;

    return todas.value.filter((o) => (o.nombre || '').toLowerCase().includes(q)
        || String(o.codigo).toLowerCase().includes(q));
});

/*
 * Lo que se dibuja. Sin filtro, lo puesto se cuela arriba aunque caiga fuera
 * del tope: es la respuesta a «¿qué tengo?», y con 594 centros de costo no iba
 * a salir en los primeros cuarenta.
 *
 * **Con filtro no se cuela.** Aquí sólo hay coincidencias, y colarla haría que
 * un filtro que no encuentra nada enseñara una fila con aire de resultado.
 * Antes sí había que colarla, cuando esto era un `<select>` y la opción que
 * desaparecía de la lista se llevaba por delante el valor del campo; ahora el
 * valor no vive en la lista.
 */
const lista = computed(() => {
    const vistas = calzan.value.slice(0, props.tope);
    const c = puesto.value?.codigo;

    if (! filtro.value.trim() && c && ! vistas.some((o) => String(o.codigo) === String(c))) {
        vistas.unshift(puesto.value);
    }

    return vistas;
});

/*
 * Cuántas calzan y no se están viendo. Se cuenta contra lo dibujado y no con
 * `calzan − tope`: la opción puesta se cuela en la lista aunque caiga fuera del
 * tope, así que esa resta decía una más de las que faltaban.
 */
const sobran = computed(() => {
    const vistas = new Set(lista.value.map((o) => String(o.codigo)));

    return calzan.value.reduce((n, o) => n + (vistas.has(String(o.codigo)) ? 0 : 1), 0);
});

/** En el campo: el filtro mientras se escribe, el nombre puesto si está cerrado. */
const texto = computed(() => (abierto.value ? filtro.value : puesto.value?.nombre || ''));

/*
 * El marcador del campo. Abierto dice lo que hay puesto, que es lo que se acaba
 * de quitar de la vista para poder escribir. Cerrado y vacío dice qué pasa si se
 * deja así —«sin centro de costo», «la del cliente»—, y si el campo es
 * obligatorio (`vacio` en null) no hay nada que decir de eso: se pide elegir.
 */
const marcador = computed(() => (abierto.value ? puesto.value?.nombre || props.filtrar
    : props.vacio ?? '— elegir —'));

function abrir() {
    if (Date.now() - acabaDeElegir < 350) return;

    clearTimeout(reloj);
    filtro.value = '';
    abierto.value = true;
}

function escribir(valor) {
    filtro.value = valor;
    abierto.value = true;
}

function elegir(codigo) {
    acabaDeElegir = Date.now();
    clearTimeout(reloj);
    abierto.value = false;
    filtro.value = '';
    emit('update:modelValue', String(codigo ?? ''));

    // Elegido el dato, el teclado ya no hace falta y tapa media pantalla.
    campo.value?.blur();
}

/*
 * Salir del campo cierra, pero no de inmediato: el toque de una fila llega
 * después de que el navegador quita el foco. Las filas ya lo evitan con
 * `mousedown.prevent`, y esto es el segundo resguardo.
 */
function salir() {
    clearTimeout(reloj);
    reloj = setTimeout(() => { abierto.value = false; filtro.value = ''; }, 160);
}

/** La tecla de «listo» del teclado elige la primera, que es lo que se buscaba. */
function aceptar() {
    if (filtro.value.trim() && lista.value.length) elegir(lista.value[0].codigo);
    else campo.value?.blur();
}
</script>

<template>
    <div class="combo" :class="{ abierto }">
        <div class="campo-buscar">
            <AppIcon name="buscar" :size="18" />
            <input ref="campo" :value="texto" type="text" role="combobox"
                   :aria-expanded="abierto" :placeholder="marcador"
                   inputmode="search" enterkeyhint="done"
                   autocapitalize="off" autocomplete="off" spellcheck="false"
                   @focus="abrir" @click.stop="abrir" @blur="salir"
                   @input="escribir($event.target.value)"
                   @keydown.enter.prevent="aceptar">
            <!--
                Cerrado, la comilla hacia abajo dice que esto es una lista y no
                un campo de texto libre. Abierto con algo escrito, la X borra
                el filtro sin bajar el teclado.
            -->
            <button v-if="abierto && filtro" class="limpiar" type="button" aria-label="Limpiar"
                    @mousedown.prevent.stop @click.stop="escribir('')">
                <AppIcon name="cerrar" :size="16" color="currentColor" />
            </button>
            <AppIcon v-else name="desplegar" :size="18" />
        </div>

        <div class="combo-lista" v-if="abierto">
            <!-- Quitar lo puesto. Sólo si el campo admite quedarse vacío. -->
            <div class="combo-opcion" v-if="vacio !== null && ! filtro"
                 :class="{ puesta: ! puesto }"
                 @mousedown.prevent.stop @click.stop="elegir('')">
                <span class="combo-nombre">{{ vacio }}</span>
            </div>

            <div class="combo-opcion" v-for="o in lista" :key="o.codigo"
                 :class="{ puesta: String(o.codigo) === String(modelValue || '') }"
                 @mousedown.prevent.stop @click.stop="elegir(o.codigo)">
                <span class="combo-nombre">{{ o.nombre }}</span>
                <span class="combo-codigo">{{ o.codigo }}</span>
            </div>

            <p class="ayuda" v-if="! lista.length">
                Nada con «{{ filtro }}». Prueba con otra palabra o con el código.
            </p>
            <p class="ayuda" v-else-if="sobran > 0">
                Hay {{ sobran.toLocaleString('es-CL') }} más. Sigue escribiendo para acotar.
            </p>
        </div>
    </div>
</template>
