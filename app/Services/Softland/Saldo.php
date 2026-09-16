<?php

namespace App\Services\Softland;

use App\Services\Dte\TipoDte;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto queda de una línea: de una cotización por convertir, de una nota de
 * venta por facturar.
 *
 * Es la pieza sobre la que se apoya todo el ciclo normal —cotización → varias
 * notas de venta → varias facturas, parcial en los dos saltos—. El plan
 * completo está en `docs/ciclo-normal.md`.
 *
 * ## Se calcula, no se guarda
 *
 * No hay ningún contador que sumar y restar. El saldo es una resta sobre los
 * documentos que existen ahora mismo, y por eso no puede desincronizarse:
 * borrar una nota de venta, anular una factura o corregir una cantidad cambian
 * el saldo solos, sin que nadie tenga que acordarse de deshacer nada.
 *
 * Softland tiene siete columnas pensadas justo para esto —`nvCantFact`,
 * `nvCantDesp`, `nvCantProd`, `nvCantOC`, `nvCantBoleta`, `nvCantNC`,
 * `nvCantDevuelto`— y **están muertas**: cero registros en las 3.824 líneas de
 * INNOVAGES y en las 3.824 de NETDOMAIN, aun con 941 facturas de NETDOMAIN
 * nacidas de una nota de venta. Mantenerlas nos haría el único proceso que las
 * escribe, y las ventanas del ERP dirían la verdad de lo que hizo la app y
 * mentira del resto.
 *
 * ## Sólo cuentan los documentos vivos
 *
 * Una nota de venta en `N` no consume cotización; una factura en `N` no consume
 * nota de venta. **Anular devuelve el saldo igual que borrar**: de las
 * cotizaciones con más de una nota de venta, 43 son exactamente eso —se anuló
 * la equivocada y se hizo otra con lo mismo—, y contarlas dejaría esas
 * cotizaciones consumidas dos veces.
 *
 * Los estados `P`, `A` y `C` sí consumen. Una nota de venta esperando la
 * aprobación del jefe está viva: si no consumiera, dos personas convertirían la
 * misma cotización mientras él decide.
 *
 * ## Dos saltos, dos enlaces, y sólo uno es nuestro
 *
 *   - **cotización → nota de venta**: `ventas.linea_origen`. Softland no tiene
 *     dónde guardarlo.
 *   - **nota de venta → factura**: `iw_gmovi.nvCorrela` → `nw_detnv.nvLinea`,
 *     nativo. Que sea del ERP compra algo que una tabla nuestra no puede: el
 *     saldo sale bien **también cuando factura el Softland de escritorio**.
 */
class Saldo
{
    private const CONN = 'softland';

    /** Documentos que no cuentan: los anulados. */
    private const NULO = 'N';

    /**
     * @param  string|null  $base  otra base de la misma instancia, para
     *                             comprobar contra historia sin tocar producción
     */
    public function __construct(private readonly ?string $base = null) {}

