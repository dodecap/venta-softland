<?php

use App\Http\Controllers\DescargaController;
use App\Http\Controllers\SetupController;
use App\Support\SoftlandConfig;
use Illuminate\Support\Facades\Route;

/*
 * Este proyecto no tiene interfaz web: se opera desde la app Android.
 * Lo único que se sirve por HTML son las dos cosas que pasan **antes** de que
 * la app exista en el teléfono: instalar el servidor e instalar la app.
 */

Route::get('/setup', [SetupController::class, 'show'])->name('setup');
Route::post('/setup', [SetupController::class, 'store']);
Route::get('/setup/listo', [SetupController::class, 'listo']);

// Repartir el APK. La dirección no lleva la versión dentro a propósito:
// entrega siempre el último publicado, así el código QR impreso no caduca.
Route::get('/app', [DescargaController::class, 'pagina'])->name('descarga');
Route::get('/app/qr.svg', [DescargaController::class, 'qr']);
Route::get('/app/apk', [DescargaController::class, 'apk']);

// Sin conexión guardada, `EnsureConfigured` deriva aquí mismo a /setup.
Route::get('/', fn () => view('estado', [
    'base' => SoftlandConfig::load()['database'] ?? '',
]));
