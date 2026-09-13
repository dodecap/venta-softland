<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Restringe una ruta a ciertos roles:  ->middleware('rol:admin,supervisor')
 * Se usa siempre después de 'auth.api'.
 */
class ExigirRol
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $u = $request->attributes->get('usuario');

        if (! $u || ! $u->esRol(...$roles)) {
            return response()->json(['message' => 'No tienes permiso para esta acción.'], 403);
        }

        return $next($request);
    }
}
