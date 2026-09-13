<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Aplica en tiempo de ejecución la conexión `softland` desde la configuración
 * guardada por /setup, y permite probar credenciales antes de guardarlas.
 */
class SoftlandConnection
{
    /** Sobrescribe la conexión `softland` con la config guardada (si existe). */
    public static function apply(?array $cfg = null): void
    {
        $cfg ??= SoftlandConfig::load();
        if (! $cfg) {
            return;
        }
        foreach (static::toConnection($cfg) as $k => $v) {
            Config::set("database.connections.softland.$k", $v);
        }
        DB::purge('softland');
    }

    /** Registra una conexión temporal `softland_probe` para validar credenciales. */
    public static function probe(array $cfg): void
    {
        Config::set('database.connections.softland_probe', array_merge(
            Config::get('database.connections.softland'),
            static::toConnection($cfg),
        ));
        DB::purge('softland_probe');
    }

    protected static function toConnection(array $cfg): array
    {
        return [
            'host' => $cfg['host'],
            'port' => ($cfg['port'] ?? null) ?: null,
            'database' => $cfg['database'],
            'username' => $cfg['username'],
            'password' => $cfg['password'],
            'schema' => $cfg['schema'] ?? 'ventas',
        ];
    }
}
