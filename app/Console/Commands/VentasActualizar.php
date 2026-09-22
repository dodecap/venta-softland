<?php

namespace App\Console\Commands;

use App\Services\Actualizacion\Actualizador;
use App\Services\Actualizacion\Publicacion;
use Illuminate\Console\Command;

/**
 * Traer la versión nueva desde GitHub y ponerla en este servidor.
 *
 *   php artisan ventas:actualizar --comprobar
 *   php artisan ventas:actualizar
 *   php artisan ventas:actualizar --a=0.46.0           (volver atrás)
 *
 * Lo normal es hacerlo desde la app, en Configuración → Versión del servidor.
 * Esto está para cuando la app no se puede abrir —que es justo cuando más
 * falta hace actualizar— y para volver atrás sin depender de nada más que de
 * una consola en el servidor.
 *
 * Nunca se actualiza solo: aquí también hay que decir que sí.
 */
class VentasActualizar extends Command
{
    protected $signature = 'ventas:actualizar
        {--comprobar : Sólo mira si hay versión nueva; no baja ni cambia nada}
        {--a= : Instala esa versión en concreto, aunque sea anterior}
        {--si : No preguntar (para dejarlo escrito en una tarea programada)}';

    protected $description = 'Actualiza el servidor a la última versión publicada';

    public function handle(): int
    {
        $mia = (string) config('app.version');
        $repo = (string) config('actualizacion.repositorio');

        $this->line("  Este servidor: <info>{$mia}</info>");
        $this->line("  Publicaciones: <info>{$repo}</info>");
        $this->newLine();

        try {
            $p = $this->option('a')
                ? Publicacion::deVersion((string) $this->option('a'))
                : Publicacion::ultima();
        } catch (\Throwable $e) {
            $this->error('  '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $p) {
            $this->option('a')
                ? $this->error('  No hay ninguna versión publicada con esa etiqueta.')
                : $this->warn('  Todavía no hay ninguna versión publicada.');

            return $this->option('a') ? self::FAILURE : self::SUCCESS;
        }

        $nueva = $p->version();
        $atras = version_compare($nueva, $mia, '<');

        if ($nueva === $mia) {
            $this->info('  Ya está en la última: nada que hacer.');

            return self::SUCCESS;
        }

        $this->line($atras
            ? "  Se puede volver a la <comment>{$nueva}</comment>."
            : "  Hay versión nueva: <comment>{$nueva}</comment>.");

        if ($p->publicadaEn()) {
            $this->line('  Publicada el '.substr((string) $p->publicadaEn(), 0, 10).'.');
        }

        $this->newLine();
        $this->piezas($p);

        if ($notas = $p->notas()) {
            $this->newLine();
            $this->line('  '.str_replace("\n", "\n  ", $notas));
        }

        $this->newLine();

        if ($this->option('comprobar')) {
            $this->line('  Para instalarla:  php artisan ventas:actualizar');

            return self::SUCCESS;
        }

        if (! $this->option('si') && ! $this->confirm("¿Instalar la {$nueva} ahora?", false)) {
            $this->line('  No se ha tocado nada.');

            return self::SUCCESS;
        }

        $this->newLine();

        try {
            (new Actualizador)->aplicar($p, fn (string $paso) => $this->line("  <info>·</info> {$paso}"));
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('  '.$e->getMessage());
            $this->newLine();
            $this->line('  El servidor puede haber quedado a medias. Vuelve a intentarlo;');
            $this->line('  si no sale, instala la anterior:');
            $this->line("      php artisan ventas:actualizar --a={$mia} --si");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("  Servidor en la {$nueva}.");
        $this->newLine();

        // El APK sólo se nombra si de verdad vino: una publicación que no lo
        // traiga deja los teléfonos en la versión que tuvieran, y decir lo
        // contrario manda a alguien a buscar en /app algo que no está.
        if ($p->pieza('apk')) {
            $this->line('  Falta repartir la app a los teléfonos: el APK de esta versión');
            $this->line('  ya está en /app, y Cuenta avisa del desfase.');
        } else {
            $this->line('  Esta publicación no traía APK: los teléfonos se quedan como estaban.');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function piezas(Publicacion $p): void
    {
        foreach (['servidor' => 'Código', 'vendor' => 'Dependencias', 'apk' => 'App Android'] as $cual => $que) {
            $pieza = $p->pieza($cual);

            if (! $pieza) {
                $this->line("  <comment>[!]</comment> {$que}: no viene en esta publicación");

                continue;
            }

            $mb = round($pieza['bytes'] / 1048576, 1);
            $firma = $pieza['sha256'] ? '' : '  <comment>(sin sha256)</comment>';
            $this->line("  <info>[ok]</info> {$que}: {$pieza['nombre']} — {$mb} MB{$firma}");
        }
    }
}
