<?php

namespace App\Services\Dte;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Emitir: armar el sobre, mandarlo al SII y dejar constancia.
 *
 * Es el único sitio de la app donde ocurre algo **irreversible de verdad**. Un
 * documento mal escrito en `iw_gsaen` se corrige; un folio enviado al SII ya
 * está enviado, y si viene mal el arreglo es una nota de crédito, no un
 * `UPDATE`.
 *
 * ## El orden, y por qué es ese
 *
 * 1. se arma y se firma el sobre **antes** de hablar con el SII: si falta un
 *    dato, se sabe aquí y no a medio envío;
 * 2. se manda;
 * 3. **recién entonces** se escribe en la base.
 *
 * El paso 3 va después a propósito. Si se guardara antes y el envío fallara,
 * quedaría un documento marcado como enviado que no lo está. Al revés el riesgo
 * es el contrario y es el menos malo: un envío hecho cuya constancia no se pudo
 * guardar. Para eso está el `TrackID` en el mensaje de error — con él se
 * recupera a mano, y por eso el error lo lleva escrito.
 *
 * ## Lo que se guarda
 *
 *   - `dte_archivos` — el XML del documento (`D`) y el del sobre (`SS`), que es
 *     de donde salen las copias que se le mandan al cliente y la evidencia
 *     contra la que se comprueba todo esto.
 *   - `dte_doccab` — el seguimiento: `TrackID`, `IDSetDTESII`, las marcas de
 *     enviado, y el `FirmaDTE`, que es el timbre que el ERP imprime como código
 *     de barras.
 *
 * Lo que **no** se guarda todavía es el espejo completo del documento en
 * `dte_doccab`/`dte_docdet` —las setenta columnas que replican el XML—. El SII
 * no lo necesita y la app no lo lee; hace falta para las ventanas de DTE del
 * Softland de escritorio, y está anotado como pendiente.
 */
class Emision
{
    private const CONN = 'softland';

    public function __construct(
        private readonly Certificado $cert,
        private readonly ?string $ambiente = null,
        private readonly ?string $base = null,
    ) {}

    /**
     * Emite un documento ya escrito en inventario y facturación.
     *
     * @return array{trackId:string, folio:int, id:string, sobre:string, documento:string, estado:string, glosa:string}
     */
    public function emitir(string $tipoSoftland, int $nroInt): array
    {
        $cab = $this->cabecera($tipoSoftland, $nroInt);
        $tipo = TipoDte::desdeSoftland($cab->Tipo, $cab->SubTipoDocto)
            ?? throw new RuntimeException("El documento {$tipoSoftland}/{$nroInt} no es electrónico.");

        if ($tipo->porApiRest()) {
            throw new RuntimeException(
                "La {$tipo->nombre()} no se envía por aquí: va por la API REST del SII, que es otra integración."
            );
        }

        if ($this->cert->vencido()) {
            throw new RuntimeException((string) $this->cert->avisaVencimiento());
        }

        $yaEnviado = $this->seguimiento($tipo, (int) $cab->Folio);

        if ($yaEnviado !== null) {
            throw new RuntimeException(
                "El folio {$cab->Folio} ya se envió al SII (TrackID {$yaEnviado}). Un folio no se manda dos veces."
            );
        }

        $documento = $this->preparar($cab, $tipo);

        return $this->despachar($cab, $tipo, $documento);
    }

    /**
     * Timbrar, firmar y **dejarlo guardado**, sin mandarlo todavía.
     *
     * Este paso existe separado del envío por una razón que no se ve hasta que
     * algo falla: **el timbre lleva dentro la hora exacta en que se timbró**, y
     * el código de barras del papel tiene que decir exactamente lo mismo que el
     * XML que recibió el SII. Si el envío falla y mañana se vuelve a generar el
     * documento, sale otro timbre — y el papel que ya se imprimió deja de
     * corresponder.
     *
     * Así que se genera una vez, se guarda, y de ahí en adelante se reusa: para
     * reintentar el envío y para imprimir. Un documento preparado y no enviado
     * es un documento que existe, con su folio gastado, esperando viajar.
     *
     * @return string el XML del documento, el guardado o el recién hecho
     */
    public function preparar(object $cab, TipoDte $tipo): string
    {
        if ($guardado = $this->documentoGuardado($cab, $tipo)) {
            return $guardado;
        }

        $documento = (new Documento($this->base))->armar($cab->Tipo, (int) $cab->NroInt, $this->cert);

        $this->guardarDocumento($cab, $tipo, (int) $cab->Folio, $documento);

        return $documento;
    }

