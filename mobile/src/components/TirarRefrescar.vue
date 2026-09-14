<script setup>
import { computed } from 'vue';
import AppIcon from './AppIcon.vue';

/*
 * El indicador del tirón hacia abajo. Va como primer hijo del contenedor que
 * se desplaza; mientras no se tira de la pantalla no ocupa nada.
 *
 * El icono gira con el dedo. No es adorno: es la única señal continua de que
 * el gesto se está registrando, porque hasta que no se suelta no pasa nada.
 */
const props = defineProps({
    distancia: { type: Number, default: 0 },
    refrescando: { type: Boolean, default: false },
    listo: { type: Boolean, default: false },
    // Qué se está actualizando, en dos palabras. Sale sólo cuando ya se soltó:
    // antes el vendedor está mirando el icono, no leyendo.
    que: { type: String, default: 'la lista' },
});

const alto = computed(() => (props.refrescando ? 44 : Math.round(props.distancia)));
const giro = computed(() => `rotate(${Math.round(props.distancia * 3)}deg)`);
</script>

<template>
    <div class="tirador" :class="{ trabajando: refrescando }" :style="{ height: `${alto}px` }">
        <div class="senal" v-show="alto > 0">
            <AppIcon name="sincronizar" :size="18" color="currentColor"
                     :class="{ girando: refrescando }"
                     :style="refrescando ? null : { transform: giro }" />
            <span v-if="refrescando">Actualizando {{ que }}…</span>
            <span v-else-if="listo">Suelta para actualizar</span>
            <span v-else>Tira para actualizar</span>
        </div>
    </div>
</template>