    /**
     * Qué queda por convertir de una cotización.
     *
     * `conocible` en falso significa que la cotización tiene notas de venta
     * vivas de las que no hay enlace de línea —convertidas antes de la app, o
     * desde el Softland de escritorio—. Ahí el saldo **no se sabe**, y decir
     * «queda todo» llevaría a convertirla dos veces. Se dice que no se sabe.
     *
     * @return array{conocible: bool, motivo: ?string, estado: ?string,
     *               lineas: list<array{linea: float, producto: string, cotizada: float,
     *                                  convertida: float, saldo: float}>}
     */
    public function deCotizacion(int $cotNum): array
    {
        $cot = $this->fila('nwcotiza', ['CotNum' => $cotNum]);

        if (! $cot) {
            return $this->vacio('La cotización no existe.');
        }

        $enlaces = $this->enlacesVivos($cotNum, $cot->FechaHoraCreacion ?? null);
        $conEnlace = count(array_unique(array_column($enlaces, 'nv_numero')));
        $vivas = $this->notasDeVentaVivas($cotNum);

        $consumido = [];

        foreach ($enlaces as $e) {
            $clave = self::clave($e['cot_linea']);
            $consumido[$clave] = ($consumido[$clave] ?? 0) + (float) $e['cantidad'];
        }

        $lineas = [];

        foreach ($this->detalle('nwdetcot', 'CotNum', $cotNum, 'CtLinea') as $l) {
            $cotizada = (float) $l->CtCant;
            $convertida = $consumido[self::clave($l->CtLinea)] ?? 0.0;

            $lineas[] = [
                'linea' => (float) $l->CtLinea,
                'producto' => trim((string) $l->CodProd),
                'cotizada' => $cotizada,
                'convertida' => $convertida,
                'saldo' => self::linea($cotizada, $convertida),
            ];
        }

        // La comparación que decide si esto se puede afirmar: hay notas de
        // venta vivas colgando de la cotización de las que no sabemos qué se
        // llevaron.
        $ciegas = count($vivas) - $conEnlace;

        return [
            'conocible' => $ciegas <= 0,
            'motivo' => $ciegas > 0
                ? "{$ciegas} nota(s) de venta viva(s) sin enlace de línea: se convirtieron fuera de la app."
                : null,
            'estado' => $cot->CtEstado ?? null,
            'lineas' => $lineas,
        ];
    }

    /**
     * Qué queda por facturar de una nota de venta.
     *
     * `no_atribuido` cuenta las líneas de nota de crédito que devuelven algo
     * pero no dicen a qué línea de la nota de venta: en NETDOMAIN sólo 84 de
     * 320 traen `nvCorrela`. Se informa en vez de repartirlas a ojo.
     *
     * @return array{conocible: bool, motivo: ?string, estado: ?string, no_atribuido: float,
     *               lineas: list<array{linea: float, producto: string, pedida: float,
     *                                  facturada: float, acreditada: float, saldo: float}>}
     */
    public function deNotaVenta(int $nvNumero): array
    {
        $nv = $this->fila('nw_nventa', ['NVNumero' => $nvNumero]);

        if (! $nv) {
            return $this->vacio('La nota de venta no existe.') + ['no_atribuido' => 0.0];
        }

        $facturado = $this->facturado($nvNumero);
        [$acreditado, $noAtribuido] = $this->acreditado($nvNumero);

        $lineas = [];

        foreach ($this->detalle('nw_detnv', 'NVNumero', $nvNumero, 'nvLinea') as $l) {
            $clave = self::clave($l->nvLinea);
            $pedida = (float) $l->nvCant;
            $fact = $facturado[$clave] ?? 0.0;
            $acre = $acreditado[$clave] ?? 0.0;

            $lineas[] = [
                'linea' => (float) $l->nvLinea,
                'producto' => trim((string) $l->CodProd),
                'pedida' => $pedida,
                'facturada' => $fact,
                'acreditada' => $acre,
                'saldo' => self::linea($pedida, $fact, $acre),
            ];
        }

        return [
            'conocible' => $noAtribuido <= 0,
            'motivo' => $noAtribuido > 0
                ? "Hay {$noAtribuido} unidad(es) acreditadas por nota de crédito sin línea de origen."
                : null,
            'estado' => $nv->nvEstado ?? null,
            'no_atribuido' => $noAtribuido,
            'lineas' => $lineas,
        ];
    }

    /**
     * Lo facturado por línea de nota de venta, sumando **sólo documentos
     * vigentes**: una factura anulada en el ERP deja de consumir.
     *
     * Se suma también la boleta: es otra forma de facturar la misma línea.
     *
     * @return array<string, float>
     */
    private function facturado(int $nvNumero): array
    {
        $filas = DB::connection(self::CONN)
            ->table($this->califica('iw_gmovi').' AS m')
            ->join($this->califica('iw_gsaen').' AS s', function ($j) {
                $j->on('s.Tipo', '=', 'm.Tipo')->on('s.NroInt', '=', 'm.NroInt');
            })
            ->where('s.nvnumero', $nvNumero)
            ->whereIn('s.Tipo', ['F', 'B'])
            ->where('s.Estado', '<>', self::NULO)
            ->where('m.nvCorrela', '>', 0)
            ->selectRaw('m.nvCorrela AS linea, SUM(m.CantFacturada) AS cantidad')
            ->groupBy('m.nvCorrela')
            ->get();

        $suma = [];

        foreach ($filas as $f) {
            $suma[self::clave($f->linea)] = (float) $f->cantidad;
        }

        return $suma;
    }

