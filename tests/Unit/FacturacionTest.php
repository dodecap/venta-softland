<?php

namespace Tests\Unit;

use App\Services\Dte\Facturacion;
use App\Services\Softland\Totales;
use PHPUnit\Framework\TestCase;

/**
 * Las reglas del documento de venta que no se ven hasta que alguien cuadra el mes.
 *
 * Lo que se comprueba contra documentos reales —199, entre facturas y notas de
 * crédito— es `dte:verifica-documento`, que necesita Softland al lado. Aquí
 * queda lo que se puede fijar sin servidor.
 */
class FacturacionTest extends TestCase
{
    public function test_el_iva_se_calcula_sobre_el_neto_ya_redondeado(): void
    {
        // El caso de la factura 187: neto 2.314.102,5.
        //   sobre el decimal    → 2314102,5 × 0,19 = 439.679,475 → 439.679
        //   sobre el redondeado → 2314103   × 0,19 = 439.679,57  → 439.680
        // Softland hace lo segundo, y la diferencia es un peso en el IVA y otro
        // en el total.
        $totales = Totales::calcular([
            ['cantidad' => 1, 'precio' => 2314102.5, 'equiv' => 1, 'afecto' => true, 'descuento_pct' => 0],
        ]);

        $m = Facturacion::montos($totales);

        $this->assertSame(2314103.0, $m['afecto']);
        $this->assertSame(439680.0, $m['iva']);
        $this->assertSame(2753783.0, $m['total']);
    }

    public function test_los_netos_van_redondeados_a_peso(): void
    {
        $totales = Totales::calcular([
            ['cantidad' => 1, 'precio' => 220561.60, 'equiv' => 1, 'afecto' => true, 'descuento_pct' => 0],
        ]);

        $m = Facturacion::montos($totales);

        // La cotización guardaría 220.561,6; la factura guarda 220.562.
        $this->assertSame(220562.0, $m['afecto']);
        $this->assertSame(41907.0, $m['iva']);
        $this->assertSame(262469.0, $m['total']);
    }

    public function test_la_nota_de_credito_lleva_el_signo_en_todo_el_encabezado(): void
    {
        $totales = Totales::calcular([
            ['cantidad' => 1, 'precio' => 323000, 'equiv' => 1, 'afecto' => true, 'descuento_pct' => 0],
        ]);

        $m = Facturacion::montos($totales, -1);

        $this->assertSame(-323000.0, $m['afecto']);
        $this->assertSame(-61370.0, $m['iva']);
        $this->assertSame(-384370.0, $m['total']);
    }

    public function test_el_signo_sale_de_las_cantidades(): void
    {
        // Y no de una bandera aparte que alguien se olvide de pasar.
        $this->assertSame(1, Facturacion::signo([['cantidad' => 3], ['cantidad' => 1]]));
        $this->assertSame(-1, Facturacion::signo([['cantidad' => -1]]));
        $this->assertSame(-1, Facturacion::signo([['cantidad' => 2], ['cantidad' => -1]]));
    }

    public function test_una_equivalencia_en_cero_significa_uno(): void
    {
        // Hay una línea con `Equivalencia` en 0 cuyo total no es cero: para
        // Softland un factor vacío es «misma moneda». Tomarlo al pie de la letra
        // deja la línea en cero y el documento cuadrado en la nada.
        $this->assertSame(1.0, Facturacion::equivalencia(0));
        $this->assertSame(1.0, Facturacion::equivalencia(null));
        $this->assertSame(1.0, Facturacion::equivalencia(''));
        $this->assertSame(40844.79, Facturacion::equivalencia(40844.79));
    }

    public function test_el_descuento_de_pie_no_se_le_suma_al_iva_dos_veces(): void
    {
        $totales = Totales::calcular([
            ['cantidad' => 1, 'precio' => 1000000, 'equiv' => 1, 'afecto' => true, 'descuento_pct' => 0],
        ], 10);

        $m = Facturacion::montos($totales);

        $this->assertSame(900000.0, $m['afecto']);
        $this->assertSame(171000.0, $m['iva']);
        $this->assertSame(1071000.0, $m['total']);
    }

    /**
     * La app sólo emite notas de crédito de **anulación**. Para el SII eso es
     * `CodRef 1`, distinto de corregir el texto (`2`) o los montos (`3`), y una
     * que devuelve parte no es ninguna de las tres cosas que dice ser.
     */
    public function test_anular_es_devolver_todas_las_lineas_enteras(): void
    {
        $factura = [
            ['linea' => 1, 'cantidad' => 4],
            ['linea' => 2, 'cantidad' => 2],
        ];

        $this->assertTrue(Facturacion::devuelveTodo([
            ['linea_referencia' => 1, 'cantidad' => -4],
            ['linea_referencia' => 2, 'cantidad' => -2],
        ], $factura));
    }

    public function test_devolver_menos_de_una_linea_no_es_anular(): void
    {
        $this->assertFalse(Facturacion::devuelveTodo(
            [['linea_referencia' => 1, 'cantidad' => -3]],
            [['linea' => 1, 'cantidad' => 4]]
        ));
    }

    public function test_dejarse_una_linea_fuera_no_es_anular(): void
    {
        $this->assertFalse(Facturacion::devuelveTodo(
            [['linea_referencia' => 1, 'cantidad' => -4]],
            [['linea' => 1, 'cantidad' => 4], ['linea' => 2, 'cantidad' => 2]]
        ));
    }

    /**
     * Y no basta con que sumen lo mismo: dos líneas intercambiadas dan el mismo
     * total y no son la misma devolución.
     */
    public function test_no_basta_con_que_el_total_cuadre(): void
    {
        $this->assertFalse(Facturacion::devuelveTodo(
            [['linea_referencia' => 1, 'cantidad' => -2], ['linea_referencia' => 2, 'cantidad' => -4]],
            [['linea' => 1, 'cantidad' => 4], ['linea' => 2, 'cantidad' => 2]]
        ));
    }
}
