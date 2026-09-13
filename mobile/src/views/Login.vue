<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api, ErrorApi } from '../api';
import { db } from '../db';
import Aviso from '../components/Aviso.vue';

const router = useRouter();
const usuario = ref('');
const password = ref('');
const entrando = ref(false);
const error = ref('');
const servidor = ref('');

onMounted(async () => {
    servidor.value = await db.getServidor();
    if (!servidor.value) router.replace('/servidor');
});

async function entrar() {
    error.value = '';
    if (!usuario.value.trim() || !password.value) {
        error.value = 'Escribe tu usuario y tu contraseña.';
        return;
    }

    entrando.value = true;
    try {
        const r = await api.login(usuario.value.trim(), password.value, navigator.userAgent.slice(0, 110));
        await db.setToken(r.token);
        await db.setUsuario(r.usuario);

        // Traer los maestros de inmediato: es lo que permite trabajar después sin señal.
        try {
            const b = await api.bootstrap();
            await db.setCatalogos(b.catalogos);
            await db.setUsuario(b.usuario);
            await db.setSincronizado(b.sincronizado_at);
        } catch { /* sin maestros se puede entrar igual; se reintenta desde Inicio */ }

        router.replace('/inicio');
    } catch (e) {
        error.value = e.message;
        if (e instanceof ErrorApi && e.status === 0) {
            error.value += ' Si cambiaste de red, revisa la dirección del servidor.';
        }
    } finally {
        entrando.value = false;
        password.value = '';
    }
}
</script>

<template>
    <div class="pantalla">
        <div class="barra"><h1>Venta Softland</h1></div>
        <div class="contenido">
            <div style="text-align:center;padding:26px 0 18px;">
                <div style="font-size:26px;font-weight:800;color:var(--indigo);letter-spacing:-.5px;">
                    Venta Softland
                </div>
                <div style="font-size:13px;color:var(--texto-suave);margin-top:4px;">
                    Cotiza, vende y factura desde el teléfono
                </div>
            </div>

            <Aviso tipo="error" v-if="error">{{ error }}</Aviso>

            <div class="tarjeta">
                <div class="tarjeta-cuerpo">
                    <label for="usr">Usuario o correo</label>
                    <input id="usr" v-model="usuario" type="text" autocapitalize="off"
                           autocorrect="off" spellcheck="false" autocomplete="username">

                    <label for="pwd">Contraseña</label>
                    <input id="pwd" v-model="password" type="password"
                           autocomplete="current-password" @keyup.enter="entrar">

                    <button class="boton" :disabled="entrando" @click="entrar">
                        {{ entrando ? 'Entrando…' : 'Entrar' }}
                    </button>
                </div>
            </div>

            <p class="ayuda" style="text-align:center;">
                Servidor: {{ servidor || '—' }}
                <br>
                <a href="#/servidor" style="color:var(--cian-oscuro);">Cambiar servidor</a>
            </p>
        </div>
    </div>
</template>
