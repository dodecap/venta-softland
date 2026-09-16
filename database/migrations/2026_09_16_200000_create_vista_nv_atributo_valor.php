<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los atributos de la nota de venta, servidos como una sola tabla.
 *
 * ## Qué son los atributos
 *
 * Un mecanismo **general** de Softland: cada maestro puede llevar campos
 * definidos por la empresa, declarados en la base y no en el código. La nota de
 * venta es el `IdMaestro = 4`, y en INNOVAGES declara cuatro — «Tipo de Venta»,
 * «TIPO DE CLIENTE», «TIPO DE CONTRATO» y «Fech. Envio SOFTLAND»— de los que
 * salen dos de las líneas de la orden de compra al distribuidor.
 *
 * **Son distintos en cada empresa**: NETDOMAIN declara uno solo. Así que nada
 * de dar por hecho que existe el atributo 2, ni que hay cuatro, ni que hay
 * alguno. Aquí no se nombra ninguno.
 *
 * ## Por qué una vista, y por qué en `ventas`
 *
 * El valor vive en **tres tablas distintas según el tipo** del atributo:
 * `...TVAtrT` guarda la opción elegida de una lista, `...TVAtrF` una fecha y
 * `...TVAtrV` un texto o un número. Es un diseño razonable en el ERP y un
 * estorbo para descargarlo: el motor de maestros copia una tabla por maestro, y
 * declararlas por separado obligaría al teléfono a cruzar tres almacenes para
 * leer un dato.
 *
 * La vista las une en la forma que el teléfono necesita — un valor por
 * documento y atributo — y **vive en el esquema `ventas`**, que es el nuestro:
 * el de Softland no se toca ni para añadir una vista.
 *
 * Trae además `VenCod` y `nvFem` de la nota de venta, que es lo que el filtro
 * del maestro necesita para acotar por vendedor y por los doce meses. Sin
 * ellos habría que rehacer el join en cada consulta.
 *
 * `TRY_CAST` y no `CAST`: la columna `Codigo` es `varchar(20)` porque el
 * mecanismo sirve para todos los maestros —el código de un producto no es un
 * número—. Con `IdMaestro = 4` siempre debería ser el número de la nota de
 * venta, pero una fila torcida no puede tumbar la descarga entera.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        DB::connection('softland')->statement($this->sql());
    }

    public function down(): void
    {
        DB::connection('softland')->statement('DROP VIEW IF EXISTS ventas.nv_atributo_valor');
    }

    private function sql(): string
    {
        return <<<'SQL'
            CREATE OR ALTER VIEW ventas.nv_atributo_valor AS
                SELECT TRY_CAST(t.Codigo AS int) AS nv_numero,
                       t.CodTat AS cod,
                       t.CodTAtE AS opcion,
                       CAST(NULL AS datetime) AS fecha,
                       CAST(NULL AS varchar(50)) AS texto,
                       n.VenCod, n.nvFem
                FROM softland.NW_NventaTVAtrT t
                JOIN softland.nw_nventa n ON n.NVNumero = TRY_CAST(t.Codigo AS int)
                WHERE t.IdMaestro = 4

                UNION ALL

                SELECT TRY_CAST(f.Codigo AS int),
                       f.CodTat,
                       CAST(NULL AS int),
                       f.ValorFecha,
                       CAST(NULL AS varchar(50)),
                       n.VenCod, n.nvFem
                FROM softland.NW_NventaTVAtrF f
                JOIN softland.nw_nventa n ON n.NVNumero = TRY_CAST(f.Codigo AS int)
                WHERE f.IdMaestro = 4

                UNION ALL

                SELECT TRY_CAST(v.Codigo AS int),
                       v.CodTat,
                       CAST(NULL AS int),
                       CAST(NULL AS datetime),
                       v.Valor,
                       n.VenCod, n.nvFem
                FROM softland.NW_NventaTVAtrV v
                JOIN softland.nw_nventa n ON n.NVNumero = TRY_CAST(v.Codigo AS int)
                WHERE v.IdMaestro = 4
            SQL;
    }
};
