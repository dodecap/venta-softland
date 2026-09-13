<?php

namespace App\Console\Commands;

use App\Services\Notificaciones\Notificador;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Crea el esquema `ventas` en la base Softland conectada y corre las migraciones.
 * Idempotente: si el esquema o las tablas ya existen, no falla.
 *
 *   php artisan ventas:install
 */
class VentasInstall extends Command
{
    protected $signature = 'ventas:install';

    protected $description = 'Crea el esquema ventas y corre las migraciones sobre la base Softland';

    public function handle(Notificador $notificador): int
    {
        $conn = DB::connection('softland');
        $this->info('Base: '.$conn->getDatabaseName());

        // El esquema tiene que existir ANTES de crear ventas.migrations.
        $conn->statement("IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = 'ventas') EXEC('CREATE SCHEMA ventas')");
        $this->line('Esquema ventas: OK');

        $code = Artisan::call('migrate', [
            '--database' => 'softland',
            '--force' => true,
        ], $this->getOutput());

        if ($code !== 0) {
            return self::FAILURE;
        }

        $notificador->sembrarReglas();
        $this->line('Reglas de notificación: sembradas');

        return self::SUCCESS;
    }
}
