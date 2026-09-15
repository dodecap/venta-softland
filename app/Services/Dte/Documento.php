<?php

namespace App\Services\Dte;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El XML del documento tributario electrónico, a partir del documento ya
 * escrito en inventario y facturación.
 *
 * El orden importa y no es casual: primero se escribe el documento en
 * `iw_gsaen` —ahí se decide el folio—, y **después** se dibuja su XML. Nunca al
 * revés. El XML es la representación de algo que ya existe.
 *
 * ## Se concatena, no se serializa
 *
 * Misma razón que en `Timbre`, y aquí pesa el doble: el `<Documento>` entero se
 * firma, y la firma cubre su forma canónica. Un serializador que reordene
 * atributos o cambie el espaciado produce otro documento. Además el `<TED>` ya
 * viene firmado desde `Timbre` y volver a pasarlo por un parser lo rompería.
 *
 * ## Lo que cambia entre tipos
 *
 * La factura y la boleta no comparten estructura:
 *
 *   - la factura identifica al receptor con giro y dirección; la boleta admite
 *     el consumidor final, sin nada de eso;
 *   - la factura desglosa `MntNeto`, `TasaIVA` e `IVA`; **la boleta no
 *     desglosa**: va `MntTotal` bruto y se acabó;
 *   - el emisor se llama `RznSoc` en la factura y `RznSocEmisor` en la boleta.
 *     Es el mismo dato con dos nombres, y el SII rechaza el que no toca.
 */
class Documento
{
    private const VERSION = '1.0';

    /**
     * Cómo se separan los elementos: un salto de línea de Windows entre cada uno.
     *
     * No es cosmética. La firma cubre la **forma canónica** del documento, y la
     * canonicalización conserva los espacios entre elementos: conserva el texto,
     * y un salto de línea es texto. Escribir el mismo documento todo seguido
     * produce un resumen distinto.
     */
    private const SALTO = "\r\n";

    public function __construct(private readonly ?string $base = null) {}

    /**
     * Arma el `<DTE>` completo y firmado de un documento de `iw_gsaen`.
     *
     * @param  string|null  $sello  marca de tiempo del timbre; solo para poder
     *                              reconstruir documentos históricos
     */
    public function armar(string $tipoSoftland, int $nroInt, ?Certificado $cert = null, ?string $sello = null): string
    {
        $cab = $this->fila('iw_gsaen', ['Tipo' => $tipoSoftland, 'NroInt' => $nroInt])
            ?? throw new RuntimeException("No está el documento {$tipoSoftland}/{$nroInt} en iw_gsaen.");

        $tipo = TipoDte::desdeSoftland($cab->Tipo, $cab->SubTipoDocto)
            ?? throw new RuntimeException("El documento {$cab->Tipo}/{$cab->SubTipoDocto} no es electrónico.");

        $emisor = $this->fila('soempre', [])
            ?? throw new RuntimeException('No están los datos de la empresa en soempre.');

        $receptor = $this->fila('cwtauxi', ['CodAux' => $cab->CodAux]);
        $lineas = $this->lineas($tipoSoftland, $nroInt);
        $folio = (int) $cab->Folio;

        $documento = $this->documento($tipo, $cab, $emisor, $receptor, $lineas, $folio, $sello);

        $firma = $cert
            ? (new FirmaXml($cert))->firmar($documento, $this->identificador($tipo, $folio))
            : '';

        return '<?xml version="1.0" encoding="ISO-8859-1"?>'
            .'<DTE version="'.self::VERSION.'">'.$documento.$firma.'</DTE>';
    }

    /** El `<Documento>` sin firmar. Es lo que se contrasta contra lo emitido. */
    public function documentoDe(string $tipoSoftland, int $nroInt, ?string $sello = null): string
    {
        $cab = $this->fila('iw_gsaen', ['Tipo' => $tipoSoftland, 'NroInt' => $nroInt])
            ?? throw new RuntimeException("No está el documento {$tipoSoftland}/{$nroInt}.");

        $tipo = TipoDte::desdeSoftland($cab->Tipo, $cab->SubTipoDocto)
            ?? throw new RuntimeException('No es un documento electrónico.');

        return $this->documento(
            $tipo,
            $cab,
            $this->fila('soempre', []),
            $this->fila('cwtauxi', ['CodAux' => $cab->CodAux]),
            $this->lineas($tipoSoftland, $nroInt),
            (int) $cab->Folio,
            $sello,
        );
    }

