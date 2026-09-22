<?php

namespace Tests\Unit;

use App\Services\Softland\Compatibilidad;
use App\Services\Softland\Maestros;
use Tests\TestCase;

/**
 * Lo que se comprueba aquí es la **lista**, no la base: que de `Maestros` salga
 * todo lo que el catálogo declara, que lo escrito a mano se sume sin duplicar y
 * que nada nuestro se cuele como requisito de Softland.
 *
 * Que las columnas existan de verdad no se prueba aquí — eso lo dice
 * `php artisan ventas:compatibilidad` contra una base real, y es como se
 * encontraron los ocho nombres que estaban mal al escribir esto.
 */
class CompatibilidadTest extends TestCase
{
    private function requisitos(): array
    {
        return (new Compatibilidad)->requisitos();
    }

    public function test_cada_maestro_de_softland_queda_comprobado(): void
    {
        $req = array_change_key_case($this->requisitos());

        foreach (Maestros::recursos() as $nombre => $r) {
            [$esquema, $tabla] = array_pad(explode('.', $r['tabla'], 2), 2, null);

            if ($tabla === null || $esquema !== 'softland') {
                continue;
            }

            $this->assertArrayHasKey(strtolower($tabla), $req, "el maestro $nombre no se comprueba");

            foreach ($r['campos'] ?? [] as $columna) {
                $this->assertContains(
                    explode(':', $columna, 2)[0],
                    $req[strtolower($tabla)][1],
                    "$nombre: falta la columna $columna"
                );
            }
        }
    }

    /**
     * Las tablas del esquema `ventas` las crean nuestras migraciones. Pedirle a
     * Softland que las traiga es pedirle algo que no puede tener, y el informe
     * diría que la base no sirve el día de la instalación, antes de que las
     * migraciones hayan corrido.
     */
    public function test_lo_nuestro_no_se_le_pide_a_softland(): void
    {
        foreach (array_keys($this->requisitos()) as $tabla) {
            $this->assertStringNotContainsString('.', $tabla, "«{$tabla}» no es una tabla de Softland");
        }

        $this->assertArrayNotHasKey('nv_atributo_valor', $this->requisitos());
        $this->assertArrayNotHasKey('linea_origen', $this->requisitos());
    }

    /**
     * El sufijo `:entero` del catálogo es para convertir el valor, no parte del
     * nombre. Si se colara, la comprobación buscaría una columna llamada
     * `CtCant:decimal` y daría por rota una base que está bien.
     */
    public function test_ninguna_columna_arrastra_el_tipo(): void
    {
        foreach ($this->requisitos() as $tabla => [$grupo, $columnas]) {
            foreach ($columnas as $c) {
                $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z_0-9]*$/', $c, "$tabla.$c");
            }
        }
    }

    /** Lo que se lee y lo que se escribe de una misma tabla van a la misma fila. */
    public function test_leer_y_escribir_no_duplican_la_tabla(): void
    {
        $req = $this->requisitos();
        $vistas = array_map('strtolower', array_keys($req));

        $this->assertSame(count($vistas), count(array_unique($vistas)), 'hay una tabla repetida');

        // `iw_gsaen` es maestro (la app lee las facturas) y además se escribe.
        $this->assertContains('Folio', $req['iw_gsaen'][1]);
        $this->assertContains('FecHoraCreacion', $req['iw_gsaen'][1]);
    }

    /** Cada tabla cuelga de un grupo declarado, y el grupo dice qué se pierde. */
    public function test_todo_cuelga_de_un_grupo_conocido(): void
    {
        $grupos = Compatibilidad::grupos();

        foreach ($this->requisitos() as $tabla => [$grupo, $columnas]) {
            $this->assertArrayHasKey($grupo, $grupos, "«{$tabla}» cuelga del grupo desconocido «{$grupo}»");
            $this->assertNotSame([], $columnas, "«{$tabla}» no comprueba ninguna columna");
        }
    }

    /**
     * Lo imprescindible es lo que impide escribir un documento. Que falte el
     * DTE limita, pero no puede negarse a instalar: una empresa que sólo cotiza
     * es una empresa que la app sirve.
     */
    public function test_el_dte_limita_pero_no_impide_instalar(): void
    {
        $req = $this->requisitos();
        $grupos = Compatibilidad::grupos();

        $this->assertTrue($grupos[$req['nwcotiza'][0]][1], 'la cotización sí es imprescindible');
        $this->assertTrue($grupos[$req['cwtauxi'][0]][1], 'sin clientes no se vende');
        $this->assertFalse($grupos[$req['dte_siicaf'][0]][1], 'el DTE no puede impedir instalar');
        $this->assertFalse($grupos[$req['nwtsegui'][0]][1], 'el seguimiento tampoco');
        $this->assertFalse($grupos[$req['cwtcarg'][0]][1], 'ni un desplegable de cargos');
    }
}
