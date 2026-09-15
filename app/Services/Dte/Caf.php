<?php

namespace App\Services\Dte;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Un lote de folios autorizado por el SII, tal como Softland lo guarda.
 *
 * El CAF («Código de Autorización de Folios») es un archivo que entrega el SII
 * y que contiene tres cosas: el rango de folios que autoriza, una llave pública
 * RSA, y su firma. Junto a él viene la **llave privada**, con la que se timbra
 * cada documento de ese rango. Softland ya tiene todo eso cargado en
 * `softland.dte_siicaf`, una fila por lote.
 *
 * ## La llave privada no sale de aquí
 *
 * `firmar()` es lo único que expone. No hay un método que devuelva la llave, y
 * es a propósito: quien pueda leerla puede timbrar documentos a nombre de la
 * empresa. Vive en la base, se carga en memoria dentro del servidor y se firma
 * ahí. Nunca se escribe a un archivo, nunca se registra en un log, nunca viaja
 * al teléfono.
 *
 * ## Por qué el `<CAF>` se guarda crudo
 *
 * El timbre de cada documento **incrusta el `<CAF>` entero, textual**, y
 * después se firma el conjunto. Si lo volviéramos a serializar —reordenando un
 * atributo, cambiando una comilla, normalizando un espacio— la firma dejaría de
 * validar aunque el contenido fuera el mismo. Por eso `xml()` devuelve los
 * bytes tal como llegaron del SII, sin pasarlos por un parser.
 */
class Caf
{
    private function __construct(
        public readonly TipoDte $tipo,
        public readonly int $folioDesde,
        public readonly int $folioHasta,
        public readonly string $rut,
        public readonly string $fecha,
        private readonly string $cafXml,
        private readonly string $llavePrivada,
    ) {}

