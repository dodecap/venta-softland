<?php

namespace App\Console\Commands;

use App\Services\Cobranza\Comprobante;
use App\Services\Cobranza\Cuentas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Arma los comprobantes de ingreso que YA existen y los compara con lo escrito.
 *
 * No escribe nada. Es el paso previo a cobrar de verdad, y la misma idea que
 * `dte:verifica-timbre`: antes de meter una fila en la contabilidad de una
 * empresa que factura, esta app tiene que demostrar que sabe escribirla como
 * la escribe el ERP.
 *
 * **Lo que se compara no es «igual a la base», es «igual a lo que quiso
 * decir».** Estos comprobantes los teclea una persona y se nota: el número del
 * traspaso aparece como 2223, 223 y 2224 dentro del mismo asiento, hay filas
 * con importe cero que nadie borró, y borradores con movimientos. Una
 * diferencia contra eso no es un fallo nuestro — es justamente lo que esta app
 * viene a evitar, porque el número lo va a poner una sola vez y en un solo
 * sitio. Por eso el recuento las separa, y sólo las **diferencias nuestras**
 * hacen que el comando falle.
 */
class CobranzaVerificaComprobante extends Command
{
    protected $signature = 'cobranza:verifica-comprobante
                            {--todos : Todos los comprobantes de ingreso, no sólo los del último año}
                            {--detalle : Enseña campo a campo lo que no calza}
                            {--base= : Otra base de la misma instancia, para contrastar sin tocar la de producción}';

    protected $description = 'Reproduce los comprobantes de ingreso existentes sin escribir ninguno';

    /** Lo que esta app decide y por tanto tiene que salir igual. */
    private const CAMPOS_FILA = [
        'AreaCod', 'PctCod', 'CpbMes', 'CvCod', 'VendCod', 'UbicCod', 'CajCod', 'IfCod',
        'DgaCod', 'CcCod', 'TipDocCb', 'NumDocCb', 'CodAux', 'TtdCod', 'NumDoc',
        'MovFe', 'MovFv', 'MovTipDocRef', 'MovNumDocRef', 'MovDebe', 'MovHaber',
        'MonCod', 'MovEquiv', 'MovDebeMa', 'MovHaberMa', 'MovAEquiv', 'FecPag',
        'GrabaDLib', 'MtoTotal', 'Marca', 'Impreso', 'CpbNormaIFRS', 'CpbNormaTrib',
        'MovGlosa',
    ];

    private const CAMPOS_CABECERA = [
        'AreaCod', 'CpbMes', 'CpbTip', 'CpbImp', 'CpbCon', 'Sistema',
        'CpbNormaIFRS', 'CpbNormaTrib', 'CpbAnoRev', 'CpbNumRev',
    ];

