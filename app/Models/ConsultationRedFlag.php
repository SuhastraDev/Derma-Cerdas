<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationRedFlag extends Model
{
    protected $fillable = [
        'consultation_id',
        'red_flag_id',
        'detected',
    ];

    protected $casts = [
        'detected' => 'boolean',
    ];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function redFlag(): BelongsTo
    {
        return $this->belongsTo(RedFlag::class);
    }
}
