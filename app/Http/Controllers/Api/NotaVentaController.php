<?php

namespace App\Http\Controllers\Api;

use App\Models\Usuario;
use App\Services\Notificaciones\Eventos;
use App\Services\Notificaciones\Notificador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notas de venta: crear, corregir y aprobar.
 *
 * ## La aprobación del jefe no existe en Softland
 *
 * `nwparam.CheckApruebaNv = N`: el ERP no pide aprobación de notas de venta.
 * La aporta la app, con los topes por vendedor de `ventas.usuario`
 * (`tope_descuento_pct`, `tope_monto_nv`). Una NV que pasa cualquiera de los
 * dos nace en estado `P` y no se aprueba sola; una que no los pasa nace en `N`.
 *
 * Eso se refleja donde Softland puede verlo: `nvEstado` y `nvFeAprob` son
 * columnas suyas, así que la nota de venta se ve pendiente también desde el
 * escritorio. El detalle de quién pidió qué y quién resolvió vive en
 * `ventas.aprobacion`, que es información de la app.
 */
class NotaVentaController extends DocumentoController
{
    protected function recurso(): string
    {
        return 'notas_venta';
    }

    /** `nwparam.CheckExigeCCostoN = S` en INNOVAGES. */
    protected function exigeCentroCosto(): bool
    {
        return true;
    }

    /** Estados en que una NV todavía se puede corregir. */
    private const EDITABLES = ['N', 'P', ''];

    public function show(Request $request, int $numero)
    {
        $doc = $this->maestros->uno('notas_venta', ['NVNumero' => $numero], self::YA_COMPROBADO);

        if (! $doc || ! $this->alcanza($request, $doc['vendedor'])) {
            return response()->json(['message' => 'Esa nota de venta no existe o no es tuya.'], 404);
        }

        return response()->json([
            'nota_venta' => $doc,
            'lineas' => $this->maestros->varios('nota_venta_lineas', ['NVNumero' => $numero], self::YA_COMPROBADO),
            'aprobacion' => $this->aprobacionDe($numero),
        ]);
    }

    /**
     * Crea la nota de venta. Si `$desdeCotizacion` viene, además deja esa
     * cotización en `V`, que es la marca que usa toda la base para decir
     * «esta se vendió».
     */
    public function store(Request $request, Notificador $notificador, ?int $desdeCotizacion = null)
    {
        $u = $this->usuario($request);
        $data = $this->validar($request, true);

        if ($numero = $this->ventas->yaEscrito($data['client_uuid'])) {
            return response()->json($this->respuesta($numero) + ['ya_existia' => true], 200);
        }

        // Se decide antes de escribir: el estado con que nace la NV es parte
        // del documento, no un parche posterior.
        $excede = $this->topesExcedidos($data, $u);
        $data['estado'] = $excede ? 'P' : 'N';

        $numero = $this->escribir(fn () => $this->ventas->crearNotaVenta($data, $u, $desdeCotizacion));

        if ($excede) {
            $this->pedirAprobacion($numero, $excede, $u, $notificador);
        }

        $this->avisar($notificador, Eventos::NV_CREADA, $numero, $u);

        if ($desdeCotizacion) {
            $this->avisar($notificador, Eventos::COTIZACION_ACEPTADA, $numero, $u);
        }

        return response()->json($this->respuesta($numero), 201);
    }

