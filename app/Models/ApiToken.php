<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiToken extends Model
{
    protected $connection = 'softland';

    protected $table = 'ventas.api_token';

    protected $guarded = ['id'];

    protected $casts = ['last_used_at' => 'datetime'];
}
