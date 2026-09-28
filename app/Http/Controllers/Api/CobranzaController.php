<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Cobranza\Cuentas;
use App\Services\Cobranza\Recaudacion;
use App\Services\Softland\Maestros;
use App\Services\Softland\Permisos;
use Illuminate\Http\Request;

/**
 * Cobrar contra la cuenta corriente del cliente.
 *
 * Tres cosas y ninguna más: qué se le puede cobrar a un cliente, escribir el
 * comprobante, y enseñarlo o borrarlo después.
 *
 * **Aquí no se decide nada de contabilidad.** Las cuentas, el saldo, la fecha
 * de emisión de cada documento y los cuatro permisos del ERP los resuelve
 * `Recaudacion`, que es por donde pasan todos los caminos. Un controlador
 * nuevo que no supiera de esas reglas escribiría un asiento descuadrado, y eso
 * no se corrige con un `UPDATE`.
 */
class CobranzaController extends Controller
{
    use AlcancePorVendedor;

    public function __construct(
        private Recaudacion $recaudacion,
        private Cuentas $cuentas,
        private Maestros $maestros,
        private Permisos $permisos,
    ) {}

    /**
     * Qué se le puede cobrar a este cliente, y con qué.
     *
     * Va antes de que nadie teclee nada, por la misma razón que la propuesta de
     * factura dice cuántos folios quedan: enterarse de que no se puede después
     * de armar el cobro es la peor forma de enterarse.
     */
    public function propuesta(Request $request, string $cliente)
    {
        $u = $this->usuario($request);
        $softland = trim((string) $u->softland_user);
        $ctx = ['vendedores' => $u->vendedoresVisibles()];

        $documentos = $this->maestros->varios('cartera', ['cliente' => $cliente], $ctx);

        return response()->json([
            'cliente' => $cliente,
            'documentos' => $documentos,
            'total' => round(array_sum(array_map(fn ($d) => (float) $d['saldo'], $documentos)), 2),
            'medios' => array_values($this->cuentas->disponibles()),
            // Lo que este usuario puede hacer según Softland, no según su rol
            // en la app. La pantalla lo usa para apagar el botón antes, con el
            // nombre del control que hay que marcar en el ERP.
            'permisos' => [
                'cobrar' => $this->permisos->puede($softland, Permisos::COBRO_AGREGA)
                    && $this->permisos->puede($softland, Permisos::COBRO_VIGENTE),
                'sobre_saldo' => $this->permisos->puede($softland, Permisos::COBRO_SOBRE_SALDO),
                'borrar' => $this->permisos->puede($softland, Permisos::COBRO_ELIMINA),
            ],
            // Si falta configurar una cuenta, se dice aquí y no al escribir.
            'problemas' => $this->cuentas->problemas(),
        ]);
    }

    /** Escribe el comprobante de ingreso. */
    public function store(Request $request)
    {
        $u = $this->usuario($request);

        $data = $request->validate([
            'client_uuid' => 'nullable|string|max:64',
            'cliente' => 'required|string|max:10',
            'fecha' => 'nullable|date',
            // 60, que es lo que mide `cwcpbte.CpbGlo`.
            'glosa' => 'nullable|string|max:60',
            'pagos' => 'required|array|min:1|max:10',
            'pagos.*.medio' => 'required|string|max:20',
            // El número del instrumento: el del cheque, el de la transferencia.
            // Va en `cwmovim.NumDoc`, que es float, y por eso no lleva letras.
            'pagos.*.numero' => 'nullable|integer|min:0',
            'pagos.*.fecha' => 'nullable|date',
            'pagos.*.aplicaciones' => 'required|array|min:1|max:50',
            'pagos.*.aplicaciones.*.tipo' => 'required|string|max:2',
            'pagos.*.aplicaciones.*.numero' => 'required|integer|min:1',
            'pagos.*.aplicaciones.*.monto' => 'required|numeric|gt:0',
        ]);

        $ctx = ['vendedores' => $u->vendedoresVisibles()];
        $cobro = $this->recaudacion->cobrar($data, $ctx, $u);

        return response()->json($cobro, 201);
    }

    /** El comprobante ya escrito. */
    public function show(string $ano, string $numero)
    {
        $cobro = $this->recaudacion->ver($ano, $numero);

        if (! $cobro) {
            return response()->json(['message' => 'Ese comprobante no existe.'], 404);
        }

        return response()->json($cobro);
    }

    /**
     * Borra el comprobante.
     *
     * Sólo lo que escribió esta app. No es anular —en contabilidad no hay tal
     * cosa— y devuelve el número al pozo, porque el correlativo es `MAX + 1`.
     */
    public function destroy(Request $request, string $ano, string $numero)
    {
        $this->recaudacion->borrar($ano, $numero, $this->usuario($request));

        return response()->json(['ok' => true]);
    }
}
