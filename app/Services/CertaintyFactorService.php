<?php

namespace App\Services;

use App\Models\Disease;
use App\Models\DiseaseSymptomRule;
use Illuminate\Support\Collection;

class CertaintyFactorService
{
    /**
     * @param  iterable<int, float|int|string>  $certaintyFactors
     */
    public function combine(iterable $certaintyFactors): float
    {
        $combined = 0.0;

        foreach ($certaintyFactors as $certaintyFactor) {
            $current = $this->clamp((float) $certaintyFactor);

            if ($current >= 0 && $combined >= 0) {
                $combined = $combined + ($current * (1 - $combined));
                continue;
            }

            if ($current < 0 && $combined < 0) {
                $combined = $combined + ($current * (1 + $combined));
                continue;
            }

            $denominator = 1 - min(abs($combined), abs($current));
            $combined = $denominator === 0.0
                ? 0.0
                : ($combined + $current) / $denominator;
        }

        return $this->round($combined);
    }

    /**
     * @param  array<int|string, float|int|string>  $userSymptomCertainties
     * @return array{
     *     disease: Disease,
     *     textual_cf: float,
     *     textual_cf_percent: float,
     *     matched_symptoms: array<int, array<string, mixed>>,
     *     missing_required_symptoms: array<int, array<string, mixed>>
     * }
     */
    public function evaluateDisease(Disease $disease, array $userSymptomCertainties): array
    {
        $rules = $this->rulesForDisease($disease);
        $matched = [];
        $missingRequired = [];
        $certaintyFactors = [];

        foreach ($rules as $rule) {
            $userCf = $this->userCertaintyForRule($rule, $userSymptomCertainties);
            $expertCf = (float) ($rule->expert_cf ?? ((float) $rule->mb - (float) $rule->md));

            if ($userCf <= 0.0) {
                if ($rule->is_required) {
                    $missingRequired[] = $this->ruleSnapshot($rule, 0.0, $expertCf, 0.0);
                }

                continue;
            }

            $symptomCf = $this->round($this->clamp($expertCf) * $this->clamp($userCf));
            $certaintyFactors[] = $symptomCf;
            $matched[] = $this->ruleSnapshot($rule, $userCf, $expertCf, $symptomCf);
        }

        $textualCf = $missingRequired === [] ? $this->combine($certaintyFactors) : 0.0;

        return [
            'disease' => $disease,
            'textual_cf' => $textualCf,
            'textual_cf_percent' => $this->asPercent($textualCf),
            'matched_symptoms' => $matched,
            'missing_required_symptoms' => $missingRequired,
        ];
    }

    /**
     * @param  array<int|string, float|int|string>  $userSymptomCertainties
     * @return array<int, array<string, mixed>>
     */
    public function rankDiseases(array $userSymptomCertainties): array
    {
        return Disease::query()
            ->where('is_active', true)
            ->with(['symptomRules.symptom'])
            ->get()
            ->map(fn (Disease $disease): array => $this->evaluateDisease($disease, $userSymptomCertainties))
            ->sortByDesc('textual_cf')
            ->values()
            ->all();
    }

    private function clamp(float $value): float
    {
        return max(-1.0, min(1.0, $value));
    }

    private function asPercent(float $value): float
    {
        return $this->round($value * 100);
    }

    private function round(float $value): float
    {
        return round($value, 4);
    }

    /**
     * @return Collection<int, DiseaseSymptomRule>
     */
    private function rulesForDisease(Disease $disease): Collection
    {
        if ($disease->relationLoaded('symptomRules')) {
            return $disease->symptomRules;
        }

        return $disease->symptomRules()->with('symptom')->get();
    }

    /**
     * @param  array<int|string, float|int|string>  $userSymptomCertainties
     */
    private function userCertaintyForRule(DiseaseSymptomRule $rule, array $userSymptomCertainties): float
    {
        $keys = [
            $rule->symptom_id,
            (string) $rule->symptom_id,
            $rule->symptom?->code,
        ];

        foreach ($keys as $key) {
            if ($key !== null && array_key_exists($key, $userSymptomCertainties)) {
                return $this->clamp((float) $userSymptomCertainties[$key]);
            }
        }

        return 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleSnapshot(
        DiseaseSymptomRule $rule,
        float $userCf,
        float $expertCf,
        float $symptomCf
    ): array {
        return [
            'symptom_id' => $rule->symptom_id,
            'symptom_code' => $rule->symptom?->code,
            'symptom_name' => $rule->symptom?->name,
            'user_cf' => $this->round($userCf),
            'expert_cf' => $this->round($expertCf),
            'symptom_cf' => $this->round($symptomCf),
            'is_required' => (bool) $rule->is_required,
        ];
    }
}
