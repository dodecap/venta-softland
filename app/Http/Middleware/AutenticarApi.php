<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\Usuario;
use App\Support\SoftlandConfig;
use Closure;
use Illuminate\Http\Request;

/**
 * Autenticación por token Bearer. El token se emite en /api/login, se guarda
 * hasheado (sha256) en `ventas.api_token` y viaja en cada request como
 * "Authorization: Bearer <token>".
 *
 * El usuario autenticado queda en $request->attributes->get('usuario').
 */
class AutenticarApi
{
    public function handle(Request $request, Closure $next)
    {
        if (! SoftlandConfig::exists()) {
            return response()->json(['message' => 'El servidor aún no está configurado.'], 503);
        }

        $bearer = $request->bearerToken();
        if (! $bearer) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $tok = ApiToken::on('softland')->where('token_hash', hash('sha256', $bearer))->first();
        if (! $tok) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        $u = Usuario::on('softland')->find($tok->usuario_id);
        if (! $u || ! $u->activo || ! $u->habilitado) {
            return response()->json(['message' => 'Usuario inactivo o no habilitado.'], 401);
        }

        $tok->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('usuario', $u);
        $request->attributes->set('api_token', $tok);

        return $next($request);
    }
}