    /**
     * El lote que cubre un folio. Si ninguno lo cubre, no se inventa: se falla.
     *
     * Un folio fuera de rango timbrado igual es un documento que el SII
     * rechaza, y el número ya se gastó.
     */
    public static function paraFolio(TipoDte $tipo, int $folio, ?string $base = null): self
    {
        $fila = DB::connection('softland')
            ->table(self::tabla($base, 'dte_siicaf'))
            ->selectRaw('DocCod, FolioD, FolioH, RUT, Fecha,
                         CAST(CAFXML AS nvarchar(max)) AS caf,
                         CAST(RSASK AS nvarchar(max)) AS sk')
            ->where('DocCod', (string) $tipo->value)
            ->where('FolioD', '<=', $folio)
            ->where('FolioH', '>=', $folio)
            ->orderBy('FolioD')
            ->first();

        if (! $fila) {
            throw new RuntimeException(
                "No hay CAF cargado que cubra el folio {$folio} de {$tipo->nombre()} ({$tipo->value})."
            );
        }

        return self::desdeFila($tipo, $fila);
    }

    /**
     * Un CAF leído del archivo que entrega el SII, sin pasar por Softland.
     *
     * El SII descarga el CAF como un XML `<AUTORIZACION>` que trae el `<CAF>` y
     * la llave privada en `<RSASK>`. Hace falta por dos razones: para cargar un
     * lote nuevo antes de que alguien lo suba al ERP —el caso de la boleta, que
     * todavía no tiene folios— y para poder probar el timbre sin base de datos.
     */
    public static function desdeAutorizacion(string $xml, ?TipoDte $tipo = null): self
    {
        $caf = self::recortaCaf($xml);

        if ($caf === '' || ! preg_match('#<RSASK>(.*?)</RSASK>#s', $xml, $sk)) {
            throw new RuntimeException('El archivo no parece un CAF del SII: falta el <CAF> o el <RSASK>.');
        }

        $dato = function (string $etiqueta) use ($caf): string {
            preg_match("#<{$etiqueta}>(.*?)</{$etiqueta}>#s", $caf, $m);

            return trim($m[1] ?? '');
        };

        $tipo ??= TipoDte::tryFrom((int) $dato('TD'))
            ?? throw new RuntimeException("El CAF es de un tipo de documento que esta app no maneja: {$dato('TD')}.");

        preg_match('#<RNG>\s*<D>(\d+)</D>\s*<H>(\d+)</H>#s', $caf, $rng);

        return new self(
            tipo: $tipo,
            folioDesde: (int) ($rng[1] ?? 0),
            folioHasta: (int) ($rng[2] ?? 0),
            rut: $dato('RE'),
            fecha: $dato('FA'),
            cafXml: $caf,
            llavePrivada: trim($sk[1]),
        );
    }

    /** Todos los lotes de un tipo, del más viejo al más nuevo. Para diagnóstico. */
    public static function lotes(TipoDte $tipo, ?string $base = null): array
    {
        return DB::connection('softland')
            ->table(self::tabla($base, 'dte_siicaf'))
            ->select('DocCod', 'FolioD', 'FolioH', 'RUT', 'Fecha')
            ->where('DocCod', (string) $tipo->value)
            ->orderBy('FolioD')
            ->get()
            ->all();
    }

    /**
     * El nombre de la tabla, opcionalmente en otra base de la misma instancia.
     *
     * Existe por una razón concreta: INNOVAGES no ha emitido nunca una boleta,
     * y NETDOMAIN —su matriz, dormida desde enero de 2024— sí tiene una, real y
     * aceptada por el SII. Es el único caso contra el que se puede contrastar
     * el camino de la boleta hoy. Es de **solo lectura**: a NETDOMAIN no se le
     * escribe jamás.
     */
    private static function tabla(?string $base, string $tabla): string
    {
        $base = trim((string) $base);

        return $base === '' ? "softland.{$tabla}" : "{$base}.softland.{$tabla}";
    }

    private static function desdeFila(TipoDte $tipo, object $fila): self
    {
        $caf = self::recortaCaf((string) $fila->caf);
        $sk = trim((string) $fila->sk);

        if ($caf === '' || $sk === '') {
            throw new RuntimeException(
                "El CAF {$fila->FolioD}-{$fila->FolioH} de {$tipo->nombre()} está incompleto en dte_siicaf."
            );
        }

        return new self(
            tipo: $tipo,
            folioDesde: (int) $fila->FolioD,
            folioHasta: (int) $fila->FolioH,
            rut: trim((string) $fila->RUT),
            fecha: substr((string) $fila->Fecha, 0, 10),
            cafXml: $caf,
            llavePrivada: $sk,
        );
    }

    /**
     * El elemento `<CAF>` listo para incrustar en el timbre.
     *
     * Softland guarda unas veces el `<CAF>` pelado y otras el `<AUTORIZACION>`
     * que lo envuelve. Se recorta por posición de texto, no con un parser, para
     * no reordenar ni reescribir nada de lo que después se firma.
     *
     * ## El detalle que cuesta una tarde
     *
     * En `dte_siicaf.CAFXML` el CAF está guardado **con espacios entre los
     * elementos** (`<CAF version="1.0"> <DA> <RE>…`), pero dentro del DTE
     * Softland lo escribe **pegado** (`<CAF version="1.0"><DA><RE>…`). Como el
     * timbre firma bytes, incrustar la versión separada da una firma distinta y
     * un documento que el SII rechaza.
     *
     * Se colapsa solo el espacio que queda **entre** un `>` y un `<`. El de
     * dentro del texto no se toca: la razón social del CAF lleva espacios de
     * verdad, y comérselos cambiaría el contenido, no el formato.
     */
    private static function recortaCaf(string $bruto): string
    {
        $bruto = trim($bruto);
        $i = strpos($bruto, '<CAF');
        $j = strrpos($bruto, '</CAF>');

        if ($i === false || $j === false) {
            return '';
        }

        $caf = substr($bruto, $i, $j - $i + strlen('</CAF>'));

        return preg_replace('/>\s+</', '><', $caf) ?? $caf;
    }

    public function xml(): string
    {
        return $this->cafXml;
    }

    public function cubre(int $folio): bool
    {
        return $folio >= $this->folioDesde && $folio <= $this->folioHasta;
    }

    public function rango(): string
    {
        return "{$this->folioDesde}–{$this->folioHasta}";
    }

    /**
     * Firma con la llave privada del CAF. SHA1 con RSA, que es lo que pide el
     * SII, y devuelve base64 — el formato en que va dentro del `<FRMT>`.
     *
     * SHA1 está roto para casi todo lo demás. Aquí no se elige: el algoritmo lo
     * fija el estándar del SII y el validador del otro lado espera exactamente
     * eso.
     */
    public function firmar(string $datos): string
    {
        $llave = $this->llave();

        $firma = '';
        $ok = openssl_sign($datos, $firma, $llave, OPENSSL_ALGO_SHA1);

        if (! $ok) {
            throw new RuntimeException('No se pudo firmar el timbre: '.openssl_error_string());
        }

        return base64_encode($firma);
    }

    /**
     * La llave privada, lista para OpenSSL.
     *
     * Softland no la guarda siempre igual. Se han visto tres formas en
     * `dte_siicaf.RSASK`, y las tres son la misma llave:
     *
     *   - PEM completo, con sus líneas `-----BEGIN RSA PRIVATE KEY-----`;
     *   - el mismo base64 **sin** cabeceras, que es como llega el CAF del SII;
     *   - base64 con restos del PEM pegados — en INNOVAGES empieza por un
     *     guion suelto, `-MIIBOwIBAAJBA…`, que es lo que queda de la cabecera
     *     recortada. Un guion no es base64 y OpenSSL se planta sin explicar.
     *
     * Se prueban en orden en vez de adivinar por el largo. Si ninguna sirve, el
     * error dice qué se intentó — pero **nunca** muestra la llave: quien la lea
     * puede timbrar documentos a nombre de la empresa.
     */
    private function llave(): \OpenSSLAsymmetricKey
    {
        foreach ($this->formasDeLlave() as $forma => $pem) {
            $llave = openssl_pkey_get_private($pem);

            if ($llave !== false) {
                return $llave;
            }
        }

        throw new RuntimeException(sprintf(
            'La llave privada del CAF %s no se pudo leer en ninguna de las formas conocidas (%s). '
            .'Largo en la base: %d bytes. Último error de OpenSSL: %s',
            $this->rango(),
            implode(', ', array_keys($this->formasDeLlave())),
            strlen($this->llavePrivada),
            openssl_error_string() ?: 'ninguno'
        ));
    }

    /** @return array<string, string> */
    private function formasDeLlave(): array
    {
        $bruto = trim($this->llavePrivada);

        // Primero se quitan las cabeceras enteras, y recién después todo lo que
        // no sea base64. En ese orden: al revés, «BEGIN RSA PRIVATE KEY» dejaría
        // sus letras metidas dentro de la llave.
        $sinCabeceras = preg_replace('/-----[A-Z ]+-----/', '', $bruto) ?? '';
        $base64 = preg_replace('#[^A-Za-z0-9+/=]#', '', $sinCabeceras) ?? '';
        $envuelto = chunk_split($base64, 64, "\n");

        return [
            'tal cual' => $bruto,
            'pkcs1' => "-----BEGIN RSA PRIVATE KEY-----\n{$envuelto}-----END RSA PRIVATE KEY-----\n",
            'pkcs8' => "-----BEGIN PRIVATE KEY-----\n{$envuelto}-----END PRIVATE KEY-----\n",
        ];
    }

    /** Comprueba una firma contra la llave pública que viene dentro del propio CAF. */
    public function verificar(string $datos, string $firmaBase64): bool
    {
        if (! preg_match('#<RSAPK>.*?<M>(.*?)</M>.*?<E>(.*?)</E>.*?</RSAPK>#s', $this->cafXml, $m)) {
            throw new RuntimeException('El CAF no trae llave pública (<RSAPK>).');
        }

        $publica = self::llavePublicaDesdeModulo(base64_decode($m[1]), base64_decode($m[2]));

        return openssl_verify($datos, base64_decode($firmaBase64), $publica, OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * Arma una llave pública RSA en PEM a partir del módulo y el exponente que
     * el CAF trae sueltos, en base64.
     *
     * Es DER a mano porque no hay otra: el SII no entrega la llave en PEM, y
     * `openssl_pkey_get_public()` no sabe leer un par de enteros.
     */
    private static function llavePublicaDesdeModulo(string $modulo, string $exponente): \OpenSSLAsymmetricKey
    {
        $entero = function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || ord($bytes[0]) > 0x7F) {
                $bytes = "\x00".$bytes;
            }

            return "\x02".self::longitudDer(strlen($bytes)).$bytes;
        };

        $secuencia = fn (string $c) => "\x30".self::longitudDer(strlen($c)).$c;
        $bitString = fn (string $c) => "\x03".self::longitudDer(strlen($c) + 1)."\x00".$c;

        $rsa = $secuencia($entero($modulo).$entero($exponente));
        $oid = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $der = $secuencia($oid.$bitString($rsa));

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";

        $llave = openssl_pkey_get_public($pem);

        if ($llave === false) {
            throw new RuntimeException('No se pudo reconstruir la llave pública del CAF: '.openssl_error_string());
        }

        return $llave;
    }

    private static function longitudDer(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }

        $bytes = ltrim(pack('N', $n), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
