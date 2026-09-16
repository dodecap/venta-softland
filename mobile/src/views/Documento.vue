<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';
import { db } from '../db';
import { idb } from '../idb';
import { monto, fecha, nombre as nombreDe, simbolo } from '../catalogos';
import { TIPOS, estado, enriquecerLineas, lineasDe, avanceFacturacion } from '../documentos';
import { facturadoDe, facturasDe, saldoCotizacion } from '../saldo';
import { conectado } from '../red';
import { compartirPdf, olvidarPdf, pdfGuardado, verPdf } from '../pdf';
import { useCapa } from '../nav';
import AppIcon from '../components/AppIcon.vue';
import Aviso from '../components/Aviso.vue';
import Persiana from '../components/Persiana.vue';
import Selector from '../components/Selector.vue';
import Vacio from '../components/Vacio.vue';

/*
 * Un documento con su detalle. Todo de IndexedDB: en terreno esta pantalla es
 * la que se le muestra al cliente, y ahí no se puede depender de la señal.
 */

const route = useRoute();
const router = useRouter();

const tipo = computed(() => route.meta.tipo);
const def = computed(() => TIPOS[tipo.value]);
const numero = computed(() => Number(route.params.numero));

const doc = ref(null);
const lineas = ref([]);
const cliente = ref(null);
const cargando = ref(true);
const seguimientos = ref([]);
const aprobacion = ref(null);
const usuario = ref(null);

/*
 * Qué queda por convertir. Se calcula en el teléfono, de IndexedDB, para que la
 * ficha lo diga también sin señal: es lo que se mira en terreno.
 */
const saldo = ref(null);

/*
 * Las facturas de esta nota de venta. Se enseñan aquí y no en una lista aparte
 * porque una factura es el desenlace de una venta, no un documento suelto: es
 * en la nota de venta donde alguien se pregunta qué se facturó y qué falta.
 */
const facturas = ref([]);
const anulando = ref(null);
const razonNc = ref('Anula Documento');

const error = ref('');
const aviso = ref('');
const trabajando = ref(false);

/*
 * Este documento no está en el teléfono: se trajo del servidor.
 *
 * Pasa con los más viejos que la ventana de doce meses — la cotización 8000 es
 * de marzo de 2024 — a los que se llega escribiendo su número en el buscador
 * de la lista. Se ven igual que los demás y se dicen distintos, porque lo son:
 * hacen falta señal y no están guardados, así que fuera de cobertura esta
 * pantalla se queda vacía.
 *
 * Y **no se guardan**. Meterlos en IndexedDB parecería un favor y sería un
 * problema: el panel cuenta lo que hay en el almacén, y una cotización
 * pendiente de 2024 entraría a la cuenta de las vencidas y al monto del
 * embudo.
 */
const delServidor = ref(false);

// Si el PDF ya está en el teléfono, verlo y mandarlo funcionan sin señal.
const papelGuardado = ref(null);

// Las hojas de cerrar por pérdida, anotar un seguimiento y eliminar.
const perdiendo = ref(false);
const siguiendo = ref(false);
const borrando = ref(false);
const formPerdida = ref({ motivo: '', observacion: '' });
const formSeguimiento = ref({ descripcion: '', proximo_contacto: '' });

// Por qué el servidor no dejó eliminar. Sólo él lo sabe: en el teléfono no
// está si el documento se facturó, si generó picking o si ya salió al cliente.
const impedimentos = ref([]);

useCapa(computed(() => perdiendo.value || siguiendo.value || borrando.value), () => {
    perdiendo.value = false;
    siguiendo.value = false;
    borrando.value = false;
});

onMounted(cargar);
onMounted(async () => { usuario.value = await db.getUsuario(); });
watch(numero, cargar);

async function cargar() {
    cargando.value = true;
    error.value = '';
    delServidor.value = false;
    try {
        doc.value = await idb.obtener(def.value.almacen, numero.value);
        lineas.value = doc.value ? await lineasDe(tipo.value, numero.value) : [];

        // Lo que no está en el teléfono se pide al servidor. Es la única
        // pantalla que lo hace, y sólo cuando no hay nada que mostrar: con el
        // documento bajado manda lo bajado, que es lo que funciona sin señal.
        if (! doc.value && conectado.value) await traerDelServidor();

        saldo.value = doc.value && esCotizacion.value ? await saldoCotizacion(numero.value) : null;

        // El avance de la nota de venta se calcula desde las facturas, no desde
        // `nvCantFact`: esa columna está en cero en las 3.824 líneas de cada
        // empresa, así que leerla daba «facturado 0 de 12» para siempre.
        if (doc.value && ! esCotizacion.value) {
            const facturado = await facturadoDe(numero.value);
            lineas.value = lineas.value.map((l) => ({
                ...l,
                facturado: facturado.get(Number(l.linea).toFixed(2)) || 0,
            }));
            facturas.value = await facturasDe(numero.value);
        }
        cliente.value = doc.value ? await idb.obtener('clientes', doc.value.cliente) : null;
        papelGuardado.value = doc.value ? await pdfGuardado(tipo.value, numero.value) : null;
        if (! delServidor.value) await refrescarDelServidor();
    } finally {
        cargando.value = false;
    }
}

/**
 * El documento entero desde la API: cabecera, detalle y lo suyo.
 *
 * Un 404 aquí no es un fallo que haya que gritar: quiere decir que ese número
 * no existe o no es de este vendedor, y la pantalla ya sabe decir «no está».
 */
