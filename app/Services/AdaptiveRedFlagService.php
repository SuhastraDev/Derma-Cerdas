<?php

namespace App\Services;

use App\Models\Disease;
use App\Models\RedFlag;
use Illuminate\Support\Collection;

class AdaptiveRedFlagService
{
    /**
     * @param  array<string, mixed>  $complaintFeatures
     * @param  array<int, array<string, mixed>>  $visualCandidates
     * @param  array<int, string>  $detectedCodes
     * @return Collection<int, RedFlag>
     */
    public function selectRedFlags(
        array $complaintFeatures,
        array $visualCandidates,
        array $detectedCodes = [],
        int $min = 4,
        int $max = 6
    ): Collection {
        $scores = [];

        foreach ($detectedCodes as $index => $code) {
            if (is_string($code) && trim($code) !== '') {
                $scores[$code] = ($scores[$code] ?? 0) + 10 - min($index, 5);
            }
        }

        foreach ($this->profileRedFlags($complaintFeatures, $visualCandidates) as $code => $score) {
            $scores[$code] = ($scores[$code] ?? 0) + $score;
        }

        foreach (['BREATHING_OR_FACE_SWELLING', 'BLACKENED_SKIN', 'VULNERABLE_PATIENT'] as $globalCode) {
            $scores[$globalCode] = ($scores[$globalCode] ?? 0) + 0.75;
        }

        $orderedCodes = collect($scores)
            ->sortDesc()
            ->keys()
            ->values();

        if ($orderedCodes->count() < $min) {
            $orderedCodes = $orderedCodes
                ->merge($this->fallbackCodes($orderedCodes->all(), $min - $orderedCodes->count()))
                ->unique()
                ->values();
        }

        $selectedCodes = $orderedCodes->take($max)->all();

        return RedFlag::query()
            ->where('is_active', true)
            ->whereIn('code', $selectedCodes)
            ->get(['id', 'code', 'question', 'severity'])
            ->sortBy(fn (RedFlag $redFlag): int => array_search($redFlag->code, $selectedCodes, true))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $complaintFeatures
     * @param  array<int, array<string, mixed>>  $visualCandidates
     * @return array<string, float>
     */
    private function profileRedFlags(array $complaintFeatures, array $visualCandidates): array
    {
        $tokens = collect($complaintFeatures['disease_hints'] ?? [])
            ->flatMap(fn ($hint): array => is_array($hint) ? [
                $hint['dataset_class_name'] ?? null,
                $hint['disease_code'] ?? null,
            ] : [])
            ->merge(collect($visualCandidates)->flatMap(function (array $candidate): array {
                /** @var Disease|null $disease */
                $disease = $candidate['disease'] ?? null;

                if (! $disease) {
                    return [];
                }

                return [
                    $disease->code,
                    $disease->name,
                    $disease->name_indonesian,
                    $disease->default_action,
                    ...$disease->loadMissing('datasetMappings')->datasetMappings->pluck('dataset_class_name')->all(),
                ];
            }))
            ->filter(fn ($token): bool => is_string($token) && trim($token) !== '')
            ->map(fn (string $token): string => str($token)->lower()->replace(['_', '-'], ' ')->value())
            ->values();

        $scores = [
            'NO_IMPROVEMENT' => 1.0,
            'SENSITIVE_AREA_SEVERE' => 1.0,
            'WIDESPREAD_RASH' => 0.9,
            'PUS_OR_WIDE_INFECTION' => 0.8,
        ];

        foreach ($tokens as $token) {
            if (str_contains($token, 'refer') || str_contains($token, 'carcinoma') || str_contains($token, 'melanoma') || str_contains($token, 'keratosis')) {
                $scores['SUSPICIOUS_LESION'] = ($scores['SUSPICIOUS_LESION'] ?? 0) + 4.0;
                $scores['FAST_SPREADING_SWELLING'] = ($scores['FAST_SPREADING_SWELLING'] ?? 0) + 2.0;
                $scores['OPEN_WOUND_LARGE'] = ($scores['OPEN_WOUND_LARGE'] ?? 0) + 1.8;

                continue;
            }

            if (str_contains($token, 'cellulitis') || str_contains($token, 'wound') || str_contains($token, 'ulcer')) {
                $scores['FEVER_HIGH'] = ($scores['FEVER_HIGH'] ?? 0) + 4.0;
                $scores['FAST_SPREADING_SWELLING'] = ($scores['FAST_SPREADING_SWELLING'] ?? 0) + 3.5;
                $scores['PUS_OR_WIDE_INFECTION'] = ($scores['PUS_OR_WIDE_INFECTION'] ?? 0) + 3.0;
                $scores['OPEN_WOUND_LARGE'] = ($scores['OPEN_WOUND_LARGE'] ?? 0) + 2.5;

                continue;
            }

            if (str_contains($token, 'psoriasis') || str_contains($token, 'eczema') || str_contains($token, 'dermatitis')) {
                $scores['WIDESPREAD_RASH'] = ($scores['WIDESPREAD_RASH'] ?? 0) + 2.4;
                $scores['SENSITIVE_AREA_SEVERE'] = ($scores['SENSITIVE_AREA_SEVERE'] ?? 0) + 2.0;
                $scores['PUS_OR_WIDE_INFECTION'] = ($scores['PUS_OR_WIDE_INFECTION'] ?? 0) + 1.6;
                $scores['NO_IMPROVEMENT'] = ($scores['NO_IMPROVEMENT'] ?? 0) + 1.5;

                continue;
            }

            if (str_contains($token, 'tinea') || str_contains($token, 'candid') || str_contains($token, 'kurap') || str_contains($token, 'panu')) {
                $scores['SENSITIVE_AREA_SEVERE'] = ($scores['SENSITIVE_AREA_SEVERE'] ?? 0) + 2.4;
                $scores['PUS_OR_WIDE_INFECTION'] = ($scores['PUS_OR_WIDE_INFECTION'] ?? 0) + 2.0;
                $scores['NO_IMPROVEMENT'] = ($scores['NO_IMPROVEMENT'] ?? 0) + 1.8;
                $scores['VULNERABLE_PATIENT'] = ($scores['VULNERABLE_PATIENT'] ?? 0) + 1.4;
            }
        }

        return $scores;
    }

    /**
     * @param  array<int, string>  $excludeCodes
     * @return array<int, string>
     */
    private function fallbackCodes(array $excludeCodes, int $needed): array
    {
        if ($needed <= 0) {
            return [];
        }

        return RedFlag::query()
            ->where('is_active', true)
            ->whereNotIn('code', $excludeCodes)
            ->orderByRaw("case severity when 'urgent_refer' then 0 when 'refer' then 1 else 2 end")
            ->pluck('code')
            ->take($needed)
            ->all();
    }
}
