import { createApp } from 'vue';
import { createRouter, createWebHashHistory } from 'vue-router';
import App from './App.vue';
import { db } from './db';
import { cargarDensidad } from './densidad';
import './style.css';

import Servidor from './views/Servidor.vue';
import Login from './views/Login.vue';
import Inicio from './views/Inicio.vue';
import Avisos from './views/Avisos.vue';
import Cuenta from './views/Cuenta.vue';
import Usuarios from './views/Usuarios.vue';
import Configuracion from './views/Configuracion.vue';
import Reglas from './views/Reglas.vue';
import Bitacora from './views/Bitacora.vue';

/*
 * Dos niveles y no más:
 *
 *   pestañas   /inicio, /avisos, /cuenta   son hermanas, se saltan de lado y
 *                                          llevan la barra inferior
 *   adentro    el resto                    se apilan sobre una pestaña y se
 *                                          salen con «atrás»
 *
 * `meta.tab` marca las de arriba. De ahí salen tanto la barra inferior como
 * las raíces del botón «atrás» de Android.
 */
const router = createRouter({
    // Hash: dentro del APK no hay servidor que resuelva rutas profundas.
    history: createWebHashHistory(),
    routes: [
        { path: '/', redirect: '/inicio' },
        { path: '/servidor', component: Servidor, meta: { publica: true } },
        { path: '/login', component: Login, meta: { publica: true } },

        { path: '/inicio', component: Inicio, meta: { tab: true } },
        { path: '/avisos', component: Avisos, meta: { tab: true } },
        { path: '/cuenta', component: Cuenta, meta: { tab: true } },

        { path: '/usuarios', component: Usuarios, meta: { admin: true } },
        { path: '/configuracion', component: Configuracion, meta: { admin: true } },
        { path: '/reglas', component: Reglas, meta: { admin: true } },
        { path: '/bitacora', component: Bitacora, meta: { admin: true } },
        // La pantalla se llamaba «notificaciones» cuando era la única que
        // hablaba de avisos. Hoy ese nombre es el del buzón del vendedor, y
        // esto son las reglas. Se deja el atajo para no romper nada guardado.
        { path: '/notificaciones', redirect: '/reglas' },
    ],
});

router.beforeEach(async (to) => {
    if (to.meta.publica) return true;

    if (!(await db.getServidor())) return '/servidor';
    if (!(await db.getToken())) return '/login';

    if (to.meta.admin) {
        const u = await db.getUsuario();
        if (!u?.es_admin) return '/inicio';
    }
    return true;
});

// La escala se aplica antes de montar: si se leyera después, la primera
// pantalla se dibujaría en tamaño normal y saltaría al tamaño elegido.
cargarDensidad().finally(() => {
    createApp(App).use(router).mount('#app');
});
