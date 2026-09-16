<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Cuán cerca de cerrarse está una cotización.
 *
 * ## Por qué no va en Softland
 *
 * Porque el ERP no tiene dónde. Lo más parecido es `nwtsegui.TipComp`, y ése es
 * **otro eje**: dice qué se quedó de hacer —llamar, visitar— no cuán avanzada
 * está la venta. INNOVAGES los mezcló, porque no había alternativa: llenó el
 * maestro de compromisos con porcentajes. El resultado es que una venta al 90 %
 * que queda en una llamada telefónica «retrocede» a la primera etapa.
 *
 * Son dos preguntas distintas y necesitan dos sitios. El compromiso se queda en
 * Softland, que es de donde es; el avance es de la app, como la aprobación por
 * topes.
 *
 * ## Por qué con historia y no un valor que se pisa
 *
 * Cuesta lo mismo y contesta dos preguntas que el valor pisado no puede:
 * **cuándo** se movió una venta de 50 a 90, y **cuáles llevan semanas sin
 * moverse**. Esas segundas son las que hay que mirar, y sin historia no hay
 * forma de encontrarlas.
 *
 * El valor de hoy es, simplemente, la última fila.
 *
 * ## La huella, otra vez
 *
 * `cot_creado_en` va junto al número por lo mismo que en `documento_app`: el
 * correlativo de Softland es `MAX + 1`, así que **un número vuelve a repartirse
 * cuando el documento que lo tenía se borra**. Sin la huella, el avance de una
 * cotización muerta se le aparecería a la siguiente que estrene su número.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        Schema::connection('softland')->create('ventas.cotizacion_avance', function ($t) {
            $t->bigIncrements('id');
            $t->integer('cot_num');
            // La misma marca que `nwcotiza.FechaHoraCreacion`.
            $t->dateTime('cot_creado_en')->nullable();
            // De 10 en 10. Sin 0 ni 100 a propósito: que se perdió o que se
            // convirtió ya lo dice el estado de la cotización, y un porcentaje
            // que contradiga al estado es una discusión que no hay por qué
            // tener.
            $t->unsignedTinyInteger('pct');
            $t->unsignedBigInteger('usuario_id')->nullable();
            $t->timestamps();

            $t->index(['cot_num', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection('softland')->dropIfExists('ventas.cotizacion_avance');
    }
};
