<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VariableSistema extends Model
{
    protected $fillable = ['clave', 'valor', 'descripcion'];
}
