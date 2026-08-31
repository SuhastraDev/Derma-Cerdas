<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationFinalResult extends Model
{
    protected $fillable = [
        'consultation_id',
        'disease_id',
        'textual_cf',
        'visual_score',
        'fusion_score',
        'action',
        'fusion_rule_code',
        'explanation',
        'recommendations_snapshot',
        'secondary_visual_note',
        'label_suppressed',
    ];

    protected $casts = [
        'textual_cf' => 'decimal:2',
        'visual_score' => 'decimal:2',
        'fusion_score' => 'decimal:2',
        'recommendations_snapshot' => 'array',
        'secondary_visual_note' => 'array',
        'label_suppressed' => 'boolean',
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
