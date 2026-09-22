<?php

namespace App\Console\Commands;

use App\Services\Softland\Compatibilidad;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ¿Sirve esta base de Softland para esta app?
 *
 *   php artisan ventas:compatibilidad
 *   php artisan ventas:compatibilidad --todo
 *
 * Por omisión sólo se imprime lo que falla, que es lo que hay que mirar; con
 * `--todo` sale también lo que está bien, que es lo que hay que enseñar cuando
 * alguien pregunta «¿y qué comprobaste?».
 *
 * Es de sólo lectura: mira `INFORMATION_SCHEMA` y nada más.
 */
class VentasCompatibilidad extends Command
{
    protected $signature = 'ventas:compatibilidad {--todo : Imprime también lo que está bien}';

    protected $description = 'Comprueba que la base de Softland tenga las tablas y columnas que la app usa';

    public function handle(): int
    {
        try {
            $base = DB::connection('softland')->getDatabaseName();
        } catch (\Throwable $e) {
            $this->error('No se pudo conectar a Softland: '.$e->getMessage());

            return self::FAILURE;
        }

        $informe = (new Compatibilidad)->informe();

        $this->line('<options=bold>Compatibilidad de la base '.$base.'</>');
        $this->newLine();

        $filas = $this->option('todo')
            ? $informe['filas']
            : array_values(array_filter($informe['filas'], fn ($f) => ! $f['ok']));

        if ($filas === []) {
            $this->info('Todo lo que la app usa está en esta base.');
        }

        foreach ($filas as $f) {
            $marca = $f['ok'] ? '<fg=green>[ok]</>' : ($f['esencial'] ? '<fg=red>[X]</>' : '<fg=yellow>[!]</>');
            $this->line($marca.' '.str_pad($f['que'], 34).' '.$f['detalle']);
        }

        $this->newLine();

        if ($informe['ok']) {
            $this->info('Esta base sirve para todo lo que la app hace.');

            return self::SUCCESS;
        }

        foreach ($informe['limita'] as $que) {
            $this->line('   - '.$que);
        }

        if ($informe['esenciales']) {
            $this->warn('La app se puede instalar, pero lo de arriba no va a funcionar.');

            return self::SUCCESS;
        }

        $this->error('Falta algo imprescindible: la app no puede funcionar sobre esta base.');

        return self::FAILURE;
    }
}