    /**
     * Meterlo en el sobre, mandarlo y anotar el TrackID.
     *
     * El sobre se arma **alrededor del documento ya guardado**, no se vuelve a
     * generar el documento: el sobre es envoltorio —su firma cubre el conjunto y
     * su marca de tiempo da igual— y el documento es lo que el SII saca de
     * dentro y valida por separado.
     *
     * @return array{trackId:string, folio:int, id:string, sobre:string, documento:string, estado:string, glosa:string}
     */
    public function despachar(object $cab, TipoDte $tipo, string $documento): array
    {
        $folio = (int) $cab->Folio;
        $rutEmisor = $this->rutEmisor();

        $sobre = (new Sobre($this->cert, $this->base))->armar([[
            'tipo' => $cab->Tipo,
            'nroInt' => (int) $cab->NroInt,
            'xml' => $documento,
        ]]);

        $sii = new Sii($this->cert, $this->ambiente);
        $respuesta = $sii->enviar($sobre['xml'], $rutEmisor, $this->nombreArchivo($rutEmisor, $tipo, $folio, 'S'));

        try {
            $this->guardarEnvio($cab, $tipo, $folio, $rutEmisor, $sobre, $respuesta['trackId']);
        } catch (Throwable $e) {
            // El envío ya ocurrió. Decirlo con el TrackID delante es la
            // diferencia entre recuperarlo a mano y volver a mandar el folio.
            throw new RuntimeException(
                "El SII aceptó el envío con TrackID {$respuesta['trackId']}, pero no se pudo dejar constancia "
                ."en la base: {$e->getMessage()}. **No reenviar**: el folio {$folio} ya está en el SII.",
                previous: $e
            );
        }

        return [
            'trackId' => $respuesta['trackId'],
            'folio' => $folio,
            'id' => $sobre['id'],
            'sobre' => $sobre['xml'],
            'documento' => $documento,
            'estado' => $respuesta['estado'],
            'glosa' => $respuesta['glosa'],
        ];
    }

    /** El XML del documento que ya se generó, si se generó. */
    public function documentoGuardado(object $cab, TipoDte $tipo): ?string
    {
        $xml = DB::connection(self::CONN)->table($this->califica('dte_archivos'))
            ->where('Tipo', $cab->Tipo)->where('NroInt', (int) $cab->NroInt)
            ->where('TipoXML', 'D')->where('TipoDTE', $tipo->value)
            ->orderByDesc('FechaGenDTE')
            ->value('Archivo');

        $xml = trim((string) $xml);

        // A los bytes que se firmaron. Reintentar un envío con la copia en
        // caracteres mandaría al SII un documento cuya firma ya no cubre su
        // propio texto: un acento que pasó de un byte a dos.
        return $xml === '' ? null : Codificacion::desdeLaBase($xml);
    }

    /** La cabecera de un documento de inventario, para quien la necesite fuera. */
    public function documentoDe(string $tipoSoftland, int $nroInt): object
    {
        return $this->cabecera($tipoSoftland, $nroInt);
    }

    /** El `TrackID` de un folio ya enviado, o null si no se ha mandado. */
    public function seguimiento(TipoDte $tipo, int $folio): ?string
    {
        $fila = DB::connection(self::CONN)->table($this->califica('dte_doccab'))
            ->where('TipoDTE', $tipo->value)->where('Folio', $folio)
            ->first();

        $track = trim((string) ($fila->TrackID ?? ''));

        return $track === '' || $track === '0' ? null : $track;
    }