    public function handle(): int
    {
        $base = $this->option('base') ?: null;
        $cuentas = new Cuentas($base);
        $motor = new Comprobante($cuentas);

        $this->cuentasEnPantalla($cuentas);

        $cabs = $this->comprobantes($base);
        $this->line('');
        $this->info('Comprobantes de ingreso a reproducir: '.count($cabs));

        $identicos = $conErratas = $nuestras = $vacios = $fuera = 0;
        $erratas = $fallos = $descartes = [];

        foreach ($cabs as $cab) {
            $filas = $this->movimientos($base, $cab);
            $id = "$cab->CpbAno-$cab->CpbNum";

            if (! $filas) {
                $vacios++;

                continue;
            }

            $intencion = $this->deducir($cab, $filas, $cuentas);

            if (is_string($intencion)) {
                $fuera++;
                $descartes[] = "$id: $intencion";

                continue;
            }

            try {
                $armado = $motor->armar($intencion);
            } catch (\Throwable $e) {
                $nuestras++;
                $fallos[] = ['id' => $id, 'motivo' => 'no se pudo armar: '.$e->getMessage(), 'difs' => []];

                continue;
            }

            [$mias, $suyas] = $this->clasificar($cab, $filas, $armado);

            if (! $mias && ! $suyas) {
                $identicos++;
            } elseif (! $mias) {
                $conErratas++;
                $erratas[] = ['id' => $id, 'motivo' => $this->resumen($suyas), 'difs' => $suyas];
            } else {
                $nuestras++;
                $fallos[] = ['id' => $id, 'motivo' => count($mias).' diferencias nuestras', 'difs' => array_merge($mias, $suyas)];
            }
        }

        $this->line('');
        $this->info('Resultado');
        $this->line("  reproducidos idénticos      : $identicos");
        $this->line("  idénticos salvo las erratas : $conErratas   (del propio comprobante, ver abajo)");
        $this->line("  no son un cobro de cartera  : $fuera");
        $this->line("  borradores sin movimientos  : $vacios");
        $this->linea('  DIFERENCIAS NUESTRAS        : '.$nuestras, $nuestras > 0);

        $this->bloque('Erratas del comprobante real (no las reproducimos a propósito)', $erratas);
        $this->bloque('Diferencias nuestras', $fallos);

        if ($descartes) {
            $this->line('');
            $this->info('Fuera de molde — por qué');
            foreach (array_count_values(array_map(fn ($d) => trim(explode(':', $d, 2)[1]), $descartes)) as $motivo => $n) {
                $this->line("  $n × $motivo");
            }
            if ($this->option('detalle')) {
                foreach ($descartes as $d) {
                    $this->line("    $d");
                }
            }
        }

        $this->numeracion($base);

        return $nuestras > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ----------------------------------------------------------- pantalla

    private function cuentasEnPantalla(Cuentas $cuentas): void
    {
        $this->line('');
        $this->info('Cuentas, deducidas de iwparam y completadas en ventas.config');
        $this->line('  cuenta corriente del cliente: '.($cuentas->cuentaCliente() ?: '(sin configurar)'));
        foreach ($cuentas->medios() as $m) {
            $this->line(sprintf('  %-20s %-3s %-14s %s', $m['rotulo'], $m['ttd'], $m['cuenta'] ?: '—', $m['fuente']));
        }
        foreach ($cuentas->problemas() as $p) {
            $this->warn('  ! '.$p);
        }
    }

    private function linea(string $texto, bool $malo): void
    {
        $malo ? $this->error($texto) : $this->line($texto);
    }

    private function bloque(string $titulo, array $items): void
    {
        if (! $items) {
            return;
        }
        $this->line('');
        $this->warn($titulo);
        foreach ($items as $f) {
            $this->line("  {$f['id']}: {$f['motivo']}");
            if ($this->option('detalle')) {
                foreach ($f['difs'] as $d) {
                    $this->line('      '.$d['texto']);
                }
            }
        }
        if (! $this->option('detalle')) {
            $this->line('  (con --detalle sale campo a campo)');
        }
    }

    private function resumen(array $difs): string
    {
        $clases = array_count_values(array_column($difs, 'clase'));
        $partes = [];
        foreach ($clases as $clase => $n) {
            $partes[] = "$n × $clase";
        }

        return implode(', ', $partes);
    }

    // ----------------------------------------------------------- lectura

    private function comprobantes(?string $base): array
    {
        $q = DB::connection('softland')
            ->table($this->tabla($base, 'cwcpbte'))
            ->where('Sistema', Comprobante::SISTEMA)
            ->where('CpbTip', Comprobante::TIPO_INGRESO)
            ->orderBy('CpbAno')->orderBy('CpbNum');

        if (! $this->option('todos')) {
            $q->where('CpbAno', '>=', (string) (now()->year - 1));
        }

        return $q->get()->all();
    }

    private function movimientos(?string $base, object $cab): array
    {
        return DB::connection('softland')
            ->table($this->tabla($base, 'cwmovim'))
            ->where('CpbAno', $cab->CpbAno)
            ->where('CpbNum', $cab->CpbNum)
            ->orderBy('MovNum')
            ->get()->all();
    }

    // -------------------------------------------------- de lo escrito al intento

    /**
     * Lee un comprobante real y deduce el cobro que lo habría producido.
     *
     * **El instrumento de pago lo identifica su fila del debe**, no el número
     * que lleva cada fila del haber: en 2024-00002000 el mismo traspaso está
     * tecleado como 2223, 223 y 2224, y agrupar por ese número inventaría tres
     * pagos donde hubo uno. Quien cobra escribe el número una vez.
     *
     * Devuelve un texto cuando el comprobante no es un cobro de cartera: en la
     * misma tabla hay egresos, traspasos y el asiento de apertura, y forzarlos
     * a este molde sería contar como fallo algo que esta app no escribe.
     *
     * @return array|string
     */
    private function deducir(object $cab, array $filas, Cuentas $cuentas)
    {
        $haberes = array_values(array_filter($filas, fn ($f) => (float) $f->MovHaber > 0));
        $debes = array_values(array_filter($filas, fn ($f) => (float) $f->MovDebe > 0));

        if (! $haberes || ! $debes) {
            return 'no tiene las dos mitades del asiento';
        }

        $clientes = array_unique(array_map(fn ($f) => trim((string) $f->CodAux), $haberes));
        if (count($clientes) > 1) {
            return 'abona a varios clientes en un solo comprobante';
        }
        $cliente = reset($clientes);
        if ($cliente === '' || $cliente === '0000000000') {
            return 'el haber no cuelga de ningún cliente';
        }

        $cuentaCliente = $cuentas->cuentaCliente();
        foreach ($haberes as $f) {
            if (trim((string) $f->PctCod) !== $cuentaCliente) {
                return 'el haber no va contra la cuenta corriente del cliente';
            }
            if (trim((string) $f->MovTipDocRef) === '00') {
                return 'hay un abono que no dice qué documento paga';
            }
        }

        // Cada fila del debe es un instrumento; las del haber se reparten entre
        // ellos por TtdCod/NumDoc contra TipDocCb/NumDocCb. Con un solo debe no
        // hay nada que repartir, que es el caso corriente.
        $pagos = [];
        foreach ($debes as $i => $d) {
            $medio = $cuentas->porTtd(trim((string) $d->TipDocCb));
            if (! $medio) {
                return 'forma de pago '.trim((string) $d->TipDocCb).' sin equivalente en la app';
            }
            $pagos[$i] = [
                'medio' => $medio['codigo'],
                'numero' => (float) $d->NumDocCb,
                'fecha' => null,
                'aplicaciones' => [],
            ];
        }

        foreach ($haberes as $f) {
            $i = $this->debeDe($f, $debes);
            if ($i === null) {
                return 'un abono no se enlaza con ninguna forma de pago';
            }
            $pagos[$i]['fecha'] ??= $this->dia($f->MovFv);
            $pagos[$i]['aplicaciones'][] = [
                'tipo' => trim((string) $f->MovTipDocRef),
                'numero' => (float) $f->MovNumDocRef,
                'fecha_documento' => $this->dia($f->MovFe),
                'monto' => round((float) $f->MovHaber, 2),
                'glosa' => $f->MovGlosa,
            ];
        }

        foreach ($pagos as $p) {
            if (! $p['aplicaciones']) {
                return 'hay una forma de pago sin ningún documento abonado';
            }
        }

        return [
            'cliente' => $cliente,
            'fecha' => $this->dia($cab->CpbFec),
            'glosa' => $cab->CpbGlo,
            'usuario' => $cab->Usuario,
            'pagos' => array_values($pagos),
        ];
    }

    /** A qué fila del debe pertenece un abono. */
    private function debeDe(object $haber, array $debes): ?int
    {
        if (count($debes) === 1) {
            return 0;
        }
        foreach ($debes as $i => $d) {
            if (trim((string) $d->TipDocCb) === trim((string) $haber->TtdCod)
                && (float) $d->NumDocCb === (float) $haber->NumDoc) {
                return $i;
            }
        }
        // Mismo medio, número mal tecleado: la errata no puede dejar el abono huérfano.
        foreach ($debes as $i => $d) {
            if (trim((string) $d->TipDocCb) === trim((string) $haber->TtdCod)) {
                return $i;
            }
        }

        return null;
    }

    // ------------------------------------------------------------ comparar

    /**
     * Separa lo que falla nuestro de lo que el comprobante real se contradice.
     *
     * @return array{0: list<array>, 1: list<array>} [nuestras, del comprobante]
     */
    private function clasificar(object $cab, array $reales, array $armado): array
    {
        $mias = $suyas = [];

        foreach (self::CAMPOS_CABECERA as $campo) {
            $a = $this->plano($armado['cabecera'][$campo] ?? null);
            $b = $this->plano($cab->{$campo} ?? null);
            if ($a === $b) {
                continue;
            }
            $dif = ['clase' => 'cabecera', 'texto' => "cabecera $campo: armado «{$a}» ≠ real «{$b}»"];

            // Un borrador con movimientos lleva CpbCon='N'. Nosotros escribimos
            // siempre vigente: un cobro a medio guardar no descuenta saldo y
            // nadie sabría que existe.
            if ($campo === 'CpbCon' && trim((string) $cab->CpbEst) === 'P') {
                $dif['clase'] = 'borrador con movimientos';
                $suyas[] = $dif;
            } else {
                $mias[] = $dif;
            }
        }

        $vacias = array_values(array_filter($reales, fn ($f) => (float) $f->MovDebe == 0 && (float) $f->MovHaber == 0));

        if (count($armado['filas']) !== count($reales)) {
            $texto = 'filas: armado '.count($armado['filas']).' ≠ real '.count($reales);
            if ($vacias && count($armado['filas']) + count($vacias) === count($reales)) {
                $suyas[] = ['clase' => 'fila con importe cero', 'texto' => $texto];
            } else {
                $mias[] = ['clase' => 'cuenta de filas', 'texto' => $texto];
            }

            return [$mias, $suyas];
        }

        foreach ($armado['filas'] as $i => $fila) {
            $real = $reales[$i];
            foreach (self::CAMPOS_FILA as $campo) {
                $a = $this->plano($fila[$campo] ?? null);
                $b = $this->plano($real->{$campo} ?? null);
                if ($a === $b) {
                    continue;
                }
                $dif = ['clase' => $campo, 'texto' => "fila $i $campo: armado «{$a}» ≠ real «{$b}»"];
                $clase = $this->erratita($campo, $i, $reales, $armado);
                if ($clase) {
                    $dif['clase'] = $clase;
                    $suyas[] = $dif;
                } else {
                    $mias[] = $dif;
                }
            }
        }

        return [$mias, $suyas];
    }

    /**
     * ¿Esta diferencia la explica una incoherencia del propio comprobante?
     *
     * Sólo tres cosas cuentan como tal, y las tres se comprueban en la fila
     * real, no se dan por supuestas.
     */
    private function erratita(string $campo, int $i, array $reales, array $armado): ?string
    {
        $real = $reales[$i];
        $esHaber = (float) $real->MovHaber > 0;

        // El número del instrumento, tecleado distinto en el debe y en el haber.
        if (in_array($campo, ['NumDoc', 'NumDocCb'], true)) {
            $numeros = [];
            foreach ($reales as $f) {
                if ((float) $f->MovHaber > 0) {
                    $numeros[] = (float) $f->NumDoc;
                } elseif ((float) $f->MovDebe > 0) {
                    $numeros[] = (float) $f->NumDocCb;
                }
            }
            if (count(array_unique($numeros)) > 1) {
                return 'número del instrumento tecleado distinto en cada fila';
            }
        }

        // La fecha del pago, distinta entre abonos del mismo instrumento.
        if ($campo === 'MovFv' && $esHaber) {
            $fechas = [];
            foreach ($reales as $f) {
                if ((float) $f->MovHaber > 0) {
                    $fechas[] = $this->dia($f->MovFv);
                }
            }
            if (count(array_unique($fechas)) > 1) {
                return 'fecha del pago distinta en cada abono';
            }
        }

        // La fila del haber con el enlace del debe relleno: Softland lo deja en
        // '00' en la inmensa mayoría, y ahí no significa nada.
        if (in_array($campo, ['TipDocCb', 'NumDocCb'], true) && $esHaber && trim((string) $real->TipDocCb) !== '00') {
            return 'el abono lleva relleno el enlace que es de la fila del debe';
        }

        return null;
    }

    /** Todo a texto comparable: los float del driver llegan como string y las fechas con hora. */
    private function plano($v): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_numeric($v)) {
            return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        }
        $s = trim((string) $v);

