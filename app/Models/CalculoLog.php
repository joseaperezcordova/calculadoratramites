<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalculoLog extends Model
{
    protected $fillable = [
        'tramite_id',
        'token_usado',
        'inputs',
        'outputs',
        'duracion_ms',
    ];

    protected $casts = [
        'inputs'  => 'array',
        'outputs' => 'array',
    ];

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class);
    }
}
