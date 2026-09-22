<?php

namespace App\Http\Controllers;

use App\Services\Reparto\Apk;
use Com\Tecnick\Barcode\Barcode;
use Illuminate\Http\Request;
use Throwable;

/**
 * La página desde la que se instala la app en un teléfono nuevo.
 *
 * Es la segunda —y última— página HTML del proyecto, y existe por la misma
 * razón que `/setup`: ocurre **antes** de que el teléfono tenga la app, así que
 * no puede estar dentro de la app.
 *
 * ## El código QR y la dirección que lleva dentro
 *
 * Un QR tiene que llevar una dirección **absoluta**, y ahí está la trampa de
 * esta instalación: el servidor se alcanza por dos caminos que no dan lo mismo
 * —`http://192.168.1.55:8086/venta-softland` desde la oficina y
 * `https://venta.netdomain.cl` desde fuera— y Laravel, detrás del proxy IIS,
 * **no sabe por cuál le hablaron**. Medido: pedida por el proxy, esta misma
 * instalación genera `http://venta.netdomain.cl/venta-softland`, con el esquema
 * y la carpeta equivocados. Un QR con esa dirección dentro no abre nada.
 *
 * Quien sí lo sabe con certeza es **el navegador que está mirando la página**.
 * Por eso el QR se pide en una segunda vuelta: la página lleva dos líneas de
 * guion que arman la dirección desde `location` y se la pasan a `qr.svg`. Lo
 * que el servidor dibuja sin que nadie se lo diga es su mejor conjetura, y se
 * ve reemplazado en cuanto el navegador contesta.
 *
 * La dirección que llega se comprueba contra el `Host` de la petición: esto
 * dibuja códigos de **esta** instalación, no es un generador de QR para
 * cualquiera que pase.
 */
class DescargaController extends Controller
{
    public function __construct(private Apk $apk) {}

    public function pagina(Request $request)
    {
        return view('descarga', [
            'apk' => $this->apk->ultimo(),
            'directa' => $request->url().'/apk',
        ]);
    }

    /** El QR de la dirección de descarga, en SVG para que no pixele al imprimirlo. */
    public function qr(Request $request)
    {
        $destino = $this->destinoValido($request) ?? $request->root().'/app/apk';

        try {
            $svg = (new Barcode)
                ->getBarcodeObj('QRCODE,M', $destino, -8, -8, 'black', [0, 0, 0, 0])
                ->getSvgCode();
        } catch (Throwable) {
            abort(500, 'No se pudo dibujar el código.');
        }

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** El archivo. Siempre el más nuevo: la dirección no lleva la versión dentro. */
    public function apk()
    {
        $a = $this->apk->ultimo();

        abort_if($a === null, 404, 'Todavía no se ha publicado ningún instalable en este servidor.');

        // El tipo MIME importa: con `application/octet-stream` Android no
        // ofrece instalar, solo guardar un archivo que nadie vuelve a tocar.
        return response()->download($a['ruta'], $a['nombre'], [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }

    /**
     * La dirección que dice el navegador, si es creíble.
     *
     * Creíble = absoluta, http o https, y **del mismo servidor que atendió la
     * petición**. Sin esa última comprobación esto sería un dibujante de
     * códigos QR abierto a internet, que es un sitio cómodo desde el que
     * repartir enlaces con la cara de la empresa.
     */
    private function destinoValido(Request $request): ?string
    {
        $u = (string) $request->query('u', '');

        if ($u === '' || strlen($u) > 300) {
            return null;
        }

        $p = parse_url($u);

        if (! is_array($p) || ! in_array($p['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        return strcasecmp($p['host'] ?? '', $request->getHost()) === 0 ? $u : null;
    }
}
