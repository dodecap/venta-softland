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

    /**
     * Cifrar el tráfico a SQL Server **sólo si sale de la máquina**.
     *
     * Con la base en la misma máquina —que es la instalación documentada: no
     * escucha en la red, y por eso existe esta API— TLS no protege de nada, y
     * con `TrustServerCertificate` menos todavía: cifra contra un certificado
     * que nadie comprueba. Lo que sí hace es añadir una pieza móvil, y es la
     * que se rompe: ver `ConectorSoftland` para lo que está medido.
     *
     * Con la base en otra máquina el tráfico cruza la red y entonces sí se
     * cifra. `SOFTLAND_DB_ENCRYPT` manda sobre la regla, en los dos sentidos,
     * para la empresa cuya política exija cifrar igual.
     */
    public static function cifrado(?string $host, mixed $forzado = null): string
    {
        if ($forzado !== null && $forzado !== '') {
            return filter_var($forzado, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no';
        }

        return static::enEstaMaquina($host) ? 'no' : 'yes';
    }

    /**
     * El servidor viene escrito de muchas maneras —`localhost\\INSTANCIA`,
     * `.\\INSTANCIA`, `(local)`, `127.0.0.1,1433`, el nombre de la máquina—, y
     * todas son la misma máquina. La instancia y el puerto no pintan aquí.
     */
    protected static function enEstaMaquina(?string $host): bool
    {
        $maquina = strtolower(trim(explode(',', explode('\\', (string) $host)[0])[0]));

        return in_array($maquina, [
            '', '.', '(local)', 'localhost', '127.0.0.1', '::1', '[::1]',
            strtolower(gethostname() ?: '_ninguno_'),
        ], true);
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
            'encrypt' => static::cifrado(
                $cfg['host'],
                Config::get('database.connections.softland.cifrado_forzado'),
            ),
        ];
    }
}
