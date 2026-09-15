<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Crea —o rehace— una copia de la base de Softland para probar la escritura.
 *
 * Escribir un documento de venta no se puede ensayar contra INNOVAGES: hay 21
 * triggers sobre `iw_gsaen`, folios CAF de verdad y contabilidad detrás. Y
 * tampoco sirve una base con cuatro tablas sueltas, porque justamente lo que
 * hay que comprobar es que el ERP acepte la fila con todo su aparato encima.
 *
 * Así que se copia entera, por respaldo y restauración. Son 650 MB y tarda
 * segundos. El respaldo se toma con `COPY_ONLY`, que no altera la cadena de
 * respaldos de producción.
 *
 * ## Los frenos
 *
 * Este comando borra una base de datos, así que se pone difícil a propósito:
 *
 *   - se niega a tocar cualquier nombre que parezca de producción;
 *   - exige `--confirmar` para borrar una base que ya existe;
 *   - nunca escribe en el origen, solo lee.
 *
 * `INNOVAGES_TEST` **no** es el destino por defecto: existe, pero es de otro
 * proyecto (una app de rendiciones, esquema `rinde`). Pisarla sería romperle
 * el trabajo a alguien más.
 *
 *   php artisan dte:base-de-pruebas
 *   php artisan dte:base-de-pruebas --confirmar
 */
class DteBaseDePruebas extends Command
{
    protected $signature = 'dte:base-de-pruebas
        {--destino=INNOVAGES_DTE : Nombre de la copia}
        {--confirmar : Necesario si la copia ya existe: se borra y se rehace}';

    protected $description = 'Copia la base de Softland a una base de pruebas, para ensayar la escritura de documentos';

    /** Nombres que este comando no va a tocar ni por equivocación. */
    private const INTOCABLES = ['INNOVAGES', 'NETDOMAIN', 'INNOVAGES_TEST', 'MASTER', 'MSDB', 'MODEL', 'TEMPDB'];

    public function handle(): int
    {
        $origen = DB::connection('softland')->getDatabaseName();
        $destino = strtoupper(trim((string) $this->option('destino')));

        if (! preg_match('/^[A-Z0-9_]{3,60}$/', $destino)) {
            $this->error('El nombre del destino solo admite letras, números y guion bajo.');

            return self::FAILURE;
        }

        if (in_array($destino, self::INTOCABLES, true) || $destino === strtoupper($origen)) {
            $this->error("«{$destino}» no es un destino válido: este comando no toca bases de producción ni ajenas.");

            return self::FAILURE;
        }

        $conn = DB::connection('softland');
        $existe = (int) $conn->select('SELECT COUNT(*) n FROM sys.databases WHERE name = ?', [$destino])[0]->n > 0;

        if ($existe && ! $this->option('confirmar')) {
            $this->warn("La base «{$destino}» ya existe. Se va a borrar y rehacer desde {$origen}.");
            $this->line('Si es lo que quieres, repite con <options=bold>--confirmar</>.');

            return self::FAILURE;
        }

        $archivos = $conn->select('SELECT name, physical_name, type_desc FROM sys.master_files WHERE database_id = DB_ID(?)', [$origen]);

        if ($archivos === []) {
            $this->error("No se pudo leer la estructura de archivos de {$origen}.");

            return self::FAILURE;
        }

        $carpeta = dirname($archivos[0]->physical_name);
        $respaldo = $carpeta.DIRECTORY_SEPARATOR.$destino.'_copia.bak';

        try {
            $this->line("Respaldando {$origen} (solo lectura, COPY_ONLY)…");
            $conn->statement("BACKUP DATABASE [{$origen}] TO DISK = N'{$respaldo}'
                              WITH COPY_ONLY, INIT, COMPRESSION, NAME = N'copia para pruebas de DTE'");

            if ($existe) {
                $this->line("Borrando la copia anterior de {$destino}…");
                $conn->statement("ALTER DATABASE [{$destino}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE");
                $conn->statement("DROP DATABASE [{$destino}]");
            }

            $this->line("Restaurando como {$destino}…");
            $movimientos = [];
            foreach ($archivos as $f) {
                $sufijo = $f->type_desc === 'LOG' ? '_Log.ldf' : '_Data.mdf';
                $nuevo = $carpeta.DIRECTORY_SEPARATOR.$destino.$sufijo;
                $movimientos[] = "MOVE N'{$f->name}' TO N'{$nuevo}'";
            }

            $conn->statement("RESTORE DATABASE [{$destino}] FROM DISK = N'{$respaldo}'
                              WITH ".implode(', ', $movimientos).', RECOVERY, REPLACE');

            // La copia queda en modo SIMPLE: es desechable, no hace falta
            // registro de transacciones para recuperarla a un punto en el tiempo.
            $conn->statement("ALTER DATABASE [{$destino}] SET RECOVERY SIMPLE");
        } catch (Throwable $e) {
            $this->error('Falló la copia: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            // El .bak pesa lo que pesa la base. No se deja tirado en el disco
            // de producción.
            try {
                $conn->statement("EXEC master.dbo.xp_delete_files N'{$respaldo}'");
            } catch (Throwable) {
                $this->warn("No se pudo borrar el respaldo temporal: {$respaldo}");
            }
        }

        $n = $conn->select("SELECT COUNT(*) n FROM {$destino}.INFORMATION_SCHEMA.TABLES")[0]->n;
        $this->newLine();
        $this->info("Listo: {$destino} con {$n} tablas, copia de {$origen}.");
        $this->line('  Es desechable. Rehacerla es volver a correr este comando con --confirmar.');

        return self::SUCCESS;
    }
}
