<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La cartera por cobrar, servida como un maestro más.
 *
 * ## Qué es y de dónde sale
 *
 * El saldo de un cliente **no está en el módulo de clientes**: las tablas `xw*`
 * están instaladas y vacías, que es el caso normal cuando se factura desde
 * inventario. Está en la cuenta corriente contable, y es una resta de partida
 * abierta: agrupar `cwmovim` por cliente y documento referido, y restar el
 * haber del debe.
 *
 * Dos filtros que no son adorno, los dos medidos:
 *
 *  1. **Sólo comprobantes vigentes** (`CpbEst = 'V'`). Un borrador conserva sus
 *     movimientos: los 8 documentos que aparecían «pagados de más» —5.868.534—
 *     eran dos borradores que nadie borró.
 *  2. **Sólo la cuenta corriente del cliente.** `cwmovim` es el libro mayor
 *     entero: sin este filtro salen las facturas de compra (`FT`, 275 millones
 *     al haber) y las rendiciones de caja (`RE`) como si fueran deuda de un
 *     cliente. Con él, en INNOVAGES quedan 27 documentos abiertos y 11.424.249,
 *     ninguno negativo.
 *
 * Qué cuenta es esa lo dice `iwparam.CtaCliente`, y `ventas.config` sólo
 * rellena el hueco si viene vacía — la misma regla de todo lo demás: campo en
 * blanco, manda el ERP.
 *
 * ## Por qué una vista, y por qué en `ventas`
 *
 * Porque así la cartera es **un maestro más**: se descarga por páginas, se
 * guarda en IndexedDB, se refresca tirando hacia abajo y se acota por vendedor
 * con el mismo filtro que todo lo demás. Un controlador aparte habría
 * duplicado las cuatro cosas. Es el mismo camino que `ventas.nv_atributo_valor`.
 *
 * Vive en el esquema `ventas`, que es el nuestro: al de Softland no se le
 * añade ni una vista.
 *
 * ## Lo que la vista decide, y por qué
 *
 * - **El vendedor sale de la propia cuenta corriente**, no de `iw_gsaen`. La
 *   centralización de ventas lo escribe en `cwmovim.VendCod`: 214 de los 225
 *   cargos lo traen. Cruzar con la factura habría obligado a traducir
 *   `MovTipDocRef` a `iw_gsaen.Tipo`, que es un mapa distinto en cada empresa.
 *   Los que vienen en `'0000'` quedan en NULL: son de nadie, y quien mira sólo
 *   lo suyo no los ve. Es 1 de los 27 abiertos, y el dato está así en el ERP.
 * - **No hay ventana de doce meses.** Es lo contrario que en los documentos, y
 *   a propósito: una factura de hace dos años sin pagar es exactamente a lo que
 *   se va a cobrar. La más vieja de INNOVAGES es de 2024-04-30.
 * - **`emision` y `vencimiento` son dos columnas aunque hoy digan lo mismo.**
 *   `MovFe` y `MovFv` coinciden en 223 de los 225 cargos porque esta empresa no
 *   usa vencimientos, pero son campos distintos y otra empresa sí los usará.
 *   La antigüedad se calcula sobre el vencimiento, que cuando falta es la
 *   emisión.
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
        DB::connection('softland')->statement('DROP VIEW IF EXISTS ventas.cartera');
    }

    private function sql(): string
    {
        return <<<'SQL'
            CREATE OR ALTER VIEW ventas.cartera AS
                WITH cuenta AS (
                    SELECT COALESCE(
                        NULLIF(LTRIM(RTRIM((
                            SELECT JSON_VALUE(valor, '$.cuenta_cliente')
                            FROM ventas.config WHERE clave = 'cobranza'
                        ))), ''),
                        LTRIM(RTRIM((SELECT TOP 1 CtaCliente FROM softland.iwparam)))
                    ) AS pc
                )
                SELECT m.CodAux                                            AS cliente,
                       m.MovTipDocRef                                      AS tipo,
                       CAST(m.MovNumDocRef AS int)                         AS numero,
                       SUM(m.MovDebe)                                      AS cargado,
                       SUM(m.MovHaber)                                     AS abonado,
                       SUM(m.MovDebe - m.MovHaber)                         AS saldo,
                       MIN(CASE WHEN m.MovDebe > 0 THEN m.MovFe END)       AS emision,
                       MIN(CASE WHEN m.MovDebe > 0 THEN m.MovFv END)       AS vencimiento,
                       NULLIF(MAX(CASE WHEN m.MovDebe > 0 THEN m.VendCod END), '0000') AS vendedor,
                       MAX(CASE WHEN m.MovDebe > 0 THEN m.MonCod END)      AS moneda,
                       MAX(CASE WHEN m.MovDebe > 0 THEN m.MovGlosa END)    AS glosa
                FROM softland.cwmovim m
                JOIN softland.cwcpbte c
                     ON c.CpbAno = m.CpbAno AND c.CpbNum = m.CpbNum AND c.CpbEst = 'V'
                CROSS JOIN cuenta u
                WHERE LTRIM(RTRIM(m.PctCod)) = u.pc
                  AND m.MovTipDocRef <> '00'
                  AND m.CodAux <> '0000000000'
                GROUP BY m.CodAux, m.MovTipDocRef, CAST(m.MovNumDocRef AS int)
                HAVING SUM(m.MovDebe - m.MovHaber) > 0
            SQL;
    }
};
