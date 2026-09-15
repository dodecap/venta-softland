<?php

namespace App\Services\Dte;

use RuntimeException;

/**
 * El timbre electrónico: el `<TED>` que va dentro del DTE y que después se
 * imprime como código de barras PDF417 en el papel.
 *
 * Existe para que un fiscalizador en la carretera, **sin conexión**, pueda
 * comprobar que ese papel corresponde a un folio realmente autorizado. Por eso
 * el timbre lleva el CAF incrustado: trae consigo su propia prueba.
 *
 * ## Se firman bytes, no un árbol
 *
 * Esta es la trampa de todo el asunto, y la razón de que aquí se arme el XML a
 * mano en vez de con `DOMDocument`. La firma del `<FRMT>` cubre el elemento
 * `<DD>` **tal como aparece escrito en el archivo**: los mismos bytes, en el
 * mismo orden, con la misma codificación y sin un espacio de más. Un
 * serializador que reordene un atributo, cambie comillas simples por dobles o
 * agregue saltos de línea para que se lea bonito produce un documento que el
 * SII rechaza, aunque el contenido sea idéntico.
 *
 * Por eso:
 *
 *   - el `<DD>` se concatena, no se genera;
 *   - el `<CAF>` se incrusta crudo, como vino del SII (ver `Caf::xml()`);
 *   - todo se pasa a **ISO-8859-1** antes de firmar, que es la codificación
 *     que declara el XML del SII. Firmar el acento en UTF-8 y escribirlo en
 *     ISO-8859-1 da una firma que no valida, y el error aparece recién cuando
 *     el SII contesta.
 *
 * ## Los recortes son del estándar
 *
 * `RSR` e `IT1` van a 40 caracteres. No es una preferencia: es el largo máximo
 * que fija el SII, y pasarse es motivo de rechazo.
 */
class Timbre
{
    private const LARGO_RAZON_SOCIAL = 40;

    private const LARGO_ITEM = 40;

    public function __construct(private readonly Caf $caf) {}

    /**
     * Arma el `<TED>` completo y firmado.
     *
     * @param  string  $rutEmisor   RUT de la empresa, `NNNNNNNN-D`
     * @param  int  $folio          el folio que se está gastando
     * @param  string  $fecha       fecha de emisión, `AAAA-MM-DD`
     * @param  string  $rutReceptor RUT del cliente; en boleta sin cliente, `66666666-6`
     * @param  string  $razonSocial razón social del cliente
     * @param  int  $monto          el total del documento, entero y sin signo
     * @param  string  $primerItem  nombre del primer producto de la primera línea
     * @param  string|null  $sello   marca de tiempo `AAAA-MM-DDTHH:MM:SS`; si no se
     *                               da, se usa la de ahora. **No se deriva de los
     *                               datos**: es el instante en que se timbra.
     */
    public function armar(
        string $rutEmisor,
        int $folio,
        string $fecha,
        string $rutReceptor,
        string $razonSocial,
        int $monto,
        string $primerItem,
        ?string $sello = null,
    ): string {
        $dd = $this->datos($rutEmisor, $folio, $fecha, $rutReceptor, $razonSocial, $monto, $primerItem, $sello);
        $frmt = $this->caf->firmar($dd);

        return '<TED version="1.0">'.$dd.'<FRMT algoritmo="SHA1withRSA">'.$frmt.'</FRMT></TED>';
    }

    /**
     * El bloque `<DD>`, en bytes ISO-8859-1, listo para firmar o para incrustar.
     *
     * Lo que sale de aquí es exactamente lo que se firma. Si esta cadena cambia
     * en un byte, la firma cambia entera.
     */
    public function datos(
        string $rutEmisor,
        int $folio,
        string $fecha,
        string $rutReceptor,
        string $razonSocial,
        int $monto,
        string $primerItem,
        ?string $sello = null,
    ): string {
        if (! $this->caf->cubre($folio)) {
            throw new RuntimeException(
                "El folio {$folio} no cae en el CAF {$this->caf->rango()}: timbrarlo sería gastar el número para nada."
            );
        }

        $sello ??= date('Y-m-d\TH:i:s');

        return '<DD>'
            .'<RE>'.$this->texto($this->rut($rutEmisor)).'</RE>'
            .'<TD>'.$this->caf->tipo->value.'</TD>'
            .'<F>'.$folio.'</F>'
            .'<FE>'.$this->texto(substr($fecha, 0, 10)).'</FE>'
            .'<RR>'.$this->texto($this->rut($rutReceptor)).'</RR>'
            .'<RSR>'.$this->texto($this->recorta($razonSocial, self::LARGO_RAZON_SOCIAL)).'</RSR>'
            // Sin signo, siempre. En `iw_gsaen` el total de una nota de crédito
            // es negativo, pero en el timbre va positivo: lo que resta o suma lo
            // dice el tipo de documento, no el número. Las 12 notas de crédito
            // de INNOVAGES lo confirman.
            .'<MNT>'.abs($monto).'</MNT>'
            .'<IT1>'.$this->texto($this->recorta($primerItem, self::LARGO_ITEM)).'</IT1>'
            .$this->iso($this->caf->xml())
            .'<TSTED>'.$this->texto($sello).'</TSTED>'
            .'</DD>';
    }

    /** Comprueba un `<DD>` contra su `<FRMT>`, con la llave pública del propio CAF. */
    public function verificar(string $dd, string $frmt): bool
    {
        return $this->caf->verificar($dd, $frmt);
    }

    /**
     * Saca el `<DD>` y el `<FRMT>` de un DTE ya escrito, sin parsearlo.
     *
     * A propósito por posición de texto: hay que recuperar **los bytes que se
     * firmaron**, y un parser devolvería una versión re-serializada que ya no
     * es la misma cadena.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function extraer(string $xml): ?array
    {
        $i = strpos($xml, '<DD>');
        $j = strpos($xml, '</DD>');

        if ($i === false || $j === false) {
            return null;
        }

        $dd = substr($xml, $i, $j - $i + strlen('</DD>'));

        if (! preg_match('#<FRMT[^>]*>(.*?)</FRMT>#s', $xml, $m)) {
            return null;
        }

        return [$dd, trim($m[1])];
    }

    /** RUT sin puntos y con la K en mayúscula, que es como lo quiere el SII. */
    private function rut(string $rut): string
    {
        return strtoupper(str_replace(['.', ' '], '', trim($rut)));
    }

    /** Recorta por caracteres, no por bytes: con acentos no es lo mismo. */
    private function recorta(string $valor, int $largo): string
    {
        return mb_substr(trim($valor), 0, $largo);
    }

    /** Texto de un elemento: escapado como XML y pasado a ISO-8859-1. */
    private function texto(string $valor): string
    {
        return $this->iso(str_replace(
            ['&', '<', '>'],
            ['&amp;', '&lt;', '&gt;'],
            $valor
        ));
    }

    /**
     * A ISO-8859-1, que es lo que declara el XML del SII.
     *
     * Si ya viene en ISO-8859-1 —como el `<CAF>` guardado— se deja quieto:
     * convertir dos veces rompe los acentos.
     */
    private function iso(string $valor): string
    {
        if (! mb_check_encoding($valor, 'UTF-8')) {
            return $valor;
        }

        return mb_convert_encoding($valor, 'ISO-8859-1', 'UTF-8');
    }
}
