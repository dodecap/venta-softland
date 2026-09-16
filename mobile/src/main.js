import { createApp } from 'vue';
import { createRouter, createWebHashHistory } from 'vue-router';
import App from './App.vue';
import { db } from './db';
import { cargarDensidad } from './densidad';
import { cargarCatalogos } from './catalogos';
import './style.css';

import Servidor from './views/Servidor.vue';
import Login from './views/Login.vue';
import Inicio from './views/Inicio.vue';
import Avisos from './views/Avisos.vue';
import Cuenta from './views/Cuenta.vue';
import Clientes from './views/Clientes.vue';
import Cliente from './views/Cliente.vue';
import Productos from './views/Productos.vue';
import Documentos from './views/Documentos.vue';
import Documento from './views/Documento.vue';
import Editor from './views/Editor.vue';
import Facturar from './views/Facturar.vue';
import FacturaNueva from './views/FacturaNueva.vue';
import Aprobaciones from './views/Aprobaciones.vue';
import Usuarios from './views/Usuarios.vue';
import Configuracion from './views/Configuracion.vue';
import Identidad from './views/Identidad.vue';
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
        { path: '/clientes', component: Clientes, meta: { tab: true } },
        { path: '/avisos', component: Avisos, meta: { tab: true } },
        { path: '/cuenta', component: Cuenta, meta: { tab: true } },

        // Consulta del catálogo y de los documentos, todo desde IndexedDB.
        // `/clientes/nuevo` y `/clientes/77234300` son la misma pantalla: la
        // ficha sabe si está dando de alta o mostrando por el código que le toca.
        { path: '/clientes/:codigo', component: Cliente },
        { path: '/productos', component: Productos },
        { path: '/cotizaciones', component: Documentos, meta: { tipo: 'cotizacion' } },
        // `nuevo` va antes que `:numero` porque el router toma la primera que
        // calce, y `:numero` calzaría también con la palabra «nuevo».
        { path: '/cotizaciones/nuevo', component: Editor, meta: { tipo: 'cotizacion' } },
        { path: '/cotizaciones/:numero', component: Documento, meta: { tipo: 'cotizacion' } },
        { path: '/cotizaciones/:numero/editar', component: Editor, meta: { tipo: 'cotizacion' } },
        { path: '/notas-venta', component: Documentos, meta: { tipo: 'nota_venta' } },
        { path: '/notas-venta/nuevo', component: Editor, meta: { tipo: 'nota_venta' } },
        { path: '/notas-venta/:numero', component: Documento, meta: { tipo: 'nota_venta' } },
        { path: '/notas-venta/:numero/editar', component: Editor, meta: { tipo: 'nota_venta' } },
        { path: '/notas-venta/:numero/facturar', component: Facturar },
        { path: '/facturas/nueva', component: FacturaNueva },
        // Lo que el jefe tiene que resolver. No es una pestaña: se entra desde
        // el panel y desde el aviso que llega por correo.
        { path: '/aprobaciones', component: Aprobaciones },

        { path: '/usuarios', component: Usuarios, meta: { admin: true } },
        { path: '/configuracion', component: Configuracion, meta: { admin: true } },
        { path: '/identidad', component: Identidad, meta: { admin: true } },
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
//
// Los maestros chicos también: son los que traducen «01» a «Peso Chileno» y
// «SOF-501» a su centro de costo. Cargarlos después haría que la primera
// pantalla mostrara códigos y se corrigiera sola un instante más tarde.
Promise.all([cargarDensidad(), cargarCatalogos().catch(() => {})]).finally(() => {
    createApp(App).use(router).mount('#app');
});
