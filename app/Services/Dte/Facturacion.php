<?php

namespace App\Services\Dte;

use App\Services\Softland\Totales;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Escribe el documento de venta en inventario y facturación: `iw_gsaen` más
 * `iw_gmovi`.
 *
 * Es lo más delicado que hace esta app. Un documento mal escrito no se nota en
 * la pantalla: se nota cuando alguien cuadra el mes.
 *
 * ## Hasta dónde llega, y por qué para ahí
 *
 * Deja el documento en IW con su folio. **No centraliza**: no escribe
 * `CpbAnoVentas` ni `CpbNumVentas`, no toca `cwcpbte`, `cwmovim` ni la cuenta
 * corriente del cliente. Eso es un procedimiento aparte que se corre desde el
 * propio Softland, y ocurre después, no al emitir. Escribir esas columnas
 * nosotros sería decirle a la contabilidad que un asiento ya existe cuando no.
 *
 * ## La factura no es la nota de venta
 *
 * En INNOVAGES la factura casi nunca es la proyección de su nota de venta: de
 * 192 facturas enlazadas a una NV, **190 van a un cliente distinto** —188 a
 * Softland Ingeniería— con una sola línea de comisión, y `nvCantFact` está en
 * cero en todas las líneas de todas las notas de venta. Es un negocio de
 * distribuidor: la NV registra la venta al cliente final y la factura cobra la
 * comisión a Softland, que es quien paga.
 *
 * Por eso aquí no hay una función «facturar la nota de venta». Hay una que
 * escribe el documento que se le pida, y la nota de venta —cuando la hay— entra
 * como **referencia** (`nvnumero`) y como sugerencia de líneas y de receptor,
 * no como fuente obligatoria. El mismo camino sirve para los tres casos: la
 * comisión, la factura al cliente de la NV y la factura suelta sin NV detrás.
 *
 * ## El correlativo y el folio son dos cosas
 *
 * `NroInt` es el número interno del documento en IW, y se calcula como el resto
 * de los correlativos de Softland: máximo más uno bajo `UPDLOCK, HOLDLOCK`, con
 * reintento si Softland de escritorio se lo lleva entremedio.
 *
 * El **folio** es el número tributario, y ese no se calcula: lo reparte
 * `DTE_pdblEntregaFolioDTE`, el procedimiento del propio Softland, que recorre
 * los rangos del CAF, salta los anulados y reserva el número. Llamándolo no se
 * le disputa la numeración al ERP. Y un folio gastado no vuelve.
 */
class Facturacion
{
    private const CONN = 'softland';

    private const REINTENTOS = 5;

    /**
     * @param  string|null  $base  otra base de la misma instancia, para ensayar
     *                             sin tocar producción. En producción va en null.
     */
    public function __construct(private readonly ?string $base = null) {}

