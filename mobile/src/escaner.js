import { Capacitor } from '@capacitor/core';
import { BarcodeScanner, BarcodeFormat, LensFacing } from '@capacitor-mlkit/barcode-scanning';

/**
 * Leer códigos de barras con la cámara.
 *
 * ## Por qué un módulo y no llamar al plugin desde la pantalla
 *
 * Porque la cámara es de las pocas cosas de esta app que se pueden quedar
 * encendidas. El plugin dibuja el vídeo **por detrás** del navegador y apaga el
 * fondo de la página para que se vea; si una pantalla se va sin llamar a
 * `stopScan()`, el vendedor se queda con la app transparente y la linterna
 * puesta. Aquí hay un solo escáner vivo a la vez y el cierre pasa siempre por
 * el mismo sitio, pase lo que pase.
 *
 * ## El modelo va dentro del APK
 *
 * El plugin trae dos caminos: `com.google.mlkit:barcode-scanning`, que empotra
 * el modelo en el instalable, y `play-services-code-scanner`, que lo descarga
 * de Google Play la primera vez. Esta app usa el primero —`startScan()`, nunca
 * `scanGoogleCode()`— porque un vendedor en una bodega sin señal es
 * exactamente el caso que esto viene a resolver, y un escáner que la primera
 * vez dice «descargando» es un escáner que no está.
 *
 * ## Qué formatos
 *
 * Los del comercio (EAN, UPC, Code 128/39/93, ITF) y los cuadrados que a veces
 * llevan los equipos de marca (QR, Data Matrix). Acotar la lista no es manía:
 * cuantos menos formatos, menos trabajo por fotograma y antes suena el pitido.
 */
const FORMATOS = [
    BarcodeFormat.Ean13,
    BarcodeFormat.Ean8,
    BarcodeFormat.UpcA,
    BarcodeFormat.UpcE,
    BarcodeFormat.Code128,
    BarcodeFormat.Code39,
    BarcodeFormat.Code93,
    BarcodeFormat.Itf,
    BarcodeFormat.Codabar,
    BarcodeFormat.QrCode,
    BarcodeFormat.DataMatrix,
];

/*
 * Un código bajo la cámara se lee treinta veces por segundo. Sin esto, apuntar
 * a una caja durante medio segundo agregaría quince líneas.
 */
const REBOTE_MS = 1500;

let vivo = null;

/** ¿Este aparato puede escanear? En el navegador de desarrollo, no. */
export async function disponible() {
    if (! Capacitor.isNativePlatform()) return false;

    try {
        const { supported } = await BarcodeScanner.isSupported();

        return !! supported;
    } catch {
        return false;
    }
}

/**
 * Pide permiso de cámara. Devuelve `true` si quedó concedido.
 *
 * Se pregunta antes de encender nada: encender la cámara y que el sistema
 * tape la pantalla con su propio diálogo deja al vendedor mirando un rectángulo
 * negro sin saber qué pasó.
 */
export async function permitido() {
    let { camera } = await BarcodeScanner.checkPermissions();

    if (camera !== 'granted' && camera !== 'limited') {
        ({ camera } = await BarcodeScanner.requestPermissions());
    }

    return camera === 'granted' || camera === 'limited';
}

/**
 * Enciende la cámara y llama a `alLeer(codigo)` por cada código nuevo.
 *
 * Devuelve el mando del escáner. Quien lo abre está obligado a cerrarlo.
 */
export async function abrir({ alLeer, alFallar = null } = {}) {
    // Sólo uno vivo. Abrir dos veces —dos toques rápidos en el botón— dejaría
    // una cámara encendida sin nadie que la apague.
    await cerrar();

    if (! await permitido()) {
        const e = new Error('Sin permiso para usar la cámara.');
        e.sinPermiso = true;
        throw e;
    }

    let ultimo = '';
    let ultimoEn = 0;
    let pausado = false;

    const oyentes = [];

    oyentes.push(await BarcodeScanner.addListener('barcodesScanned', ({ barcodes }) => {
        if (pausado) return;

        for (const b of barcodes || []) {
            const codigo = (b?.rawValue || b?.displayValue || '').trim();
            if (! codigo) continue;

            const ahora = Date.now();
            if (codigo === ultimo && ahora - ultimoEn < REBOTE_MS) continue;

            ultimo = codigo;
            ultimoEn = ahora;

            // El aviso es al tacto y no al oído: en una bodega con ruido el
            // pitido no se oye, y en una oficina en silencio molesta. Se carga
            // al vuelo, como en `BotonCrear.vue`: no vale la pena tener el
            // paquete en el arranque para un golpecito.
            import('@capacitor/haptics')
                .then(({ Haptics, ImpactStyle }) => Haptics.impact({ style: ImpactStyle.Medium }))
                .catch(() => {});

            alLeer?.(codigo);

            return;
        }
    }));

    if (alFallar) {
        oyentes.push(await BarcodeScanner.addListener('scanError', ({ message }) => alFallar(message)));
    }

    // El vídeo va por detrás del navegador: sin esto se ve la página opaca y
    // encima nada. La clase la lee `style.css`.
    document.body.classList.add('escaneando');

    try {
        await BarcodeScanner.startScan({ formats: FORMATOS, lensFacing: LensFacing.Back });
    } catch (e) {
        /*
         * Si la cámara no arranca hay que deshacer lo de arriba a mano: todavía
         * no hay mando que cerrar, y sin esto se queda la página transparente
         * con dos oyentes puestos. Es exactamente lo que este módulo existe
         * para que no pase.
         */
        for (const o of oyentes) { try { await o.remove(); } catch { /* ya no estaba */ } }
        document.body.classList.remove('escaneando');

        throw e;
    }

    let linterna = false;

    vivo = {
        /** Deja de aceptar lecturas sin apagar la cámara. */
        pausar: () => { pausado = true; },
        /**
         * Vuelve a aceptar, olvidando lo último leído: dos unidades del mismo
         * producto se escanean dos veces, y la segunda no es un rebote.
         */
        reanudar: () => { pausado = false; ultimo = ''; ultimoEn = 0; },

        linternaDisponible: async () => {
            try {
                const { available } = await BarcodeScanner.isTorchAvailable();

                return !! available;
            } catch {
                return false;
            }
        },
        linternaEncendida: () => linterna,
        alternarLinterna: async () => {
            await BarcodeScanner.toggleTorch();
            linterna = ! linterna;

            return linterna;
        },

        cerrar: async () => {
            pausado = true;
            for (const o of oyentes) { try { await o.remove(); } catch { /* ya no estaba */ } }
            // Apagarla explícitamente: `stopScan()` la deja como estaba y una
            // linterna encendida se come la batería sin que se vea por qué.
            if (linterna) { try { await BarcodeScanner.disableTorch(); } catch { /* da igual */ } }
            try { await BarcodeScanner.stopScan(); } catch { /* ya estaba parado */ }
            document.body.classList.remove('escaneando');
        },
    };

    return vivo;
}

/** Apaga el escáner que hubiera. Es seguro llamarlo sin ninguno abierto. */
export async function cerrar() {
    const q = vivo;
    vivo = null;
    if (q) await q.cerrar();
}
