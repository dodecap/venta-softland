<?php

namespace App\Support;

/**
 * Cifrado propio de Softland para las contraseñas de `wisusuarios`.
 *
 * El primer byte del valor almacenado codifica el largo de la contraseña;
 * a cada byte siguiente se le resta una clave que cicla 3,4,5,6,7,1,2,3,...
 *
 * IMPORTANTE: el valor almacenado debe leerse en bytes crudos (vía sqlsrv),
 * nunca a través de la consola cmd de Windows, que corrompe el encoding.
 */
class SoftlandCipher
{
    /**
     * Descifra la contraseña almacenada y devuelve el texto plano.
     */
    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        $text = '';
        $key = 3;                          // clave inicial
        $limit = ord($stored[0]) + 1;      // el primer byte codifica el largo

        for ($i = 2; $i <= $limit; $i++) {
            if (! isset($stored[$i - 1])) {
                break;
            }
            $text .= chr((ord($stored[$i - 1]) - $key) & 0xFF);
            if (++$key > 7) {
                $key = 1;
            }
        }

        return $text;
    }

    /**
     * ¿La contraseña tecleada coincide con la almacenada?
     */
    public static function verify(string $stored, string $plain): bool
    {
        return hash_equals(self::decrypt($stored), $plain);
    }
}
