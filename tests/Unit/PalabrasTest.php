<?php

namespace Tests\Unit;

use App\Services\Documentos\Palabras;
use PHPUnit\Framework\TestCase;

/**
 * El «Son:» del papel.
 *
 * Es la clase más fácil de romper sin enterarse: el número sale bien en las
 * pruebas de a ojo —mil, dos mil, cien— y falla justo en los casos que el
 * castellano complica, que son los que aquí quedan fijados.
 */
class PalabrasTest extends TestCase
{
    public function test_el_monto_de_la_factura_234(): void
    {
        // El que imprimió Softland, letra por letra.
        $this->assertSame(
            'DOSCIENTOS SESENTA Y DOS MIL CUATROCIENTOS SESENTA Y NUEVE PESOS',
            Palabras::monto(262469),
        );
    }

    public function test_cien_va_solo_y_ciento_acompañado(): void
    {
        $this->assertSame('cien', Palabras::numero(100));
        $this->assertSame('ciento uno', Palabras::numero(101));
        $this->assertSame('cien mil', Palabras::numero(100000));
    }

    public function test_del_dieciseis_al_veintinueve_se_escribe_junto(): void
    {
        $this->assertSame('dieciséis', Palabras::numero(16));
        $this->assertSame('veintitrés', Palabras::numero(23));
        // Y a partir de treinta, separado y con «y».
        $this->assertSame('treinta y uno', Palabras::numero(31));
    }

    public function test_el_uno_se_apocopa_delante_de_mil(): void
    {
        // «veintiún mil», no «veintiuno mil». Es el caso que delata que la
        // apócope no puede vivir dentro del bucle: depende de lo que venga
        // detrás.
        $this->assertSame('VEINTIÚN MIL PESOS', Palabras::monto(21000));
        $this->assertSame('TREINTA Y UN MIL PESOS', Palabras::monto(31000));
    }

    public function test_el_millon_lleva_plural_y_el_mil_no(): void
    {
        $this->assertSame('UN MILLÓN PESOS', Palabras::monto(1000000));
        $this->assertSame('DOS MILLONES PESOS', Palabras::monto(2000000));
        $this->assertSame('MIL PESOS', Palabras::monto(1000));
        $this->assertSame('DOS MIL PESOS', Palabras::monto(2000));
    }

    public function test_el_cero_se_dice(): void
    {
        // Una nota de crédito de ajuste puede quedar en cero, y el renglón no
        // puede salir en blanco.
        $this->assertSame('CERO PESOS', Palabras::monto(0));
    }

    public function test_la_nota_de_credito_va_en_negativo_y_se_nota(): void
    {
        // En `iw_gsaen` el total de una nota de crédito es negativo. Si el papel
        // lo escribiera igual que la factura que anula, los dos documentos se
        // leerían como el mismo cobro.
        $this->assertStringStartsWith('MENOS ', Palabras::monto(-262469));
    }

    public function test_los_centavos_se_redondean(): void
    {
        // Se factura en pesos enteros: así lo guarda Softland y así cuadra con
        // el número impreso al lado.
        $this->assertSame('MIL PESOS', Palabras::monto(999.6));
    }

    public function test_otra_moneda_se_nombra_como_le_digan(): void
    {
        $this->assertSame('CINCO DÓLARES', Palabras::monto(5, 'DÓLARES'));
    }
}
