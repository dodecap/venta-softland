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
 */
class FirmaXml
{
    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    private const SHA1 = 'http://www.w3.org/2000/09/xmldsig#sha1';

    private const RSA_SHA1 = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';

    /**
     * El certificado es opcional a propósito.
     *
     * Calcular el resumen de un documento —que es la prueba de que lo generamos
     * bien— no necesita ninguna llave: es canonicalizar y resumir. Solo firmar
     * la necesita. Exigirla para las dos cosas obligaría a tener el certificado
     * a mano para comprobar algo que no lo requiere.
     */
    public function __construct(private readonly ?Certificado $cert = null) {}

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
        $signedInfo = $this->signedInfo($id, $resumen);

        return '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
            .$signedInfo
            .'<SignatureValue>'.$cert->firmar($this->canonico($signedInfo)).'</SignatureValue>'
            .'<KeyInfo><KeyValue><RSAKeyValue>'
            .'<Modulus>'.$cert->moduloBase64().'</Modulus>'
            .'<Exponent>'.$cert->exponenteBase64().'</Exponent>'
            .'</RSAKeyValue></KeyValue>'
            .'<X509Data><X509Certificate>'.$cert->x509Base64().'</X509Certificate></X509Data>'
            .'</KeyInfo></Signature>';
    }

    /** El resumen SHA1 del elemento, sobre su forma canónica, en base64. */
    public function resumen(string $xml): string
    {
        return base64_encode(sha1($this->canonico($xml), true));
    }

    /**
     * La forma canónica de un fragmento de XML.
     *
     * Se carga declarando ISO-8859-1, que es la codificación del DTE: si se deja
     * adivinar, un acento se interpreta como UTF-8 y la forma canónica sale
     * distinta. Ese error no se ve en pantalla; se ve cuando el SII rechaza.
     */
    public function canonico(string $xml): string
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;

        $previo = libxml_use_internal_errors(true);
        $ok = $doc->loadXML('<?xml version="1.0" encoding="ISO-8859-1"?>'.$xml);
        $errores = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        if (! $ok) {
            throw new RuntimeException(
                'El XML no se pudo leer para canonicalizar: '
                .trim($errores[0]->message ?? 'sin detalle')
            );
        }

        return $doc->documentElement->C14N();
    }

    private function signedInfo(string $id, string $resumen): string
    {
        return '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">'
            .'<CanonicalizationMethod Algorithm="'.self::C14N.'"/>'
            .'<SignatureMethod Algorithm="'.self::RSA_SHA1.'"/>'
            .'<Reference URI="#'.$id.'">'
            .'<Transforms><Transform Algorithm="'.self::C14N.'"/></Transforms>'
            .'<DigestMethod Algorithm="'.self::SHA1.'"/>'
            .'<DigestValue>'.$resumen.'</DigestValue>'
            .'</Reference></SignedInfo>';
    }
}
