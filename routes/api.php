<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvisoController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\CotizacionController;
use App\Http\Controllers\Api\FacturaController;
use App\Http\Controllers\Api\IdentidadController;
use App\Http\Controllers\Api\NotaVentaController;
use App\Http\Controllers\Api\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
 * API de la app móvil. Token Bearer emitido en /api/login.
 *
 * Convención: todo lo administrativo cuelga de /api/admin y exige rol admin,
 * porque no hay panel web — la administración se hace desde el teléfono.
 */

Route::get('/ping', [AuthController::class, 'ping']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth.api')->group(function () {
    Route::get('/bootstrap', [AuthController::class, 'bootstrap']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Buzón personal: lo que le llegó a quien está mirando el teléfono.
    // No confundir con /admin/notificaciones/bitacora, que es todo lo enviado.
    Route::get('/avisos', [AvisoController::class, 'index']);

    /*
     * Maestros para trabajar sin señal. `/catalogo` dice qué hay y cuánto pesa;
     * `/catalogo/{recurso}` lo sirve por páginas. El orquestador está en el
     * teléfono (`mobile/src/sync.js`): el servidor no recuerda qué bajó quién.
     */
    Route::get('/catalogo', [CatalogoController::class, 'index']);
    Route::get('/catalogo/{recurso}', [CatalogoController::class, 'show']);

    // Clientes: lo único de Softland que la app escribe además del flujo de venta.
    Route::get('/clientes/{codigo}', [ClienteController::class, 'show']);
    Route::post('/clientes', [ClienteController::class, 'store']);
    Route::put('/clientes/{codigo}', [ClienteController::class, 'update']);

    /*
     * El flujo de ventas. Las cotizaciones y las notas de venta se leen del
     * maestro descargado — el teléfono ya las tiene — y se escriben por aquí.
     *
     * `client_uuid` viaja en el cuerpo del alta y es lo que hace idempotente el
     * reenvío: un teléfono que perdió la respuesta reintenta y recibe el mismo
     * número, no una cotización nueva.
     */
    // La identidad corporativa la usa cualquiera — es la marca de sus propios
    // documentos — pero sólo el admin la cambia. Por eso el logo se lee aquí y
    // se escribe bajo /admin.
    Route::get('/identidad', [IdentidadController::class, 'index']);
    Route::get('/identidad/logo', [IdentidadController::class, 'logo']);

    Route::get('/cotizaciones/{numero}', [CotizacionController::class, 'show'])->whereNumber('numero');
    Route::post('/cotizaciones', [CotizacionController::class, 'store']);
    Route::put('/cotizaciones/{numero}', [CotizacionController::class, 'update'])->whereNumber('numero');
    Route::post('/cotizaciones/{numero}/enviar', [CotizacionController::class, 'enviar'])->whereNumber('numero');
    Route::post('/cotizaciones/{numero}/perder', [CotizacionController::class, 'perder'])->whereNumber('numero');
    Route::post('/cotizaciones/{numero}/anular', [CotizacionController::class, 'anular'])->whereNumber('numero');
    Route::delete('/cotizaciones/{numero}', [CotizacionController::class, 'destroy'])->whereNumber('numero');
    Route::post('/cotizaciones/{numero}/seguimientos', [CotizacionController::class, 'seguimiento'])->whereNumber('numero');
    Route::post('/cotizaciones/{numero}/nota-venta', [CotizacionController::class, 'convertir'])->whereNumber('numero');
    // Qué queda por convertir. Una cotización en `V` puede tener saldo: se
    // llevó siete líneas de ocho y la octava sigue esperando.
    Route::get('/cotizaciones/{numero}/saldo', [CotizacionController::class, 'saldo'])->whereNumber('numero');
    // El papel. `pdf` lo dibuja y lo guarda como emisión; `compartido` deja
    // constancia de que salió por un camino que el servidor no controla — la
    // hoja de compartir de Android, WhatsApp, una impresora.
    Route::get('/cotizaciones/{numero}/pdf', [CotizacionController::class, 'pdf'])->whereNumber('numero');
    Route::post('/cotizaciones/{numero}/compartido', [CotizacionController::class, 'compartido'])->whereNumber('numero');

    // Facturar. Es lo único de la app que gasta algo que no se recupera, así
    // que la propuesta dice cuántos folios quedan antes de que nadie teclee.
    Route::get('/notas-venta/{numero}/facturar', [FacturaController::class, 'propuesta'])->whereNumber('numero');
    Route::post('/facturas', [FacturaController::class, 'store']);
    Route::get('/facturas/{tipo}/{numero}', [FacturaController::class, 'show'])
        ->where('tipo', '[FBN]')->whereNumber('numero');
    // Anular una factura es emitir la nota de crédito que la devuelve entera.
    // Las líneas las arma el servidor desde la factura: anular es devolver lo
    // que se facturó, todo y tal cual.
    Route::get('/facturas/{tipo}/{numero}/nota-credito', [FacturaController::class, 'propuestaNotaCredito'])
        ->where('tipo', '[FB]')->whereNumber('numero');
    Route::post('/facturas/{tipo}/{numero}/nota-credito', [FacturaController::class, 'notaCredito'])
        ->where('tipo', '[FB]')->whereNumber('numero');
    // Mandarlo al SII. Es lo más irreversible de la app: un documento escrito
    // en inventario se corrige, uno que ya viajó existe para el fisco.
    Route::post('/facturas/{tipo}/{numero}/sii', [FacturaController::class, 'enviar'])
        ->where('tipo', '[FBN]')->whereNumber('numero');
    Route::get('/facturas/{tipo}/{numero}/sii', [FacturaController::class, 'estadoSii'])
        ->where('tipo', '[FBN]')->whereNumber('numero');

    Route::get('/notas-venta/aprobaciones', [NotaVentaController::class, 'pendientes']);
    Route::get('/notas-venta/{numero}', [NotaVentaController::class, 'show'])->whereNumber('numero');
    Route::post('/notas-venta', [NotaVentaController::class, 'store']);
    Route::put('/notas-venta/{numero}', [NotaVentaController::class, 'update'])->whereNumber('numero');
    Route::post('/notas-venta/{numero}/aprobacion', [NotaVentaController::class, 'resolver'])->whereNumber('numero');
    Route::post('/notas-venta/{numero}/anular', [NotaVentaController::class, 'anular'])->whereNumber('numero');
    Route::delete('/notas-venta/{numero}', [NotaVentaController::class, 'destroy'])->whereNumber('numero');
    Route::post('/notas-venta/{numero}/enviar', [NotaVentaController::class, 'enviar'])->whereNumber('numero');
    Route::get('/notas-venta/{numero}/pdf', [NotaVentaController::class, 'pdf'])->whereNumber('numero');
    Route::post('/notas-venta/{numero}/compartido', [NotaVentaController::class, 'compartido'])->whereNumber('numero');

    Route::middleware('rol:admin')->prefix('admin')->group(function () {
        // Usuarios
        Route::get('/usuarios', [UsuarioController::class, 'index']);
        Route::get('/usuarios/opciones', [UsuarioController::class, 'opciones']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        Route::put('/usuarios/{id}', [UsuarioController::class, 'update']);
        Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);
        Route::get('/usuarios/{id}/sesiones', [UsuarioController::class, 'sesiones']);
        Route::delete('/usuarios/{id}/sesiones', [UsuarioController::class, 'revocarSesiones']);

        // Configuración del servidor
        Route::get('/configuracion', [ConfiguracionController::class, 'index']);
        Route::put('/configuracion/conexion', [ConfiguracionController::class, 'guardarConexion']);
        Route::put('/configuracion/correo', [ConfiguracionController::class, 'guardarCorreo']);
        Route::put('/configuracion/facturacion', [ConfiguracionController::class, 'guardarFacturacion']);
        Route::post('/configuracion/correo/probar', [ConfiguracionController::class, 'probarCorreo']);

        // Identidad corporativa: datos de la empresa, logo y condiciones que
        // salen impresos en cotizaciones, notas de venta y lo que venga después.
        Route::put('/identidad', [IdentidadController::class, 'guardar']);
        Route::post('/identidad/logo', [IdentidadController::class, 'subirLogo']);
        Route::delete('/identidad/logo', [IdentidadController::class, 'borrarLogo']);

        // Notificaciones
        Route::get('/notificaciones', [ConfiguracionController::class, 'notificaciones']);
        Route::get('/notificaciones/bitacora', [ConfiguracionController::class, 'bitacora']);
        Route::put('/notificaciones/{evento}', [ConfiguracionController::class, 'guardarNotificacion']);
    });
});
