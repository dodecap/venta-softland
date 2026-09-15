<?php

namespace Tests\Unit;

use App\Services\Dte\FirmaXml;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * La canonicalización, que es de lo que depende que un DTE valide.
 *
 * Lo que se comprueba contra documentos reales —209 que el SII ya aceptó— es
 * `dte:verifica-xml`. Aquí queda fijado el comportamiento del que todo aquello
 * cuelga, y que es fácil romper sin darse cuenta.
 */
class FirmaXmlTest extends TestCase
{
    private function firma(): FirmaXml
    {
        return new FirmaXml;
    }

    public function test_el_resumen_no_necesita_certificado(): void
    {
        // Resumir es canonicalizar y aplicar SHA1: ninguna llave interviene.
        // Exigir el certificado para esto obligaría a tenerlo a mano para
        // comprobar algo que no lo requiere.
        $this->assertNotSame('', $this->firma()->resumen('<a>1</a>'));
    }

    public function test_firmar_sin_certificado_falla_diciendo_por_que(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/certificado/');

        $this->firma()->firmar('<a ID="x">1</a>', 'x');
    }

    public function test_los_comentarios_no_se_firman(): void
    {
        // Por eso el comentario de versión que escribe Softland da igual… pero
        // los saltos de línea que lo rodean no, y de ahí que el generador lo
        // escriba de todas formas.
        $con = $this->firma()->canonico('<a><!-- hola --><b>1</b></a>');

        $this->assertStringNotContainsString('hola', $con);
        $this->assertStringContainsString('<b>1</b>', $con);
    }

    public function test_el_espacio_entre_elementos_si_se_firma(): void
    {
        // Es la trampa del asunto: el mismo documento escrito todo seguido y
        // escrito con un elemento por línea tiene resúmenes distintos.
        $seguido = $this->firma()->resumen('<a><b>1</b></a>');
        $enLineas = $this->firma()->resumen("<a>\n<b>1</b>\n</a>");

        $this->assertNotSame($seguido, $enLineas);
    }

    public function test_los_saltos_de_windows_se_normalizan(): void
    {
        // La canonicalización convierte CRLF en LF, así que el mismo documento
        // escrito en Windows y en Linux firma igual.
        $this->assertSame(
            $this->firma()->resumen("<a>\n<b>1</b>\n</a>"),
            $this->firma()->resumen("<a>\r\n<b>1</b>\r\n</a>"),
        );
    }

    public function test_el_acento_se_lee_como_iso_8859_1(): void
    {
        // El DTE se declara ISO-8859-1. Leer el acento como UTF-8 da otra forma
        // canónica, otra firma, y un rechazo del SII que aparece días después.
        $iso = $this->firma()->canonico("<a>GESTI\xD3N</a>");

        // En la forma canónica, que es UTF-8, la Ó son dos bytes.
        $this->assertStringContainsString("GESTI\xC3\x93N", $iso);
    }

    public function test_un_xml_roto_se_queja_en_vez_de_firmar_basura(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/canonicalizar/');

        $this->firma()->canonico('<a><b></a>');
    }

    public function test_el_mismo_documento_da_siempre_el_mismo_resumen(): void
    {
        // Si no fuera determinista, reproducir un documento ya emitido no
        // probaría nada.
        $xml = '<Documento ID="D033F1">'."\n".'<Encabezado>'."\n".'<x>1</x>'."\n".'</Encabezado>'."\n".'</Documento>';

        $this->assertSame($this->firma()->resumen($xml), $this->firma()->resumen($xml));
    }
}
