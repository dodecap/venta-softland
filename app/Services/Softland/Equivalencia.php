<?php

namespace App\Services\Softland;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cuánto vale la moneda de un producto expresada en la moneda del documento.
 *
 * Softland guarda el precio de cada línea en la moneda **del producto** y, al
 * lado, el factor de conversión a la del documento (`CtEquiv` / `nvEquiv`).
 * En INNOVAGES un tercio de las líneas tiene el producto en UF y la cotización
 * en pesos, así que el factor es el valor de la UF del día — no un 1 de
 * relleno. Escribirlo mal no se nota en la app y se nota en la factura.
 *
 * De dónde sale cada factor:
 *
 *   misma moneda          1
 *   UF (02) → peso (01)   `softland.so_UF`, la fila del día o la anterior
 *   cualquier otro par    no se inventa: se rechaza el documento
 *
 * Lo último es a propósito. El dólar existe en `cwtmone` pero INNOVAGES no
 * tiene su valor diario en ninguna tabla, y un factor adivinado en un
 * documento de venta es peor que un error en pantalla.
 */
class Equivalencia
{
    private const PESO = '01';

    private const UF = '02';

    /** @var array<string, float> memoria de la petición, que una cotización repite el mismo par */
    private array $cache = [];

    public function factor(?string $monedaProducto, ?string $monedaDocumento, string $fecha): float
    {
        $prod = trim((string) $monedaProducto) ?: self::PESO;
        $doc = trim((string) $monedaDocumento) ?: self::PESO;

        if ($prod === $doc) {
            return 1.0;
        }

        $clave = "$prod>$doc@$fecha";

        return $this->cache[$clave] ??= $this->resolver($prod, $doc, $fecha);
    }

    private function resolver(string $prod, string $doc, string $fecha): float
    {
        if ($prod === self::UF && $doc === self::PESO) {
            return $this->uf($fecha);
        }

        // Peso → UF sería dividir por la UF, pero ninguna cotización de
        // INNOVAGES está en UF: mejor fallar a la vista que estrenar un camino
        // sin un solo caso real con que compararlo.
        throw new RuntimeException(
            "No hay forma de convertir la moneda $prod a la moneda $doc: Softland no guarda ese cambio."
        );
    }

    /**
     * Valor de la UF. Se toma el del día del documento o, si falta (fin de
     * semana, tabla sin actualizar), el último anterior: es lo que hace
     * Softland y evita que una cotización de un sábado quede en cero.
     */
    private function uf(string $fecha): float
    {
        foreach (['softland.so_UF', 'softland.so_TablaUFPaso'] as $tabla) {
            $valor = DB::connection('softland')->table($tabla)
                ->where('Fecha', '<=', $fecha.' 23:59:59')
                ->orderByDesc('Fecha')
                ->value('Valor');

            if ($valor > 0) {
                return (float) $valor;
            }
        }

        throw new RuntimeException("No está el valor de la UF al $fecha en Softland.");
    }
}
