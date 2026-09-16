<?php

namespace Tests\Unit;

use App\Services\Dte\FirmaXml;
use App\Services\Dte\Sii;
use App\Services\Dte\Sobre;
use App\Services\Dte\TipoDte;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * El sobre y el camino al SII.
 *
 * La comprobación contra la realidad es `dte:verifica-sobre`, que reproduce los
 * 210 envíos que el SII ya aceptó. Aquí quedan fijadas las reglas de las que
 * aquello depende y que se rompen sin hacer ruido.
 */
class SobreTest extends TestCase
{
    /**
     * El ámbito es la regla que costó descubrir y la que más caro sale: firmar
     * el sobre sin los espacios de nombres que hereda de `<EnvioDTE>` produce
     * una firma que no valida, y el SII rechaza el envío entero.
     */
    public function test_la_forma_canonica_arrastra_los_espacios_de_nombres_heredados(): void
    {
        $firma = new FirmaXml;
        $suelto = $firma->canonico('<SetDTE ID="x"><a>1</a></SetDTE>');
        $enAmbito = $firma->canonico('<SetDTE ID="x"><a>1</a></SetDTE>', [
            '' => 'http://www.sii.cl/SiiDte',
            'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        ]);

        $this->assertStringStartsWith('<SetDTE ID="x">', $suelto);
        $this->assertStringStartsWith(
            '<SetDTE xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ID="x">',
            $enAmbito
        );
        $this->assertNotSame($suelto, $enAmbito);
    }

    /**
     * Y al revés: el documento se firma **suelto**. Suena al revés de lo
     * anterior, pero es lo que hace Softland y lo que el SII aceptó 209 veces,
     * porque saca cada `<DTE>` del sobre y lo valida por separado.
     */
    public function test_sin_ambito_la_forma_canonica_no_cambia(): void
    {
        $firma = new FirmaXml;

        $this->assertSame(
            $firma->canonico('<Documento ID="x"><a>1</a></Documento>'),
            $firma->canonico('<Documento ID="x"><a>1</a></Documento>', [])
        );
    }

    public function test_el_identificador_del_envio_lleva_tipo_y_folio_rellenos(): void
    {
        $sobre = new Sobre($this->certificadoQueNoHace(), null);

        $this->assertSame(
            'SS77828631-9SS033F0000000234',
            $sobre->identificador('77828631-9', TipoDte::FACTURA, 234)
        );
        $this->assertSame(
            'SS77828631-9SS061F0000000007',
            $sobre->identificador('77828631-9', TipoDte::NOTA_CREDITO, 7)
        );
    }

    public function test_un_sobre_sin_documentos_no_se_manda(): void
    {
        $this->expectException(RuntimeException::class);

        (new Sobre($this->certificadoQueNoHace(), null))->armar([]);
    }

    /** El SII nunca quiere el RUT entero: siempre partido, y con la K en mayúscula. */
    public function test_el_rut_se_parte_en_numero_y_digito(): void
    {
        $this->assertSame(['77828631', '9'], Sii::parteRut('77828631-9'));
        $this->assertSame(['60803000', 'K'], Sii::parteRut('60803000-k'));
        $this->assertSame(['17421371', '2'], Sii::parteRut('17.421.371-2'));
    }

    public function test_un_rut_ilegible_se_dice_antes_de_salir_a_la_red(): void
    {
        $this->expectException(RuntimeException::class);

        Sii::parteRut('');
    }

    /**
     * Un certificado que sirve para construir el objeto pero no firma nada: lo
     * que se prueba aquí no llega a usar ninguna llave.
     */
    private function certificadoQueNoHace(): \App\Services\Dte\Certificado
    {
        $r = new \ReflectionClass(\App\Services\Dte\Certificado::class);

        return $r->newInstanceWithoutConstructor();
    }
}
