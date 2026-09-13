import { createApp } from 'vue';
import { createRouter, createWebHashHistory } from 'vue-router';
import App from './App.vue';
import { db } from './db';
import './style.css';

import Servidor from './views/Servidor.vue';
import Login from './views/Login.vue';
import Inicio from './views/Inicio.vue';
import Usuarios from './views/Usuarios.vue';
import Configuracion from './views/Configuracion.vue';
import Notificaciones from './views/Notificaciones.vue';
import Bitacora from './views/Bitacora.vue';

const router = createRouter({
    // Hash: dentro del APK no hay servidor que resuelva rutas profundas.
    history: createWebHashHistory(),
    routes: [
        { path: '/', redirect: '/inicio' },
        { path: '/servidor', component: Servidor, meta: { publica: true } },
        { path: '/login', component: Login, meta: { publica: true } },
        { path: '/inicio', component: Inicio },
        { path: '/usuarios', component: Usuarios, meta: { admin: true } },
        { path: '/configuracion', component: Configuracion, meta: { admin: true } },
        { path: '/notificaciones', component: Notificaciones, meta: { admin: true } },
        { path: '/bitacora', component: Bitacora, meta: { admin: true } },
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

createApp(App).use(router).mount('#app');
