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

Artisan::command('dataset:import-classes {--dry-run : Preview without saving} {--only-file= : Optional path to a newline-separated list restricting which class names get imported}', function () {
    $dryRun = (bool) $this->option('dry-run');
    $classesFile = base_path('datasets/sd-198/classes.txt');

    if (! is_file($classesFile)) {
        $this->error("Berkas classes.txt tidak ditemukan di {$classesFile}.");

        return Command::FAILURE;
    }

    $onlyFile = $this->option('only-file');
    $allowedNames = null;

    if ($onlyFile) {
        if (! is_file($onlyFile)) {
            $this->error("Berkas filter tidak ditemukan di {$onlyFile}.");

            return Command::FAILURE;
        }

        $allowedNames = array_flip(file($onlyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    $imported = 0;
    $skipped = 0;

    foreach (file($classesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$id, $name] = array_pad(explode(' ', trim($line), 2), 2, null);

        if ($id === null || $name === null || ! ctype_digit($id)) {
            continue;
        }

        if ($allowedNames !== null && ! isset($allowedNames[$name])) {
            continue;
        }

        $exists = DatasetClassMapping::query()->where('dataset_class_name', $name)->exists();

        if ($exists) {
            $skipped++;

            continue;
        }

        // Match by name, not dataset_class_id: an existing row (e.g. a curated MVP
        // mapping seeded with a placeholder id) must never get silently renamed
        // just because its id collides with a different class's official SD-198 id.
        if (! $dryRun) {
            DatasetClassMapping::query()->updateOrCreate(
                ['dataset_class_name' => $name],
                ['dataset_class_id' => (int) $id]
            );
        }

        $imported++;
    }

    $verb = $dryRun ? 'Would import' : 'Imported';
    $this->info("{$verb} {$imported} new dataset class mapping rows ({$skipped} already existed).");

    return Command::SUCCESS;
})->purpose('Import all official SD-198 classes.txt rows as dataset_class_mappings');

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
    $skippedValidated = 0;

    $link = function () use ($mappings, $dryRun, &$summary, &$diseasesTouched, &$skippedValidated): void {
        foreach ($mappings as $mapping) {
            // Never demote a mapping that already points to a disease with its own
            // validated symptom/CF knowledge base (naskah scope or curated MVP) down
            // to a generic clinical group - that erased real Tinea/Eczema/etc links once.
            if ($mapping->disease_id && $mapping->disease?->symptomRules()->exists()) {
                $skippedValidated++;

                continue;
            }

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
    $this->info("Skipped {$skippedValidated} mapping(s) already backed by a validated symptom knowledge base.");
    $this->table(
        ['Kelompok klinis', 'Jumlah class'],
        collect($summary)->sortKeys()->map(fn (int $count, string $group): array => [$group, $count])->values()->all()
    );

    return Command::SUCCESS;
})->purpose('Link production SD-198 dataset classes to clinical disease groups with source notes');
