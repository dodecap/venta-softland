<?php

namespace App\Services\Dte;

use DOMDocument;
use RuntimeException;

/**
 * La firma electrónica del XML: `XMLDSig` como lo pide el SII.
 *
 * Firma el elemento `<Documento>` —o el `<SetDTE>` del sobre— y produce el
 * bloque `<Signature>` que va a su lado.
 *
 * ## Lo que hace que esto funcione o no
 *
 * La firma **no cubre el texto del XML**: cubre su **forma canónica**, que es
 * lo que produce la canonicalización C14N. Eso es una buena noticia y conviene
 * entenderla, porque cambia cómo se comprueba:
 *
 *   - dos documentos escritos distinto —uno con `<a/>` y otro con `<a></a>`,
 *     uno con saltos de línea y otro sin— pueden tener **la misma** forma
 *     canónica y por tanto el mismo resumen;
 *   - y al revés: un espacio dentro de un texto sí cambia la forma canónica.
 *
 * Por eso la prueba de que generamos bien un documento no es que el texto
 * coincida letra por letra con el de Softland, sino que coincida el
 * `<DigestValue>`. Si nuestro resumen es igual al que el SII aceptó, nuestro
 * documento es canónicamente el mismo.
 *
 * ## Dos firmas, dos alcances
 *
 *   - el `<Reference>` resume el elemento firmado, por su `URI="#…"`;
 *   - el `<SignatureValue>` firma el `<SignedInfo>`, que contiene ese resumen.
 *
 * Los dos usan SHA1. No se elige: lo fija el estándar del SII.
 *
 * ## El ámbito: lo que hereda el elemento firmado
 *
 * La canonicalización inclusiva arrastra al elemento firmado **todos los
 * espacios de nombres que tiene en ámbito**, aunque los declare su abuelo. Un
 * `<SetDTE>` suelto se canonicaliza `<SetDTE ID="…">`; el mismo dentro de
 * `<EnvioDTE xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="…">` se canonicaliza
 * `<SetDTE xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="…" ID="…">`. Son dos
 * textos distintos y por tanto dos resúmenes distintos.
 *
 * De ahí `$ambito`. No es una opción de estilo: firmar el sobre sin él produce
 * una firma que no valida, y el SII lo rechaza entero.
 *
 * Y no es lo mismo para las dos cosas que se firman aquí:
 *
 *   - **el documento** se firma suelto, sin ámbito. Suena raro porque dentro
 *     del sobre sí hereda, pero es lo que hace Softland y es lo que el SII
 *     aceptó 209 veces: el SII saca cada `<DTE>` del sobre y lo valida como
 *     documento aparte.
 *   - **el sobre** se firma con el ámbito de `<EnvioDTE>`.
 *
 * Esto no se dedujo de un manual: se sacó comparando contra los sobres que el
 * SII ya aceptó, probando las cuatro combinaciones hasta que una dio el mismo
 * resumen guardado.
 */
class FirmaXml
{
    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    private const SHA1 = 'http://www.w3.org/2000/09/xmldsig#sha1';

    private const RSA_SHA1 = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';

    private const ENVOLVENTE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    /**
     * El certificado es opcional a propósito.
     *
     * Calcular el resumen de un documento —que es la prueba de que lo generamos
     * bien— no necesita ninguna llave: es canonicalizar y resumir. Solo firmar
     * la necesita. Exigirla para las dos cosas obligaría a tener el certificado
     * a mano para comprobar algo que no lo requiere.
     */
    /**
     * @param  array<string, string>  $ambito  espacios de nombres que el elemento
     *         hereda de quien lo contiene, con el prefijo como clave y `''` para
     *         el predeterminado
     */
    public function __construct(
        private readonly ?Certificado $cert = null,
        private readonly array $ambito = [],
    ) {}

    /**
     * El bloque `<Signature>` para un elemento identificado por su `ID`.
     *
     * @param  string  $xml  el elemento a firmar, tal como va a quedar escrito
     * @param  string  $id   el valor de su atributo `ID`, sin la almohadilla
     */
    public function firmar(string $xml, string $id): string
    {
        return $this->firmarResumen($this->resumen($xml), $id);
    }

    /**
     * El `<Signature>` a partir de un resumen ya calculado.
     *
     * Separado a propósito: permite comprobar **la firma sola**, dándole el
     * resumen de un documento que el SII ya aceptó. Si con el mismo resumen sale
     * la misma firma, el certificado y el algoritmo son los correctos,
     * independientemente de si reprodujimos el documento letra por letra.
     */
    public function firmarResumen(string $resumen, string $id): string
    {
        $cert = $this->cert ?? throw new RuntimeException('Para firmar hace falta el certificado digital.');

        return $this->bloque($this->signedInfo($id, $resumen), $cert);
    }

