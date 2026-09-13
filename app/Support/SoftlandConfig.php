<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;

/**
 * Configuración de conexión a Softland, guardada cifrada en disco.
 * Se escribe en /setup y se lee al arrancar. No vive en la base (la app aún
 * no tiene base antes de configurarse).
 */
class SoftlandConfig
{
    protected static function path(): string
    {
        return storage_path('app/private/softland.json');
    }

    public static function exists(): bool
    {
        return File::exists(static::path());
    }

    public static function load(): ?array
    {
        if (! static::exists()) {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString(File::get(static::path())), true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function save(array $data): void
    {
        File::ensureDirectoryExists(dirname(static::path()));
        File::put(static::path(), Crypt::encryptString(json_encode($data)));
    }

    public static function forget(): void
    {
        File::delete(static::path());
    }
}
