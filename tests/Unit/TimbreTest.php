<?php

namespace Tests\Unit;

use App\Services\Dte\Caf;
use App\Services\Dte\Timbre;
use App\Services\Dte\TipoDte;
use PHPUnit\Framework\TestCase;

/**
 * El timbre electrónico, sin base de datos.
 *
 * Lo que se comprueba contra documentos reales —615 de ellos, de dos empresas—
 * es el comando `dte:verifica-timbre`, que necesita Softland al lado. Aquí
 * queda lo que se puede fijar sin servidor: las reglas que costó descubrir y
 * que un cambio descuidado volvería a romper en silencio, porque el síntoma no
 * aparece hasta que el SII rechaza un documento.
 */
class TimbreTest extends TestCase
{
    /**
     * Una llave RSA de 512 bits **de juguete**, generada para esta prueba y
     * para nada más.
     *
     * No firma ningún documento real, no corresponde a ningún CAF del SII y no
     * sirve para nada fuera de este archivo: está aquí para que la prueba no
     * dependa de generar llaves al vuelo, que en el PHP de Windows falla
     * cuando no encuentra su `openssl.cnf`. Los CAF de verdad traen llaves de
     * este mismo largo, y viven en la base de datos, nunca en el repositorio.
     */
    private const LLAVE_DE_JUGUETE = <<<'PEM'
        -----BEGIN RSA PRIVATE KEY-----
        MIIBOwIBAAJBAL0jiVExEleZLvQIWCY6lF8Aq4W1cLxoAh5l/0TVZWcO12eYfdZJ
        BWRjvBklEcIJhYtTdOZftIYtUM7f5yNktw8CAwEAAQJAZYmhW1wbu7k50rp0EDnc
        k0/5xPNODWdM0+Lv8pUZNgR+C9H4Q3pqbblE9OxRLRt9IPqnVwZTQCsyBYqkiJDl
        CQIhAOj4BFbw8yZWVoEQF1lAiNvcIKrN7eWMkQ4LG5Ur/64LAiEAz9ZEqIxE8K8o
        OlBJN8OlZD5pNMR8iBuuiY6jYU2dcY0CIDmWVTxIg1JOtUNh/uOJGEuAtnKCRPQh
        MxoNlNvi7GjRAiEAglPSaf7LnEG58Bc4UoeUxu97+WLc1FzHberL+NA60mECIQDh
        ykVag18wyuqqseM4ZokrStPx9vwX7E2KiDXddDJ0xw==
        -----END RSA PRIVATE KEY-----
        PEM;

    /** Un CAF de mentira, autoconsistente: su llave pública es la de su llave privada. */
    private function caf(int $desde = 1, int $hasta = 100): Caf
    {
        $privada = self::LLAVE_DE_JUGUETE;
        $detalle = openssl_pkey_get_details(openssl_pkey_get_private($privada));

        $xml = '<AUTORIZACION><CAF version="1.0"><DA>'
            .'<RE>77828631-9</RE><RS>EMPRESA DE PRUEBA</RS><TD>33</TD>'
            ."<RNG><D>{$desde}</D><H>{$hasta}</H></RNG><FA>2026-01-01</FA>"
            .'<RSAPK><M>'.base64_encode($detalle['rsa']['n']).'</M>'
            .'<E>'.base64_encode($detalle['rsa']['e']).'</E></RSAPK>'
            .'<IDK>300</IDK></DA>'
            .'<FRMA algoritmo="SHA1withRSA">x</FRMA></CAF>'
            ."<RSASK>{$privada}</RSASK></AUTORIZACION>";

        return Caf::desdeAutorizacion($xml);
    }

    private function timbre(): Timbre
    {
        return new Timbre($this->caf());
    }

    public function test_el_dd_lleva_los_campos_en_el_orden_del_sii(): void
    {
        $dd = $this->timbre()->datos(
            rutEmisor: '77828631-9',
            folio: 7,
            fecha: '2026-09-15',
            rutReceptor: '89889200-K',
            razonSocial: 'CLIENTE DE PRUEBA',
            monto: 262469,
            primerItem: 'COMISION SOFTWARE',
            sello: '2026-09-15T10:00:00',
        );

        $this->assertStringStartsWith('<DD><RE>77828631-9</RE><TD>33</TD><F>7</F>', $dd);
        $this->assertStringEndsWith('<TSTED>2026-09-15T10:00:00</TSTED></DD>', $dd);
        $this->assertStringContainsString('<RR>89889200-K</RR><RSR>CLIENTE DE PRUEBA</RSR>', $dd);
        $this->assertStringContainsString('<MNT>262469</MNT><IT1>COMISION SOFTWARE</IT1>', $dd);
    }

    public function test_el_monto_va_sin_signo(): void
    {
        // En `iw_gsaen` el total de una nota de crédito es negativo; en el
        // timbre va positivo. Las 12 notas de crédito de INNOVAGES fallaban
        // todas por esto.
        $dd = $this->timbre()->datos(
            rutEmisor: '77828631-9', folio: 1, fecha: '2026-09-15',
            rutReceptor: '89889200-K', razonSocial: 'X',
            monto: -341213, primerItem: 'Y', sello: '2026-09-15T10:00:00',
        );

        $this->assertStringContainsString('<MNT>341213</MNT>', $dd);
    }

