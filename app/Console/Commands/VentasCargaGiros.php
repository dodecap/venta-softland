<?php

namespace App\Console\Commands;

use App\Services\Sii\Traduccion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Carga el catálogo ACTECO vigente en `cwtgiro`.
 *
 * ## Por qué hace falta
 *
 * Porque si no, el alta desde el SII deja el giro vacío casi siempre. El SII
 * devuelve un ACTECO de seis dígitos y `cwtgiro` son descripciones escritas a
 * mano con códigos inventados: de los 674 actecos vigentes, sólo **16** tienen
 * hoy una fila a la que llegar. Seis de cada siete clientes nuevos entrarían
 * sin giro.
 *
 * ## Por qué es un comando y no parte del alta
 *
 * `cwtgiro` es un maestro de Softland, compartido con el escritorio: lo que se
 * meta aquí lo ve el administrativo en su desplegable. Que crezca porque un
 * vendedor tecleó un RUT es un efecto colateral; que crezca porque alguien
 * corrió esto mirando la lista es una decisión. Por eso **no escribe si no se
 * lo pide con `--escribir`**: sin la opción enseña lo que haría y se va.
 *
 * ## Lo que no hace, y es lo más importante
 *
 * **No toca ni una fila que ya exista.** Ni la descripción. Un giro que ya usan
 * clientes tiene el texto que esa gente reconoce —y que sale impreso en el
 * `GiroRecep` de sus DTE—; reescribirlo con la redacción del SII cambiaría
 * documentos de clientes vivos sin que nadie lo hubiera pedido. Los 16 actecos
 * que ya están se quedan exactamente como están.
 *
 * Y **no borra nunca**: `cwtauxi.GirAux` tiene clave foránea contra esta tabla.
 *
 *   php artisan ventas:carga-giros --base=INNOVAGES_DTE
 *   php artisan ventas:carga-giros --base=INNOVAGES_DTE --escribir
 *   php artisan ventas:carga-giros --escribir
 */
class VentasCargaGiros extends Command
{
    protected $signature = 'ventas:carga-giros
        {--base= : Otra base de la instancia; sin esto va a la de producción}
        {--escribir : Escribe de verdad. Sin esto sólo enseña lo que haría}
        {--detalle : Enseña las 674 filas, no una muestra}';

    protected $description = 'Carga los ACTECO vigentes del SII en el maestro de giros de Softland';

