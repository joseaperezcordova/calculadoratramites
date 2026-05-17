<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tramite extends Model
{
    protected $fillable = ['nombre', 'descripcion', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function configs(): HasMany
    {
        return $this->hasMany(TramiteConfig::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(TramiteToken::class);
    }
}
