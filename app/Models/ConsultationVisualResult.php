<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationVisualResult extends Model
{
    protected $fillable = [
        'consultation_id',
        'provider',
        'disease_id',
        'visual_score',
        'visual_reason',
        'raw_response',
    ];

    protected $casts = [
        'visual_score' => 'decimal:2',
        'raw_response' => 'array',
    ];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function disease(): BelongsTo
    {
        return $this->belongsTo(Disease::class);
    }
}
