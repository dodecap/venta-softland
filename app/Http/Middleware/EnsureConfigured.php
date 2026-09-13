<?php

namespace App\Http\Middleware;

use App\Support\SoftlandConfig;
use Closure;
use Illuminate\Http\Request;

/**
 * Mientras no exista la configuración de conexión, la única página servible es
 * /setup. Una vez configurada, /setup deja de estar disponible (reconfigurar se
 * hace desde la app, con rol admin y la clave de Softland).
 */
class EnsureConfigured
{
    public function handle(Request $request, Closure $next)
    {
        $configurado = SoftlandConfig::exists();

        if (! $configurado && ! $request->is('setup', 'setup/*', 'up')) {
            return redirect('/setup');
        }

        if ($configurado && $request->is('setup')) {
            return redirect('/');
        }

        return $next($request);
    }
}
