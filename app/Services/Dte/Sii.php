<?php

namespace App\Services\Dte;

use App\Support\Texto;
use RuntimeException;

/**
 * El transporte al SII: semilla, token, envío y consulta de estado.
 *
 * Son dos protocolos distintos en la misma casa:
 *
 *   - la autenticación y las consultas van por **SOAP** a unos `.jws` que son
 *     servicios Java de hace veinte años;
 *   - el envío del sobre va por un **formulario multiparte** a un CGI, con el
 *     token viajando en una cookie. No es una API: es la misma subida que hace
 *     el navegador en la página del SII.
 *
 * La boleta no pasa por aquí: va por la API REST, que es otra integración.
 *
 * ## El apretón de manos
 *
 * 1. se pide una **semilla**, que es un número que caduca en dos minutos;
 * 2. se firma la semilla con el certificado y se canjea por un **token**;
 * 3. el token vale unos minutos y se manda como cookie en cada llamada.
 *
 * Firmar la semilla es lo que prueba quién es uno. Por eso pedir un token es
 * una comprobación completa de que el certificado sirve, sin emitir nada ni
 * gastar un folio: si el SII devuelve token, la mitad difícil ya funciona.
 *
 * ## Sobre los errores
 *
 * El SII contesta 200 casi siempre, incluso cuando rechaza; lo que cambia es el
 * cuerpo. Así que aquí no se mira el código HTTP para decidir si algo salió
 * bien, se mira lo que dice el XML.
 */
class Sii
{
    /** Cuánto esperar al SII. Sus servicios son lentos y contestan igual. */
    private const ESPERA = 60;

    /**
     * El SII comprueba el navegador. Con el agente por omisión de cURL hay
     * llamadas que devuelven una página de error en vez del XML.
     */
    private const AGENTE = 'Mozilla/4.0 (compatible; PROG 1.0; Windows NT 5.0; YComp 5.0.2.4)';

    private ?string $token = null;

    public function __construct(
        private readonly Certificado $cert,
        private readonly ?string $ambiente = null,
    ) {}

    public function ambiente(): string
    {
        return $this->ambiente ?? (string) config('dte.ambiente', 'produccion');
    }

    public function certificacion(): bool
    {
        return $this->ambiente() === 'certificacion';
    }

    public function direccion(string $cual): string
    {
        $ambiente = $this->ambiente();
        $url = config("dte.ambientes.{$ambiente}.{$cual}");

        if (! is_string($url) || $url === '') {
            throw new RuntimeException("No está configurada la dirección «{$cual}» del ambiente {$ambiente}.");
        }

        return $url;
    }

    /** La semilla: un número que el SII da para firmar y que caduca enseguida. */
    public function semilla(): string
    {
        $respuesta = $this->soap($this->direccion('semilla'), 'getSeed');
        $semilla = $this->dentro($respuesta, 'SEMILLA');

        if ($semilla === null) {
            throw new RuntimeException('El SII no entregó semilla: '.$this->resumenDe($respuesta));
        }

        return $semilla;
    }

    /**
     * El token, canjeando una semilla firmada.
     *
     * La petición se firma **envolvente** —la firma va dentro del propio
     * `<getToken>`—, que es la única forma que acepta este servicio.
     */
    public function token(bool $recordar = true): string
    {
        if ($recordar && $this->token !== null) {
            return $this->token;
        }

        $peticion = '<getToken><item><Semilla>'.$this->semilla().'</Semilla></item></getToken>';
        $firmada = '<?xml version="1.0" encoding="UTF-8"?>'
            .substr($peticion, 0, -strlen('</getToken>'))
            .(new FirmaXml($this->cert))->firmarEnvolvente($peticion)
            .'</getToken>';

        $respuesta = $this->soap($this->direccion('token'), 'getToken', ['pszXml' => $firmada]);
        $token = $this->dentro($respuesta, 'TOKEN');

        if ($token === null) {
            throw new RuntimeException('El SII no entregó token: '.$this->resumenDe($respuesta));
        }

        return $this->token = $token;
    }