    private function documento(TipoDte $tipo, object $cab, object $emisor, ?object $receptor, array $lineas, int $folio, ?string $sello): string
    {
        // ## Lo que este generador todavía no sabe escribir
        //
        // Un descuento o recargo **de pie** va en el DTE en su propio bloque,
        // `<DscRcgGlobal>`. Las 209 facturas y notas de crédito de INNOVAGES no
        // usan ninguno —la columna existe y está en cero en todas—, así que el
        // bloque no está escrito.
        //
        // Se falla en vez de ignorarlo. Un documento cuyas líneas suman una cosa
        // y cuyo total dice otra es lo que el SII rechaza, y el folio ya se
        // habría gastado. Mejor no llegar ahí.
        if (round(abs((float) ($cab->TotalDesc ?? 0))) > 0 || round(abs((float) ($cab->Recargo ?? 0))) > 0) {
            throw new RuntimeException(
                'El documento lleva descuento o recargo de pie, y el bloque <DscRcgGlobal> del DTE '
                .'todavía no está escrito: no hay ningún caso real en INNOVAGES contra el que comprobarlo.'
            );
        }

        $ted = $this->timbre($tipo, $cab, $receptor, $lineas, $folio, $sello);

        // El comentario de versión lo escribe Softland y lo escribimos también.
        // La canonicalización lo descarta —los comentarios no se firman— pero
        // **los dos saltos de línea que lo rodean sí cuentan**. Sin él, el
        // documento es el mismo y el resumen no.
        $sello ??= date('Y-m-d\TH:i:s');

        return '<Documento ID="'.$this->identificador($tipo, $folio).'">'.self::SALTO
            .'<!-- '.$this->marca().'-->'.self::SALTO
            .$this->encabezado($tipo, $cab, $emisor, $receptor, $folio)
            .$this->detalle($tipo, $lineas)
            .$this->referencias($cab)
            .$ted
            .'<TmstFirma>'.$sello.'</TmstFirma>'
            .'</Documento>';
    }

    /** De dónde salió el documento. Va en el comentario, que no se firma. */
    private function marca(): string
    {
        return 'Venta Softland '.config('app.version', '');
    }

    /** `D033F234`: tipo y folio. Es lo que la firma referencia con `URI="#…"`. */
    private function identificador(TipoDte $tipo, int $folio): string
    {
        return sprintf('D%03dF%d', $tipo->value, $folio);
    }

