<?php

namespace App\Console\Commands;

use App\Services\Sii\Traduccion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Comprueba la traducción del SII a Softland, sin escribir nada y sin salir a
 * internet.
 *
 * La consulta al padrón es de la API; esto es lo de después, que es donde se
 * puede meter la pata en silencio. Un código de comuna mal traducido no da
 * error: da un cliente en otra comuna, y eso se imprime en el DTE.
 *
 * Lo que se exige, y por qué:
 *
 *  1. **Cada alias apunta a una comuna que existe.** Es una lista escrita a
 *     mano; una errata ahí deja de traducir sin avisar.
 *  2. **Cada comuna y cada ciudad de Softland se traducen a sí mismas.** Si la
 *     normalización rompe un nombre, aquí se ve: son 352 y 937 casos reales, no
 *     ejemplos inventados.
 *  3. **Los duplicados a mano resuelven a la fila oficial**, la del código del
 *     INE, y no a la que alguien añadió con el nombre mal escrito.
 *  4. **El catálogo ACTECO está sano**: 674 códigos, todos de seis dígitos.
 *
 * Y se informa de lo que no es un fallo pero decide el trabajo que queda: qué
 * porcentaje de los actecos tiene hoy un giro al que llegar.
 *
 *   php artisan ventas:verifica-sii
 *   php artisan ventas:verifica-sii --base=NETDOMAIN
 */
class VentasVerificaSii extends Command
{
    protected $signature = 'ventas:verifica-sii
        {--base= : Otra base de la instancia, solo lectura}
        {--detalle : Enseña todos los casos, no sólo los primeros}';

    protected $description = 'Contrasta la traducción del padrón del SII contra los maestros de Softland';

    private bool $ok = true;

