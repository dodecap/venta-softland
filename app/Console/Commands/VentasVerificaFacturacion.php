<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Services\Dte\Facturacion;
use App\Services\Dte\ReglasFactura;
use App\Services\Dte\TipoDte;
use App\Services\Softland\Saldo;
use App\Services\Softland\Ventas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Paso 3 de la fase 4.5: facturar parte de una nota de venta.
 *
 * Reproduce la forma del caso real de NETDOMAIN —la nota de venta 1874, doce
 * unidades facturadas de a una— en INNOVAGES, porque **NETDOMAIN es sólo
 * lectura**.
 *
 * ## El ciclo entero
 *
 * Convertir, facturar en parte, acreditar, anular la nota de crédito y anular
 * la factura. El saldo tiene que volver y volver a irse **sin perderse ni
 * duplicarse** en ninguno de los cinco pasos.
 *
 * ## Por qué una sola factura y no doce
 *
 * Porque queda **un solo folio**. El repartidor de Softland entrega el 235 y a
 * la segunda llamada devuelve -1: no hay más CAF cargado. Así que aquí se
 * factura una vez y se comprueba lo que esa vez demuestra —el enlace, la
 * herencia del precio, el saldo y la vuelta atrás al anular—, y el caso de
 * muchas facturas contra una nota de venta queda demostrado por la historia,
 * que lo tiene de sobra: `ventas:verifica-saldo` recorre 397 notas de venta de
 * NETDOMAIN facturadas del todo, algunas en 26 veces.
 *
 * Como en el paso 2, todo ocurre dentro de una transacción que se deshace: ni
 * el folio se gasta.
 *
 *   php artisan ventas:verifica-facturacion
 */
class VentasVerificaFacturacion extends Command
{
    protected $signature = 'ventas:verifica-facturacion {--dejar : No deshace, para poder mirarlo en el ERP}';

    protected $description = 'Factura parte de una nota de venta y comprueba el enlace, la herencia y el saldo';

    private int $fallos = 0;

