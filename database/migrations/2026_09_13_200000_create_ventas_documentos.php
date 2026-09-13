<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3: lo que la app necesita para escribir cotizaciones y notas de venta
 * en Softland sin duplicarlas.
 *
 * Los documentos siguen viviendo en `softland.nwcotiza` y `softland.nw_nventa`.
 * Aquí va solo lo que Softland no sabe:
 *
 *  - `documento_app`: el mapa `client_uuid` → número de Softland. Es lo que
 *    hace idempotente el envío desde un teléfono que perdió la respuesta. Sin
 *    esto un reintento crea una segunda cotización, porque el número lo pone
 *    el servidor y no hay clave natural con que chocar.
 *  - `aprobacion`: la aprobación del jefe por topes, que **no existe en
 *    Softland** (`nwparam.CheckApruebaNv = N`). La aporta la app.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        $c = Schema::connection('softland');

        $c->create('ventas.documento_app', function ($t) {
            $t->bigIncrements('id');
            // Lo genera el teléfono al guardar, antes de que haya red. Es lo
            // único estable entre el borrador y el documento ya escrito.
            $t->string('client_uuid', 64)->unique();
            $t->string('tipo', 12);                  // cotizacion | nota_venta
            $t->integer('numero');                   // CotNum / NVNumero ya asignado
            $t->unsignedBigInteger('usuario_id');
            $t->timestamps();
            $t->index(['tipo', 'numero']);
            $t->index('usuario_id');
        });

        $c->create('ventas.aprobacion', function ($t) {
            $t->bigIncrements('id');
            $t->integer('nv_numero');
            $t->unsignedBigInteger('solicitante_id');
            $t->unsignedBigInteger('jefe_id')->nullable();
            $t->string('estado', 12)->default('pendiente');   // pendiente|aprobada|rechazada
            // Por qué se pidió: «descuento 18% sobre un tope de 10%», «monto
            // 4.500.000 sobre un tope de 2.000.000». Se guarda el texto porque
            // el tope del vendedor puede cambiar después y entonces el motivo
            // de aquel día ya no se podría reconstruir.
            $t->string('motivo', 200);
            $t->decimal('descuento_pct', 5, 2)->default(0);
            $t->decimal('monto', 18, 2)->default(0);
            $t->string('comentario', 400)->nullable();
            $t->unsignedBigInteger('resuelto_por')->nullable();
            $t->timestamp('resuelto_at')->nullable();
            $t->timestamps();
            $t->index('nv_numero');
            $t->index(['estado', 'jefe_id']);
        });
    }

    public function down(): void
    {
        $c = Schema::connection('softland');
        $c->dropIfExists('ventas.aprobacion');
        $c->dropIfExists('ventas.documento_app');
    }
};
