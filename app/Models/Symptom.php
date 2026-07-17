<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Symptom extends Model
{
    protected $fillable = [
        'code',
        'name',
        'question',
        'input_type',
        'is_red_flag_candidate',
        'is_active',
    ];

    protected $casts = [
        'is_red_flag_candidate' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function diseaseRules(): HasMany
    {
        return $this->hasMany(DiseaseSymptomRule::class);
    }
}
