<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Qué se avisa y a quién, por evento. Editable desde la app (rol admin). */
class NotificacionRegla extends Model
{
    protected $connection = 'softland';

    protected $table = 'ventas.notificacion_regla';

    protected $guarded = ['id'];

    protected $casts = [
        'activa' => 'boolean',
        'avisar_dueno' => 'boolean',
        'avisar_jefe' => 'boolean',
        'avisar_cliente' => 'boolean',
    ];
}
