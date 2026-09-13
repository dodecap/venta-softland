<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Usuario;
use App\Services\Softland\Catalogos;
use App\Support\SoftlandCipher;
use App\Support\SoftlandConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(private Catalogos $catalogos) {}

    /**
     * Comprobación que hace la app al configurar el servidor, antes de pedir
     * usuario y clave: confirma que la dirección apunta a una instalación viva.
     */
    public function ping()
    {
        $cfg = SoftlandConfig::load();

        return response()->json([
            'ok' => true,
            'app' => (string) config('app.name'),
            'configurado' => $cfg !== null,
            'base' => $cfg['database'] ?? null,
        ]);
    }

    /** Login por usuario/clave. Devuelve un token Bearer y los datos del usuario. */
    public function login(Request $request)
    {
        $data = $request->validate([
            'usuario' => 'required|string|max:60',
            'password' => 'required|string|max:120',
            'dispositivo' => 'nullable|string|max:120',
        ]);

        if (! SoftlandConfig::exists()) {
            return response()->json(['message' => 'El servidor aún no está configurado.'], 503);
        }

        $u = Usuario::on('softland')
            ->where(function ($q) use ($data) {
                $q->where('email', $data['usuario'])->orWhere('softland_user', $data['usuario']);
            })
            ->first();

        // Mismo mensaje para usuario inexistente y clave mala: no revelamos cuál falló.
        if (! $u || ! $u->activo || ! $this->claveValida($u, $data['password'])) {
            return response()->json(['message' => 'Usuario o contraseña incorrectos.'], 422);
        }

        if (! $u->habilitado) {
            return response()->json([
                'message' => 'Tu cuenta todavía no está habilitada. Pídele al administrador que la active.',
            ], 403);
        }

        $plain = Str::random(48);
        ApiToken::on('softland')->create([
            'usuario_id' => $u->id,
            'token_hash' => hash('sha256', $plain),
            'nombre' => 'app-movil',
            'dispositivo' => $data['dispositivo'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'token' => $plain,
            'usuario' => $u->payload(),
        ]);
    }

    /**
     * Todo lo que la app necesita para arrancar y luego trabajar sin señal:
     * el usuario, los maestros que no cambian seguido y las capacidades activas.
     */
    public function bootstrap(Request $request)
    {
        $u = $request->attributes->get('usuario');

        return response()->json([
            'usuario' => $u->payload(),
            'servidor' => [
                'app' => (string) config('app.name'),
                'base' => SoftlandConfig::load()['database'] ?? null,
                'rut_emisor' => $this->catalogos->rutEmisor(),
            ],
            'catalogos' => [
                'vendedores' => $this->catalogos->vendedores(),
                'bodegas' => $this->catalogos->bodegas(),
                'listas_precio' => $this->catalogos->listasPrecio(),
                'centros_costo' => $this->catalogos->centrosCosto(),
                'condiciones_venta' => $this->catalogos->condicionesVenta(),
            ],
            'sincronizado_at' => now()->toIso8601String(),
        ]);
    }

    /** Cierra la sesión de ESTE dispositivo (borra su token). */
    public function logout(Request $request)
    {
        $request->attributes->get('api_token')?->delete();

        return response()->json(['ok' => true]);
    }

    private function claveValida(Usuario $u, string $password): bool
    {
        // Con login Softland la verdad está en wisusuarios (cifrado propio de Softland).
        if ($u->softland_user) {
            $row = DB::connection('softland')->selectOne(
                'SELECT PassWord FROM softland.wisusuarios WHERE Usuario = ?',
                [$u->softland_user]
            );

            return $row && SoftlandCipher::verify($row->PassWord ?? '', $password);
        }

        return $u->password && Hash::check($password, $u->password);
    }
}
