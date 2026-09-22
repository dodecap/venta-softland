<?php

namespace Tests\Unit;

use App\Support\Rutas;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Las direcciones que el servidor escribe tienen que valer en los dos mundos:
 * desde la oficina, donde la app vive en `/venta-softland/…`, y desde fuera,
 * donde el proxy la publica en `/…`. Por eso van relativas.
 *
 * `getPathInfo()` es la ruta **sin** la carpeta del Alias, así que vale igual
 * en los dos casos; de ahí que la cuenta salga bien sin saber nada del proxy.
 */
class RutasTest extends TestCase
{
    #[DataProvider('casos')]
    public function test_la_ruta_relativa_sube_los_niveles_justos(string $desde, string $destino, string $esperada): void
    {
        $this->assertSame($esperada, Rutas::relativa(Request::create($desde), $destino));
    }

    public static function casos(): array
    {
        return [
            'desde la raíz' => ['/', 'setup', 'setup'],
            'desde una página de primer nivel' => ['/setup', 'setup/listo', 'setup/listo'],
            'de vuelta a la raíz desde primer nivel' => ['/setup', '', ''],
            'desde una de segundo nivel' => ['/setup/listo', 'app', '../app'],
            'desde la API' => ['/api/ping', 'setup', '../setup'],
            'desde lo más hondo que hay' => ['/api/catalogo/cotizaciones', 'setup', '../../setup'],
            'la barra de delante del destino sobra' => ['/', '/setup', 'setup'],
        ];
    }

    /**
     * Un `Location` vacío no es una dirección válida. Quedarse donde se está
     * —volver a la raíz desde una página de primer nivel— se dice con `./`.
     */
    public function test_volver_a_la_raiz_nunca_manda_un_location_vacio(): void
    {
        $r = Rutas::irA(Request::create('/setup'));

        $this->assertSame(302, $r->getStatusCode());
        $this->assertSame('./', $r->headers->get('Location'));
    }

    public function test_la_redireccion_no_lleva_nombre_de_maquina(): void
    {
        foreach (['/', '/setup', '/api/ping'] as $desde) {
            $location = Rutas::irA(Request::create($desde), 'setup')->headers->get('Location');

            $this->assertStringNotContainsString('http', $location, "desde $desde");
            $this->assertStringStartsNotWith('/', $location, "desde $desde");
        }
    }
}