    /**
     * Lo devuelto por nota de crédito.
     *
     * ## Dónde dice una nota de crédito qué acredita
     *
     * En `IW_GSaEn_RefDTE`, que es la tabla de referencias del DTE: `CodRefSII`
     * con el tipo del SII del documento referido y `FolioRef` con su folio. Es
     * la misma tabla que escribe `Facturacion` al emitir.
     *
     * **No en `AuxDocNum`**, que es lo que parecía: ahí coincidía en INNOVAGES
     * por casualidad, pero 5.317 facturas de NETDOMAIN también lo llevan
     * relleno, o sea que no es «a qué documento acredito» sino un número
     * auxiliar cualquiera. Cruzar por ahí emparejaba notas de crédito con notas
     * de venta que no tenían nada que ver.
     *
     * Se compara el tipo además del folio porque **los folios se repiten entre
     * tipos**: en NETDOMAIN hay folios que existen a la vez como factura y como
     * boleta.
     *
     * **La cantidad viene en negativo** y se devuelve en positivo. Softland
     * escribe la nota de crédito con `esDevolucion = -1` y `CantFacturada =
     * -1.0`: es la misma línea de la factura con el signo cambiado. Sumarla tal
     * cual restaba dos veces, y una línea pedida 1 y facturada 1 daba saldo -1,
     * que no es un número posible.
     *
     * Las líneas que no traen `nvCorrela` no se pueden atribuir: se cuentan
     * aparte en vez de repartirlas a ojo. En NETDOMAIN sólo 84 de 320 lo traen.
     *
     * @return array{0: array<string, float>, 1: float}
     */
    private function acreditado(int $nvNumero): array
    {
        $referibles = $this->documentosQueConsumen($nvNumero);

        if ($referibles === []) {
            return [[], 0.0];
        }

        $filas = DB::connection(self::CONN)
            ->table($this->califica('iw_gmovi').' AS m')
            ->join($this->califica('iw_gsaen').' AS s', function ($j) {
                $j->on('s.Tipo', '=', 'm.Tipo')->on('s.NroInt', '=', 'm.NroInt');
            })
            ->join($this->califica('IW_GSaEn_RefDTE').' AS r', function ($j) {
                $j->on('r.Tipo', '=', 's.Tipo')->on('r.NroInt', '=', 's.NroInt');
            })
            ->where('s.Tipo', 'N')
            ->where('s.Estado', '<>', self::NULO)
            ->where(function ($w) use ($referibles) {
                foreach ($referibles as $d) {
                    $w->orWhere(function ($p) use ($d) {
                        $p->where('r.CodRefSII', (string) $d['sii'])
                            ->where('r.FolioRef', $d['folio']);
                    });
                }
            })
            ->selectRaw('m.nvCorrela AS linea, m.FactNumLin AS lineaFactura, '
                .'r.CodRefSII AS sii, r.FolioRef AS folio, SUM(m.CantFacturada) AS cantidad')
            ->groupBy('m.nvCorrela', 'm.FactNumLin', 'r.CodRefSII', 'r.FolioRef')
            ->get();

        $suma = [];
        $noAtribuido = 0.0;

        foreach ($filas as $f) {
            $linea = (float) $f->linea;

            // Cuando la línea de la nota de crédito no dice a qué línea de nota
            // de venta devuelve, se llega por el otro camino: `FactNumLin` dice
            // qué línea de la factura devuelve, y esa sí lleva `nvCorrela`. Son
            // dos saltos en vez de uno y llegan al mismo sitio.
            if ($linea <= 0 && (float) $f->lineaFactura > 0) {
                $linea = $this->lineaDeNotaVenta((string) $f->sii, (string) $f->folio, (float) $f->lineaFactura);
            }

            if ($linea > 0) {
                $clave = self::clave($linea);
                $suma[$clave] = ($suma[$clave] ?? 0) + abs((float) $f->cantidad);

                continue;
            }

            $noAtribuido += abs((float) $f->cantidad);
        }

        return [$suma, $noAtribuido];
    }

