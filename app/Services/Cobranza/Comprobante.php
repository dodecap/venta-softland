<?php

namespace App\Services\Cobranza;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El comprobante de ingreso, armado fila a fila y sin escribir nada.
 *
 * No es una forma elegida: es la que Softland escribe, reproducida contra los
 * 114 comprobantes de `cwcpbte` que ya existen en INNOVAGES. Por eso está
 * separado de quien lo guarda — se puede contrastar con lo real sin tocar la
 * base, que es lo que hace `cobranza:verifica-comprobante`.
 *
 * **La forma del asiento**, medida:
 *
 * - Una fila al **haber** por cada documento que se abona, con la cuenta
 *   corriente del cliente, el cliente en `CodAux` y el documento pagado en
 *   `MovTipDocRef`/`MovNumDocRef`. Eso es lo que resta del saldo: la cartera
 *   se calcula agrupando por `CodAux, MovTipDocRef, MovNumDocRef`.
 * - Una fila al **debe** por cada instrumento de pago, con la cuenta del medio
 *   —el banco, la caja— y el importe igual a **la suma de lo que ese
 *   instrumento abonó**. No lleva `CodAux`: el dinero no es de nadie.
 * - **El haber va delante de su debe**, y los dos se enlazan por
 *   `TtdCod`/`NumDoc` en las filas del haber contra `TipDocCb`/`NumDocCb` en
 *   la del debe. De los 82 comprobantes con movimientos, 67 llevan justo ese
 *   orden; el resto son egresos y traspasos, que no son esto.
 *
 * **Las dos fechas de la fila del haber no son la misma fecha**, y es el error
 * fácil: `MovFe` es la **emisión del documento que se paga** y `MovFv` es la
 * **fecha del pago** —del instrumento, no del comprobante—. Comprobado: en las
 * 185 filas de abono a factura electrónica, `MovFv` **nunca** coincide con el
 * vencimiento de la factura (0 de 185) y sí es constante dentro de cada
 * instrumento (147 de 149). Un cliente que transfiere el día 1 y a quien
 * contabilidad le arma el comprobante el día 20 tiene que quedar con el día 1.
 *
 * El asiento **tiene que cuadrar**, y eso se comprueba aquí y no en el
 * controlador: un comprobante descuadrado es un problema de contabilidad, no
 * de pantalla.
 */
class Comprobante
{
    /** Contabilidad. El área, la moneda y el centro de costo van en su valor neutro. */
    public const SISTEMA = 'CW';

    public const TIPO_INGRESO = 'I';

    public const AREA = '000';

    public const MONEDA_BASE = '01';

    /** Lo que Softland deja en blanco con ceros en vez de con NULL. */
    private const NEUTROS = [
        'CvCod' => '000',
        'VendCod' => '0000',
        'UbicCod' => '000',
        'CajCod' => '0000000000',
        'IfCod' => '000',
        'DgaCod' => '00000000',
        'CcCod' => '00000000',
        'MovIfCant' => 0,
        'MovDgCant' => 0,
        'CbaNumMov' => 0,
        'MtoTotal' => 0,
        'MovEquiv' => 1,
        'MovAEquiv' => 'S',
        'GrabaDLib' => 'S',
        'Marca' => 'N',
        'Impreso' => 'N',
        'CpbNormaIFRS' => 'S',
        'CpbNormaTrib' => 'S',
    ];

    public function __construct(private Cuentas $cuentas) {}