    /**
     * Sube el sobre y devuelve el `TrackID`.
     *
     * **Esto es irreversible**: a partir de aquí el documento existe para el
     * SII. Quien llame a esto tiene que estar seguro.
     *
     * @return array{trackId:string, estado:string, glosa:string, cuerpo:string}
     */
    public function enviar(string $sobre, string $rutEmisor, string $nombreArchivo): array
    {
        [$rutEnvia, $dvEnvia] = self::parteRut($this->cert->rut);
        [$rutEmpresa, $dvEmpresa] = self::parteRut($rutEmisor);

        $cuerpo = $this->multiparte([
            'rutSender' => $rutEnvia,
            'dvSender' => $dvEnvia,
            'rutCompany' => $rutEmpresa,
            'dvCompany' => $dvEmpresa,
        ], 'archivo', $nombreArchivo, $sobre, $frontera);

        $respuesta = $this->pide($this->direccion('envio'), $cuerpo, [
            'Content-Type: multipart/form-data; boundary='.$frontera,
            'Cookie: TOKEN='.$this->token(),
        ]);

        $estado = $this->dentro($respuesta, 'STATUS') ?? '';
        $track = $this->dentro($respuesta, 'TRACKID');

        if ($track === null || $track === '0') {
            throw new RuntimeException(
                'El SII no aceptó el envío'.($estado !== '' ? " (estado {$estado})" : '')
                .': '.$this->resumenDe($respuesta)
            );
        }

        return [
            'trackId' => $track,
            'estado' => $estado,
            'glosa' => $this->dentro($respuesta, 'DETAIL') ?? '',
            'cuerpo' => $respuesta,
        ];
    }

    /**
     * En qué quedó un envío.
     *
     * La respuesta tarda: recién subido contesta «en proceso», y el veredicto
     * puede demorar minutos. Que un envío no esté resuelto no es un error.
     *
     * @return array{estado:string, glosa:string, aceptados:?int, rechazados:?int, reparos:?int, cuerpo:string}
     */
    public function estadoEnvio(string $trackId, string $rutEmisor): array
    {
        [$rut, $dv] = self::parteRut($rutEmisor);

        $respuesta = $this->soap($this->direccion('estado_envio'), 'getEstUp', [
            'Rut' => $rut,
            'Dv' => $dv,
            'TrackId' => $trackId,
            'Token' => $this->token(),
        ]);

        return [
            'estado' => $this->dentro($respuesta, 'ESTADO') ?? '',
            'glosa' => $this->dentro($respuesta, 'GLOSA') ?? ($this->dentro($respuesta, 'GLOSA_ESTADO') ?? ''),
            'aceptados' => $this->entero($this->dentro($respuesta, 'ACEPTADOS')),
            'rechazados' => $this->entero($this->dentro($respuesta, 'RECHAZADOS')),
            'reparos' => $this->entero($this->dentro($respuesta, 'REPAROS')),
            'cuerpo' => $respuesta,
        ];
    }

    /**
     * En qué quedó un documento concreto, que es distinto de en qué quedó su
     * envío: un envío aceptado puede traer un documento reparado.
     *
     * @return array{estado:string, glosa:string, cuerpo:string}
     */
    public function estadoDocumento(
        string $rutEmisor,
        TipoDte $tipo,
        int $folio,
        string $rutReceptor,
        string $fecha,
        int $monto,
    ): array {
        [$rutE, $dvE] = self::parteRut($rutEmisor);
        [$rutR, $dvR] = self::parteRut($rutReceptor);
        [$rutC, $dvC] = self::parteRut($this->cert->rut);

        $respuesta = $this->soap($this->direccion('estado_dte'), 'getEstDte', [
            'RutConsultante' => $rutC,
            'DvConsultante' => $dvC,
            'RutCompania' => $rutE,
            'DvCompania' => $dvE,
            'RutReceptor' => $rutR,
            'DvReceptor' => $dvR,
            'TipoDte' => (string) $tipo->value,
            'FolioDte' => (string) $folio,
            // El SII lo quiere como ddmmaaaa, sin separadores.
            'FechaEmisionDte' => date('dmY', strtotime($fecha)),
            'MontoDte' => (string) abs($monto),
            'Token' => $this->token(),
        ]);

        return [
            'estado' => $this->dentro($respuesta, 'ESTADO') ?? '',
            'glosa' => $this->dentro($respuesta, 'GLOSA') ?? '',
            'cuerpo' => $respuesta,
        ];
    }

