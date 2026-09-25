<?php

namespace App\Services\Dte;

/**
 * La frontera entre los bytes del DTE y los caracteres de la base.
 *
 * ## Por qué hacen falta dos formas de lo mismo
 *
 * El DTE va en **ISO-8859-1** y no es una preferencia: la firma cubre los bytes
 * del `<DD>` tal como están escritos, así que una «ó» tiene que ocupar un byte
 * —`0xF3`— y no dos. Eso lo decide `Documento` y no se toca.
 *
 * La base guarda otra cosa. `dte_archivos.Archivo` es **ntext**, que es
 * Unicode, y `dte_doccab.FirmaDTE` es `varchar` con intercalación Latin1; el
 * controlador ODBC traduce lo que le mandamos asumiendo que viene en UTF-8. Un
 * `0xF3` suelto no es UTF-8 válido, así que el controlador **rechaza la
 * escritura entera**:
 *
 *     SQLSTATE[IMSSP]: An error occurred translating string for input param 2
 *     to UCS-2: No hay ninguna asignación en la página de códigos…
 *
 * Eso es lo que impidió emitir la factura 238 el 25-09-2026: la primera de la
 * app cuya glosa llevaba una vocal acentuada —«Distribución»—. Las tres
 * anteriores sólo tenían `N°`, cuyo `0xB0` el controlador sí traga, y por eso
 * el camino parecía bueno.
 *
 * ## Qué hace Softland, que es lo que se copia
 *
 * Mirando los XML que escribió el Softland de escritorio —folios 178, 179, 180
 * y 187 de INNOVAGES— la respuesta está clara: guarda **caracteres**, no bytes.
 * La «ó» del folio 179 y la «Ñ» del 187 vuelven de la base como `C3 B3` y
 * `C3 91`, aunque el propio XML declare `encoding="ISO-8859-1"`. Esa
 * declaración describe el archivo que viaja al SII, no la columna.
 *
 * `dte:verifica-timbre` ya había dado con el mismo hecho desde el otro lado, y
 * lo dejó escrito: «la copia archivada se volvió a codificar a UTF-8 en algún
 * punto del camino a la base, y el DTE se firmó en ISO-8859-1». Lo que allí es
 * un síntoma que se tolera al leer documentos ajenos, aquí es la regla al
 * escribir los propios.
 *
 * ## La regla
 *
 * **Lo que viaja al SII va en ISO-8859-1; lo que se guarda en la base va en
 * caracteres.** La conversión ocurre en esta clase y en ningún otro sitio, y es
 * exacta en los dos sentidos: ISO-8859-1 es un subconjunto de Unicode con
 * correspondencia uno a uno, así que el XML que se recupera de la base para
 * reintentar un envío, y el timbre que se reimprime meses después, son byte a
 * byte los que se firmaron.
 */
final class Codificacion
{
    /**
     * Los bytes del DTE, listos para una columna de la base.
     *
     * Se comprueba antes de convertir porque esto se llama también sobre textos
     * que ya son UTF-8 —los nombres de archivo, y lo que venga de otro camino—,
     * y convertir dos veces sí estropea: la «ó» pasaría a «Ã³».
     */
    public static function paraLaBase(string $texto): string
    {
        if ($texto === '' || mb_check_encoding($texto, 'UTF-8')) {
            return $texto;
        }

        return mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Lo guardado en la base, devuelto a los bytes que se firmaron.
     *
     * Vale para el XML que se recupera para reintentar un envío y para el TED
     * que se reimprime: el PDF417 del papel tiene que decir exactamente lo
     * mismo que el XML que recibió el SII, y eso son bytes, no letras.
     */
    public static function desdeLaBase(string $texto): string
    {
        if ($texto === '' || ! mb_check_encoding($texto, 'UTF-8')) {
            return $texto;
        }

        return mb_convert_encoding($texto, 'ISO-8859-1', 'UTF-8');
    }
}
