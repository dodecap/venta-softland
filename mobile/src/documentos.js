import { idb } from './idb';

/*
 * Cotizaciones y notas de venta: lo que tienen en común.
 *
 * Son el mismo documento en dos momentos de su vida — la cotización se
 * convierte en nota de venta y la NV guarda de qué cotización vino — así que la
 * app las dibuja con la misma pantalla y solo cambia esta tabla.
 *
 * ## Los estados son cuatro, y son los de Softland
 *
 * No son una lectura de los datos: son el vocabulario del ERP, y no hay más.
 * Son también los que filtran las listas, porque filtrar por algo que Softland
 * no reconoce es filtrar por nada.
 *
 * **`N` es «nula», no «nueva»**, en los dos documentos. Su gemelo en el
 * servidor — el que escribe el PDF — está en `TipoDocumento::estados()`. Los
 * dos tienen que decir lo mismo.
 */

export const TIPOS = {
    cotizacion: {
        titulo: 'Cotizaciones',
        singular: 'Cotización',
        icono: 'cotizacion',
        ruta: '/cotizaciones',
        almacen: 'cotizaciones',
        // El grupo de sincronización: cabecera y detalle juntos. Lo usa el
        // tirón hacia abajo de la lista (`sync.js`, `GRUPOS`).
        grupo: 'cotizaciones',
        lineas: 'cotizacion_lineas',
        indiceLineas: 'cotizacion',
        estados: {
            P: { rotulo: 'Pendiente', color: 'amarillo' },
            V: { rotulo: 'En nota de venta', color: 'verde' },
            R: { rotulo: 'Perdida', color: 'rojo' },
            N: { rotulo: 'Nula', color: 'gris' },
        },
    },
    nota_venta: {
        /*
         * Los papeles con los que puede salir. El primero es el suyo.
         *
         * La orden de compra es el mismo documento mirado desde el otro lado:
         * lo que el cliente compró, pedido al proveedor. Es el espejo de lo que
         * declara `TipoDocumento` en el servidor, y los dos tienen que decir lo
         * mismo — igual que los estados.
         */
        papeles: [
            { id: null, rotulo: 'Nota de venta', para: 'Para el cliente' },
            { id: 'orden_compra', rotulo: 'Orden de compra', para: 'Para el proveedor' },
        ],
        titulo: 'Notas de venta',
        singular: 'Nota de venta',
        icono: 'notaVenta',
        ruta: '/notas-venta',
        almacen: 'notas_venta',
        grupo: 'notas_venta',
        lineas: 'nota_venta_lineas',
        indiceLineas: 'nota_venta',
        estados: {
            P: { rotulo: 'Pendiente', color: 'amarillo' },
            A: { rotulo: 'Aprobada', color: 'verde' },
            C: { rotulo: 'Concluida', color: 'cian' },
            N: { rotulo: 'Nula', color: 'gris' },
        },
    },
};

/** Estado legible. Un código que no está en la tabla se muestra tal cual. */
export function estado(tipo, codigo) {
    const c = String(codigo || '').trim().toUpperCase();
    return TIPOS[tipo].estados[c] ?? { rotulo: c || 'Sin estado', color: 'gris' };
}

/**
 * En qué quedó un documento con el SII.
 *
 * Es **otro estado**, no una variante del anterior: el de arriba dice si el
 * documento está vigente o anulado en el ERP, y éste dice si existe para el
 * fisco. Una factura puede estar impecable en inventario y no haber salido
 * nunca, y ésa es justamente la que hay que ver de lejos.
 *
 * Lo que significa «sin enviar» depende del modo: con el envío automático es
 * una avería, con el manual es la tarea de alguien. De ahí el segundo
 * argumento, que sale de la configuración del servidor.
 *
 * Vive aquí, con el otro, para que la lista y la ficha no puedan discrepar.
 */
