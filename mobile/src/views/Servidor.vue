<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { db } from '../db';
import Aviso from '../components/Aviso.vue';

const router = useRouter();
const direccion = ref('');
const probando = ref(false);
const error = ref('');
const ok = ref('');

onMounted(async () => {
    direccion.value = await db.getServidor();
});

/*
 * ## Por qué esta pantalla no se cree lo que le escriben
 *
 * Hay dos formas de llegar al servidor y son distintas:
 *
 *  - la instalación de la oficina, `http://192.168.1.55:8086/venta-softland`;
 *  - un nombre público detrás de un proxy, `https://venta.netdomain.cl`, que
 *    reenvía a la anterior.
 *
 * Suponer `http://` cuando no se escribe el esquema —que es lo que hacía esta
 * pantalla— rompe el segundo caso de una forma especialmente fea: el proxy
 * responde a `http` con un **301 a https**, y como `Accept` es una cabecera que
 * no exige comprobación previa, la prueba de conexión **pasa** siguiendo la
 * redirección. Todo lo demás no: un `POST` con cuerpo JSON pide primero
 * permiso con un `OPTIONS`, y una redirección en esa respuesta es un error de
 * CORS, no algo que el navegador siga. Resultado: «Probar y continuar» dice
 * que sí y el login dice que no se pudo llegar al servidor.
 *
 * Así que se prueban los dos esquemas y **se guarda la dirección que de verdad
 * contestó**, que es `res.url` — ya con la redirección resuelta. El vendedor
 * escribe lo que le dieron y la app se arregla sola.
 */
const ESPERA_MS = 8000;

/** En qué orden probar, cuando la dirección viene sin `http://` ni `https://`. */
function candidatas(d) {
    if (/^https?:\/\//i.test(d)) return [d];

    const host = d.split('/')[0];
    const interna = /:\d+$/.test(host) || /^\d{1,3}(\.\d{1,3}){3}$/.test(host);

    // Una IP, o cualquier cosa con puerto, es la instalación de la oficina y va
    // por http. Un nombre de dominio pelado es casi siempre un servidor público
    // con certificado. Se prueban las dos igual: el orden sólo decide cuál
    // contesta primero y ahorra una espera.
    return interna
        ? ['http://' + d, 'https://' + d]
        : ['https://' + d, 'http://' + d];
}

/** De `https://host/api/ping` saca `https://host`. */
function baseDe(url) {
    return url.replace(/\/api\/ping(\?.*)?$/, '').replace(/\/+$/, '');
}

/**
 * La dirección que responde de verdad, o `null`.
 *
 * No usa `api.ping()` a propósito: hace falta la `Response` entera para leer
 * `res.url`, y eso el envoltorio no lo devuelve.
 */
async function resolver(escrita) {
    for (const base of candidatas(escrita)) {
        try {
            const res = await fetch(base + '/api/ping', {
                headers: { Accept: 'application/json' },
                signal: AbortSignal.timeout(ESPERA_MS),
            });

            if (! res.ok) continue;

            const datos = await res.json();
            if (! datos?.ok) continue;

            return { base: baseDe(res.url || (base + '/api/ping')), datos };
        } catch { /* la siguiente */ }
    }

    return null;
}

async function probar() {
    error.value = '';
    ok.value = '';

    const escrita = direccion.value.trim().replace(/\/+$/, '');
    if (! escrita) {
        error.value = 'Escribe la dirección del servidor.';
        return;
    }

    probando.value = true;
    try {
        const r = await resolver(escrita);

        if (! r) {
            error.value = noLlego(escrita);
            return;
        }

        if (! r.datos.configurado) {
            // Llega, pero nadie corrió el instalador. Se da la URL completa: el
            // vendedor no tiene por qué saber armarla, y el administrador la abre y listo.
            error.value = 'El servidor responde, pero todavía no está instalado. '
                + `El administrador debe abrir ${r.base}/setup en un navegador.`;
            return;
        }

        await db.setServidor(r.base);
        direccion.value = r.base;
        ok.value = `Conectado a ${r.datos.app} (base ${r.datos.base}).`;
        setTimeout(() => router.push('/login'), 700);
    } finally {
        probando.value = false;
    }
}

/**
 * Qué decirle a quien no llegó. Los dos errores clásicos son simétricos y por
 * eso se mira la carpeta: sobra en una dirección pública y falta en una
 * interna.
 */
function noLlego(escrita) {
    const carpeta = escrita.replace(/^https?:\/\//i, '').includes('/');

    return 'No se pudo llegar al servidor con esa dirección. '
        + (carpeta
            ? 'Si te dieron una dirección de internet, prueba sin la carpeta del final.'
            : 'Puede que falte la carpeta al final, por ejemplo /venta-softland.');
}
</script>

<template>
    <div class="pantalla">
        <div class="barra"><h1>Servidor</h1></div>
        <div class="contenido">
            <Aviso tipo="info">
                Escribe la dirección donde está instalado el servidor de la empresa.
                Te la da el administrador; es la misma que se usó para instalarlo.
            </Aviso>

            <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
            <Aviso tipo="ok" v-if="ok">{{ ok }}</Aviso>

            <div class="tarjeta">
                <div class="tarjeta-cuerpo">
                    <label for="dir">Dirección</label>
                    <input id="dir" v-model="direccion" type="url" inputmode="url"
                           autocapitalize="off" autocorrect="off" spellcheck="false"
                           placeholder="venta.netdomain.cl"
                           @keyup.enter="probar">
                    <p class="ayuda">
                        Escríbela tal como te la dieron. Si es la de la oficina,
                        lleva puerto y carpeta
                        (<code>192.168.1.55:8086/venta-softland</code>); si es la de
                        internet, va sola (<code>venta.netdomain.cl</code>). El
                        <code>http://</code> o <code>https://</code> lo resuelve la app.
                    </p>

                    <button class="boton" :disabled="probando" @click="probar">
                        {{ probando ? 'Probando…' : 'Probar y continuar' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
