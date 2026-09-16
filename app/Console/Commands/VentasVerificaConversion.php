<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Services\Softland\Saldo;
use App\Services\Softland\Ventas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Paso 2 de la fase 4.5: comprobar la conversión parcial de punta a punta.
 *
 * Reproduce el caso real de la cotización 7952 —ocho líneas repartidas entre
 * dos notas de venta el mismo día— y le añade lo que aquella no tenía: reparto
 * **por cantidad** dentro de una línea, y la vuelta atrás al anular.
 *
 * ## Escribe de verdad, y lo deshace
 *
 * `Ventas` no sabe escribir en otra base: escribe en la de la instalación. Así
 * que en vez de una copia se usa una transacción que se deshace al final. Se
 * recorre el camino real —el mismo que usa el teléfono— y no queda nada: ni
 * documentos, ni enlaces, ni números gastados, porque el correlativo vuelve
 * atrás con todo lo demás.
 *
 *   php artisan ventas:verifica-conversion
 */
class VentasVerificaConversion extends Command
{
    protected $signature = 'ventas:verifica-conversion {--dejar : No deshace, para poder mirarlo en el ERP}';

    protected $description = 'Convierte una cotización en dos notas de venta y comprueba el saldo, sin dejar rastro';

    private int $fallos = 0;

    public function handle(Ventas $ventas, Saldo $saldo): int
    {
        $u = Usuario::where('habilitado', true)->whereNotNull('ven_cod')->first()
            ?? Usuario::whereNotNull('ven_cod')->first();

        if (! $u) {
            $this->error('No hay ningún usuario con vendedor: sin `VenCod` un documento no existe para Softland.');

            return self::FAILURE;
        }

        $this->line("Usuario: {$u->nombre} (vendedor {$u->ven_cod})");

        DB::connection('softland')->beginTransaction();

        try {
            $this->correr($ventas, $saldo, $u);
        } catch (Throwable $e) {
            DB::connection('softland')->rollBack();
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dejar')) {
            DB::connection('softland')->commit();
            $this->newLine();
            $this->warn('Los documentos quedaron escritos: --dejar.');
        } else {
            DB::connection('softland')->rollBack();
            $this->newLine();
            $this->line('Deshecho: no queda ningún documento ni número gastado.');
        }

        return $this->fallos === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function correr(Ventas $ventas, Saldo $saldo, Usuario $u): void
    {
        [$cliente, $productos] = $this->materiaPrima();

        // Tres líneas: una que se va a repartir por cantidad, una que se
        // convierte entera de una vez y una que se queda esperando.
        $lineas = [
            ['producto' => $productos[0], 'cantidad' => 12, 'precio' => 10000],
            ['producto' => $productos[1], 'cantidad' => 5, 'precio' => 20000],
            ['producto' => $productos[2], 'cantidad' => 1, 'precio' => 30000],
        ];

        $cot = $ventas->crearCotizacion($this->documento($cliente, $lineas, $u), $u);
        $this->line("Cotización {$cot}: 12 + 5 + 1");

        $this->comprobar('recién creada, no se ha convertido nada',
            $saldo->deCotizacion($cot), [12.0, 5.0, 1.0], 'P');

        // ---- primera nota de venta: 5 de la línea 1 y las 5 de la línea 2
        $nv1 = $ventas->crearNotaVenta($this->documento($cliente, [
            ['producto' => $productos[0], 'cantidad' => 5, 'precio' => 10000, 'cot_linea' => 1],
            ['producto' => $productos[1], 'cantidad' => 5, 'precio' => 20000, 'cot_linea' => 2],
        ], $u) + ['centro_costo' => $this->centroDeCosto()], $u, $cot);

        $this->line("Nota de venta {$nv1}: 5 de la línea 1, las 5 de la línea 2");
        $this->comprobar('convertida a medias', $saldo->deCotizacion($cot), [7.0, 0.0, 1.0], 'V');

        // ---- segunda nota de venta: lo que quedaba
        $nv2 = $ventas->crearNotaVenta($this->documento($cliente, [
            ['producto' => $productos[0], 'cantidad' => 7, 'precio' => 10000, 'cot_linea' => 1],
            ['producto' => $productos[2], 'cantidad' => 1, 'precio' => 30000, 'cot_linea' => 3],
        ], $u) + ['centro_costo' => $this->centroDeCosto()], $u, $cot);

        $this->line("Nota de venta {$nv2}: los 7 que faltaban y la línea 3");
        $this->comprobar('convertida del todo', $saldo->deCotizacion($cot), [0.0, 0.0, 0.0], 'V');

        // ---- anular la segunda: el saldo vuelve, la cotización sigue vendida
        $ventas->anularNotaVenta($nv2, $u);
        $this->line("Anulada la nota de venta {$nv2}");
        $this->comprobar('el saldo vuelve y sigue en V porque queda una viva',
            $saldo->deCotizacion($cot), [7.0, 0.0, 1.0], 'V');

        // ---- anular la primera: sin notas de venta vivas, vuelve a P
        $ventas->anularNotaVenta($nv1, $u);
        $this->line("Anulada la nota de venta {$nv1}");
        $this->comprobar('sin ninguna nota de venta viva, vuelve a P',
            $saldo->deCotizacion($cot), [12.0, 5.0, 1.0], 'P');
    }

    /** @param  list<float>  $esperado */
    private function comprobar(string $que, array $r, array $esperado, string $estado): void
    {
        $saldos = array_map(fn ($l) => round((float) $l['saldo'], 4), $r['lineas']);
        $bien = $saldos === $esperado && trim((string) $r['estado']) === $estado && $r['conocible'];

        $bien || $this->fallos++;

        $this->line(sprintf('   %s %-52s saldo [%s] estado %s',
            $bien ? '<fg=green>ok</>' : '<fg=red>NO</>',
            $que,
            implode(', ', $saldos),
            $r['estado'],
        ));

        if (! $bien) {
            $this->line('      se esperaba [<fg=yellow>'.implode(', ', $esperado)."</>] estado <fg=yellow>{$estado}</>");
            $r['conocible'] || $this->line("      y además dice que no lo sabe: {$r['motivo']}");
        }
    }

    /** @return array{0: string, 1: list<string>} */
    private function materiaPrima(): array
    {
        $cliente = DB::connection('softland')->table('softland.cwtauxi')->value('CodAux')
            ?? throw new RuntimeException('No hay clientes en cwtauxi.');

        $productos = DB::connection('softland')->table('softland.iw_tprod')
            ->limit(3)->pluck('CodProd')->map(fn ($p) => trim((string) $p))->all();

        if (count($productos) < 3) {
            throw new RuntimeException('Hacen falta tres productos en iw_tprod para la prueba.');
        }

        return [trim((string) $cliente), $productos];
    }

    private function centroDeCosto(): ?string
    {
        $cc = DB::connection('softland')->table('softland.cwtccos')->value('CodiCC');

        return $cc ? trim((string) $cc) : null;
    }

    private function documento(string $cliente, array $lineas, Usuario $u): array
    {
        return [
            'client_uuid' => 'verifica-'.bin2hex(random_bytes(8)),
            'cliente' => $cliente,
            'vendedor' => $u->ven_cod,
            'lineas' => $lineas,
        ];
    }
}
