<?php

namespace App\Services;

use App\Models\Disease;
use App\Models\Symptom;
use Illuminate\Support\Collection;

class AdaptiveQuestionService
{
    /**
     * @param  array<string, mixed>  $complaintFeatures
     * @param  array<int, array<string, mixed>>  $visualCandidates
     * @return Collection<int, Symptom>
     */
    public function selectSymptoms(array $complaintFeatures, array $visualCandidates, int $min = 5, int $max = 8): Collection
    {
        $scores = [];

        foreach (($complaintFeatures['symptom_evidence'] ?? []) as $code => $evidence) {
            $scores[$code] = ($scores[$code] ?? 0) + (float) ($evidence['score'] ?? 0) + 1.2;
        }

        collect($visualCandidates)
            ->take(3)
            ->each(function (array $candidate, int $index) use (&$scores): void {
                /** @var Disease|null $disease */
                $disease = $candidate['disease'] ?? null;

                if (! $disease) {
                    return;
                }

                $visualBoost = max(0.25, (float) ($candidate['visual_score'] ?? 0)) * (1.0 - ($index * 0.15));

                $disease->symptomRules()
                    ->with('symptom')
                    ->orderByDesc('mb')
                    ->get()
                    ->each(function ($rule) use (&$scores, $visualBoost): void {
                        $code = $rule->symptom?->code;

                        if (! $code) {
                            return;
                        }

                        $scores[$code] = ($scores[$code] ?? 0) + ((float) $rule->mb * $visualBoost);

                        if ($rule->is_required) {
                            $scores[$code] += 0.75;
                        }
                    });
            });

        foreach (['ITCHING', 'RED_RASH', 'DRY_SCALY_SKIN', 'RECURRENT_OR_DAYS'] as $fallbackCode) {
            $scores[$fallbackCode] = ($scores[$fallbackCode] ?? 0) + 0.25;
        }

        $orderedCodes = collect($scores)
            ->sortDesc()
            ->keys()
            ->values();

        if ($orderedCodes->count() < $min) {
            $orderedCodes = $orderedCodes
                ->merge(Symptom::query()->where('is_active', true)->orderBy('id')->pluck('code'))
                ->unique()
                ->values();
        }

        $selectedCodes = $orderedCodes->take($max)->all();

        return Symptom::query()
            ->where('is_active', true)
            ->whereIn('code', $selectedCodes)
            ->get(['id', 'code', 'name', 'question'])
            ->sortBy(fn (Symptom $symptom): int => array_search($symptom->code, $selectedCodes, true))
            ->values();
    }
}