    /**
     * Escribe el documento y devuelve cómo quedó identificado.
     *
     * @param  array{
     *     tipo: TipoDte,
     *     receptor: string,
     *     vendedor: string,
     *     usuario: string,
     *     fecha?: string,
     *     bodega?: string,
     *     moneda?: string,
     *     cond_pago?: string|null,
     *     centro_costo?: string|null,
     *     canal?: string|null,
     *     contacto?: string|null,
     *     glosa?: string|null,
     *     nota_venta?: int|null,
     *     tipo_trans?: int,
     *     forma_pago?: int,
     *     referencia?: array{folio: int, fecha: string, tipo?: string, subtipo?: string,
     *                        glosa?: string|null, codigo?: string|null, razon?: string|null}|null,
     *     descuento_pct?: float,
     *     lineas: array<int, array{producto: string, cantidad: float, precio: float, glosa?: string|null,
     *                              unidad?: string|null, afecto?: bool, descuento_pct?: float,
     *                              equiv?: float, nv_linea?: int|null}>
     * }  $spec
     * @return array{tipo: string, nroint: int, folio: int}
     */
    public function escribir(array $spec): array
    {
        $tipo = $spec['tipo'];
        [$letra, $subTipo] = $tipo->claveSoftland();

        if (trim((string) ($spec['vendedor'] ?? '')) === '') {
            // Misma regla que la cotización y la nota de venta: un documento sin
            // vendedor no sale en las ventanas de búsqueda del ERP.
            throw new RuntimeException('Un documento de venta sin vendedor no existe para Softland.');
        }

        if (($spec['lineas'] ?? []) === []) {
            throw new RuntimeException('Un documento sin líneas no se escribe.');
        }

        // ## El signo se aplica fuera de la aritmética
        //
        // En una nota de crédito el signo vive en la **cantidad** —cantidad −1 y
        // precio positivo, así lo escribe Softland—, y eso deja el bruto
        // negativo. `Totales` está escrito para cotizaciones y notas de venta,
        // que nunca lo son: su reparto entre afecto y exento se salta cuando el
        // bruto no es positivo, y devuelve todo como exento y el IVA en cero.
        //
        // No se toca `Totales`: está contrastado contra 200 cotizaciones reales
        // y cambiarlo obliga a rehacer esa comprobación entera. Se calcula en
        // positivo y se le pone el signo al final, que es lo mismo y no mueve
        // nada de lo que ya está probado.
        $signo = self::signo($spec['lineas']);

        $totales = Totales::calcular(
            array_map(fn ($l) => [
                'cantidad' => abs((float) $l['cantidad']),
                'precio' => (float) $l['precio'],
                'equiv' => self::equivalencia($l['equiv'] ?? null),
                'afecto' => (bool) ($l['afecto'] ?? $tipo->afecto()),
                'descuento_pct' => (float) ($l['descuento_pct'] ?? 0),
            ], $spec['lineas']),
            (float) ($spec['descuento_pct'] ?? 0),
        );

        return $this->conReintento(function () use ($spec, $tipo, $letra, $subTipo, $totales, $signo) {
            return DB::connection(self::CONN)->transaction(function () use ($spec, $tipo, $letra, $subTipo, $totales, $signo) {
                $nroInt = (int) $this->bloqueada('iw_gsaen')->where('Tipo', $letra)->max('NroInt') + 1;
                $folio = $this->folio($tipo);

                $this->tabla('iw_gsaen')->insert(
                    $this->encabezado($spec, $letra, $subTipo, $nroInt, $folio, $totales, $signo)
                );

                foreach ($totales['lineas'] as $i => $calculada) {
                    $this->tabla('iw_gmovi')->insert(
                        $this->linea($spec, $letra, $nroInt, $i + 1, $spec['lineas'][$i], $calculada, $signo)
                    );
                }

                $this->referencia($spec, $letra, $nroInt);

                return ['tipo' => $letra, 'nroint' => $nroInt, 'folio' => $folio];
            });
        });
    }

    /**
     * Pide el folio al repartidor de Softland, reservándolo.
     *
     * `@GuardaFolio = 1` deja una fila mínima en `dte_doccab` con el número
     * tomado: es así como el ERP evita que dos personas se lleven el mismo. Esa
     * fila se completa al emitir el DTE.
     *
     * Devuelve `-1` cuando no quedan folios en ningún CAF cargado, y entonces
     * no hay nada que hacer salvo pedirle más al SII.
     */
    private function folio(TipoDte $tipo): int
    {
        $sp = $this->califica('DTE_pdblEntregaFolioDTE');
        // `SET NOCOUNT ON` no es adorno: el procedimiento inserta la reserva del
        // folio en `dte_doccab` y, sin esto, el contador de filas de ese INSERT
        // viaja como si fuera el primer resultado. El driver se queda con él y
        // el `select` del folio no llega nunca.
        $fila = DB::connection(self::CONN)->select("SET NOCOUNT ON; EXEC {$sp} ?, 1", [$tipo->value]);

        // El procedimiento devuelve un `select` de una sola columna sin nombre,
        // así que se toma por posición.
        $valores = array_values((array) ($fila[0] ?? []));
        $folio = (int) ($valores[0] ?? -1);

        if ($folio <= 0) {
            throw new RuntimeException(
                "No quedan folios disponibles para {$tipo->nombre()} ({$tipo->value}). Hay que cargar un CAF nuevo."
            );
        }

        return $folio;
    }

