<?php

namespace Tests\Unit;

use App\Support\SoftlandCipher;
use PHPUnit\Framework\TestCase;

/**
 * El cifrado propio de Softland para `wisusuarios`: el primer byte codifica el
 * largo y a cada byte siguiente se le resta una clave que cicla 3,4,5,6,7,1,2.
 */
class SoftlandCipherTest extends TestCase
{
    /** Cifra igual que Softland, para poder probar el descifrado de ida y vuelta. */
    private function cifrar(string $plano): string
    {
        $out = chr(strlen($plano));
        $key = 3;
        foreach (str_split($plano) as $c) {
            $out .= chr((ord($c) + $key) & 0xFF);
            $key = $key < 7 ? $key + 1 : 1;
        }

        return $out;
    }

    public function test_descifra_lo_que_cifra_softland(): void
    {
        foreach (['abc', 'Clave123', 'una clave mas larga que el ciclo'] as $clave) {
            $this->assertSame($clave, SoftlandCipher::decrypt($this->cifrar($clave)));
        }
    }

    public function test_verify_compara_sin_filtrar_la_clave(): void
    {
        $guardada = $this->cifrar('Secreta1');
        $this->assertTrue(SoftlandCipher::verify($guardada, 'Secreta1'));
        $this->assertFalse(SoftlandCipher::verify($guardada, 'Secreta2'));
    }

    public function test_valor_vacio_no_revienta(): void
    {
        $this->assertSame('', SoftlandCipher::decrypt(''));
        $this->assertFalse(SoftlandCipher::verify('', 'algo'));
    }
}
