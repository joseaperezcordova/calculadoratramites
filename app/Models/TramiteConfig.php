<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TramiteConfig extends Model
{
    protected $fillable = ['tramite_id', 'config', 'version', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class);
    }
}