        return preg_match('/^\d{4}-\d{2}-\d{2}[ T]/', $s) ? substr($s, 0, 10) : $s;
    }

    private function dia($v): string
    {
        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : substr((string) $v, 0, 10);
    }

    // ---------------------------------------------------------- numeración

    /**
     * ¿Le habríamos puesto a cada comprobante el número que lleva?
     *
     * Se recalcula mirando sólo lo que existía antes que él, que es lo que
     * `numeroSiguiente()` verá en producción. Los huecos que dejan los
     * comprobantes borrados salen aquí, y salen como lo que son: un número que
     * volvió al pozo.
     */
    private function numeracion(?string $base): void
    {
        $todos = DB::connection('softland')
            ->table($this->tabla($base, 'cwcpbte'))
            ->orderBy('CpbAno')->orderBy('CpbNum')
            ->get(['CpbAno', 'CpbNum'])->all();

        $maximo = [];
        $acierta = $falla = 0;
        $huecos = [];

        foreach ($todos as $c) {
            $prefijo = substr($c->CpbNum, 0, 5);
            $clave = $c->CpbAno.'/'.$prefijo;
            $anterior = $maximo[$clave] ?? null;
            $nuestro = $prefijo.str_pad((string) ($anterior === null ? 0 : (int) substr($anterior, 5) + 1), 3, '0', STR_PAD_LEFT);

            if ($nuestro === $c->CpbNum) {
                $acierta++;
            } else {
                $falla++;
                if (count($huecos) < 8) {
                    $huecos[] = "  {$c->CpbAno}-{$c->CpbNum}: habríamos puesto $nuestro";
                }
            }
            $maximo[$clave] = $c->CpbNum;
        }

        $this->line('');
        $this->info('Correlativo (MAX + 1 por año y prefijo de mes, sobre los tres sistemas)');
        $this->line("  le habríamos puesto el mismo número a $acierta de ".count($todos));
        if ($falla) {
            $this->line("  $falla llevan uno posterior, que es el hueco que deja un comprobante borrado:");
            foreach ($huecos as $h) {
                $this->line($h);
            }
        }
    }

    private function tabla(?string $base, string $nombre): string
    {
        return $base ? "$base.softland.$nombre" : "softland.$nombre";
    }
}
