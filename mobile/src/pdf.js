import { Capacitor } from '@capacitor/core';
import { Directory, Filesystem } from '@capacitor/filesystem';
import { Share } from '@capacitor/share';
import { idb } from './idb';
import { api } from './api';
import { conectado } from './red';

/*
 * El PDF del documento en el teléfono.
 *
 * ## El teléfono no dibuja PDF, los archiva
 *
 * El papel se dibuja en el servidor, siempre. La razón no es de comodidad: el
 * número del documento lo asigna el servidor, y un PDF que dice «Cotización
 * N° —» no es un documento comercial, es un borrador. Además, dibujarlo aquí
 * obligaría a mantener una segunda plantilla en JavaScript — el problema que
 * `documentos.js` ya obliga a vigilar con los totales, y que allá era
 * inevitable porque el vendedor tiene que ver el total antes de grabar.
 *
 * Lo que sí hace el teléfono es guardarse los bytes que le llegaron, para
 * poder abrir y mandar el documento en un subterráneo sin señal.
 *
 * ## Por qué WhatsApp se resuelve con la hoja de compartir
 *
 * Porque `wa.me` **sólo transporta texto**. No existe forma de adjuntar un
 * archivo por un enlace de WhatsApp; lo que hace `soporte.js` — abrir el chat
 * con un mensaje escrito — es todo lo que ese camino permite. Para que el PDF
 * viaje de verdad hay que pasarle el archivo al sistema y dejar que el vendedor
 * elija WhatsApp y el contacto. De paso queda servido el resto: correo, Drive,
 * Bluetooth o la impresora.
 *
 * Mandar un enlace al PDF del servidor queda descartado y conviene que se sepa
 * por qué: `192.168.1.55:8086` no existe fuera de la oficina, y el cliente está
 * fuera de la oficina.
 */

/** Cuántos PDF se guardan. A unos 60 KB cada uno, el tope son ~3 MB. */
const TOPE = 50;

/**
 * Los documentos que tienen papel, y cómo se pide cada uno.
 *
 * Los comerciales se piden por su número, que es el que ve todo el mundo. Los
 * legales se piden por su **número interno**, que es la clave en Softland: el
 * folio se enseña, pero no identifica el documento en la API — es único dentro
 * de su tipo y nada más. Por eso llevan `etiqueta`, que es lo que sale en el
 * nombre del archivo y en el mensaje: el folio.
 */
const TIPOS = {
    cotizacion: {
        rotulo: 'Cotización',
        archivo: 'cotizacion',
        pedir: (n) => api.pdfCotizacion(n),
    },
    nota_venta: {
        rotulo: 'Nota de venta',
        archivo: 'nota-de-venta',
        pedir: (n) => api.pdfNotaVenta(n),
    },
    /*
     * La otra cara de la nota de venta: lo mismo pedido al proveedor.
     *
     * Va como tipo aparte y no como una opción de la nota de venta porque son
     * dos papeles distintos que se mandan a dos personas distintas — y cada uno
     * necesita su propia copia guardada. Si compartieran clave, abrir uno
     * borraría el otro del teléfono.
     */
    orden_compra: {
        rotulo: 'Orden de compra',
        archivo: 'orden-de-compra',
        pedir: (n) => api.pdfNotaVenta(n, 'orden_compra'),
    },
    factura: {
        rotulo: 'Factura',
        archivo: 'factura',
        pedir: (n) => api.pdfFactura('F', n),
    },
    boleta: {
        rotulo: 'Boleta',
        archivo: 'boleta',
        pedir: (n) => api.pdfFactura('B', n),
    },
    nota_credito: {
        rotulo: 'Nota de crédito',
        archivo: 'nota-de-credito',
        pedir: (n) => api.pdfFactura('N', n),
    },
};

const nativo = Capacitor.isNativePlatform();

function clave(tipo, numero) {
    return `${tipo}:${numero}`;
}

/**
 * Los bytes del documento: del teléfono si están, del servidor si no.
 *
 * @param {boolean} refrescar  forzar la bajada aunque haya copia — se usa
 *                             después de corregir el documento, cuando lo
 *                             guardado ya no es lo que dice Softland.
 */
export async function pdfDe(tipo, numero, { refrescar = false } = {}) {
    const guardado = refrescar ? null : await idb.obtener('pdfs', clave(tipo, numero));

    if (guardado) {
        // Se reordena el uso para que la limpieza se lleve los que nadie abre.
        await idb.guardar('pdfs', [{ ...guardado, usado: new Date().toISOString() }]);
        return guardado.bytes;
    }

    if (! conectado.value) {
        throw new Error('El documento todavía no está descargado y no hay conexión.');
    }

    const { bytes, version } = await TIPOS[tipo].pedir(numero);

    await idb.guardar('pdfs', [{
        clave: clave(tipo, numero),
        tipo,
        numero,
        version,
        bytes,
        usado: new Date().toISOString(),
    }]);
    await limpiar();

    return bytes;
}

/** Lo guardado de este documento, para saber si hay algo que mostrar sin señal. */
export async function pdfGuardado(tipo, numero) {
    return idb.obtener('pdfs', clave(tipo, numero));
}

