<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { db } from '../db';
import { noLeidos, refrescarAvisosSiToca } from '../avisos';
import AppIcon from './AppIcon.vue';

/**
 * La cabecera de las pestañas: quién soy, qué me llegó y qué hago aquí.
 *
 * ## Por qué lo personal se fue arriba
 *
 * Avisos y Cuenta eran dos de los cuatro botones de la barra de abajo, y esa
 * barra es para **dónde estoy**, no para quién soy. Ocupaban la mitad del sitio
 * más valioso de la pantalla con dos cosas que se visitan una vez al día.
 *
 * Arriba pasan a ser lo que son en cualquier app: las iniciales llevan a lo
 * mío, la campana a lo que me llegó. Y como la cabecera la comparten todas las
 * pestañas, están siempre en el mismo píxel — que es lo que hace que no haya
 * que buscarlas.
 *
 * ## El globo de la campana
 *
 * Va sobre el icono y no al lado: al lado empuja el resto de la fila cada vez
 * que llega un aviso, y una cabecera que se mueve sola se siente rota.
 *
 * Y lo cuenta esta cabecera, que es quien lo dibuja. El buzón se pedía sólo
 * desde el panel, así que quien entraba por Clientes o por Cuenta veía la
 * cuenta de cuando arrancó la app — o ninguna. El plazo de `avisos.js` evita
 * que cambiar de pestaña sea una petición.
 */

defineProps({
    /** El título de la pestaña. En el panel se reemplaza por el saludo. */
    titulo: { type: String, default: '' },
});

const router = useRouter();
const usuario = ref(null);

onMounted(async () => {
    usuario.value = await db.getUsuario();
    refrescarAvisosSiToca().catch(() => { /* el buzón es un extra */ });
});

/**
 * Las iniciales del vendedor.
 *
 * Dos letras: la del nombre y la del apellido. Con una sola no se distingue a
 * dos personas del mismo equipo, y con tres no cabe en la placa.
 */
const iniciales = computed(() => (usuario.value?.nombre || '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0].toUpperCase())
    .join('') || '·');
</script>

<template>
    <div class="encabezado">
        <button class="marca" @click="router.push('/cuenta')"
                :title="usuario?.nombre || 'Mi cuenta'" aria-label="Mi cuenta">
            {{ iniciales }}
        </button>

        <!-- El panel pone aquí su saludo; el resto, su título. -->
        <div class="saludo">
            <slot>
                <div class="hola">{{ titulo }}</div>
            </slot>
        </div>

        <button class="icono-barra campana" @click="router.push('/avisos')"
                :title="noLeidos ? `Avisos (${noLeidos} sin leer)` : 'Avisos'"
                :aria-label="noLeidos ? `Avisos, ${noLeidos} sin leer` : 'Avisos'">
            <AppIcon name="notificacion" :size="20" />
            <span class="punto" v-if="noLeidos">{{ noLeidos > 9 ? '9+' : noLeidos }}</span>
        </button>

        <slot name="acciones" />
    </div>
</template>
