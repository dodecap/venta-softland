<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4.5: de qué línea de cotización salió cada línea de nota de venta.
 *
 * Es el **único** enlace del ciclo que hay que guardar por nuestra cuenta. El
 * otro salto —nota de venta → factura— ya lo tiene Softland: `iw_gmovi.nvCorrela`
 * apunta a `nw_detnv.nvLinea` y lo escribe el propio ERP en 2.558 de 2.620
 * líneas. Duplicarlo aquí sería crear una segunda verdad que sólo sabría de lo
 * que hizo la app.
 *
 * De la cotización, en cambio, Softland no guarda nada: `nwdetcot` no tiene
 * columna de cantidad consumida y `CtEstado` pasa a `V` con la primera nota de
 * venta, dé lo mismo si se convirtió entera o una línea de ocho. Las 221
 * cotizaciones que hoy tienen más de una nota de venta están todas en `V`, las
 * repartidas igual que las enteras.
 *
 * ## Por qué van las dos marcas de creación
 *
 * El correlativo de Softland es `MAX(numero) + 1`: un número vuelve a
 * repartirse cuando el documento que lo tenía se borra. Guardar `CotNum` y
 * `NVNumero` a secas dejaría filas que, tras un borrado, apuntan al documento
 * de otra persona. Se guarda además el `FechaHoraCreacion` de cada punta —la
 * misma solución que ya usa `ventas.documento_app`—: si las marcas no
 * coinciden, la fila está muerta y no cuenta.
 *
 * ## Por qué la clave única
 *
 * Una línea de nota de venta sale de **una** línea de cotización. La clave
 * impide que un reenvío escriba el enlace dos veces, que es como un saldo se
 * consume solo sin que nadie lo note.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        Schema::connection('softland')->create('ventas.linea_origen', function ($t) {
            $t->bigIncrements('id');

            $t->integer('nv_numero');
            // `nvLinea` y `CtLinea` son float en Softland: valen 1.0, 2.0…
            $t->decimal('nv_linea', 9, 2);
            $t->dateTime('nv_creado_en')->nullable();

            $t->integer('cot_num');
            $t->decimal('cot_linea', 9, 2);
            $t->dateTime('cot_creado_en')->nullable();

            // Lo que esta línea de nota de venta se llevó de la cotización. No
            // tiene por qué ser toda: de doce unidades cotizadas se pueden
            // convertir cinco y dejar siete.
            $t->decimal('cantidad', 18, 4);

            $t->timestamps();

            $t->unique(['nv_numero', 'nv_linea'], 'linea_origen_destino_unica');
            $t->index(['cot_num', 'cot_linea']);
        });
    }

    public function down(): void
    {
        Schema::connection('softland')->dropIfExists('ventas.linea_origen');
    }
};
