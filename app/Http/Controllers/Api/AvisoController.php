<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notificaciones\Eventos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Buzón personal de avisos.
 *
 * No es la bitácora: la bitácora es de administración y muestra TODO lo que
 * salió del servidor. Esto es lo que le llegó a quien está mirando el
 * teléfono — los correos en los que figura como destinatario, más los que
 * disparó él mismo. Un vendedor no tiene por qué ver los avisos de otro.
 *
 * Lo leído no se guarda en el servidor: es del aparato. El teléfono recuerda
 * hasta qué id llegó y cuenta solo. Así funciona sin señal y no gasta una
 * escritura en la base por cada vez que se abre la pestaña.
 */
class AvisoController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->attributes->get('usuario');
        $limite = min(100, max(10, (int) $request->query('limite', 40)));

        $filas = DB::connection('softland')
            ->table('ventas.notificacion')
            ->where(function ($q) use ($u) {
                $q->where('usuario_id', $u->id);

                // El destinatario es una lista de correos separados por coma.
                // El LIKE va con ESCAPE porque el guion bajo es comodín en SQL
                // Server y en los correos aparece: sin escapar, «a_b@x.cl»
                // calzaría con «aXb@x.cl» y se filtraría el aviso de otro.
                if ($u->email) {
                    $q->orWhereRaw("destinatarios LIKE ? ESCAPE '\\'", [
                        '%'.$this->escaparLike($u->email).'%',
                    ]);
                }
            })
            ->orderByDesc('id')
            ->limit($limite)
            ->get(['id', 'evento', 'referencia', 'asunto', 'estado', 'error', 'enviada_at', 'created_at']);

        $avisos = $filas->map(fn ($n) => [
            'id' => (int) $n->id,
            'evento' => $n->evento,
            'label' => Eventos::label($n->evento),
            'familia' => Eventos::familia($n->evento),
            'referencia' => $n->referencia,
            'asunto' => $n->asunto,
            'estado' => $n->estado,
            'error' => $n->error,
            'enviada_at' => $n->enviada_at,
            'created_at' => $n->created_at,
        ])->all();

        return response()->json([
            'avisos' => $avisos,
            'familias' => Eventos::familias(),
        ]);
    }

    /** Neutraliza los comodines de LIKE de SQL Server (%, _, [) en el texto buscado. */
    private function escaparLike(string $texto): string
    {
        return str_replace(['\\', '%', '_', '['], ['\\\\', '\\%', '\\_', '\\['], $texto);
    }
}