    /** @return array<string, mixed> */
    private function encabezado(array $spec, string $letra, string $subTipo, int $nroInt, int $folio, array $totales, int $signo): array
    {
        $fecha = $spec['fecha'] ?? date('Y-m-d');
        $m = self::montos($totales, $signo);
        $ref = $spec['referencia'] ?? null;

        return [
            'Tipo' => $letra,
            'NroInt' => $nroInt,
            'SubTipoDocto' => $subTipo,
            'Folio' => $folio,
            'CodBode' => $spec['bodega'] ?? 'GEN',
            'Concepto' => '01',
            'Estado' => 'V',
            'Fecha' => $fecha,
            'FechaVenc' => $this->vencimiento($fecha, $spec['cond_pago'] ?? null),
            'Glosa' => $spec['glosa'] ?? null,
            'AuxTipo' => 'A',
            'CodAux' => $spec['receptor'],
            'CodVendedor' => $spec['vendedor'],
            'CodMoneda' => $spec['moneda'] ?? '01',

            // Cero en la factura, uno en la nota de crédito. Softland deja
            // `Equivalencia` en cero en el encabezado de las 197 facturas y en
            // uno en las 12 notas de crédito. No tiene lógica aparente; es lo
            // que escribe, y se reproduce.
            'Equivalencia' => $signo < 0 ? 1.0 : 0.0,

            'Usuario' => $spec['usuario'],

            // Los netos van **redondeados a peso**, aunque el cálculo dé
            // decimales. En la nota de venta no: ahí `nvNetoAfecto` guarda los
            // centavos. Y el IVA se calcula sobre el neto **ya redondeado**, no
            // sobre el decimal: con un neto de 2.314.102,5 la diferencia es un
            // peso en el IVA y otro en el total, y es lo que separaba la
            // factura 187 de la nuestra.
            'NetoAfecto' => $m['afecto'],
            'NetoExento' => $m['exento'],
            'IVA' => $m['iva'],
            'SubTotal' => $m['subtotal'],
            'Total' => $m['total'],
            'TotalDesc' => $totales['descuento'],
            'PorcDesc01' => (float) ($spec['descuento_pct'] ?? 0),
            'Descto01' => $totales['descuento'],

            'CentroDeCosto' => $spec['centro_costo'] ?? null,
            'CondPago' => $spec['cond_pago'] ?? null,
            'CanCod' => $spec['canal'] ?? null,
            'NomContacto' => $this->recorta($spec['contacto'] ?? null, 30),
            'nvnumero' => $spec['nota_venta'] ?? 0,

            'Sistema' => 'IW',
            'Proceso' => 'Venta Softland',
            'TtdCod' => $spec['ttd'] ?? 'EL',
            'FecHoraCreacion' => date('Y-m-d H:i:s'),

            'FactorCostoImportacion' => 1.0,
            'TipoServicioSII' => 3,
            // Casi siempre 1 y 2, pero no siempre: de las 197 facturas hay una
            // con `TipoTrans` 2 y otra con `FmaPago` 1. Son datos del
            // documento, no constantes.
            'TipoTrans' => (int) ($spec['tipo_trans'] ?? 1),
            'FmaPago' => (int) ($spec['forma_pago'] ?? 2),



            // ## La referencia al documento corregido
            //
            // Una nota de crédito sin referencia no es una nota de crédito: el
            // SII exige saber qué documento corrige, y Softland lo guarda dos
            // veces —aquí, para su propia ventana, y en `IW_GSaEn_RefDTE`, para
            // el XML—. Las 12 notas de crédito de INNOVAGES apuntan todas a una
            // factura que existe.
            'AuxDocNum' => $ref['folio'] ?? null,
            'AuxDocfec' => $ref['fecha'] ?? null,
            'TipDocRef' => $ref ? ($ref['tipo'] ?? 'F') : null,
            'SubTipDocRef' => $ref ? ($ref['subtipo'] ?? 'T') : null,

            // `-1` es el «sí» de Softland. Marca el documento como devolución,
            // que es lo que lo distingue de una venta con signo cambiado.
            'esDevolucion' => $signo < 0 ? -1 : 0,

            // `CpbAnoVentas` y `CpbNumVentas` se dejan fuera a propósito: los
            // escribe la centralización, que es otro procedimiento y ocurre
            // después.
        ];
    }

