<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * La huella que distingue *nuestro* documento del que ocupa hoy su número.
 *
 * El correlativo de Softland es `MAX(numero) + 1`, así que **un número vuelve
 * a repartirse cuando el documento que lo tenía se borra**. En INNOVAGES eso
 * no es teórico: hay 4.453 huecos en las cotizaciones y 906 en las notas de
 * venta, y en las pruebas de este proyecto el número 8553 llegó a estar
 * asignado a tres documentos distintos, uno después de otro.
 *
 * El mapa `client_uuid` → número, entonces, no basta por sí solo: apunta a un
 * número, y un número no identifica nada de forma permanente. Se guarda además
 * el instante exacto que se escribió en `FechaHoraCreacion` del documento. Si
 * las dos huellas no coinciden, ese número ya es de otro y la fila del mapa
 * está muerta.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        Schema::connection('softland')->table('ventas.documento_app', function ($t) {
            // Nulo en las filas anteriores a esta columna: de esas sólo se
            // puede comprobar que el documento siga existiendo.
            $t->dateTime('creado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('softland')->table('ventas.documento_app', function ($t) {
            $t->dropColumn('creado_en');
        });
    }
};
