<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * El mapa de idempotencia necesita un año, porque hay claves que lo llevan.
 *
 * `ventas.documento_app` nació para la cotización y la nota de venta, cuyo
 * número identifica el documento por sí solo. El **comprobante contable** no:
 * su clave primaria es `CpbAno` + `CpbNum`, y el número se reinicia cada año.
 * Sin esta columna, el `client_uuid` de un cobro de enero de 2027 apuntaría al
 * comprobante de enero de 2026.
 *
 * Se amplía el mapa que ya hay en vez de abrir uno nuevo, y a propósito: el
 * `client_uuid` es único **entre todo lo que la app escribe** —el teléfono
 * tiene una sola bandeja de salida— y esa garantía la da el índice único de
 * esta tabla. Con dos mapas habría dos espacios de nombres que nadie compara.
 * Es además de donde `ventas:huella` saca la cuenta de lo que la app ha
 * escrito dentro del ERP.
 *
 * Nula para los tipos cuyo número ya identifica solo: cotización, nota de
 * venta, factura y nota de crédito.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        Schema::connection('softland')->table('ventas.documento_app', function ($t) {
            $t->string('ano', 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('softland')->table('ventas.documento_app', function ($t) {
            $t->dropColumn('ano');
        });
    }
};
