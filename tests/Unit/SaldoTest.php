<?php

namespace Tests\Unit;

use App\Services\Softland\Saldo;
use PHPUnit\Framework\TestCase;

/**
 * Las dos reglas del saldo que se rompen sin hacer ruido.
 *
 * La comprobación contra la realidad es `ventas:verifica-saldo`, que recorre la
 * historia de las dos empresas. Aquí quedan fijadas las dos cosas que un cambio
 * distraído rompe y que ninguna pantalla delataría hasta que alguien facture de
 * menos.
 */
class SaldoTest extends TestCase
{
    public function test_lo_pendiente_es_lo_pedido_menos_lo_consumido(): void
    {
        $this->assertSame(7.0, Saldo::linea(12, 5));
        $this->assertSame(0.0, Saldo::linea(12, 12));
    }

    /**
     * Devolver suma. Softland guarda la línea de la nota de crédito con la
     * cantidad en negativo, y sumarla tal cual restaba dos veces: una línea
     * pedida 1 y facturada 1 daba saldo -1, que no es un número posible.
     */
    public function test_la_nota_de_credito_devuelve_aunque_venga_en_negativo(): void
    {
        $this->assertSame(1.0, Saldo::linea(1, 1, -1));
        $this->assertSame(1.0, Saldo::linea(1, 1, 1));
        $this->assertSame(5.0, Saldo::linea(12, 10, -3));
    }

    /**
     * Facturar de más está permitido —se pueden agregar cantidades—, así que el
     * saldo negativo es un dato, no un error. En NETDOMAIN hay notas de venta
     * de doce mensualidades que acabaron con catorce facturas.
     */
    public function test_el_saldo_puede_ser_negativo(): void
    {
        $this->assertSame(-2.0, Saldo::linea(12, 14));
    }

    /**
     * `nvLinea` y `CtLinea` son `float` en Softland y valen 1.0, 2.0… Si la
     * clave no se normaliza, lo facturado contra la línea `1` no se encuentra
     * con la línea `1.0` y la nota de venta aparece entera por facturar.
     */
    public function test_el_numero_de_linea_se_normaliza_para_poder_cruzarlo(): void
    {
        $esperado = Saldo::clave(1);

        $this->assertSame($esperado, Saldo::clave('1'));
        $this->assertSame($esperado, Saldo::clave(1.0));
        $this->assertSame($esperado, Saldo::clave('1.00'));
        $this->assertNotSame($esperado, Saldo::clave(10));
    }
}
