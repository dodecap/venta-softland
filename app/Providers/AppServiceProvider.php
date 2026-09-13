<?php

namespace App\Providers;

use App\Support\MailConfig;
use App\Support\SoftlandConnection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Conexión a la base Softland guardada en /setup (no-op si aún no existe).
        SoftlandConnection::apply();

        // SMTP por instalación (no-op si no está configurado → los correos van al log).
        MailConfig::apply();
    }
}
