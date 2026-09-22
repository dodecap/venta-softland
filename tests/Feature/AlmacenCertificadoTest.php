<?php

namespace Tests\Feature;

use App\Services\Dte\AlmacenCertificado;
use App\Services\Dte\Certificado;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * El certificado que se sube desde la app.
 *
 * El PKCS#12 de estas pruebas se fabrica aquí mismo con OpenSSL: no hace falta
 * —ni debe— meter el de la empresa en el repositorio, y lo que se comprueba es
 * el almacén, no ese certificado en concreto.
 *
 * La carpeta se desvía a una temporal. Sin eso, correr las pruebas en el
 * servidor de producción escribiría encima del certificado con que se factura.
 */
class AlmacenCertificadoTest extends TestCase
{
    private string $carpeta;

    private string $pfx;

    private const CLAVE = 'la-clave-de-la-prueba';

    protected function setUp(): void
    {
        parent::setUp();

        $this->carpeta = storage_path('framework/testing/cert-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->carpeta);
        config(['dte.certificado.almacen' => $this->carpeta]);

        $this->pfx = $this->carpeta.'/origen.pfx';
        File::put($this->pfx, $this->fabricarPkcs12());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->carpeta);
        parent::tearDown();
    }

    /**
     * Un PKCS#12 de mentira, hecho aquí mismo.
     *
     * No se guarda uno en el repositorio: el de la empresa no puede estar ahí y
     * uno de prueba sería una llave privada versionada, que es justo lo que no
     * se hace en este proyecto.
     *
     * En Windows, OpenSSL no encuentra su `openssl.cnf` solo y **todas** las
     * funciones que crean algo devuelven `false` sin decir por qué —la primera
     * es `openssl_pkey_new`, no la que se sospecha—. Así que primero se busca
     * una configuración que sirva y después se usa la misma en toda la cadena.
     * Si no hay ninguna, la prueba se salta con su motivo en vez de fallar por
     * algo que no es lo que se está probando.
     */
    private function fabricarPkcs12(): string
    {
        $opciones = $this->opcionesOpenssl();

        if ($opciones === null) {
            $this->markTestSkipped('OpenSSL no encuentra un openssl.cnf con el que crear un certificado de prueba.');
        }

        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $opciones);
        $csr = openssl_csr_new(['CN' => 'PRUEBA DE FIRMA', 'C' => 'CL'], $llave, $opciones);
        $x509 = openssl_csr_sign($csr, null, $llave, 30, $opciones);

        openssl_pkcs12_export($x509, $salida, $llave, self::CLAVE, $opciones);

        return $salida;
    }

    /**
     * La primera configuración con la que OpenSSL sabe generar una llave, o
     * null si no hay ninguna. En Linux la de por defecto basta; en el XAMPP de
     * Windows hay que decírsela.
     *
     * @return array<string, string>|null
     */
    private function opcionesOpenssl(): ?array
    {
        $candidatas = [
            null,
            getenv('OPENSSL_CONF') ?: null,
            'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
            'C:\\xampp\\apache\\conf\\openssl.cnf',
        ];

        foreach ($candidatas as $cnf) {
            if ($cnf !== null && ! is_file($cnf)) {
                continue;
            }

            $opciones = ['digest_alg' => 'sha256'] + ($cnf === null ? [] : ['config' => $cnf]);

            if (@openssl_pkey_new(['private_key_bits' => 2048] + $opciones) !== false) {
                return $opciones;
            }
        }

        // Vaciar la cola de errores para no contaminar la prueba siguiente.
        while (openssl_error_string()) {
        }

        return null;
    }

    private function subida(): UploadedFile
    {
        return new UploadedFile($this->pfx, 'firma.pfx', null, null, true);
    }

    public function test_se_guarda_y_queda_en_uso(): void
    {
        $this->assertFalse(AlmacenCertificado::hay());

        $ficha = AlmacenCertificado::guardar($this->subida(), self::CLAVE, 'alguien');

        $this->assertSame('PRUEBA DE FIRMA', $ficha['sujeto']);
        $this->assertTrue(AlmacenCertificado::hay());

        // Y es el que se usa: no basta con que el archivo esté escrito.
        $this->assertSame('PRUEBA DE FIRMA', Certificado::desdeConfiguracion()->sujeto);
    }

    /**
     * Lo que ya funciona no se toca hasta que lo nuevo valga. Subir un archivo
     * con la clave equivocada no puede dejar a la empresa sin poder emitir.
     */
    public function test_una_clave_equivocada_no_se_lleva_por_delante_lo_que_habia(): void
    {
        AlmacenCertificado::guardar($this->subida(), self::CLAVE, 'alguien');

        try {
            AlmacenCertificado::guardar($this->subida(), 'no-es-esta', 'otro');
            $this->fail('aceptó una clave que no era');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('la clave no corresponde', $e->getMessage());
        }

        $this->assertTrue(AlmacenCertificado::hay());
        $this->assertSame('PRUEBA DE FIRMA', Certificado::desdeConfiguracion()->sujeto);
    }

    /** Lo que la app ve no lleva la clave: ese arreglo va a una respuesta JSON. */
    public function test_el_resumen_nunca_lleva_la_clave(): void
    {
        AlmacenCertificado::guardar($this->subida(), self::CLAVE, 'alguien');

        $resumen = AlmacenCertificado::resumen();

        $this->assertArrayNotHasKey('clave', $resumen);
        $this->assertStringNotContainsString(self::CLAVE, json_encode($resumen));
        $this->assertSame('alguien', $resumen['subido_por']);
    }

    /** Y en disco tampoco está en claro. */
    public function test_la_clave_se_guarda_cifrada(): void
    {
        AlmacenCertificado::guardar($this->subida(), self::CLAVE, 'alguien');

        $enDisco = File::get($this->carpeta.'/certificado-app.json');

        $this->assertStringNotContainsString(self::CLAVE, $enDisco);
        $this->assertSame(self::CLAVE, AlmacenCertificado::clave());
    }

    /**
     * Quitar el subido es deshacer la subida, no borrar la firma de la empresa:
     * el archivo al que apunta `DTE_CERT_RUTA` tiene que seguir donde estaba.
     */
    public function test_quitar_el_subido_no_toca_el_del_env(): void
    {
        $delEnv = $this->carpeta.'/certificado.pfx';
        File::copy($this->pfx, $delEnv);
        config(['dte.certificado.ruta' => $delEnv, 'dte.certificado.clave' => self::CLAVE]);

        AlmacenCertificado::guardar($this->subida(), self::CLAVE, 'alguien');
        AlmacenCertificado::olvidar();

        $this->assertFalse(AlmacenCertificado::hay());
        $this->assertTrue(File::exists($delEnv), 'se llevó por delante el del .env');
        $this->assertSame('PRUEBA DE FIRMA', Certificado::desdeConfiguracion()->sujeto);
    }

    /** Sin ninguno de los dos, el mensaje dice dónde se arregla. */
    public function test_sin_certificado_el_error_dice_donde_se_pone(): void
    {
        config(['dte.certificado.ruta' => $this->carpeta.'/no-existe.pfx']);

        $this->expectExceptionMessageMatches('/Configuración → Certificado digital/u');

        Certificado::desdeConfiguracion();
    }
}