    /** @return array<string, mixed> */
    private function linea(array $spec, string $letra, int $nroInt, int $n, array $original, array $calculada, int $signo): array
    {
        return [
            'Tipo' => $letra,
            'NroInt' => $nroInt,
            'Linea' => $n,
            'CodProd' => $original['producto'],
            'CodBode' => $spec['bodega'] ?? 'GEN',
            'Fecha' => $spec['fecha'] ?? date('Y-m-d'),
            // El signo va en la cantidad, no en el precio: cantidad −1 y precio
            // positivo. Es como lo escribe Softland en sus 12 notas de crédito,
            // y como lo espera el XML del SII.
            'CantFacturada' => abs((float) $original['cantidad']) * $signo,
            'CantFactUVta' => abs((float) $original['cantidad']) * $signo,
            'PreUniMB' => abs((float) $original['precio']),
            'TotLinea' => round($calculada['total']) * $signo,
            'PorcDescMov01' => (float) ($original['descuento_pct'] ?? 0),
            'DescMov01' => $calculada['descuento'],
            'TotalDescMov' => $calculada['descuento'],
            'Equivalencia' => self::equivalencia($original['equiv'] ?? null),

            // `-1` es el «sí» de Softland, heredado de Visual Basic. Marca la
            // línea como ya reflejada en los saldos.
            'Actualizado' => -1,

            'DetProd' => $original['glosa'] ?? null,

            // «D» en la factura, «N» en la nota de crédito.
            'TipoOrigen' => $signo < 0 ? 'N' : 'D',
            'TipoDestino' => 'N',

            // Qué línea del documento corregido devuelve esta.
            'FactNumLin' => $original['linea_referencia'] ?? null,
            'AuxTipo' => 'A',
            'CodAux' => $spec['receptor'],
            'CodUMed' => $original['unidad'] ?? 'UN',

            // El centro de costo va en la línea de la nota de crédito y **no**
            // en la de la factura. No es una decisión nuestra: Softland lo llena
            // en 9 de 10 líneas de nota de crédito y solo en 5 de 199 de
            // factura, donde vive en el encabezado (`CentroDeCosto`).
            'CodiCC' => $signo < 0 ? ($spec['centro_costo'] ?? null) : null,

            // Cero, no nulo, cuando la línea no viene de una nota de venta.
            'nvCorrela' => $original['nv_linea'] ?? 0,
        ];
    }

    /**
     * Los montos del encabezado, a partir de la aritmética de `Totales`.
     *
     * Dos reglas que no son obvias y que costaron encontrarse comparando contra
     * 199 documentos reales:
     *
     *  1. Los netos van **redondeados a peso**. En la nota de venta no:
     *     `nvNetoAfecto` guarda los centavos. En `iw_gsaen` no.
     *  2. El IVA se calcula **sobre el neto ya redondeado**, no sobre el
     *     decimal. Con un neto de 2.314.102,5 eso vale un peso en el IVA y otro
     *     en el total — es exactamente lo que separaba la factura 187 de la
     *     nuestra.
     *
     * @return array{afecto: float, exento: float, iva: float, subtotal: float, total: float}
     */
    public static function montos(array $totales, int $signo = 1): array
    {
        $afecto = round($totales['afecto']);
        $iva = round($afecto * $totales['iva_pct'] / 100);

        return [
            'afecto' => $afecto * $signo,
            'exento' => round($totales['exento']) * $signo,
            'iva' => $iva * $signo,
            'subtotal' => $totales['subtotal'] * $signo,
            'total' => ($totales['subtotal'] - $totales['descuento']) * $signo + $iva * $signo,
        ];
    }

