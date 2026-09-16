<?php

namespace App\Http\Controllers\Api;

use App\Models\Usuario;
use Illuminate\Http\Request;

/**
 * Quién puede ver y tocar qué, y a nombre de quién queda lo que se escribe.
 *
 * Es la misma regla para la cotización, la nota de venta y la factura, así que
 * vive en un solo sitio. Estaba copiada en dos controladores, y de las dos
 * copias sólo una sabía qué hacer cuando quien opera no es vendedor: por eso
 * facturar reventaba para administración y facturación, que son justamente
 * quienes facturan.
 */
trait AlcancePorVendedor
{
    protected function usuario(Request $request): Usuario
    {
        return $request->attributes->get('usuario');
    }

    /**
     * ¿Este usuario puede ver/tocar un documento de este vendedor?
     *
     * Misma regla que la descarga de maestros: el vendedor ve lo suyo, el
     * supervisor lo de su gente, administración y facturación todo. Y sin
     * contexto no se abre nada.
     */
    protected function alcanza(Request $request, ?string $venCod): bool
    {
        $visibles = $this->usuario($request)->vendedoresVisibles();

        return $visibles === null || in_array(trim((string) $venCod), $visibles, true);
    }

    /**
     * A nombre de qué vendedor queda el documento.
     *
     * Por omisión, el de quien lo está escribiendo. Un supervisor puede grabar
     * a nombre de alguien de su gente — pasa en la práctica, cuando entra un
     * pedido por teléfono y lo carga el jefe — pero un vendedor no puede
     * atribuirle una venta a otro: eso descuadraría las comisiones de los dos.
     *
     * **Nunca devuelve null.** Un documento sin vendedor no aparece en las
     * búsquedas del Softland de escritorio: de las 2.351 cotizaciones de
     * INNOVAGES, la única con `VenCod` nulo era una escrita por esta app,
     * grabada por un administrador que no tiene vendedor asociado. Antes que
     * escribir un documento invisible, se rechaza y se dice qué falta.
     *
     * Ojo con el caso contrario, que es el de la factura: cuando el documento
     * **hereda** su vendedor de otro —la factura de su nota de venta, la nota
     * de crédito de la factura que anula— esto no se pregunta siquiera. Ahí la
     * venta ya tiene dueño.
     */
    protected function vendedorDe(array $data, Request $request): string
    {
        $u = $this->usuario($request);
        $pedido = trim((string) ($data['vendedor'] ?? ''));

        if ($pedido === '') {
            $propio = trim((string) $u->ven_cod);

            if ($propio === '') {
                $this->rechazar(['vendedor' => 'Elige el vendedor: tu usuario no tiene uno asociado.']);
            }

            return $propio;
        }

        if (! $this->alcanza($request, $pedido)) {
            $this->rechazar(['vendedor' => 'No puedes grabar documentos a nombre de otro vendedor.']);
        }

        return $pedido;
    }

    protected function rechazar(array $errores): never
    {
        abort(response()->json([
            'message' => reset($errores),
            'errors' => array_map(fn ($m) => [$m], $errores),
        ], 422));
    }
}
