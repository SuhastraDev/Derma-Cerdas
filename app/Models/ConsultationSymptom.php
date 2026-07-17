<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationSymptom extends Model
{
    protected $fillable = [
        'consultation_id',
        'symptom_id',
        'user_cf',
        'selected',
    ];

    protected $casts = [
        'user_cf' => 'decimal:2',
        'selected' => 'boolean',
    ];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function symptom(): BelongsTo
    {
        return $this->belongsTo(Symptom::class);
    }
}
