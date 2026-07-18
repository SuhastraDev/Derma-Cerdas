<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\ConsultationFinalResult;
use App\Models\ConsultationRedFlag;
use App\Models\ConsultationSymptom;
use App\Models\ConsultationVisualResult;
use App\Models\Disease;
use App\Models\RedFlag;
use App\Models\Symptom;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConsultationWorkflowService
{
    public function __construct(
        private readonly CertaintyFactorService $certaintyFactorService,
        private readonly RedFlagService $redFlagService,
        private readonly FusionDecisionService $fusionDecisionService,
        private readonly AiVisualService $aiVisualService,
        private readonly ComplaintExtractionService $complaintExtractionService,
    ) {}

    /**
     * @param  array<int|string, float|int|string>  $symptomInputs
     * @param  array<int|string, bool|int|string>  $redFlagInputs
     */
    public function process(string $visitorName, ?string $complaintText, UploadedFile $image, array $symptomInputs, array $redFlagInputs): Consultation
    {
        return DB::transaction(function () use ($visitorName, $complaintText, $image, $symptomInputs, $redFlagInputs): Consultation {
            $complaintFeatures = $this->complaintExtractionService->extract($complaintText);
            $imagePath = $image->store('consultations', 'public');
            $consultation = Consultation::query()->create([
                'user_id' => auth()->id(),
                'visitor_name' => $visitorName,
                'complaint_text' => $complaintText,
                'complaint_features' => $complaintFeatures,
                'session_code' => $this->sessionCode(),
                'image_path' => $imagePath,
                'status' => 'processing',
                'metadata' => [
                    'consent_accepted_at' => now()->toIso8601String(),
                    'ai_mode' => 'mock_local',
                ],
            ]);

            $normalizedSymptoms = $this->applyComplaintSymptomEvidence(
                $this->normalizeSymptomInputs($symptomInputs),
                $complaintFeatures
            );
            $normalizedRedFlags = $this->applyComplaintRedFlagEvidence(
                $this->normalizeRedFlagInputs($redFlagInputs),
                $complaintFeatures
            );

            $this->storeSymptoms($consultation, $normalizedSymptoms);
            $redFlagResult = $this->redFlagService->evaluate($normalizedRedFlags);
            $this->storeRedFlags($consultation, $redFlagResult);

            $textualRankings = $this->certaintyFactorService->rankDiseases($normalizedSymptoms);
            $visualAnalysis = $this->aiVisualService->analyze($imagePath, $textualRankings);

            if ($visualAnalysis['is_valid_skin_image'] !== true) {
                Storage::disk('public')->delete($imagePath);

                throw ValidationException::withMessages([
                    'image' => $this->visualValidationMessage($visualAnalysis),
                ]);
            }

            $visualCandidates = $visualAnalysis['candidates'];
            $this->storeVisualResults($consultation, $visualCandidates);

            $finalResult = $this->storeFinalResult(
                $consultation,
                $textualRankings,
                $visualCandidates,
                $redFlagResult,
                $visualAnalysis['validation_status'] === 'valid',
            );

            $consultation->update([
                'status' => 'completed',
                'final_score' => round(((float) $finalResult->fusion_score) * 100, 2),
                'final_action' => $finalResult->action,
                'metadata' => [
                    ...($consultation->metadata ?? []),
                    'red_flag_summary' => $redFlagResult,
                    'textual_top_count' => count($textualRankings),
                    'visual_candidate_count' => count($visualCandidates),
                    'complaint_summary' => $complaintFeatures['summary'] ?? [],
                    'visual_validation' => [
                        'provider' => $visualAnalysis['provider'],
                        'status' => $visualAnalysis['validation_status'],
                        'is_valid_skin_image' => $visualAnalysis['is_valid_skin_image'],
                        'warnings' => $visualAnalysis['warnings'],
                    ],
                ],
            ]);

            return $consultation->refresh();
        });
    }

    /**
     * @param  array{validation_status: string, warnings: array<int, string>}  $visualAnalysis
     */
    private function visualValidationMessage(array $visualAnalysis): string
    {
        if ($visualAnalysis['validation_status'] === 'not_configured') {
            return 'Validasi visual AI belum aktif, sehingga foto belum bisa dipastikan sebagai area kulit. Aktifkan AI service sebelum menjalankan analisis foto.';
        }

        if ($visualAnalysis['validation_status'] === 'unavailable') {
            return 'Validasi visual AI sedang tidak dapat dihubungi. Coba lagi setelah service AI aktif.';
        }

        return 'Foto yang diunggah belum terdeteksi sebagai foto area kulit yang valid. Gunakan foto area keluhan kulit yang jelas, terang, dan tidak tertutup objek lain.';
    }

    public function loadResult(string $sessionCode): Consultation
    {
        return Consultation::query()
            ->where('session_code', $sessionCode)
            ->with([
                'symptoms.symptom',
                'visualResults.disease',
                'finalResults.disease.medicineRecommendations.medicine',
                'redFlags.redFlag',
            ])
            ->firstOrFail();
    }

    private function sessionCode(): string
    {
        return 'DC-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(5));
    }

    /**
     * @param  array<int|string, float|int|string>  $inputs
     * @return array<string, float>
     */
    private function normalizeSymptomInputs(array $inputs): array
    {
        $symptoms = Symptom::query()->where('is_active', true)->pluck('code', 'id');
        $normalized = [];

        foreach ($symptoms as $id => $code) {
            $value = $inputs[$id] ?? $inputs[(string) $id] ?? $inputs[$code] ?? 0;
            $normalized[$code] = max(0.0, min(1.0, (float) $value));
        }

        return $normalized;
    }

    /**
     * @param  array<int|string, bool|int|string>  $inputs
     * @return array<string, bool>
     */
    private function normalizeRedFlagInputs(array $inputs): array
    {
        $redFlags = RedFlag::query()->where('is_active', true)->pluck('code', 'id');
        $normalized = [];

        foreach ($redFlags as $id => $code) {
            $value = $inputs[$id] ?? $inputs[(string) $id] ?? $inputs[$code] ?? false;
            $normalized[$code] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $normalized;
    }

    /**
     * @param  array<string, float>  $symptoms
     * @param  array<string, mixed>  $complaintFeatures
     * @return array<string, float>
     */
    private function applyComplaintSymptomEvidence(array $symptoms, array $complaintFeatures): array
    {
        foreach (($complaintFeatures['symptom_evidence'] ?? []) as $code => $evidence) {
            if (! array_key_exists($code, $symptoms)) {
                continue;
            }

            $symptoms[$code] = max((float) $symptoms[$code], (float) ($evidence['score'] ?? 0.0));
        }

        return $symptoms;
    }

    /**
     * @param  array<string, bool>  $redFlags
     * @param  array<string, mixed>  $complaintFeatures
     * @return array<string, bool>
     */
    private function applyComplaintRedFlagEvidence(array $redFlags, array $complaintFeatures): array
    {
        foreach (($complaintFeatures['red_flag_evidence'] ?? []) as $code => $evidence) {
            if (! array_key_exists($code, $redFlags)) {
                continue;
            }

            $redFlags[$code] = (bool) $redFlags[$code] || (bool) ($evidence['detected'] ?? false);
        }

        return $redFlags;
    }

    /**
     * @param  array<string, float>  $symptoms
     */
    private function storeSymptoms(Consultation $consultation, array $symptoms): void
    {
        Symptom::query()
            ->where('is_active', true)
            ->get()
            ->each(function (Symptom $symptom) use ($consultation, $symptoms): void {
                $userCf = $symptoms[$symptom->code] ?? 0.0;

                ConsultationSymptom::query()->create([
                    'consultation_id' => $consultation->id,
                    'symptom_id' => $symptom->id,
                    'user_cf' => $userCf,
                    'selected' => $userCf > 0,
                ]);
            });
    }

    /**
     * @param  array<string, mixed>  $redFlagResult
     */
    private function storeRedFlags(Consultation $consultation, array $redFlagResult): void
    {
        $detectedIds = collect($redFlagResult['detected'] ?? [])->pluck('id')->all();

        RedFlag::query()
            ->where('is_active', true)
            ->get()
            ->each(function (RedFlag $redFlag) use ($consultation, $detectedIds): void {
                ConsultationRedFlag::query()->create([
                    'consultation_id' => $consultation->id,
                    'red_flag_id' => $redFlag->id,
                    'detected' => in_array($redFlag->id, $detectedIds, true),
                ]);
            });
    }

    /**
     * @param  array<int, array<string, mixed>>  $visualCandidates
     */
    private function storeVisualResults(Consultation $consultation, array $visualCandidates): void
    {
        foreach ($visualCandidates as $candidate) {
            /** @var Disease $disease */
            $disease = $candidate['disease'];

            ConsultationVisualResult::query()->create([
                'consultation_id' => $consultation->id,
                'provider' => $candidate['provider'],
                'disease_id' => $disease->id,
                'visual_score' => $candidate['visual_score'],
                'visual_reason' => $candidate['visual_reason'],
                'raw_response' => $candidate['raw_response'],
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $textualRankings
     * @param  array<int, array<string, mixed>>  $visualCandidates
     * @param  array<string, mixed>  $redFlagResult
     */
    private function storeFinalResult(
        Consultation $consultation,
        array $textualRankings,
        array $visualCandidates,
        array $redFlagResult,
        bool $hasValidatedVisual
    ): ConsultationFinalResult {
        if (! $textualRankings && ! $visualCandidates) {
            /** @var Disease $fallbackDisease */
            $fallbackDisease = Disease::query()->where('is_active', true)->firstOrFail();
            $textualRankings = [['disease' => $fallbackDisease, 'textual_cf' => 0.0]];
        }

        $candidateDiseases = collect($textualRankings)
            ->pluck('disease')
            ->merge(collect($visualCandidates)->pluck('disease'))
            ->filter()
            ->unique(fn (Disease $disease): int => $disease->id)
            ->values();

        $decisions = $candidateDiseases
            ->map(function (Disease $candidateDisease) use ($textualRankings, $visualCandidates, $redFlagResult, $hasValidatedVisual): array {
                $textualCandidate = collect($textualRankings)
                    ->first(fn (array $ranking): bool => $ranking['disease']->is($candidateDisease));
                $visualCandidate = collect($visualCandidates)
                    ->first(fn (array $candidate): bool => $candidate['disease']->is($candidateDisease));

                return [
                    'disease' => $candidateDisease,
                    'decision' => $this->fusionDecisionService->decide(
                        disease: $candidateDisease->loadMissing('datasetMappings'),
                        visualScore: (float) ($visualCandidate['visual_score'] ?? 0.0),
                        textualCf: (float) ($textualCandidate['textual_cf'] ?? 0.0),
                        redFlagResult: $redFlagResult,
                        visualWeight: $hasValidatedVisual ? null : 0.0,
                        textWeight: $hasValidatedVisual ? null : 1.0,
                        hasValidatedVisual: $hasValidatedVisual,
                    ),
                ];
            })
            ->sortByDesc(fn (array $item): float => (float) $item['decision']['fusion_score'])
            ->values();

        /** @var Disease $disease */
        $disease = $decisions->first()['disease'];
        $decision = $decisions->first()['decision'];

        return ConsultationFinalResult::query()->create([
            'consultation_id' => $consultation->id,
            'disease_id' => $disease->id,
            'textual_cf' => $decision['textual_cf'],
            'visual_score' => $decision['visual_score'],
            'fusion_score' => $decision['fusion_score'],
            'action' => $decision['action'],
            'explanation' => $decision['explanation'],
            'recommendations_snapshot' => $this->recommendationsSnapshot($disease, $decision['can_recommend_medicine']),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recommendationsSnapshot(Disease $disease, bool $canRecommendMedicine): array
    {
        if (! $canRecommendMedicine) {
            return [];
        }

        return $disease->medicineRecommendations()
            ->where('is_active', true)
            ->with('medicine')
            ->orderBy('priority')
            ->get()
            ->map(fn ($recommendation): array => [
                'medicine_name' => $recommendation->medicine->name,
                'category' => $recommendation->medicine->category,
                'dosage_form' => $recommendation->medicine->dosage_form,
                'usage_instruction' => $recommendation->medicine->usage_instruction,
                'warnings' => $recommendation->medicine->warnings,
                'recommendation_note' => $recommendation->recommendation_note,
            ])
            ->values()
            ->all();
    }
}
