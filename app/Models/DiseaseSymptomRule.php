<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiseaseSymptomRule extends Model
{
    protected $fillable = [
        'disease_id',
        'symptom_id',
        'mb',
        'md',
        'expert_cf',
        'is_required',
        'note',
    ];

    protected $casts = [
        'mb' => 'decimal:2',
        'md' => 'decimal:2',
        'expert_cf' => 'decimal:2',
        'is_required' => 'boolean',
    ];

    public function disease(): BelongsTo
    {
        return $this->belongsTo(Disease::class);
    }

    public function symptom(): BelongsTo
    {
        return $this->belongsTo(Symptom::class);
    }
}
