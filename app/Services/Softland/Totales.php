<?php

namespace App\Services\Softland;

/**
 * La aritmética de una cotización o de una nota de venta.
 *
 * No toca la base: entran líneas y descuentos, salen los números que van a las
 * columnas de Softland. Está aparte justamente para poder compararla contra
 * documentos reales sin escribir nada.
 *
 * ## Las reglas, medidas contra INNOVAGES
 *
 * Salieron de reproducir 200 cotizaciones reales columna por columna, no de un
 * manual. Cualquier cambio aquí hay que volver a contrastarlo igual.
 *
 * Por línea:
 *
 *     subtotal = redondeo(cantidad × precio × equivalencia, 2)
 *     descuento = redondeo(subtotal × pct / 100)            ← a peso entero
 *     total     = subtotal − descuento
 *
 * Del encabezado:
 *
 *     bruto       = Σ total de líneas
 *     subtotal    = redondeo(bruto)                         ← antes del descuento de pie
 *     descuento   = redondeo(bruto × pct / 100)
 *     afecto      = parte afecta de (bruto − descuento)
 *     exento      = el resto, para que afecto + exento sea exacto
 *     IVA         = redondeo(afecto × tasa / 100)
 *     total       = subtotal − descuento + IVA
 *
 * El descuento de pie se reparte entre lo afecto y lo exento en proporción a
 * lo que cada parte pesa; si se aplicara solo a uno, el IVA saldría distinto
 * al de Softland y la factura no cuadraría con la nota de venta.
 *
 * Los cinco niveles de descuento de Softland existen en las tablas, pero en las
 * 2.350 cotizaciones de INNOVAGES **solo se usa el primero**, tanto en la línea
 * como en el encabezado. La app escribe ese y deja los otros cuatro en cero:
 * inventar una cascada sin un caso real con que comprobarla sería adivinar
 * sobre el precio que se le cobra a un cliente.
 */
class Totales
{
    /** Tasa de IVA de respaldo, si Softland no tiene ningún documento del que copiarla. */
    public const IVA_POR_DEFECTO = 19.0;

    /**
     * @param  array<int, array{cantidad: float, precio: float, equiv: float, afecto: bool, descuento_pct: float}>  $lineas
     * @param  float  $descuentoPct  descuento de pie, en porcentaje
     * @return array{lineas: array, bruto: float, subtotal: float, descuento: float, afecto: float, exento: float, iva: float, iva_pct: float, total: float}
     */
    public static function calcular(array $lineas, float $descuentoPct = 0, float $ivaPct = self::IVA_POR_DEFECTO): array
    {
        $calculadas = [];
        $bruto = 0.0;
        $brutoAfecto = 0.0;

        foreach ($lineas as $l) {
            $subtotal = round(((float) $l['cantidad']) * ((float) $l['precio']) * ((float) $l['equiv']), 2);
            $descuento = round($subtotal * ((float) ($l['descuento_pct'] ?? 0)) / 100);
            $total = round($subtotal - $descuento, 2);

            $calculadas[] = $l + ['subtotal' => $subtotal, 'descuento' => $descuento, 'total' => $total];

            $bruto += $total;
            if ($l['afecto']) {
                $brutoAfecto += $total;
            }
        }

        $bruto = round($bruto, 2);
        $brutoAfecto = round($brutoAfecto, 2);

        $descuentoPie = round($bruto * $descuentoPct / 100);
        $neto = round($bruto - $descuentoPie, 2);

        // El reparto va sobre lo afecto y lo exento se calcula por diferencia:
        // así los dos suman exactamente el neto y no aparece un peso de la nada
        // al sumarlos en la factura.
        $afecto = $bruto > 0 ? round($neto * $brutoAfecto / $bruto, 2) : 0.0;
        $exento = round($neto - $afecto, 2);

        $iva = round($afecto * $ivaPct / 100);

        return [
            'lineas' => $calculadas,
            'bruto' => $bruto,
            'subtotal' => round($bruto),
            'descuento' => $descuentoPie,
            'afecto' => $afecto,
            'exento' => $exento,
            'iva' => $iva,
            'iva_pct' => $ivaPct,
            'total' => round($bruto) - $descuentoPie + $iva,
        ];
    }
}
