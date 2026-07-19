<?php

use App\Models\DatasetClassMapping;
use App\Support\DatasetScopeClassifier;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('dataset:classify-scopes {--force : Reclassify all dataset mappings, including manually edited rows} {--dry-run : Preview changes without saving}', function () {
    $query = DatasetClassMapping::query()->orderBy('dataset_class_id');

    if (! $this->option('force')) {
        $query->where(function ($query): void {
            $query->whereNull('scope_category')
                ->orWhere('scope_category', '')
                ->orWhere('scope_category', 'edukasi');
        });
    }

    $updated = 0;
    $summary = [
        'swamedikasi' => 0,
        'edukasi' => 0,
        'rujuk' => 0,
        'exclude' => 0,
    ];

    $query->chunkById(100, function ($mappings) use (&$updated, &$summary): void {
        foreach ($mappings as $mapping) {
            $classification = DatasetScopeClassifier::classify($mapping->dataset_class_name);
            $mapping->fill($classification);

            if (! $this->option('dry-run')) {
                $mapping->save();
            }

            $summary[$classification['scope_category']]++;
            $updated++;
        }
    });

    $verb = $this->option('dry-run') ? 'Would update' : 'Updated';
    $this->info("{$verb} {$updated} dataset mapping rows.");
    $this->table(['Kategori', 'Jumlah'], collect($summary)->map(fn ($count, $scope) => [$scope, $count])->values()->all());
})->purpose('Classify SD-198 dataset mappings into safer usage scopes');
