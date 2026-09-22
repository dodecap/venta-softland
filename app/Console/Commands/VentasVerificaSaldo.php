<?php

namespace App\Console\Commands;

use App\Services\Softland\Saldo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Paso 1 de la fase 4.5: comprobar el saldo contra la historia, sin escribir
 * nada.
 *
 * Son dos comprobaciones distintas y sólo una puede dar números exactos:
 *
 *   - **Notas de venta.** Aquí el enlace es nativo (`iw_gmovi.nvCorrela`), así
 *     que el saldo de los documentos que ya existen se puede calcular y
 *     contrastar. En NETDOMAIN hay 1.017 líneas cruzables y facturación parcial
 *     de verdad: 140 líneas facturadas por menos de lo pedido.
 *   - **Cotizaciones.** Aquí el enlace es nuestro y la historia no lo tiene, así
 *     que lo que se comprueba es lo contrario: que el servicio **diga que no lo
 *     sabe** en vez de inventar un saldo. Una cotización vieja que apareciera
 *     con «le queda todo» se convertiría dos veces.
 *
 *   php artisan ventas:verifica-saldo
 *   php artisan ventas:verifica-saldo --base=NETDOMAIN
 */
class VentasVerificaSaldo extends Command
{
    protected $signature = 'ventas:verifica-saldo
        {--base= : Otra base de la instancia, solo lectura}
        {--limite=400 : Cuántos documentos como máximo de cada clase}
        {--detalle : Enseña las líneas de los casos que no cuadran}';

    protected $description = 'Contrasta el cálculo del saldo contra los documentos que ya existen';

    public function handle(): int
    {
        $base = trim((string) $this->option('base')) ?: null;
        $limite = (int) $this->option('limite');
        $saldo = new Saldo($base);

        $this->line('Base: <options=bold>'.($base ?? DB::connection('softland')->getDatabaseName()).'</>');
        $this->newLine();

        try {
            $ok = $this->notasDeVenta($saldo, $base, $limite);
            $this->newLine();
            $ok = $this->cotizaciones($saldo, $base, $limite) && $ok;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * De las notas de venta que ya tienen factura.
     *
     * Lo que **no** se comprueba es que el saldo sea positivo: facturar de más
     * está permitido —la regla 4— y en NETDOMAIN pasa de verdad, con notas de
     * venta de doce mensualidades que acabaron con catorce facturas. Esos casos
     * se enseñan, no fallan.
     *
     * Lo que sí es imposible es **acreditar más de lo facturado**: significaría
     * que estamos emparejando notas de crédito que no son de esta nota de
     * venta. Ese es el invariante que se exige.
     */
    private function notasDeVenta(Saldo $saldo, ?string $base, int $limite): bool
    {
        $pre = $base ? "{$base}.softland" : 'softland';

        $numeros = DB::connection('softland')->table("{$pre}.iw_gsaen")
            ->whereIn('Tipo', ['F', 'B'])
            ->where('Estado', '<>', 'N')
            ->where('nvnumero', '>', 0)
            ->distinct()->limit($limite)
            ->orderByDesc('nvnumero')
            ->pluck('nvnumero');

        $this->line("<options=bold>Notas de venta con factura</>: {$numeros->count()}");

        $conSaldo = $enteras = $deMas = $sinEnlace = $borradas = $sinAtribuir = $imposibles = 0;
        $lineas = 0;

        foreach ($numeros as $n) {
            $r = $saldo->deNotaVenta((int) $n);

            // La nota de venta que la factura cita puede no existir ya: en
            // NETDOMAIN sólo 614 de las 941 citadas sobreviven. Eso no es un
            // fallo del cálculo, es historia borrada.
            if ($r['lineas'] === []) {
                $borradas++;

                continue;
            }

            $r['no_atribuido'] > 0 && $sinAtribuir++;

            $algoFacturado = false;
            $algoPendiente = false;

            foreach ($r['lineas'] as $l) {
                $lineas++;
                $l['facturada'] > 0 && $algoFacturado = true;

                if ($l['acreditada'] > $l['facturada'] + 0.0001) {
                    $imposibles++;
                    $this->line(
                        "   <fg=red>NV {$n} línea {$l['linea']}: acreditada {$l['acreditada']} "
                        ."sobre facturada {$l['facturada']}</>"
                    );
                }

                if ($l['saldo'] < -0.0001) {
                    $deMas++;
                    $this->option('detalle') && $this->line(
                        "   NV {$n} línea {$l['linea']} {$l['producto']}: pedida {$l['pedida']}, "
                        ."facturada {$l['facturada']}, saldo {$l['saldo']}"
                    );

                    continue;
                }

                $l['saldo'] > 0.0001 && $algoPendiente = true;
            }

            $algoFacturado || $sinEnlace++;
            $algoPendiente ? $conSaldo++ : $enteras++;
        }

        $this->line("   líneas miradas: {$lineas}");
        $this->line("   con saldo por facturar:  {$conSaldo}");
        $this->line("   facturadas del todo:     {$enteras}");
        $this->line("   facturadas de más (permitido): {$deMas}");
        $this->line("   sin ninguna línea enlazada por nvCorrela: {$sinEnlace}");
        $borradas && $this->line("   la nota de venta ya no existe: {$borradas}");
        $sinAtribuir && $this->line("   con nota de crédito sin línea de origen: {$sinAtribuir}");
        $this->line('   acreditado por encima de lo facturado: '
            .($imposibles ? "<fg=red>{$imposibles}</>" : '<fg=green>0</>'));

        return $imposibles === 0;
    }

    /**
     * De las cotizaciones ya convertidas, lo que se comprueba es que el
     * servicio **no** invente saldo: sin enlace de línea no se puede saber, y
     * decirlo es la respuesta correcta.
     */
    private function cotizaciones(Saldo $saldo, ?string $base, int $limite): bool
    {
        $pre = $base ? "{$base}.softland" : 'softland';

        $numeros = DB::connection('softland')->table("{$pre}.nw_nventa")
            ->where('CotNum', '>', 0)
            ->where('nvEstado', '<>', 'N')
            ->distinct()->limit($limite)
            ->orderByDesc('CotNum')
            ->pluck('CotNum');

        $this->line("<options=bold>Cotizaciones convertidas</>: {$numeros->count()}");

        $avisan = $inventan = $borradas = 0;

        foreach ($numeros as $n) {
            $r = $saldo->deCotizacion((int) $n);

            if ($r['lineas'] === [] && $r['estado'] === null) {
                $borradas++;

                continue;
            }

            if (! $r['conocible']) {
                $avisan++;

                continue;
            }

            // Conocible y con saldo, sin una sola fila de enlace, sería el
            // servicio afirmando que queda todo lo que ya se vendió.
            $inventan++;
            $this->option('detalle') && $this->line(
                "   cotización {$n} (estado {$r['estado']}): se declara conocible sin enlaces"
            );
        }

        $this->line("   dicen «no se sabe», que es lo correcto: <fg=green>{$avisan}</>");
        $borradas && $this->line("   la cotización ya no existe: {$borradas}");
        $this->line('   inventan un saldo: '.($inventan ? "<fg=red>{$inventan}</>" : '<fg=green>0</>'));

        return $inventan === 0;
    }
}
