<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Actualizacion\Actualizador;
use App\Services\Actualizacion\Publicacion;
use Illuminate\Http\Request;

/**
 * Actualizar el servidor desde la app (solo rol admin).
 *
 * No hay página web de administración —no la hay en todo el proyecto— y ésta
 * no iba a ser la excepción: `/setup` y `/app` existen porque hacen falta
 * *antes* de que la app funcione, y actualizar es lo contrario, algo que se
 * hace con la app ya instalada. Quien administra la empresa lleva el servidor
 * en el bolsillo.
 *
 * El mismo trabajo se puede hacer desde la consola con `ventas:actualizar`,
 * que es el camino para el día en que la app no abra — que es justo el día en
 * que más falta hace actualizar.
 */
class ActualizacionController extends Controller
{
    /** Qué versión hay puesta, qué versión hay publicada y en qué quedó el último intento. */
    public function index()
    {
        return response()->json(
            (new Actualizador)->comprobar() + [
                'repositorio' => (string) config('actualizacion.repositorio'),
                'ultimo_intento' => Actualizador::estado(),
            ]
        );
    }

    /**
     * Instalarla.
     *
     * Se puede pedir una versión concreta —anterior incluida— para volver
     * atrás cuando una sale mala: sin eso, actualizar sería una puerta que
     * sólo se abre en un sentido.
     */
    public function aplicar(Request $request)
    {
        $data = $request->validate([
            'version' => ['nullable', 'string', 'regex:/^v?\d+\.\d+\.\d+$/'],
        ]);

        try {
            $p = isset($data['version'])
                ? Publicacion::deVersion($data['version'])
                : Publicacion::ultima();
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $p) {
            return response()->json(['message' => 'No hay ninguna versión publicada con esa etiqueta.'], 404);
        }

        if ($p->version() === config('app.version')) {
            return response()->json(['message' => 'El servidor ya está en la '.$p->version().'.'], 422);
        }

        try {
            $hecho = (new Actualizador)->aplicar($p);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'estado' => Actualizador::estado(),
            ], 500);
        }

        return response()->json([
            'version' => $hecho['version'],
            'pasos' => $hecho['pasos'],
            // La app la compila quien publica, no este servidor: aquí sólo se
            // guarda para repartirla. El teléfono se entera por la campanita.
            'apk' => (bool) $p->pieza('apk'),
        ]);
    }
}
