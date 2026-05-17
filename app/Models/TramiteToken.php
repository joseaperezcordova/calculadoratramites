<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TramiteToken extends Model
{
    protected $fillable = ['tramite_id', 'token', 'activo', 'descripcion'];

    protected $casts = ['activo' => 'boolean'];

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class);
    }
}