/** Se borra la copia local: el documento cambió y lo guardado ya no vale. */
export async function olvidarPdf(tipo, numero) {
    await idb.borrar('pdfs', clave(tipo, numero));
}

export function nombreArchivo(tipo, numero, etiqueta = null) {
    return TIPOS[tipo].archivo + '-' + (etiqueta ?? numero) + '.pdf';
}

/**
 * Abre el documento con el visor del teléfono.
 *
 * En el APK hay que escribirlo primero en un archivo: la WebView no abre un
 * `blob:` con el visor del sistema. En el navegador de desarrollo, en cambio,
 * un `blob:` en una pestaña nueva es exactamente lo que hace falta.
 */
export async function verPdf(tipo, numero, opciones = {}) {
    const bytes = await pdfDe(tipo, numero, opciones);
    const etiqueta = opciones.etiqueta ?? null;

    if (! nativo) {
        const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
        window.open(url, '_blank');
        // Un minuto alcanza de sobra para que el visor lo lea, y no deja el
        // objeto colgando en memoria el resto de la sesión.
        setTimeout(() => URL.revokeObjectURL(url), 60_000);
        return;
    }

    const { uri } = await escribir(tipo, numero, bytes, etiqueta);
    await Share.share({
        title: rotulo(tipo, numero, etiqueta),
        files: [uri],
    });
}

/**
 * Manda el documento por WhatsApp.
 *
 * Son dos pasos y no uno, y es una limitación del propio WhatsApp: el texto se
 * deja escrito para que el vendedor no lo redacte, pero el archivo lo entrega
 * la hoja de compartir del sistema, donde él elige el chat. No hay forma de
 * hacer las dos cosas en una sola llamada.
 */
export async function compartirPdf(tipo, numero, { cliente, total, moneda, etiqueta = null } = {}) {
    const bytes = await pdfDe(tipo, numero);

    if (! nativo) {
        // En el navegador de desarrollo no hay hoja de compartir: se descarga,
        // que es lo más cercano a «ahora haz algo con este archivo».
        const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = nombreArchivo(tipo, numero, etiqueta);
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 60_000);
        return { compartido: false, motivo: 'navegador' };
    }

    const { uri } = await escribir(tipo, numero, bytes, etiqueta);

    await Share.share({
        title: rotulo(tipo, numero, etiqueta),
        text: mensaje(tipo, numero, { cliente, total, moneda, etiqueta }),
        files: [uri],
        dialogTitle: 'Enviar ' + rotulo(tipo, numero, etiqueta).toLowerCase(),
    });

    return { compartido: true };
}

/**
 * El mensaje que acompaña al archivo.
 *
 * Lo escribe la app y no el vendedor por lo mismo que el mensaje de soporte:
 * quien está en la puerta de una empresa con el cliente esperando no redacta un
 * saludo, manda el archivo pelado y queda como un correo automático.
 */
function mensaje(tipo, numero, { cliente, total, moneda, etiqueta = null } = {}) {
    const lineas = [];

    lineas.push(cliente ? `Estimados ${cliente}:` : 'Estimados:');
    lineas.push('');
    lineas.push(`Adjunto ${rotulo(tipo, numero, etiqueta).toLowerCase()}.`);

    if (total) {
        lineas.push(`Total: ${moneda || '$'} ${Number(total).toLocaleString('es-CL')}`);
    }

    lineas.push('', 'Quedo atento a cualquier consulta.');

    return lineas.join('\n');
}

function rotulo(tipo, numero, etiqueta = null) {
    return TIPOS[tipo].rotulo + ' N° ' + (etiqueta ?? numero);
}

/**
 * Deja el PDF en un archivo que otras apps puedan leer.
 *
 * `Directory.Cache` y no `Documents`: es un archivo de paso, lo borra Android
 * cuando necesita espacio, y no ensucia la carpeta de documentos del teléfono
 * con una copia por cada vez que el vendedor mandó la misma cotización.
 */
async function escribir(tipo, numero, bytes, etiqueta = null) {
    return Filesystem.writeFile({
        path: nombreArchivo(tipo, numero, etiqueta),
        data: base64(bytes),
        directory: Directory.Cache,
    });
}

/** `Filesystem` recibe base64, no bytes. */
function base64(bytes) {
    let binario = '';
    const trozo = 0x8000;   // de a 32 KB: `apply` con 60.000 argumentos revienta la pila
    const a = new Uint8Array(bytes);

    for (let i = 0; i < a.length; i += trozo) {
        binario += String.fromCharCode.apply(null, a.subarray(i, i + trozo));
    }

    return btoa(binario);
}

/** Se queda con los más usados y tira el resto. */
async function limpiar() {
    const todos = await idb.todos('pdfs');
    if (todos.length <= TOPE) return;

    const sobran = todos
        .sort((a, b) => (b.usado || '').localeCompare(a.usado || ''))
        .slice(TOPE);

    for (const p of sobran) {
        await idb.borrar('pdfs', p.clave);
    }
}

/** Cuánto ocupan los documentos guardados, para la pantalla de Cuenta. */
export async function pesoGuardado() {
    const todos = await idb.todos('pdfs');

    return {
        cantidad: todos.length,
        bytes: todos.reduce((s, p) => s + (p.bytes?.byteLength || 0), 0),
    };
}