    private function encabezado(TipoDte $tipo, object $cab, object $emisor, ?object $receptor, int $folio): string
    {
        $x = '<Encabezado>'.self::SALTO.'<IdDoc>'.self::SALTO
            .$this->e('TipoDTE', $tipo->value)
            .$this->e('Folio', $folio)
            .$this->e('FchEmis', substr((string) $cab->Fecha, 0, 10));

        // La diferencia entre los dos tipos es más chica de lo que parece, y
        // conviene tenerla clara: la **boleta** declara `IndServicio` —si la
        // venta es de servicios, de bienes o mixta— y la **factura** declara
        // `TpoTranVenta`, el tipo de transacción, que sale de
        // `iw_gsaen.TipoTrans` y no de `TipoServicioSII`. La forma de pago y el
        // vencimiento van en los dos.
        if ($tipo->porApiRest()) {
            // La boleta se paga al contado y se acabó: ni forma de pago ni
            // glosa de condición. Vencimiento sí, aunque sea el mismo día.
            $x .= $this->e('IndServicio', (int) ($cab->TipoServicioSII ?: 3));
        } else {
            $x .= $this->e('TpoTranVenta', (int) ($cab->TipoTrans ?: 1))
                .$this->e('FmaPago', (int) ($cab->FmaPago ?: 2))
                .$this->e('TermPagoGlosa', $this->condicionDeVenta($cab->CondPago), omitirVacio: true);
        }

        $x .= $this->e('FchVenc', substr((string) ($cab->FechaVenc ?: $cab->Fecha), 0, 10));

        $x .= '</IdDoc>'.self::SALTO.'<Emisor>'.self::SALTO
            .$this->e('RUTEmisor', $this->rut((string) $emisor->RutEmisor))
            .$this->e($tipo->porApiRest() ? 'RznSocEmisor' : 'RznSoc', $emisor->NomB)
            .$this->e($tipo->porApiRest() ? 'GiroEmisor' : 'GiroEmis', $emisor->Giro);

        if (! $tipo->porApiRest()) {
            // Una empresa puede tener varios giros ante el SII, y van todos:
            // `soempre` guarda hasta cuatro. INNOVAGES declara dos, y con uno
            // solo el documento ya no es el mismo.
            foreach (['ACTECO01', 'ACTECO02', 'ACTECO03', 'ACTECO04'] as $col) {
                $x .= $this->e('Acteco', $emisor->{$col} ?? null, omitirVacio: true);
            }
        }

        $x .= $this->e('DirOrigen', $emisor->Dire)
            .$this->e('CmnaOrigen', $emisor->Comu)
            .$this->e('CiudadOrigen', $emisor->Ciud)
            // El vendedor viaja en el DTE. Es el mismo `VenCod` sin el que un
            // documento no existe para las ventanas de búsqueda de Softland.
            .$this->e('CdgVendedor', $cab->CodVendedor, omitirVacio: true)
            .'</Emisor>'.self::SALTO.'<Receptor>'.self::SALTO
            .$this->e('RUTRecep', $this->rut((string) ($receptor->RutAux ?? $tipo->receptorAnonimo() ?? '')))
            // El código con que el emisor conoce al cliente: en Softland,
            // `CodAux`. Sirve para que el receptor concilie contra su propia
            // ficha sin depender del RUT.
            .$this->e('CdgIntRecep', $receptor->CodAux ?? null, omitirVacio: true)
            .$this->e('RznSocRecep', $receptor->NomAux ?? $tipo->razonSocialAnonima());

        if (! $tipo->porApiRest()) {
            // El giro del cliente es un **código** en `cwtauxi.GirAux`, no un
            // texto: hay que resolverlo contra `cwtgiro`. Y va recortado a 40
            // caracteres, que es el máximo del SII.
            $x .= $this->e('GiroRecep', mb_substr($this->giro($receptor->GirAux ?? null), 0, 40))
                // El correo **para DTE** del cliente, que no es su correo
                // comercial: `cwtauxi` guarda los dos y el intercambio va por
                // este. Mandarlo al otro es que el DTE no llegue a quien lo
                // procesa.
                .$this->e('CorreoRecep', $receptor->eMailDTE ?? null, omitirVacio: true)
                .$this->e('DirRecep', $receptor->DirAux ?? null)
                // Comuna y ciudad también son códigos en `cwtauxi`, no nombres:
                // «13123» es Providencia y «STGO» es Santiago. El SII quiere el
                // nombre.
                .$this->e('CmnaRecep', $this->nombreDe('cwtcomu', 'ComCod', 'ComDes', $receptor->ComAux ?? null))
                .$this->e('CiudadRecep', $this->nombreDe('cwtciud', 'CiuCod', 'CiuDes', $receptor->CiuAux ?? null), omitirVacio: true)
                // El código postal sale del maestro de ciudades, no del cliente.
                .$this->e('CiudadPostal', $this->nombreDe('cwtciud', 'CiuCod', 'CodPostal', $receptor->CiuAux ?? null), omitirVacio: true);
        }

        $x .= '</Receptor>'.self::SALTO.'<Totales>'.self::SALTO;

        if ($tipo->montoBruto()) {
            // La boleta no desglosa: el IVA va dentro del total y punto.
            $x .= $this->e('MntTotal', $this->entero($cab->Total));
        } else {
            // Y una exenta no desglosa lo que no tiene: sin parte afecta no van
            // `MntNeto`, ni `TasaIVA`, ni `IVA`. Escribirlos en cero es un
            // documento que dice algo distinto —que hubo base imponible y el
            // impuesto salió cero— y el SII lo trata como tal.
            $afecto = (int) round(abs((float) $cab->NetoAfecto));

            $x .= $this->e('MntNeto', $afecto, omitirVacio: true)
                .$this->e('MntExe', $this->entero($cab->NetoExento), omitirVacio: true);

            if ($afecto > 0) {
                $x .= $this->e('TasaIVA', '19')
                    .$this->e('IVA', $this->entero($cab->IVA));
            }

            $x .= $this->e('MntTotal', $this->entero($cab->Total));
        }

        return $x.'</Totales>'.self::SALTO.'</Encabezado>'.self::SALTO;
    }

