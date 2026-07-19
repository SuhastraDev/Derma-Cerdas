<?php

use App\Models\DatasetClassMapping;
use App\Models\Disease;
use App\Support\DatasetDiseaseMapper;
use App\Support\DatasetScopeClassifier;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command;

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

Artisan::command('dataset:link-diseases {--force : Persist disease links and clinical mapping updates} {--dry-run : Preview without saving}', function () {
    $dryRun = (bool) $this->option('dry-run');
    $force = (bool) $this->option('force');

    if (! $dryRun && ! $force) {
        $this->error('Use --dry-run to preview or --force to persist changes.');

        return Command::FAILURE;
    }

    $mappings = DatasetClassMapping::query()
        ->orderBy('dataset_class_name')
        ->get();

    $classNames = $mappings->pluck('dataset_class_name')->all();
    $missing = DatasetDiseaseMapper::missingClassNames($classNames);

    if ($missing !== []) {
        $this->error('Dataset classes without clinical disease mapping:');
        foreach ($missing as $className) {
            $this->line("- {$className}");
        }

        return Command::FAILURE;
    }

    $summary = [];
    $diseasesTouched = [];

    $link = function () use ($mappings, $dryRun, &$summary, &$diseasesTouched): void {
        foreach ($mappings as $mapping) {
            $payload = DatasetDiseaseMapper::payloadFor($mapping->dataset_class_name);
            $diseasePayload = $payload['disease'];
            $mappingPayload = $payload['mapping'];
            $diseaseCode = $diseasePayload['code'];

            $disease = Disease::query()->firstOrNew(['code' => $diseaseCode]);
            $disease->fill($diseasePayload);

            if (! $dryRun) {
                $disease->save();
            }

            $mapping->fill($mappingPayload);
            $mapping->disease_id = $dryRun ? ($disease->id ?: null) : $disease->id;

            if (! $dryRun) {
                $mapping->save();
            }

            $summary[$mappingPayload['clinical_group']] = ($summary[$mappingPayload['clinical_group']] ?? 0) + 1;
            $diseasesTouched[$diseaseCode] = $diseasePayload['name_indonesian'];
        }
    };

    if ($dryRun) {
        $link();
    } else {
        DB::transaction($link);
    }

    $verb = $dryRun ? 'Would link' : 'Linked';
    $this->info("{$verb} {$mappings->count()} dataset class mappings to ".count($diseasesTouched).' clinical diseases.');
    $this->table(
        ['Kelompok klinis', 'Jumlah class'],
        collect($summary)->sortKeys()->map(fn (int $count, string $group): array => [$group, $count])->values()->all()
    );

    return Command::SUCCESS;
})->purpose('Link production SD-198 dataset classes to clinical disease groups with source notes');