    public function update(Request $request, int $numero)
    {
        $u = $this->usuario($request);
        $actual = $this->cabecera($numero);

        if (! $actual || ! $this->alcanza($request, $actual->VenCod)) {
            return response()->json(['message' => 'Esa nota de venta no existe o no es tuya.'], 404);
        }

        if (! in_array(trim((string) $actual->nvEstado), self::EDITABLES, true)) {
            return response()->json([
                'message' => 'Esta nota de venta ya está aprobada: no se cambia desde el teléfono.',
            ], 409);
        }

        $data = $this->validar($request, false);

        // Corregirla puede meterla o sacarla de los topes. Se vuelve a decidir
        // entero en vez de conservar el estado anterior: bajar el descuento por
        // debajo del tope tiene que quitar la aprobación pendiente, no dejarla
        // colgada esperando a un jefe que ya no hace falta.
        $excede = $this->topesExcedidos($data, $u);
        $data['estado'] = $excede ? 'P' : 'N';

        $this->escribir(fn () => $this->ventas->actualizarNotaVenta($numero, $data, $u));

        if (! $excede) {
            DB::connection('softland')->table('ventas.aprobacion')
                ->where('nv_numero', $numero)->where('estado', 'pendiente')->delete();
        }

        return response()->json($this->respuesta($numero));
    }

    /** Lo que un jefe tiene sobre la mesa. */
    public function pendientes(Request $request)
    {
        $u = $this->usuario($request);

        $filas = DB::connection('softland')->table('ventas.aprobacion as a')
            ->join('ventas.usuario as v', 'v.id', '=', 'a.solicitante_id')
            ->where('a.estado', 'pendiente')
            ->when(! $u->esRol('admin'), fn ($q) => $q->where('a.jefe_id', $u->id))
            ->orderByDesc('a.created_at')
            ->get(['a.id', 'a.nv_numero', 'a.motivo', 'a.descuento_pct', 'a.monto', 'a.created_at', 'v.nombre as vendedor']);

        return response()->json(['aprobaciones' => $filas->map(fn ($a) => [
            'id' => (int) $a->id,
            'nota_venta' => (int) $a->nv_numero,
            'vendedor' => $a->vendedor,
            'motivo' => $a->motivo,
            'descuento_pct' => (float) $a->descuento_pct,
            'monto' => (float) $a->monto,
            'pedida' => $a->created_at,
        ])->all()]);
    }

    /** El jefe resuelve. `aprobar = false` es rechazo, y el rechazo pide motivo. */
    public function resolver(Request $request, int $numero, Notificador $notificador)
    {
        $u = $this->usuario($request);

        $data = $request->validate([
            'aprobar' => 'required|boolean',
            'comentario' => 'required_if:aprobar,false|nullable|string|max:400',
        ]);

        $pendiente = DB::connection('softland')->table('ventas.aprobacion')
            ->where('nv_numero', $numero)->where('estado', 'pendiente')->first();

        if (! $pendiente) {
            return response()->json(['message' => 'Esa nota de venta no tiene una aprobación pendiente.'], 404);
        }

        if (! $u->esRol('admin') && (int) $pendiente->jefe_id !== (int) $u->id) {
            return response()->json(['message' => 'Esta aprobación no te corresponde.'], 403);
        }

        $aprobada = (bool) $data['aprobar'];

        DB::connection('softland')->table('ventas.aprobacion')->where('id', $pendiente->id)->update([
            'estado' => $aprobada ? 'aprobada' : 'rechazada',
            'comentario' => $data['comentario'] ?? null,
            'resuelto_por' => $u->id,
            'resuelto_at' => now(),
            'updated_at' => now(),
        ]);

        // `C` es el estado cerrado de Softland: una NV rechazada no se borra,
        // se cierra. Borrarla dejaría la cotización de origen marcada como
        // vendida sin nada al otro lado.
        $this->ventas->fijarEstadoNotaVenta($numero, $aprobada ? 'A' : 'C', $u, $aprobada);

        $solicitante = Usuario::on('softland')->find($pendiente->solicitante_id);
        $this->avisar(
            $notificador,
            $aprobada ? Eventos::NV_APROBADA : Eventos::NV_RECHAZADA,
            $numero,
            $solicitante ?? $u,
        );

        return response()->json($this->respuesta($numero));
    }

    // -------------------------------------------------------------- interior

