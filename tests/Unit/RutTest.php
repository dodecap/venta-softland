<?php

namespace Tests\Unit;

use App\Support\Rut;
use PHPUnit\Framework\TestCase;

class RutTest extends TestCase
{
    public function test_valida_el_digito_verificador(): void
    {
        $this->assertTrue(Rut::esValido('11.111.111-1'));
        $this->assertTrue(Rut::esValido('111111111'));
        $this->assertFalse(Rut::esValido('11.111.111-2'));
    }

    public function test_acepta_el_digito_k(): void
    {
        // Cuando el módulo 11 da 10, el dígito se escribe K.
        $this->assertSame('K', Rut::dv('11111109'));
        $this->assertTrue(Rut::esValido('11.111.109-K'));
        $this->assertTrue(Rut::esValido('11111109k')); // minúscula también
    }

    public function test_acepta_el_digito_cero(): void
    {
        // Y cuando da 11, se escribe 0.
        $this->assertSame('0', Rut::dv('11111103'));
        $this->assertTrue(Rut::esValido('11.111.103-0'));
    }

    public function test_formatea_con_puntos_y_guion(): void
    {
        $this->assertSame('11.111.111-1', Rut::formatear('111111111'));
    }

    public function test_rut_incompleto_no_es_valido(): void
    {
        $this->assertFalse(Rut::esValido(''));
        $this->assertFalse(Rut::esValido('1'));
        $this->assertFalse(Rut::esValido(null));
    }
}
