<?php

use App\Http\Controllers\SetupController;
use App\Support\SoftlandConfig;
use Illuminate\Support\Facades\Route;

/*
 * Este proyecto no tiene interfaz web: se opera desde la app Android.
 * Lo único que se sirve por HTML es la instalación, que necesariamente ocurre
 * antes de que exista cualquier usuario o dispositivo.
 */

Route::get('/setup', [SetupController::class, 'show'])->name('setup');
Route::post('/setup', [SetupController::class, 'store']);
Route::get('/setup/listo', [SetupController::class, 'listo']);

Route::get('/', fn () => view('estado', [
    'base' => SoftlandConfig::load()['database'] ?? '',
]));
