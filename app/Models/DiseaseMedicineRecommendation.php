<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiseaseMedicineRecommendation extends Model
{
    protected $fillable = [
        'disease_id',
        'medicine_id',
        'recommendation_note',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function disease(): BelongsTo
    {
        return $this->belongsTo(Disease::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