async function traerDelServidor() {
    try {
        const r = esCotizacion.value
            ? await api.cotizacion(numero.value)
            : await api.notaVenta(numero.value);

        doc.value = esCotizacion.value ? r.cotizacion : r.nota_venta;
        lineas.value = await enriquecerLineas(r.lineas ?? []);
        seguimientos.value = r.seguimientos ?? [];
        aprobacion.value = r.aprobacion ?? null;
        delServidor.value = true;
    } catch { /* no está, o no es suyo: la pantalla lo dice sola */ }
}

/**
 * Lo que solo está en el servidor: los seguimientos de una cotización y el
 * estado de la aprobación de una nota de venta. No se descargan al teléfono
 * porque no se consultan en terreno, se consultan cuando se está decidiendo.
 */
async function refrescarDelServidor() {
    if (! conectado.value || ! doc.value) return;
    try {
        if (esCotizacion.value) {
            seguimientos.value = (await api.cotizacion(numero.value)).seguimientos ?? [];
        } else {
            aprobacion.value = (await api.notaVenta(numero.value)).aprobacion ?? null;
        }
    } catch { /* sin conexión al servidor se muestra lo que hay en el teléfono */ }
}

const esCotizacion = computed(() => tipo.value === 'cotizacion');

/** Comuna y ciudad juntas, como en la ficha de cliente. */
const ubicacionCliente = computed(() => [
    nombreDe('comunas', cliente.value?.comuna),
    nombreDe('ciudades', cliente.value?.ciudad),
].filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join(' · '));

/**
 * En qué estados el documento todavía admite cambios.
 *
 * La cotización, sólo pendiente. Ojo: `N` **no** entra, porque en Softland es
 * «nula».
 *
 * La nota de venta tiene una vuelta más. Donde el ERP no exige aprobación
 * —`nwparam.CheckApruebaNv = N`, que es el caso de INNOVAGES— la NV **nace en
 * `A`**, igual que las que escribe el Softland de escritorio. Si `A` cerrara
 * el documento, el vendedor no podría corregir la que acaba de grabar. Lo que
 * lo cierra es que alguien la haya aprobado, y eso se lee en `fecha_aprobacion`
 * (`nvFeAprob`).
 *
 * Aquí sólo se sabe lo que se ve. Si además se facturó o tiene picking, lo
 * sabe el servidor y responde 409; es el mismo trato que `borrable`.
 */
const editable = computed(() => {
    const e = (doc.value?.estado || '').trim().toUpperCase();

    if (esCotizacion.value) return ['P', ''].includes(e);

    return ['P', ''].includes(e) || (e === 'A' && ! doc.value?.fecha_aprobacion);
});

/*
 * Convertir ya no exige que la cotización esté pendiente.
 *
 * `V` quiere decir «tiene nota de venta», y desde el reparto parcial eso convive
 * con que quede algo por convertir: una cotización de ocho líneas puede haberse
 * llevado siete a una nota de venta y tener la octava esperando. Lo que cierra
 * la puerta es que no quede saldo — la misma regla que aplica el servidor.
 */
const puedeConvertir = computed(
    () => esCotizacion.value && (editable.value || parcial.value)
);

/**
 * Facturar: sólo una nota de venta viva y aprobada, y sólo si queda algo.
 *
 * Una en `P` espera el visto bueno del jefe y facturarla se lo saltaría; una
 * anulada no existe. Lo que queda por facturar lo dice el avance, que se
 * calcula desde las facturas y no desde la columna muerta del ERP.
 */
const puedeFacturar = computed(() => {
    if (esCotizacion.value) return false;

    const e = (doc.value?.estado || '').trim().toUpperCase();

    return ['A', 'C'].includes(e) && !! avance.value && ! avance.value.completo;
});

/** Convertida a medias: tiene nota de venta y todavía le queda algo. */
const parcial = computed(() => !! saldo.value?.parcial);

/**
 * Las líneas que aún no se han convertido, con lo que les queda.
 *
 * El saldo se calcula sobre las líneas crudas del almacén; el nombre del
 * producto lo traen las ya enriquecidas, que es lo que se le enseña a alguien.
 */
const pendientes = computed(() => {
    const conNombre = new Map(lineas.value.map((l) => [l.linea, l]));

    return (saldo.value?.lineas || [])
        .filter((l) => l.saldo > 0.0001)
        .map((l) => ({ ...l, nombre: conNombre.get(l.linea)?.nombre || l.producto }));
});

/**
 * El switch de aprobar sólo lo ve quien puede soltarla, nunca el vendedor que
 * la escribió. Si lo viera cualquiera, el tope por vendedor que pone la app
 * dejaría de servir de nada: cualquiera se soltaría el freno solo.
 *
 * Dos caminos, porque una nota de venta en `P` puede llegar ahí de dos formas
 * distintas:
 *
 * - **Con solicitud**: pasó el tope, `ventas.aprobacion` tiene una fila con
 *   `jefe_id` ya asignado por la app. Sólo ese jefe —o un admin— puede
 *   resolverla.
 * - **Sin solicitud**: quedó en `P` desde Softland de escritorio o de antes de
 *   que existiera esta app —16 notas de 2020 en INNOVAGES, ninguna con fila en
 *   `ventas.aprobacion`—. No hay `jefe_id` que la reparta sola, así que decide
 *   el organigrama: un admin, o el supervisor de ese vendedor puntual
 *   (`subordinados_ven_cod`, que llega en el usuario y no incluye al
 *   vendedor mismo).
 *
 * Es la misma regla que ya exige `resolver()` en el servidor; esto sólo evita
 * mostrar un botón que el servidor de todos modos va a rechazar con 403.
 */