    /**
     * Una llamada SOAP a mano.
     *
     * A mano porque la extensión `soap` no está instalada en el servidor, y
     * porque estos servicios son de sobre plano: un método con parámetros de
     * texto. Montar un cliente SOAP completo para esto no compra nada.
     */
    private function soap(string $url, string $metodo, array $parametros = []): string
    {
        $cuerpo = '';

        foreach ($parametros as $nombre => $valor) {
            $cuerpo .= '<'.$nombre.'>'.$this->escapa((string) $valor).'</'.$nombre.'>';
        }

        $sobre = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soapenv:Body><'.$metodo.'>'.$cuerpo.'</'.$metodo.'></soapenv:Body>'
            .'</soapenv:Envelope>';

        return $this->pide($url, $sobre, [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: ""',
        ]);
    }

    private function pide(string $url, string $cuerpo, array $cabeceras): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $cuerpo,
            CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_USERAGENT => self::AGENTE,
            CURLOPT_TIMEOUT => self::ESPERA,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $respuesta = curl_exec($ch);
        $error = curl_error($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($respuesta === false) {
            throw new RuntimeException("No se pudo hablar con el SII ({$url}): {$error}");
        }

        // Lo que contesta el SII no siempre es UTF-8: los `.jws` declaran
        // `ISO-8859-1` y sus glosas llevan acentos —«Envío Aceptado Conforme»—,
        // así que una «í» viene en un byte. Se normaliza aquí, que es por donde
        // entra todo, y no en cada sitio que lee un elemento: aguas abajo esa
        // glosa acaba en `dte_doccab.Motivo` —donde el controlador ODBC la
        // rechazaría igual que rechazó el XML de la factura 238— y en una
        // respuesta JSON, que no admite otra cosa.
        $respuesta = Texto::cadena((string) $respuesta);

        if ($codigo >= 400) {
            throw new RuntimeException("El SII contestó {$codigo} en {$url}: ".$this->resumenDe($respuesta));
        }

        return $respuesta;
    }

    /**
     * El valor de un elemento, venga como XML o escapado dentro de otro.
     *
     * Estos servicios devuelven un XML **dentro** del cuerpo SOAP, escapado como
     * texto: `&lt;SEMILLA&gt;123&lt;/SEMILLA&gt;`. Desescapar primero y buscar
     * después sirve para los dos casos y evita anidar dos parseos.
     */
    private function dentro(string $xml, string $elemento): ?string
    {
        $plano = html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return preg_match('#<'.$elemento.'>(.*?)</'.$elemento.'>#s', $plano, $m)
            ? trim($m[1])
            : null;
    }

    /** Lo que se le enseña a una persona cuando algo salió mal. */
    private function resumenDe(string $respuesta): string
    {
        $plano = html_entity_decode($respuesta, ENT_QUOTES | ENT_XML1, 'UTF-8');

        foreach (['faultstring', 'GLOSA', 'DETAIL', 'ESTADO'] as $elemento) {
            if (preg_match('#<'.$elemento.'>(.*?)</'.$elemento.'>#s', $plano, $m)) {
                return trim($m[1]);
            }
        }

        return trim(mb_substr(strip_tags($plano), 0, 300));
    }

    private function multiparte(array $campos, string $nombreCampo, string $archivo, string $contenido, ?string &$frontera): string
    {
        $frontera = '----VentaSoftland'.bin2hex(random_bytes(8));
        $cuerpo = '';

        foreach ($campos as $nombre => $valor) {
            $cuerpo .= "--{$frontera}\r\n"
                ."Content-Disposition: form-data; name=\"{$nombre}\"\r\n\r\n"
                ."{$valor}\r\n";
        }

        return $cuerpo
            ."--{$frontera}\r\n"
            ."Content-Disposition: form-data; name=\"{$nombreCampo}\"; filename=\"{$archivo}\"\r\n"
            ."Content-Type: text/xml\r\n\r\n"
            .$contenido."\r\n"
            ."--{$frontera}--\r\n";
    }

    /**
     * El RUT partido en número y dígito verificador, que es como lo quieren
     * todos los servicios del SII: nunca en un solo campo.
     *
     * @return array{0:string, 1:string}
     */
    public static function parteRut(string $rut): array
    {
        $limpio = strtoupper(preg_replace('/[^0-9kK]/', '', $rut) ?? '');

        if (strlen($limpio) < 2) {
            throw new RuntimeException("RUT ilegible: «{$rut}».");
        }

        return [substr($limpio, 0, -1), substr($limpio, -1)];
    }

    private function escapa(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function entero(?string $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }
}