    /**
     * Del intento de cobro al asiento.
     *
     * @param  array  $intencion  cliente, fecha, glosa, usuario y `pagos`;
     *                            cada pago con medio, número, fecha y sus `aplicaciones`.
     * @return array{cabecera:array,filas:list<array>,debe:float,haber:float}
     */
    public function armar(array $intencion): array
    {
        $fecha = Carbon::parse($intencion['fecha'] ?? 'today')->startOfDay();
        $cliente = trim((string) ($intencion['cliente'] ?? ''));
        $cuentaCliente = $this->cuentas->cuentaCliente();

        if ($cliente === '') {
            abort(422, 'El cobro no dice de qué cliente es.');
        }
        if (! $cuentaCliente) {
            abort(422, 'No hay cuenta corriente de cliente configurada.');
        }

        $filas = [];
        $n = 0;
        $debe = $haber = 0.0;

        foreach ($intencion['pagos'] ?? [] as $pago) {
            $medio = $this->medio($pago);
            $ttd = $medio['ttd'];
            $numero = (float) ($pago['numero'] ?? 0);
            $fechaPago = Carbon::parse($pago['fecha'] ?? $fecha)->startOfDay();
            $suma = 0.0;

            foreach ($pago['aplicaciones'] ?? [] as $ap) {
                $monto = round((float) ($ap['monto'] ?? 0), 2);
                if ($monto <= 0) {
                    abort(422, 'Hay un abono sin importe.');
                }
                $suma += $monto;
                $haber += $monto;

                $filas[] = $this->fila($n++, $fecha, [
                    'PctCod' => $cuentaCliente,
                    'CodAux' => $cliente,
                    'TtdCod' => $ttd,
                    'NumDoc' => $numero,
                    'TipDocCb' => '00',
                    'NumDocCb' => 0,
                    'MovTipDocRef' => trim((string) ($ap['tipo'] ?? '00')),
                    'MovNumDocRef' => (float) ($ap['numero'] ?? 0),
                    'MovDebe' => 0,
                    'MovHaber' => $monto,
                    'MovDebeMa' => 0,
                    'MovHaberMa' => $monto,
                    // La emisión del documento que se paga; la fecha del pago
                    // va en MovFv. Ver la explicación de arriba.
                    'MovFe' => Carbon::parse($ap['fecha_documento'] ?? $fecha)->startOfDay(),
                    'MovFv' => $fechaPago,
                    'MovGlosa' => $this->recorta($ap['glosa'] ?? null, 255),
                ]);
            }

            if ($suma <= 0) {
                abort(422, 'Hay una forma de pago sin ningún documento abonado.');
            }

            $suma = round($suma, 2);
            $debe += $suma;

            $filas[] = $this->fila($n++, $fecha, [
                'PctCod' => $medio['cuenta'],
                'CodAux' => '0000000000',
                'TtdCod' => '00',
                'NumDoc' => 0,
                'TipDocCb' => $ttd,
                'NumDocCb' => $numero,
                'MovTipDocRef' => '00',
                'MovNumDocRef' => 0,
                'MovDebe' => $suma,
                'MovHaber' => 0,
                'MovDebeMa' => $suma,
                'MovHaberMa' => 0,
                'MovFe' => $fecha,
                'MovFv' => $fecha,
                'MovGlosa' => null,
            ]);
        }

        if (! $filas) {
            abort(422, 'El cobro no lleva ninguna forma de pago.');
        }
        if (round($debe - $haber, 2) !== 0.0) {
            abort(422, 'El comprobante no cuadra: debe '.$debe.' contra haber '.$haber.'.');
        }

        return [
            'cabecera' => [
                'AreaCod' => self::AREA,
                'CpbFec' => $fecha,
                'CpbMes' => $fecha->format('m'),
                'CpbEst' => 'V',
                'CpbTip' => self::TIPO_INGRESO,
                'CpbGlo' => $this->recorta($intencion['glosa'] ?? null, 60),
                'CpbImp' => 'N',
                // Va de la mano del estado: los 85 comprobantes vigentes
                // llevan 'S' y los 29 en borrador, 'N'. No es una tercera cosa.
                'CpbCon' => 'S',
                'Sistema' => self::SISTEMA,
                'Proceso' => 'Venta Softland',
                'Usuario' => $this->recorta($intencion['usuario'] ?? null, 8),
                'CpbNormaIFRS' => 'S',
                'CpbNormaTrib' => 'S',
                'CpbAnoRev' => '0000',
                'CpbNumRev' => '00000000',
                'TipoLog' => 'I',
            ],
            'filas' => $filas,
            'debe' => $debe,
            'haber' => $haber,
        ];
    }

    /**
     * El siguiente `CpbNum` del mes.
     *
     * No es IDENTITY y no hay tabla de correlativos: es `MAX + 1` dentro del
     * año y del **prefijo de mes**, que es lo que de verdad numera la serie.
     * Los ocho caracteres son `'000' + MM + NNN`, y la serie la comparten los
     * tres sistemas que escriben aquí (CW, IW y PW): contar sólo los nuestros
     * daría un número ya usado. Medido: 32 de los 35 grupos van contiguos
     * desde `MM000`, y los tres que no tienen **huecos** —comprobantes
     * borrados—, nunca repetidos.
     *
     * Ojo: `CpbMes` puede no coincidir con el prefijo (5 filas en INNOVAGES).
     * Manda el prefijo, que es parte de la clave primaria.
     */
    public function numeroSiguiente(string $ano, string $mes, ?string $base = null): string
    {
        $prefijo = '000'.$mes;

        $ultimo = DB::connection('softland')
            ->table($base ? "$base.softland.cwcpbte" : 'softland.cwcpbte')
            ->where('CpbAno', $ano)
            ->where('CpbNum', 'like', $prefijo.'%')
            ->lockForUpdate()
            ->max('CpbNum');

        $siguiente = $ultimo ? ((int) substr($ultimo, 5) + 1) : 0;

        if ($siguiente > 999) {
            abort(409, "No quedan números de comprobante para el mes $mes.");
        }

        return $prefijo.str_pad((string) $siguiente, 3, '0', STR_PAD_LEFT);
    }

    /**
     * El `CpbNui`, que es otro correlativo y va por año, mes y sistema.
     *
     * Se reproduce porque el ERP lo escribe, no porque sirva para algo nuestro:
     * no es único —IW y PW lo dejan en cero siempre— y por eso nada se busca
     * por él.
     */
    public function nuiSiguiente(string $ano, string $mes, ?string $base = null): string
    {
        $max = DB::connection('softland')
            ->table($base ? "$base.softland.cwcpbte" : 'softland.cwcpbte')
            ->where('CpbAno', $ano)
            ->where('CpbMes', $mes)
            ->where('Sistema', self::SISTEMA)
            ->max(DB::raw('CAST(CpbNui AS int)'));

        return str_pad((string) ((int) $max + 1), 8, '0', STR_PAD_LEFT);
    }

    // -------------------------------------------------------------- privado

    private function medio(array $pago): array
    {
        $codigo = trim((string) ($pago['medio'] ?? ''));
        $medio = $this->cuentas->medio($codigo);

        if (! $medio) {
            abort(422, "Forma de pago desconocida: «{$codigo}».");
        }
        if (! $medio['cuenta']) {
            abort(422, "La forma de pago «{$medio['rotulo']}» no tiene cuenta contable configurada.");
        }

        return $medio;
    }

    private function fila(int $n, Carbon $fecha, array $propio): array
    {
        return array_merge([
            'MovNum' => $n,
            'AreaCod' => self::AREA,
            'CpbFec' => $fecha,
            'CpbMes' => $fecha->format('m'),
            'MonCod' => self::MONEDA_BASE,
            'FecPag' => $fecha,
        ], self::NEUTROS, $propio);
    }

    private function recorta(?string $texto, int $largo): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : mb_substr($texto, 0, $largo);
    }
}