const puedeAprobar = computed(() => {
    if (esCotizacion.value || ! usuario.value) return false;
    if ((doc.value?.estado || '').trim().toUpperCase() !== 'P') return false;
    if (usuario.value.es_admin) return true;

    if (aprobacion.value?.estado === 'pendiente') {
        return usuario.value.id === aprobacion.value.jefe_id;
    }

    return usuario.value.rol === 'supervisor'
        && (usuario.value.subordinados_ven_cod || []).includes(doc.value?.vendedor);
});

/**
 * Anular deja el documento donde está, con su número, fuera de juego. Sólo
 * desde pendiente: lo que ya se convirtió, se perdió, se aprobó o se concluyó
 * tuvo un desenlace, y anularlo lo borraría de la historia en vez de cerrarlo.
 */
const anulable = computed(() => editable.value);

/**
 * Eliminar borra la fila de Softland. Aquí sólo se sabe lo que se ve — que el
 * documento esté pendiente o ya anulado — y con eso basta para no ofrecer el
 * botón donde seguro no va a funcionar. El resto de las condiciones las
 * contesta el servidor, que es el único que sabe si esto se facturó, si generó
 * picking o si el PDF ya salió al cliente.
 */
const borrable = computed(() => ['P', 'N', ''].includes((doc.value?.estado || '').trim())
    || (! esCotizacion.value && editable.value));

async function perder() {
    if (! formPerdida.value.motivo) return;
    await conServidor(async () => {
        const r = await api.perderCotizacion(numero.value, formPerdida.value);
        await idb.guardar(def.value.almacen, [JSON.parse(JSON.stringify(r.cotizacion))]);
        perdiendo.value = false;
        aviso.value = 'Cotización cerrada como perdida.';
        await cargar();
    });
}

/**
 * Manda el documento al correo del cliente, con el PDF adjunto.
 *
 * Va aparte de guardar a propósito: un documento se corrige tres veces antes de
 * mandarlo, y un correo por cada guardado sería una plaga para el cliente.
 */
async function enviarPorCorreo() {
    if (! confirm(`¿Enviar ${esCotizacion.value ? 'esta cotización' : 'esta nota de venta'} al correo del cliente?`)) return;
    await conServidor(async () => {
        const r = esCotizacion.value
            ? await api.enviarCotizacion(numero.value)
            : await api.enviarNotaVenta(numero.value);
        aviso.value = r.message;
        papelGuardado.value = await pdfGuardado(tipo.value, numero.value);
    });
}

/** Abre el PDF con el visor del teléfono. Con copia guardada, sin señal también. */
async function verPapel() {
    error.value = '';
    trabajando.value = true;
    try {
        await verPdf(tipo.value, numero.value);
        papelGuardado.value = await pdfGuardado(tipo.value, numero.value);
    } catch (e) {
        error.value = e.message;
    } finally {
        trabajando.value = false;
    }
}

/**
 * Manda el documento por WhatsApp.
 *
 * Son dos pasos y es culpa de WhatsApp, no de la app: un enlace `wa.me` sólo
 * lleva texto, así que el archivo se entrega por la hoja de compartir de
 * Android y ahí el vendedor elige el chat. El mensaje ya va escrito.
 */
async function compartirPapel() {
    error.value = '';
    trabajando.value = true;
    try {
        const r = await compartirPdf(tipo.value, numero.value, {
            cliente: cliente.value?.nombre,
            total: doc.value?.total,
            moneda: simbolo(doc.value?.moneda),
        });
        papelGuardado.value = await pdfGuardado(tipo.value, numero.value);

        // El servidor no ve salir esto: el acuse es lo que deja la emisión
        // marcada como entregada, y con eso una corrección posterior genera una
        // versión nueva en vez de pisar la que tiene el cliente.
        if (r.compartido && conectado.value) {
            try {
                await api.marcarCompartido(tipo.value, numero.value, 'whatsapp');
            } catch { /* el acuse no puede tumbar un envío que ya salió */ }
        }
        aviso.value = r.compartido ? 'Documento entregado a la app que elegiste.' : 'Documento descargado.';
    } catch (e) {
        error.value = e.message;
    } finally {
        trabajando.value = false;
    }
}

/**
 * Suelta el freno del tope: la nota de venta pasa al estado que le habría
 * dado el ERP de entrada, y `nvFeAprob` queda estampada. Igual que `anular()`,
 * el documento que devuelve el servidor se guarda en el teléfono — si no,
 * `doc.value` se queda con el `P` de IndexedDB hasta la próxima sincronización
 * y «Corregir» sigue apareciendo aunque la tarjeta de arriba ya diga aprobada.
 * De ahí para adelante ya no es corregible: `editable` lo lee de
 * `fecha_aprobacion`, sin ninguna regla nueva.
 *
 * Es un botón, no un switch: no hay estado que mantener si se cancela, y el
 * botón deja de verse en cuanto `puedeAprobar` pasa a `false`.
 */
async function aprobar() {
    const que = `la nota de venta Nº ${numero.value} por ${monto(doc.value.total, doc.value.moneda)}`;
    if (! confirm(`¿Aprobar ${que}? Después no se va a poder corregir.`)) return;
    await conServidor(async () => {
        const r = await api.resolverAprobacion(numero.value, { aprobar: true });
        await idb.guardar(def.value.almacen, [JSON.parse(JSON.stringify(r.nota_venta))]);
        aviso.value = 'Nota de venta aprobada.';
        await cargar();
    });
}

async function anular() {
    const que = esCotizacion.value ? 'esta cotización' : 'esta nota de venta';
    if (! confirm(`¿Anular ${que}? Se queda en Softland con su número, pero deja de contar.`)) return;

    await conServidor(async () => {
        const r = esCotizacion.value
            ? await api.anularCotizacion(numero.value)
            : await api.anularNotaVenta(numero.value);
        await idb.guardar(def.value.almacen, [JSON.parse(JSON.stringify(r.cotizacion ?? r.nota_venta))]);
        aviso.value = 'Anulada en Softland.';
        await cargar();
    });
}

