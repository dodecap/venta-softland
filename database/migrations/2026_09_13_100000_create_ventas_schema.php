<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas del esquema `ventas` dentro de la base Softland.
 *
 * Fase 1: solo lo transversal (usuarios, tokens, configuración y notificaciones).
 * Las cotizaciones, notas de venta y documentos NO se duplican aquí: viven en las
 * tablas nativas de Softland (`nwcotiza`, `nw_nventa`, `iw_gsaen`, `dte_*`). Lo que
 * sí tendrá tabla propia más adelante es el borrador offline y el mapa de
 * idempotencia client_uuid -> folio Softland.
 *
 * Sin claves foráneas: en SQL Server los FK con auto-referencia y cascadas
 * múltiples dan "cyclic cascade path". Se usan índices.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        $c = Schema::connection('softland');

        $c->create('ventas.usuario', function ($t) {
            $t->bigIncrements('id');
            $t->string('nombre', 120);
            $t->string('email', 150)->nullable();
            $t->string('password', 255)->nullable();      // auth propia de la app
            $t->string('softland_user', 20)->nullable();  // login en softland.wisusuarios
            $t->string('rut', 20)->nullable();
            $t->string('ven_cod', 4)->nullable();         // vendedor Softland (softland.cwtvend)
            $t->string('cod_bode', 10)->nullable();       // bodega por defecto (softland.iw_tbode)
            $t->string('cod_lista', 3)->nullable();       // lista de precios (softland.iw_tlispre)
            $t->string('cod_cc', 8)->nullable();          // centro de costo (softland.cwtccos)
            $t->string('rol', 20)->default('vendedor');   // vendedor|supervisor|facturacion|admin
            $t->unsignedBigInteger('jefe_id')->nullable();
            // Topes que disparan aprobación del jefe (0 = sin tope propio).
            $t->decimal('tope_descuento_pct', 5, 2)->default(0);
            $t->decimal('tope_monto_nv', 18, 2)->default(0);
            $t->boolean('habilitado')->default(false);    // el admin habilita tras revisar
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->index('email');
            $t->index('softland_user');
            $t->index('ven_cod');
            $t->index('rut');
            $t->index('jefe_id');
        });

        $c->create('ventas.api_token', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('usuario_id');
            $t->char('token_hash', 64)->unique();
            $t->string('nombre', 60)->nullable();     // "app-movil", "tablet-bodega"...
            $t->string('dispositivo', 120)->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->index('usuario_id');
        });

        $c->create('ventas.config', function ($t) {
            $t->string('clave', 60)->primary();
            $t->text('valor')->nullable();
            $t->timestamps();
        });

        // Reglas de notificación: qué se avisa, a quién y por qué canal.
        $c->create('ventas.notificacion_regla', function ($t) {
            $t->bigIncrements('id');
            $t->string('evento', 40);                       // ver App\Services\Notificaciones\Eventos
            $t->boolean('activa')->default(true);
            $t->boolean('avisar_dueno')->default(true);     // el vendedor del documento
            $t->boolean('avisar_jefe')->default(false);     // su jefe directo
            $t->boolean('avisar_cliente')->default(false);  // el contacto del cliente (cwtauxi)
            $t->string('roles', 120)->nullable();           // roles extra, coma separados
            $t->string('copia_a', 500)->nullable();         // correos fijos, coma separados
            $t->timestamps();
            $t->unique('evento');
        });

        // Bitácora de cada correo: qué se intentó enviar y qué pasó.
        $c->create('ventas.notificacion', function ($t) {
            $t->bigIncrements('id');
            $t->string('evento', 40);
            $t->string('referencia', 60)->nullable();   // p. ej. "cotizacion:1234"
            $t->string('destinatarios', 1000);
            $t->string('asunto', 200);
            $t->string('estado', 12)->default('pendiente'); // pendiente|enviada|error
            $t->string('error', 500)->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); // quién disparó el evento
            $t->timestamp('enviada_at')->nullable();
            $t->timestamps();
            $t->index('evento');
            $t->index('referencia');
            $t->index('estado');
        });
    }

    public function down(): void
    {
        $c = Schema::connection('softland');
        foreach (['notificacion', 'notificacion_regla', 'config', 'api_token', 'usuario'] as $tbl) {
            $c->dropIfExists("ventas.$tbl");
        }
    }
};