    /** El `<Signature>` armado alrededor de un `<SignedInfo>` ya escrito. */
    private function bloque(string $signedInfo, Certificado $cert): string
    {
        return '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
            .$signedInfo
            .'<SignatureValue>'.$cert->firmar($this->canonico($signedInfo, $this->ambitoDeLaFirma())).'</SignatureValue>'
            .'<KeyInfo><KeyValue><RSAKeyValue>'
            .'<Modulus>'.$cert->moduloBase64().'</Modulus>'
            .'<Exponent>'.$cert->exponenteBase64().'</Exponent>'
            .'</RSAKeyValue></KeyValue>'
            .'<X509Data><X509Certificate>'.$cert->x509Base64().'</X509Certificate></X509Data>'
            .'</KeyInfo></Signature>';
    }

    /**
     * La firma **envolvente**: la que va dentro del elemento que firma, no al
     * lado.
     *
     * Es la que pide la petición de token del SII, y se diferencia en dos cosas
     * de la del documento:
     *
     *   - la referencia es `URI=""`, o sea «el documento entero», no un `ID`;
     *   - lleva la transformación `enveloped-signature`, que significa «quita la
     *     firma antes de resumir». Por eso el resumen se calcula **antes** de
     *     insertarla, que es lo que hace este método: lo que se le pasa todavía
     *     no la contiene.
     */
    public function firmarEnvolvente(string $xml): string
    {
        $cert = $this->cert ?? throw new RuntimeException('Para firmar hace falta el certificado digital.');
        $referencia = '<Reference URI="">'
            .'<Transforms><Transform Algorithm="'.self::ENVOLVENTE.'"/></Transforms>'
            .'<DigestMethod Algorithm="'.self::SHA1.'"/>'
            .'<DigestValue>'.$this->resumen($xml).'</DigestValue>'
            .'</Reference>';

        return $this->bloque($this->signedInfoCon($referencia), $cert);
    }

    /** El resumen SHA1 del elemento, sobre su forma canónica, en base64. */
    public function resumen(string $xml): string
    {
        return base64_encode(sha1($this->canonico($xml, $this->ambito), true));
    }

    /**
     * La forma canónica de un fragmento de XML.
     *
     * Se carga declarando ISO-8859-1, que es la codificación del DTE: si se deja
     * adivinar, un acento se interpreta como UTF-8 y la forma canónica sale
     * distinta. Ese error no se ve en pantalla; se ve cuando el SII rechaza.
     */
    public function canonico(string $xml, array $ambito = []): string
    {
        // Para canonicalizar en ámbito se envuelve el elemento en uno que
        // declare lo heredado y se canonicaliza el hijo: así el resultado lleva
        // escritos los espacios de nombres, que es justo lo que cambia.
        $envoltura = '';

        foreach ($ambito as $prefijo => $uri) {
            $envoltura .= ' '.($prefijo === '' ? 'xmlns' : "xmlns:{$prefijo}").'="'.$uri.'"';
        }

        $texto = $envoltura === '' ? $xml : "<ambito{$envoltura}>{$xml}</ambito>";

        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;

        $previo = libxml_use_internal_errors(true);
        $ok = $doc->loadXML('<?xml version="1.0" encoding="ISO-8859-1"?>'.$texto);
        $errores = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        if (! $ok) {
            throw new RuntimeException(
                'El XML no se pudo leer para canonicalizar: '
                .trim($errores[0]->message ?? 'sin detalle')
            );
        }

        $nodo = $envoltura === '' ? $doc->documentElement : $doc->documentElement->firstChild;

        return $nodo?->C14N() ?? '';
    }

    /**
     * El ámbito del `<SignedInfo>`, que no es el mismo del elemento firmado: el
     * `<Signature>` vuelve a declarar el espacio de nombres predeterminado como
     * el de XMLDSig, así que de lo heredado solo siguen en pie los prefijados.
     */
    private function ambitoDeLaFirma(): array
    {
        return array_filter($this->ambito, fn ($prefijo) => $prefijo !== '', ARRAY_FILTER_USE_KEY);
    }

    private function signedInfo(string $id, string $resumen): string
    {
        return $this->signedInfoCon(
            '<Reference URI="#'.$id.'">'
            .'<Transforms><Transform Algorithm="'.self::C14N.'"/></Transforms>'
            .'<DigestMethod Algorithm="'.self::SHA1.'"/>'
            .'<DigestValue>'.$resumen.'</DigestValue>'
            .'</Reference>'
        );
    }

    private function signedInfoCon(string $referencia): string
    {
        return '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">'
            .'<CanonicalizationMethod Algorithm="'.self::C14N.'"/>'
            .'<SignatureMethod Algorithm="'.self::RSA_SHA1.'"/>'
            .$referencia
            .'</SignedInfo>';
    }
}
