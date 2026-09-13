<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\Notificaciones\Notificador;
use App\Services\Softland\Catalogos;
use App\Services\Softland\DocumentoPdf;
use App\Services\Softland\Maestros;
use App\Services\Softland\Ventas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lo que comparten cotización y nota de venta: son el mismo documento en dos
 * momentos de su vida, con los mismos maestros detrás y el mismo alcance por
 * vendedor.
 *
 * Aquí vive la validación del cuerpo, la comprobación de que los códigos
 * existen y la regla de quién puede tocar qué. Lo que cambia de una a otra
 * — tablas, columnas, estados — está en cada controlador.
 */
abstract class DocumentoController extends Controller
{
    public function __construct(protected Ventas $ventas, protected Maestros $maestros) {}

    /**
     * Lectura de un maestro sin su filtro por vendedor.
     *
     * Los maestros de documentos se cierran si no se les dice de parte de quién
     * se pregunta, y está bien que así sea. Aquí se abren a propósito, en los
     * sitios donde el permiso ya se comprobó a mano un par de líneas antes:
     * volver a filtrar por vendedor haría que la cotización recién escrita
     * desapareciera de su propia respuesta si el vendedor cambió de manos.
     */
    protected const YA_COMPROBADO = ['vendedores' => null];

    /** `cotizacion` o `nota_venta`, como los nombra el maestro y la app. */
    abstract protected function recurso(): string;

    /** ¿El centro de costo es obligatorio? En la NV sí (`nwparam.CheckExigeCCostoN = S`). */
    protected function exigeCentroCosto(): bool
    {
        return false;
    }

    protected function usuario(Request $request): Usuario
    {
        return $request->attributes->get('usuario');
    }

    // ------------------------------------------------------------ validación

    protected function validar(Request $request, bool $creando): array
    {
        $data = $request->validate([
            'client_uuid' => ($creando ? 'required' : 'nullable').'|string|max:64',
            'cliente' => 'required|string|max:12',
            'vendedor' => 'nullable|string|max:4',
            'contacto' => 'nullable|string|max:30',
            'moneda' => 'nullable|string|max:2',
            'lista' => 'nullable|string|max:3',
            'condicion' => 'nullable|string|max:3',
            'centro_costo' => ($this->exigeCentroCosto() ? 'required' : 'nullable').'|string|max:8',
            'bodega' => 'nullable|string|max:10',
            'fecha' => 'nullable|date',
            'fecha_entrega' => 'nullable|date',
            'oc' => 'nullable|string|max:15',
            'observacion' => 'nullable|string|max:400',
            'descuento_pct' => 'nullable|numeric|min:0|max:100',
            'lineas' => 'required|array|min:1|max:200',
            'lineas.*.producto' => 'required|string|max:20',
            'lineas.*.detalle' => 'nullable|string|max:500',
            'lineas.*.unidad' => 'nullable|string|max:6',
            'lineas.*.cantidad' => 'required|numeric|gt:0',
            'lineas.*.precio' => 'required|numeric|min:0',
            'lineas.*.descuento_pct' => 'nullable|numeric|min:0|max:100',
        ]);

        $this->verificarCodigos($data, $request);
        $data['vendedor'] = $this->vendedorDe($data, $request);

        return $data;
    }

    /**
     * Los códigos son claves foráneas de verdad. Sin esto, un código que no
     * existe sale como un 500 con el nombre del constraint de SQL Server en la
     * pantalla del vendedor, que no le dice nada a nadie.
     */
    protected function verificarCodigos(array $data, Request $request): void
    {
        $refs = [
            'cliente' => ['softland.cwtauxi', 'CodAux', 'Ese cliente no existe en Softland.'],
            'vendedor' => ['softland.cwtvend', 'VenCod', 'Ese vendedor no existe en Softland.'],
            'moneda' => ['softland.cwtmone', 'CodMon', 'Esa moneda no existe.'],
            'lista' => ['softland.iw_tlispre', 'CodLista', 'Esa lista de precios no existe.'],
            'condicion' => ['softland.cwtconv', 'CveCod', 'Esa condición de venta no existe.'],
            'centro_costo' => ['softland.cwtccos', 'CodiCC', 'Ese centro de costo no existe.'],
            'bodega' => ['softland.iw_tbode', 'CodBode', 'Esa bodega no existe.'],
        ];

        $errores = [];
        foreach ($refs as $campo => [$tabla, $columna, $mensaje]) {
            $valor = $data[$campo] ?? null;
            if ($valor && ! DB::connection('softland')->table($tabla)->where($columna, $valor)->exists()) {
                $errores[$campo] = $mensaje;
            }
        }

        // El descuento por sobre el tope no se rechaza aquí: en la cotización
        // es libre, y en la nota de venta dispara la aprobación del jefe.
        if ($errores) {
            $this->rechazar($errores);
        }
    }

    /** Razón social, giro, dirección y teléfono de quien emite. */
    private function emisor($conn): array
    {
        $e = $conn->table('softland.soempre')->first(['NomB', 'Giro', 'Dire', 'Fono']);

        return [
            'nombre' => trim((string) ($e->NomB ?? '')) ?: (string) config('app.name'),
            'giro' => trim((string) ($e->Giro ?? '')),
            'direccion' => trim((string) ($e->Dire ?? '')),
            'fono' => trim((string) ($e->Fono ?? '')),
        ];
    }

