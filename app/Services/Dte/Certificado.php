<?php

namespace App\Services\Dte;

use RuntimeException;

/**
 * El certificado digital de la empresa: quien acredita al emisor.
 *
 * No confundir con el CAF. Son dos firmas distintas sobre el mismo documento y
 * sirven para cosas distintas:
 *
 *   - el **CAF** timbra el folio —«este número me lo autorizó el SII»— y su
 *     llave vive en la base de Softland;
 *   - el **certificado** firma el documento entero —«y lo emito yo»— y es una
 *     firma electrónica avanzada, emitida a una persona natural por una entidad
 *     acreditada.
 *
 * El de INNOVAGES es de Jorge Palominos Valenzuela, de E-Certchile, y **vence
 * el 26 de diciembre de 2026**. Cuando venza deja de emitir esta app y también
 * el Softland de escritorio, que usa el mismo. Por eso `avisaVencimiento()`
 * existe y por eso la ruta es configurable: renovarlo tiene que ser copiar un
 * archivo.
 *
 * ## La clave no se queda en ningún lado
 *
 * Se lee del `.env` del servidor, se usa para abrir el PKCS#12 y se descarta.
 * Como con el CAF, aquí no hay un método que devuelva la llave privada: hay uno
 * que firma.
 */
class Certificado
{
    /** Cuántos días antes empezar a avisar que se vence. */
    public const AVISO_DIAS = 60;

    private function __construct(
        private readonly string $llavePrivada,
        public readonly string $certificadoPem,
        public readonly string $sujeto,
        public readonly string $rut,
        public readonly string $vence,
        public readonly array $rsa,
    ) {}

    public static function desdeConfiguracion(): self
    {
        $ruta = (string) config('dte.certificado.ruta');
        $clave = (string) config('dte.certificado.clave');

        if ($ruta === '' || ! is_file($ruta)) {
            throw new RuntimeException(
                "No está el certificado digital en «{$ruta}». Se configura con DTE_CERT_RUTA."
            );
        }

        return self::desdeArchivo($ruta, $clave);
    }

    public static function desdeArchivo(string $ruta, string $clave): self
    {
        $pkcs12 = @file_get_contents($ruta);

        if ($pkcs12 === false) {
            throw new RuntimeException("No se pudo leer el certificado en «{$ruta}».");
        }

        $bolsa = [];

        if (! openssl_pkcs12_read($pkcs12, $bolsa, $clave)) {
            // El error de OpenSSL aquí es casi siempre «mac verify failure», que
            // en cristiano es «la clave no es esa». Se dice así, que es lo que
            // le sirve a quien lo lee.
            throw new RuntimeException(
                'No se pudo abrir el certificado: la clave no corresponde, o el archivo no es un PKCS#12. '
                .'('.(openssl_error_string() ?: 'sin detalle').')'
            );
        }

        $x509 = openssl_x509_read($bolsa['cert']);
        $datos = openssl_x509_parse($x509);
        $detalle = openssl_pkey_get_details(openssl_pkey_get_private($bolsa['pkey']));

        return new self(
            llavePrivada: $bolsa['pkey'],
            certificadoPem: $bolsa['cert'],
            sujeto: $datos['subject']['CN'] ?? '(sin nombre)',
            rut: self::rutDe($datos),
            vence: date('Y-m-d', $datos['validTo_time_t']),
            rsa: ['n' => $detalle['rsa']['n'], 'e' => $detalle['rsa']['e']],
        );
    }

    /**
     * El RUT de quien firma, que el SII comprueba contra sus usuarios
     * autorizados del contribuyente.
     *
     * E-Certchile lo pone en una extensión propia (`1.3.6.1.4.1.8321.1`), no en
     * un campo estándar, así que se busca ahí y se acepta no encontrarlo: el
     * documento igual se firma, y quien valida es el SII.
     */
    private static function rutDe(array $datos): string
    {
        $todo = implode(' ', array_map('strval', (array) ($datos['extensions'] ?? [])));

        // E-Certchile pone dos RUT: en `8321.1` el de la **persona** que firma y
        // en `8321.2` el de la entidad. El que el SII comprueba contra sus
        // usuarios autorizados es el primero; quedarse con el que aparezca antes
        // devolvía el otro.
        if (preg_match('/8321\.1[^0-9]{0,4}(\d{7,8}-[\dkK])/', $todo, $m)) {
            return strtoupper($m[1]);
        }

        return preg_match('/\b(\d{7,8}-[\dkK])\b/', $todo, $m) ? strtoupper($m[1]) : '';
    }

    /** Días que le quedan. Negativo si ya venció. */
    public function diasRestantes(): int
    {
        return (int) floor((strtotime($this->vence) - time()) / 86400);
    }

    public function vencido(): bool
    {
        return $this->diasRestantes() < 0;
    }

    /** El aviso que hay que mostrar, o null si todavía no toca. */
    public function avisaVencimiento(): ?string
    {
        $dias = $this->diasRestantes();

        if ($dias < 0) {
            return "El certificado digital venció el {$this->vence}. No se puede emitir hasta renovarlo.";
        }

        if ($dias <= self::AVISO_DIAS) {
            return "El certificado digital vence el {$this->vence}: quedan {$dias} días. "
                .'Cuando venza deja de emitir esta app y también el Softland de escritorio.';
        }

        return null;
    }

    /** Firma con la llave del certificado. SHA1 con RSA, que es lo que pide el SII. */
    public function firmar(string $datos): string
    {
        $llave = openssl_pkey_get_private($this->llavePrivada);

        if ($llave === false) {
            throw new RuntimeException('No se pudo usar la llave del certificado: '.openssl_error_string());
        }

        $firma = '';

        if (! openssl_sign($datos, $firma, $llave, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('No se pudo firmar: '.openssl_error_string());
        }

        return base64_encode($firma);
    }

    /** El certificado en base64, sin cabeceras, como lo quiere `<X509Certificate>`. */
    public function x509Base64(): string
    {
        return preg_replace('/\s+|-----[^-]+-----/', '', $this->certificadoPem) ?? '';
    }

    public function moduloBase64(): string
    {
        return base64_encode($this->rsa['n']);
    }

    public function exponenteBase64(): string
    {
        return base64_encode($this->rsa['e']);
    }
}
