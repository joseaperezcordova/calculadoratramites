<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalculoLog extends Model
{
    protected $table = 'calculos_log';

    protected $fillable = [
        'tramite_id',
        'token_usado',
        'inputs_json',
        'outputs_json',
        'tiempo_ms',
        'ip',
    ];

    protected $casts = [
        'inputs_json'  => 'array',
        'outputs_json' => 'array',
    ];

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class);
    }
}
