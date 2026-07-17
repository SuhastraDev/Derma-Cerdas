<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medicine extends Model
{
    protected $fillable = [
        'name',
        'category',
        'dosage_form',
        'usage_instruction',
        'warnings',
        'source_note',
        'is_limited_otc',
        'is_active',
    ];

    protected $casts = [
        'is_limited_otc' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function diseaseRecommendations(): HasMany
    {
        return $this->hasMany(DiseaseMedicineRecommendation::class);
    }
}
