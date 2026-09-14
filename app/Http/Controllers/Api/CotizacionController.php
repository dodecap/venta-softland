<?php

namespace App\Http\Controllers\Api;

use App\Services\Notificaciones\Eventos;
use App\Services\Notificaciones\Notificador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cotizaciones: crear, corregir, seguir, perder y convertir en nota de venta.
 *
 * Una cotización es un documento de trabajo: se corrige tantas veces como haga
 * falta mientras esté en curso. Lo que no se hace nunca es tocar una que ya se
 * vendió (`V`) o que se cerró como perdida (`R`) — ahí el documento dejó de ser
 * un borrador y pasó a ser historia de la que cuelgan otras cosas.
 */
class CotizacionController extends DocumentoController
{
    protected function recurso(): string
    {
        return 'cotizaciones';
    }

    /**
     * Los estados en que todavía se puede editar: sólo `P`, pendiente.
     *
     * `N` es **nula** y `R` perdida — un documento cerrado no se corrige, se
     * hace otro — y `V` ya pasó a nota de venta. El vacío se admite porque en
     * INNOVAGES hay cotizaciones viejas sin estado.
     */
    private const EDITABLES = ['P', ''];

    // ------------------------------------------------ lo que pide la base

    protected function documentoDe(int $numero): ?array
    {
        return $this->maestros->uno('cotizaciones', ['CotNum' => $numero], self::YA_COMPROBADO);
    }

    protected function lineasDocumento(int $numero): array
    {
        return $this->maestros->varios('cotizacion_lineas', ['CotNum' => $numero], self::YA_COMPROBADO);
    }

    protected function vendedorDelDocumento(int $numero): ?string
    {
        return $this->cabecera($numero)?->VenCod;
    }

    protected function noEncontrado(): string
    {
        return 'Esa cotización no existe o no es tuya.';
    }

    protected function eventoEnvio(): string
    {
        return Eventos::COTIZACION_ENVIADA;
    }

    public function show(Request $request, int $numero)
    {
        $doc = $this->documentoDe($numero);

        if (! $doc || ! $this->alcanza($request, $doc['vendedor'])) {
            return response()->json(['message' => $this->noEncontrado()], 404);
        }

        return response()->json([
            'cotizacion' => $doc,
            'lineas' => $this->lineasDocumento($numero),
            'seguimientos' => $this->seguimientosDe($numero),
            'emisiones' => app(\App\Services\Documentos\Emision::class)
                ->historial($this->tipoDoc(), $numero),
        ]);
    }

    public function store(Request $request, Notificador $notificador)
    {
        $u = $this->usuario($request);
        $data = $this->validar($request, true);

        // Idempotencia: si este mismo borrador ya se escribió, se devuelve el
        // que hay. Un teléfono que perdió la respuesta reintenta, y reintentar
        // no puede dejar dos cotizaciones iguales en Softland.
        if ($numero = $this->ventas->yaEscrito($data['client_uuid'])) {
            return response()->json($this->respuesta($numero) + ['ya_existia' => true], 200);
        }

        $numero = $this->escribir(fn () => $this->ventas->crearCotizacion($data, $u));

        // Guardar no es enviar. Una cotización se corrige tres veces antes de
        // mandarla, y un correo al cliente por cada guardado sería una plaga.
        // El envío es un acto aparte: `POST /cotizaciones/{n}/enviar`.
        return response()->json($this->respuesta($numero), 201);
    }

    public function update(Request $request, int $numero)
    {
        $u = $this->usuario($request);
        $actual = $this->cabecera($numero);

        if (! $actual || ! $this->alcanza($request, $actual->VenCod)) {
            return response()->json(['message' => 'Esa cotización no existe o no es tuya.'], 404);
        }

        if (! in_array(trim((string) $actual->CtEstado), self::EDITABLES, true)) {
            return response()->json([
                'message' => 'Esta cotización ya no se puede cambiar: está '.
                    strtolower($this->tipoDoc()->estado($actual->CtEstado)).'.',
            ], 409);
        }

        $data = $this->validar($request, false);
        $this->escribir(fn () => $this->ventas->actualizarCotizacion($numero, $data, $u));

        return response()->json($this->respuesta($numero));
    }

