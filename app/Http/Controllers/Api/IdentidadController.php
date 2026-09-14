<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documentos\Identidad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * La identidad corporativa: quién emite los documentos y cómo se ve.
 *
 * Se edita sólo con rol admin, como el resto de `/api/admin`. El vendedor la
 * usa — le llega dentro del PDF — pero no la ve como pantalla ni la escribe.
 *
 * El logo se sirve por aquí y no desde `public/`: un archivo que sube un
 * usuario y que además queda en una ruta que Apache interpreta es la receta
 * clásica de la ejecución remota.
 */
class IdentidadController extends Controller
{
    public function __construct(private Identidad $identidad) {}

    /** Lo propio y lo heredado por separado: el admin tiene que ver contra qué cambia. */
    public function index()
    {
        return response()->json($this->identidad->paraEditar());
    }

    public function guardar(Request $request)
    {
        $data = $request->validate([
            'razon_social' => 'nullable|string|max:120',
            'nombre_comercial' => 'nullable|string|max:120',
            'rut' => 'nullable|string|max:20',
            'giro' => 'nullable|string|max:200',
            'direccion' => 'nullable|string|max:160',
            'comuna' => 'nullable|string|max:60',
            'ciudad' => 'nullable|string|max:60',
            'fono' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:120',
            'web' => 'nullable|string|max:120',
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'condiciones_comerciales' => 'nullable|string|max:2000',
            'datos_bancarios' => 'nullable|string|max:1000',
            'pie_documento' => 'nullable|string|max:200',
            'vigencia_cotizacion_dias' => 'nullable|integer|min:0|max:365',
            'modelo_negocio' => 'nullable|in:PRODUCTOS,SERVICIOS,MIXTO',
        ], [
            'color.regex' => 'El color tiene que ir en formato #rrggbb.',
        ]);

        $this->identidad->guardar($data);

        return response()->json([
            'ok' => true,
            'message' => 'Identidad guardada.',
        ] + $this->identidad->paraEditar());
    }

    /**
     * Sube el logo.
     *
     * La validación de verdad está en el servicio, que decodifica la imagen y
     * guarda **otra**: lo que venga escondido en los metadatos no sobrevive a
     * ese viaje. Aquí sólo se acota el tamaño para no leer 40 MB antes de
     * decidir que no.
     */
    public function subirLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|file|max:2048',   // KB
        ], [
            'logo.max' => 'El logo no puede pesar más de 2 MB.',
        ]);

        try {
            $r = $this->identidad->guardarLogo($request->file('logo'));
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['logo' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Logo guardado ('.$r['ancho'].' × '.$r['alto'].' px).',
            'logo' => $r,
        ]);
    }

    public function borrarLogo()
    {
        $this->identidad->borrarLogo();

        return response()->json(['ok' => true, 'message' => 'Logo eliminado.']);
    }

    /**
     * El logo, para la vista previa de la pantalla de administración.
     *
     * No exige rol admin: cualquier usuario con token puede verlo, porque es la
     * marca que va en sus propios documentos. Lo que exige admin es cambiarlo.
     */
    public function logo()
    {
        $ruta = $this->identidad->rutaLogo();

        if (! File::exists($ruta)) {
            return response()->json(['message' => 'No hay logo cargado.'], 404);
        }

        return response(File::get($ruta), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