export function estadoSii(d, automatico = true) {
    if (! d?.track_id) {
        // El mismo hecho, dos lecturas. Con el envío automático, un documento
        // escrito y sin viajar es una **avería** —o el SII no contestó, o salió
        // del Softland de escritorio— y va en rojo. Con el envío en manual es
        // el paso siguiente del trabajo, y pintarlo de rojo sería que la lista
        // gritara todos los días por algo que se decidió que fuera así.
        return automatico
            ? { rotulo: 'No llegó al SII', color: 'rojo' }
            : { rotulo: 'Por enviar al SII', color: 'amarillo' };
    }

    if (d.aceptada) return { rotulo: 'Aceptada por el SII', color: 'verde' };
    if (d.motivo_sii) return { rotulo: 'Rechazada por el SII', color: 'rojo' };

    return { rotulo: 'Enviada, esperando al SII', color: 'amarillo' };
}

/**
 * Las líneas de un documento, en orden y con el precio ya convertido.
 *
 * Softland guarda el precio de la línea en la moneda **del producto** y el
 * total en la del documento; `equiv` es el factor entre las dos. Un tercio de
 * las líneas de INNOVAGES lo tiene distinto de 1 — el producto está en UF y la
 * cotización en pesos — así que mostrar `precio` a secas dice «1 UNIDAD × $ 5
 * = $ 214.735», que no cuadra ni con una calculadora en la mano.
 *
 * Cada línea sale de aquí con dos campos añadidos: `unitario`, que es lo que
 * hay que mostrar junto al total, y `moneda_origen`, para poder escribir «5,25
 * UF» debajo cuando el precio venía en otra moneda.
 */
export async function lineasDe(tipo, numero) {
    const def = TIPOS[tipo];

    return enriquecerLineas(await idb.porIndice(def.lineas, def.indiceLineas, Number(numero)));
}

/**
 * Lo mismo, para líneas que no vienen de IndexedDB.
 *
 * Existe por los documentos más viejos que la ventana de doce meses: no están
 * en el teléfono y se traen del servidor enteros, cabecera y detalle. Se ven
 * exactamente igual que los demás, y por eso el arreglo pasa por aquí en vez
 * de dibujarse aparte.
 */
export async function enriquecerLineas(lineas) {
    const filas = [...lineas];
    filas.sort((a, b) => a.linea - b.linea);

    for (const l of filas) {
        const factor = l.equiv || 1;
        const p = await idb.obtener('productos', l.producto);

        l.unitario = (l.precio || 0) * factor;
        // Cómo se llama la línea. Softland deja `DetProd` vacío casi siempre, y
        // entonces lo que hay que leer es el nombre del producto: una lista de
        // códigos de ocho dígitos no se le muestra a un cliente.
        l.nombre = l.detalle || p?.nombre || l.producto;
        // La moneda del precio original sale de la ficha del producto. Puede no
        // estar — líneas de texto suelto, productos dados de baja — y entonces
        // se muestra el número sin símbolo antes que mentir con un «$».
        l.moneda_origen = factor === 1 ? null : p?.moneda ?? '';
    }

    return filas;
}

/**
 * Cuánto falta por facturar de una nota de venta.
 *
 * Se mira línea por línea y no en el encabezado a propósito: los flags
 * `nvEstFact` y `nvEstDesp` están en 0 en las 800 notas de venta de INNOVAGES,
 * Softland no los usa. El avance real vive en `nvCantFact` de cada línea.
 */
export function avanceFacturacion(lineas) {
    const pedido = lineas.reduce((n, l) => n + (l.cantidad || 0), 0);
    const facturado = lineas.reduce((n, l) => n + (l.facturado || 0), 0);
    if (! pedido) return null;

    return {
        pedido,
        facturado,
        pct: Math.round((facturado / pedido) * 100),
        completo: facturado >= pedido,
    };
}

/* ------------------------------------------------------------------ escribir */

