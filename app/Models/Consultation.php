<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consultation extends Model
{
    protected $fillable = [
        'user_id',
        'visitor_name',
        'session_code',
        'image_path',
        'status',
        'final_score',
        'final_action',
        'metadata',
    ];

    protected $casts = [
        'final_score' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function symptoms(): HasMany
    {
        return $this->hasMany(ConsultationSymptom::class);
    }

    public function visualResults(): HasMany
    {
        return $this->hasMany(ConsultationVisualResult::class);
    }

    public function finalResults(): HasMany
    {
        return $this->hasMany(ConsultationFinalResult::class);
    }

    public function redFlags(): HasMany
    {
        return $this->hasMany(ConsultationRedFlag::class);
    }
}