/**
 * Eliminar de verdad. Es irreversible y **el número vuelve al pozo**: el
 * correlativo de Softland es el máximo más uno, así que el siguiente documento
 * que se cree puede quedarse con él.
 *
 * Si el servidor dice que no, devuelve las razones en vez de un mensaje suelto,
 * y se muestran todas: al vendedor le sirve saber de una vez qué estorba.
 */
async function eliminar() {
    trabajando.value = true;
    error.value = '';
    impedimentos.value = [];

    try {
        const r = esCotizacion.value
            ? await api.eliminarCotizacion(numero.value)
            : await api.eliminarNotaVenta(numero.value);

        // Borrar la nota de venta deshace la conversión: la cotización de la
        // que salía vuelve a pendiente en Softland, y la copia del teléfono
        // tiene que enterarse o seguirá diciendo «en nota de venta» —
        // sin botones, y sin la nota de venta a la que llevaba.
        if (r?.cotizacion_liberada) {
            const cot = await idb.obtener('cotizaciones', r.cotizacion_liberada);
            if (cot) await idb.guardar('cotizaciones', [{ ...cot, estado: 'P' }]);
        }

        // Del teléfono también: la ficha, sus líneas y el PDF guardado. Si no,
        // el documento sigue apareciendo en la lista hasta la próxima descarga
        // completa, y el papel se abriría sin nada detrás.
        await olvidarPdf(tipo.value, numero.value);
        for (const l of lineas.value) {
            await idb.borrar(def.value.lineas, [numero.value, l.linea]);
        }
        await idb.borrar(def.value.almacen, numero.value);

        borrando.value = false;
        router.replace(def.value.ruta);
    } catch (e) {
        impedimentos.value = e?.datos?.razones ?? [];
        if (! impedimentos.value.length) {
            error.value = e.message;
            borrando.value = false;
        }
    } finally {
        trabajando.value = false;
    }
}

async function anotarSeguimiento() {
    if (! formSeguimiento.value.descripcion.trim()) return;
    await conServidor(async () => {
        const r = await api.seguirCotizacion(numero.value, formSeguimiento.value);
        seguimientos.value = r.seguimientos ?? [];
        formSeguimiento.value = { descripcion: '', proximo_contacto: '' };
        siguiendo.value = false;
        aviso.value = 'Seguimiento anotado.';
    });
}

/**
 * Convertir en nota de venta.
 *
 * Se manda el mismo detalle que tiene la cotización: el vendedor puede
 * corregirlo después en la nota de venta, pero convertir no debe ser una
 * ocasión de volver a teclear diez líneas.
 *
 * Lo que se arrastra de la cotización es todo lo que el cliente ya aceptó: el
 * vendedor a cuyo nombre está, la fecha de entrega pactada y el texto de cada
 * línea. Volver a deducirlos del usuario que aprieta el botón cambiaría el
 * documento sin que nadie lo haya pedido.
 */
/**
 * Qué se convierte: todo, o sólo lo que quedó fuera.
 *
 * Las cantidades salen del saldo, no del detalle: si de doce unidades ya se
 * convirtieron cinco, la nueva nota de venta lleva siete, no doce.
 */
function lineasAConvertir() {
    if (! parcial.value) return lineas.value;

    const queda = new Map(pendientes.value.map((l) => [l.linea, l.saldo]));

    return lineas.value
        .filter((l) => queda.has(l.linea))
        .map((l) => ({ ...l, cantidad: queda.get(l.linea) }));
}

async function convertir() {
    await conServidor(async () => {
        const u = await db.getUsuario();
        const r = await api.convertirCotizacion(numero.value, {
            client_uuid: crypto.randomUUID?.() ?? `nv-${numero.value}-${Date.now()}`,
            cliente: doc.value.cliente,
            vendedor: doc.value.vendedor || u?.ven_cod || null,
            contacto: doc.value.contacto || null,
            moneda: doc.value.moneda,
            lista: doc.value.lista || null,
            condicion: doc.value.condicion || null,
            centro_costo: doc.value.centro_costo || u?.cod_cc || null,
            bodega: u?.cod_bode || null,
            fecha_entrega: (doc.value.fecha_entrega || '').slice(0, 10) || null,
            oc: doc.value.oc && doc.value.oc !== '0' ? doc.value.oc : null,
            observacion: doc.value.observacion || null,
            lineas: lineasAConvertir().map((l) => ({
                producto: l.producto,
                detalle: l.detalle || null,
                unidad: l.unidad || null,
                cantidad: l.cantidad,
                precio: l.unitario,
                // De qué línea de la cotización sale ésta. Es lo único que
                // permite saber después qué se llevó cada nota de venta; sin
                // esto, una cotización repartida queda en `V` y nadie sabe qué
                // falta.
                cot_linea: l.linea,
                descuento_pct: l.cantidad && l.precio
                    ? Math.round((l.descuento || 0) * 10000 / (l.cantidad * l.precio * (l.equiv || 1))) / 100
                    : 0,
            })),
        });
        const plano = JSON.parse(JSON.stringify(r));
        await idb.guardar('notas_venta', [plano.nota_venta]);
        await idb.guardar('nota_venta_lineas', plano.lineas || []);
        router.push(`/notas-venta/${r.nota_venta.numero}`);
    });
}

