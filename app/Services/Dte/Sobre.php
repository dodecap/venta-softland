<?php

namespace App\Services\Dte;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El sobre `<EnvioDTE>`: lo que viaja al SII.
 *
 * Al SII no se le manda un documento, se le manda un **envío** que contiene uno
 * o varios documentos y una carátula que dice quién envía, a quién, bajo qué
 * resolución y cuántos documentos de cada tipo van dentro.
 *
 * ## Tres RUT distintos, y los tres importan
 *
 *   - `RutEmisor` — la empresa que factura (77828631-9).
 *   - `RutEnvia` — la **persona** cuyo certificado firma, que tiene que estar
 *     autorizada ante el SII para ese emisor (17421371-2). Confundirlo con el
 *     anterior es el rechazo más típico y el mensaje del SII no ayuda.
 *   - `RutReceptor` — el SII, siempre `60803000-K`. No es el cliente: el cliente
 *     va dentro del documento.
 *
 * ## Dos firmas, y ninguna reemplaza a la otra
 *
 * Cada `<DTE>` viene ya firmado desde `Documento`, y el `<SetDTE>` se firma
 * **otra vez** aquí, entero. La primera dice «este documento es este»; la
 * segunda, «y este envío lo mando yo». El SII comprueba las dos.
 *
 * ## Los espacios de la carátula no son cosmética
 *
 * La firma del sobre cubre la forma canónica del `<SetDTE>`, y la
 * canonicalización conserva el texto entre elementos. La carátula se escribe
 * con la misma sangría que escribe Softland —cuatro espacios, sin saltos de
 * línea— porque así el resumen de un sobre regenerado sale idéntico al del que
 * el SII ya aceptó, que es la única forma que hay de comprobar esto sin enviar.
 */
class Sobre
{
    /** El SII como receptor del envío. Es el mismo para todo Chile. */
    public const RUT_SII = '60803000-K';

    /**
     * Lo que el `<SetDTE>` hereda de `<EnvioDTE>`, y que su firma **sí** lleva.
     *
     * Sin esto la firma del sobre no valida y el SII rechaza el envío entero,
     * con los documentos dentro. Es distinto del documento, que se firma suelto:
     * está explicado en `FirmaXml`.
     */
    private const AMBITO = [
        '' => 'http://www.sii.cl/SiiDte',
        'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
    ];

    private const VERSION = '1.0';

    public function __construct(
        private readonly Certificado $cert,
        private readonly ?string $base = null,
    ) {}

    /**
     * Arma y firma el sobre de uno o varios documentos ya escritos en `iw_gsaen`.
     *
     * @param  list<array{tipo:string, nroInt:int, sello?:string|null, xml?:string|null}>  $documentos
     *         cada documento de `iw_gsaen`; `sello` es la marca de tiempo de su
     *         timbre, y solo hace falta para reconstruir documentos históricos.
     *         `xml` permite meter un `<DTE>` ya hecho en vez de generarlo: es lo
     *         que deja comprobar **el sobre solo**, poniéndole dentro el mismo
     *         documento que viajó al SII. Sin eso, cualquier diferencia del
     *         documento se confundiría con una del sobre.
     * @param  string|null  $sello  marca de tiempo del envío; solo para poder
     *                              reconstruir sobres históricos y compararlos
     * @return array{xml:string, id:string, resumen:string, folios:list<int>}
     */
    public function armar(array $documentos, ?string $sello = null): array
    {
        if ($documentos === []) {
            throw new RuntimeException('Un sobre sin documentos no se envía.');
        }

        $emisor = $this->fila('soempre')
            ?? throw new RuntimeException('No están los datos de la empresa en soempre.');

        $rutEmisor = $this->rut((string) $emisor->RutEmisor);
        $generador = new Documento($this->base);

        $cuerpo = '';
        $porTipo = [];
        $folios = [];
        $identificador = null;

        foreach ($documentos as $doc) {
            $tipoSoftland = (string) $doc['tipo'];
            $nroInt = (int) $doc['nroInt'];
            $cab = $this->cabecera($tipoSoftland, $nroInt);
            $tipo = TipoDte::desdeSoftland($cab->Tipo, $cab->SubTipoDocto)
                ?? throw new RuntimeException("El documento {$tipoSoftland}/{$nroInt} no es electrónico.");

            // El `<DTE>` va sin su propia declaración de XML: dentro del sobre
            // solo puede haber una, la del sobre.
            $xml = $doc['xml'] ?? $generador->armar($tipoSoftland, $nroInt, $this->cert, $doc['sello'] ?? null);
            $cuerpo .= substr($xml, strpos($xml, '<DTE ') ?: 0);

            $porTipo[$tipo->value] = ($porTipo[$tipo->value] ?? 0) + 1;
            $folios[] = (int) $cab->Folio;
            $identificador ??= $this->identificador($rutEmisor, $tipo, (int) $cab->Folio);
        }

        $sello ??= date('Y-m-d\TH:i:s');
        $setDte = '<SetDTE ID="'.$identificador.'">'
            .$this->caratula($rutEmisor, $emisor, $porTipo, $sello)
            .$cuerpo
            .'</SetDTE>';

        $firma = new FirmaXml($this->cert, self::AMBITO);

        return [
            'xml' => '<?xml version="1.0" encoding="ISO-8859-1"?>'."\r\n"
                .'<EnvioDTE xmlns="http://www.sii.cl/SiiDte" '
                .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
                .'xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioDTE_v10.xsd" '
                .'version="'.self::VERSION.'"> '
                .$setDte
                .$firma->firmar($setDte, $identificador)
                .'</EnvioDTE>',
            'id' => $identificador,
            'resumen' => $firma->resumen($setDte),
            'folios' => $folios,
        ];
    }

