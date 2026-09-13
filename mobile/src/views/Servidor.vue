<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';

const router = useRouter();
const direccion = ref('');
const probando = ref(false);
const error = ref('');
const ok = ref('');

onMounted(async () => {
    direccion.value = await db.getServidor();
});

async function probar() {
    error.value = '';
    ok.value = '';
    let d = direccion.value.trim().replace(/\/+$/, '');
    if (!d) {
        error.value = 'Escribe la dirección del servidor.';
        return;
    }
    // Sin esquema explícito se asume http: en la red interna es lo habitual.
    if (!/^https?:\/\//i.test(d)) d = 'http://' + d;

    probando.value = true;
    try {
        await db.setServidor(d);
        const r = await api.ping();
        if (!r.configurado) {
            error.value = 'El servidor responde, pero todavía no está instalado. Abre /setup en el navegador.';
            return;
        }
        direccion.value = d;
        ok.value = `Conectado a ${r.app} (base ${r.base}).`;
        setTimeout(() => router.push('/login'), 700);
    } catch (e) {
        error.value = e.message;
        // Error clásico: escribir solo el host y el puerto, sin la carpeta donde
        // está instalada la app. Se detecta y se dice, en vez de dejarlo adivinando.
        if (!new URL(d).pathname.replace(/\/+$/, '')) {
            error.value += ' Puede que falte la carpeta al final, por ejemplo /venta-softland.';
        }
    } finally {
        probando.value = false;
    }
}
</script>

<template>
    <div class="pantalla">
        <div class="barra"><h1>Servidor</h1></div>
        <div class="contenido">
            <div class="aviso info">
                Escribe la dirección donde está instalado el servidor de la empresa.
                Te la da el administrador; es la misma que se usó para instalarlo.
            </div>

            <div class="aviso error" v-if="error">{{ error }}</div>
            <div class="aviso ok" v-if="ok">{{ ok }}</div>

            <div class="tarjeta">
                <div class="tarjeta-cuerpo">
                    <label for="dir">Dirección</label>
                    <input id="dir" v-model="direccion" type="url" inputmode="url"
                           autocapitalize="off" autocorrect="off" spellcheck="false"
                           placeholder="http://172.30.205.106:8086/venta-softland"
                           @keyup.enter="probar">
                    <p class="ayuda">
                        Incluye el puerto si no es el 80. Si la dirección va sin
                        <code>http://</code>, se asume http.
                    </p>

                    <button class="boton" :disabled="probando" @click="probar">
                        {{ probando ? 'Probando…' : 'Probar y continuar' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
