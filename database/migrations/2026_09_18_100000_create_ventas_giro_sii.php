<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * El giro del SII que no se llama igual en Softland.
 *
 * ## Por qué hace falta una tabla si el código ya calza
 *
 * Porque casi nunca calza todavía. El SII habla en ACTECO —seis dígitos, 674
 * códigos vigentes— y `cwtgiro` son 2.009 descripciones escritas a mano a lo
 * largo de los años, con códigos inventados: `CCV`, `PPC`, `WSS`, una `..3` y
 * hasta una con el código vacío. De 35 actecos que devolvió el SII para una
 * muestra de clientes reales, **sólo 5 existían en `cwtgiro`**.
 *
 * Hay dos maneras de cerrar ese hueco y se usan las dos:
 *
 *  1. **Cargar los 674 actecos en `cwtgiro`** con su código como `GirCod`. Es
 *     lo que alguien ya empezó a hacer a mano: los 16 giros de seis dígitos que
 *     hay hoy son actecos de verdad, y los 16 son de la lista nueva.
 *  2. **Esta tabla**, para cuando el acteco tenga que apuntar a un giro
 *     histórico que ya usan clientes —de los 2.009 hay 1.041 en uso— en vez de
 *     estrenar fila. Manda sobre lo anterior.
 *
 * ## Por qué el mapa es del acteco y no del texto
 *
 * El texto del SII cambia sin que cambie el código: el padrón reescribe
 * descripciones de una tanda a otra. Un mapa por texto se queda sin encontrar
 * en la siguiente actualización, y lo peor es que **no falla, acierta otra
 * cosa**. El código de seis dígitos es lo único estable.
 *
 * ## `acteco` es texto, no número
 *
 * Hay **94 actecos que empiezan por cero** (`011101`, `011102`…). Guardados
 * como entero se convierten en `11101`, que existe y significa otra cosa en la
 * lista anterior a la renumeración del SII. Un acierto silencioso y equivocado,
 * que acabaría impreso en el `GiroRecep` del DTE.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        Schema::connection('softland')->create('ventas.giro_sii', function ($t) {
            // Los seis dígitos del SII, con su cero delante si lo lleva.
            $t->char('acteco', 6)->primary();
            // El `GirCod` de `cwtgiro` al que apunta. Sin clave foránea a
            // propósito: `cwtgiro` es de Softland y esta tabla es nuestra.
            // Que el destino exista lo comprueba `ventas:verifica-sii`.
            $t->string('gir_cod', 6);
            // Por qué se decidió así, para quien lo mire en dos años.
            $t->string('nota', 200)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('softland')->dropIfExists('ventas.giro_sii');
    }
};
