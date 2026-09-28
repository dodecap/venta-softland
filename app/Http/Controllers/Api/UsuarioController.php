<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Usuario;
use App\Services\Notificaciones\Eventos;
use App\Services\Notificaciones\Notificador;
use App\Services\Softland\Catalogos;
use App\Support\Rut;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestión de usuarios desde la app (solo rol admin).
 *
 * Un usuario de esta app **siempre** tiene usuario de Softland: la clave vive
 * en `wisusuarios` y aquí no se guarda ninguna. Lo que además necesita para
 * vender es un **código de vendedor** (`ven_cod`, de `softland.cwtvend`), que
 * es lo que queda estampado en la cotización y en la nota de venta; son dos
 * cosas distintas y un administrador no tiene por qué tener la segunda.
 */
class UsuarioController extends Controller
{
    public function __construct(
        private Catalogos $catalogos,
        private Notificador $notificador,
    ) {}

    public function index()
    {
        $usuarios = Usuario::on('softland')->orderBy('nombre')->get();
        $porId = $usuarios->keyBy('id');

        return response()->json([
            'usuarios' => $usuarios->map(fn ($u) => $u->payload() + [
                'habilitado' => (bool) $u->habilitado,
                'activo' => (bool) $u->activo,
                'softland_user' => $u->softland_user,
                'jefe_nombre' => $u->jefe_id ? ($porId[$u->jefe_id]->nombre ?? null) : null,
            ])->values(),
            'roles' => Usuario::ROLES,
        ]);
    }