    protected function rechazar(array $errores): never
    {
        abort(response()->json([
            'message' => reset($errores),
            'errors' => array_map(fn ($m) => [$m], $errores),
        ], 422));
    }

    /**
     * A nombre de qué vendedor queda el documento.
     *
     * Por omisión, el de quien lo está escribiendo. Un supervisor puede grabar
     * a nombre de alguien de su gente — pasa en la práctica, cuando entra un
     * pedido por teléfono y lo carga el jefe — pero un vendedor no puede
     * atribuirle una venta a otro: eso descuadraría las comisiones de los dos.
     */
    protected function vendedorDe(array $data, Request $request): ?string
    {
        $u = $this->usuario($request);
        $pedido = trim((string) ($data['vendedor'] ?? ''));

        if ($pedido === '') {
            return $u->ven_cod;
        }

        if (! $this->alcanza($request, $pedido)) {
            $this->rechazar(['vendedor' => 'No puedes grabar documentos a nombre de otro vendedor.']);
        }

        return $pedido;
    }

    /**
     * ¿Este usuario puede ver/tocar un documento de este vendedor?
     *
     * Misma regla que la descarga de maestros: el vendedor ve lo suyo, el
     * supervisor lo de su gente, administración y facturación todo. Y sin
     * contexto no se abre nada.
     */
    protected function alcanza(Request $request, ?string $venCod): bool
    {
        $visibles = $this->usuario($request)->vendedoresVisibles();

        return $visibles === null || in_array(trim((string) $venCod), $visibles, true);
    }

    // ----------------------------------------------------------------- correo

    /**
     * Arma y manda el correo de un documento.
     *
     * Todo lo que el correo necesita y el documento no trae — el nombre del
     * cliente, el nombre de cada producto, el símbolo de la moneda — se resuelve
     * aquí. En la plantilla no se consulta nada: una vista que hace consultas es
     * una vista que se cae distinto en cada correo.
     */
    protected function avisarDocumento(
        Notificador $notificador,
        string $evento,
        string $titulo,
        array $doc,
        array $lineas,
        Usuario $u,
        bool $conPdf = false,
    ): void {
        $ctx = $this->contextoCorreo($doc, $lineas);

        $adjuntos = [];
        if ($conPdf) {
            $pdf = app(DocumentoPdf::class);
            $adjuntos[] = [
                'nombre' => $pdf->nombre($this->recurso(), (int) ($doc['numero'] ?? 0)),
                'contenido' => $pdf->generar($titulo, $doc, $ctx['cliente'], $lineas, $ctx),
                'mime' => 'application/pdf',
            ];
        }

        $notificador->disparar(
            $evento,
            [
                'asunto' => $titulo.' — '.($ctx['cliente']['nombre'] ?? ''),
                'cuerpo_html' => view('correo.documento', [
                    'titulo' => $titulo,
                    'documento' => $doc,
                    'lineas' => $lineas,
                ] + $ctx)->render(),
                'referencia' => $this->recurso().':'.($doc['numero'] ?? ''),
            ],
            dueno: $u,
            emailCliente: $ctx['cliente']['email'] ?? null,
            actor: $u,
            adjuntos: $adjuntos,
        );
    }

    /**
     * Todo lo que el correo y el PDF necesitan y el documento no trae: el
     * nombre del cliente, el de cada producto, el del vendedor, el símbolo de
     * la moneda. Se resuelve aquí de una vez y las plantillas no consultan
     * nada — una vista que hace consultas se cae distinto en cada correo.
     */
    protected function contextoCorreo(array $doc, array $lineas): array
    {
        $conn = DB::connection('softland');

        $nombres = $conn->table('softland.iw_tprod')
            ->whereIn('CodProd', array_column($lineas, 'producto'))
            ->pluck('DesProd', 'CodProd')
            ->mapWithKeys(fn ($n, $c) => [trim($c) => trim((string) $n)])->all();

        $moneda = $conn->table('softland.cwtmone')
            ->where('CodMon', $doc['moneda'] ?? '01')->first(['SimMon', 'DesMon']);

        return [
            'cliente' => $this->maestros->uno('clientes', ['CodAux' => $doc['cliente'] ?? '']) ?? [],
            'nombres' => $nombres,
            'moneda_simbolo' => trim((string) ($moneda->SimMon ?? '')) ?: '$',
            'moneda_nombre' => trim((string) ($moneda->DesMon ?? '')) ?: 'pesos chilenos',
            'vendedor' => trim((string) $conn->table('softland.cwtvend')
                ->where('VenCod', $doc['vendedor'] ?? '')->value('VenDes')),
            'condicion' => trim((string) $conn->table('softland.cwtconv')
                ->where('CveCod', $doc['condicion'] ?? '')->value('CveDes')),
            // La ficha de la empresa emisora, para la cabecera del PDF. En
            // `soempre` la razón social es `NomB`, no `NomEmp`: conviene mirar
            // `sys.columns` antes de dar por buena cualquier columna de Softland.
            'empresa' => $this->emisor($conn),
            'rut_emisor' => app(Catalogos::class)->rutEmisor(),
        ];
    }

    /** Traduce la excepción de un producto o una moneda imposible en un 422 legible. */
    protected function escribir(callable $fn)
    {
        try {
            return $fn();
        } catch (\RuntimeException $e) {
            $this->rechazar(['lineas' => $e->getMessage()]);
        }
    }
}
