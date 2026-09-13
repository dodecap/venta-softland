<?php

namespace App\Support;

/**
 * Utilidades para el RUT chileno (validación de dígito verificador módulo 11,
 * normalización y formato). Reutilizable en toda la app.
 */
class Rut
{
    /** Deja solo dígitos y K (en mayúscula). */
    public static function limpiar(?string $rut): string
    {
        return strtoupper((string) preg_replace('/[^0-9kK]/', '', (string) $rut));
    }

    /** Cuerpo sin dígito verificador (base para cod_aux por defecto). */
    public static function cuerpo(?string $rut): string
    {
        $r = self::limpiar($rut);

        return strlen($r) >= 2 ? substr($r, 0, -1) : $r;
    }

    /** Dígito verificador que corresponde a un cuerpo numérico. */
    public static function dv(string $cuerpo): string
    {
        $suma = 0;
        $mult = 2;
        for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
            $suma += ((int) $cuerpo[$i]) * $mult;
            $mult = $mult < 7 ? $mult + 1 : 2;
        }
        $esperado = 11 - ($suma % 11);

        return $esperado === 11 ? '0' : ($esperado === 10 ? 'K' : (string) $esperado);
    }

    /** Valida el RUT completo (cuerpo + DV). */
    public static function esValido(?string $rut): bool
    {
        $r = self::limpiar($rut);
        if (strlen($r) < 2) {
            return false;
        }
        $cuerpo = substr($r, 0, -1);
        $dv = substr($r, -1);

        return ctype_digit($cuerpo) && self::dv($cuerpo) === $dv;
    }

    /** Formatea a 12.345.678-5 (o el valor limpio si es muy corto). */
    public static function formatear(?string $rut): string
    {
        $r = self::limpiar($rut);
        if (strlen($r) < 2) {
            return $r;
        }
        $cuerpo = substr($r, 0, -1);
        $dv = substr($r, -1);

        return number_format((int) $cuerpo, 0, '', '.').'-'.$dv;
    }
}