/**
 * Anular una factura: emitir la nota de crédito que la devuelve entera.
 *
 * Las líneas no se mandan: las arma el servidor desde la factura. Anular es
 * devolver lo que se facturó, todo y tal cual, y dejar que el teléfono proponga
 * las líneas sería dejar abierta la puerta a una nota de crédito que no cuadra
 * con lo que anula.
 *
 * Gasta un folio de nota de crédito, así que la hoja lo dice antes.
 */
async function anularFactura() {
    const f = anulando.value;

    await conServidor(async () => {
        const r = await api.emitirNotaCredito(f.tipo, f.numero_interno, razonNc.value);

        await idb.guardar('facturas', [r.documento]);
        await idb.guardar('factura_lineas', r.lineas || []);
        anulando.value = null;
        aviso.value = `Nota de crédito N° ${r.documento.folio} emitida: la factura N° ${f.folio} queda anulada.`;
        await cargar();
    });
}

/**
 * Duplicar: abre el alta con este documento ya cargado.
 *
 * No se copia nada aquí ni se llama al servidor. La copia vive en el
 * formulario hasta que el vendedor la guarda, que es cuando decide si lo que
 * quería era realmente otro documento igual. Duplicar y arrepentirse no deja
 * rastro en Softland.
 */
function duplicar() {
    router.push(`${def.value.ruta}/nuevo?desde=${numero.value}`);
}

async function conServidor(fn) {
    trabajando.value = true;
    error.value = '';
    aviso.value = '';
    try {
        await fn();
    } catch (e) {
        // Un 409 con el número de la NV es «ya estaba convertida»: se lleva ahí.
        if (e.status === 409 && e.datos?.nota_venta) {
            router.push(`/notas-venta/${e.datos.nota_venta}`);
            return;
        }
        error.value = e.message;
    } finally {
        trabajando.value = false;
    }
}

const avance = computed(() => (tipo.value === 'nota_venta' ? avanceFacturacion(lineas.value) : null));

/** Cantidades: Softland las guarda como float y «2» no se escribe «2,00». */
function cantidad(n) {
    return Number(n || 0).toLocaleString('es-CL', { maximumFractionDigits: 2 });
}
</script>

