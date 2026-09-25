<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documentos\Emision as EmisionPapel;
use App\Services\Documentos\TipoDocumento;
use App\Services\Dte\Caf;
use App\Services\Dte\Certificado;
use App\Services\Dte\CodigoBarras;
use App\Services\Dte\Codificacion;
use App\Services\Dte\Emision;
use App\Services\Dte\Facturacion;
use App\Services\Dte\ReglasFactura;
use App\Services\Dte\Sii;
use App\Services\Dte\TipoDte;
use App\Services\Softland\Maestros;
use App\Services\Softland\Saldo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
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
    use AlcancePorVendedor;

    public function __construct(
        private Facturacion $facturacion,
        private Saldo $saldo,
        private ReglasFactura $reglas,
        private Maestros $maestros,
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
            // Lo que la factura hereda de la venta. Va todo, se le facture a
            // quien se le facture: cambiar el pagador no cambia lo que se
            // vendió, ni con qué orden de compra, ni en qué bodega estaba.
            'oc' => self::ocDe($nv),
            'observacion' => trim((string) $nv->nvObser) ?: null,
            'bodega' => trim((string) $nv->CodBode) ?: null,
            // El contacto, que es una persona y no un código: `NomCon` guarda el
            // nombre, igual que en la cotización. La factura lo hereda como
            // hereda la condición de pago, y se puede cambiar — quien recibe la
            // factura en administración no siempre es quien pidió el presupuesto.
            'contacto' => trim((string) $nv->NomCon) ?: null,
            'fecha' => substr((string) $nv->nvFem, 0, 10),
            // Por usuario, no por empresa: el permiso lo concede Softland a
            // cada uno, y la pantalla tiene que enseñar lo que éste puede.
            'receptor_editable' => $this->reglas->receptorEditable(
                $this->usuario($request)->softland_user
            ),
            // Las referencias que van a salir solas, tal como las va a escribir
            // el servidor. Se calculan aquí y no en el teléfono a propósito:
            // cuáles salen lo decide la empresa, y una copia de esa regla en la
            // pantalla sería un papel con renglones que la pantalla no anunció.
            'referencias_automaticas' => array_map(
                fn ($r) => ['tipo_sii' => (string) $r['sii'], 'folio' => $r['folio'], 'fecha' => $r['fecha']],
                $this->referenciasDelDocumento([], $nv),
            ),
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
     * Cuántos folios quedan, antes de que exista ningún documento.
     *
     * La pantalla de factura libre lo necesita al abrirse: no hay nota de venta
     * de la que colgar la pregunta, y enterarse de que no hay folios después de
     * teclear el documento es la peor forma de enterarse.
     */
    public function folios()
    {
        return response()->json(['folios' => $this->foliosLibres(TipoDte::FACTURA)]);
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
            'client_uuid' => 'nullable|string|max:64',
            // «Sé lo que hago»: lo manda la bandeja cuando el vendedor
            // responde que sí a una factura que llegó tarde.
            'confirmado' => 'nullable|boolean',
            'nota_venta' => 'nullable|integer|min:1',
            'receptor' => 'required|string|max:12',
            'vendedor' => 'nullable|string|max:4',
            'fecha' => 'nullable|date',
            'centro_costo' => 'nullable|string|max:8',
            'condicion' => 'nullable|string|max:3',
            'bodega' => 'nullable|string|max:10',
            // 30, que es lo que mide `iw_gsaen.NomContacto`. Es el nombre de la
            // persona, no un código: así lo guarda el ERP y así lo guarda la
            // cotización en `nwcotiza.NomCon`.
            'contacto' => 'nullable|string|max:30',
            // 255, que es lo que mide `iw_gsaen.Glosa`. La observación de la
            // nota de venta cabe en 4.000 y aquí no: el teléfono enseña el
            // recorte antes de emitir, y `Facturacion` lo vuelve a cortar por
            // si llega de otra parte.
            'glosa' => 'nullable|string|max:255',
            // La orden de compra del cliente, que va al DTE como referencia
            // 801. Sale de la nota de venta; viaja en la petición porque puede
            // haber llegado después de escribirla, y entonces se corrige aquí
            // sin tener que volver a tocar la venta.
            'oc' => 'nullable|string|max:18',
            // Los papeles que esta factura nombra además de los suyos: la HES
            // que pide una eléctrica, un contrato, la factura que se está
            // reemplazando. El tipo se comprueba contra el maestro del ERP
            // antes de gastar un folio; ver `referenciasDelDocumento()`.
            'referencias' => 'nullable|array|max:20',
            'referencias.*.tipo_sii' => 'required|string|max:3',
            // Texto y no entero: hay folios referenciados como «272-OC00008216».
            'referencias.*.folio' => 'required|string|max:18',
            'referencias.*.fecha' => 'nullable|date',
            'referencias.*.glosa' => 'nullable|string|max:400',
            'lineas' => 'required|array|min:1|max:200',
            'lineas.*.producto' => 'required|string|max:20',
            'lineas.*.cantidad' => 'required|numeric|gt:0',
            'lineas.*.precio' => 'nullable|numeric|min:0',
            'lineas.*.glosa' => 'nullable|string|max:500',
            // Faltaba, y `validate()` devuelve sólo lo validado: el descuento
            // que se tecleaba en la factura suelta se caía aquí en silencio. La
            // pantalla enseñaba un total y se emitía otro, en un documento
            // tributario. El escritor siempre supo leerlo.
            'lineas.*.descuento_pct' => 'nullable|numeric|min:0|max:100',
            'lineas.*.nv_linea' => 'nullable|numeric|min:1',
        ]);

        $nv = null;

        if ($data['nota_venta'] ?? null) {
            $nv = $this->notaVenta((int) $data['nota_venta']);

            if (! $nv || ! $this->alcanza($request, $nv->VenCod)) {
                return response()->json(['message' => 'Esa nota de venta no existe o no es tuya.'], 404);
            }

            if ($pare = $this->elMundoCambio($nv, (bool) ($data['confirmado'] ?? false))) {
                return $pare;
            }
        }

        // De quién es la venta. Con nota de venta detrás no se pregunta: lo
        // hereda de ella `Facturacion`, y quien factura no tiene por qué ser
        // vendedor —facturación y administración no lo son—. Sin nota de venta
        // no hay de dónde heredarlo y hay que decirlo, con la misma regla que
        // la cotización: el tuyo, o el de tu gente si eres supervisor.
        $vendedor = ($data['nota_venta'] ?? null)
            ? trim((string) $u->ven_cod)
            : $this->vendedorDe($data, $request);

        try {
            $doc = $this->facturacion->escribir([
                'tipo' => TipoDte::FACTURA,
                'client_uuid' => $data['client_uuid'] ?? null,
                'usuario_id' => (int) $u->id,
                'receptor' => $data['receptor'],
                'vendedor' => $vendedor,
                // `softland_user`, no `usuario`: esa propiedad no existe y se
                // escribía en blanco. En `iw_gsaen` el ERP pone aquí el usuario
                // de Softland —«jpalomin» en las facturas de escritorio—, y es
                // por quién se pregunta cuando alguien cuadra el mes.
                'usuario' => substr((string) ($u->softland_user ?: $u->email), 0, 8),
                'fecha' => $data['fecha'] ?? null,
                'centro_costo' => $data['centro_costo'] ?? null,
                'cond_pago' => $data['condicion'] ?? null,
                'bodega' => $data['bodega'] ?? null,
                'contacto' => $data['contacto'] ?? null,
                'glosa' => $data['glosa'] ?? null,
                'nota_venta' => $data['nota_venta'] ?? null,
                'referencias' => $this->referenciasDelDocumento($data, $nv),
                'lineas' => $data['lineas'],
            ]);
        } catch (Throwable $e) {
            // Lo que falla aquí es casi siempre una regla de negocio dicha en
            // castellano —sin folios, receptor que no corresponde, línea que no
            // existe—, y el vendedor tiene que poder leerla.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            $this->documento($doc['tipo'], $doc['nroint'])
                + ['sii' => $this->mandarAlSii($doc['tipo'], $doc['nroint'])],
            201
        );
    }

    /**
     * Qué llevaría la nota de crédito que anula esta factura.
     *
     * **Anulación entera, no devolución parcial.** El SII distingue: `CodRef 1`
     * anula el documento, `2` corrige su texto y `3` corrige sus montos. Devolver
     * tres de diez unidades no es anular, es otro documento con otra referencia
     * y otra razón. Aquí se hace lo primero, que es lo que se pide cuando una
     * factura salió mal.
     */
    public function propuestaNotaCredito(Request $request, string $tipo, int $numeroInterno)
    {
        $letra = strtoupper($tipo);
        $doc = $this->documento($letra, $numeroInterno);

        if (! $doc || ! $this->alcanza($request, $doc['documento']['vendedor'])) {
            return response()->json(['message' => 'Esa factura no existe o no es tuya.'], 404);
        }

        if ($letra === 'N') {
            return response()->json(['message' => 'Una nota de crédito no se anula con otra.'], 409);
        }

        if ($doc['documento']['estado'] === 'N') {
            return response()->json(['message' => 'Esa factura ya está anulada en el ERP.'], 409);
        }

        if ($ya = $this->acreditadaPor($letra, $doc['documento']['subtipo'], $doc['documento']['folio'])) {
            return response()->json([
                'message' => "Esta factura ya se anuló con la nota de crédito {$ya}.",
                'nota_credito' => $ya,
            ], 409);
        }

        return response()->json([
            'factura' => $doc['documento'],
            'folios' => $this->foliosLibres(TipoDte::NOTA_CREDITO),
            'lineas' => $this->facturacion->propuestaNotaCredito($letra, $numeroInterno),
        ]);
    }

    /**
     * Emite la nota de crédito que anula la factura.
     *
     * Las líneas **no llegan del teléfono**: las arma el servidor desde la
     * factura. Anular es devolver lo que se facturó, todo y tal cual; dejar que
     * el cliente mande las líneas sería dejar abierta la puerta a una nota de
     * crédito que no cuadra con lo que anula.
     */
    public function notaCredito(Request $request, string $tipo, int $numeroInterno)
    {
        $letra = strtoupper($tipo);
        $previo = $this->propuestaNotaCredito($request, $letra, $numeroInterno);

        if ($previo->getStatusCode() !== 200) {
            return $previo;
        }

        $u = $this->usuario($request);
        $doc = $this->documento($letra, $numeroInterno);
        $factura = $doc['documento'];
        $data = $request->validate([
            'razon' => 'nullable|string|max:90',
            'client_uuid' => 'nullable|string|max:64',
        ]);

        // Las líneas las arma el servidor y no se aceptan del teléfono: la
        // petición ni siquiera tiene dónde traerlas.
        $lineas = $this->facturacion->propuestaNotaCredito($letra, $numeroInterno);

        // Y se comprueba que de verdad devuelvan **todo**. Aquí sólo se emiten
        // notas de crédito de anulación: el SII distingue `CodRef 1` —anula— de
        // `2` y `3`, que corrigen texto y montos, y una que devuelve parte no es
        // ninguna de las tres cosas que decimos estar haciendo. Si algún día
        // `propuestaNotaCredito()` dejara fuera una línea, esto lo para antes de
        // gastar el folio en un documento que dice una cosa y hace otra.
        if (! Facturacion::devuelveTodo($lineas, $doc['lineas'])) {
            return response()->json([
                'message' => 'Esta nota de crédito no devolvería la factura entera, y aquí sólo '
                    .'se emiten anulaciones. Una devolución parcial es otro documento.',
            ], 422);
        }

        try {
            $doc = $this->facturacion->escribir([
                'tipo' => TipoDte::NOTA_CREDITO,
                'client_uuid' => $data['client_uuid'] ?? null,
                'usuario_id' => (int) $u->id,
                'receptor' => $factura['cliente'],
                // Lo pisa el de la factura que anula; va por si esa no tuviera.
                'vendedor' => trim((string) $u->ven_cod),
                // `softland_user`, no `usuario`: esa propiedad no existe y se
                // escribía en blanco. En `iw_gsaen` el ERP pone aquí el usuario
                // de Softland —«jpalomin» en las facturas de escritorio—, y es
                // por quién se pregunta cuando alguien cuadra el mes.
                'usuario' => substr((string) ($u->softland_user ?: $u->email), 0, 8),
                'centro_costo' => $factura['centro_costo'] ?: null,
                'cond_pago' => $factura['condicion'] ?: null,
                'glosa' => $data['razon'] ?? null,
                'lineas' => $lineas,
                'referencia' => [
                    'folio' => $factura['folio'],
                    'fecha' => substr((string) $factura['fecha'], 0, 10),
                    'tipo' => $letra,
                    'subtipo' => $factura['subtipo'],
                    // «1» es anular, y es lo único que emite esta pantalla.
                    'codigo' => '1',
                    // **En `Glosa`, no en `RazonRef`.** El `RazonRef` del DTE
                    // sale de la columna `Glosa` de Softland; la columna que se
                    // llama `RazonRef` está vacía en los 209 documentos reales.
                    // Escribir en la que se llama igual habría funcionado por
                    // casualidad y dejado el ERP diciendo otra cosa.
                    'glosa' => $data['razon'] ?? 'Anula Documento',
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            $this->documento($doc['tipo'], $doc['nroint'])
                + ['sii' => $this->mandarAlSii($doc['tipo'], $doc['nroint'])],
            201
        );
    }

    /**
     * El folio de la nota de crédito que ya anuló esta factura, si la hay.
     *
     * Se busca por la referencia del DTE —tipo del SII y folio—, que es donde
     * de verdad se dice qué se acredita. **No por `AuxDocNum`**: ahí coincide
     * por casualidad, pero 5.317 facturas de NETDOMAIN lo llevan relleno con
     * otra cosa.
     */
    private function acreditadaPor(string $letra, string $subtipo, int $folio): ?int
    {
        $tipo = TipoDte::desdeSoftland($letra, $subtipo);

        if (! $tipo) {
            return null;
        }

        $folioNc = DB::connection('softland')->table('softland.iw_gsaen AS s')
            ->join('softland.IW_GSaEn_RefDTE AS r', function ($j) {
                $j->on('r.Tipo', '=', 's.Tipo')->on('r.NroInt', '=', 's.NroInt');
            })
            ->where('s.Tipo', 'N')
            ->where('s.Estado', '<>', 'N')
            ->where('r.CodRefSII', (string) $tipo->value)
            ->where('r.FolioRef', (string) $folio)
            ->value('s.Folio');

        return $folioNc ? (int) $folioNc : null;
    }

    /**
     * Manda el documento al SII.
     *
     * **Es lo más irreversible que hace la app.** Un documento escrito en
     * inventario se corrige; uno que ya viajó al SII existe para el fisco, y el
     * arreglo es una nota de crédito. De ahí las barreras, que no son ceremonia:
     *
     *  - **sólo facturación y administración**. Escribir la factura es trabajo
     *    del vendedor; mandarla al SII es un acto tributario de la empresa, y
     *    quien lo hace tiene que ser quien responde por él. Si el cliente
     *    prefiere que el vendedor también pueda, se abre aquí en una línea.
     *  - **no se manda dos veces**: si el folio ya tiene `TrackID`, se dice cuál
     *    en vez de repetir el envío.
     *  - el resto —certificado vencido, tipo que no va por este camino, folio que
     *    no cuadra— lo para `Emision`, que es donde no lo puede esquivar nadie.
     */
    public function enviar(Request $request, string $tipo, int $numeroInterno)
    {
        // Sin barrera de rol, a propósito, y es un cambio respecto de antes.
        // Emitir y mandar son un solo acto desde que la app manda sola al
        // emitir: quien puede escribir el documento ya está haciendo el acto
        // tributario, y separar el permiso sólo conseguía que la factura del
        // vendedor se quedara esperando a que alguien de la oficina se
        // acordara. El permiso que sí tiene sentido es «quién puede emitir», y
        // ése se aplica antes, al escribir.
        $letra = strtoupper($tipo);
        $doc = $this->documento($letra, $numeroInterno);

        if (! $doc || ! $this->alcanza($request, $doc['documento']['vendedor'])) {
            return response()->json(['message' => 'Ese documento no existe o no es tuyo.'], 404);
        }

        if ($doc['documento']['estado'] === 'N') {
            return response()->json(['message' => 'Ese documento está anulado en el ERP.'], 409);
        }

        try {
            $cert = Certificado::desdeConfiguracion();
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $emision = new Emision($cert);
        $tipoDte = TipoDte::desdeSoftland($letra, $doc['documento']['subtipo']);

        if ($tipoDte && $ya = $emision->seguimiento($tipoDte, $doc['documento']['folio'])) {
            return response()->json([
                'message' => "Este documento ya se mandó al SII: TrackID {$ya}.",
                'track_id' => $ya,
            ], 409);
        }

        try {
            $r = $emision->emitir($letra, $numeroInterno);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'track_id' => $r['trackId'],
            'folio' => $r['folio'],
            'glosa' => $r['glosa'],
            'aviso' => $cert->avisaVencimiento(),
            'documento' => $this->documento($letra, $numeroInterno)['documento'],
        ], 201);
    }

    /**
     * En qué quedó el envío.
     *
     * El veredicto tarda: recién mandado el SII contesta «en proceso», y eso no
     * es un error sino que todavía no lo ha mirado. Por eso esto es una consulta
     * aparte y no algo que se espere dentro del envío.
     */
    public function estadoSii(Request $request, string $tipo, int $numeroInterno)
    {
        $letra = strtoupper($tipo);
        $doc = $this->documento($letra, $numeroInterno);

        if (! $doc || ! $this->alcanza($request, $doc['documento']['vendedor'])) {
            return response()->json(['message' => 'Ese documento no existe o no es tuyo.'], 404);
        }

        $seguimiento = DB::connection('softland')->table('softland.dte_doccab')
            ->where('Tipo', $letra)->where('NroInt', $numeroInterno)
            ->first(['TrackID', 'EnviadoSII', 'AceptadoSII', 'Motivo', 'FechaEnvioSII']);

        $track = trim((string) ($seguimiento->TrackID ?? ''));

        if ($track === '' || $track === '0') {
            return response()->json([
                'enviado' => false,
                'message' => 'Este documento todavía no se ha mandado al SII.',
            ]);
        }

        try {
            $cert = Certificado::desdeConfiguracion();
            $sii = new Sii($cert);
            $rut = strtoupper(trim((string) DB::connection('softland')
                ->table('softland.soempre')->value('RutEmisor')));

            $envio = $sii->estadoEnvio($track, $rut);
        } catch (Throwable $e) {
            // Sin señal o con el SII caído, lo guardado sigue sirviendo: dice
            // que se mandó y cuándo, que es la mitad de la pregunta.
            return response()->json([
                'enviado' => true,
                'track_id' => $track,
                'fecha_envio' => $seguimiento->FechaEnvioSII ?? null,
                'motivo' => $seguimiento->Motivo ?? null,
                'message' => 'No se pudo preguntarle al SII: '.$e->getMessage(),
            ]);
        }

        // «EPR» es «envío procesado»: el SII terminó de mirarlo.
        $resuelto = in_array($envio['estado'], ['EPR', 'DOK'], true);

        // Y se anota, que es lo que hace que el verde aparezca solo. El SII no
        // avisa: hasta que alguien pregunta, no se sabe, y si nadie guarda la
        // respuesta hay que volver a preguntar cada vez.
        if ($resuelto && $tipoDte = TipoDte::desdeSoftland($letra, $doc['documento']['subtipo'])) {
            (new Emision(Certificado::desdeConfiguracion()))->anotarVeredicto(
                $tipoDte,
                $doc['documento']['folio'],
                aceptado: (int) $envio['rechazados'] === 0 && (int) $envio['aceptados'] > 0,
                motivo: trim($envio['glosa'].' · rechazados '.$envio['rechazados'].', con reparos '.$envio['reparos']),
            );
        }

        return response()->json([
            'enviado' => true,
            'track_id' => $track,
            'fecha_envio' => $seguimiento->FechaEnvioSII ?? null,
            'estado' => $envio['estado'],
            'glosa' => $envio['glosa'],
            'aceptados' => $envio['aceptados'],
            'rechazados' => $envio['rechazados'],
            'reparos' => $envio['reparos'],
            'resuelto' => $resuelto,
        ]);
    }

    /**
     * Borra una factura que nunca llegó al SII.
     *
     * **No es lo mismo que anular.** Anular deja el documento en `N` con su
     * folio y su historia; borrar lo quita y **devuelve el folio**, porque un
     * documento que no viajó nunca existió para el fisco. Lo comprobamos contra
     * el repartidor de Softland: en cuanto deja de ver el folio ocupado, lo
     * vuelve a entregar.
     *
     * Tres condiciones, y las tres por el mismo motivo — que lo borrado no le
     * falte a nadie:
     *
     *  1. **Que no tenga TrackID.** Enviada existe para el SII y sólo se corrige
     *     con una nota de crédito.
     *  2. **Que la haya escrito esta app.** Una factura del Softland de
     *     escritorio se borra desde el Softland de escritorio: no conocemos su
     *     historia ni lo que cuelga de ella.
     *  3. **Que sea suya**, con el alcance por vendedor de siempre.
     *
     * Lo que no hay que deshacer: el saldo de la nota de venta **vuelve solo**,
     * porque se calcula y no se guarda.
     */
    public function destroy(Request $request, string $tipo, int $numeroInterno)
    {
        $letra = strtoupper($tipo);
        $doc = $this->documento($letra, $numeroInterno);

        if (! $doc || ! $this->alcanza($request, $doc['documento']['vendedor'])) {
            return response()->json(['message' => 'Ese documento no existe o no es tuyo.'], 404);
        }

        $cab = $doc['documento'];
        $tipoDte = TipoDte::desdeSoftland($letra, $cab['subtipo']);

        if ($tipoDte && $track = (new Emision(Certificado::desdeConfiguracion()))->seguimiento($tipoDte, $cab['folio'])) {
            return response()->json([
                'message' => "Este documento ya viajó al SII (TrackID {$track}): existe para el fisco y "
                    .'no se borra. Lo que salió mal se corrige con una nota de crédito.',
                'track_id' => $track,
            ], 409);
        }

        if (! $this->laEscribioLaApp($letra, $numeroInterno, $cab)) {
            return response()->json([
                'message' => 'Esta factura no la escribió la app, así que desde aquí no se borra. '
                    .'Se borra desde el Softland de escritorio, que es donde se escribió.',
            ], 409);
        }

        try {
            $folio = $this->facturacion->eliminar($letra, $numeroInterno);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'borrado' => true,
            'folio' => $folio,
            'mensaje' => "Borrada. El folio {$folio} vuelve a quedar disponible.",
        ]);
    }

    /**
     * ¿Este documento lo escribió la app?
     *
     * Lo dice la propia fila: al escribirla ponemos `Proceso = 'Venta
     * Softland'`, y el ERP pone lo suyo — «Factura en Línea» en 197 documentos
     * y «Nota de Crédito» en 12. Es la misma marca con la que `dte:pendientes`
     * decide qué le toca mandar.
     *
     * Aquí no sirve el mapa de `client_uuid`, y por dos razones. La primera es
     * que responde a otra pregunta —«¿qué documento escribió *este* envío?»— y
     * la segunda la vimos ensayando: las facturas anteriores a que la app
     * empezara a dejar huella no tienen fila, y son nuestras igual. El mapa
     * protege de reenviar dos veces; la marca dice de quién es la fila.
     */
    private function laEscribioLaApp(string $letra, int $nroInt, array $cab): bool
    {
        $proceso = DB::connection('softland')->table('softland.iw_gsaen')
            ->where('Tipo', $letra)->where('NroInt', $nroInt)->value('Proceso');

        return trim((string) $proceso) === Facturacion::PROCESO;
    }

    /**
     * El papel: la representación impresa del documento electrónico.
     *
     * **No es el documento.** El documento es el XML firmado que aceptó el SII;
     * esto es lo que se le entrega al cliente para que lo lea. De ahí que el
     * timbre salga de `dte_doccab.FirmaDTE` y no se vuelva a calcular: tiene
     * que decir exactamente lo mismo que lo que viajó.
     *
     * Se versiona como la cotización: lo entregado al cliente no se toca, y
     * corregir crea la siguiente versión.
     */
    public function pdf(Request $request, string $tipo, int $numeroInterno)
    {
        $letra = strtoupper($tipo);
        $doc = $this->documento($letra, $numeroInterno);

        if (! $doc || ! $this->alcanza($request, $doc['documento']['vendedor'])) {
            return response()->json(['message' => 'Ese documento no existe o no es tuyo.'], 404);
        }

        $tipoDoc = match ($letra) {
            'F' => TipoDocumento::FACTURA,
            'B' => TipoDocumento::BOLETA,
            'N' => TipoDocumento::NOTA_CREDITO,
            default => null,
        };

        if (! $tipoDoc) {
            return response()->json(['message' => 'Ese tipo de documento no se imprime desde aquí.'], 422);
        }

        try {
            $r = app(EmisionPapel::class)->emitir(
                $tipoDoc,
                (int) $doc['documento']['folio'],
                $this->contextoPapel($doc),
                $this->usuario($request),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($r['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$tipoDoc->archivo((int) $doc['documento']['folio']).'"',
            'X-Version-Documento' => $r['version'],
        ]);
    }

    /**
     * Todo lo que el papel necesita y el documento no trae.
     *
     * Se resuelve aquí de una vez: una plantilla que consulta se dibuja distinto
     * cada vez, y la emisión guarda exactamente esto — si faltara un dato,
     * faltaría también en el archivo histórico.
     */
    private function contextoPapel(array $doc): array
    {
        $conn = DB::connection('softland');
        $t = fn ($v) => trim((string) ($v ?? ''));
        $cab = $doc['documento'];

        $cliente = $this->maestros->uno('clientes', ['CodAux' => $cab['cliente']]) ?? [];

        $nombres = $conn->table('softland.iw_tprod')
            ->whereIn('CodProd', array_column($doc['lineas'], 'producto'))
            ->pluck('DesProd', 'CodProd')
            ->mapWithKeys(fn ($n, $c) => [trim($c) => trim((string) $n)])->all();

        $moneda = $conn->table('softland.cwtmone')
            ->where('CodMon', $cab['moneda'] ?: '01')->first(['SimMon', 'DesMon']);

        return [
            'documento' => $cab + [
                'creado' => $conn->table('softland.iw_gsaen')
                    ->where('Tipo', $cab['tipo'])->where('NroInt', $cab['numero_interno'])
                    ->value('FecHoraCreacion'),
                'descuento' => 0,
            ],
            'lineas' => $doc['lineas'],
            'cliente' => $cliente,
            'nombres' => $nombres,
            'timbre' => $this->timbreDe($cab),
            'referencias' => $this->referenciasDe($cab),
            'moneda_simbolo' => $t($moneda->SimMon ?? '') ?: '$',
            'moneda_nombre' => $t($moneda->DesMon ?? '') ?: 'pesos chilenos',
            // En letras va el nombre de la moneda, no su símbolo: «SON:
            // DOSCIENTOS SESENTA Y DOS MIL … PESOS».
            // En plural, que es como se dice un monto: «… Y NUEVE PESOS». El
            // maestro guarda el singular —«PESO CHILENO»— y escribirlo tal cual
            // dejaba el papel diciendo «NUEVE PESO CHILENO».
            'moneda_palabra' => ($cab['moneda'] ?: '01') === '01'
                ? 'PESOS'
                : mb_strtoupper($t($moneda->DesMon ?? '') ?: 'PESOS', 'UTF-8'),
            'giro_cliente' => $t($conn->table('softland.cwtgiro')
                ->where('GirCod', $cliente['giro'] ?? '')->value('GirDes')),
            'comuna_cliente' => $t($conn->table('softland.cwtcomu')
                ->where('ComCod', $cliente['comuna'] ?? '')->value('ComDes')),
            'ciudad_cliente' => $t($conn->table('softland.cwtciud')
                ->where('CiuCod', $cliente['ciudad'] ?? '')->value('CiuDes')),
            'condicion' => $t($conn->table('softland.cwtconv')
                ->where('CveCod', $cab['condicion'])->value('CveDes')),
            'vendedor' => [
                'codigo' => $cab['vendedor'],
                'nombre' => $t($conn->table('softland.cwtvend')
                    ->where('VenCod', $cab['vendedor'])->value('VenDes')),
            ],
            'iva_pct' => $this->porcentajeIva($cab),
        ];
    }

    /**
     * El timbre que se imprime, sacado de donde quedó guardado el que viajó.
     *
     * Nunca se regenera: el TED lleva dentro la hora en que se timbró y la
     * firma del CAF sobre ella. Uno nuevo sería válido y **distinto**, y un
     * papel que no dice lo mismo que el XML es un papel que no cuadra.
     *
     * Vacío cuando el documento todavía no se ha preparado. El papel lo dice.
     */
    private function timbreDe(array $cab): ?string
    {
        $ted = DB::connection('softland')->table('softland.dte_doccab')
            ->where('Tipo', $cab['tipo'])->where('NroInt', $cab['numero_interno'])
            ->value('FirmaDTE');

        // Devuelto a los bytes que se firmaron. El PDF417 codifica bytes, no
        // letras: con el TED en caracteres, una «ó» ocuparía dos y el timbre
        // impreso dejaría de decir lo mismo que el XML que recibió el SII.
        $ted = Codificacion::desdeLaBase(trim((string) $ted));

        return $ted === '' ? null : CodigoBarras::timbre($ted);
    }

    /**
     * Los documentos de los que éste viene, en palabras.
     *
     * La nota de venta se nombra como la nombra Softland en su papel —«Nota de
     * Pedido»— porque es lo que el cliente lleva años leyendo. Y la referencia
     * al documento que se anula sale de `IW_GSaEn_RefDTE`, que es donde de
     * verdad se dice qué se acredita.
     *
     * Cada renglón lleva **rótulo, folio y fecha**. La fecha estaba fuera y
     * hace falta: quien recibe la factura la cuadra contra un papel suyo, y en
     * una eléctrica hay varias HES con numeración propia por contrato. Va entre
     * paréntesis y sólo si la hay, que es como la escribe el papel de Softland.
     *
     * @return list<string>
     */
    private function referenciasDe(array $cab): array
    {
        $lista = [];

        $refs = DB::connection('softland')->table('softland.IW_GSaEn_RefDTE')
            ->where('Tipo', $cab['tipo'])->where('NroInt', $cab['numero_interno'])
            ->orderBy('LineaRef')->get();

        foreach ($refs as $r) {
            // La glosa es el rótulo que Softland imprime —«Nota de
            // Pedido/Hes/Has»— y el folio va detrás. Cuando no hay glosa se
            // nombra el tipo del SII; y si el código no es ni numérico —el
            // maestro del ERP admite filas escritas a mano— se imprime tal cual,
            // que al menos es lo que eligió quien facturó.
            $codigo = trim((string) $r->CodRefSII);
            $glosa = trim((string) ($r->Glosa ?? ''))
                ?: (ctype_digit($codigo) ? TipoDte::nombreSii((int) $codigo) : $codigo);

            $fecha = substr((string) $r->FechaRef, 0, 10);
            $lista[] = trim($glosa.' '.$r->FolioRef)
                .($fecha === '' ? '' : ' ('.date('d-m-Y', strtotime($fecha)).')');
        }

        // La nota de venta sólo se nombra si no vino ya como referencia del
        // DTE: Softland la escribe ahí con el código 802, y decirla dos veces
        // en el mismo renglón es exactamente lo que parece, un error.
        if ($lista === [] && (int) $cab['nota_venta'] > 0) {
            $lista[] = 'Nota de Pedido '.$cab['nota_venta'];
        }

        return $lista;
    }

    /**
     * El porcentaje de IVA que llevó este documento.
     *
     * Se deduce de sus propios montos y no del maestro: el papel de una factura
     * de hace dos años tiene que imprimir el IVA de entonces, no el de hoy.
     */
    private function porcentajeIva(array $cab): int
    {
        $neto = abs((float) $cab['neto']);
        $iva = abs((float) $cab['iva']);

        return $neto > 0 && $iva > 0 ? (int) round($iva * 100 / $neto) : 19;
    }

    /**
     * Lo que pudo cambiar mientras la factura esperaba en la bandeja.
     *
     * Con la cola del teléfono, entre escribir la factura y emitirla pueden
     * pasar horas, y en ese rato otro la pudo facturar desde el ERP o alguien
     * pudo anular la nota de venta. Reservar el saldo sin red no es posible
     * —ningún sistema puede—, pero emitir a ciegas sí se puede evitar.
     *
     * De ahí la regla: **se emite sola cuando el mundo sigue igual; se pregunta
     * cuando cambió.** Lo que se puede confirmar vuelve con `confirmable`, y la
     * bandeja ofrece «emitir igual»; lo que no —una nota de venta anulada— no
     * tiene salida y se dice así.
     *
     * Facturar de más está permitido y no se toca: es legítimo y pasa. Lo que
     * se pregunta es facturar algo que **ya no queda**, que es distinto.
     */
    private function elMundoCambio(object $nv, bool $confirmado)
    {
        $estado = strtoupper(trim((string) $nv->nvEstado));

        if ($estado === 'N') {
            return response()->json([
                'message' => 'Esa nota de venta está anulada: no se factura.',
            ], 409);
        }

        if ($estado === 'P') {
            return response()->json([
                'message' => 'Esa nota de venta todavía espera aprobación.',
            ], 409);
        }

        if ($confirmado) {
            return null;
        }

        $saldo = $this->saldo->deNotaVenta((int) $nv->NVNumero);
        $queda = array_sum(array_map(fn ($l) => max(0.0, (float) $l['saldo']), $saldo['lineas']));

        if ($saldo['conocible'] && $queda <= 0.0001) {
            return response()->json([
                'message' => 'Esta nota de venta ya está facturada entera. Si la factura que '
                    .'preparaste sigue haciendo falta, confírmala y se emite igual.',
                'confirmable' => true,
            ], 409);
        }

        return null;
    }

    /**
     * Mandarlo al SII ahora mismo, en cuanto queda escrito.
     *
     * **Emitir y enviar son un solo acto.** Un documento escrito y no enviado
     * no existe para el fisco, y esperar a que alguien se acuerde de mandarlo
     * es cómo una factura del día 30 termina emitida el 2.
     *
     * Lo que **no** hace es tumbar la petición si el SII no contesta: el
     * documento ya está escrito y el folio ya se gastó, así que decir «falló»
     * a secas sería mentir. Se devuelve lo que pasó, el teléfono lo pinta en
     * rojo y la tarea `dte:pendientes` lo reintenta sola.
     *
     * @return array{enviado: bool, track_id?: string, glosa?: string, error?: string}
     */
    private function mandarAlSii(string $letra, int $nroInt): array
    {
        // En manual el documento queda escrito y esperando, y eso **no es una
        // avería**: es lo que la empresa pidió. Se dice con esas palabras para
        // que la pantalla no lo pinte de rojo.
        if (! $this->reglas->envioAutomatico()) {
            return ['enviado' => false, 'automatico' => false];
        }

        try {
            $cert = Certificado::desdeConfiguracion();
            $emision = new Emision($cert);
            $doc = $this->documento($letra, $nroInt)['documento'];
            $tipoDte = TipoDte::desdeSoftland($letra, $doc['subtipo']);

            // Reenvío del mismo `client_uuid`: el documento ya existía y ya
            // había viajado. Decir «no se pudo enviar» aquí sería asustar por
            // haber hecho las cosas bien.
            if ($tipoDte && $ya = $emision->seguimiento($tipoDte, (int) $doc['folio'])) {
                return ['enviado' => true, 'automatico' => true, 'track_id' => $ya, 'glosa' => 'Ya estaba enviada.'];
            }

            $r = $emision->emitir($letra, $nroInt);

            return [
                'enviado' => true,
                'automatico' => true,
                'track_id' => $r['trackId'],
                'glosa' => $r['glosa'],
                'aviso' => $cert->avisaVencimiento(),
            ];
        } catch (Throwable $e) {
            return ['enviado' => false, 'automatico' => true, 'error' => $e->getMessage()];
        }
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

    /**
     * La orden de compra de un documento de venta, si la tiene.
     *
     * `numOC` es NOT NULL con cero por defecto en Softland: el cero ahí
     * significa «sin orden de compra», no una OC número cero. Son 43 de 804
     * notas de venta las que traen una de verdad.
     */
    private static function ocDe(object $doc): ?string
    {
        $oc = trim((string) ($doc->NumOC ?? $doc->numOC ?? ''));

        return ($oc === '' || $oc === '0') ? null : $oc;
    }

    /**
     * De qué papeles viene esta factura, para el `<Referencia>` del DTE.
     *
     * Son dos cosas que se suman, y conviene no confundirlas:
     *
     *  - **las que deduce el servidor** de la propia venta, que salen solas y
     *    no hay que acordarse de ellas;
     *  - **las que escribe quien factura**, que son papeles que el sistema no
     *    tiene forma de conocer.
     *
     * ## Las que salen solas
     *
     *  - **801, Orden de Compra**, con la que dio el cliente. Es la que de
     *    verdad le sirve a quien recibe la factura para cuadrarla contra lo que
     *    encargó.
     *  - **802, Nota de Pedido**, con el número de la nota de venta. Es el
     *    enlace hacia adentro.
     *
     * El ERP las escribe en ese orden —la 232 lleva la 801 en la línea 1 y la
     * 802 en la 2— y con la fecha del documento referido, no con la de hoy: las
     * seis referencias 801 de INNOVAGES llevan las seis la fecha de su nota de
     * venta. Las dos se pueden apagar por empresa: poner el número de la nota de
     * venta en el DTE es una costumbre de INNOVAGES —188 documentos—, no una
     * regla del SII.
     *
     * ## Las que se escriben
     *
     * Hay clientes que **no pagan una factura que no nombre su documento**: las
     * eléctricas y las forestales piden la HES, y también aparecen contratos y
     * resoluciones. Eso no se deduce de nada — lo sabe quien factura y nadie
     * más —, así que se escribe, y va detrás de las automáticas.
     *
     * Tres reglas, y las tres son para que un folio no se gaste en un documento
     * que el SII va a rechazar:
     *
     *  - **el tipo tiene que existir en `DTE_SiiTDocRef`.** Va tal cual al
     *    `<TpoDocRef>` del XML, así que un código inventado es un rechazo
     *    después de haber gastado el folio. El maestro es del ERP y la app no lo
     *    interpreta —lee y muestra lo que declare—, pero no acepta lo que no
     *    declara.
     *  - **la fecha, si no viene, es la del documento.** El `<FchRef>` no es
     *    opcional en el DTE, y dejarlo vacío es el mismo rechazo.
     *  - **una referencia repetida es una.** Mismo tipo y mismo folio escritos
     *    dos veces —o escritos a mano habiendo salido ya solos, que es justo lo
     *    que pasa con la orden de compra— es un renglón, no dos.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function referenciasDelDocumento(array $data, ?object $nv): array
    {
        $refs = [];
        $fecha = $nv
            ? substr((string) $nv->nvFem, 0, 10)
            : substr((string) ($data['fecha'] ?? date('Y-m-d')), 0, 10);

        // La que viene tecleada manda sobre la de la nota de venta: la OC puede
        // haber llegado después de escribir la venta, y entonces se corrige al
        // facturar sin tener que volver atrás.
        $oc = trim((string) ($data['oc'] ?? '')) ?: ($nv ? self::ocDe($nv) : null);

        if ($oc && $this->reglas->referenciaOrdenCompra()) {
            $refs[] = ['sii' => 801, 'folio' => $oc, 'fecha' => $fecha];
        }

        if ($nv && $this->reglas->referenciaNotaVenta()) {
            $refs[] = ['sii' => 802, 'folio' => (string) $nv->NVNumero, 'fecha' => $fecha];
        }

        foreach ($data['referencias'] ?? [] as $r) {
            $tipo = trim((string) ($r['tipo_sii'] ?? ''));
            $folio = trim((string) ($r['folio'] ?? ''));

            if ($tipo === '' || $folio === '') {
                continue;
            }

            if (! self::tipoDeReferenciaExiste($tipo)) {
                throw new RuntimeException(
                    "El tipo de documento de referencia «{$tipo}» no está en el maestro del ERP. ".
                    'Elige uno de la lista: el SII rechaza el documento si no lo reconoce.'
                );
            }

            $refs[] = [
                'sii' => $tipo,
                'folio' => $folio,
                'fecha' => substr((string) ($r['fecha'] ?? $fecha), 0, 10),
                // Vacía a propósito cuando no se escribe: `Facturacion` la
                // rellena con el rótulo del maestro, que es el que el cliente
                // lleva años leyendo en el papel de Softland.
                'glosa' => trim((string) ($r['glosa'] ?? '')) ?: null,
            ];
        }

        return self::sinRepetidas($refs);
    }

    /**
     * Si el ERP declara este tipo de documento de referencia.
     *
     * Contra el maestro y no contra una lista escrita aquí: los códigos del SII
     * son suyos, y una lista nuestra se quedaría vieja el día que el SII añada
     * uno. Se compara sin espacios porque `CodRefSII` es `varchar(3)` y hay
     * instalaciones con el valor rellenado.
     */
    private static function tipoDeReferenciaExiste(string $tipo): bool
    {
        return DB::connection('softland')->table('softland.DTE_SiiTDocRef')
            ->whereRaw('LTRIM(RTRIM(CodRefSII)) = ?', [$tipo])->exists();
    }

    /**
     * Una referencia por tipo y folio, en el orden en que aparecieron.
     *
     * Hace falta porque las automáticas y las escritas a mano pueden nombrar lo
     * mismo: teclear la orden de compra como referencia libre habiendo salido ya
     * sola por la 801 daría dos renglones iguales en el DTE. Gana la primera,
     * que es la automática, y con ella la fecha deducida de la venta.
     *
     * @param  list<array<string, mixed>>  $refs
     * @return list<array<string, mixed>>
     */
    private static function sinRepetidas(array $refs): array
    {
        $vistas = [];
        $limpias = [];

        foreach ($refs as $r) {
            $clave = trim((string) $r['sii']).'|'.strtoupper(trim((string) $r['folio']));

            if (isset($vistas[$clave])) {
                continue;
            }

            $vistas[$clave] = true;
            $limpias[] = $r;
        }

        return $limpias;
    }

    private function notaVenta(int $numero)
    {
        return DB::connection('softland')->table('softland.nw_nventa')
            ->where('NVNumero', $numero)->first();
    }

}
