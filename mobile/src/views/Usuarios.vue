<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api';
import { useCapa } from '../nav';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';

const router = useRouter();
const usuarios = ref([]);
const roles = ref([]);
const opciones = ref({ vendedores: [], usuarios_softland: [], bodegas: [], listas_precio: [], centros_costo: [], jefes: [] });
const cargando = ref(true);
const error = ref('');
const aviso = ref('');
const busqueda = ref('');
const guardando = ref(false);

const editando = ref(null); // null = hoja cerrada
const form = ref(vacio());

// Con la hoja abierta, el «atrás» de Android la cierra en vez de salir de
// la pantalla: es lo que hace el resto del teléfono y lo que espera la mano.
useCapa(computed(() => editando.value !== null), () => { editando.value = null; });

// El botón de crear va **en la barra**, no en el flotante. El flotante sólo
// sale en las pestañas —ver `BotonCrear.vue`—, y esta pantalla se apila sobre
// Cuenta: declarar aquí la acción de crear era registrarla para un botón que
// nadie iba a ver, y dejaba la administración sin forma de dar de alta a nadie.

function vacio() {
    return {
        id: null, nombre: '', email: '', password: '', softland_user: '', rut: '',
        ven_cod: '', cod_bode: '', cod_lista: '', cod_cc: '', rol: 'vendedor',
        jefe_id: '', tope_descuento_pct: 0, tope_monto_nv: 0,
        habilitado: false, activo: true,
    };
}

const filtrados = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    if (!q) return usuarios.value;
    return usuarios.value.filter((u) =>
        [u.nombre, u.email, u.softland_user, u.ven_cod, u.rol]
            .filter(Boolean).join(' ').toLowerCase().includes(q));
});

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    error.value = '';
    try {
        const [a, b] = await Promise.all([api.usuarios(), api.usuariosOpciones()]);
        usuarios.value = a.usuarios;
        roles.value = a.roles;
        opciones.value = b;
    } catch (e) {
        error.value = e.message;
    } finally {
        cargando.value = false;
    }
}

function nuevo() {
    form.value = vacio();
    editando.value = 'nuevo';
}

function editar(u) {
    form.value = {
        ...vacio(),
        ...u,
        password: '',                 // nunca se precarga
        jefe_id: u.jefe_id ?? '',
        email: u.email ?? '',
        softland_user: u.softland_user ?? '',
        rut: u.rut ?? '',
        ven_cod: u.ven_cod ?? '',
        cod_bode: u.cod_bode ?? '',
        cod_lista: u.cod_lista ?? '',
        cod_cc: u.cod_cc ?? '',
    };
    editando.value = u.id;
}

/** Al elegir un usuario de Softland se rellenan nombre, RUT y correo desde wisusuarios. */
function desdeSoftland() {
    const u = opciones.value.usuarios_softland.find((x) => x.usuario === form.value.softland_user);
    if (!u) return;
    if (!form.value.nombre) form.value.nombre = u.nombre;
    if (!form.value.rut) form.value.rut = u.rut;
    if (!form.value.email) form.value.email = u.email;
}

/** Al elegir vendedor, si no hay correo se toma el de cwtvend. */
function desdeVendedor() {
    const v = opciones.value.vendedores.find((x) => x.codigo === form.value.ven_cod);
    if (v && !form.value.email) form.value.email = v.email;
    if (v && !form.value.nombre) form.value.nombre = v.nombre;
}

async function guardar() {
    error.value = '';
    aviso.value = '';
    guardando.value = true;
    const payload = {
        ...form.value,
        jefe_id: form.value.jefe_id === '' ? null : Number(form.value.jefe_id),
        tope_descuento_pct: Number(form.value.tope_descuento_pct) || 0,
        tope_monto_nv: Number(form.value.tope_monto_nv) || 0,
    };
    delete payload.id;
    if (!payload.password) delete payload.password;

    try {
        if (editando.value === 'nuevo') {
            await api.crearUsuario(payload);
            aviso.value = 'Usuario creado.';
        } else {
            await api.editarUsuario(editando.value, payload);
            aviso.value = 'Usuario actualizado.';
        }
        editando.value = null;
        await cargar();
    } catch (e) {
        error.value = e.message;
    } finally {
        guardando.value = false;
    }
}