    /**
     * El detalle.
     *
     * `IndExe` marca la línea exenta, y aquí se decide **por el tipo de
     * documento**, no por el producto. Se intentó por `iw_tprod.Impuesto`, que
     * es lo que parecería: en INNOVAGES está en cero para productos que sí se
     * facturan con IVA, así que ese campo no dice lo que aparenta. Con los datos
     * que hay, lo que se puede afirmar es que una factura exenta lleva líneas
     * exentas y una afecta no.
     *
     * Un documento con líneas de las dos clases no se puede armar todavía. No
     * hay ninguno entre los 209 de INNOVAGES ni entre los de NETDOMAIN, así que
     * tampoco hay contra qué comprobarlo: inventarlo sería adivinar.
     */
    private function detalle(TipoDte $tipo, array $lineas): string
    {
        $x = '';

        foreach ($lineas as $i => $l) {
            $x .= '<Detalle>'.self::SALTO
                .$this->e('NroLinDet', $i + 1)
                // El código interno, y el de barras si el producto lo tiene.
                // Son dos `<CdgItem>` distintos, no uno con dos valores.
                .'<CdgItem>'.self::SALTO
                .$this->e('TpoCodigo', 'INT1')
                .$this->e('VlrCodigo', trim((string) $l->CodProd))
                .'</CdgItem>'.self::SALTO
                .$this->codigoDeBarras($l)
                // Una línea exenta lo dice: si no, el SII entiende que la venta
                // es afecta y el documento no cuadra con sus totales.
                .($tipo->afecto() ? '' : $this->e('IndExe', '1'))
                // `NmbItem` es el nombre del **producto**, del maestro; la
                // glosa que escribió quien facturó va en `DscItem`. Son campos
                // distintos y el SII los trata distinto: el nombre es corto y de
                // catálogo, la descripción es libre.
                .$this->e('NmbItem', mb_substr(trim((string) ($l->nombreProducto ?? '')), 0, 80))
                .$this->e('DscItem', $this->enUnaLinea($l->DetProd), omitirVacio: true)
                .$this->e('QtyItem', $this->numero($l->CantFacturada))
                .$this->e('UnmdItem', $l->CodUMed, omitirVacio: true)
                .$this->e('PrcItem', $this->numero($l->PreUniMB))
                // El descuento de línea, cuando lo hay, va desglosado: el SII
                // quiere ver el porcentaje y el monto, no solo el total ya
                // rebajado.
                // Redondeado a dos decimales. Softland guarda el porcentaje con
                // la precisión que salga de dividir —9,999982 %— y en el DTE
                // escribe «10». Es lo mismo y se lee mejor.
                .$this->e('DescuentoPct', $this->porcentaje($l->PorcDescMov01), omitirVacio: true)
                .$this->e('DescuentoMonto', $this->entero($l->DescMov01), omitirVacio: true)
                .$this->e('MontoItem', $this->entero($l->TotLinea))
                .'</Detalle>'.self::SALTO;
        }

        return $x;
    }

