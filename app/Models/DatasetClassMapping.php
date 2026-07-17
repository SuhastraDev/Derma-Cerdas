<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetClassMapping extends Model
{
    protected $fillable = [
        'dataset_class_id',
        'dataset_class_name',
        'nama_indonesia',
        'scope_category',
        'boleh_rekomendasi_obat',
        'default_action',
        'disease_id',
        'risk_note',
    ];

    protected $casts = [
        'dataset_class_id' => 'integer',
        'boleh_rekomendasi_obat' => 'boolean',
    ];

    public function disease(): BelongsTo
    {
        return $this->belongsTo(Disease::class);
    }
}
