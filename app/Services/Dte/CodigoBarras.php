<?php

namespace App\Services\Dte;

use Com\Tecnick\Barcode\Barcode;
use RuntimeException;
use Throwable;

/**
 * El timbre impreso: el TED convertido en código de barras PDF417.
 *
 * Es la parte del papel que de verdad importa. El texto de una factura lo puede
 * escribir cualquiera; lo que la acredita es este rectángulo, porque lleva
 * dentro el TED firmado con la llave del CAF. Un fiscalizador con un lector lo
 * saca de aquí y lo comprueba contra el SII.
 *
 * ## Lo que no se puede improvisar
 *
 * **El contenido va tal cual.** El TED se firmó en bytes; cualquier cosa que le
 * pase encima —reordenar atributos, cambiar la codificación, quitar un espacio—
 * lo invalida. Por eso se toma de `dte_doccab.FirmaDTE`, que es donde quedó
 * guardado el que se mandó, y no se vuelve a generar.
 *
 * **Nivel de corrección 5**, que es el que pide el SII. Más bajo cabe en menos
 * espacio y se vuelve ilegible en una fotocopia; más alto engorda el rectángulo
 * sin que nadie lo agradezca.
 *
 * ## Cómo se comprobó
 *
 * Generando el timbre de la factura 234 —que Softland ya había impreso— y
 * leyéndolo con un decodificador: devuelve los mismos 777 bytes que el código
 * de barras del papel de Softland. No es que se parezca: dice lo mismo.
 */
class CodigoBarras
{
    /** Lo que pide el SII: PDF417 con nivel de corrección de errores 5. */
    private const TIPO = 'PDF417,,5';

    /**
     * Cuántos puntos mide cada módulo.
     *
     * En negativo, la librería lo lee como «píxeles por módulo». Con 3 el
     * rectángulo sale de unos 6 cm de ancho al imprimirlo, dentro de lo que
     * el SII admite, y aguanta una fotocopia.
     */
    private const ESCALA = 3;

    /**
     * El margen en blanco alrededor, en módulos.
     *
     * No es estética: un PDF417 sin zona de silencio no se lee. Lo comprobamos
     * a la primera — el decodificador no encontraba nada hasta que apareció
     * este margen.
     */
    private const SILENCIO = 4;

    /** El timbre como `data:` URI, listo para meter en un `<img>` del PDF. */
    public static function timbre(string $ted): string
    {
        return 'data:image/png;base64,'.base64_encode(self::png($ted));
    }

    public static function png(string $ted): string
    {
        $ted = trim($ted);

        if ($ted === '') {
            throw new RuntimeException('No hay timbre que dibujar: el documento no tiene TED guardado.');
        }

        try {
            return (new Barcode)
                ->getBarcodeObj(
                    self::TIPO,
                    $ted,
                    -self::ESCALA,
                    -self::ESCALA,
                    'black',
                    array_fill(0, 4, self::SILENCIO),
                )
                ->getPngData();
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo dibujar el timbre: '.$e->getMessage(), previous: $e);
        }
    }
}
