<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\Dte\Caf;
use App\Services\Dte\Facturacion;
use App\Services\Dte\ReglasFactura;
use App\Services\Dte\TipoDte;
use App\Services\Softland\Saldo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Facturar: lo que queda por facturar de una nota de venta, y emitirlo.
 *
 * ## Esto gasta un folio, y un folio no se devuelve
 *
 * Es la única pantalla de la app que consume algo irrecuperable. De ahí que
 * `propuesta()` diga **cuántos folios quedan** antes de que nadie apriete nada:
 * enterarse de que no hay después de teclear el documento es la peor forma de
 * enterarse.
 *
 * ## Lo que decide el servidor y lo que decide quien factura
 *
 * Quien factura elige **la cantidad** de cada línea y qué líneas agrega. El
 * precio, el factor de conversión y el descuento **los pone la nota de venta**
 * y no se pueden tocar; el receptor también, salvo que esté encendida la llave
 * de configuración. Eso no se comprueba aquí sino en `Facturacion`, que es
 * donde no lo puede esquivar ningún camino.
 *
 * El saldo **sugiere y no limita**: se puede facturar de más y agregar
 * productos que no estaban. La app avisa; no bloquea.
 */
class FacturaController extends Controller
{
    public function __construct(
        private Facturacion $facturacion,
        private Saldo $saldo,
        private ReglasFactura $reglas,
    ) {}

    /**
     * Qué facturar de esta nota de venta.
     *
     * Devuelve lo pendiente con su cantidad, el receptor que corresponde, si se
     * puede cambiar y cuántos folios quedan.
     */
    public function propuesta(Request $request, int $numero)
    {
        $nv = $this->notaVenta($numero);

        if (! $nv || ! $this->alcanza($request, $nv->VenCod)) {
            return response()->json(['message' => 'Esa nota de venta no existe o no es tuya.'], 404);
        }

        $estado = strtoupper(trim((string) $nv->nvEstado));

        if (in_array($estado, ['N', 'P'], true)) {
            return response()->json([
                'message' => $estado === 'N'
                    ? 'Esta nota de venta está anulada.'
                    : 'Esta nota de venta todavía espera aprobación.',
            ], 409);
        }

        $saldo = $this->saldo->deNotaVenta($numero);

        return response()->json([
            'nota_venta' => $numero,
            'cliente' => trim((string) $nv->CodAux),
            'moneda' => trim((string) $nv->CodMon),
            'centro_costo' => trim((string) $nv->CodiCC) ?: null,
            'condicion' => trim((string) $nv->CveCod) ?: null,
            'vendedor' => trim((string) $nv->VenCod),
            'receptor_editable' => $this->reglas->receptorEditable(),
            'conocible' => $saldo['conocible'],
            'motivo' => $saldo['motivo'],
            'folios' => $this->foliosLibres(TipoDte::FACTURA),
            'lineas' => array_values(array_map(fn ($l) => [
                'linea' => $l['linea'],
                'producto' => $l['producto'],
                'pedida' => $l['pedida'],
                'facturada' => $l['facturada'],
                'saldo' => $l['saldo'],
            ], $saldo['lineas'])),
        ]);
    }