    /** Cierre por pérdida, con el motivo del maestro `softland.nwperdida`. */
    public function perder(Request $request, int $numero, Notificador $notificador)
    {
        $u = $this->usuario($request);
        $actual = $this->cabecera($numero);

        if (! $actual || ! $this->alcanza($request, $actual->VenCod)) {
            return response()->json(['message' => 'Esa cotización no existe o no es tuya.'], 404);
        }

        $data = $request->validate([
            'motivo' => 'required|string|max:2',
            'observacion' => 'nullable|string|max:500',
        ]);

        if (! DB::connection('softland')->table('softland.nwperdida')->where('CodPerd', $data['motivo'])->exists()) {
            $this->rechazar(['motivo' => 'Ese motivo de pérdida no existe en Softland.']);
        }

        $this->ventas->marcarPerdida($numero, $data['motivo'], $data['observacion'] ?? null, $u);

        $this->avisar($notificador, Eventos::COTIZACION_PERDIDA, $numero, $u);

        return response()->json($this->respuesta($numero));
    }

    /** Un seguimiento: la llamada, la visita, el correo que se le hizo al cliente. */
    public function seguimiento(Request $request, int $numero)
    {
        $u = $this->usuario($request);
        $actual = $this->cabecera($numero);

        if (! $actual || ! $this->alcanza($request, $actual->VenCod)) {
            return response()->json(['message' => 'Esa cotización no existe o no es tuya.'], 404);
        }

        $data = $request->validate([
            'descripcion' => 'required|string|max:2000',
            'tipo' => 'nullable|string|max:2',
            'contacto' => 'nullable|string|max:30',
            'proximo_contacto' => 'nullable|date',
        ]);

        $this->ventas->anotarSeguimiento($numero, $data, $u);

        return response()->json(['seguimientos' => $this->seguimientosDe($numero)], 201);
    }

    /**
     * Convierte la cotización en nota de venta.
     *
     * Se delega en el controlador de notas de venta: la aprobación por topes,
     * el centro de costo obligatorio y la bodega son reglas de la NV, y tenerlas
     * escritas en dos sitios es tenerlas escritas mal en uno de los dos.
     */
    public function convertir(Request $request, int $numero, NotaVentaController $notasVenta, Notificador $notificador)
    {
        $actual = $this->cabecera($numero);

        if (! $actual || ! $this->alcanza($request, $actual->VenCod)) {
            return response()->json(['message' => 'Esa cotización no existe o no es tuya.'], 404);
        }

        if (trim((string) $actual->CtEstado) === 'V') {
            $nv = DB::connection('softland')->table('softland.nw_nventa')
                ->where('CotNum', $numero)->value('NVNumero');

            return response()->json([
                'message' => 'Esta cotización ya se convirtió en la nota de venta '.$nv.'.',
                'nota_venta' => $nv ? (int) $nv : null,
            ], 409);
        }

        return $notasVenta->store($request, $notificador, $numero);
    }

    // -------------------------------------------------------------- interior

    private function cabecera(int $numero)
    {
        return DB::connection('softland')->table('softland.nwcotiza')
            ->where('CotNum', $numero)->first(['CotNum', 'CtEstado', 'VenCod', 'CodAux']);
    }

    private function seguimientosDe(int $numero): array
    {
        return DB::connection('softland')->table('softland.nwtsegui')
            ->where('CotNum', $numero)->orderBy('NroSeg')
            ->get()->map(fn ($s) => [
                'numero' => (int) $s->NroSeg,
                'fecha' => $s->FecSeg,
                'tipo' => trim((string) $s->TipComp),
                'contacto' => trim((string) $s->Contacto),
                'proximo_contacto' => $s->FecProComp,
                'descripcion' => (string) $s->Descripcion,
            ])->all();
    }

    private function respuesta(int $numero): array
    {
        return [
            'cotizacion' => $this->documentoDe($numero),
            'lineas' => $this->lineasDocumento($numero),
        ];
    }

    /** Avisar nunca voltea la operación: el documento ya está escrito. */
    private function avisar(Notificador $notificador, string $evento, int $numero, $u, bool $conPdf = false): void
    {
        $this->avisarDocumento(
            $notificador,
            $evento,
            'Cotización '.$numero,
            $this->documentoDe($numero) ?? [],
            $this->lineasDocumento($numero),
            $u,
            $conPdf,
        );
    }
}