    /** El `<CdgItem>` del código de barras, si el producto tiene uno. */
    private function codigoDeBarras(object $linea): string
    {
        $barra = trim((string) ($linea->CodBarra ?? ''));

        if ($barra === '') {
            return '';
        }

        return '<CdgItem>'.self::SALTO
            .$this->e('TpoCodigo', 'EAN13')
            .$this->e('VlrCodigo', $barra)
            .'</CdgItem>'.self::SALTO;
    }

    /**
     * Las referencias a otros documentos.
     *
     * Una nota de crédito sin esto no es una nota de crédito: el SII exige saber
     * qué documento corrige. Sale de `IW_GSaEn_RefDTE`, con el código del SII.
     */
    private function referencias(object $cab): string
    {
        $refs = DB::connection('softland')->table($this->califica('IW_GSaEn_RefDTE'))
            ->where('Tipo', $cab->Tipo)->where('NroInt', $cab->NroInt)
            ->orderBy('LineaRef')->get();

        // Una devolución **anula** el documento que referencia, y el SII quiere
        // ese motivo escrito: código 1 y su glosa. No está en ninguna columna —
        // las 12 notas de crédito de INNOVAGES traen `CodRef` nulo—: Softland lo
        // deduce de que el documento sea devolución, y aquí se deduce igual.
        $anula = (int) ($cab->esDevolucion ?? 0) !== 0;

        $x = '';

        foreach ($refs as $i => $r) {
            $x .= '<Referencia>'.self::SALTO
                .$this->e('NroLinRef', $i + 1)
                .$this->e('TpoDocRef', trim((string) $r->CodRefSII))
                // El folio referenciado es **texto**, no un número: hay órdenes
                // de compra como «272-OC00008216» o «U36401». Convertirlo a
                // entero daba 272 y 0, que es peor que no ponerlo.
                .$this->e('FolioRef', trim((string) $r->FolioRef))
                .$this->e('FchRef', substr((string) $r->FechaRef, 0, 10))
                // El motivo de la referencia (1 anula, 2 corrige texto, 3
                // corrige montos) y su glosa. Ojo: la glosa que el SII llama
                // `RazonRef` vive en la columna **`Glosa`** de Softland, no en
                // la que se llama `RazonRef` —que está vacía en las 209—.
                .$this->e('CodRef', $r->CodRef ?: ($anula ? '1' : ''), omitirVacio: true)
                .$this->e('RazonRef', $r->Glosa ?: ($r->RazonRef ?: ($anula ? 'Anula Documento' : '')), omitirVacio: true)
                .'</Referencia>'.self::SALTO;
        }

        return $x;
    }

    private function timbre(TipoDte $tipo, object $cab, ?object $receptor, array $lineas, int $folio, ?string $sello): string
    {
        $primera = $lineas[0] ?? null;

        return (new Timbre(Caf::paraFolio($tipo, $folio, $this->base)))->armar(
            rutEmisor: (string) $this->fila('soempre', [])->RutEmisor,
            folio: $folio,
            fecha: substr((string) $cab->Fecha, 0, 10),
            rutReceptor: (string) ($receptor->RutAux ?? $tipo->receptorAnonimo() ?? ''),
            razonSocial: (string) ($receptor->NomAux ?? $tipo->razonSocialAnonima() ?? ''),
            monto: (int) round((float) $cab->Total),
            // `IT1` es el mismo texto que `NmbItem`: el nombre del producto, no
            // la glosa de la línea. Si no coinciden, el timbre sella un nombre y
            // el papel muestra otro.
            primerItem: $primera ? trim((string) ($primera->nombreProducto ?? '')) : '',
            sello: $sello,
        );
    }

    // ------------------------------------------------------------- utilidades

    /** Un elemento con su texto escapado y en ISO-8859-1. */
    private function e(string $nombre, mixed $valor, bool $omitirVacio = false): string
    {
        return $this->crudo($nombre, $valor, $omitirVacio);
    }

    /** El elemento, ya con su salto de línea detrás. */
    private function crudo(string $nombre, mixed $valor, bool $omitirVacio): string
    {
        $v = is_string($valor) ? rtrim($valor) : (string) $valor;

        if ($omitirVacio && ($v === '' || $v === '0')) {
            return '';
        }

        $v = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $v);

