<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Primera petición de una instalación recién descomprimida: no hay `.env`, no
// hay `APP_KEY` y no hay carpetas donde escribir. Y sin eso Laravel no arranca
// ni para enseñar `/setup`, porque su driver de sesiones por omisión es la base
// de datos —justo lo que aún no está configurado— y sin sesión no hay
// formulario. Ver `deploy/arranque.php`.
//
// Cuesta un `is_file()` por petición, y sólo entra la primera vez de todas.
if (! is_file(__DIR__.'/../.env')) {
    require __DIR__.'/../deploy/arranque.php';
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