<template>
    <div class="pantalla">
        <div class="barra">
            <button class="icono-barra" @click="router.back()"><AppIcon name="atras" :size="24" /></button>
            <h1>{{ def.singular }} Nº {{ numero }}</h1>
        </div>

        <div class="contenido">
            <div class="cargando" v-if="cargando">Cargando…</div>

            <Vacio v-else-if="! doc && ! conectado" icono="sinRed"
                   :titulo="`No tenemos la ${def.singular.toLowerCase()} ${numero}`">
                No está en el teléfono y ahora no hay señal para buscarla en Softland.
                Vuelve a intentarlo con cobertura.
            </Vacio>

            <Vacio v-else-if="! doc" icono="sinResultados" :titulo="`No tenemos la ${def.singular.toLowerCase()} ${numero}`">
                Tampoco está en Softland con ese número, o es de otro vendedor.
            </Vacio>

            <template v-else>
                <!-- Se trajo del servidor, no estaba en el teléfono. Se dice,
                     porque cambia lo que se puede esperar de ella: sin señal
                     esta pantalla se queda vacía. -->
                <Aviso tipo="info" v-if="delServidor">
                    Esta {{ def.singular.toLowerCase() }} es anterior a los 12 meses que se
                    descargan al teléfono: se acaba de traer de Softland y no queda guardada.
                    Sin señal no se puede abrir.
                </Aviso>

                <div class="ficha">
                    <h2>{{ monto(doc.total, doc.moneda) }}</h2>
                    <div class="etiquetas">
                        <span class="etiqueta" :class="estado(tipo, doc.estado).color === 'rojo' ? 'roja'
                              : estado(tipo, doc.estado).color === 'verde' ? 'verde' : ''">
                            {{ estado(tipo, doc.estado).rotulo }}
                        </span>
                        <span class="etiqueta gris">{{ fecha(doc.fecha) }}</span>
                        <!-- `numOC` es NOT NULL en Softland con cero por defecto:
                             un «OC 0» no es una orden de compra, es el hueco. -->
                        <span v-if="doc.oc && doc.oc !== '0'" class="etiqueta gris">OC {{ doc.oc }}</span>
                    </div>
                </div>

                <!-- El cliente se despliega aquí mismo: ya está en el teléfono
                     (viene de IndexedDB con el documento), así que no hace
                     falta saltar a su ficha para ver dos datos. -->
                <Persiana v-if="cliente" class="cliente-persiana">
                    <template #cabecera><span class="cliente-nombre">{{ cliente.nombre }}</span></template>
                    <div class="tarjeta-cuerpo datos">
                        <div><span>RUT</span><b>{{ cliente.rut }}</b></div>
                        <div v-if="cliente.giro"><span>Giro</span><b>{{ nombreDe('giros', cliente.giro) }}</b></div>
                        <div v-if="cliente.direccion"><span>Dirección</span><b>{{ cliente.direccion }}</b></div>
                        <div v-if="ubicacionCliente"><span>Ubicación</span><b>{{ ubicacionCliente }}</b></div>
                        <div v-if="cliente.fono"><span>Teléfono</span><b>{{ cliente.fono }}</b></div>
                        <div v-if="cliente.email"><span>Correo</span><b>{{ cliente.email }}</b></div>
                    </div>
                    <button class="enlace cliente-ficha" @click="router.push(`/clientes/${cliente.codigo}`)">
                        Ver ficha completa
                    </button>
                </Persiana>
                <div class="sub" v-else>Cliente {{ doc.cliente }}</div>

                <Aviso tipo="error" v-if="error">{{ error }}</Aviso>
                <Aviso tipo="ok" v-if="aviso">{{ aviso }}</Aviso>

                <!-- Convertida a medias. Softland no distingue este caso — su
                     estado `V` dice «tiene nota de venta» y nada más —, así que
                     sin esto el vendedor no tiene cómo saber qué quedó fuera
                     salvo acordándose. -->
                <Aviso tipo="info" v-if="parcial">
                    Convertida a medias: quedan
                    <b>{{ saldo.pendientes }} de {{ saldo.totales }}</b>
                    {{ saldo.totales === 1 ? 'línea' : 'líneas' }} por pasar a nota de venta.
                    <ul class="saldo-lineas">
                        <li v-for="l in pendientes" :key="l.linea">
                            {{ l.nombre }} — quedan <b>{{ cantidad(l.saldo) }}</b>
                            <span v-if="l.convertida > 0"> de {{ cantidad(l.cantidad) }}</span>
                        </li>
                    </ul>
                </Aviso>

                <!-- Convertida, pero de antes de la app: no hay enlace de línea
                     y el saldo no se puede saber. Decirlo es mejor que callar:
                     el vendedor sabe que tiene que mirarlo en Softland. -->
                <Aviso tipo="info" v-else-if="esCotizacion && saldo && ! saldo.conocible">
                    Esta cotización se convirtió fuera de la app, así que no se puede saber qué
                    quedó pendiente. Lo dice Softland.
                </Aviso>

                <!-- El papel. Siempre visible: ver o mandar el documento no
                     depende de que todavía se pueda corregir, y con el PDF ya
                     guardado tampoco de la señal. -->
                <div class="acciones-doc">
                    <button class="chip-accion" :disabled="trabajando || (! conectado && ! papelGuardado)"
                            @click="compartirPapel">
                        <AppIcon name="compartir" :size="17" color="currentColor" /> Enviar por WhatsApp
                    </button>
                    <button class="chip-accion" :disabled="! conectado || trabajando"
                            @click="enviarPorCorreo">
                        <AppIcon name="correo" :size="17" color="currentColor" /> Enviar por correo
                    </button>
                    <button class="chip-accion" :disabled="trabajando || (! conectado && ! papelGuardado)"
                            @click="verPapel">
                        <AppIcon name="pdf" :size="17" color="currentColor" /> Ver el documento
                    </button>
                </div>
                <p class="ayuda" v-if="! conectado && ! papelGuardado">
                    El documento se dibuja en el servidor. Ábrelo una vez con señal y después queda en el teléfono.
                </p>

                <!-- Lo que ya se facturó de esta nota de venta. Va aquí porque
                     es aquí donde alguien se pregunta qué salió y qué falta, no
                     en una lista de facturas aparte. Las notas de crédito no se
                     listan: son el desenlace de una factura, y enseñarlas
                     sueltas haría contar dos veces la misma operación. -->
                <template v-if="! esCotizacion && facturas.length">
                    <div class="seccion"><h2>Facturado</h2></div>
                    <div class="item" v-for="f in facturas" :key="`${f.tipo}-${f.numero_interno}`">
                        <div class="item-estado" :class="f.anulada || f.acreditada ? 'gris' : 'verde'"></div>
                        <div class="item-cuerpo">
                            <div class="item-titulo">
                                Factura Nº {{ f.folio }} · {{ monto(f.total, f.moneda) }}
                            </div>
                            <div class="item-meta">
                                <span>{{ fecha(f.fecha) }}</span>
                                <span v-if="f.acreditada">
                                    · <span class="etiqueta gris">Anulada con la NC Nº {{ f.acreditada }}</span>
                                </span>
                                <span v-else-if="f.anulada"> · <span class="etiqueta gris">Anulada</span></span>
                                <span v-else-if="! f.enviado_sii"> · <span class="etiqueta cian">Sin enviar al SII</span></span>
                            </div>
                            <button class="boton-texto peligro" v-if="! f.anulada && ! f.acreditada"
                                    :disabled="! conectado" @click.stop="anulando = f; razonNc = 'Anula Documento'">
                                Anular con nota de crédito
                            </button>
                        </div>
                    </div>
                </template>

                <!-- Todo lo que se puede hacer con este documento, en una sola
                     fila que se desplaza: sólo se ofrece lo que de verdad se
                     puede, porque un botón que responde «ya no se puede» es
                     peor que no tener el botón. Corregir y Duplicar van juntos
                     porque se buscan juntos —la venta del mes pasado, la
                     cotización que se perdió por precio—; Aprobar, Anular y
                     Eliminar van al final porque son las que cierran el
                     documento. -->
                <div class="acciones-doc" v-if="editable || esCotizacion || puedeConvertir || puedeAprobar || anulable || borrable">
                    <button class="chip-accion" v-if="editable"
                            @click="router.push(`${def.ruta}/${numero}/editar`)">
                        <AppIcon name="configuracion" :size="17" color="currentColor" /> Corregir
                    </button>
                    <button class="chip-accion" @click="duplicar">
                        <AppIcon name="duplicar" :size="17" color="currentColor" /> Duplicar
                    </button>
                    <button class="chip-accion" v-if="esCotizacion" :disabled="! conectado"
                            @click="siguiendo = true">
                        <AppIcon name="buzon" :size="17" color="currentColor" /> Seguimiento
                    </button>
                    <button class="chip-accion" v-if="esCotizacion && editable" :disabled="! conectado"
                            @click="perdiendo = true">
                        <AppIcon name="error" :size="17" color="currentColor" /> Perdida
                    </button>
                    <button class="chip-accion fuerte" v-if="puedeConvertir"
                            :disabled="! conectado || trabajando" @click="convertir">
                        <AppIcon name="notaVenta" :size="17" color="currentColor" />
                        {{ parcial ? 'Nota de venta por el saldo' : 'Pasar a nota de venta' }}
                    </button>
                    <button class="chip-accion fuerte" v-if="puedeFacturar" :disabled="! conectado"
                            @click="router.push(`/notas-venta/${numero}/facturar`)">
                        <AppIcon name="factura" :size="17" color="currentColor" /> Facturar
                    </button>
                    <button class="chip-accion" v-if="puedeAprobar" :disabled="! conectado || trabajando"
                            @click="aprobar">
                        <AppIcon name="ok" :size="17" color="currentColor" /> Aprobar
                    </button>
                    <button class="chip-accion peligro" v-if="anulable" :disabled="! conectado || trabajando"
                            @click="anular">
                        <AppIcon name="anular" :size="17" color="currentColor" /> Anular
                    </button>
                    <button class="chip-accion peligro" v-if="borrable" :disabled="! conectado || trabajando"
                            @click="impedimentos = []; borrando = true">
                        <AppIcon name="borrar" :size="17" color="currentColor" /> Eliminar
                    </button>
                </div>
                <p class="ayuda" v-if="! conectado && (editable || puedeConvertir)">
                    Sin señal solo se puede mirar: cambiar un documento que ya está en Softland necesita red.
                </p>

                <!-- La aprobación del jefe no existe en Softland: la pone la app
                     cuando la venta pasa el tope del vendedor. Sin fila en
                     `ventas.aprobacion` no hay estado que citar — pasa con las
                     notas que quedaron en `P` desde Softland de escritorio, de
                     antes de esta app —, pero si de todos modos se puede
                     aprobar el aviso aparece igual con el motivo genérico. -->
                <Aviso tipo="info" v-if="! esCotizacion && (aprobacion || puedeAprobar)">
                    {{ aprobacion?.motivo || 'Pendiente, sin solicitud de aprobación registrada en la app.' }}
                    <template v-if="aprobacion?.comentario"> — {{ aprobacion.comentario }}</template>
                </Aviso>

                <!-- El avance real de una NV se lee línea por línea: los flags del
                     encabezado están en 0 en las 800 notas de venta de INNOVAGES. -->
                <div class="tarjeta" v-if="avance">
                    <div class="tarjeta-cabecera">Facturación</div>
                    <div class="tarjeta-cuerpo">
                        <div class="progreso"><div class="relleno" :style="{ width: avance.pct + '%' }"></div></div>
                        <p class="ayuda">
                            {{ avance.completo ? 'Facturada por completo.'
                               : `Facturado ${cantidad(avance.facturado)} de ${cantidad(avance.pedido)} (${avance.pct} %).` }}
                        </p>
                    </div>
                </div>

                <div class="tarjeta datos-persiana">
                    <Persiana>
                        <template #cabecera><b>Datos</b></template>
                        <div class="tarjeta-cuerpo datos">
                            <div v-if="doc.contacto"><span>Contacto</span><b>{{ doc.contacto }}</b></div>
                            <div v-if="doc.vendedor"><span>Vendedor</span><b>{{ nombreDe('vendedores', doc.vendedor) }}</b></div>
                            <div v-if="doc.condicion"><span>Condición</span><b>{{ nombreDe('condiciones_venta', doc.condicion) }}</b></div>
                            <div v-if="doc.centro_costo"><span>Centro de costo</span><b>{{ nombreDe('centros_costo', doc.centro_costo) }}</b></div>
                            <div v-if="doc.bodega"><span>Bodega</span><b>{{ nombreDe('bodegas', doc.bodega) }}</b></div>
                            <div v-if="doc.fecha_entrega"><span>Entrega</span><b>{{ fecha(doc.fecha_entrega) }}</b></div>
                            <div v-if="doc.cotizacion"><span>Viene de</span>
                                <b><button class="enlace" @click="router.push(`/cotizaciones/${doc.cotizacion}`)">
                                    Cotización {{ doc.cotizacion }}</button></b>
                            </div>
                            <div v-if="doc.observacion"><span>Observación</span><b>{{ doc.observacion }}</b></div>
                        </div>
                    </Persiana>
                </div>

                <div class="seccion">
                    <h2>Detalle</h2>
                    <span class="sub">{{ lineas.length }} {{ lineas.length === 1 ? 'línea' : 'líneas' }}</span>
                </div>

                <div class="item" v-for="l in lineas" :key="l.linea">
                    <div class="item-estado cian"></div>
                    <div class="item-cuerpo">
                        <div class="item-titulo">{{ l.nombre }}</div>
                        <div class="item-linea">
                            {{ cantidad(l.cantidad) }} {{ nombreDe('unidades', l.unidad) }}
                            × {{ monto(l.unitario, doc.moneda) }} = {{ monto(l.total, doc.moneda) }}
                        </div>
                        <div class="item-meta">
                            <span class="etiqueta gris">{{ l.producto }}</span>
                            <span v-if="l.moneda_origen !== null">
                                · {{ cantidad(l.precio) }} <template v-if="l.moneda_origen">{{ simbolo(l.moneda_origen) }}</template>
                                a {{ monto(l.equiv, doc.moneda) }}
                            </span>
                            <span v-if="l.descuento"> · desc. {{ monto(l.descuento, doc.moneda) }}</span>
                            <span v-if="l.facturado"> · facturado {{ cantidad(l.facturado) }}</span>
                        </div>
                    </div>
                </div>

                <template v-if="esCotizacion && seguimientos.length">
                    <div class="seccion">
                        <h2>Seguimiento</h2>
                        <span class="sub">{{ seguimientos.length }}</span>
                    </div>
                    <div class="item" v-for="s in seguimientos" :key="s.numero">
                        <div class="item-estado amarillo"></div>
                        <div class="item-cuerpo">
                            <div class="item-titulo">{{ s.descripcion }}</div>
                            <div class="item-meta">
                                <span class="etiqueta gris">{{ fecha(s.fecha) }}</span>
                                <span v-if="s.contacto"> · {{ s.contacto }}</span>
                                <span v-if="s.proximo_contacto"> · vuelve el {{ fecha(s.proximo_contacto) }}</span>
                            </div>
                        </div>
                    </div>
                </template>

                <div class="tarjeta">
                    <div class="tarjeta-cabecera">Totales</div>
                    <div class="tarjeta-cuerpo datos">
                        <div><span>Neto afecto</span><b>{{ monto(doc.neto, doc.moneda) }}</b></div>
                        <div v-if="doc.exento"><span>Exento</span><b>{{ monto(doc.exento, doc.moneda) }}</b></div>
                        <div v-if="doc.descuento"><span>Descuentos</span><b>{{ monto(doc.descuento, doc.moneda) }}</b></div>
                        <div v-if="doc.flete"><span>Flete</span><b>{{ monto(doc.flete, doc.moneda) }}</b></div>
                        <div v-if="doc.embalaje"><span>Embalaje</span><b>{{ monto(doc.embalaje, doc.moneda) }}</b></div>
                        <div class="fuerte"><span>Total</span><b>{{ monto(doc.total, doc.moneda) }}</b></div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Cerrar por pérdida -->
        <div class="velo" v-if="perdiendo" @click.self="perdiendo = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Cerrar como perdida</h2>
                    <button class="icono-barra" @click="perdiendo = false"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <p class="ayuda">
                        Queda cerrada en Softland con su motivo. Después no se puede corregir ni convertir.
                    </p>

                    <label>Motivo</label>
                    <Selector v-model="formPerdida.motivo" maestro="motivos_perdida" :vacio="null" />

                    <label>Qué pasó</label>
                    <textarea v-model="formPerdida.observacion" rows="3"
                              placeholder="Lo que sirva para la próxima"></textarea>

                    <button class="boton peligro" :disabled="! formPerdida.motivo || trabajando" @click="perder">
                        {{ trabajando ? 'Cerrando…' : 'Cerrar como perdida' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Eliminar de Softland -->
        <div class="velo" v-if="borrando" @click.self="borrando = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Eliminar de Softland</h2>
                    <button class="icono-barra" @click="borrando = false"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <Aviso v-if="impedimentos.length" tipo="error">
                        <ul class="motivos">
                            <li v-for="(m, i) in impedimentos" :key="i">{{ m }}</li>
                        </ul>
                    </Aviso>

                    <template v-else>
                        <p class="ayuda">
                            {{ esCotizacion ? 'La cotización' : 'La nota de venta' }} N° {{ numero }} desaparece de
                            Softland con todo su detalle. No se puede deshacer, y el número queda libre: el
                            siguiente documento que se cree puede quedarse con él.
                        </p>
                        <p class="ayuda">
                            Si ya salió al cliente, anúlala en vez de eliminarla: así conserva su número.
                        </p>

                        <button class="boton peligro" :disabled="trabajando" @click="eliminar">
                            {{ trabajando ? 'Eliminando…' : 'Eliminar definitivamente' }}
                        </button>
                    </template>

                    <button class="boton-texto peligro" v-if="anulable" @click="borrando = false; anular()">
                        Anular en vez de eliminar
                    </button>
                </div>
            </div>
        </div>

        <!-- Anular una factura con nota de crédito. Gasta un folio, así que se
             dice antes y se nombra la factura que se está anulando. -->
        <div class="velo" v-if="anulando" @click.self="anulando = null">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Anular la factura Nº {{ anulando.folio }}</h2>
                    <button class="icono-barra" @click="anulando = null">
                        <AppIcon name="cerrar" :size="21" />
                    </button>
                </div>
                <div class="hoja-cuerpo">
                    <p class="ayuda">
                        Se emite una nota de crédito que devuelve la factura entera, por
                        {{ monto(anulando.total, anulando.moneda) }}. La factura conserva su número
                        y su folio: lo entregado al cliente no se borra, se anula.
                    </p>
                    <p class="ayuda">
                        Gasta un folio de nota de crédito, y un folio no se devuelve.
                    </p>

                    <label>Razón</label>
                    <input v-model="razonNc" maxlength="90" placeholder="Anula Documento">

                    <Aviso tipo="error" v-if="error">{{ error }}</Aviso>

                    <button class="boton peligro" :disabled="trabajando || ! conectado"
                            @click="anularFactura">
                        {{ trabajando ? 'Emitiendo…' : 'Emitir la nota de crédito' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Anotar un seguimiento -->
        <div class="velo" v-if="siguiendo" @click.self="siguiendo = false">
            <div class="hoja">
                <div class="hoja-cabecera">
                    <h2>Seguimiento</h2>
                    <button class="icono-barra" @click="siguiendo = false"><AppIcon name="cerrar" :size="21" /></button>
                </div>
                <div class="hoja-cuerpo">
                    <label>Qué se hizo</label>
                    <textarea v-model="formSeguimiento.descripcion" rows="3"
                              placeholder="Llamada, visita, correo…"></textarea>

                    <label>Próximo contacto</label>
                    <input v-model="formSeguimiento.proximo_contacto" type="date" class="angosto">

                    <button class="boton" :disabled="! formSeguimiento.descripcion.trim() || trabajando"
                            @click="anotarSeguimiento">
                        {{ trabajando ? 'Guardando…' : 'Anotar' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
