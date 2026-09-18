<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el SII dijo de un RUT, guardado tal como lo dijo.
 *
 * ## Para qué, si la consulta tarda 0,15 s
 *
 * No es por velocidad. Son tres cosas distintas:
 *
 *  1. **Queda rastro de qué propuso el SII frente a qué se guardó.** Dentro de
 *     un año, cuando un cliente tenga la comuna cambiada, se podrá saber si la
 *     puso así el padrón o la corrigió una persona. Sin esto no hay forma.
 *  2. **La API se cae, y el alta no puede depender de que esté viva.** Con una
 *     respuesta guardada se contesta con lo último conocido, diciendo que es
 *     viejo; sin ella, no hay nada que ofrecer.
 *  3. **El reintento no sale a internet.** Dar de alta un cliente son varios
 *     idas y vueltas —se busca, se corrige, se vuelve atrás— y cada uno
 *     consultaría otra vez lo mismo.
 *
 * ## Por qué se guarda la respuesta cruda y no los campos
 *
 * Porque la traducción a códigos de Softland **cambia**: se arregla un alias,
 * se carga `cwtgiro`, se corrige una comuna. Si aquí se guardaran los códigos
 * ya traducidos, una ficha vieja seguiría devolviendo la traducción de cuando
 * se consultó. Guardando lo que dijo el SII, la traducción se rehace cada vez
 * con las reglas de hoy.
 *
 * ## La clave es el cuerpo del RUT
 *
 * Sin dígito verificador, igual que `cwtauxi.CodAux`. Así esta tabla y la ficha
 * del cliente se emparejan por lo mismo, y no hay que decidir si `76469595-K` y
 * `76.469.595-k` son la misma fila: lo son, y lo son antes de llegar aquí.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        Schema::connection('softland')->create('ventas.sii_auxiliar', function ($t) {
            // El cuerpo del RUT, como `cwtauxi.CodAux`.
            $t->string('rut', 10)->primary();
            // Un RUT que el padrón no tiene también se guarda: es una respuesta
            // igual de válida y volver a preguntarla cada vez no la cambia.
            $t->boolean('encontrado');
            // El JSON tal cual llegó. Nada de columnas por campo: la API va a
            // crecer y esto no tiene por qué enterarse.
            $t->text('respuesta')->nullable();
            $t->dateTime('consultado_en');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('softland')->dropIfExists('ventas.sii_auxiliar');
    }
};