    /**
     * El segundo salto: de una línea de factura a la línea de nota de venta que
     * consumía.
     *
     * Hace falta porque las dos columnas se reparten el trabajo sin ponerse de
     * acuerdo. De las 320 líneas de nota de crédito de NETDOMAIN, 84 traen
     * `nvCorrela` y sólo 13 traen `FactNumLin`; en INNOVAGES es al revés — las
     * 12 traen `FactNumLin` y sólo 2 `nvCorrela`. Mirar una sola columna deja
     * fuera a la mayoría en una de las dos empresas.
     */
    private function lineaDeNotaVenta(string $sii, string $folio, float $lineaFactura): float
    {
        $tipo = TipoDte::tryFrom((int) $sii);

        if (! $tipo) {
            return 0.0;
        }

        [$letra] = $tipo->claveSoftland();

        $valor = DB::connection(self::CONN)
            ->table($this->califica('iw_gmovi').' AS m')
            ->join($this->califica('iw_gsaen').' AS s', function ($j) {
                $j->on('s.Tipo', '=', 'm.Tipo')->on('s.NroInt', '=', 'm.NroInt');
            })
            ->where('s.Tipo', $letra)
            ->where('s.Folio', (int) $folio)
            ->where('m.Linea', $lineaFactura)
            ->value('m.nvCorrela');

        return (float) ($valor ?? 0);
    }

