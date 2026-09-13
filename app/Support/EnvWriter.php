<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Escribe/actualiza una clave en el archivo .env (reemplaza la línea si existe,
 * la agrega si no). Solo para valores de configuración simples (API keys, modelo).
 */
class EnvWriter
{
    public static function set(string $key, string $value): void
    {
        $path = base_path('.env');
        $contents = File::exists($path) ? File::get($path) : '';

        // Encomilla si el valor trae espacios, comillas o comentarios.
        $val = preg_match('/[\s#"\']/', $value)
            ? '"'.str_replace('"', '\"', $value).'"'
            : $value;
        $line = $key.'='.$val;

        $patron = '/^'.preg_quote($key, '/').'=.*$/m';
        if (preg_match($patron, $contents)) {
            $contents = preg_replace($patron, $line, $contents);
        } else {
            $contents = rtrim($contents, "\r\n")."\n".$line."\n";
        }

        File::put($path, $contents);
    }
}
