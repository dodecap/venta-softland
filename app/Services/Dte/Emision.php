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

        $sobre = (new Sobre($this->cert, $this->base))
            ->armar([['tipo' => $tipoSoftland, 'nroInt' => $nroInt]]);

        $documento = (new Documento($this->base))->armar($tipoSoftland, $nroInt, $this->cert);
        $folio = (int) $cab->Folio;
        $rutEmisor = $this->rutEmisor();

        $sii = new Sii($this->cert, $this->ambiente);
        $respuesta = $sii->enviar($sobre['xml'], $rutEmisor, $this->nombreArchivo($rutEmisor, $tipo, $folio, 'S'));

        try {
            $this->guardar($cab, $tipo, $folio, $rutEmisor, $documento, $sobre, $respuesta['trackId']);
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
     * Deja constancia del envío.
     *
     * Todo dentro de una transacción: o queda el XML y el seguimiento, o no
     * queda nada. Media constancia es peor que ninguna, porque se lee como si
     * estuviera completa.
     */
    private function guardar(object $cab, TipoDte $tipo, int $folio, string $rutEmisor, string $documento, array $sobre, string $trackId): void
    {
        DB::connection(self::CONN)->transaction(function () use ($cab, $tipo, $folio, $rutEmisor, $documento, $sobre, $trackId) {
            $ahora = date('Y-m-d H:i:s');

            $idDocumento = $this->archivo($cab, $tipo, $folio, 'D', $this->nombreArchivo($rutEmisor, $tipo, $folio, 'D'), $documento);
            $this->archivo($cab, $tipo, $folio, 'SS', $this->nombreArchivo($rutEmisor, $tipo, $folio, 'S'), $sobre['xml']);

            $timbre = Timbre::extraer($documento);

            $datos = [
                'Tipo' => $cab->Tipo,
                'NroInt' => (int) $cab->NroInt,
                'IDSetDTESII' => $sobre['id'],
                'TrackID' => $trackId,
                'EnviadoSII' => 1,
                'FechaEnvioSII' => $ahora,
                'FechaGenDTE' => $ahora,
                'Archivo' => $this->nombreArchivo($rutEmisor, $tipo, $folio, 'D'),
                'IDXMLDoc' => $idDocumento,
                'Proceso' => 'Venta Softland',
            ];

            if ($timbre !== null) {
                // El ERP imprime el código de barras desde aquí, no desde el XML.
                $datos['FirmaDTE'] = '<TED version="1.0">'.$timbre[0]
                    .'<FRMT algoritmo="SHA1withRSA">'.$timbre[1].'</FRMT></TED>';
            }

            $tabla = DB::connection(self::CONN)->table($this->califica('dte_doccab'))
                ->where('RUTEmisor', $rutEmisor)
                ->where('TipoDTE', $tipo->value)
                ->where('Folio', $folio);

            if ($tabla->exists()) {
                $tabla->update($datos);

                return;
            }

            // No debería pasar: el repartidor de folios deja la fila al
            // entregarlo. Si no está, se crea con lo mínimo para que el folio no
            // quede huérfano.
            DB::connection(self::CONN)->table($this->califica('dte_doccab'))->insert($datos + [
                'RUTEmisor' => $rutEmisor,
                'TipoDTE' => $tipo->value,
                'Folio' => $folio,
                'FchEmis' => $cab->Fecha ?? $ahora,
                'RUTRecep' => null,
            ]);
        });
    }

    /** Guarda un XML en `dte_archivos` y devuelve su identificador. */
    private function archivo(object $cab, TipoDte $tipo, int $folio, string $tipoXml, string $nombre, string $xml): int
    {
        $tabla = $this->califica('dte_archivos');

        DB::connection(self::CONN)->table($tabla)->insert([
            'Extension' => pathinfo($nombre, PATHINFO_EXTENSION),
            'Archivo' => $xml,
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
