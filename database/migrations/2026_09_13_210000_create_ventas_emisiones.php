<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se le entregó al cliente, congelado.
 *
 * El requisito es real: si mañana cambia la UF, el logo o la dirección, la
 * cotización que el cliente tiene guardada no puede cambiar sola. Pero conviene
 * no sobreconstruirlo, porque **el PDF ya es un snapshot** — es un archivo de
 * bytes inmutable con todo dibujado adentro.
 *
 * Por eso se guardan dos cosas y no una tabla de campos: el archivo tal cual se
 * entregó, y el contexto con que se dibujó, en JSON. El archivo es lo que el
 * cliente tiene en la mano. El JSON es para poder responder *por qué decía eso*
 * dos años después, y para volver a dibujarlo si algún día cambia el formato.
 *
 * La regla que evita la complejidad: una versión que ya salió no se toca nunca
 * más. Corregir un documento emitido crea la versión siguiente; las anteriores
 * quedan. Una versión que se generó y nunca se envió — la vista previa, el
 * vendedor que miró y cerró — sí se reemplaza: nadie la tiene.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        $c = Schema::connection('softland');

        $c->create('ventas.documento_emision', function ($t) {
            $t->bigIncrements('id');
            $t->string('tipo', 24);              // App\Services\Documentos\TipoDocumento
            $t->unsignedInteger('numero');       // CotNum / NVNumero
            $t->unsignedSmallInteger('version')->default(1);
            // sha256 del HTML con que se dibujó: dos PDF del mismo documento no
            // tienen los mismos bytes, porque dompdf les estampa la fecha dentro.
            $t->char('hash', 64);
            $t->text('datos')->nullable();       // el contexto completo del render
            $t->string('archivo', 200);          // relativo a storage/app/private
            $t->unsignedInteger('bytes')->default(0);
            $t->timestamp('emitido_at')->nullable();
            $t->timestamp('enviado_at')->nullable();   // null = nunca salió al cliente
            $t->string('canal', 20)->nullable();       // correo | whatsapp | descarga
            $t->unsignedBigInteger('usuario_id')->nullable();
            $t->timestamps();
            $t->unique(['tipo', 'numero', 'version']);
            $t->index(['tipo', 'numero']);
        });

        // La firma del documento lleva cargo y teléfono, y no están en Softland:
        // `cwtvend` sólo tiene código, nombre, tipo, correo y usuario.
        $c->table('ventas.usuario', function ($t) {
            $t->string('cargo', 80)->nullable();
            $t->string('fono', 40)->nullable();
        });
    }

    public function down(): void
    {
        $c = Schema::connection('softland');

        $c->table('ventas.usuario', function ($t) {
            $t->dropColumn(['cargo', 'fono']);
        });
        $c->dropIfExists('ventas.documento_emision');
    }
};
