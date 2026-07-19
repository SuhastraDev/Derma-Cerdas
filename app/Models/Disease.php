<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Disease extends Model
{
    protected $fillable = [
        'code',
        'name',
        'slug',
        'name_indonesian',
        'description',
        'source_note',
        'severity_scope',
        'default_action',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function symptomRules(): HasMany
    {
        return $this->hasMany(DiseaseSymptomRule::class);
    }

    public function medicineRecommendations(): HasMany
    {
        return $this->hasMany(DiseaseMedicineRecommendation::class);
    }

    public function datasetMappings(): HasMany
    {
        return $this->hasMany(DatasetClassMapping::class);
    }
}
