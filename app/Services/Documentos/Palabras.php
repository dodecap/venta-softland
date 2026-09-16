<?php

namespace App\Services\Documentos;

/**
 * El «Son:» del papel: un monto escrito con letras.
 *
 * Va en las facturas desde mucho antes de que existieran los ordenadores, y
 * sigue: es el resguardo contra el dígito cambiado. Si el número dice 262.469 y
 * la letra dice doscientos sesenta y dos mil cuatrocientos sesenta y nueve, una
 * corrección a mano se nota.
 *
 * Se escribe **en pesos enteros**, sin centavos, porque así se factura en Chile
 * y así lo guarda Softland.
 *
 * Las trampas del castellano, que son las que hacen que esto no sea un bucle de
 * tres líneas: «uno» se apocopa en «un» delante de mil —«veintiún mil», no
 * «veintiuno mil»—; del 16 al 29 se escribe junto —«dieciséis», «veintitrés»—;
 * «ciento» es «cien» sólo cuando va solo; y el millón lleva plural y el mil no
 * —«dos millones», pero «dos mil»—.
 */
class Palabras
{
    private const UNIDADES = [
        '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
        'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete',
        'dieciocho', 'diecinueve', 'veinte', 'veintiuno', 'veintidós', 'veintitrés',
        'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
    ];

    private const DECENAS = [
        3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta',
        7 => 'setenta', 8 => 'ochenta', 9 => 'noventa',
    ];

    private const CENTENAS = [
        1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos',
        5 => 'quinientos', 6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos',
        9 => 'novecientos',
    ];

    /**
     * El monto en letras, en mayúsculas, como va en el papel.
     *
     * El negativo se dice: una nota de crédito por −262.469 no puede salir
     * escrita igual que la factura que anula.
     */
    public static function monto(float $valor, string $moneda = 'PESOS'): string
    {
        $entero = (int) round(abs($valor));
        $signo = $valor < 0 ? 'MENOS ' : '';

        return mb_strtoupper($signo.self::apocopar(self::numero($entero)).' '.$moneda, 'UTF-8');
    }

    public static function numero(int $n): string
    {
        if ($n === 0) {
            return 'cero';
        }

        if ($n < 0) {
            return 'menos '.self::numero(-$n);
        }

        if ($n >= 1_000_000) {
            $millones = intdiv($n, 1_000_000);
            $resto = $n % 1_000_000;

            // «un millón», no «uno millón»; y «dos millones», con plural — que
            // es donde se diferencia del mil, que nunca lo lleva.
            $texto = $millones === 1 ? 'un millón' : self::numero($millones).' millones';

            return $resto ? $texto.' '.self::numero($resto) : $texto;
        }

        if ($n >= 1_000) {
            $miles = intdiv($n, 1_000);
            $resto = $n % 1_000;

            $texto = $miles === 1 ? 'mil' : self::numero($miles).' mil';

            return $resto ? $texto.' '.self::numero($resto) : $texto;
        }

        if ($n >= 100) {
            $centena = intdiv($n, 100);
            $resto = $n % 100;

            // «cien» a secas; «ciento uno» en cuanto le sigue algo.
            $texto = $n === 100 ? 'cien' : self::CENTENAS[$centena];

            return $resto ? $texto.' '.self::numero($resto) : $texto;
        }

        if ($n >= 30) {
            $decena = intdiv($n, 10);
            $resto = $n % 10;

            return $resto ? self::DECENAS[$decena].' y '.self::UNIDADES[$resto] : self::DECENAS[$decena];
        }

        return self::UNIDADES[$n];
    }

    /**
     * Apocopa donde toca, que es lo último que se hace.
     *
     * «veintiún mil pesos», no «veintiuno mil pesos». Va fuera de `numero()`
     * porque depende de lo que venga **detrás**, y eso sólo se sabe cuando la
     * frase está entera.
     */
    private static function apocopar(string $texto): string
    {
        return preg_replace(
            ['/\bveintiuno (?=\w)/u', '/\buno (?=mil|millones|millón)/u'],
            ['veintiún ', 'un '],
            $texto
        ) ?? $texto;
    }
}