    /**
     * Deja guardado el documento timbrado, antes de que viaje.
     *
     * Escribe el XML en `dte_archivos` y el timbre en `dte_doccab.FirmaDTE`,
     * que es de donde el ERP —y nuestro PDF— sacan el código de barras. Lo que
     * **no** escribe es el TrackID ni `EnviadoSII`: eso es del envío, y todavía
     * no ocurrió. Un documento con timbre y sin TrackID es exactamente lo que
     * se ve en rojo en la lista.
     */
    private function guardarDocumento(object $cab, TipoDte $tipo, int $folio, string $documento): void
    {
        DB::connection(self::CONN)->transaction(function () use ($cab, $tipo, $folio, $documento) {
            $rutEmisor = $this->rutEmisor();
            $ahora = date('Y-m-d H:i:s');
            $nombre = $this->nombreArchivo($rutEmisor, $tipo, $folio, 'D');

            $idDocumento = $this->archivo($cab, $tipo, $folio, 'D', $nombre, $documento);

            $datos = [
                'Tipo' => $cab->Tipo,
                'NroInt' => (int) $cab->NroInt,
                'FechaGenDTE' => $ahora,
                'Archivo' => $nombre,
                'IDXMLDoc' => $idDocumento,
                // `Proceso` **no se escribe**: es `varchar(10)` —«Venta
                // Softland» no cabe— y está en NULL en las 4.798 filas de las
                // dos empresas. El ERP no la usa. Escribirla reventaba el
                // primer envío de verdad, que es donde este camino se estrena.
            ];

            if ($timbre = Timbre::extraer($documento)) {
                // El ERP imprime el código de barras desde aquí, no desde el XML.
                // Recodificado por lo mismo que el archivo, y con la misma
                // salvedad: al imprimirlo se devuelve a ISO-8859-1, porque el
                // PDF417 lleva bytes y tiene que decir lo mismo que el XML.
                $datos['FirmaDTE'] = Codificacion::paraLaBase(
                    '<TED version="1.0">'.$timbre[0]
                    .'<FRMT algoritmo="SHA1withRSA">'.$timbre[1].'</FRMT></TED>'
                );
            }

            $this->escribirSeguimiento($cab, $tipo, $folio, $rutEmisor, $datos);
        });
    }

    /**
     * Deja constancia del envío.
     *
     * Todo dentro de una transacción: o queda el XML del sobre y el
     * seguimiento, o no queda nada. Media constancia es peor que ninguna,
     * porque se lee como si estuviera completa.
     */
    private function guardarEnvio(object $cab, TipoDte $tipo, int $folio, string $rutEmisor, array $sobre, string $trackId): void
    {
        DB::connection(self::CONN)->transaction(function () use ($cab, $tipo, $folio, $rutEmisor, $sobre, $trackId) {
            $ahora = date('Y-m-d H:i:s');

            $this->archivo($cab, $tipo, $folio, 'SS', $this->nombreArchivo($rutEmisor, $tipo, $folio, 'S'), $sobre['xml']);

            $this->escribirSeguimiento($cab, $tipo, $folio, $rutEmisor, [
                'Tipo' => $cab->Tipo,
                'NroInt' => (int) $cab->NroInt,
                'IDSetDTESII' => $sobre['id'],
                'TrackID' => $trackId,
                'EnviadoSII' => 1,
                'FechaEnvioSII' => $ahora,
            ]);
        });
    }

    /**
     * Anota el veredicto del SII en la fila del documento.
     *
     * Lo escribe quien pregunta —la ficha o la tarea que barre lo pendiente—,
     * porque hasta que alguien pregunta, no se sabe: el SII no avisa.
     */
    public function anotarVeredicto(TipoDte $tipo, int $folio, bool $aceptado, ?string $motivo): void
    {
        DB::connection(self::CONN)->table($this->califica('dte_doccab'))
            ->where('RUTEmisor', $this->rutEmisor())
            ->where('TipoDTE', $tipo->value)
            ->where('Folio', $folio)
            ->update([
                'AceptadoSII' => $aceptado ? 1 : 0,
                // El motivo sólo tiene sentido cuando no se aceptó: guardarlo
                // con la aceptación dejaría la fila diciendo dos cosas.
                'Motivo' => $aceptado ? null : $this->recorta($motivo),
            ]);
    }

