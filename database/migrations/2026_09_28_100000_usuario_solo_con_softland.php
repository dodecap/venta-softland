<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cierra la segunda puerta de entrada: a la app se entra **sólo** con usuario
 * de Softland.
 *
 * La autenticación doble de la fase 1 —contra `wisusuarios` para quien tiene
 * licencia, o contra una contraseña propia para el vendedor de terreno— dejó
 * de ser la política. Y una política que sólo vive en la cabeza de quien
 * instala se rompe el día que alguien crea un usuario con prisa: quien entra
 * sin usuario de Softland no tiene con qué firmar lo que escribe en el ERP
 * —`cwcpbte.Usuario` es `varchar(8)` y apunta a `wisusuarios`—, así que su
 * comprobante quedaría firmado por nadie.
 *
 * Por eso no basta con validar en el controlador: la columna pasa a `NOT NULL`
 * y la contraseña propia se va. Dejar una columna de contraseña que ya no
 * autentica es dejar el cable colgando para que alguien lo vuelva a enchufar.
 *
 * `softland_user` baja de 20 a 8 caracteres, que es lo que mide de verdad
 * `wisusuarios.Usuario`. Veinte era una promesa que la base de Softland no
 * puede cumplir.
 */
return new class extends Migration
{
    protected $connection = 'softland';

    public function up(): void
    {
        $c = DB::connection('softland');

        // Lo que falta se dice antes, no después: si alguien entra hoy sin
        // usuario de Softland, esta migración le quitaría el acceso sin avisar.
        $huerfanos = $c->table('ventas.usuario')
            ->where('activo', true)
            ->where(fn ($q) => $q->whereNull('softland_user')->orWhere('softland_user', ''))
            ->pluck('nombre');

        if ($huerfanos->isNotEmpty()) {
            throw new RuntimeException(
                'Hay usuarios activos sin usuario de Softland: '.$huerfanos->implode(', ')
                .'. Asígnaselo (o desactívalos) antes de actualizar: desde esta versión '
                .'no se entra a la app sin licencia Softland.'
            );
        }

        // Los inactivos sin usuario de Softland no bloquean, pero tampoco
        // pueden quedarse: la columna va a NOT NULL.
        $c->table('ventas.usuario')
            ->where(fn ($q) => $q->whereNull('softland_user')->orWhere('softland_user', ''))
            ->delete();

        // SQL Server no deja tocar una columna indexada: el índice se quita y
        // se vuelve a poner con el nombre que tenga de verdad en esta base.
        $indice = $this->indiceDe('softland_user');

        if ($indice) {
            $c->statement("DROP INDEX [$indice] ON ventas.usuario");
        }

        $c->statement('ALTER TABLE ventas.usuario ALTER COLUMN softland_user varchar(8) NOT NULL');

        if ($indice) {
            $c->statement("CREATE INDEX [$indice] ON ventas.usuario (softland_user)");
        }

        if ($this->tieneColumna('password')) {
            $c->statement('ALTER TABLE ventas.usuario DROP COLUMN password');
        }
    }

    public function down(): void
    {
        $c = DB::connection('softland');

        if (! $this->tieneColumna('password')) {
            $c->statement('ALTER TABLE ventas.usuario ADD password varchar(255) NULL');
        }

        $indice = $this->indiceDe('softland_user');

        if ($indice) {
            $c->statement("DROP INDEX [$indice] ON ventas.usuario");
        }

        $c->statement('ALTER TABLE ventas.usuario ALTER COLUMN softland_user varchar(20) NULL');

        if ($indice) {
            $c->statement("CREATE INDEX [$indice] ON ventas.usuario (softland_user)");
        }
    }

    /** El nombre real del índice sobre esa columna, que no tiene por qué ser el de Laravel. */
    private function indiceDe(string $columna): ?string
    {
        $row = DB::connection('softland')->selectOne(
            'SELECT i.name
               FROM sys.indexes i
               JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
               JOIN sys.columns col ON col.object_id = i.object_id AND col.column_id = ic.column_id
              WHERE i.object_id = OBJECT_ID(?) AND col.name = ? AND i.is_primary_key = 0',
            ['ventas.usuario', $columna]
        );

        return $row->name ?? null;
    }

    private function tieneColumna(string $columna): bool
    {
        return (bool) DB::connection('softland')->selectOne(
            'SELECT 1 x FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['ventas', 'usuario', $columna]
        );
    }
};