async function desactivar(u) {
    if (!confirm(`¿Desactivar a ${u.nombre}? Se cerrarán sus sesiones. No se borra: los documentos ya emitidos lo siguen nombrando.`)) return;
    try {
        await api.desactivarUsuario(u.id);
        aviso.value = 'Usuario desactivado.';
        await cargar();
    } catch (e) {
        error.value = e.message;
    }
}

async function revocar(u) {
    if (!confirm(`¿Cerrar todas las sesiones de ${u.nombre} en todos sus dispositivos?`)) return;
    try {
        await api.revocarSesiones(u.id);
        aviso.value = 'Sesiones cerradas.';
    } catch (e) {
        error.value = e.message;
    }
}

function color(u) {
    if (!u.activo) return 'rojo';
    if (!u.habilitado) return 'amarillo';
    return u.rol === 'admin' ? 'cian' : 'verde';
}
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>Usuarios</h1>
            <button class="icono-barra" title="Nuevo usuario" @click="nuevo">
                <AppIcon name="crear" :size="22" />
            </button>
        </div>

        <div class="contenido">
            <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
            <Aviso tipo="ok" v-if="aviso">{{ aviso }}</Aviso>

            <div class="campo-buscar">
                <AppIcon name="buscar" :size="18" />
                <input :value="busqueda" @input="busqueda = $event.target.value"
                       type="text" inputmode="search" enterkeyhint="search"
                       placeholder="Buscar por nombre, correo o vendedor" autocapitalize="off">
            </div>

            <div class="cargando" v-if="cargando">Cargando…</div>
            <div class="vacio" v-else-if="!filtrados.length">No hay usuarios que coincidan.</div>

            <div class="item" v-for="u in filtrados" :key="u.id">
                <div class="item-estado" :class="color(u)"></div>
                <div class="item-cuerpo" @click="editar(u)">
                    <div class="item-titulo">{{ u.nombre }}</div>
                    <div class="item-linea">{{ u.email || u.softland_user || 'sin acceso' }}</div>
                    <div class="item-meta">
                        <span class="etiqueta">{{ u.rol }}</span>
                        <span v-if="u.ven_cod" class="etiqueta gris">vend. {{ u.ven_cod }}</span>
                        <span v-if="!u.habilitado" class="etiqueta roja">sin habilitar</span>
                        <span v-if="!u.activo" class="etiqueta roja">inactivo</span>
                        <span v-if="u.jefe_nombre"> · jefe: {{ u.jefe_nombre }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hoja de edición -->
        <div class="velo" v-if="editando !== null" @click.self="editando = null">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>{{ editando === 'nuevo' ? 'Nuevo usuario' : 'Editar usuario' }}</h2>
                    <button class="icono-barra" @click="editando = null"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <label>Nombre</label>
                    <input v-model="form.nombre" type="text">

                    <label>Rol</label>
                    <select v-model="form.rol">
                        <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
                    </select>
                    <p class="ayuda">
                        <b>vendedor</b>: cotiza y vende lo suyo · <b>supervisor</b>: además aprueba a su equipo ·
                        <b>facturacion</b>: emite documentos · <b>admin</b>: todo, más esta pantalla.
                    </p>

                    <label>Usuario de Softland</label>
                    <select v-model="form.softland_user" @change="desdeSoftland">
                        <option value="">— sin licencia Softland —</option>
                        <option v-for="s in opciones.usuarios_softland" :key="s.usuario" :value="s.usuario">
                            {{ s.usuario }} — {{ s.nombre }}
                        </option>
                    </select>
                    <p class="ayuda">
                        Si tiene usuario Softland, entra con esa misma contraseña y no hay
                        una segunda clave que mantener.
                    </p>

                    <label>Correo</label>
                    <input v-model="form.email" type="email" autocapitalize="off" spellcheck="false">
                    <p class="ayuda">Sirve para entrar y es donde llegan las notificaciones.</p>

                    <label>Contraseña propia</label>
                    <input v-model="form.password" type="password" autocomplete="new-password"
                           :placeholder="editando === 'nuevo' ? 'Mínimo 6 caracteres' : 'En blanco = no cambiar'">
                    <p class="ayuda">Solo para quien no tiene usuario Softland.</p>

                    <label>Código de vendedor (Softland)</label>
                    <select v-model="form.ven_cod" @change="desdeVendedor">
                        <option value="">— sin código —</option>
                        <option v-for="v in opciones.vendedores" :key="v.codigo" :value="v.codigo">
                            {{ v.codigo }} — {{ v.nombre }}
                        </option>
                    </select>
                    <p class="ayuda">
                        Es lo que queda estampado en la cotización y en la nota de venta.
                        Sin código puede mirar, pero no vender.
                    </p>

                    <label>RUT</label>
                    <input v-model="form.rut" type="text" inputmode="text" placeholder="12.345.678-5">

                    <div class="fila">
                        <div>
                            <label>Bodega</label>
                            <select v-model="form.cod_bode">
                                <option value="">—</option>
                                <option v-for="b in opciones.bodegas" :key="b.codigo" :value="b.codigo">
                                    {{ b.nombre }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label>Lista de precios</label>
                            <select v-model="form.cod_lista">
                                <option value="">—</option>
                                <option v-for="l in opciones.listas_precio" :key="l.codigo" :value="l.codigo">
                                    {{ l.nombre }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <label>Centro de costo</label>
                    <select v-model="form.cod_cc">
                        <option value="">—</option>
                        <option v-for="c in opciones.centros_costo" :key="c.codigo" :value="c.codigo">
                            {{ c.codigo }} — {{ c.nombre }}
                        </option>
                    </select>
                    <p class="ayuda">
                        En esta empresa Softland exige centro de costo en la nota de venta,
                        así que conviene dejarlo fijado por vendedor.
                    </p>

                    <label>Jefe</label>
                    <select v-model="form.jefe_id">
                        <option value="">— sin jefe —</option>
                        <option v-for="j in opciones.jefes" :key="j.id" :value="j.id">
                            {{ j.nombre }} ({{ j.rol }})
                        </option>
                    </select>
                    <p class="ayuda">Quien aprueba sus notas de venta y recibe sus avisos.</p>

                    <div class="fila">
                        <div>
                            <label>Tope descuento %</label>
                            <input v-model="form.tope_descuento_pct" type="number" inputmode="decimal" min="0" max="100">
                        </div>
                        <div>
                            <label>Tope monto NV</label>
                            <input v-model="form.tope_monto_nv" type="number" inputmode="numeric" min="0">
                        </div>
                    </div>
                    <p class="ayuda">Pasado el tope, la nota de venta se va a aprobación del jefe. 0 = sin tope.</p>

                    <div style="margin-top:16px;">
                        <div class="interruptor">
                            <span>Habilitado para entrar</span>
                            <input type="checkbox" v-model="form.habilitado">
                        </div>
                        <div class="interruptor">
                            <span>Activo</span>
                            <input type="checkbox" v-model="form.activo">
                        </div>
                    </div>

                    <button class="boton" :disabled="guardando" @click="guardar">
                        {{ guardando ? 'Guardando…' : 'Guardar' }}
                    </button>

                    <template v-if="editando !== 'nuevo'">
                        <button class="boton secundario" @click="revocar(form)">Cerrar sus sesiones</button>
                        <button class="boton peligro" @click="desactivar(form)">Desactivar usuario</button>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
