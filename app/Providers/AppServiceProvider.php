<?php

namespace App\Providers;

use App\Http\Respuestas;
use App\Support\ConectorSoftland;
use App\Support\MailConfig;
use App\Support\SoftlandConnection;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Ninguna respuesta de la API se convierte en un 500 por su
        // codificación: ver `App\Http\Respuestas`. Se rebinda el contrato que
        // registra `RoutingServiceProvider`, que es por donde pasan los 153
        // `response()->json(...)` del proyecto.
        $this->app->singleton(
            ResponseFactory::class,
            fn ($app) => new Respuestas($app[ViewFactory::class], $app['redirect']),
        );

        // Conectar a SQL Server puede fallar por un instante y arreglarse
        // solo; eso no puede ser un 500 en el teléfono de un vendedor. Ver
        // `App\Support\ConectorSoftland`. `ConnectionFactory` mira este
        // binding antes de fabricar el conector de serie.
        $this->app->bind('db.connector.sqlsrv', fn () => new ConectorSoftland);
    }

    public function boot(): void
    {
        // Conexión a la base Softland guardada en /setup (no-op si aún no existe).
        SoftlandConnection::apply();

        // SMTP por instalación (no-op si no está configurado → los correos van al log).
        MailConfig::apply();
    }
}
