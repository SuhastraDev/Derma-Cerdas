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
    /** Ambang kandidat visual dianggap kuat sebelum boleh menggeser keputusan. */
    private const VISUAL_STRONG = 0.55;

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

            // Teks keluhan TIDAK PERNAH mengisi nilai gejala. Ia hanya menentukan
            // pertanyaan mana yang diajukan (AdaptiveQuestionService) dan disimpan
            // sebagai ringkasan pada metadata. Prinsip yang sama seperti saran AI:
            // boleh memilih pertanyaan, tidak boleh menjawabnya atas nama pengguna.
            $normalizedSymptoms = $this->normalizeSymptomInputs($symptomInputs);
            $normalizedRedFlags = $this->applyComplaintRedFlagEvidence(
                $this->normalizeRedFlagInputs($redFlagInputs),
                $complaintFeatures,
                $this->answeredRedFlagCodes($redFlagInputs)
            );

            $this->storeSymptoms($consultation, $normalizedSymptoms);
            $redFlagResult = $this->redFlagService->evaluate($normalizedRedFlags);
            $this->storeRedFlags($consultation, $redFlagResult);

            $textualRankings = $this->certaintyFactorService->rankDiseases($normalizedSymptoms);
            $visualAnalysis = $this->aiVisualService->analyze(
                $imagePath,
                $textualRankings,
                (string) $complaintText,
                $complaintFeatures['disease_hints'] ?? []
            );

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
                $complaintFeatures['disease_hints'] ?? [],
                $normalizedSymptoms,
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
                        'provider_status' => $visualAnalysis['provider_status'] ?? 'ok',
                        'status' => $visualAnalysis['validation_status'],
                        'is_valid_skin_image' => $visualAnalysis['is_valid_skin_image'],
                        'suggested_symptom_codes' => $visualAnalysis['suggested_symptom_codes'] ?? [],
                        'dataset_retrieval' => array_values(array_slice(
                            $visualAnalysis['raw_response']['dataset_retrieval'] ?? [],
                            0,
                            10
                        )),
                        'warnings' => $visualAnalysis['warnings'],
                    ],
                ],
            ]);

            return $consultation->refresh();
        });
    }

    /**
     * @param  array{provider_status?: string, validation_status: string, warnings: array<int, string>}  $visualAnalysis
     */
    private function visualValidationMessage(array $visualAnalysis): string
    {
        if (($visualAnalysis['provider_status'] ?? null) === 'quota_exceeded') {
            return 'Kuota/limit analisis visual NVIDIA NIM sedang habis. Tunggu hingga kuota tersedia kembali atau gunakan API key dengan limit aktif.';
        }

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
     * Kode tanda bahaya yang benar-benar ditanyakan dan dijawab pengguna.
     *
     * Kuncinya keberadaan key, bukan nilainya: "Tidak" dan "tidak ditanyakan"
     * sama-sama bernilai false, tetapi hanya yang pertama merupakan pernyataan
     * pengguna yang harus dihormati.
     *
     * @param  array<int|string, bool|int|string>  $inputs
     * @return array<int, string>
     */
    private function answeredRedFlagCodes(array $inputs): array
    {
        return RedFlag::query()
            ->where('is_active', true)
            ->pluck('code', 'id')
            ->filter(fn (string $code, int $id): bool => array_key_exists($id, $inputs)
                || array_key_exists((string) $id, $inputs)
                || array_key_exists($code, $inputs))
            ->values()
            ->all();
    }

    /**
     * Tanda bahaya yang terdeteksi dari teks keluhan tetap dipakai sebagai
     * jaring pengaman, TETAPI hanya untuk pertanyaan yang tidak sempat
     * ditanyakan. Bila pengguna sudah menjawab suatu tanda bahaya, jawabannya
     * menang mutlak - sebelumnya operator || membuat jawaban "Tidak" tidak
     * pernah bisa membatalkan deteksi kata kunci, sehingga F07 menahan seluruh
     * hasil tanpa bisa dibantah.
     *
     * @param  array<string, bool>  $redFlags
     * @param  array<string, mixed>  $complaintFeatures
     * @param  array<int, string>  $answeredCodes
     * @return array<string, bool>
     */
    private function applyComplaintRedFlagEvidence(
        array $redFlags,
        array $complaintFeatures,
        array $answeredCodes
    ): array {
        foreach (($complaintFeatures['red_flag_evidence'] ?? []) as $code => $evidence) {
            if (! array_key_exists($code, $redFlags) || in_array($code, $answeredCodes, true)) {
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
     * @param  array<int, array<string, mixed>>  $diseaseHints
     */
    private function storeFinalResult(
        Consultation $consultation,
        array $textualRankings,
        array $visualCandidates,
        array $redFlagResult,
        bool $hasValidatedVisual,
        array $diseaseHints = [],
        array $normalizedSymptoms = []
    ): ConsultationFinalResult {
        if (! $textualRankings) {
            /** @var Disease $fallbackDisease */
            $fallbackDisease = Disease::query()->where('is_active', true)->firstOrFail();
            $textualRankings = [['disease' => $fallbackDisease, 'textual_cf' => 0.0]];
        }

        // Pt / CFt: kandidat teks berkeyakinan tertinggi hasil Forward Chaining + Certainty Factor.
        $topTextual = $textualRankings[0];
        /** @var Disease $textualDisease */
        $textualDisease = $topTextual['disease'];
        $textualCf = (float) ($topTextual['textual_cf'] ?? 0.0);

        // Pv: kandidat visual teratas. Kosong berarti F06 (citra tak dapat dianalisis / di luar ruang lingkup).
        $topVisual = $visualCandidates[0] ?? null;
        $visualDisease = $topVisual ? $topVisual['disease'] : null;
        $visualScore = $topVisual ? (float) $topVisual['visual_score'] : 0.0;
        $hasRedFlags = (bool) ($redFlagResult['has_red_flags'] ?? false);

        // F08: kandidat visual mengarah ke penyakit tanpa basis gejala/CF tervalidasi
        // (di luar cakupan naskah/MVP) dengan keyakinan visual memadai, sedangkan hasil
        // gejala nyaris tidak berbukti apa pun. Tampilkan temuan visual itu langsung
        // (edukasi/rujuk) alih-alih dipaksakan ke Pt yang tidak relevan. Tanda bahaya
        // tetap didahulukan lewat decide() normal (F07 menggantikan semua aturan lain).
        // Kandidat yang berasal dari fallback indeks dataset ($hasValidatedVisual
        // false) tidak boleh menggeser keputusan lewat F08/F09 - sumbernya indeks
        // ber-recall 3,5%, bukan analisis model.
        if (
            ! $hasRedFlags
            && $hasValidatedVisual
            && $visualDisease
            && $visualScore >= self::VISUAL_STRONG
            && $this->visualMatchesDiseaseHint($visualDisease, $diseaseHints)
            && in_array($visualDisease->default_action, ['educate_only', 'refer'], true)
        ) {
            $decision = $this->fusionDecisionService->decideContextAlignedVisual($visualDisease, $visualScore);
            $finalDisease = $visualDisease;
        } elseif (
            ! $hasRedFlags
            && $hasValidatedVisual
            && $this->visualFindingMustNotBeMasked($visualDisease, $visualScore, $textualDisease, $textualCf)
        ) {
            $decision = $this->fusionDecisionService->decideVisualOnly($visualDisease, $visualScore);
            $finalDisease = $visualDisease;
        } else {
            $decision = $this->fusionDecisionService->decide(
                textualDisease: $textualDisease,
                textualCf: $textualCf,
                visualDisease: $visualDisease,
                visualScore: $visualScore,
                visualAvailable: $hasValidatedVisual && $topVisual !== null,
                redFlagResult: $redFlagResult,
            );
            $finalDisease = $textualDisease;
        }

        return ConsultationFinalResult::query()->create([
            'consultation_id' => $consultation->id,
            'disease_id' => $finalDisease->id,
            'textual_cf' => $decision['textual_cf'],
            'visual_score' => $decision['visual_score'],
            'fusion_score' => $decision['fusion_score'],
            'action' => $decision['action'],
            'fusion_rule_code' => $decision['fusion_rule_code'],
            'explanation' => $decision['explanation'],
            'recommendations_snapshot' => $this->recommendationsSnapshot($finalDisease, $decision['can_recommend_medicine']),
        ]);
    }

    /**
     * F08: temuan visual yang kuat tidak boleh ditimpa diam-diam oleh kandidat
     * gejala (Pt) yang berbeda.
     *
     * Sebelumnya syaratnya CFt < 0,10, sehingga Pt sekecil apa pun di atas itu
     * sudah cukup membuang hasil visual. Akibatnya foto psoriasis, lesi
     * mencurigakan, atau infeksi bakteri tetap dilaporkan sebagai penyakit
     * swamedikasi. Syaratnya sekarang:
     *
     * - Pv kuat (>= VISUAL_STRONG) dan berbeda dari Pt;
     * - Pv belum punya basis pengetahuan gejala/CF tervalidasi, sehingga
     *   jalur Certainty Factor memang tidak pernah bisa membenarkannya; dan
     * - Pv bergolongan rujuk (kanker, autoimun, infeksi bakteri) sehingga
     *   keselamatan didahulukan berapa pun CFt, ATAU CFt sendiri belum
     *   mencapai batas "cukup yakin" (Tabel 3.10).
     *
     * Hasilnya tidak pernah berupa rekomendasi obat, hanya edukasi atau rujukan.
     */
    private function visualFindingMustNotBeMasked(
        ?Disease $visualDisease,
        float $visualScore,
        Disease $textualDisease,
        float $textualCf
    ): bool {
        if (! $visualDisease || $visualScore < self::VISUAL_STRONG) {
            return false;
        }

        if ($visualDisease->is($textualDisease) || $visualDisease->symptomRules()->exists()) {
            return false;
        }

        return $visualDisease->default_action === 'refer'
            || $textualCf < FusionDecisionService::HIGH_CF;
    }

    /**
     * @param  array<int, array<string, mixed>>  $diseaseHints
     */
    private function visualMatchesDiseaseHint(Disease $visualDisease, array $diseaseHints): bool
    {
        $hintClasses = collect($diseaseHints)
            ->map(fn ($hint): mixed => is_array($hint) ? ($hint['dataset_class_name'] ?? null) : null)
            ->filter(fn ($className): bool => is_string($className) && trim($className) !== '')
            ->values();

        if ($hintClasses->isEmpty()) {
            return false;
        }

        $visualClasses = $visualDisease->loadMissing('datasetMappings')
            ->datasetMappings
            ->pluck('dataset_class_name');

        return $visualClasses->intersect($hintClasses)->isNotEmpty();
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
