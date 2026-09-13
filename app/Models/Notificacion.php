<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bitácora de correos: qué se intentó enviar, a quién y con qué resultado. */
class Notificacion extends Model
{
    protected $connection = 'softland';

    protected $table = 'ventas.notificacion';

    protected $guarded = ['id'];

    protected $casts = ['enviada_at' => 'datetime'];
}