    public function handle(): int
    {
        $base = trim((string) $this->option('base')) ?: null;
        $escribir = (bool) $this->option('escribir');
        $tabla = $base ? "{$base}.softland.cwtgiro" : 'softland.cwtgiro';

        $this->line('Base: <options=bold>'.($base ?? 'INNOVAGES').'</>   Tabla: '.$tabla);
        $this->newLine();

        try {
            $existentes = $this->existentes($tabla);
            $antes = count($existentes);

            [$nuevos, $yaEstaban, $recortados] = $this->preparar($existentes);

            $this->line("Giros en el maestro ahora: <options=bold>{$antes}</>");
            $this->line('Actecos del catálogo: <options=bold>'.count(Traduccion::catalogo()).'</>');
            $this->line('   ya estaban: <options=bold>'.count($yaEstaban).'</> — no se tocan');
            $this->line('   se insertan: <fg=cyan;options=bold>'.count($nuevos).'</>');
            $this->line('   con descripción recortada a 60: <options=bold>'.count($recortados).'</>');
            $this->newLine();

            $this->muestra($nuevos, $recortados, $yaEstaban);

            if ($nuevos === []) {
                $this->line('<fg=green>No hay nada que insertar.</>');

                return self::SUCCESS;
            }

            if (! $escribir) {
                $this->newLine();
                $this->warn('Prueba en seco: no se ha escrito nada. Añade --escribir para hacerlo.');

                return self::SUCCESS;
            }

            $this->escribir($tabla, $nuevos);

            $despues = count($this->existentes($tabla, recargar: true));
            $this->newLine();
            $this->line("Giros en el maestro ahora: <fg=green;options=bold>{$despues}</> (eran {$antes})");
            $this->line('<fg=green>Cargado.</>');
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Los `GirCod` que ya están, como texto.
     *
     * Se traen todos y se comparan en PHP en vez de mandar un `whereIn` con los
     * 674: `GirCod` es `varchar` y hay códigos que no son números —`'..3'`, el
     * vacío—, así que en cuanto un binding viaja como entero SQL Server intenta
     * convertir la columna entera y revienta. Son 2.009 filas: traerlas es más
     * barato que la alternativa.
     *
     * @return array<string, true>
     */
    private function existentes(string $tabla, bool $recargar = false): array
    {
        static $cache = null;

        if ($cache !== null && ! $recargar) {
            return $cache;
        }

        $cache = [];

        foreach (DB::connection('softland')->table($tabla)->pluck('GirCod') as $codigo) {
            $cache[trim((string) $codigo)] = true;
        }

        return $cache;
    }

    /**
     * @param  array<string, true>  $existentes
     * @return array{0: list<array{GirCod: string, GirDes: string}>, 1: list<string>, 2: list<array{0: string, 1: string}>}
     */
    private function preparar(array $existentes): array
    {
        $nuevos = [];
        $yaEstaban = [];
        $recortados = [];

        foreach (Traduccion::catalogo() as $fila) {
            if (isset($existentes[$fila['codigo']])) {
                $yaEstaban[] = $fila['codigo'];

                continue;
            }

            $descripcion = Traduccion::recortar($fila['descripcion']);

            if ($descripcion !== $fila['descripcion']) {
                $recortados[] = [$fila['codigo'], $descripcion];
            }

            $nuevos[] = ['GirCod' => $fila['codigo'], 'GirDes' => $descripcion];
        }

        return [$nuevos, $yaEstaban, $recortados];
    }

    /** @param  list<array{GirCod: string, GirDes: string}>  $nuevos */
    private function escribir(string $tabla, array $nuevos): void
    {
        DB::connection('softland')->transaction(function () use ($tabla, $nuevos) {
            // De 200 en 200: SQL Server admite 2.100 parámetros por sentencia y
            // cada fila gasta dos.
            foreach (array_chunk($nuevos, 200) as $lote) {
                DB::connection('softland')->table($tabla)->insert($lote);
            }
        });
    }

    /**
     * @param  list<array{GirCod: string, GirDes: string}>  $nuevos
     * @param  list<array{0: string, 1: string}>  $recortados
     * @param  list<string>  $yaEstaban
     */
    private function muestra(array $nuevos, array $recortados, array $yaEstaban): void
    {
        $detalle = (bool) $this->option('detalle');

        if ($yaEstaban !== []) {
            $this->line('<options=bold>Ya estaban (se dejan como están):</>');
            $this->line('   '.implode(', ', $yaEstaban));
            $this->newLine();
        }

        if ($recortados !== []) {
            $this->line('<options=bold>Descripciones recortadas a 60, cortando en palabra:</>');

            foreach (array_slice($recortados, 0, $detalle ? count($recortados) : 6) as [$codigo, $texto]) {
                $this->line("   {$codigo}  {$texto}");
            }

            if (! $detalle && count($recortados) > 6) {
                $this->line('   … y '.(count($recortados) - 6).' más (--detalle)');
            }

            $this->newLine();
        }

        $this->line('<options=bold>Se insertarían, por ejemplo:</>');

        foreach (array_slice($nuevos, 0, $detalle ? count($nuevos) : 6) as $fila) {
            $this->line("   {$fila['GirCod']}  {$fila['GirDes']}");
        }

        if (! $detalle && count($nuevos) > 6) {
            $this->line('   … y '.(count($nuevos) - 6).' más (--detalle)');
        }
    }
}
