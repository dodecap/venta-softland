<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Qué códigos de barras aprendió la app, quién se los enseñó y cuándo.
 *
 * ## Por qué una tabla, si el código ya queda en `iw_tprod`
 *
 * Porque `iw_tprod.CodBarra` no guarda autor ni fecha. El día que un código
 * lleve a un producto equivocado —se escaneó la caja de al lado, alguien
 * confundió dos referencias parecidas— la pregunta va a ser «¿quién lo puso?»,
 * y la respuesta tiene que existir. Es la misma regla que `ventas.documento_app`:
 * la huella de esta app se lleva en el esquema de esta app.
 *
 * ## No es la fuente de la verdad
 *
 * El código lo lee todo el mundo de `iw_tprod`, también el Softland de
 * escritorio. Esto es la bitácora, y se puede borrar entera sin que nada deje
 * de funcionar: por eso no tiene clave foránea a nada, ni al producto.
 *
 * ## Una fila por vez que se escribe, no una por producto
 *
 * El código de un producto no se pisa nunca —ésa es la primera de las cinco
 * reglas de `CodigoBarras`— así que en la práctica hay una fila por producto.
 * Si algún día se permitiera corregirlo, aquí quedarían las dos, que es lo que
 * hace falta para entender qué pasó. Pisar la fila sería perder justo eso.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        Schema::connection('softland')->create('ventas.codigo_barras_app', function ($t) {
            $t->id();
            // `iw_tprod.CodProd` es `varchar(20)`.
            $t->string('producto', 20);
            // Y `CodBarra` también. El servicio no deja pasar nada más largo.
            $t->string('barra', 20);
            // Quién lo enseñó, por su correo, que es como se le nombra en la
            // app. Nulo si lo escribió un comando y no una persona.
            $t->string('usuario', 120)->nullable();
            $t->dateTime('creado_en');

            $t->index('producto');
            $t->index('barra');
        });
    }

    public function down(): void
    {
        Schema::connection('softland')->dropIfExists('ventas.codigo_barras_app');
    }
};
