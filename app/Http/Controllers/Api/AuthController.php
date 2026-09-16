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
            'version' => (string) config('app.version'),
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
     * Lo que la app necesita para arrancar: quién es y contra qué instalación
     * está hablando.
     *
     * Los maestros ya **no** viajan aquí. Desde la fase 2 se descargan por
     * `/api/catalogo`, por páginas y a IndexedDB: 3.824 clientes y 1.229
     * productos no caben en una respuesta de login, y sobre todo no se pueden
     * buscar si llegan como un solo bloque.
     */
    public function bootstrap(Request $request, \App\Services\Softland\Ventas $ventas, \App\Services\Documentos\Identidad $identidad)
    {
        $u = $request->attributes->get('usuario');

        return response()->json([
            'usuario' => $u->payload(),
            'servidor' => [
                'app' => (string) config('app.name'),
                // La versión de la API. El teléfono la guarda y la
                // muestra al lado de la suya en Cuenta: cuando no
                // coinciden, media hora de soporte se ahorra en una
                // línea.
                'version' => (string) config('app.version'),
                'base' => SoftlandConfig::load()['database'] ?? null,
                'rut_emisor' => $this->catalogos->rutEmisor(),
                // Dos números que el teléfono necesita para calcular un total
                // sin señal y que no vienen en ningún maestro: la tasa de IVA,
                // que Softland estampa documento a documento, y el valor de la
                // UF, que hace falta para proponer el precio de un producto
                // tarifado en UF dentro de una cotización en pesos. Al escribir,
                // el servidor los vuelve a calcular con los de hoy: estos son
                // para que el vendedor vea un total mientras teclea, no para
                // que el documento se guarde con ellos.
                'iva_pct' => $ventas->ivaPct(),
                'uf' => $this->uf(),
                // Cuántos días la empresa da por buena una cotización. Es el
                // mismo número que sale impreso en el PDF, y con él el panel
                // sabe cuáles están por vencer sin preguntar: la cotización no
                // tiene fecha de vencimiento en Softland, se calcula.
                'vigencia_cotizacion_dias' => $identidad->actual()['vigencia_cotizacion_dias'],
                // Si emitir manda el documento al SII en el mismo acto. El
                // teléfono lo necesita para dos cosas: decir en la confirmación
                // qué va a pasar al apretar, y saber si «sin enviar» es una
                // avería —rojo— o el paso siguiente del trabajo —ámbar—.
                'envio_automatico' => (new \App\Services\Dte\ReglasFactura)->envioAutomatico(),
            ],
            'sincronizado_at' => now()->toIso8601String(),
        ]);
    }

    /** Valor de la UF de hoy, o el último que haya. Null si Softland no lo tiene. */
    private function uf(): ?float
    {
        // El corte va al segundo y no con `endOfDay()`: el tipo `datetime` de
        // SQL Server redondea a 3,33 ms, así que las 23:59:59.999 se convierten
        // en las 00:00 del día siguiente y la consulta devolvía la UF de mañana.
        $v = DB::connection('softland')->table('softland.so_UF')
            ->where('Fecha', '<=', now()->format('Y-m-d').' 23:59:59')
            ->orderByDesc('Fecha')->value('Valor');

        return $v ? (float) $v : null;
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