        if (mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'ISO-8859-1', 'UTF-8');
        }

        return "<{$nombre}>{$v}</{$nombre}>".self::SALTO;
    }

    /** El giro, resuelto desde su código. */
    private function giro(?string $codigo): string
    {
        return $this->nombreDe('cwtgiro', 'GirCod', 'GirDes', $codigo);
    }

    /**
     * El nombre que hay detrás de un código de maestro.
     *
     * `cwtauxi` guarda giro, comuna y ciudad **como códigos**. El DTE los quiere
     * escritos. Es la misma traducción que hace `catalogos.js` en el teléfono,
     * pero del lado del servidor y contra la base, no contra IndexedDB.
     */
    private function nombreDe(string $tabla, string $claveCol, string $nombreCol, ?string $codigo): string
    {
        $codigo = trim((string) $codigo);

        if ($codigo === '') {
            return '';
        }

        return trim((string) ($this->fila($tabla, [$claveCol => $codigo])->{$nombreCol} ?? ''));
    }

    /** Cómo se llama la condición de venta: «CONTADO», «30 DÍAS»… */
    private function condicionDeVenta(?string $codigo): string
    {
        $codigo = trim((string) $codigo);

        if ($codigo === '') {
            return '';
        }

        return trim((string) ($this->fila('cwtconv', ['CveCod' => $codigo])->CveDes ?? ''));
    }

    /** El SII no quiere puntos en el RUT, y la K va en mayúscula. */
    private function rut(string $rut): string
    {
        return strtoupper(str_replace(['.', ' '], '', trim($rut)));
    }

    /** Un monto: entero, sin signo y sin separadores. */
    private function entero(mixed $v): string
    {
        return (string) (int) round(abs((float) $v));
    }

    /**
     * Una cantidad o un precio.
     *
     * Sin ceros de relleno a la derecha, y **sin el cero de la izquierda**: una
     * décima se escribe `.1`, no `0.1`. Es la forma que usa Softland, y los dos
     * son válidos para el SII; se escribe igual que el ERP para que los
     * documentos sean el mismo y no dos maneras de decir lo mismo.
     */
    private function numero(mixed $v): string
    {
        $n = abs((float) $v);
        $texto = rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.') ?: '0';

        return str_starts_with($texto, '0.') ? substr($texto, 1) : $texto;
    }

    /** Un porcentaje, redondeado a dos decimales y sin ceros de relleno. */
    private function porcentaje(mixed $v): string
    {
        return $this->numero(round((float) $v, 2));
    }

    /**
     * Una glosa de varias líneas, puesta en una sola.
     *
     * Los saltos de línea de `DetProd` se vuelven espacios: el DTE no los
     * admite ahí. Softland hace lo mismo, y conviene que coincida — si no, el
     * papel que ve el cliente diría una cosa y el XML otra.
     */
    private function enUnaLinea(?string $v): string
    {
        return trim(preg_replace('/\r\n|\r|\n/', ' ', (string) $v) ?? '');
    }

    private function lineas(string $tipo, int $nroInt): array
    {
        return DB::connection('softland')->table($this->califica('iw_gmovi').' AS m')
            ->leftJoin($this->califica('iw_tprod').' AS p', 'p.CodProd', '=', 'm.CodProd')
            ->where('m.Tipo', $tipo)->where('m.NroInt', $nroInt)
            ->orderBy('m.Linea')
            ->select('m.*', 'p.DesProd AS nombreProducto', 'p.CodBarra')
            ->get()->all();
    }

    private function fila(string $tabla, array $donde): ?object
    {
        $q = DB::connection('softland')->table($this->califica($tabla));

        foreach ($donde as $col => $valor) {
            $q->where($col, $valor);
        }

        return $q->first();
    }

    private function califica(string $objeto): string
    {
        return $this->base ? "{$this->base}.softland.{$objeto}" : "softland.{$objeto}";
    }
}