    /**
     * La referencia al documento corregido, en la tabla que alimenta el XML.
     *
     * `IW_GSaEn_RefDTE` es de donde sale el `<Referencia>` del DTE. Va con el
     * código **del SII** (33 para la factura), no con el de Softland.
     */
    private function referencia(array $spec, string $letra, int $nroInt): void
    {
        $ref = $spec['referencia'] ?? null;

        if (! $ref) {
            return;
        }

        $tipoRef = TipoDte::desdeSoftland($ref['tipo'] ?? 'F', $ref['subtipo'] ?? 'T');

        $this->tabla('IW_GSaEn_RefDTE')->insert([
            'Tipo' => $letra,
            'NroInt' => $nroInt,
            'LineaRef' => 1,
            'CodRefSII' => (string) ($tipoRef?->value ?? TipoDte::FACTURA->value),
            'FolioRef' => $ref['folio'],
            'FechaRef' => $ref['fecha'],
            'Glosa' => $ref['glosa'] ?? null,
            'CodRef' => $ref['codigo'] ?? null,
            'RazonRef' => $ref['razon'] ?? null,
        ]);
    }

    /**
     * Hacia dónde va el documento: +1 una venta, −1 una devolución.
     *
     * Se deduce de las cantidades, no del tipo: es el dato el que manda, y así
     * no hay que acordarse de pasar una bandera aparte.
     */
    public static function signo(array $lineas): int
    {
        foreach ($lineas as $l) {
            if ((float) $l['cantidad'] < 0) {
                return -1;
            }
        }

        return 1;
    }

    /**
     * El factor de conversión de la línea, con el cero tratado como uno.
     *
     * Hay líneas con `Equivalencia` en cero y su `TotLinea` no es cero: para
     * Softland, un factor vacío es «misma moneda», no «multiplica por nada».
     * Tomarlo al pie de la letra deja la línea en cero y el documento cuadrado
     * en la nada.
     */
    public static function equivalencia(mixed $v): float
    {
        $f = (float) $v;

        return $f > 0 ? $f : 1.0;
    }

    /** La fecha de vencimiento sale de los días de la condición de venta. */
    private function vencimiento(string $fecha, ?string $condPago): string
    {
        if (! $condPago) {
            return $fecha;
        }

        $dias = (int) ($this->tabla('cwtconv')->where('CveCod', $condPago)->value('CveDias') ?? 0);

        return date('Y-m-d', strtotime($fecha." +{$dias} days"));
    }

    private function recorta(?string $v, int $largo): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $largo);
    }

    private function tabla(string $t)
    {
        return DB::connection(self::CONN)->table($this->califica($t));
    }

    private function bloqueada(string $t)
    {
        return DB::connection(self::CONN)->table(DB::raw($this->califica($t).' WITH (UPDLOCK, HOLDLOCK)'));
    }

    /** El nombre completo, apuntando a la base de pruebas cuando corresponde. */
    private function califica(string $objeto): string
    {
        return $this->base ? "{$this->base}.softland.{$objeto}" : "softland.{$objeto}";
    }

    /** @return array{tipo: string, nroint: int, folio: int} */
    private function conReintento(callable $fn): array
    {
        for ($i = 1; ; $i++) {
            try {
                return $fn();
            } catch (UniqueConstraintViolationException $e) {
                if ($i >= self::REINTENTOS) {
                    throw $e;
                }
                usleep(random_int(20_000, 120_000));
            }
        }
    }
}
