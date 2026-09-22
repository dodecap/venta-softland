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
 * Se lee de donde esté guardada —`AlmacenCertificado` si lo subieron desde la
 * app, el `.env` del servidor si no—, se usa para abrir el PKCS#12 y se
 * descarta. Como con el CAF, aquí no hay un método que devuelva la llave
 * privada: hay uno que firma.
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

    /**
     * El certificado que esta instalación está usando.
     *
     * Manda el que se subió desde la app: es el que alguien decidió después. El
     * `.env` queda de respaldo para las instalaciones que ya lo tenían puesto,
     * que siguen emitiendo sin que nadie haga nada.
     */
    public static function desdeConfiguracion(): self
    {
        if (AlmacenCertificado::hay()) {
            return self::desdeArchivo(
                AlmacenCertificado::rutaArchivo(),
                (string) AlmacenCertificado::clave()
            );
        }

        $ruta = (string) config('dte.certificado.ruta');
        $clave = (string) config('dte.certificado.clave');

        if ($ruta === '' || ! is_file($ruta)) {
            throw new RuntimeException(
                'No hay certificado digital en este servidor. Se sube desde la app, '
                .'en Configuración → Certificado digital.'
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

        return self::desdeContenido($pkcs12, $clave);
    }

    /**
     * Desde los bytes, sin pasar por el disco.
     *
     * Es lo que permite comprobar una subida **antes** de escribirla: si el
     * archivo no sirve, el que está funcionando se queda donde está.
     */
    public static function desdeContenido(string $pkcs12, string $clave): self
    {
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
            rut: self::rutDe($datos, $bolsa['cert']),
            vence: date('Y-m-d', $datos['validTo_time_t']),
            rsa: ['n' => $detalle['rsa']['n'], 'e' => $detalle['rsa']['e']],
        );
    }

    /**
     * El RUT de quien firma, que va en el `<RutEnvia>` del sobre y que el SII
     * comprueba contra los usuarios autorizados del contribuyente.
     *
     * No es el RUT de la empresa. Aquí el certificado es de Jorge Palominos
     * (17421371-2) y la empresa es INNOVAGES (77828631-9); mandar el segundo
     * como `RutEnvia` es un rechazo inmediato.
     *
     * ## Por qué hay que bajar hasta el DER
     *
     * E-Certchile no lo pone en un campo estándar sino en un `otherName` del
     * `subjectAltName`, bajo el OID **1.3.6.1.4.1.8321.1**. PHP no sabe leer
     * ese tipo de nombre: `openssl_x509_parse()` devuelve literalmente
     * «othername:<unsupported>». Así que se busca el OID en los bytes del
     * certificado y se lee lo que viene detrás.
     *
     * Cuidado con el OID: 8321 se codifica **`c1 01`** —0x80|65, luego 1—, no
     * `c1 41`. Con el orden cambiado no aparece y parece que la extensión no
     * está.
     *
     * Hay un segundo RUT en el certificado, bajo `8321.2`: es el de la entidad
     * certificadora (96928180-5). Ese no sirve, y es el que salía antes.
     */
    private static function rutDe(array $datos, string $pem): string
    {
        $der = base64_decode((string) preg_replace('/\s+|-----[^-]+-----/', '', $pem), true);
        $oid = "\x06\x08\x2b\x06\x01\x04\x01\xc1\x01\x01";
        $donde = $der === false ? false : strpos($der, $oid);

        // El valor va justo detrás, envuelto en un par de etiquetas ASN.1 de
        // longitud corta. Buscar el patrón de RUT en los bytes siguientes evita
        // tener que escribir un lector de ASN.1 para leer un dato de 10 letras.
        if ($donde !== false && preg_match('/\d{7,8}-[\dkK]/', substr((string) $der, $donde, 48), $m)) {
            return strtoupper($m[0]);
        }

        // Respaldo: algunos emisores sí lo dejan a la vista en una extensión.
        $todo = implode(' ', array_map('strval', (array) ($datos['extensions'] ?? [])));

        return preg_match('/8321\.1[^0-9]{0,4}(\d{7,8}-[\dkK])/', $todo, $m) ? strtoupper($m[1]) : '';
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
