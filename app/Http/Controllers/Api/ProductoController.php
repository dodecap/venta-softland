<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Softland\CodigoBarras;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Lo poco que la app escribe del maestro de productos.
 *
 * Los productos se **leen** por el catálogo, como el resto de los maestros.
 * Aquí sólo está el código de barras, y sólo para enseñárselo a un producto
 * que no lo tiene: las cinco reglas que lo hacen inocuo están en
 * `App\Services\Softland\CodigoBarras`, no aquí, porque una pantalla nueva que
 * no supiera de ellas acabaría pisando el código de un producto que ya se
 * escanea en el ERP.
 */
class ProductoController extends Controller
{
    public function __construct(private CodigoBarras $codigos) {}

    /**
     * Le enseña a un producto el código de barras que se acaba de leer.
     *
     * Es idempotente: reenviar el mismo código del mismo producto contesta que
     * sí, no que ya estaba. Un teléfono que perdió la respuesta reintenta.
     */
    public function codigoBarras(Request $request)
    {
        $data = $request->validate([
            'producto' => 'required|string|max:20',
            'barra' => 'required|string|max:'.CodigoBarras::LARGO_MAXIMO,
        ]);

        $u = $request->attributes->get('usuario');

        try {
            return response()->json([
                'producto' => $this->codigos->asignar($data['producto'], $data['barra'], $u?->email),
            ]);
        } catch (RuntimeException $e) {
            // 409 y no 422: lo que se mandó es válido: es el estado de la base
            // el que no deja. La diferencia importa en el teléfono, que ofrece
            // buscar otro producto en vez de corregir el campo.
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