    /**
     * Los documentos de esta nota de venta que **consumieron** algo: los que
     * tienen al menos una línea enlazada por `nvCorrela`.
     *
     * Una factura de comisión, con todas sus líneas sin enlace, no se llevó
     * saldo de ninguna línea, así que su nota de crédito tampoco tiene nada que
     * devolver. Sin este filtro las diez notas de crédito de comisión de
     * INNOVAGES salían como «no atribuibles», que suena a dato perdido y no lo es.
     *
     * @return list<array{sii: int, folio: string}>
     */
    private function documentosQueConsumen(int $nvNumero): array
    {
        $docs = DB::connection(self::CONN)->table($this->califica('iw_gsaen').' AS s')
            ->where('s.nvnumero', $nvNumero)
            ->whereIn('s.Tipo', ['F', 'B'])
            ->where('s.Estado', '<>', self::NULO)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from($this->califica('iw_gmovi').' AS m')
                    ->whereColumn('m.Tipo', 's.Tipo')
                    ->whereColumn('m.NroInt', 's.NroInt')
                    ->where('m.nvCorrela', '>', 0);
            })
            ->select('s.Tipo', 's.SubTipoDocto', 's.Folio')
            ->get();

        $referibles = [];

        foreach ($docs as $d) {
            $tipo = TipoDte::desdeSoftland($d->Tipo, $d->SubTipoDocto);

            if (! $tipo) {
                continue;
            }

            $referibles[] = ['sii' => $tipo->value, 'folio' => (string) (int) $d->Folio];
        }

        return $referibles;
    }

    /**
     * Los enlaces de una cotización cuya punta de nota de venta sigue viva.
     *
     * Vivos quiere decir tres cosas a la vez: que la nota de venta exista, que
     * no esté anulada, y que **sea la misma** que se enlazó —las marcas de
     * creación tienen que coincidir, porque un número borrado se vuelve a
     * repartir—.
     *
     * @return list<array{nv_numero: int, cot_linea: float, cantidad: float}>
     */
    private function enlacesVivos(int $cotNum, ?string $creadoEn): array
    {
        $q = DB::connection(self::CONN)
            ->table($this->califica('ventas.linea_origen', esquemaPropio: true).' AS e')
            ->join($this->califica('nw_nventa').' AS v', 'v.NVNumero', '=', 'e.nv_numero')
            ->where('e.cot_num', $cotNum)
            ->where('v.nvEstado', '<>', self::NULO)
            ->select('e.nv_numero', 'e.cot_linea', 'e.cantidad');

        // Las filas viejas no tienen marca: de esas sólo se puede comprobar que
        // el documento siga existiendo.
        $creadoEn && $q->where(function ($w) use ($creadoEn) {
            $w->whereNull('e.cot_creado_en')->orWhere('e.cot_creado_en', $creadoEn);
        });

        $q->where(function ($w) {
            $w->whereNull('e.nv_creado_en')->orWhereColumn('e.nv_creado_en', 'v.FechaHoraCreacion');
        });

        return array_map(fn ($f) => (array) $f, $q->get()->all());
    }

    /** @return list<object> */
    private function notasDeVentaVivas(int $cotNum): array
    {
        return DB::connection(self::CONN)->table($this->califica('nw_nventa'))
            ->where('CotNum', $cotNum)
            ->where('nvEstado', '<>', self::NULO)
            ->select('NVNumero')
            ->get()->all();
    }

    /** @return list<object> */
    private function detalle(string $tabla, string $clave, int $numero, string $orden): array
    {
        return DB::connection(self::CONN)->table($this->califica($tabla))
            ->where($clave, $numero)->orderBy($orden)->get()->all();
    }

    private function fila(string $tabla, array $donde): ?object
    {
        $q = DB::connection(self::CONN)->table($this->califica($tabla));

        foreach ($donde as $col => $valor) {
            $q->where($col, $valor);
        }

        return $q->first();
    }

    /**
     * El saldo de una línea, que es la misma resta en los dos saltos.
     *
     * `devuelta` va en **positivo**: devolver suma. Softland escribe la línea de
     * la nota de crédito con la cantidad en negativo —`esDevolucion = -1`,
     * `CantFacturada = -1.0`, la misma línea de la factura con el signo
     * cambiado—, así que quien la lea tiene que darle la vuelta antes de llegar
     * aquí. Pasarla tal cual restaba dos veces: una línea pedida 1 y facturada 1
     * daba saldo -1, que no es un número posible.
     *
     * Puede salir negativo, y no es un error: facturar de más está permitido.
     * En NETDOMAIN hay notas de venta de doce mensualidades con catorce
     * facturas.
     */
    public static function linea(float $pedida, float $consumida, float $devuelta = 0.0): float
    {
        return $pedida - $consumida + abs($devuelta);
    }

    /**
     * La línea como clave de arreglo.
     *
     * `nvLinea` y `CtLinea` son `float` en Softland y valen 1.0, 2.0… Compararlas
     * como números sueltos invita a que `1.0` y `1` no se encuentren; se pasan
     * por una forma fija y se acabó.
     */
    public static function clave(mixed $linea): string
    {
        return number_format((float) $linea, 2, '.', '');
    }

    private function vacio(string $motivo): array
    {
        return ['conocible' => false, 'motivo' => $motivo, 'estado' => null, 'lineas' => []];
    }

    /**
     * Las tablas del ERP se mueven con `--base`; **el esquema `ventas` no**.
     *
     * Lo nuestro vive en una sola base, la de la instalación, aunque se esté
     * mirando la historia de otra. Y así tiene que ser también para el
     * resultado: los documentos de NETDOMAIN no tienen enlace de línea en
     * nuestra tabla, y que la consulta devuelva cero filas es la respuesta
     * correcta —«no se sabe»—, no un error de objeto inexistente.
     */
    private function califica(string $objeto, bool $esquemaPropio = false): string
    {
        if ($esquemaPropio) {
            return $objeto;
        }

        return $this->base ? "{$this->base}.softland.{$objeto}" : "softland.{$objeto}";
    }
}
