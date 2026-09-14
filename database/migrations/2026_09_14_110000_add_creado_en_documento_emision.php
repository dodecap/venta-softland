<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Lo mismo que en `documento_app`, y por la misma razón: **un número no
 * identifica un documento para siempre**.
 *
 * El correlativo de Softland es `MAX + 1`, así que borrar un documento
 * devuelve su número al pozo. `documento_app` ya se defiende de eso con
 * `creado_en`; `documento_emision` no, y se notó: la cotización 8553 se emitió
 * y se entregó por WhatsApp el 13-09, alguien la borró desde el Softland de
 * escritorio, y la 8553 siguiente —otro cliente, otro vendedor, otro día—
 * heredó su historial de entregas. Con eso:
 *
 *  - el vendedor veía «entregada al cliente» en un documento recién escrito;
 *  - y no podía borrarlo, porque una emisión enviada bloquea el borrado.
 *
 * Las filas anteriores a esta columna no se pueden identificar —no hay con qué
 * comprobar a qué documento pertenecían— así que se van, con su archivo. La
 * tabla se creó el 13-09 y lo único que tenía era la emisión de esa 8553
 * muerta.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        foreach (DB::connection('softland')->table('ventas.documento_emision')->get(['id', 'archivo']) as $v) {
            File::delete(storage_path('app/private/'.$v->archivo));
        }

        DB::connection('softland')->table('ventas.documento_emision')->delete();

        Schema::connection('softland')->table('ventas.documento_emision', function ($t) {
            // El mismo instante que `FechaHoraCreacion` del documento. Si no
            // coincide, esta versión es de otro documento que tuvo el número.
            $t->dateTime('creado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('softland')->table('ventas.documento_emision', function ($t) {
            $t->dropColumn('creado_en');
        });
    }
};