    private function recorta(?string $texto, int $largo = 250): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : mb_substr($texto, 0, $largo);
    }

    /**
     * Escribe en `dte_doccab`, actualizando o creando según haga falta.
     *
     * Lo normal es actualizar: el repartidor de folios deja la fila al entregar
     * el número. Insertar es el caso raro, y está para que el folio no quede
     * huérfano si algún día no la dejara.
     */
    private function escribirSeguimiento(object $cab, TipoDte $tipo, int $folio, string $rutEmisor, array $datos): void
    {
        $tabla = DB::connection(self::CONN)->table($this->califica('dte_doccab'))
            ->where('RUTEmisor', $rutEmisor)
            ->where('TipoDTE', $tipo->value)
            ->where('Folio', $folio);

        if ($tabla->exists()) {
            $tabla->update($datos);

            return;
        }

        DB::connection(self::CONN)->table($this->califica('dte_doccab'))->insert($datos + [
            'RUTEmisor' => $rutEmisor,
            'TipoDTE' => $tipo->value,
            'Folio' => $folio,
            'FchEmis' => $cab->Fecha ?? date('Y-m-d H:i:s'),
            'RUTRecep' => null,
        ]);
    }

    /**
     * Guarda un XML en `dte_archivos` y devuelve su identificador.
     *
     * El XML va **recodificado** (`Codificacion::paraLaBase`). La columna es
     * `ntext` y el controlador ODBC traduce asumiendo UTF-8: los bytes
     * ISO-8859-1 del DTE lo hacen abortar la escritura entera, que es lo que
     * dejó la factura 238 escrita en inventario y sin timbrar. Lo que viaja al
     * SII sigue siendo lo que se firmó; aquí sólo cambia la forma de guardarlo,
     * que es además la que usa el Softland de escritorio.
     */
    private function archivo(object $cab, TipoDte $tipo, int $folio, string $tipoXml, string $nombre, string $xml): int
    {
        $tabla = $this->califica('dte_archivos');

        DB::connection(self::CONN)->table($tabla)->insert([
            'Extension' => pathinfo($nombre, PATHINFO_EXTENSION),
            'Archivo' => Codificacion::paraLaBase($xml),
            'Tipo' => $cab->Tipo,
            'NroInt' => (int) $cab->NroInt,
            'Folio' => $folio,
            'TipoXML' => $tipoXml,
            'TipoDTE' => $tipo->value,
            'FechaGenDTE' => date('Y-m-d H:i:s'),
        ]);

        return (int) DB::connection(self::CONN)->getPdo()->lastInsertId();
    }

    /**
     * El nombre del archivo, como lo arma Softland: `D` o `S` según sea el
     * documento o el sobre, el RUT, el tipo a tres dígitos y el folio a diez.
     */
    private function nombreArchivo(string $rutEmisor, TipoDte $tipo, int $folio, string $prefijo): string
    {
        return $prefijo.$rutEmisor.$prefijo.str_pad((string) $tipo->value, 3, '0', STR_PAD_LEFT)
            .'F'.str_pad((string) $folio, 10, '0', STR_PAD_LEFT).'.xml';
    }

    private function rutEmisor(): string
    {
        $emisor = DB::connection(self::CONN)->table($this->califica('soempre'))->first();

        return strtoupper(trim((string) ($emisor->RutEmisor ?? '')));
    }

    private function cabecera(string $tipoSoftland, int $nroInt): object
    {
        $fila = DB::connection(self::CONN)->table($this->califica('iw_gsaen'))
            ->where('Tipo', $tipoSoftland)->where('NroInt', $nroInt)->first();

        return $fila ?? throw new RuntimeException("No está el documento {$tipoSoftland}/{$nroInt} en iw_gsaen.");
    }

    private function califica(string $objeto): string
    {
        return $this->base ? "{$this->base}.softland.{$objeto}" : "softland.{$objeto}";
    }
}