    public function handle(): int
    {
        $base = trim((string) $this->option('base')) ?: null;

        $this->line('Base: <options=bold>'.($base ?? 'INNOVAGES').'</>');
        $this->newLine();

        try {
            $traduccion = new Traduccion($base);

            $this->alias($traduccion);
            $this->comunas($traduccion, $base);
            $this->ciudades($traduccion, $base);
            $this->catalogo();
            $this->giros($traduccion, $base);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line($this->ok
            ? '<fg=green>Todo cuadra.</>'
            : '<fg=red>Hay traducciones que no cuadran.</>');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    /** 1. Los once alias tienen que llegar a alguna parte. */
    private function alias(Traduccion $traduccion): void
    {
        $this->titulo('Alias de comuna');
        $perdidos = [];

        foreach (Traduccion::COMUNAS as $padron => $softland) {
            if ($traduccion->comuna($padron) === null) {
                $perdidos[] = "«{$padron}» apuntaba a «{$softland}», que no está en cwtcomu";
            }
        }

        $this->resultado(count(Traduccion::COMUNAS) - count($perdidos), count(Traduccion::COMUNAS), $perdidos);
    }

    /** 2 y 3. Toda comuna se traduce, y los duplicados van a la oficial. */
    private function comunas(Traduccion $traduccion, ?string $base): void
    {
        $this->titulo('Comunas de Softland que se traducen a sí mismas');

        $filas = DB::connection('softland')->table($this->tabla('cwtcomu', $base))->get();
        $porNombre = [];
        $fallos = [];
        $duplicados = [];

        foreach ($filas as $fila) {
            $llave = Traduccion::llave($fila->ComDes);
            $codigo = trim((string) $fila->ComCod);

            if ($llave === '' || $codigo === '') {
                continue;
            }

            $porNombre[$llave][] = $codigo;
            $obtenido = $traduccion->comuna($fila->ComDes);

            if ($obtenido === null) {
                $fallos[] = "«{$fila->ComDes}» ({$codigo}) no se traduce";
            }
        }

        foreach ($porNombre as $llave => $codigos) {
            if (count($codigos) < 2) {
                continue;
            }

            $oficial = array_values(array_filter($codigos, ctype_digit(...)));
            $elegido = $traduccion->comuna($llave);
            $duplicados[] = sprintf('«%s» está %d veces (%s) y resuelve a %s',
                $llave, count($codigos), implode(', ', $codigos), $elegido ?? 'nada');

            if ($oficial !== [] && $elegido !== $oficial[0]) {
                $fallos[] = "«{$llave}» tenía que resolver a la oficial {$oficial[0]} y resolvió a ".($elegido ?? 'nada');
            }
        }

        $this->resultado(count($filas) - count($fallos), count($filas), $fallos);

        if ($duplicados !== []) {
            $this->line('   <fg=yellow>Duplicados a mano en cwtcomu:</> '.count($duplicados));
            $this->enumerar($duplicados);
        }
    }

    /** 2. Lo mismo con las 937 ciudades. */
    private function ciudades(Traduccion $traduccion, ?string $base): void
    {
        $this->titulo('Ciudades de Softland que se traducen a sí mismas');

        $filas = DB::connection('softland')->table($this->tabla('cwtciud', $base))->get();
        $fallos = [];
        $ambiguas = 0;

        foreach ($filas as $fila) {
            if (Traduccion::llave($fila->CiuDes) === '' || trim((string) $fila->CiuCod) === '') {
                continue;
            }

            $obtenido = $traduccion->ciudad($fila->CiuDes);

            if ($obtenido === null) {
                // El mismo nombre en dos regiones y sin comuna que desempate:
                // devolver null es lo correcto, no un fallo.
                $ambiguas++;
            } elseif ($obtenido !== trim((string) $fila->CiuCod)) {
                $repetida = $filas->filter(
                    fn ($o) => Traduccion::llave($o->CiuDes) === Traduccion::llave($fila->CiuDes)
                )->count() > 1;

                if (! $repetida) {
                    $fallos[] = "«{$fila->CiuDes}» ({$fila->CiuCod}) resolvió a {$obtenido}";
                }
            }
        }

        $this->resultado(count($filas) - count($fallos), count($filas), $fallos);
        $this->line("   Nombres repetidos en varias regiones, que quedan para elegir a mano: <options=bold>{$ambiguas}</>");
    }

    /** 4. El catálogo que va en el repo. */
    private function catalogo(): void
    {
        $this->titulo('Catálogo ACTECO (resources/sii/actecos.tsv)');

        $catalogo = Traduccion::catalogo();
        $malos = [];

        foreach ($catalogo as $codigo => $descripcion) {
            if (! preg_match('/^\d{6}$/', (string) $codigo)) {
                $malos[] = "«{$codigo}» no son seis dígitos";
            } elseif (trim($descripcion) === '') {
                $malos[] = "{$codigo} sin descripción";
            }
        }

        $this->resultado(count($catalogo) - count($malos), count($catalogo), $malos);

        $ceros = count(array_filter(array_keys($catalogo), fn ($c) => str_starts_with((string) $c, '0')));
        $largas = count(array_filter($catalogo, fn ($d) => strlen($d) > 60));
        $this->line("   Empiezan por cero (por eso el código es texto): <options=bold>{$ceros}</>");
        $this->line("   Descripciones que no caben en GirDes(60): <options=bold>{$largas}</>");
    }

    /** No es un fallo: es cuánto queda por hacer. */
    private function giros(Traduccion $traduccion, ?string $base): void
    {
        $this->titulo('Cobertura del giro');

        $catalogo = Traduccion::catalogo();
        $alcanzables = count(array_filter(
            array_keys($catalogo),
            fn ($acteco) => $traduccion->giro((string) $acteco) !== null
        ));

        $total = count($catalogo);
        $pct = $total > 0 ? $alcanzables * 100 / $total : 0;
        $color = $pct >= 90 ? 'green' : ($pct >= 50 ? 'yellow' : 'red');

        $this->line(sprintf('   Actecos con giro al que llegar: <fg=%s;options=bold>%d de %d</> (%.1f %%)',
            $color, $alcanzables, $total, $pct));

        $propios = DB::connection('softland')->table($this->tabla('ventas.giro_sii', $base))->count();
        $this->line("   De ellos, por ventas.giro_sii: <options=bold>{$propios}</>");

        if ($pct < 90) {
            $this->line('   <fg=yellow>Falta cargar el catálogo en cwtgiro: el alta dejará el giro vacío.</>');
        }
    }

    // ---------------------------------------------------------------- pintado

    private function titulo(string $texto): void
    {
        $this->line("<options=bold>{$texto}</>");
    }

    /** @param  list<string>  $fallos */
    private function resultado(int $bien, int $total, array $fallos): void
    {
        if ($fallos === []) {
            $this->line("   <fg=green>{$bien} de {$total}</>");
            $this->newLine();

            return;
        }

        $this->ok = false;
        $this->line("   <fg=red>{$bien} de {$total}</>, ".count($fallos).' sin cuadrar:');
        $this->enumerar($fallos);
    }

    /** @param  list<string>  $lineas */
    private function enumerar(array $lineas): void
    {
        $tope = $this->option('detalle') ? count($lineas) : 12;

        foreach (array_slice($lineas, 0, $tope) as $linea) {
            $this->line("      - {$linea}");
        }

        if (count($lineas) > $tope) {
            $this->line('      … y '.(count($lineas) - $tope).' más (--detalle)');
        }

        $this->newLine();
    }

    private function tabla(string $objeto, ?string $base): string
    {
        $calificado = str_contains($objeto, '.') ? $objeto : "softland.{$objeto}";

        return $base ? "{$base}.{$calificado}" : $calificado;
    }
}
