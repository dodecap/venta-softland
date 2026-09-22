<?php

namespace Tests\Feature;

use App\Services\Actualizacion\Actualizador;
use App\Services\Actualizacion\Publicacion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lo que decide si una actualización sale bien, mirado sin actualizar nada.
 *
 * La actualización entera —bajar, comprobar, descomprimir encima, migrar y
 * volver atrás— se ensayó contra el servidor de verdad con una publicación de
 * mentira servida por Apache. Lo que se comprueba aquí es la parte que no se
 * ve al mirarla funcionar: cómo se lee la respuesta de GitHub, qué pieza es
 * cuál y qué pasa cuando algo no cuadra.
 */
class ActualizacionTest extends TestCase
{
    private const RELEASE = [
        'tag_name' => 'v0.99.0',
        'draft' => false,
        'published_at' => '2026-09-22T12:00:00Z',
        'body' => "- Una cosa\n- Otra",
        'assets' => [
            ['name' => 'venta-softland-0.99.0.apk', 'size' => 5242880,
                'browser_download_url' => 'https://ejemplo/apk',
                'digest' => 'sha256:'.self::CERO],
            ['name' => 'vendor-9ea5350edab5.tgz', 'size' => 18000000,
                'browser_download_url' => 'https://ejemplo/vendor',
                'digest' => 'sha256:'.self::CERO],
            ['name' => 'venta-softland-0.99.0-servidor.tar.gz', 'size' => 1400000,
                'browser_download_url' => 'https://ejemplo/servidor',
                'digest' => 'sha256:'.self::CERO],
        ],
    ];

    private const CERO = '0000000000000000000000000000000000000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        config(['actualizacion.api' => 'https://ejemplo/api', 'actualizacion.repositorio' => 'quien/sea']);
    }

    private function publican(array $cambios = []): void
    {
        Http::fake(['ejemplo/api/*' => Http::response(array_merge(self::RELEASE, $cambios))]);
    }

    public function test_la_etiqueta_se_lee_como_version(): void
    {
        $this->publican();

        $p = Publicacion::ultima();

        $this->assertNotNull($p);
        $this->assertSame('0.99.0', $p->version());
        $this->assertTrue($p->esMasNuevaQue('0.46.0'));
        $this->assertFalse($p->esMasNuevaQue('0.99.0'));
        $this->assertFalse($p->esMasNuevaQue('1.0.0'));
    }

    /** Un repositorio recién bifurcado no tiene publicaciones, y eso no es un error. */
    public function test_sin_publicaciones_no_hay_version_nueva(): void
    {
        Http::fake(['ejemplo/api/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->assertNull(Publicacion::ultima());

        $r = (new Actualizador)->comprobar();

        $this->assertFalse($r['hay']);
        $this->assertNull($r['problema']);
    }

    /**
     * Un borrador se ve con token y no se baja sin él: ofrecerlo sería enseñar
     * una versión nueva que nadie puede instalar.
     */
    public function test_un_borrador_no_cuenta_como_publicacion(): void
    {
        $this->publican(['draft' => true]);

        $this->assertNull(Publicacion::ultima());
    }

    /**
     * Las piezas se buscan por cómo se llaman, no por el orden en que vengan:
     * GitHub las devuelve como quiere. Aquí vienen al revés a propósito.
     */
    public function test_cada_pieza_se_reconoce_por_su_nombre(): void
    {
        $this->publican();
        $p = Publicacion::ultima();

        $this->assertSame('venta-softland-0.99.0-servidor.tar.gz', $p->pieza('servidor')['nombre']);
        $this->assertSame('venta-softland-0.99.0.apk', $p->pieza('apk')['nombre']);
        $this->assertSame('vendor-9ea5350edab5.tgz', $p->pieza('vendor')['nombre']);
    }

    /**
     * El nombre del paquete de dependencias lleva dentro el sha256 del
     * `composer.lock`: es lo único que le dice al servidor si tiene que bajarse
     * 17 MB o puede saltárselos.
     */
    public function test_el_paquete_de_dependencias_declara_el_composer_lock(): void
    {
        $this->publican();

        $this->assertSame('9ea5350edab5', Publicacion::ultima()->pieza('vendor')['marca']);
    }

    /** GitHub firma cada archivo con `sha256:…`; lo que se guarda es el hash pelado. */
    public function test_el_sha256_se_toma_de_github(): void
    {
        $this->publican();

        $this->assertSame(self::CERO, Publicacion::ultima()->pieza('servidor')['sha256']);
    }

    /** Las publicaciones viejas no traen `digest`, y eso no puede romper nada. */
    public function test_una_pieza_sin_sha256_no_revienta(): void
    {
        $assets = self::RELEASE['assets'];
        unset($assets[2]['digest']);
        $this->publican(['assets' => $assets]);

        $this->assertNull(Publicacion::ultima()->pieza('servidor')['sha256']);
    }

    /** Una publicación a medias se dice, no se instala a medias. */
    public function test_sin_el_paquete_del_servidor_no_se_instala(): void
    {
        $this->publican(['assets' => [self::RELEASE['assets'][0]]]);

        $this->expectExceptionMessageMatches('/publicada a medias/');

        (new Actualizador($this->raizDePrueba()))->aplicar(Publicacion::ultima());
    }

    /**
     * Lo que llega tiene que ser lo que GitHub dice que subió. Un proxy que
     * devuelve su propia página de error con código 200 es exactamente lo que
     * esto para: sin comprobarlo, esa página acabaría descomprimida encima del
     * servidor.
     */
    public function test_una_descarga_que_no_cuadra_no_llega_a_tocar_el_arbol(): void
    {
        $raiz = $this->raizDePrueba();

        Http::fake([
            'ejemplo/api/*' => Http::response(self::RELEASE),
            'ejemplo/servidor' => Http::response('esto no es el paquete'),
        ]);

        try {
            (new Actualizador($raiz))->aplicar(Publicacion::ultima());
            $this->fail('tenía que quejarse de que no llegó entero');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no llegó entero', $e->getMessage());
        }

        $this->assertSame([], File::files($raiz), 'no puede haber quedado nada escrito');

        $estado = Actualizador::estado();
        $this->assertSame('error', $estado['estado']);
        $this->assertStringContainsString('no llegó entero', $estado['error']);
    }

    private function raizDePrueba(): string
    {
        $raiz = storage_path('app/private/prueba-actualizacion');
        File::deleteDirectory($raiz);
        File::ensureDirectoryExists($raiz);

        return $raiz;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/prueba-actualizacion'));
        File::delete(Actualizador::rutaEstado());

        parent::tearDown();
    }
}