    /**
     * ¿Se pasó de sus topes? Devuelve el motivo en palabras, o null.
     *
     * Un tope en 0 es «sin tope propio», no «tope cero»: así está sembrado en
     * `ventas.usuario` y así lo entiende la pantalla de administración. Los
     * jefes y la administración no tienen a quién pedirle permiso, así que
     * tampoco se les pide.
     */
    private function topesExcedidos(array $data, Usuario $u): ?array
    {
        if ($u->esRol('admin', 'supervisor')) {
            return null;
        }

        $motivos = [];

        $descuento = (float) ($data['descuento_pct'] ?? 0);
        foreach ($data['lineas'] as $l) {
            $descuento = max($descuento, (float) ($l['descuento_pct'] ?? 0));
        }

        $topeDesc = (float) $u->tope_descuento_pct;
        if ($topeDesc > 0 && $descuento > $topeDesc) {
            $motivos[] = 'descuento '.rtrim(rtrim(number_format($descuento, 2, ',', '.'), '0'), ',').
                '% sobre un tope de '.rtrim(rtrim(number_format($topeDesc, 2, ',', '.'), '0'), ',').'%';
        }

        // El monto se calcula con la misma aritmética con que se va a escribir:
        // comparar el tope contra un total aproximado dejaría notas de venta
        // pasando por un peso de diferencia.
        $monto = $this->totalEstimado($data);
        $topeMonto = (float) $u->tope_monto_nv;
        if ($topeMonto > 0 && $monto > $topeMonto) {
            $motivos[] = 'monto '.number_format($monto, 0, ',', '.').
                ' sobre un tope de '.number_format($topeMonto, 0, ',', '.');
        }

        return $motivos ? ['texto' => ucfirst(implode(' y ', $motivos)), 'descuento' => $descuento, 'monto' => $monto] : null;
    }

    private function totalEstimado(array $data): float
    {
        try {
            return (float) $this->ventas->totalDe($data);
        } catch (\RuntimeException $e) {
            $this->rechazar(['lineas' => $e->getMessage()]);
        }
    }

    private function pedirAprobacion(int $numero, array $excede, Usuario $u, Notificador $notificador): void
    {
        DB::connection('softland')->table('ventas.aprobacion')->insert([
            'nv_numero' => $numero,
            'solicitante_id' => $u->id,
            'jefe_id' => $u->jefe_id,
            'estado' => 'pendiente',
            'motivo' => mb_substr($excede['texto'], 0, 200),
            'descuento_pct' => $excede['descuento'],
            'monto' => $excede['monto'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->avisar($notificador, Eventos::NV_REQUIERE_APROBACION, $numero, $u);
    }

    private function aprobacionDe(int $numero): ?array
    {
        $a = DB::connection('softland')->table('ventas.aprobacion')
            ->where('nv_numero', $numero)->orderByDesc('id')->first();

        return $a ? [
            'estado' => $a->estado,
            'motivo' => $a->motivo,
            'comentario' => $a->comentario,
            'resuelta' => $a->resuelto_at,
        ] : null;
    }

    private function cabecera(int $numero)
    {
        return DB::connection('softland')->table('softland.nw_nventa')
            ->where('NVNumero', $numero)->first(['NVNumero', 'nvEstado', 'VenCod', 'CodAux']);
    }

    private function respuesta(int $numero): array
    {
        return [
            'nota_venta' => $this->maestros->uno('notas_venta', ['NVNumero' => $numero], self::YA_COMPROBADO),
            'lineas' => $this->maestros->varios('nota_venta_lineas', ['NVNumero' => $numero], self::YA_COMPROBADO),
            'aprobacion' => $this->aprobacionDe($numero),
        ];
    }

    private function avisar(Notificador $notificador, string $evento, int $numero, Usuario $u): void
    {
        $this->avisarDocumento(
            $notificador,
            $evento,
            'Nota de venta '.$numero,
            $this->maestros->uno('notas_venta', ['NVNumero' => $numero], self::YA_COMPROBADO) ?? [],
            $this->maestros->varios('nota_venta_lineas', ['NVNumero' => $numero], self::YA_COMPROBADO),
            $u,
        );
    }
}