    public function handle(Ventas $ventas, Saldo $saldo, Facturacion $facturacion): int
    {
        $u = Usuario::whereNotNull('ven_cod')->first();

        if (! $u) {
            $this->error('No hay ningún usuario con vendedor.');

            return self::FAILURE;
        }

        DB::connection('softland')->beginTransaction();

        try {
            $this->correr($ventas, $saldo, $facturacion, $u);
        } catch (Throwable $e) {
            DB::connection('softland')->rollBack();
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dejar')) {
            DB::connection('softland')->commit();
            $this->warn("\nQuedó escrito: --dejar. El folio se gastó.");
        } else {
            DB::connection('softland')->rollBack();
            $this->line("\nDeshecho: no queda documento ni folio gastado.");
        }

        return $this->fallos === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function correr(Ventas $ventas, Saldo $saldo, Facturacion $facturacion, Usuario $u): void
    {
        [$cliente, $productos, $cc] = $this->materiaPrima();
        $precioNv = 17500.0;

        $nv = $ventas->crearNotaVenta([
            'client_uuid' => 'verifica-fact-'.bin2hex(random_bytes(6)),
            'cliente' => $cliente,
            'vendedor' => $u->ven_cod,
            'centro_costo' => $cc,
            'lineas' => [['producto' => $productos[0], 'cantidad' => 12, 'precio' => $precioNv]],
        ], $u);

        $this->line("Nota de venta {$nv}: 12 unidades de {$productos[0]} a ".number_format($precioNv, 0, ',', '.'));
        $this->saldoEs('recién creada, sin facturar', $saldo->deNotaVenta($nv), [12.0]);

        $pendiente = $facturacion->propuesta($nv);
        $this->comprobar('la propuesta ofrece las 12 pendientes',
            count($pendiente) === 1 && abs($pendiente[0]['cantidad'] - 12) < 0.0001);

        // ---- las dos negativas, que no gastan folio porque fallan antes
        $this->rechaza('se rechaza una línea que dice venir de una NV sin decir de cuál',
            fn () => $facturacion->escribir($this->factura($cliente, $cc, $u, null, [
                ['producto' => $productos[0], 'cantidad' => 1, 'precio' => 1, 'nv_linea' => 1],
            ])));

        $this->rechaza('se rechaza una línea de la NV que no existe',
            fn () => $facturacion->escribir($this->factura($cliente, $cc, $u, $nv, [
                ['producto' => $productos[0], 'cantidad' => 1, 'precio' => 1, 'nv_linea' => 99],
            ])));

        // ---- la llave del receptor. Ninguna de estas gasta folio: la regla se
        //      comprueba antes de pedirlo.
        $reglas = new ReglasFactura;
        $otro = $this->otroCliente($cliente);

        $reglas->fijarReceptorEditable(false);
        $this->rechaza('con la llave apagada se rechaza facturarle a otro cliente',
            fn () => $facturacion->escribir($this->factura($otro, $cc, $u, $nv, [
                ['producto' => $productos[0], 'cantidad' => 1, 'precio' => 1, 'nv_linea' => 1],
            ])), 'configuración de facturación');

        $reglas->fijarReceptorEditable(true);
        $this->rechaza('con la llave encendida el receptor ya no estorba',
            fn () => $facturacion->escribir($this->factura($otro, $cc, $u, $nv, [
                // La línea es inválida a propósito: si el error que llega es el
                // de la línea y no el del receptor, la regla dejó pasar.
                ['producto' => $productos[0], 'cantidad' => 1, 'precio' => 1, 'nv_linea' => 99],
            ])), 'no tiene la línea');

        $reglas->fijarReceptorEditable(false);

        // ---- la factura de verdad: 5 de la línea 1, más una línea suya
        //      y con un precio equivocado a propósito, que debe ser ignorado
        $doc = $facturacion->escribir($this->factura($cliente, $cc, $u, $nv, [
            ['producto' => $productos[0], 'cantidad' => 5, 'precio' => 999, 'nv_linea' => 1],
            ['producto' => $productos[1], 'cantidad' => 2, 'precio' => 4000],
        ]));

        $this->line("Factura folio {$doc['folio']}: 5 de la línea 1 más una línea agregada a mano");
        $this->saldoEs('facturada en parte', $saldo->deNotaVenta($nv), [7.0]);

        $lineas = DB::connection('softland')->table('softland.iw_gmovi')
            ->where('Tipo', $doc['tipo'])->where('NroInt', $doc['nroint'])->orderBy('Linea')->get();

        $this->comprobar('la línea heredada apunta a la línea 1 de la nota de venta',
            (float) $lineas[0]->nvCorrela === 1.0);
        $this->comprobar('la línea agregada a mano no apunta a ninguna',
            (float) $lineas[1]->nvCorrela === 0.0);
        $this->comprobar('el precio lo puso la nota de venta, no quien facturó',
            abs((float) $lineas[0]->PreUniMB - $precioNv) < 0.01,
            'PreUniMB = '.$lineas[0]->PreUniMB.', se esperaba '.$precioNv);

        $cab = DB::connection('softland')->table('softland.iw_gsaen')
            ->where('Tipo', $doc['tipo'])->where('NroInt', $doc['nroint'])->first();
        $this->comprobar('el encabezado dice de qué nota de venta viene',
            (int) $cab->nvnumero === $nv);

        $this->comprobar('lo que queda por facturar son 7',
            count($p = $facturacion->propuesta($nv)) === 1 && abs($p[0]['cantidad'] - 7) < 0.0001);

        // ---- la nota de crédito: devuelve lo facturado y el saldo vuelve
        $nc = $facturacion->escribir([
            'tipo' => TipoDte::NOTA_CREDITO,
            'receptor' => $cliente,
            'vendedor' => $u->ven_cod,
            'usuario' => 'verifica',
            'centro_costo' => $cc,
            'lineas' => $facturacion->propuestaNotaCredito($doc['tipo'], $doc['nroint']),
            'referencia' => [
                'folio' => $doc['folio'],
                'fecha' => date('Y-m-d'),
                'tipo' => 'F',
                'subtipo' => 'T',
                'codigo' => '1',
                'razon' => 'Anula Documento',
            ],
        ]);

        $this->line("Nota de crédito folio {$nc['folio']}: devuelve la factura entera");
        $this->saldoEs('acreditada, el saldo vuelve', $saldo->deNotaVenta($nv), [12.0]);

        $lineasNc = DB::connection('softland')->table('softland.iw_gmovi')
            ->where('Tipo', $nc['tipo'])->where('NroInt', $nc['nroint'])->orderBy('Linea')->get();

        $this->comprobar('la nota de crédito dice qué línea de la factura devuelve',
            (float) $lineasNc[0]->FactNumLin === 1.0);
        $this->comprobar('y hereda de ella el enlace a la nota de venta',
            (float) $lineasNc[0]->nvCorrela === 1.0,
            'nvCorrela = '.$lineasNc[0]->nvCorrela);
        $this->comprobar('la cantidad devuelta va en negativo',
            (float) $lineasNc[0]->CantFacturada < 0);
        $this->comprobar('vuelve a haber 12 por facturar',
            count($p2 = $facturacion->propuesta($nv)) === 1 && abs($p2[0]['cantidad'] - 12) < 0.0001);

        // ---- anular la nota de crédito: lo facturado vuelve a consumir
        DB::connection('softland')->table('softland.iw_gsaen')
            ->where('Tipo', $nc['tipo'])->where('NroInt', $nc['nroint'])->update(['Estado' => 'N']);

        $this->line("Anulada la nota de crédito {$nc['folio']}");
        $this->saldoEs('sin la nota de crédito, los 5 vuelven a consumir',
            $saldo->deNotaVenta($nv), [7.0]);

        // ---- anular la factura: el saldo vuelve entero por el otro camino
        DB::connection('softland')->table('softland.iw_gsaen')
            ->where('Tipo', $doc['tipo'])->where('NroInt', $doc['nroint'])->update(['Estado' => 'N']);

        $this->line("Anulada la factura {$doc['folio']}");
        $this->saldoEs('una factura anulada no consume', $saldo->deNotaVenta($nv), [12.0]);
    }

    /** @param  list<float>  $esperado */
    private function saldoEs(string $que, array $r, array $esperado): void
    {
        $saldos = array_map(fn ($l) => round((float) $l['saldo'], 4), $r['lineas']);

        $this->comprobar($que, $saldos === $esperado,
            'saldo ['.implode(', ', $saldos).'], se esperaba ['.implode(', ', $esperado).']');
    }

    private function comprobar(string $que, bool $bien, ?string $detalle = null): void
    {
        $bien || $this->fallos++;
        $this->line(sprintf('   %s %s', $bien ? '<fg=green>ok</>' : '<fg=red>NO</>', $que));
        $bien || $detalle === null || $this->line("      <fg=yellow>{$detalle}</>");
    }

    /**
     * Comprueba que algo se rechaza, y **por el motivo que toca**.
     *
     * Lo segundo importa tanto como lo primero: una petición inválida por dos
     * razones falla igual, y sin mirar el mensaje una regla que dejó de
     * aplicarse seguiría pareciendo que funciona.
     */
    private function rechaza(string $que, callable $fn, string $porque = ''): void
    {
        try {
            $fn();
            $this->comprobar($que, false, 'no se rechazó: se escribió el documento');
        } catch (RuntimeException $e) {
            $this->comprobar($que, $porque === '' || str_contains($e->getMessage(), $porque),
                'se rechazó por otra cosa: '.$e->getMessage());
        }
    }

    private function factura(string $cliente, ?string $cc, Usuario $u, ?int $nv, array $lineas): array
    {
        return [
            'tipo' => TipoDte::FACTURA,
            'receptor' => $cliente,
            'vendedor' => $u->ven_cod,
            'usuario' => 'verifica',
            'centro_costo' => $cc,
            'nota_venta' => $nv,
            'lineas' => $lineas,
        ];
    }

    private function otroCliente(string $distintoDe): string
    {
        return trim((string) DB::connection('softland')->table('softland.cwtauxi')
            ->where('CodAux', '<>', $distintoDe)->value('CodAux'));
    }

    /** @return array{0: string, 1: list<string>, 2: ?string} */
    private function materiaPrima(): array
    {
        $c = DB::connection('softland');
        $cliente = $c->table('softland.cwtauxi')->value('CodAux')
            ?? throw new RuntimeException('No hay clientes en cwtauxi.');
        $productos = $c->table('softland.iw_tprod')->limit(2)
            ->pluck('CodProd')->map(fn ($p) => trim((string) $p))->all();
        $cc = $c->table('softland.cwtccos')->value('CodiCC');

        return [trim((string) $cliente), $productos, $cc ? trim((string) $cc) : null];
    }
}
