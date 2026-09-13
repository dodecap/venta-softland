import { ref } from 'vue';
import { api } from './api';
import { db } from './db';

/**
 * Buzón de avisos, compartido entre la barra inferior (que muestra la cuenta)
 * y la pantalla (que muestra la lista). Si cada una pidiera lo suyo, el número
 * del punto rojo y lo que se ve al entrar no coincidirían.
 *
 * Lo leído es del aparato: el teléfono guarda hasta qué id llegó. Sin señal la
 * cuenta sigue siendo correcta, y abrir la pestaña no escribe en la base.
 */
export const avisos = ref([]);
export const familias = ref({});
export const noLeidos = ref(0);
export const cargando = ref(false);

let visto = 0;

function recontar() {
    noLeidos.value = avisos.value.filter((a) => a.id > visto).length;
}

/** Trae el buzón. Sin red no rompe nada: se queda con lo último que tenía. */
export async function refrescarAvisos() {
    cargando.value = true;
    try {
        visto = await db.getAvisoVisto();
        const r = await api.avisos();
        avisos.value = r.avisos;
        familias.value = r.familias;
        recontar();
    } catch {
        // El buzón es un extra: que falle no puede tumbar la pantalla que lo pide.
    } finally {
        cargando.value = false;
    }
}

/** Se llama al mirar el buzón: lo que está a la vista deja de estar sin leer. */
export async function marcarVistos() {
    const tope = avisos.value.reduce((m, a) => Math.max(m, a.id), 0);
    if (tope <= visto) return;
    visto = tope;
    await db.setAvisoVisto(tope);
    recontar();
}

/** Al cerrar sesión el buzón del anterior no puede quedar en pantalla. */
export function olvidarAvisos() {
    avisos.value = [];
    noLeidos.value = 0;
    visto = 0;
}
