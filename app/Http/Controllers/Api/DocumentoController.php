<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\Documentos\Emision;
use App\Services\Documentos\TipoDocumento;
use App\Services\Notificaciones\Notificador;
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
 * existen, la regla de quién puede tocar qué, y — desde el motor de documentos
 * — el PDF y el envío al cliente. Lo que cambia de una a otra — tablas,
 * columnas, estados — está en cada controlador.
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

    /** `cotizaciones` o `notas_venta`, como los nombra el maestro. */
    abstract protected function recurso(): string;

    /** La cabecera del documento tal como la sirve el maestro, o null si no está. */
    abstract protected function documentoDe(int $numero): ?array;

    /** Las líneas del documento, tal como las sirve el maestro. */
    abstract protected function lineasDocumento(int $numero): array;

    /** El vendedor a cuyo nombre está el documento, para comprobar el alcance. */
    abstract protected function vendedorDelDocumento(int $numero): ?string;

    /** Qué se le dice a quien pide un documento que no existe o no es suyo. */
    abstract protected function noEncontrado(): string;

    /** El evento de notificación que corresponde a mandárselo al cliente. */
    abstract protected function eventoEnvio(): string;

    /** ¿El centro de costo es obligatorio? En la NV sí (`nwparam.CheckExigeCCostoN = S`). */
    protected function exigeCentroCosto(): bool
    {
        return false;
    }

    protected function tipoDoc(): TipoDocumento
    {
        return TipoDocumento::desdeRecurso($this->recurso());
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

        $data['vendedor'] = $this->vendedorDe($data, $request);
        $this->verificarCodigos($data, $request);

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
     *
     * **Nunca devuelve null.** Un documento sin vendedor no aparece en las
     * búsquedas del Softland de escritorio: de las 2.351 cotizaciones de
     * INNOVAGES, la única con `VenCod` nulo era una escrita por esta app,
     * grabada por un administrador que no tiene vendedor asociado. Antes que
     * escribir un documento invisible, se rechaza y se dice qué falta.
     */
    protected function vendedorDe(array $data, Request $request): string
    {
        $u = $this->usuario($request);
        $pedido = trim((string) ($data['vendedor'] ?? ''));

        if ($pedido === '') {
            $propio = trim((string) $u->ven_cod);

            if ($propio === '') {
                $this->rechazar(['vendedor' => 'Elige el vendedor: tu usuario no tiene uno asociado.']);
            }

            return $propio;
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

    // ----------------------------------------------------- anular y eliminar

    /**
     * Desde dónde se puede anular: sólo pendiente. Un documento perdido, ya
     * convertido, aprobado o concluido tuvo un desenlace, y anularlo lo
     * borraría de la historia comercial en vez de cerrarlo.
     *
     * El vacío es por las cotizaciones viejas de INNOVAGES, que no tienen
     * estado escrito.
     */
    protected const ANULABLES = ['P', ''];

    /** Anula el documento en Softland: `N`, que allá quiere decir «nula». */
    abstract protected function anularEnSoftland(int $numero, Usuario $u): void;

    /** Lo borra de Softland de verdad, y con eso su número vuelve al pozo. */
    abstract protected function eliminarDeSoftland(int $numero): void;

    /** Por qué este documento no se puede eliminar. Vacío = se puede. */
    abstract protected function impedimentosParaEliminar(int $numero): array;

    /** El documento tal como lo devuelven `store` y `update`. */
    abstract protected function respuesta(int $numero): array;

    /**
     * Anular: el documento se queda donde está y conserva su número.
     *
     * Es lo que corresponde casi siempre, y desde luego lo único correcto si
     * el papel ya salió. El cliente tiene un PDF que dice «Cotización N° 8551»;
     * que ese número no exista después es peor que que exista anulado.
     */
    public function anular(Request $request, int $numero)
    {
        $u = $this->usuario($request);
        $doc = $this->documentoDe($numero);

        if (! $doc || ! $this->alcanza($request, $this->vendedorDelDocumento($numero))) {
            return response()->json(['message' => $this->noEncontrado()], 404);
        }

        $estado = strtoupper(trim((string) ($doc['estado'] ?? '')));

        if ($estado === 'N') {
            return response()->json(['message' => 'Ya estaba anulada.'], 409);
        }

        if (! in_array($estado, static::ANULABLES, true)) {
            return response()->json([
                'message' => 'No se puede anular: está '
                    .mb_strtolower($this->tipoDoc()->estado($estado), 'UTF-8').'.',
            ], 409);
        }

        $this->anularEnSoftland($numero, $u);

        return response()->json($this->respuesta($numero));
    }

    /**
     * Eliminar: la fila desaparece de Softland.
     *
     * Sólo para un documento que nunca salió de la casa, y con las cuatro
     * condiciones puestas: que lo haya creado esta app, que no se le haya
     * entregado al cliente, que no haya avanzado a nada — nota de venta,
     * factura, picking, compra — y que quien lo pide lo tenga a su alcance.
     * Las tres primeras las contesta `Ventas`, que es donde está escrito lo que
     * el ERP arrastra detrás de cada documento; la cuarta, el alcance por
     * vendedor, es de aquí.
     *
     * Es irreversible y **el número vuelve al pozo**: el siguiente documento
     * que se cree puede quedarse con él. Por eso la app ofrece antes anular, y
     * por eso la bitácora de Softland — que sí sobrevive al borrado — queda con
     * su evento `Elimina`.
     */
    public function destroy(Request $request, int $numero, Emision $emision)
    {
        $doc = $this->documentoDe($numero);

        if (! $doc || ! $this->alcanza($request, $this->vendedorDelDocumento($numero))) {
            return response()->json(['message' => $this->noEncontrado()], 404);
        }

        if ($razones = $this->impedimentosParaEliminar($numero)) {
            return response()->json([
                'message' => 'No se puede eliminar. '.implode(' ', $razones),
                'razones' => $razones,
                'puede_anular' => in_array(
                    strtoupper(trim((string) ($doc['estado'] ?? ''))), static::ANULABLES, true
                ),
            ], 409);
        }

        $emision->borrar($this->tipoDoc(), $numero);
        $this->eliminarDeSoftland($numero);

        return response()->json(['eliminado' => $numero]);
    }

    // -------------------------------------------------------------- el papel

    /**
     * El PDF del documento.
     *
     * Se dibuja en el servidor, siempre. El teléfono guarda los bytes que le
     * llegan y los vuelve a abrir sin señal, pero nunca dibuja: el número del
     * documento lo asigna el servidor, y un PDF que dice «Cotización N° —» no
     * es un documento comercial.
     *
     * Sale como `application/pdf` en crudo — no en JSON con base64 — para que
     * el teléfono lo guarde tal cual y la hoja de compartir de Android lo
     * reconozca sin traducir nada.
     */
    public function pdf(Request $request, int $numero, Emision $emision)
    {
        $u = $this->usuario($request);

        if (! $this->alcanza($request, $this->vendedorDelDocumento($numero))) {
            return response()->json(['message' => $this->noEncontrado()], 404);
        }

        $doc = $this->documentoDe($numero);
        if (! $doc) {
            return response()->json(['message' => $this->noEncontrado()], 404);
        }

        $r = $emision->emitir(
            $this->tipoDoc(),
            $numero,
            $this->contextoDocumento($doc, $this->lineasDocumento($numero)),
            $u,
        );

        return response($r['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->tipoDoc()->archivo($numero).'"',
            // Para que el teléfono sepa si lo que tiene guardado sigue vigente
            // sin volver a bajar 60 KB.
            'X-Documento-Version' => (string) $r['version'],
            'X-Documento-Hash' => $r['hash'],
        ]);
    }

    /**
     * Manda el documento al cliente por correo, con el PDF adjunto.
     *
     * Es una acción deliberada del vendedor, no un efecto de guardar: por eso
     * tiene su propio camino y su propio botón. Un documento se corrige tres
     * veces antes de mandarlo, y un correo por cada guardado sería una plaga.
     *
     * El PDF va adjunto y no enlazado — un enlace a la dirección interna del
     * servidor no se abre desde fuera de la oficina, y el cliente está fuera de
     * la oficina.
     */
    public function enviar(Request $request, int $numero, Notificador $notificador, Emision $emision)
    {
        $u = $this->usuario($request);

        if (! $this->alcanza($request, $this->vendedorDelDocumento($numero))) {
            return response()->json(['message' => $this->noEncontrado()], 404);
        }

        $doc = $this->documentoDe($numero);
        if (! $doc) {
            return response()->json(['message' => $this->noEncontrado()], 404);
        }

        $cliente = $this->maestros->uno('clientes', ['CodAux' => $doc['cliente'] ?? '']) ?? [];
        if (empty($cliente['email'])) {
            return response()->json([
                'message' => 'Ese cliente no tiene correo. Agrégalo en su ficha y vuelve a intentar.',
            ], 422);
        }

        $this->avisarDocumento(
            $notificador,
            $this->eventoEnvio(),
            $this->tipoDoc()->titulo().' '.$numero,
            $doc,
            $this->lineasDocumento($numero),
            $u,
            conPdf: true,
        );

        $emision->marcarEnviado($this->tipoDoc(), $numero, 'correo');

        return response()->json([
            'enviada_a' => $cliente['email'],
            'message' => $this->tipoDoc()->titulo().' enviada a '.$cliente['email'].'.',
        ]);
    }

    /**
     * Deja constancia de que el documento salió por un camino que el servidor no
     * controla: la hoja de compartir de Android, WhatsApp, una impresora.
     *
     * Importa por el snapshot: a partir de aquí esa versión del PDF es la que
     * tiene el cliente, y corregir el documento genera la siguiente en vez de
     * pisarla.
     */
    public function compartido(Request $request, int $numero, Emision $emision)
    {
        if (! $this->alcanza($request, $this->vendedorDelDocumento($numero))) {
            return response()->json(['message' => $this->noEncontrado()], 404);
        }

        $canal = $request->input('canal');
        $canal = in_array($canal, ['whatsapp', 'descarga', 'impresion'], true) ? $canal : 'descarga';

        $emision->marcarEnviado($this->tipoDoc(), $numero, $canal);

        return response()->json(['ok' => true]);
    }

    // ----------------------------------------------------------------- correo

    /**
     * Arma y manda el correo de un documento.
     *
     * El PDF, cuando va, se pide al motor a través de la emisión: así el archivo
     * que recibe el cliente queda guardado con su hash y su fecha, y no hay dos
     * caminos distintos para dibujar el mismo papel.
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
        $ctx = $this->contextoDocumento($doc, $lineas);
        $numero = (int) ($doc['numero'] ?? 0);

        $adjuntos = [];
        if ($conPdf && $numero > 0) {
            $r = app(Emision::class)->emitir($this->tipoDoc(), $numero, $ctx, $u);
            $adjuntos[] = [
                'nombre' => $this->tipoDoc()->archivo($numero),
                'contenido' => $r['pdf'],
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

    // --------------------------------------------------------------- contexto

    /**
     * Todo lo que el papel y el correo necesitan y el documento no trae.
     *
     * Se resuelve aquí de una vez y las plantillas no consultan nada: una vista
     * que hace consultas se cae distinto en cada correo, y el snapshot de la
     * emisión guarda exactamente esto — si faltara un dato, faltaría también en
     * el archivo histórico.
     */
    protected function contextoDocumento(array $doc, array $lineas): array
    {
        $conn = DB::connection('softland');
        $t = fn ($v) => trim((string) ($v ?? ''));

        $cliente = $this->maestros->uno('clientes', ['CodAux' => $doc['cliente'] ?? '']) ?? [];

        $nombres = $conn->table('softland.iw_tprod')
            ->whereIn('CodProd', array_column($lineas, 'producto'))
            ->pluck('DesProd', 'CodProd')
            ->mapWithKeys(fn ($n, $c) => [trim($c) => trim((string) $n)])->all();

        $moneda = $conn->table('softland.cwtmone')
            ->where('CodMon', $doc['moneda'] ?? '01')->first(['SimMon', 'DesMon']);

        return [
            'documento' => $doc,
            'lineas' => $lineas,
            'cliente' => $cliente,
            'nombres' => $nombres,
            'moneda_simbolo' => $t($moneda->SimMon ?? '') ?: '$',
            'moneda_nombre' => $t($moneda->DesMon ?? '') ?: 'pesos chilenos',
            // La comuna y la ciudad del cliente son códigos, no nombres. Sin
            // traducirlos el documento diría «Dirección: Ongolmo 2155, 081».
            // `GirAux` es un código (`ADI`), no el giro escrito. Sin traducirlo
            // el documento diría «Giro: ADI», que no le dice nada al cliente.
            'giro_cliente' => $t($conn->table('softland.cwtgiro')
                ->where('GirCod', $cliente['giro'] ?? '')->value('GirDes')),
            'comuna_cliente' => $t($conn->table('softland.cwtcomu')
                ->where('ComCod', $cliente['comuna'] ?? '')->value('ComDes')),
            'ciudad_cliente' => $t($conn->table('softland.cwtciud')
                ->where('CiuCod', $cliente['ciudad'] ?? '')->value('CiuDes')),
            'contacto' => $this->contactoDe($doc),
            // El estado en palabras. En el papel importa: una cotización
            // perdida y una pendiente se imprimen igual, y quien la recibe por
            // correo no tiene la app delante para distinguirlas.
            'estado_nombre' => $this->tipoDoc()->estado($doc['estado'] ?? null),
            'vendedor' => $this->vendedorFicha($doc['vendedor'] ?? ''),
            'condicion' => $t($conn->table('softland.cwtconv')
                ->where('CveCod', $doc['condicion'] ?? '')->value('CveDes')),
            'bodega' => $t($conn->table('softland.iw_tbode')
                ->where('CodBode', $doc['bodega'] ?? '')->value('DesBode')),
            'centro_costo' => $t($conn->table('softland.cwtccos')
                ->where('CodiCC', $doc['centro_costo'] ?? '')->value('DescCC')),
            'impuestos' => $this->impuestosDe((int) ($doc['numero'] ?? 0)),
            'uf' => $this->ufDe($doc['fecha'] ?? null),
            'moneda_producto' => $this->monedaDeLasLineas($lineas),
        ];
    }

    /**
     * El fono y el correo de la persona a la que va dirigido.
     *
     * En Softland el documento guarda el **nombre** del contacto (`NomCon`), no
     * un id: la clave de `cwtaxco` es cliente + nombre. Por eso la búsqueda va
     * por los dos.
     */
    private function contactoDe(array $doc): array
    {
        $nombre = trim((string) ($doc['contacto'] ?? ''));
        if ($nombre === '') {
            return [];
        }

        $c = DB::connection('softland')->table('softland.cwtaxco')
            ->where('CodAuc', $doc['cliente'] ?? '')
            ->where('NomCon', $nombre)
            ->first(['FonCon', 'Email']);

        return [
            'nombre' => $nombre,
            'fono' => trim((string) ($c->FonCon ?? '')),
            'email' => trim((string) ($c->Email ?? '')),
        ];
    }

    /**
     * Quién firma.
     *
     * El nombre y el correo salen de `cwtvend`, que es lo que Softland tiene.
     * El cargo y el teléfono salen de `ventas.usuario`: `cwtvend` sólo guarda
     * código, nombre, tipo, correo y usuario, así que no hay de dónde sacarlos
     * del ERP. Nada de esto se escribe a mano en la plantilla.
     */
    private function vendedorFicha(string $venCod): array
    {
        $venCod = trim($venCod);
        if ($venCod === '') {
            return [];
        }

        $v = DB::connection('softland')->table('softland.cwtvend')
            ->where('VenCod', $venCod)->first(['VenDes', 'EMail']);

        $u = Usuario::where('ven_cod', $venCod)->first();

        return [
            'nombre' => trim((string) ($v->VenDes ?? '')) ?: ($u->nombre ?? ''),
            'cargo' => trim((string) ($u->cargo ?? '')),
            'fono' => trim((string) ($u->fono ?? '')),
            'email' => trim((string) ($v->EMail ?? '')) ?: trim((string) ($u->email ?? '')),
        ];
    }

    /**
     * Los impuestos tal como los estampó el documento, no recalculados.
     *
     * Softland guarda la tasa documento a documento en `valpctIni` y no en
     * ningún maestro: leerla de aquí es la única forma de que un documento de
     * hace tres años se reimprima con el IVA que tenía entonces. Y como es una
     * lista, el día que se calcule el ILA entra como una fila más sin tocar ni
     * la plantilla ni esto.
     */
    private function impuestosDe(int $numero): array
    {
        if ($numero <= 0) {
            return [];
        }

        [$tabla, $columna] = $this->tipoDoc() === TipoDocumento::NOTA_VENTA
            ? ['softland.NW_Impto', 'nvNumero']
            : ['softland.NWCtImpto', 'CotNum'];

        return DB::connection('softland')->table($tabla)
            ->where($columna, $numero)
            ->get(['codimpto', 'valpctIni', 'Impto'])
            ->map(function ($i) {
                $codigo = trim((string) $i->codimpto);
                $pct = (float) $i->valpctIni;

                return [
                    'nombre' => $pct > 0
                        ? $codigo.' '.rtrim(rtrim(number_format($pct, 1, ',', '.'), '0'), ',').' %'
                        : $codigo,
                    'monto' => (float) $i->Impto,
                ];
            })->all();
    }

    /**
     * El valor de la UF del día del documento, para las condiciones.
     *
     * El corte va al segundo y no con `endOfDay()`: `datetime` de SQL Server
     * redondea a 3,33 ms, y las 23:59:59.999 se guardan como las 00:00 del día
     * siguiente — la consulta devolvía el valor de mañana.
     */
    private function ufDe(?string $fecha): ?float
    {
        $dia = $fecha ? substr($fecha, 0, 10) : now()->format('Y-m-d');

        $v = DB::connection('softland')->table('softland.so_UF')
            ->where('Fecha', '<=', $dia.' 23:59:59')
            ->orderByDesc('Fecha')
            ->value('Valor');

        return $v ? (float) $v : null;
    }

    /**
     * En qué moneda están tarifadas las líneas que no van en la del documento.
     *
     * Es el rótulo de la columna de conversión del detalle: «Valor UF» y no
     * «Valor 02». Si las líneas mezclan monedas — no ocurre en INNOVAGES — se
     * cae a un rótulo neutro antes que mentir con una.
     */
    private function monedaDeLasLineas(array $lineas): string
    {
        // Sólo las líneas que de verdad se convierten. Una línea de comentario
        // — el producto comodín `*` de Softland — viene con `equiv = 1` y su
        // moneda no dice nada del documento: contarla dejaba el rótulo en
        // «Valor origen» aunque todo lo demás estuviera en UF.
        $convertidas = array_column(array_filter(
            $lineas,
            fn ($l) => abs(((float) ($l['equiv'] ?? 1)) - 1) > 0.000001,
        ), 'producto');

        $codigos = DB::connection('softland')->table('softland.iw_tprod')
            ->whereIn('CodProd', $convertidas)
            ->pluck('CodMonPVta')->map(fn ($m) => trim((string) $m))->unique()->values();

        if ($codigos->count() !== 1) {
            return 'origen';
        }

        $nombre = DB::connection('softland')->table('softland.cwtmone')
            ->where('CodMon', $codigos[0])->value('SimMon');

        return trim((string) $nombre) ?: 'origen';
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