    /**
     * Emite el documento.
     *
     * **No es reversible.** Lo que sale mal se corrige con una nota de crédito,
     * no borrando: el folio ya se gastó y el número ya es de este documento.
     */
    public function store(Request $request)
    {
        $u = $this->usuario($request);

        $data = $request->validate([
            'nota_venta' => 'nullable|integer|min:1',
            'receptor' => 'required|string|max:12',
            'fecha' => 'nullable|date',
            'centro_costo' => 'nullable|string|max:8',
            'condicion' => 'nullable|string|max:3',
            'glosa' => 'nullable|string|max:200',
            'lineas' => 'required|array|min:1|max:200',
            'lineas.*.producto' => 'required|string|max:20',
            'lineas.*.cantidad' => 'required|numeric|gt:0',
            'lineas.*.precio' => 'nullable|numeric|min:0',
            'lineas.*.glosa' => 'nullable|string|max:500',
            'lineas.*.nv_linea' => 'nullable|numeric|min:1',
        ]);

        if ($data['nota_venta'] ?? null) {
            $nv = $this->notaVenta((int) $data['nota_venta']);

            if (! $nv || ! $this->alcanza($request, $nv->VenCod)) {
                return response()->json(['message' => 'Esa nota de venta no existe o no es tuya.'], 404);
            }
        }

        try {
            $doc = $this->facturacion->escribir([
                'tipo' => TipoDte::FACTURA,
                'receptor' => $data['receptor'],
                'vendedor' => $u->ven_cod,
                'usuario' => $u->usuario,
                'fecha' => $data['fecha'] ?? null,
                'centro_costo' => $data['centro_costo'] ?? null,
                'cond_pago' => $data['condicion'] ?? null,
                'glosa' => $data['glosa'] ?? null,
                'nota_venta' => $data['nota_venta'] ?? null,
                'lineas' => $data['lineas'],
            ]);
        } catch (Throwable $e) {
            // Lo que falla aquí es casi siempre una regla de negocio dicha en
            // castellano —sin folios, receptor que no corresponde, línea que no
            // existe—, y el vendedor tiene que poder leerla.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->documento($doc['tipo'], $doc['nroint']), 201);
    }

    /** Un documento emitido, con su detalle. */
    public function show(Request $request, string $tipo, int $numeroInterno)
    {
        $doc = $this->documento(strtoupper($tipo), $numeroInterno);

        if (! $doc || ! $this->alcanza($request, $doc['documento']['vendedor'] ?? null)) {
            return response()->json(['message' => 'Ese documento no existe o no es tuyo.'], 404);
        }

        return response()->json($doc);
    }

    // -------------------------------------------------------------- interior

    /**
     * Cuántos folios va a entregar Softland, que no es lo mismo que cuántos
     * quedan sin usar.
     *
     * El repartidor (`DTE_pdblEntregaFolioDTE`) va hacia adelante: entrega el
     * **siguiente al último usado**, y si ningún CAF lo cubre devuelve −1. No
     * rellena huecos. En INNOVAGES hay 33 folios de rangos viejos sin rastro en
     * ninguna tabla y no los va a repartir jamás.
     *
     * Contar «los que no están usados» daría 38 donde la realidad es **uno**, y
     * ese número de más es peor que no dar ninguno: alguien planificaría el mes
     * contando con folios que no existen.
     *
     * @return array{libres: int, siguiente: int|null}
     */
    private function foliosLibres(TipoDte $tipo): array
    {
        $rangos = array_map(
            fn ($l) => [(int) $l->FolioD, (int) $l->FolioH],
            Caf::lotes($tipo)
        );

        if ($rangos === []) {
            return ['libres' => 0, 'siguiente' => null];
        }

        // Sólo cuentan los folios **dentro de algún CAF**. `dte_doccab` tiene
        // filas con folios que no salieron de ninguna autorización —hay una de
        // factura con el folio 56.909.421—, y tomar el máximo a secas dejaba el
        // siguiente fuera de todo rango y la cuenta en cero.
        $usado = 0;

        foreach ($rangos as [$desde, $hasta]) {
            $max = (int) DB::connection('softland')->table('softland.dte_doccab')
                ->where('TipoDTE', $tipo->value)
                ->whereBetween('Folio', [$desde, $hasta])
                ->max('Folio');

            $usado = max($usado, $max);
        }

        $siguiente = max($usado + 1, min(array_column($rangos, 0)));
        $libres = 0;

        // Se avanza folio a folio mientras algún CAF lo cubra: es literalmente
        // lo que hará el repartidor la próxima vez y la siguiente.
        for ($f = $siguiente; $this->cubierto($f, $rangos); $f++) {
            $libres++;
        }

        return [
            'libres' => $libres,
            'siguiente' => $libres > 0 ? $siguiente : null,
        ];
    }

    /** @param  list<array{0:int,1:int}>  $rangos */
    private function cubierto(int $folio, array $rangos): bool
    {
        foreach ($rangos as [$desde, $hasta]) {
            if ($folio >= $desde && $folio <= $hasta) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function documento(string $tipo, int $nroInt): ?array
    {
        $cab = DB::connection('softland')->table('softland.iw_gsaen')
            ->where('Tipo', $tipo)->where('NroInt', $nroInt)->first();

        if (! $cab) {
            return null;
        }

        $lineas = DB::connection('softland')->table('softland.iw_gmovi')
            ->where('Tipo', $tipo)->where('NroInt', $nroInt)->orderBy('Linea')->get();

        return [
            'documento' => [
                'tipo' => trim((string) $cab->Tipo),
                'numero_interno' => (int) $cab->NroInt,
                'subtipo' => trim((string) $cab->SubTipoDocto),
                'folio' => (int) $cab->Folio,
                'cliente' => trim((string) $cab->CodAux),
                'vendedor' => trim((string) $cab->CodVendedor),
                'moneda' => trim((string) $cab->CodMoneda),
                'estado' => trim((string) $cab->Estado),
                'fecha' => $cab->Fecha,
                'fecha_vencimiento' => $cab->FechaVenc,
                'nota_venta' => (int) $cab->nvnumero,
                'centro_costo' => trim((string) $cab->CentroDeCosto),
                'condicion' => trim((string) $cab->CondPago),
                'glosa' => (string) $cab->Glosa,
                'neto' => (float) $cab->NetoAfecto,
                'exento' => (float) $cab->NetoExento,
                'iva' => (float) $cab->IVA,
                'total' => (float) $cab->Total,
                'enviado_sii' => $cab->FechaGenDTE,
            ],
            'lineas' => $lineas->map(fn ($l) => [
                'tipo' => trim((string) $l->Tipo),
                'numero_interno' => (int) $l->NroInt,
                'linea' => (float) $l->Linea,
                'producto' => trim((string) $l->CodProd),
                'detalle' => (string) $l->DetProd,
                'unidad' => trim((string) $l->CodUMed),
                'cantidad' => (float) $l->CantFacturada,
                'precio' => (float) $l->PreUniMB,
                'descuento' => (float) $l->TotalDescMov,
                'total' => (float) $l->TotLinea,
                'nota_venta_linea' => (float) $l->nvCorrela,
                'devuelve_linea' => (float) ($l->FactNumLin ?? 0),
            ])->all(),
        ];
    }

    private function notaVenta(int $numero)
    {
        return DB::connection('softland')->table('softland.nw_nventa')
            ->where('NVNumero', $numero)->first();
    }

    private function usuario(Request $request): Usuario
    {
        return $request->attributes->get('usuario');
    }

    /** El alcance por vendedor, el mismo que el resto de documentos. */
    private function alcanza(Request $request, ?string $vendedor): bool
    {
        $visibles = $this->usuario($request)->vendedoresVisibles();

        return $visibles === null || in_array(trim((string) $vendedor), $visibles, true);
    }
}
