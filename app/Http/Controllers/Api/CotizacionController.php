<?php

namespace App\Http\Controllers\Api;

use App\Models\Usuario;
use App\Services\Notificaciones\Eventos;
use App\Services\Notificaciones\Notificador;
use App\Services\Softland\Saldo;
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

    protected function documentoDe(int $numero, bool $ventana = true): ?array
    {
        return $this->maestros->uno('cotizaciones', ['CotNum' => $numero], self::YA_COMPROBADO, $ventana);
    }

    protected function lineasDocumento(int $numero, bool $ventana = true): array
    {
        return $this->maestros->varios('cotizacion_lineas', ['CotNum' => $numero], self::YA_COMPROBADO, $ventana);
    }

    protected function vendedorDelDocumento(int $numero): ?string
    {
        return $this->cabecera($numero)?->VenCod;
    }

    protected function noEncontrado(): string
    {
        return 'Esa cotización no existe o no es tuya.';
    }

    /** Anular una cotización no libera nada: no cuelga de ningún otro documento. */
    protected function anularEnSoftland(int $numero, Usuario $u): ?int
    {
        $this->ventas->anularCotizacion($numero, $u);

        return null;
    }

    protected function eliminarDeSoftland(int $numero, Usuario $u): ?int
    {
        $this->ventas->eliminarCotizacion($numero);

        return null;
    }

    protected function impedimentosParaEliminar(int $numero): array
    {
        return $this->ventas->impedimentosCotizacion($numero);
    }

    protected function eventoEnvio(): string
    {
        return Eventos::COTIZACION_ENVIADA;
    }

    /**
     * Una cotización por su número, **de cualquier fecha**.
     *
     * El teléfono sólo se lleva doce meses, que es lo que cabe y lo que se
     * usa. Pero preguntar por la 8000 de 2024 tiene que funcionar: es del
     * vendedor que la pide y él sabe su número. Por eso aquí la ventana se
     * levanta (`$ventana = false`) y el alcance no: una cotización de otro
     * vendedor sigue siendo 404.
     *
     * `fuera_de_ventana` le dice al teléfono que esto no está en IndexedDB y
     * que no lo guarde. Si lo guardara, el panel empezaría a contar
     * cotizaciones de hace dos años entre las vencidas.
     */
    public function show(Request $request, int $numero)
    {
        $doc = $this->documentoDe($numero, false);

        if (! $doc || ! $this->alcanza($request, $doc['vendedor'])) {
            return response()->json(['message' => $this->noEncontrado()], 404);
        }

        return response()->json([
            'cotizacion' => $doc,
            'lineas' => $this->lineasDocumento($numero, false),
            'seguimientos' => $this->seguimientosDe($numero),
            'avance' => $this->ventas->avanceDe($numero),
            // El historial es de **este** documento. Sin la fecha de nacimiento,
            // una cotización recién escrita heredaría las entregas de la que
            // tuvo ese número antes de que alguien la borrara.
            'emisiones' => app(\App\Services\Documentos\Emision::class)
                ->historial($this->tipoDoc(), $numero, $doc['creado'] ?? null),
            'fuera_de_ventana' => $this->fueraDeVentana($doc),
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
            // Tres, no dos: `nwtsegui.TipComp` es `varchar(3)` y los códigos
            // de compromiso ocupan los tres. Con dos, «LLA» se rechazaba.
            'tipo' => 'nullable|string|max:3',
            'contacto' => 'nullable|string|max:30',
            // Con hora, que es lo que hace que sirva en el calendario.
            'proximo_contacto' => 'nullable|date',
            // El avance viaja con el seguimiento porque se mueve en el mismo
            // momento: uno vuelve de la reunión y sabe las dos cosas a la vez.
            'avance' => 'nullable|integer|min:1|max:99',
        ]);

        $this->ventas->anotarSeguimiento($numero, $data, $u);

        if (! empty($data['avance'])) {
            try {
                $this->ventas->fijarAvance($numero, (int) $data['avance'], $u);
            } catch (\RuntimeException $e) {
                // Un avance fuera de la escalera no puede tumbar el
                // seguimiento, que es lo que de verdad se vino a anotar.
                return response()->json([
                    'seguimientos' => $this->seguimientosDe($numero),
                    'avance' => $this->ventas->avanceDe($numero),
                    'aviso' => $e->getMessage(),
                ], 201);
            }
        }

        return response()->json([
            'seguimientos' => $this->seguimientosDe($numero),
            'avance' => $this->ventas->avanceDe($numero),
        ], 201);
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

        // `V` ya no cierra la puerta. Quiere decir «tiene nota de venta», y
        // desde el reparto parcial eso convive con que quede algo por
        // convertir: una cotización de ocho líneas puede haberse llevado siete
        // a una nota de venta y tener la octava esperando. Lo que cierra la
        // puerta es que no quede saldo.
        if (trim((string) $actual->CtEstado) === 'V' && ! $this->quedaPorConvertir($numero)) {
            $nvs = DB::connection('softland')->table('softland.nw_nventa')
                ->where('CotNum', $numero)->where('nvEstado', '<>', 'N')
                ->pluck('NVNumero')->map(fn ($n) => (int) $n)->all();

            return response()->json([
                'message' => count($nvs) === 1
                    ? 'Esta cotización ya se convirtió en la nota de venta '.$nvs[0].'.'
                    : 'Esta cotización ya se convirtió del todo.',
                'nota_venta' => $nvs[0] ?? null,
                'notas_venta' => $nvs,
            ], 409);
        }

        return $notasVenta->store($request, $notificador, $numero);
    }

    /**
     * Qué queda por convertir de esta cotización.
     *
     * Es lo que precarga el editor cuando se vuelve a convertir una cotización
     * repartida, y lo que la ficha usa para decir «quedan 1 de 8 líneas».
     *
     * Puede responder que **no se sabe**, y eso no es un fallo: una cotización
     * convertida antes de la app, o desde el Softland de escritorio, no tiene
     * enlace de línea. Decir «queda todo» ahí la convertiría dos veces.
     */
    public function saldo(Request $request, int $numero, Saldo $saldo)
    {
        $actual = $this->cabecera($numero);

        if (! $actual || ! $this->alcanza($request, $actual->VenCod)) {
            return response()->json(['message' => 'Esa cotización no existe o no es tuya.'], 404);
        }

        $r = $saldo->deCotizacion($numero);
        $pendientes = array_values(array_filter($r['lineas'], fn ($l) => $l['saldo'] > 0.0001));

        return response()->json([
            'cotizacion' => $numero,
            'estado' => trim((string) $actual->CtEstado),
            'conocible' => $r['conocible'],
            'motivo' => $r['motivo'],
            'lineas' => $r['lineas'],
            'lineas_pendientes' => count($pendientes),
            'lineas_totales' => count($r['lineas']),
        ]);
    }

    // -------------------------------------------------------------- interior

    /**
     * Si queda algo por convertir. Cuando el saldo no se puede saber —sin
     * enlace de línea— la respuesta es **no**: es lo conservador, y evita
     * convertir dos veces una cotización vieja.
     */
    private function quedaPorConvertir(int $numero): bool
    {
        $r = app(Saldo::class)->deCotizacion($numero);

        if (! $r['conocible']) {
            return false;
        }

        foreach ($r['lineas'] as $l) {
            if ($l['saldo'] > 0.0001) {
                return true;
            }
        }

        return false;
    }

    private function cabecera(int $numero)
    {
        return DB::connection('softland')->table('softland.nwcotiza')
            ->where('CotNum', $numero)->first(['CotNum', 'CtEstado', 'VenCod', 'CodAux']);
    }

    private function seguimientosDe(int $numero): array
    {
        return DB::connection('softland')->table('softland.nwtsegui')
            ->where('CotNum', $numero)->orderByDesc('NroSeg')
            ->get()->map(fn ($s) => [
                'numero' => (int) $s->NroSeg,
                'fecha' => $s->FecSeg,
                'tipo' => trim((string) $s->TipComp),
                'contacto' => trim((string) $s->Contacto),
                'proximo_contacto' => $s->FecProComp,
                'descripcion' => (string) $s->Descripcion,
            ])->all();
    }

    protected function respuesta(int $numero): array
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