    /** Maestros que la pantalla de usuarios necesita para los selectores. */
    public function opciones()
    {
        return response()->json([
            'vendedores' => $this->catalogos->vendedores(),
            'usuarios_softland' => $this->catalogos->usuariosSoftland(),
            'bodegas' => $this->catalogos->bodegas(),
            'listas_precio' => $this->catalogos->listasPrecio(),
            'centros_costo' => $this->catalogos->centrosCosto(),
            'jefes' => Usuario::on('softland')
                ->whereIn('rol', ['supervisor', 'admin'])->where('activo', true)
                ->orderBy('nombre')->get(['id', 'nombre', 'rol']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);

        $u = new Usuario;
        $this->llenar($u, $data);
        $u->save();

        if ($u->habilitado) {
            $this->avisarHabilitado($u, $request);
        }

        return response()->json(['usuario' => $u->payload()], 201);
    }

    public function update(Request $request, int $id)
    {
        $u = Usuario::on('softland')->findOrFail($id);
        $data = $this->validar($request, $u->id);

        $estabaHabilitado = (bool) $u->habilitado;
        $this->llenar($u, $data);

        // Nadie puede dejarse a sí mismo sin acceso y bloquear la instalación.
        $actual = $request->attributes->get('usuario');
        if ((int) $actual->id === (int) $u->id && (! $u->activo || $u->rol !== 'admin')) {
            return response()->json([
                'message' => 'No puedes quitarte a ti mismo el rol de administrador ni desactivarte.',
            ], 422);
        }
        if (! $this->quedaAlgunAdmin($u)) {
            return response()->json([
                'message' => 'Debe quedar al menos un administrador activo.',
            ], 422);
        }

        $u->save();

        // Al desactivar, se cierran las sesiones abiertas de ese usuario.
        if (! $u->activo) {
            ApiToken::on('softland')->where('usuario_id', $u->id)->delete();
        }

        if (! $estabaHabilitado && $u->habilitado) {
            $this->avisarHabilitado($u, $request);
        }

        return response()->json(['usuario' => $u->payload()]);
    }

    /**
     * No se borra: se desactiva. Los documentos de Softland ya emitidos siguen
     * apuntando a este vendedor y la trazabilidad tiene que sobrevivir.
     */
    public function destroy(Request $request, int $id)
    {
        $u = Usuario::on('softland')->findOrFail($id);
        $actual = $request->attributes->get('usuario');

        if ((int) $actual->id === (int) $u->id) {
            return response()->json(['message' => 'No puedes desactivarte a ti mismo.'], 422);
        }

        $u->activo = false;
        if (! $this->quedaAlgunAdmin($u)) {
            return response()->json(['message' => 'Debe quedar al menos un administrador activo.'], 422);
        }
        $u->save();

        ApiToken::on('softland')->where('usuario_id', $u->id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Sesiones abiertas de un usuario (para revocarlas si pierde el teléfono). */
    public function sesiones(int $id)
    {
        return response()->json([
            'sesiones' => ApiToken::on('softland')
                ->where('usuario_id', $id)->orderByDesc('id')
                ->get(['id', 'nombre', 'dispositivo', 'last_used_at', 'created_at']),
        ]);
    }

    public function revocarSesiones(int $id)
    {
        ApiToken::on('softland')->where('usuario_id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'nombre' => 'required|string|max:120',
            'email' => ['nullable', 'email', 'max:150', Rule::unique('softland.ventas.usuario', 'email')->ignore($id)],
            // A la app se entra sólo con usuario de Softland. Ocho caracteres
            // porque eso mide `wisusuarios.Usuario`, que es también el largo de
            // `cwcpbte.Usuario`: lo que no quepa ahí no puede firmar un asiento.
            'softland_user' => ['required', 'string', 'max:8', Rule::unique('softland.ventas.usuario', 'softland_user')->ignore($id)],
            'rut' => 'nullable|string|max:20',
            'ven_cod' => 'nullable|string|max:4',
            'cod_bode' => 'nullable|string|max:10',
            'cod_lista' => 'nullable|string|max:3',
            'cod_cc' => 'nullable|string|max:8',
            'rol' => ['required', Rule::in(Usuario::ROLES)],
            'jefe_id' => 'nullable|integer',
            'tope_descuento_pct' => 'nullable|numeric|min:0|max:100',
            'tope_monto_nv' => 'nullable|numeric|min:0',
            'habilitado' => 'boolean',
            'activo' => 'boolean',
        ]);
    }

    private function llenar(Usuario $u, array $d): void
    {
        // El usuario de Softland tiene que existir de verdad. La validación de
        // formato no basta: un nombre tecleado a mano que no está en
        // `wisusuarios` crea una cuenta con la que nadie puede entrar —la clave
        // se comprueba allí— y que además no puede firmar un asiento.
        if (! $this->catalogos->existeUsuarioSoftland($d['softland_user'])) {
            abort(422, 'El usuario «'.$d['softland_user'].'» no existe en Softland.');
        }
        if (! empty($d['rut']) && ! Rut::esValido($d['rut'])) {
            abort(422, 'El RUT no es válido.');
        }

        $u->fill([
            'nombre' => $d['nombre'],
            'email' => $d['email'] ?? null,
            'softland_user' => $d['softland_user'],
            'rut' => ! empty($d['rut']) ? Rut::formatear($d['rut']) : null,
            'ven_cod' => $d['ven_cod'] ?? null,
            'cod_bode' => $d['cod_bode'] ?? null,
            'cod_lista' => $d['cod_lista'] ?? null,
            'cod_cc' => $d['cod_cc'] ?? null,
            'rol' => $d['rol'],
            'jefe_id' => $d['jefe_id'] ?? null,
            'tope_descuento_pct' => $d['tope_descuento_pct'] ?? 0,
            'tope_monto_nv' => $d['tope_monto_nv'] ?? 0,
            'habilitado' => (bool) ($d['habilitado'] ?? false),
            'activo' => (bool) ($d['activo'] ?? true),
        ]);

        // Un usuario no puede ser su propio jefe.
        if ($u->jefe_id && $u->id && (int) $u->jefe_id === (int) $u->id) {
            $u->jefe_id = null;
        }
    }

    /** ¿Sigue habiendo al menos un admin activo si guardamos este cambio? */
    private function quedaAlgunAdmin(Usuario $modificado): bool
    {
        $otros = Usuario::on('softland')
            ->where('rol', 'admin')->where('activo', true)
            ->where('id', '!=', $modificado->id ?? 0)
            ->count();

        return $otros > 0 || ($modificado->rol === 'admin' && $modificado->activo);
    }

    private function avisarHabilitado(Usuario $u, Request $request): void
    {
        $this->notificador->disparar(
            Eventos::USUARIO_HABILITADO,
            [
                'asunto' => 'Tu acceso a '.config('app.name').' está activo',
                'referencia' => 'usuario:'.$u->id,
                'cuerpo_html' => '<p>Hola '.e($u->nombre).',</p>'
                    .'<p>Tu cuenta quedó habilitada. Ya puedes entrar desde la app con '
                    .'<b>'.e($u->softland_user ?: $u->email).'</b>.</p>'
                    .($u->ven_cod ? '<p>Tu código de vendedor en Softland es <b>'.e($u->ven_cod).'</b>.</p>' : '')
                    .'<p>Si todavía no tienes la app instalada, pídesela al administrador.</p>',
            ],
            dueno: $u,
            actor: $request->attributes->get('usuario'),
        );
    }
}
