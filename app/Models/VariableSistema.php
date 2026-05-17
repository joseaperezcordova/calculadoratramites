<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VariableSistema extends Model
{
    protected $table = 'variables_sistema';

    protected $fillable = ['clave', 'valor', 'descripcion'];
}
