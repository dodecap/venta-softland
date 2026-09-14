<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Softland\Maestros;
use Illuminate\Http\Request;

/**
 * Descarga de maestros al teléfono, por página.
 *
 * El vendedor sale a terreno sin señal, así que todo lo que va a consultar
 * tiene que estar ya en el aparato. Esto es la manguera: el orquestador está en
 * la app (`mobile/src/sync.js`), aquí solo se sirven páginas.
 */
class CatalogoController extends Controller
{
    public function __construct(private Maestros $maestros) {}

    /**
     * Qué maestros hay y cuántas filas tiene cada uno hoy, para este usuario.
     *
     * `?solo=cotizaciones,cotizacion_lineas` acota la respuesta a esos. Lo usa
     * el refresco de una sola lista —el tirón hacia abajo—, que no tiene por
     * qué pagar los veintiún `COUNT` del inventario completo para bajar dos
     * maestros. Un nombre que no existe se ignora en vez de romper: el teléfono
     * puede ser más nuevo que el servidor.
     */
    public function index(Request $request)
    {
        $solo = array_filter(
            array_map('trim', explode(',', (string) $request->query('solo', ''))),
            fn ($r) => $r !== '' && Maestros::existe($r)
        );

        return response()->json([
            'recursos' => $this->maestros->inventario($this->contexto($request), $solo ?: null),
            'meses_historia' => Maestros::MESES_HISTORIA,
            'servidor_at' => now()->toIso8601String(),
        ]);
    }

    public function show(Request $request, string $recurso)
    {
        if (! Maestros::existe($recurso)) {
            return response()->json(['message' => "No existe el maestro «{$recurso}»."], 404);
        }

        $data = $request->validate([
            'desde' => 'nullable|date',
            'cursor' => 'nullable|string|max:120',
            'limite' => 'nullable|integer|min:1|max:'.Maestros::LIMITE_MAX,
        ]);

        $pagina = $this->maestros->pagina(
            $recurso,
            isset($data['desde']) ? date('Y-m-d H:i:s', strtotime($data['desde'])) : null,
            $data['cursor'] ?? null,
            (int) ($data['limite'] ?? Maestros::LIMITE),
            $this->contexto($request),
        );

        // La marca de tiempo es del servidor, siempre. Si el teléfono usara su
        // propio reloj para pedir «lo cambiado desde», bastaría con que fuera
        // un minuto adelantado para saltarse filas y no enterarse nunca.
        $pagina['servidor_at'] = now()->toIso8601String();

        return response()->json($pagina);
    }

    /**
     * Lo que el maestro necesita saber de quién pregunta. Hoy es solo el alcance
     * por vendedor, que decide qué cotizaciones y notas de venta se bajan.
     */
    private function contexto(Request $request): array
    {
        return ['vendedores' => $request->attributes->get('usuario')->vendedoresVisibles()];
    }
}
