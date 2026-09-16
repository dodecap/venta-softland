<?php

namespace App\Console\Commands;

use App\Services\Dte\Facturacion;
use App\Services\Dte\ReglasFactura;
use App\Services\Dte\TipoDte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Paso 2: demostrar que sabemos escribir el documento en inventario y
 * facturación.
 *
 * El método es el mismo que funcionó con el timbre. Se toma una factura que
 * Softland ya escribió, se le sacan **sus datos de entrada** —receptor,
 * vendedor, fecha, condición de pago, líneas con producto, cantidad, precio y
 * glosa—, se le pide a nuestro código que escriba ese documento en la base de
 * pruebas, y se comparan **las 168 columnas del encabezado y las 62 de cada
 * línea**, una por una.
 *
 * Lo que aparece es lo que nos falta: la columna que no llenamos, la que
 * llenamos distinto, la que Softland deja nula y nosotros ponemos en cero.
 *
 * ## Qué no se compara, y por qué
 *
 * Hay columnas que no pueden coincidir y compararlas sería ruido:
 *
 *   - `NroInt` y `Folio` son números nuevos: el documento que escribimos es
 *     otro documento, no una copia con el mismo número.
 *   - `FecHoraCreacion` es ahora, no cuando lo grabó Softland.
 *   - `CpbAnoVentas`, `CpbNumVentas`, `ContabVenta`, `ContabCosto` y
 *     `FechaGenDTE` las escriben la centralización y la emisión, que son
 *     procedimientos posteriores. **Que aparezcan vacías en lo nuestro es lo
 *     correcto**, no un fallo.
 *
 * Nunca escribe en producción: exige una base de pruebas, y se niega si el
 * nombre es el de la base real.
 *
 *   php artisan dte:verifica-documento
 *   php artisan dte:verifica-documento --folio=234
 *   php artisan dte:verifica-documento --todos
 */
class DteVerificaDocumento extends Command
{
    protected $signature = 'dte:verifica-documento
        {--base=INNOVAGES_DTE : Base de pruebas donde escribir}
        {--tipo=33 : Tipo de DTE del SII}
        {--folio= : Un folio concreto; si no se da, el último}
        {--todos : Recorre todos los documentos de ese tipo}
        {--limite=25 : Cuántos como máximo, con --todos}
        {--incluir-convertidas : Incluye las nacidas de una nota de venta, que van por otro camino}';

    protected $description = 'Reescribe facturas reales en la base de pruebas y compara columna por columna';

    /** Las que no pueden coincidir, y por qué. */
    private const ESPERADAS = [
        'NroInt' => 'número nuevo',
        'Folio' => 'folio nuevo',
        'FecHoraCreacion' => 'se graba ahora',
        'FechaGenDTE' => 'lo escribe la emisión del DTE',
        'Proceso' => 'lo escribe quien crea el documento',
        'Usuario' => 'lo escribe quien crea el documento',
    ];

    /**
     * Familias de columnas que llena un procedimiento posterior.
     *
     * `Cpb*` es la centralización —de ventas, de costos, de consumos y de
     * **pagos**, que son cuatro momentos distintos— y `Contab*` la marca de que
     * el asiento ya se generó. De las 197 facturas, 190 tienen centralizado el
     * pago; ninguna lo tenía al nacer.
     *
     * Que en lo nuestro salgan vacías es lo correcto, no un fallo: escribirlas
     * sería decirle a la contabilidad que un asiento existe cuando no.
     */
    private const DE_PROCESOS_POSTERIORES = ['Cpb', 'Contab'];

    /**
     * Diferencias conocidas y explicadas: se cuentan y se muestran, pero no
     * hacen fallar la comprobación.
     *
     * Todas salen de que Softland **no es consistente consigo mismo** a lo
     * largo de los años. Escribir lo mismo que la minoría histórica sería
     * copiar el error; lo que se hace es seguir el comportamiento actual y
     * dejar anotado por qué difiere del pasado.
     */
    private const TOLERADAS = [
        'línea.CodiCC' => 'Softland es inconsistente consigo mismo; seguimos lo que hace en la gran mayoría de cada tipo',
        'línea.NVCorrelaOC' => 'Softland dejó de escribir el «0» en agosto de 2024; desde entonces lo deja vacío, como nosotros',
        'línea.Equivalencia' => 'una línea lo guarda en 0 pero calcula como si fuera 1; guardamos el 1, que es lo que el número significa',
    ];

