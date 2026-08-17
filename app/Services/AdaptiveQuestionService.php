<?php

namespace App\Services;

use App\Models\Disease;
use App\Models\Symptom;
use App\Support\QuestionBank;
use Illuminate\Support\Collection;

class AdaptiveQuestionService
{
    /**
     * @param  array<string, mixed>  $complaintFeatures
     * @param  array<int, array<string, mixed>>  $visualCandidates
     * @return Collection<int, Symptom>
     */
    public function selectSymptoms(
        array $complaintFeatures,
        array $visualCandidates,
        array $aiSuggestedCodes = [],
        int $min = 5,
        int $max = 8
    ): Collection {
        $scores = [];

        // AI may choose which questions are useful, but it must never answer
        // them on behalf of the user.
        foreach (array_values($aiSuggestedCodes) as $index => $code) {
            if (! is_string($code) || trim($code) === '') {
                continue;
            }

            $scores[$code] = ($scores[$code] ?? 0) + max(1.5, 3.0 - ($index * 0.15));
        }

        foreach (($complaintFeatures['symptom_evidence'] ?? []) as $code => $evidence) {
            $scores[$code] = ($scores[$code] ?? 0) + (float) ($evidence['score'] ?? 0) + 1.2;
        }

        foreach ($this->candidateProfileBoosts($complaintFeatures, $visualCandidates) as $code => $boost) {
            $scores[$code] = ($scores[$code] ?? 0) + $boost;
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

        $orderedCodes = collect($scores)
            ->sortDesc()
            ->keys()
            ->values();

        if ($orderedCodes->count() < $min) {
            $orderedCodes = $orderedCodes
                ->merge($this->fallbackSymptomCodes($orderedCodes->all(), $min - $orderedCodes->count()))
                ->unique()
                ->values();
        }

        return $this->selectByQuestionGroup($orderedCodes->all(), $complaintFeatures, $visualCandidates, $min, $max);
    }

    /**
     * Bank pertanyaan berbentuk pilihan ganda: satu pertanyaan hanya bermakna
     * bila SELURUH pilihannya ikut ditampilkan. Skor per pilihan karenanya
     * diangkat menjadi skor pertanyaan (nilai tertinggi di antara pilihannya),
     * lalu pertanyaan yang terpilih dikembalikan lengkap beserta opsinya.
     *
     * Pertanyaan bertanda 'always' - lokasi dan bentuk - selalu diikutkan
     * karena daya pisahnya jauh melampaui yang lain.
     *
     * @param  array<int, string>  $orderedCodes
     * @param  array<string, mixed>  $complaintFeatures
     * @param  array<int, array<string, mixed>>  $visualCandidates
     * @return Collection<int, Symptom>
     */
    private function selectByQuestionGroup(
        array $orderedCodes,
        array $complaintFeatures,
        array $visualCandidates,
        int $min,
        int $max
    ): Collection {
        $all = Symptom::query()
            ->where('is_active', true)
            ->whereNotNull('question_group')
            ->orderBy('display_order')
            ->get(['id', 'code', 'name', 'question', 'question_group', 'question_text', 'option_label', 'option_explanation', 'display_order']);

        if ($all->isEmpty()) {
            return collect();
        }

        $selalu = collect(QuestionBank::questions())
            ->filter(fn (array $question): bool => $question['always'])
            ->keys();

        $peringkat = [];

        foreach ($orderedCodes as $posisi => $code) {
            $group = $all->firstWhere('code', $code)?->question_group;

            if ($group !== null && ! isset($peringkat[$group])) {
                $peringkat[$group] = $posisi;
            }
        }

        $urutanGroup = $selalu
            ->merge(collect($peringkat)->sort()->keys())
            ->merge($all->pluck('question_group')->unique())
            ->unique()
            ->values();

        $jumlahPertanyaan = max(2, min(6, (int) ceil($max / 2)));
        $terpilih = $urutanGroup->take($jumlahPertanyaan);

        return $all
            ->filter(fn (Symptom $symptom): bool => $terpilih->contains($symptom->question_group))
            ->sortBy([
                fn (Symptom $a, Symptom $b): int => $terpilih->search($a->question_group) <=> $terpilih->search($b->question_group),
                fn (Symptom $a, Symptom $b): int => $a->display_order <=> $b->display_order,
            ])
            ->map(fn (Symptom $symptom): Symptom => $this->applyQuestionOverride(
                $symptom,
                $complaintFeatures,
                $visualCandidates
            ))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $complaintFeatures
     * @param  array<int, array<string, mixed>>  $visualCandidates
     * @return array<string, float>
     */
    private function candidateProfileBoosts(array $complaintFeatures, array $visualCandidates): array
    {
        $profiles = $this->activeProfiles($complaintFeatures, $visualCandidates);
        $boosts = [];

        foreach ($profiles as $profile) {
            foreach (($this->profileSymptoms()[$profile] ?? []) as $code => $boost) {
                $boosts[$code] = max($boosts[$code] ?? 0.0, $boost);
            }
        }

        return $boosts;
    }

    /**
     * @param  array<string, mixed>  $complaintFeatures
     * @param  array<int, array<string, mixed>>  $visualCandidates
     * @return array<int, string>
     */
    private function activeProfiles(array $complaintFeatures, array $visualCandidates): array
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
                    ...$disease->loadMissing('datasetMappings')->datasetMappings->pluck('dataset_class_name')->all(),
                ];
            }))
            ->filter(fn ($token): bool => is_string($token) && trim($token) !== '')
            ->map(fn (string $token): string => str($token)->lower()->replace(['_', '-'], ' ')->value())
            ->values();

        if ($tokens->isEmpty()) {
            return [];
        }

        $profiles = [];

        foreach ($tokens as $token) {
            $profiles[] = match (true) {
                str_contains($token, 'psoriasis') => 'psoriasis',
                str_contains($token, 'tinea') || str_contains($token, 'kurap') || str_contains($token, 'panu') => 'fungal',
                str_contains($token, 'eczema') || str_contains($token, 'dermatitis') || str_contains($token, 'eksim') => 'eczema',
                str_contains($token, 'candid') => 'fold_fungal',
                str_contains($token, 'urticaria') || str_contains($token, 'biduran') => 'urticaria',
                str_contains($token, 'acne') || str_contains($token, 'jerawat') => 'acne',
                str_contains($token, 'carcinoma') || str_contains($token, 'melanoma') || str_contains($token, 'keratosis') => 'suspicious_lesion',
                default => null,
            };
        }

        return collect($profiles)->filter()->unique()->values()->all();
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function profileSymptoms(): array
    {
        return [
            'psoriasis' => [
                'G03' => 3.4,
                'DRY_SCALY_SKIN' => 3.2,
                'G08' => 2.8,
                'G01' => 2.4,
                'RED_RASH' => 2.2,
                'G16' => 2.0,
                'RECURRENT_OR_DAYS' => 1.8,
                'G19' => 1.5,
                'G02' => 1.2,
            ],
            'fungal' => [
                'G06' => 3.5,
                'RING_SHAPED_EDGE' => 3.4,
                'G07' => 3.0,
                'G08' => 2.8,
                'G19' => 2.3,
                'G03' => 2.0,
                'G02' => 1.8,
            ],
            'fold_fungal' => [
                'MOIST_FOLD_RASH' => 3.4,
                'G10' => 2.8,
                'G14' => 2.0,
                'ITCHING' => 1.8,
                'RED_RASH' => 1.6,
            ],
            'eczema' => [
                'CONTACT_TRIGGER' => 2.8,
                'G13' => 2.8,
                'G20' => 2.5,
                'ITCHING' => 2.0,
                'RED_RASH' => 1.8,
                'DRY_SCALY_SKIN' => 1.7,
                'VESICLES_OOZING' => 1.5,
            ],
            'urticaria' => [
                'WHEALS_COME_GO' => 3.5,
                'ITCHING' => 2.4,
                'CONTACT_TRIGGER' => 1.8,
                'RED_RASH' => 1.5,
            ],
            'acne' => [
                'RED_RASH' => 1.8,
                'BURNING_STINGING' => 1.4,
                'RECURRENT_OR_DAYS' => 1.2,
            ],
            'suspicious_lesion' => [
                'RECURRENT_OR_DAYS' => 2.2,
                'G19' => 2.0,
                'BURNING_STINGING' => 1.2,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $complaintFeatures
     * @param  array<int, array<string, mixed>>  $visualCandidates
     */
    private function applyQuestionOverride(Symptom $symptom, array $complaintFeatures, array $visualCandidates): Symptom
    {
        $profile = $this->activeProfiles($complaintFeatures, $visualCandidates)[0] ?? null;
        $question = $this->profileQuestionOverrides()[$profile][$symptom->code] ?? null;

        if ($question) {
            $symptom->setAttribute('question', $question);
        }

        return $symptom;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function profileQuestionOverrides(): array
    {
        return [
            'psoriasis' => [
                'G03' => 'Apakah bercak tampak menebal dengan sisik putih atau keperakan, bukan hanya kering biasa?',
                'DRY_SCALY_SKIN' => 'Apakah permukaan bercak terasa tebal, kasar, dan bersisik seperti plak?',
                'G08' => 'Apakah pinggir bercak terlihat cukup jelas dibanding kulit sekitarnya?',
                'G01' => 'Apakah dasar bercak tampak merah atau merah muda di bawah sisik?',
                'RED_RASH' => 'Apakah area yang bersisik juga terlihat kemerahan?',
                'G16' => 'Apakah keluhan berada di siku, lutut, kulit kepala, punggung, lengan, atau kaki?',
                'RECURRENT_OR_DAYS' => 'Apakah bercak berlangsung berminggu-minggu, sering kambuh, atau makin menebal?',
                'G19' => 'Apakah bercak bertambah luas perlahan dari waktu ke waktu?',
                'G02' => 'Apakah bercak terasa gatal ringan sampai sedang, bukan nyeri hebat?',
                'ITCHING' => 'Apakah bercak terasa gatal ringan sampai sedang?',
            ],
            'fungal' => [
                'G06' => 'Apakah bentuk ruam cenderung melingkar seperti cincin?',
                'RING_SHAPED_EDGE' => 'Apakah tepi ruam tampak lebih aktif/merah dibanding bagian tengahnya?',
                'G07' => 'Apakah bagian tengah ruam tampak lebih bersih atau lebih normal dibanding tepinya?',
                'G08' => 'Apakah batas tepi ruam terlihat jelas?',
                'G19' => 'Apakah lingkar ruam bertambah lebar perlahan?',
            ],
            'eczema' => [
                'G13' => 'Apakah keluhan muncul setelah memakai sabun, skincare, parfum, logam, tanaman, atau bahan tertentu?',
                'CONTACT_TRIGGER' => 'Apakah ada bahan yang jelas menyentuh area itu sebelum ruam muncul?',
                'G20' => 'Apakah ruam hanya muncul di area yang terkena bahan pemicu tersebut?',
                'VESICLES_OOZING' => 'Apakah ada bintil kecil berair atau kulit tampak basah pada ruam?',
            ],
            'urticaria' => [
                'WHEALS_COME_GO' => 'Apakah bentol muncul lalu hilang, atau berpindah tempat dalam beberapa jam?',
                'ITCHING' => 'Apakah bentol terasa sangat gatal?',
            ],
        ];
    }

    /**
     * Fills remaining slots with symptoms not yet scored from complaint/visual evidence,
     * ranked by how often diseases require them (a proxy for diagnostic value) and
     * shuffled among ties so the fallback set is not the same fixed list every time.
     *
     * @param  array<int, string>  $excludeCodes
     * @return array<int, string>
     */
    private function fallbackSymptomCodes(array $excludeCodes, int $needed): array
    {
        if ($needed <= 0) {
            return [];
        }

        return Symptom::query()
            ->where('is_active', true)
            ->whereNotIn('code', $excludeCodes)
            ->withCount(['diseaseRules as required_rule_count' => fn ($query) => $query->where('is_required', true)])
            ->get(['id', 'code'])
            ->shuffle()
            ->sortByDesc('required_rule_count')
            ->pluck('code')
            ->take($needed)
            ->all();
    }
}