    public function test_recorta_la_razon_social_y_el_item_a_cuarenta(): void
    {
        $dd = $this->timbre()->datos(
            rutEmisor: '77828631-9', folio: 1, fecha: '2026-09-15',
            rutReceptor: '89889200-K',
            razonSocial: str_repeat('A', 60),
            monto: 1000,
            primerItem: str_repeat('B', 60),
            sello: '2026-09-15T10:00:00',
        );

        $this->assertStringContainsString('<RSR>'.str_repeat('A', 40).'</RSR>', $dd);
        $this->assertStringContainsString('<IT1>'.str_repeat('B', 40).'</IT1>', $dd);
    }

    public function test_el_texto_va_en_iso_8859_1(): void
    {
        // El XML del SII se declara ISO-8859-1 y la firma cubre esos bytes.
        // Escribir el acento en UTF-8 da una firma que no valida, y el error
        // solo aparece cuando el SII contesta.
        $dd = $this->timbre()->datos(
            rutEmisor: '77828631-9', folio: 1, fecha: '2026-09-15',
            rutReceptor: '89889200-K', razonSocial: 'GESTIÓN',
            monto: 1000, primerItem: 'Facturación', sello: '2026-09-15T10:00:00',
        );

        $this->assertStringContainsString("GESTI\xD3N", $dd);
        $this->assertStringNotContainsString("GESTI\xC3\x93N", $dd);
    }

    public function test_el_rut_va_sin_puntos_y_con_k_mayuscula(): void
    {
        $dd = $this->timbre()->datos(
            rutEmisor: '77.828.631-9', folio: 1, fecha: '2026-09-15',
            rutReceptor: '89.889.200-k', razonSocial: 'X',
            monto: 1, primerItem: 'Y', sello: '2026-09-15T10:00:00',
        );

        $this->assertStringContainsString('<RE>77828631-9</RE>', $dd);
        $this->assertStringContainsString('<RR>89889200-K</RR>', $dd);
    }

    public function test_el_caf_se_incrusta_sin_espacios_entre_elementos(): void
    {
        // Softland lo guarda separado y lo escribe pegado. Como se firman
        // bytes, incrustar la versión separada da un documento que el SII
        // rechaza.
        $dd = $this->timbre()->datos(
            rutEmisor: '77828631-9', folio: 1, fecha: '2026-09-15',
            rutReceptor: '89889200-K', razonSocial: 'X',
            monto: 1, primerItem: 'Y', sello: '2026-09-15T10:00:00',
        );

        $this->assertStringContainsString('<CAF version="1.0"><DA><RE>', $dd);
        // Pero el espacio de dentro del texto sí se respeta.
        $this->assertStringContainsString('<RS>EMPRESA DE PRUEBA</RS>', $dd);
    }

    public function test_un_folio_fuera_del_caf_no_se_timbra(): void
    {
        $this->expectExceptionMessageMatches('/no cae en el CAF/');

        (new Timbre($this->caf(1, 10)))->datos(
            rutEmisor: '77828631-9', folio: 11, fecha: '2026-09-15',
            rutReceptor: '89889200-K', razonSocial: 'X',
            monto: 1, primerItem: 'Y',
        );
    }

    public function test_la_firma_valida_contra_la_llave_publica_del_caf(): void
    {
        $caf = $this->caf();
        $timbre = new Timbre($caf);

        $ted = $timbre->armar(
            rutEmisor: '77828631-9', folio: 3, fecha: '2026-09-15',
            rutReceptor: '89889200-K', razonSocial: 'CLIENTE',
            monto: 5000, primerItem: 'SERVICIO', sello: '2026-09-15T10:00:00',
        );

        [$dd, $frmt] = Timbre::extraer($ted);

        $this->assertTrue($timbre->verificar($dd, $frmt));
        $this->assertFalse($timbre->verificar($dd.' ', $frmt));
    }

    public function test_extraer_devuelve_los_bytes_originales(): void
    {
        // Si se parseara y volviera a serializar, la firma dejaría de calzar.
        $xml = '<DTE><Documento><DD><RE>1-9</RE></DD>'
            .'<FRMT algoritmo="SHA1withRSA">abc==</FRMT></Documento></DTE>';

        $this->assertSame(['<DD><RE>1-9</RE></DD>', 'abc=='], Timbre::extraer($xml));
        $this->assertNull(Timbre::extraer('<DTE></DTE>'));
    }

    public function test_los_tipos_conocen_su_pareja_en_softland(): void
    {
        $this->assertSame(['F', 'T'], TipoDte::FACTURA->claveSoftland());
        $this->assertSame(['B', 'T'], TipoDte::BOLETA->claveSoftland());
        $this->assertSame(TipoDte::NOTA_CREDITO, TipoDte::desdeSoftland('N', 'T'));
        $this->assertSame(TipoDte::BOLETA_EXENTA, TipoDte::desdeSoftland('b', 's'));
        $this->assertNull(TipoDte::desdeSoftland('G', 'A'));
    }

    public function test_solo_la_boleta_va_por_la_api_rest_y_admite_receptor_anonimo(): void
    {
        $this->assertTrue(TipoDte::BOLETA->porApiRest());
        $this->assertTrue(TipoDte::BOLETA->exigeConsumoFolios());
        $this->assertSame('66666666-6', TipoDte::BOLETA->receptorAnonimo());
        $this->assertSame('Cliente Generico', TipoDte::BOLETA->razonSocialAnonima());

        $this->assertFalse(TipoDte::FACTURA->porApiRest());
        $this->assertFalse(TipoDte::FACTURA->exigeConsumoFolios());
        $this->assertNull(TipoDte::FACTURA->receptorAnonimo());
    }
}
