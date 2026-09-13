<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvisoController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ConfiguracionController;
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
        Route::post('/configuracion/correo/probar', [ConfiguracionController::class, 'probarCorreo']);

        // Notificaciones
        Route::get('/notificaciones', [ConfiguracionController::class, 'notificaciones']);
        Route::get('/notificaciones/bitacora', [ConfiguracionController::class, 'bitacora']);
        Route::put('/notificaciones/{evento}', [ConfiguracionController::class, 'guardarNotificacion']);
    });
});