    /**
     * Diferencia máxima, en pesos, que se acepta como redondeo.
     *
     * De 209 documentos hay **dos** cuya línea guarda decimales en vez de peso
     * entero. Los otros 207 van redondeados, y es lo que escribimos. Menos de un
     * peso de diferencia es eso y no otra cosa; un peso o más sería un error de
     * cálculo y tiene que saltar.
     */
    private const REDONDEO = 1.0;

    public function handle(): int
    {
        $base = strtoupper(trim((string) $this->option('base')));
        $produccion = strtoupper(DB::connection('softland')->getDatabaseName());

        if ($base === '' || $base === $produccion) {
            $this->error("Este comando escribe documentos: necesita una base de pruebas, no «{$produccion}».");
            $this->line('Créala con <options=bold>php artisan dte:base-de-pruebas</>.');

            return self::FAILURE;
        }

        if ((int) DB::connection('softland')->select('SELECT COUNT(*) n FROM sys.databases WHERE name = ?', [$base])[0]->n === 0) {
            $this->error("La base de pruebas «{$base}» no existe. Créala con «php artisan dte:base-de-pruebas».");

            return self::FAILURE;
        }

        $tipo = TipoDte::tryFrom((int) $this->option('tipo'));

        if (! $tipo) {
            $this->error('Tipo de DTE desconocido.');

            return self::FAILURE;
        }

        [$letra, $sub] = $tipo->claveSoftland();
        $this->line("<options=bold>{$tipo->nombre()}</> — escribiendo en {$base}, leyendo de {$produccion}");
        $this->newLine();

        $originales = $this->originales($letra, $sub);

        if ($originales === []) {
            $this->warn('No hay documentos de ese tipo contra los que contrastar.');

            return self::FAILURE;
        }

        $escritos = 0;
        $identicos = 0;
        $diferencias = [];

        foreach ($originales as $o) {
            [$estado, $difs] = $this->contrasta($tipo, $o, $base);

            if ($estado === null) {
                continue; // ni siquiera se pudo escribir
            }

            $escritos++;
            $estado ? $identicos++ : null;

            foreach ($difs as $col => $texto) {
                $diferencias[$col][] = $texto;
            }
        }

        $this->newLine();

        if ($escritos === 0) {
            $this->error('Ningún documento se pudo escribir.');

            return self::FAILURE;
        }

        if ($diferencias === []) {
            $this->info("Los {$escritos} documentos se reescriben con las mismas ".
                count((array) $originales[0]).' columnas que escribió Softland.');

            return self::SUCCESS;
        }

        $nuevas = array_diff_key($diferencias, self::TOLERADAS);
        $conocidas = array_intersect_key($diferencias, self::TOLERADAS);

        $this->line("Escritos {$escritos}, idénticos {$identicos}.");

        if ($conocidas !== []) {
            $this->newLine();
            $this->line('<options=bold>Diferencias conocidas</> (no son fallos):');
            foreach ($conocidas as $col => $casos) {
                $this->line(sprintf('  <fg=cyan>%-24s</> %d de %d — %s',
                    $col, count($casos), $escritos, self::TOLERADAS[$col]));
            }
        }

        if ($nuevas === []) {
            $this->newLine();
            $this->info("Los {$escritos} documentos se reescriben igual que Softland, salvo lo conocido.");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('Columnas que no calzan:');

        foreach ($nuevas as $col => $casos) {
            $this->line(sprintf('  <fg=yellow>%-24s</> %d de %d',
                $col, count($casos), $escritos));
            foreach (array_slice(array_unique($casos), 0, 3) as $caso) {
                $this->line('      '.OutputFormatter::escape($caso));
            }
        }

        return self::FAILURE;
    }

    /** @return array<int, object> */
    private function originales(string $letra, string $sub): array
    {
        $q = DB::connection('softland')->table('softland.iw_gsaen')
            ->where('Tipo', $letra)->where('SubTipoDocto', $sub)
            ->orderByDesc('Folio');

        if ($folio = $this->option('folio')) {
            $q->where('Folio', (int) $folio);
        } else {
            $q->limit($this->option('todos') ? (int) $this->option('limite') : 1);
        }

        return $q->get()->all();
    }

    /**
     * Escribe el documento en la base de pruebas y lo compara con el original.
     *
     * @return array{0: bool|null, 1: array<string, string>}  null si no se pudo escribir
     */
    private function contrasta(TipoDte $tipo, object $orig, string $base): array
    {
        $etiqueta = sprintf('folio %-8s', $orig->Folio);

        try {
            $lineasOrig = DB::connection('softland')->table('softland.iw_gmovi')
                ->where('Tipo', $orig->Tipo)->where('NroInt', $orig->NroInt)
                ->orderBy('Linea')->get()->all();

            if ($lineasOrig === []) {
                $this->line("  {$etiqueta} <fg=yellow>sin líneas en iw_gmovi</>");

                return [null, []];
            }

            // Las nacidas convirtiendo una nota de venta van por otro camino:
            // arrastran los decimales de la NV en vez de redondear a peso, y
            // llevan `Orden` y `nvCorrela`. Son **2 de 197** y ese camino
            // todavía no está escrito. Compararlas contra el que sí está sería
            // medir otra cosa.
            $deNotaVenta = array_filter($lineasOrig, fn ($l) => (int) ($l->nvCorrela ?? 0) > 0);

            if ($deNotaVenta !== [] && ! $this->option('incluir-convertidas')) {
                $this->line("  {$etiqueta} <fg=cyan>convertida de nota de venta; ese camino aún no se escribe</>");

                return [null, []];
            }

            // Se escribe, se lee y se deshace. Dentro de la misma transacción
            // vemos lo nuestro sin haberlo confirmado, así que la comparación es
            // real y no queda nada: ni el documento, ni —sobre todo— el folio.
            //
            // Eso último importa más de lo que parece. El repartidor de Softland
            // reserva el folio en `dte_doccab` al entregarlo, y de factura queda
            // **uno solo libre**, el 235. Sin deshacer, la primera corrida se lo
            // come y la segunda ya no tiene con qué probar.
            $conn = DB::connection('softland');
            $conn->beginTransaction();

            try {
                // Con la llave del receptor **encendida a la fuerza**: lo que
                // se está reproduciendo son los documentos históricos de
                // INNOVAGES, que son comisiones y van a un cliente distinto del
                // de su nota de venta. Con la llave como nace —apagada— no se
                // podrían reescribir, y esto comprueba el escritor, no la
                // configuración de la empresa.
                $escrito = (new Facturacion($base, new ReglasFactura(true)))->escribir(
                    $this->spec($tipo, $orig, $lineasOrig)
                );

                $nuestro = $conn->table("{$base}.softland.iw_gsaen")
                    ->where('Tipo', $escrito['tipo'])->where('NroInt', $escrito['nroint'])->first();

                $difs = $this->compara((array) $orig, (array) $nuestro);

                $nuestrasLineas = $conn->table("{$base}.softland.iw_gmovi")
                    ->where('Tipo', $escrito['tipo'])->where('NroInt', $escrito['nroint'])
                    ->orderBy('Linea')->get()->all();

                foreach ($lineasOrig as $i => $lo) {
                    if (! isset($nuestrasLineas[$i])) {
                        $difs['(falta la línea '.($i + 1).')'] = 'no se escribió';

                        continue;
                    }
                    foreach ($this->compara((array) $lo, (array) $nuestrasLineas[$i]) as $col => $t) {
                        $difs["línea.{$col}"] = $t;
                    }
                }
            } finally {
                $conn->rollBack();
            }

            $this->line(sprintf('  %s %s %s',
                $etiqueta,
                $difs === [] ? '<fg=green>igual</>' : '<fg=red>'.count($difs).' columnas distintas</>',
                $difs === [] ? '' : OutputFormatter::escape(implode(', ', array_slice(array_keys($difs), 0, 6)))));

            return [$difs === [], $difs];
        } catch (Throwable $e) {
            $this->line("  {$etiqueta} <fg=red>error</> ".OutputFormatter::escape($e->getMessage()));

            return [null, []];
        }
    }

    /** Los datos de entrada del documento original, como si alguien los tecleara. */
    private function spec(TipoDte $tipo, object $o, array $lineas): array
    {
        return [
            'tipo' => $tipo,
            'receptor' => trim((string) $o->CodAux),
            'vendedor' => trim((string) $o->CodVendedor),
            'usuario' => trim((string) $o->Usuario),
            'fecha' => substr((string) $o->Fecha, 0, 10),
            'bodega' => trim((string) $o->CodBode),
            'moneda' => trim((string) $o->CodMoneda),
            'cond_pago' => trim((string) $o->CondPago) ?: null,
            'centro_costo' => trim((string) $o->CentroDeCosto) ?: null,
            'canal' => trim((string) $o->CanCod) ?: null,
            'contacto' => trim((string) $o->NomContacto) ?: null,
            'glosa' => trim((string) $o->Glosa) ?: null,
            'nota_venta' => (int) $o->nvnumero ?: null,
            'descuento_pct' => (float) $o->PorcDesc01,
            'ttd' => trim((string) $o->TtdCod) ?: null,
            'tipo_trans' => (int) $o->TipoTrans,
            'forma_pago' => (int) $o->FmaPago,
            'referencia' => $o->AuxDocNum ? [
                'folio' => (int) $o->AuxDocNum,
                'fecha' => substr((string) $o->AuxDocfec, 0, 10),
                'tipo' => trim((string) $o->TipDocRef) ?: 'F',
                'subtipo' => trim((string) $o->SubTipDocRef) ?: 'T',
            ] : null,
            'lineas' => array_map(fn ($l) => [
                'producto' => trim((string) $l->CodProd),
                'cantidad' => (float) $l->CantFacturada,
                'precio' => (float) $l->PreUniMB,
                'glosa' => $l->DetProd,
                'unidad' => trim((string) $l->CodUMed) ?: null,
                // Tal cual, sin convertir el cero en uno: hay una línea con
                // equivalencia 0 y falsearla sería tapar el caso, no probarlo.
                'equiv' => (float) $l->Equivalencia,
                'descuento_pct' => (float) $l->PorcDescMov01,
                'afecto' => true,
                'nv_linea' => (int) ($l->nvCorrela ?? 0) ?: null,
                'linea_referencia' => $l->FactNumLin === null ? null : (float) $l->FactNumLin,
            ], $lineas),
        ];
    }

    /**
     * Compara dos filas columna por columna.
     *
     * Los números se comparan por valor, no por texto: Softland guarda floats y
     * `220562.0` y `220562` son el mismo número aunque no la misma cadena. Las
     * fechas, hasta el día, salvo las que llevan hora a propósito.
     *
     * @return array<string, string>
     */
    private function compara(array $orig, array $nuestro): array
    {
        $difs = [];

        foreach ($orig as $col => $valorOrig) {
            if (isset(self::ESPERADAS[$col]) || $this->esDeProcesoPosterior($col)) {
                continue;
            }

            $a = $this->normaliza($valorOrig);
            $b = $this->normaliza($nuestro[$col] ?? null);

            if ($a === $b || $this->esRedondeo($a, $b)) {
                continue;
            }

            $difs[$col] = sprintf('Softland %s, nosotros %s',
                $a === null ? 'NULL' : "«{$a}»",
                $b === null ? 'NULL' : "«{$b}»");
        }

        return $difs;
    }

    /** Dos números que difieren en menos de un peso: es redondeo, no un error. */
    private function esRedondeo(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null || ! is_numeric($a) || ! is_numeric($b)) {
            return false;
        }

        return abs((float) $a - (float) $b) < self::REDONDEO;
    }

    private function esDeProcesoPosterior(string $col): bool
    {
        foreach (self::DE_PROCESOS_POSTERIORES as $prefijo) {
            if (str_starts_with($col, $prefijo)) {
                return true;
            }
        }

        return false;
    }

    private function normaliza(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        if (is_string($v)) {
            $v = rtrim($v);

            if ($v === '') {
                return null;
            }

            // Fechas: hasta el segundo, que es donde Softland tiene precisión.
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $v)) {
                return substr($v, 0, 19);
            }

            if (is_numeric($v)) {
                return $this->numero((float) $v);
            }

            return $v;
        }

        return is_numeric($v) ? $this->numero((float) $v) : (string) $v;
    }

    /** Un número como texto canónico, para que 220562.0 y 220562 sean iguales. */
    private function numero(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.') ?: '0';
    }
}
