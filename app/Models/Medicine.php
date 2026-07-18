<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medicine extends Model
{
    protected $fillable = [
        'dataset_class_mapping_id',
        'name',
        'category',
        'dosage_form',
        'image_path',
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

    public function datasetClassMapping(): BelongsTo
    {
        return $this->belongsTo(DatasetClassMapping::class);
    }
}