    /**
     * El identificador del envío, que es el mismo que Softland guarda en
     * `dte_doccab.IDSetDTESII`: `SS` + RUT + `SS` + tipo + `F` + folio a diez
     * dígitos. Cuando el sobre lleva varios documentos, nombra al primero.
     */
    public function identificador(string $rutEmisor, TipoDte $tipo, int $folio): string
    {
        return 'SS'.$rutEmisor.'SS'.str_pad((string) $tipo->value, 3, '0', STR_PAD_LEFT)
            .'F'.str_pad((string) $folio, 10, '0', STR_PAD_LEFT);
    }

    /**
     * La carátula, con la sangría de Softland.
     *
     * La fecha y el número de resolución salen de `soempre`, que es donde los
     * guarda el ERP. Escribirlos en el código sería dejar que una empresa nueva
     * mande la resolución de INNOVAGES.
     */
    private function caratula(string $rutEmisor, object $emisor, array $porTipo, string $sello): string
    {
        $resolucion = $emisor->DTEFechaResol ?? null;

        if (! $resolucion) {
            throw new RuntimeException(
                'Falta la fecha de resolución del SII en soempre.DTEFechaResol: sin ella el sobre no valida.'
            );
        }

        $subtotales = '';

        foreach ($porTipo as $tipoDte => $cuantos) {
            $subtotales .= '    <SubTotDTE>'
                .'      <TpoDTE>'.$tipoDte.'</TpoDTE>'
                .'      <NroDTE>'.$cuantos.'</NroDTE>'
                .'    </SubTotDTE>';
        }

        return '<Caratula version="'.self::VERSION.'">'
            .'    <RutEmisor>'.$rutEmisor.'</RutEmisor>'
            .'    <RutEnvia>'.$this->rutQueEnvia().'</RutEnvia>'
            .'    <RutReceptor>'.self::RUT_SII.'</RutReceptor>'
            .'    <FchResol>'.substr((string) $resolucion, 0, 10).'</FchResol>'
            .'    <NroResol>'.(int) ($emisor->DTENumeroResol ?? 0).'</NroResol>'
            .'    <TmstFirmaEnv>'.$sello.'</TmstFirmaEnv>'
            .$subtotales
            .'  </Caratula>';
    }

    /**
     * El RUT de quien firma. Si el certificado no lo trae legible, se para aquí
     * y no en el SII: el rechazo remoto llega horas después y dice otra cosa.
     */
    public function rutQueEnvia(): string
    {
        if ($this->cert->rut === '') {
            throw new RuntimeException(
                'El certificado no trae el RUT de quien firma, y el sobre lo exige en <RutEnvia>.'
            );
        }

        return $this->cert->rut;
    }

    private function cabecera(string $tipoSoftland, int $nroInt): object
    {
        $fila = DB::connection('softland')->table($this->califica('iw_gsaen'))
            ->where('Tipo', $tipoSoftland)->where('NroInt', $nroInt)->first();

        return $fila ?? throw new RuntimeException("No está el documento {$tipoSoftland}/{$nroInt} en iw_gsaen.");
    }

    private function fila(string $tabla): ?object
    {
        return DB::connection('softland')->table($this->califica($tabla))->first();
    }

    private function califica(string $objeto): string
    {
        return $this->base ? "{$this->base}.softland.{$objeto}" : "softland.{$objeto}";
    }

    private function rut(string $rut): string
    {
        return strtoupper(trim($rut));
    }
}