/**
 * Los mismos totales que va a calcular el servidor, pero en el teléfono.
 *
 * Existe para que el vendedor vea el total mientras teclea, también sin señal.
 * La aritmética está copiada de `app/Services/Softland/Totales.php`, que a su
 * vez salió de reproducir 200 cotizaciones reales de INNOVAGES columna por
 * columna. Si se toca una, hay que tocar la otra.
 *
 * Una diferencia a propósito: aquí el precio es el de la **moneda del
 * documento**, que es como lo escribe el vendedor. El servidor lo devuelve a la
 * moneda del producto antes de guardarlo, porque es donde lo espera Softland.
 * Los totales salen iguales; lo que cambia es dónde está el factor.
 *
 * @param {Array} lineas  con cantidad, precio, descuento_pct y afecto
 */
export function calcularTotales(lineas, descuentoPct = 0, ivaPct = 19) {
    let bruto = 0;
    let brutoAfecto = 0;
    const calculadas = [];

    for (const l of lineas) {
        const subtotal = redondear(Number(l.cantidad || 0) * Number(l.precio || 0), 2);
        const descuento = Math.round(subtotal * Number(l.descuento_pct || 0) / 100);
        const total = redondear(subtotal - descuento, 2);

        calculadas.push({ ...l, subtotal, descuento, total });
        bruto += total;
        if (l.afecto) brutoAfecto += total;
    }

    bruto = redondear(bruto, 2);
    brutoAfecto = redondear(brutoAfecto, 2);

    const descuentoPie = Math.round(bruto * Number(descuentoPct || 0) / 100);
    const neto = redondear(bruto - descuentoPie, 2);

    // Lo exento sale por diferencia para que las dos partes sumen exactamente
    // el neto: repartir los dos por separado hace aparecer un peso de la nada.
    const afecto = bruto > 0 ? redondear(neto * brutoAfecto / bruto, 2) : 0;
    const exento = redondear(neto - afecto, 2);
    const iva = Math.round(afecto * ivaPct / 100);

    return {
        lineas: calculadas,
        bruto,
        subtotal: Math.round(bruto),
        descuento: descuentoPie,
        afecto,
        exento,
        iva,
        total: Math.round(bruto) - descuentoPie + iva,
    };
}

function redondear(n, decimales) {
    const f = 10 ** decimales;
    return Math.round((n + Number.EPSILON) * f) / f;
}

/**
 * Lo que se le manda al servidor, sacado del formulario.
 *
 * Va aparte del formulario porque el formulario tiene cosas que son para la
 * pantalla — el nombre del producto, si es afecto — y mandarlas sería decirle
 * al servidor cosas que él sabe mejor. El IVA de una línea se decide en
 * Softland, no en un teléfono que puede tener el catálogo de hace una semana.
 */
export function cuerpoDe(form) {
    return {
        cliente: form.cliente,
        // Los campos que define la empresa en el ERP. Va el objeto entero,
        // incluidos los vacíos: un atributo que se borra tiene que llegar como
        // vacío, porque «no viene» y «viene en blanco» son cosas distintas —
        // lo primero no toca nada y lo segundo borra lo que hubiera.
        atributos: form.atributos ?? undefined,
        vendedor: form.vendedor || null,
        contacto: form.contacto || null,
        moneda: form.moneda || '01',
        lista: form.lista || null,
        condicion: form.condicion || null,
        centro_costo: form.centro_costo || null,
        bodega: form.bodega || null,
        fecha: form.fecha || null,
        fecha_entrega: form.fecha_entrega || null,
        oc: form.oc || null,
        observacion: form.observacion || null,
        descuento_pct: Number(form.descuento_pct || 0),
        lineas: form.lineas.map((l) => ({
            producto: l.producto,
            // Lo que va a leer el cliente en el papel. Va siempre: si el
            // vendedor no lo tocó, es la descripción del maestro.
            detalle: l.detalle || l.nombre || null,
            unidad: l.unidad || null,
            cantidad: Number(l.cantidad || 0),
            precio: Number(l.precio || 0),
            descuento_pct: Number(l.descuento_pct || 0),
        })),
    };
}
