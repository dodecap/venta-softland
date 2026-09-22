<?php

namespace App\Http\Middleware;

use App\Support\Rutas;
use App\Support\SoftlandConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mientras no exista la configuración de conexión, la única página servible es
 * /setup. Una vez configurada, /setup se aparta: reconfigurar se hace desde la
 * app, con rol admin y la clave de Softland.
 *
 * **Con una excepción, y es la que evita quedarse fuera.** Si la conexión
 * guardada dejó de servir —cambió la instancia de SQL, se renombró la base, se
 * rotó la contraseña del usuario SQL—, reconfigurar desde la app es imposible:
 * para entrar en la app hace falta el token, el token está en `ventas.api_token`
 * y esa tabla está justo al otro lado de la conexión rota. Así que cuando la
 * conexión no responde, /setup vuelve a abrirse. No debilita nada: para guardar
 * sigue haciendo falta el usuario SQL y la contraseña del administrador de
 * Softland.
 *
 * Las redirecciones van relativas a propósito. Ver `Rutas`.
 */
class EnsureConfigured
{
    public function handle(Request $request, Closure $next)
    {
        if (! SoftlandConfig::exists()) {
            return $request->is('setup', 'setup/*', 'up')
                ? $next($request)
                : Rutas::irA($request, 'setup');
        }

        if ($request->is('setup') && $this->conexionViva()) {
            return Rutas::irA($request);
        }

        return $next($request);
    }

    /**
     * ¿La conexión guardada todavía sirve?
     *
     * Sólo se pregunta al pedir /setup —tres páginas HTML en todo el
     * proyecto—, nunca en la API: ahí un `SELECT 1` de más por petición sería
     * un impuesto sobre cada sincronización.
     */
    private function conexionViva(): bool
    {
        try {
            DB::connection('softland')->select('SELECT 1 AS x');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
